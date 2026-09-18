# TtsVoice 音色样本接收端 + TTS 鉴权代理

跑在 **TTS 主机**（跑 Qwen / mlx-audio 的那台机器）上，**零依赖**（只用 Python 标准库）。

```
receiver.py  (:8899)
  ├─ POST /voice      收音色样本 → 落盘 → 回传绝对路径
  └─ ANY  /v1/...     校验密钥 → 转发到 Qwen (:8880，只监听 127.0.0.1)
```

---

## 为什么要这一层

### ① 音色样本必须落到 TTS 主机上

Qwen 服务端读参考音频的代码是：

```python
if not os.path.exists(ref_audio):
    raise HTTPException(detail=f"Reference audio file not found: {ref_audio}")
```

`ref_audio` **只能是 TTS 主机上的本地文件路径** —— 不接受 URL，也不接受 base64。

所以当「网站」和「TTS 主机」不是同一台机器时，站点上传的样本必须真的传过来。

> 同机 / 共享存储时**不需要**这一层，插件里接收端地址留空即可。

### ② mlx-audio 完全没有鉴权

这不是配置问题，是它**根本没有这个功能** —— 它本来就是给本机开发用的。

实测（未加代理时，直连 8880）：

```
POST /v1/audio/speech  不带任何密钥                  → 200
POST /v1/audio/speech  带 Authorization: Bearer 乱写 → 200   ← 请求头被直接忽略
```

**谁能连上 8880，谁就能白用你的 GPU。** 所以这里在它前面加了一层校验，
并把 Qwen 改成只监听 `127.0.0.1`，不再对外暴露。

---

## 运行

```bash
python3 receiver.py \
    --dir ~/tts/voice-samples \
    --token 换成你的密钥 \
    --host 0.0.0.0 --port 8899 \
    --upstream http://127.0.0.1:8880
```

| 参数 | 默认 | 说明 |
|---|---|---|
| `--dir` | `~/tts/voice-samples` | 音色样本存放目录 |
| `--host` | `0.0.0.0` | 监听地址 |
| `--port` | `8899` | 监听端口 |
| `--token` | 空 | 共享密钥，**强烈建议设置**；留空会打醒目警告 |
| `--upstream` | `http://127.0.0.1:8880` | 上游 Qwen 服务地址 |

生成密钥：

```bash
python3 -c "import secrets; print('ttsv-' + secrets.token_hex(16))"
```

> **配套要求**：把 Qwen 服务的 `--host` 从 `0.0.0.0` 改成 **`127.0.0.1`**。
> 否则它自己那个 8880 还是敞开的，加代理就没意义了。

---

## 接口

```
GET  /voice/health
     → {"ok":true,"version":"1.1.0","dir":"...","writable":true,
        "auth":true,"upstream":"http://127.0.0.1:8880"}

POST /voice
     Header: X-TtsVoice-Token: <密钥>
     Header: X-TtsVoice-Name: ref.wav      （可选，默认 ref.wav）
     Body:   音频二进制（裸 body，不是 multipart）
     → {"ok":true,"path":"/abs/path/ref.wav","size":576078,"sha256":"..."}

ANY  /v1/...
     Header: Authorization: Bearer <密钥>     ← 插件填了 openaiKey 时发的
       或   X-TtsVoice-Token: <密钥>
     → 原样转发到 upstream，响应原样返回
```

**两种密钥头都认**，所以插件侧**不用改任何代码** —— 只是别忘了填 `openaiKey`。

---

## 安全说明

已内置（都实测过）：

| 防护 | 说明 |
|---|---|
| 密钥校验 | 上传和代理全程生效；`hmac.compare_digest` 常数时间比较 |
| 路径穿越 | 文件名洗成安全 basename + 扩展名白名单。实测 `../../../../tmp/evil.sh` → 落成目标目录里的 `evil.wav`，`/tmp/evil.sh` **未被创建** |
| 半截文件 | 先写 `.part` 再 `os.replace` 原子替换，避免被读到不完整的文件 |
| 体积上限 | 单次上传 20MB |
| 密钥缺失 | 启动时打醒目警告 |

仍需注意：

- **务必设置 `--token`**，监听 `0.0.0.0` 时尤其重要
- **不要**把 8899（和 8880）映射到公网 —— 内网 / Tailscale / ZeroTier 就够了
- 样本目录**不要放在 web 根目录下**（默认 `~/tts/voice-samples` 是安全的）
- 即便加了代理，同一内网里知道密钥的人仍能使用；密钥要当密码管理

---

## 开机自启（macOS launchd）

见 `com.zizdog.voicereceiver.plist.example`，改好路径、目录、密钥后：

```bash
cp com.zizdog.voicereceiver.plist.example ~/Library/LaunchAgents/com.zizdog.voicereceiver.plist
launchctl load ~/Library/LaunchAgents/com.zizdog.voicereceiver.plist
tail -f ~/tts/voice-receiver/launchd.out.log
```

---

## 插件里怎么填

| 设置项 | 值 |
|---|---|
| `openaiBaseUrl` | `http://<TTS 主机地址>:8899/v1`　← **走代理，不是 8880** |
| `openaiKey` | 上面那个密钥　← **必填**，代理靠它鉴权 |
| `refUploadUrl` | `http://<TTS 主机地址>:8899` |
| `refUploadToken` | 同一个密钥 |

> 注意 `openaiBaseUrl` 要指向**代理端口**（8899），不是 Qwen 的 8880。

---

## 从 v1.0 升级（已经只部署了直连的）

1. 把新的 `receiver.py` 覆盖到 TTS 主机
2. Qwen 服务的 plist 里 `--host 0.0.0.0` 改成 `--host 127.0.0.1`，重新 load
3. 接收端 plist 加上 `--token <密钥> --upstream http://127.0.0.1:8880`，重新 load
4. 插件里：
   - `openaiBaseUrl` 从 `...:8880/v1` 改成 `...:8899/v1`
   - `openaiKey` 填上密钥
   - `refUploadToken` 填**同一个**密钥

**验证**（在网站那台机器上）：

```bash
TTS=192.168.1.4
TOKEN=你的密钥

# 8880 应该已经连不上了（已只监听 127.0.0.1）
curl -s -o /dev/null -w "8880: %{http_code}\n" --max-time 5 http://$TTS:8880/v1/models

# 不带密钥 → 403
curl -s -o /dev/null -w "无密钥: %{http_code}\n" --max-time 5 http://$TTS:8899/v1/models

# 带密钥 → 200
curl -s -o /dev/null -w "有密钥: %{http_code}\n" --max-time 10 \
  -H "Authorization: Bearer $TOKEN" http://$TTS:8899/v1/models
```
