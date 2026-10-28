<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * TtsVoice - 多服务商 TTS 后端
 *
 * 1.6.0 起把「文本 → 音频」这一步抽象成 Provider 层（1.6.8 起 local/tts-worker 已移除）：
 *   openai  自建 Qwen3-TTS（OpenAI 兼容协议；支持异步作业队列 / 音色克隆）
 *   azure   微软 Azure 语音（同步 REST，逐块合成后拼接 MP3）
 *
 * 音色样本为**可选**：没上传时提交不带 ref_audio，接收端用它的默认音色。
 *
 * 共同的对外契约（Action / Doctor / panel 只依赖这一层）：
 *   id()                               服务商标识
 *   label()                            展示名
 *   isAsync()                          true=异步任务（local），false=同步直出
 *   endpoint()                         当前实际请求的基址（诊断用，可为空）
 *   health()                           array(ok, error, ms, data)
 *   voices()                           array(ok, error, voices, note)
 *   submit($cid, $title, $text, $src)  array(ok, error, jobId, status, audio,
 *                                            duration, size, chapters, progress)
 *   poll($job)                         array|null  任务行补丁；null=无需同步
 *   fetchAudio($job, $targetPath)      bool
 *   delete($job)                       bool
 *
 * 【注意】$submit() 的第 4 个参数是可选的「原始 Markdown 正文」：
 * cleanText() 会去掉 # 标题标记，同步型服务需要靠它把标题映射成章节时间点；
 * 省略时回退为直接在传入文本里找 # 开头的行。
 *
 * 本文件同时提供「下划线类名」（Typecho 1.2 风格）与命名空间别名，
 * 与 compat.php 的取值方式保持一致；所有类都用 class_exists(..., false) 兜底，
 * 重复 include 不会重复声明。
 *
 * @package TtsVoice
 * @author  zizdog
 * @version 1.7.4
 */

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';

if (!class_exists('TtsVoice_Provider', false)) {

    /**
     * 服务商抽象基类
     */
    abstract class TtsVoice_Provider
    {
        /**
         * 服务商标识（local|azure|openai）
         *
         * @return string
         */
        abstract public function id();

        /**
         * 展示名
         *
         * @return string
         */
        abstract public function label();

        /**
         * 是否异步任务模型
         *
         * 只有 local 是异步：submit 只排队，状态靠 poll() 轮询；
         * azure / openai 同步返回音频，submit 结束时任务已 ready。
         *
         * @return bool
         */
        /* ------------------------------------------------------------------ */
        /* 退化（大段静音）检测与重试                                          */
        /* ------------------------------------------------------------------ */

        /**
         * 1 token 对应多少秒音频
         *
         * 实测标定（temperature=0 强制退化，逐档 max_tokens 量时长）：
         *     150→12.0s  300→24.0s  400→32.0s  600→48.0s  900→72.0s  1200→96.0s
         * 全部精确等于 max_tokens × 0.08 —— 也就是 12.5 token/秒，
         * 与模型名里的「12Hz」吻合。
         */
        const SECONDS_PER_TOKEN = 0.08;

        /**
         * 每个字最多给多少 token
         *
         * 正常语速实测 0.23~0.26 秒/字（40~125 字都稳定）；
         * 诗句因为有停顿最高见过 0.53 秒/字。这里按 0.8 秒/字 封顶：
         *     0.8 ÷ 0.08 = ×10
         * 既不会截断正常输出，又把「退化时一路生成到上限」的损失限制在合理范围。
         *
         * 注意别把它调太小：120 字的诗句正常就可能要 60 秒以上，
         * 一律设 400 token（=32 秒）会把正常音频拦腰截断。
         */
        const TOKENS_PER_CHAR = 10;

        /**
         * 单块至少给这么多 token（很短的块也要留余量）
         */
        const MIN_MAX_TOKENS = 400;

        /**
         * 时长顶到上限的多少比例，就判定为退化
         *
         * 退化时输出的是整段静音，时长会**正好**顶到 max_tokens 换算出来的上限，
         * 所以这个判据非常干净（不是「超过某个经验值」，而是「撞到硬上限」）。
         */
        const DEGENERATE_RATIO = 0.95;

        /**
         * 单块最多尝试几次（含首次）
         *
         * 退化是模型的确定性失败模式，随机采样只是「有时能逃出来」；
         * 实测逃离概率约 2/3，所以 3 次能把失败率压到 ~4%。
         * 关键是**别设 temperature=0** —— 贪心解码每次都掉进去（实测 6/6 全退化）。
         */
        const SYNTH_ATTEMPTS = 3;

        public function isAsync()
        {
            return false;
        }

        /**
         * 当前实际请求基址（诊断展示用）
         *
         * @return string
         */
        public function endpoint()
        {
            return '';
        }

        /**
         * 连通性 + 鉴权自检
         *
         * @return array {ok:bool, error:string, ms:int, data:mixed}
         */
        abstract public function health();

        /**
         * 可用音色
         *
         * @return array {ok:bool, error:string, voices:mixed, note:string}
         */
        abstract public function voices();

        /**
         * 提交合成
         *
         * @param int    $cid
         * @param string $title
         * @param string $text       已清洗的朗读文本
         * @param string $sourceText 原始 Markdown 正文（可选，用于章节定位）
         * @return array
         */
        abstract public function submit($cid, $title, $text, $sourceText = '');

        /**
         * 轮询任务状态
         *
         * @param array $job 本地任务行
         * @return array|null 需要写回的任务字段补丁；null 表示无变化/已终态
         */
        public function poll($job)
        {
            return null;
        }

        /**
         * 把远程音频抓取到本地文件
         *
         * @param array  $job
         * @param string $targetPath 绝对路径
         * @return bool
         */
        public function fetchAudio($job, $targetPath)
        {
            return false;
        }

        /**
         * 删除远程任务/资源
         *
         * @param array $job
         * @return bool
         */
        public function delete($job)
        {
            return true;
        }

        /* ============================================================== */
        /* 公共工具                                                        */
        /* ============================================================== */

        /**
         * 读配置
         *
         * @param string $key
         * @param mixed  $default
         * @return mixed
         */
        protected function opt($key, $default = '')
        {
            return TtsVoice_Plugin::opt($key, $default);
        }

        /**
         * 请求超时（秒），与 1.5.0 的语义一致
         *
         * @return int
         */
        protected function timeout()
        {
            return max(5, TtsVoice_Plugin::intOpt('timeout', 15));
        }

        /**
         * 是否校验证书
         *
         * @return bool
         */
        protected function verifySsl()
        {
            return TtsVoice_Plugin::boolOpt('verifySsl', true);
        }

        /**
         * 同步型服务的分块长度
         *
         * @return int
         */
        protected function chunkChars()
        {
            $limit = intval($this->opt('chunkChars', 800));

            if ($limit <= 0) {
                $limit = 800;
            }

            if ($limit < 50) {
                $limit = 50;
            }

            return $limit;
        }

        /**
         * 音频扩展名
         *
         * mp3 / wav 由「输出格式」决定；各 Provider 可以覆盖。
         * 单独抽出来是因为路径、入库字段、时长估算三处必须一致。
         *
         * @return string
         */
        protected function audioExtension()
        {
            return 'mp3';
        }

        /**
         * 音频绝对路径
         *
         * @param int    $cid
         * @param string $ext 留空 = 用 audioExtension()（收尾时如果发现实际拿到的
         *                    格式和请求的不一致，就显式传进来，避免写错后缀）
         * @return string
         */
        protected function audioTarget($cid, $ext = '')
        {
            $ext = ('' !== trim((string) $ext)) ? trim((string) $ext) : $this->audioExtension();

            return rtrim(TtsVoice_Plugin::audioPath(), '/') . '/' . intval($cid) . '.' . $ext;
        }

        /**
         * 音频相对路径（入库用）
         *
         * @param int    $cid
         * @param string $ext
         * @return string
         */
        protected function audioRelative($cid, $ext = '')
        {
            $ext = ('' !== trim((string) $ext)) ? trim((string) $ext) : $this->audioExtension();

            return TtsVoice_Plugin::AUDIO_DIR . '/' . intval($cid) . '.' . $ext;
        }

        /**
         * 底层 HTTP 请求（同步服务用）
         *
         * 不跟随重定向：Authorization / Ocp-Apim-Subscription-Key 这类
         * 自定义头在跨主机 302 时会被 curl 继续带上，等于把 Key 送出去。
         *
         * @param string      $method GET|POST
         * @param string      $url
         * @param array       $headers
         * @param string|null $payload
         * @param int         $timeout
         * @param int         $connectTimeout
         * @return array {ok:bool, code:int, body:string, error:string, headers:array}
         */
        protected function httpRequest($method, $url, $headers, $payload, $timeout, $connectTimeout)
        {
            $method = strtoupper($method);
            $verify = $this->verifySsl();
            $raw    = NULL;
            $code   = 0;
            $err    = '';

            // 响应头（小写名 => 值）。只为了让调用方看得到 X-TtsVoice-Model-Cold 这类头；
            // 旧调用方忽略这个键即可，语义与以前完全一致。
            $respHeaders = array();

            if (function_exists('curl_init')) {
                $ch = curl_init($url);

                $options = array(
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => max(1, intval($timeout)),
                    CURLOPT_CONNECTTIMEOUT => max(1, intval($connectTimeout)),
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_USERAGENT      => 'TtsVoice',
                    CURLOPT_CUSTOMREQUEST  => $method,
                    // HEADERFUNCTION 必须返回「已处理的字节数」，否则 curl 会报错
                    CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                        $len = strlen($line);
                        $pos = strpos($line, ':');

                        if (false !== $pos) {
                            $name = strtolower(trim(substr($line, 0, $pos)));
                            $val  = trim(substr($line, $pos + 1));

                            if ('' !== $name) {
                                $respHeaders[$name] = $val;
                            }
                        }

                        return $len;
                    },
                );

                if (!$verify) {
                    $options[CURLOPT_SSL_VERIFYPEER] = false;
                    $options[CURLOPT_SSL_VERIFYHOST] = false;
                }

                if (NULL !== $payload) {
                    $options[CURLOPT_POSTFIELDS] = $payload;
                }

                curl_setopt_array($ch, $options);

                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

                if (false === $raw) {
                    $err = curl_error($ch);
                }

                curl_close($ch);
            } elseif (ini_get('allow_url_fopen')) {
                $context = stream_context_create(array(
                    'http' => array(
                        'method'         => $method,
                        'header'         => implode("\r\n", $headers),
                        'content'        => NULL === $payload ? '' : $payload,
                        'timeout'        => max(1, intval($timeout)),
                        'ignore_errors'  => true,
                        // 【安全】绝不跟随重定向：curl 分支早就关掉了 FOLLOWLOCATION，
                        // 但 fopen 分支默认会跟 20 跳，并把 X-TtsVoice-Token /
                        // Ocp-Apim-Subscription-Key 一起带到目标主机（实测过）。
                        'follow_location' => 0,
                        'max_redirects'   => 1,
                    ),
                    'ssl' => array(
                        'verify_peer'       => $verify,
                        'verify_peer_name'  => $verify,
                        'allow_self_signed' => !$verify,
                    ),
                ));

                $raw = @file_get_contents($url, false, $context);

                if (false === $raw) {
                    $last = error_get_last();
                    $err  = (is_array($last) && !empty($last['message']))
                        ? '请求失败：' . $last['message']
                        : '请求失败';
                } elseif (isset($http_response_header) && is_array($http_response_header)) {
                    // 取最后一个 HTTP/ 行的状态码：`HTTP/2 200` 这种没有 reason-phrase 的也能解析
                    foreach ($http_response_header as $line) {
                        if (0 === stripos($line, 'HTTP/') && preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                            $code = (int) $m[1];
                        }
                    }

                    foreach ($http_response_header as $line) {
                        $pos = strpos($line, ':');

                        if (false !== $pos) {
                            $name = strtolower(trim(substr($line, 0, $pos)));
                            $val  = trim(substr($line, $pos + 1));

                            if ('' !== $name) {
                                $respHeaders[$name] = $val;
                            }
                        }
                    }
                }
            } else {
                return array(
                    'ok'      => false,
                    'code'    => 0,
                    'body'    => '',
                    'error'   => '服务器未启用 curl 且 allow_url_fopen 已关闭',
                    'headers' => array(),
                );
            }

            if ('' !== $err) {
                return array(
                    'ok'      => false,
                    'code'    => $code,
                    'body'    => '',
                    'error'   => self::explainError($err),
                    'headers' => $respHeaders,
                );
            }

            return array(
                'ok'      => true,
                'code'    => $code,
                'body'    => (string) $raw,
                'error'   => '',
                'headers' => $respHeaders,
            );
        }

        /**
         * 把底层网络错误翻译成人话（与 1.5.0 文案逐字保持一致）
         *
         * @param string $error
         * @return string
         */
        protected static function explainError($error)
        {
            $error = (string) $error;

            $rules = array(
                'SSL certificate problem' =>
                    'HTTPS 证书校验失败。如果用自签证书，到插件设置把「HTTPS 证书校验」改为「跳过验证」；'
                    . '否则检查证书是否过期、域名是否匹配。',

                'peer certificate cannot be authenticated' =>
                    'HTTPS 证书不被信任（多为自签证书）。到插件设置把「HTTPS 证书校验」改为「跳过验证」。',

                'couldn\'t connect to host' =>
                    '连不上目标地址。检查：域名解析是否正确、端口是否放行、反代是否在运行。',

                'Could not resolve host' =>
                    '域名解析失败。检查域名拼写，或在能上网的环境确认该域名可解析。',

                'Operation timed out' =>
                    '请求超时。公网访问建议把「请求超时」调到 30 以上。',

                'Connection refused' =>
                    '连接被拒绝。目标端口没有服务在监听，检查端口映射与容器状态。',

                'Empty reply from server' =>
                    '服务器返回了空响应。反向代理可能配置有误，或后端服务崩溃。',
            );

            foreach ($rules as $needle => $advice) {
                if (false !== stripos($error, $needle)) {
                    return $error . '（' . $advice . '）';
                }
            }

            return $error;
        }

        /* ---------- 同步型服务的文本分块与 MP3 拼接 ---------- */

        /**
         * 把清洗后的文本切成适合单次请求的块
         *
         * 优先在句末标点（。！？!?；;\n）处切；其次在逗号/顿号/空格处；
         * 都没有才硬切。这样可避免把一句话（更不会把词）从中间劈开。
         *
         * @param string $text
         * @param int    $limit 单块最大字符数
         * @return array
         */
        protected function chunkText($text, $limit)
        {
            $text = trim((string) $text);

            if ('' === $text) {
                return array();
            }

            $limit = max(50, intval($limit));
            $len   = mb_strlen($text, 'UTF-8');

            if ($len <= $limit) {
                return array($text);
            }

            $primary   = array('。', '！', '？', '!', '?', '；', ';', "\n");
            $secondary = array('，', '、', ',', '：', ':', ' ', '）', ')', '】', ']');
            $half      = (int) floor($limit * 0.5);

            $chunks = array();
            $pos    = 0;

            while ($pos < $len) {
                $remaining = $len - $pos;

                if ($remaining <= $limit) {
                    $tail = trim(mb_substr($text, $pos, $remaining, 'UTF-8'));

                    if ('' !== $tail) {
                        $chunks[] = $tail;
                    }

                    break;
                }

                $window = mb_substr($text, $pos, $limit, 'UTF-8');

                $best = -1;

                foreach ($primary as $sep) {
                    $p = mb_strrpos($window, $sep, 0, 'UTF-8');

                    if (false !== $p && $p > $best) {
                        $best = $p;
                    }
                }

                if ($best >= $half) {
                    $cut = $best + 1;
                } else {
                    $best2 = -1;

                    foreach ($secondary as $sep) {
                        $p = mb_strrpos($window, $sep, 0, 'UTF-8');

                        if (false !== $p && $p > $best2) {
                            $best2 = $p;
                        }
                    }

                    $cut = ($best2 >= $half) ? $best2 + 1 : $limit;
                }

                $piece = trim(mb_substr($text, $pos, $cut, 'UTF-8'));

                if ('' !== $piece) {
                    $chunks[] = $piece;
                }

                $pos += $cut;
            }

            return $chunks;
        }

        /**
         * 同步型分块方式
         *
         * paragraph —— 按段落 / Markdown 标题切（默认）
         * chars     —— 纯按字数切
         *
         * @return string
         */
        protected function chunkMode()
        {
            $mode = strtolower(trim((string) $this->opt('chunkMode', 'paragraph')));

            return in_array($mode, array('paragraph', 'chars'), true) ? $mode : 'paragraph';
        }

        /**
         * 同步型分块入口
         *
         * @param string $text       清洗后的朗读文本
         * @param string $sourceText 原始 Markdown（用于识别标题；异步型为空）
         * @return array
         */
        protected function chunkForSynth($text, $sourceText = '')
        {
            $limit = $this->chunkChars();

            if ('paragraph' === $this->chunkMode()) {
                return $this->chunkByParagraph($text, $sourceText, $limit);
            }

            return $this->chunkText($text, $limit);
        }

        /**
         * 单段超限时按句切（复用按字数切分）
         *
         * @param string $para
         * @param int    $limit
         * @return array
         */
        protected function splitToLimit($para, $limit)
        {
            $para = trim((string) $para);

            if ('' === $para) {
                return array();
            }

            if (mb_strlen($para, 'UTF-8') <= $limit) {
                return array($para);
            }

            return $this->chunkText($para, $limit);
        }

        /**
         * 段首是否正好是一个 Markdown 标题
         *
         * 用户的 Markdown 里标题与正文之间通常有空行，cleanText() 之后标题
         * 会成为独立段落；但也存在 `## 标题` 紧跟正文（无空行）的写法，
         * 会被并成一段，所以这里再按「段首前缀」兜一次。
         * 标题至少 2 个字符，避免单字标题误匹配。
         *
         * @param string $para
         * @param array  $headings 已清洗的标题文字
         * @return string 命中的标题（未命中返回空串）
         */
        protected function matchLeadingHeading($para, array $headings)
        {
            $para = (string) $para;

            foreach ($headings as $head) {
                $len = mb_strlen($head, 'UTF-8');

                if ($len < 2) {
                    continue;
                }

                if (mb_substr($para, 0, $len, 'UTF-8') === $head) {
                    return $head;
                }
            }

            return '';
        }

        /**
         * 按段落 / Markdown 标题切块
         *
         * 规则：
         *   1. 以空行分段；
         *   2. 原始 Markdown 中的标题（`#` ~ `######`）**开启新块**，
         *      标题与其后续正文合并在同一块里，直到触到长度上限；
         *   3. 单段超限时先在句末标点处切，保证不超长；
         *   4. 拿不到原始 Markdown（异步型/无标题）时退化为「按空行分段 + 超限切句」。
         *
         * 为什么标题要跟正文合并：标题单独成块会变成一次只有几个字的
         * 合成请求，语气也会被截断；合并后语流更自然。
         *
         * @param string $text
         * @param string $sourceText
         * @param int    $limit
         * @return array
         */
        protected function chunkByParagraph($text, $sourceText, $limit)
        {
            $limit = max(50, intval($limit));
            $text  = trim((string) $text);

            if ('' === $text) {
                return array();
            }

            $headings   = $this->headingTitles($sourceText);
            $paragraphs = preg_split('/\n\s*\n/u', $text);

            if (!is_array($paragraphs)) {
                return $this->chunkText($text, $limit);
            }

            $chunks = array();
            $buf    = '';

            foreach ($paragraphs as $para) {
                $para = trim((string) $para);

                if ('' === $para) {
                    continue;
                }

                // 段首命中标题 → 先结束上一块，标题开启新块
                $head = $this->matchLeadingHeading($para, $headings);

                if ('' !== $head) {
                    if ('' !== $buf) {
                        $chunks[] = $buf;
                    }

                    $buf  = $head;
                    $para = trim(mb_substr($para, mb_strlen($head, 'UTF-8'), NULL, 'UTF-8'));

                    if ('' === $para) {
                        continue;
                    }
                }

                foreach ($this->splitToLimit($para, $limit) as $piece) {
                    if ('' === $buf) {
                        $buf = $piece;
                    } elseif (mb_strlen($buf, 'UTF-8') + mb_strlen($piece, 'UTF-8') + 1 <= $limit) {
                        $buf .= "\n" . $piece;
                    } else {
                        $chunks[] = $buf;
                        $buf      = $piece;
                    }
                }
            }

            if ('' !== $buf) {
                $chunks[] = $buf;
            }

            return array_values(array_filter($chunks, function ($c) {
                return '' !== trim($c);
            }));
        }

        /**
         * 提取原始 Markdown 中的标题文字
         *
         * @param string $source
         * @return array
         */
        protected function headingTitles($source)
        {
            $source = (string) $source;
            $titles = array();

            if ('' === trim($source)) {
                return $titles;
            }

            if (preg_match_all('/^[ \t]{0,3}#{1,6}[ \t]+(.+?)[ \t]*#*[ \t]*$/mu', $source, $m)) {
                foreach ($m[1] as $raw) {
                    $title = TtsVoice_Plugin::cleanTitle($raw);
                    $title = trim(preg_replace('/(\*\*|__|~~|`|\*|_)/u', '', $title));

                    if ('' !== $title) {
                        $titles[] = $title;
                    }
                }
            }

            return $titles;
        }

        /**
         * 依据 Markdown 标题近似放置章节起点
         *
         * 【近似说明】正文在进入本层前已被 cleanText() 清洗（# 标记被去掉），
         * 所以这里从「原始 Markdown」取标题文字，再在清洗后的文本里按顺序
         * 查找该文字，取它落在第几个音频块，用该块之前的累计时长作为起点。
         * 标题重复或正文恰好含同一句话时会有偏移，属于可接受的近似。
         *
         * @param string $sourceText 原始 Markdown（可为空）
         * @param string $cleanText  清洗后的朗读文本
         * @param array  $chunks     分块结果
         * @param array  $durations  每块时长（秒）
         * @return array [{title,start}]
         */
        protected function chaptersFromHeadings($sourceText, $cleanText, array $chunks, array $durations)
        {
            $scan = '' === trim((string) $sourceText) ? (string) $cleanText : (string) $sourceText;
            $titles = $this->headingTitles($scan);

            if (empty($titles)) {
                return array();
            }

            // 每块在清洗文本中的起始字符偏移
            $offsets = array();
            $cursor  = 0;

            foreach ($chunks as $piece) {
                $offsets[] = $cursor;
                $cursor   += mb_strlen($piece, 'UTF-8');
            }

            // 累计时长
            $starts = array();
            $acc    = 0.0;

            foreach ($durations as $d) {
                $starts[] = $acc;
                $acc     += (float) $d;
            }

            $chapters = array();
            $cursor   = 0;

            foreach ($titles as $title) {
                $pos = mb_strpos($cleanText, $title, $cursor, 'UTF-8');

                if (false === $pos) {
                    continue;
                }

                $cursor = $pos + mb_strlen($title, 'UTF-8');

                $idx = 0;

                foreach ($offsets as $i => $offset) {
                    if ($offset <= $pos) {
                        $idx = $i;
                    } else {
                        break;
                    }
                }

                $chapters[] = array(
                    'title' => $title,
                    'start' => round(isset($starts[$idx]) ? $starts[$idx] : 0.0, 2),
                );
            }

            return $chapters;
        }

        /**
         * 去掉 MP3 头尾的 ID3 标签
         *
         * 逐块合成时每个响应都可能自带 ID3v2（开头 ID3 + syncsafe 长度）
         * 与 ID3v1（末尾 128 字节的 TAG）。直接拼接会在音频流中间插入
         * 非法标签，播放器会在该处停住，所以必须先剥掉。
         *
         * @param string $data
         * @return string
         */
        protected function stripId3($data)
        {
            $data = (string) $data;
            $len  = strlen($data);

            if ($len > 10 && 'ID3' === substr($data, 0, 3)) {
                $size = ((ord($data[6]) & 0x7f) << 21)
                    | ((ord($data[7]) & 0x7f) << 14)
                    | ((ord($data[8]) & 0x7f) << 7)
                    | (ord($data[9]) & 0x7f);

                $tagSize = 10 + $size;

                // flags bit4 = footer present
                if (0x10 & ord($data[5])) {
                    $tagSize += 10;
                }

                $data = ($tagSize < $len) ? substr($data, $tagSize) : '';
            }

            $len = strlen($data);

            if ($len > 128 && 'TAG' === substr($data, $len - 128, 3)) {
                $data = substr($data, 0, $len - 128);
            }

            return $data;
        }

        /**
         * 原子写入拼接后的音频
         *
         * @param array  $pieces
         * @param string $targetPath
         * @return bool
         */
        protected function writeAudio(array $pieces, $targetPath)
        {
            $data = $this->mergePieces($pieces);

            if ('' === $data) {
                return false;
            }

            $dir = dirname($targetPath);

            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                    return false;
                }
            }

            if (!is_writable($dir)) {
                return false;
            }

            $tmp = $targetPath . '.tmp';

            if (false === @file_put_contents($tmp, $data)) {
                @unlink($tmp);

                return false;
            }

            if (!@rename($tmp, $targetPath)) {
                @unlink($tmp);

                return false;
            }

            return true;
        }

        /**
         * 同一篇文章只保留**刚生成**的那个格式的文件
         *
         * 【为什么需要】格式是可能变的：以前配 mp3，生成了 12.mp3；后来改成 wav（或服务端
         * 没 ffmpeg 退回 wav），这次落盘成 12.wav —— 旧的 12.mp3 就成了孤儿。
         * 它不会被引用（jobs.audio 已指向新文件），但会一直占空间，也容易被手工翻出来
         * 当成「有两个版本」而困惑。这里只清理**同一 cid 的另一种容器格式**，
         * 绝不碰别的文章、别的文件。
         *
         * @param int    $cid
         * @param string $keepExt 本次保留下来的扩展名
         * @return void
         */
        protected function dropStaleAudio($cid, $keepExt)
        {
            $keepExt = strtolower(trim((string) $keepExt));

            foreach (array('mp3', 'wav') as $ext) {
                if ($ext === $keepExt) {
                    continue;
                }

                foreach (array($this->audioTarget($cid, $ext), $this->audioTarget($cid, $ext) . '.tmp') as $path) {
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
            }
        }

        /**
         * 提交/合成失败的统一返回结构
         *
         * @param string $error
         * @return array
         */
        protected function fail($error)
        {
            return array(
                'ok'       => false,
                'error'    => (string) $error,
                'jobId'    => '',
                'status'   => 'failed',
                'audio'    => '',
                'duration' => 0,
                'size'     => 0,
                'chapters' => array(),
                'progress' => '',
                'voice'    => '',
            );
        }

        /**
         * 把若干段音频拼成一个字节串
         *
         * 只保留队列路径后，一次 writeAudio() 拿到的是「整段音频」，
         * 不会再出现多块拼接；原来的 wavPcm/wavFile 合并逻辑已删除。
         *
         * @param array $pieces
         * @return string
         */
        protected function mergePieces(array $pieces)
        {
            return implode('', $pieces);
        }

        /**
         * 从输出格式里解析码率（kbps）
         *
         * azureFormat 形如 audio-24khz-48kbitrate-mono-mp3 → 48
         *
         * @param string $format
         * @param int    $fallback
         * @return int
         */
        protected function bitrateKbps($format, $fallback = 48)
        {
            $format = (string) $format;

            if (preg_match('/(\d{2,3})\s*k\s*(?:bitrate|bit|bps)/i', $format, $m)) {
                return max(8, intval($m[1]));
            }

            return max(8, intval($fallback));
        }

        /**
         * 以「字节数 ÷ 码率」估算时长（秒）
         *
         * @param int $bytes
         * @param int $kbps
         * @return float
         */
        protected function estimateDuration($bytes, $kbps)
        {
            $kbps = max(8, intval($kbps));

            return round(intval($bytes) * 8 / ($kbps * 1000), 3);
        }

        /**
         * 一块文本要多少 max_tokens
         *
         * 标定见基类常量：`max(400, 字数 × 10)`。
         * 网站与 TTS 主机两侧必须用同一个公式，否则退化判定和截断行为会对不上。
         *
         * 【2026-10-28 从 OpenAI 子类上移】Azure 的 synthesizeAll() 也要用它。
         *
         * @param string $text
         * @return int
         */
        protected function maxTokensFor($text)
        {
            $chars = function_exists('mb_strlen')
                ? mb_strlen((string) $text, 'UTF-8')
                : strlen((string) $text);

            return max(self::MIN_MAX_TOKENS, (int) ceil($chars * self::TOKENS_PER_CHAR));
        }

        /**
         * 按实际字节判断容器格式
         *
         * 【为什么需要】接收端如果还没升级（v1.3.0 只吐 mp3），而网站配置成了 wav，
         * 就会把 mp3 字节写进 `.wav` 文件 —— 后缀和内容不一致，播放器可能不认，
         * 章节时长也会按 48000 B/s 算错。所以收尾时一律以**真实字节**为准。
         *
         * 【2026-10-28 从 OpenAI 子类上移】Azure 的 synthesizeAll() 也要用它。
         *
         * @param string $data
         * @return string wav | mp3
         */
        protected function sniffExtension($data)
        {
            $data = (string) $data;

            if (strlen($data) >= 12 && 'RIFF' === substr($data, 0, 4) && 'WAVE' === substr($data, 8, 4)) {
                return 'wav';
            }

            return 'mp3';
        }

    }

    /**
     * OpenAI 兼容 TTS（同步 REST，POST {base}/audio/speech）
     */
    class TtsVoice_Provider_OpenAI extends TtsVoice_Provider
    {
        /**
         * @return string
         */
        public function id()
        {
            return 'openai';
        }

        /**
         * @return string
         */
        public function label()
        {
            return _t('自建 Qwen3-TTS');
        }

        /**
         * @return string
         */
        public function endpoint()
        {
            $base = trim((string) $this->opt('openaiBaseUrl', 'https://api.openai.com/v1'));

            if ('' === $base) {
                $base = 'https://api.openai.com/v1';
            }

            return rtrim($base, '/');
        }

        /**
         * OpenAI 的 response_format
         *
         * @return string
         */
        protected function outputFormat()
        {
            $format = trim((string) $this->opt('openaiFormat', 'mp3'));

            return '' === $format ? 'mp3' : $format;
        }

        /**
         * 落盘的音频扩展名（跟着 response_format 走）
         *
         * @return string
         */
        protected function audioExtension()
        {
            $format = strtolower($this->outputFormat());

            if (0 === strpos($format, 'wav') || 0 === strpos($format, 'pcm')) {
                return 'wav';
            }

            return 'mp3';
        }

        /**
         * 本地变速倍率（openaiSpeed）
         *
         * 上游 mlx-audio 的 speed 是空实现，所以这个值只用来做下载后的
         * ffmpeg atempo —— 不再往请求里带（避免将来上游实现了变成双重变速）。
         *
         * @return float
         */
        protected function speedFactor()
        {
            $speed = floatval($this->opt('openaiSpeed', '1.0'));

            if ($speed <= 0) {
                $speed = 1.0;
            }

            // 0.5x ~ 4x，超出范围基本只有坏处
            return max(0.5, min(4.0, $speed));
        }

        /**
         * Qwen3-TTS 的采样参数
         *
         * 上游 server.py 的默认值是 temperature=0.7 / top_p=0.95 / top_k=40，
         * 但它把模型内部的 repetition_penalty=1.05 **覆盖成了 1.0**，
         * 结果是「要么正常、要么整段退化成噪声」。
         * 显式带上 rp=1.05 后重试 4/4 正常（见 SPEC §14）。
         *
         * @return array
         */
        protected function sampling()
        {
            /*
             * 这组值是反复对照试出来的「不退化」组合（详见 SPEC-TO-MINI-job-queue.md §14）：
             * 上游默认把 repetition_penalty 压成 1.0，长句会整段退化成噪声；
             * 显式补上 1.05 后 1/4 → 4/4 正常。因此不再暴露成配置项 —— 它们是实验结论，不是用户选项。
             */
            return array(
                'temperature'        => 0.7,
                'top_p'              => 0.9,
                'top_k'              => 40,
                'repetition_penalty' => 1.05,
            );
        }

        /**
         * 时长估算：wav 是定码率（24kHz × 16bit × 单声道 = 48000 B/s），
         * 直接拿字节数算比按 kbps 反推准得多（mp3 才需要反推）。
         *
         * @param int    $bytes
         * @param int    $kbps
         * @param string $ext 留空 = 按配置；退化判定时传真实字节的格式
         * @return float
         */
        protected function estimateDuration($bytes, $kbps, $ext = '')
        {
            $ext = ('' !== trim((string) $ext)) ? trim((string) $ext) : $this->audioExtension();

            if ('wav' === $ext) {
                return round(max(0, intval($bytes) - 44) / 48000, 3);
            }

            return parent::estimateDuration($bytes, $kbps);
        }

        /**
         * 每块的时长（已按实际生效的变速倍率折算）
         *
         * 章节时间轴靠它，必须和最终音频一致。
         *
         * @param array  $sizes  每块字节数
         * @param float  $factor 实际生效的变速倍率（1.0 = 没变）
         * @param string $ext    实际落盘格式（留空 = 按配置）
         * @return array
         */
        protected function durationsFromSizes(array $sizes, $factor = 1.0, $ext = '')
        {
            $factor = ($factor > 0) ? floatval($factor) : 1.0;
            $ext    = ('' !== trim((string) $ext)) ? trim((string) $ext) : $this->audioExtension();
            $kbps   = $this->bitrateKbps($this->outputFormatForBitrate(), 128);
            $out    = array();

            foreach ($sizes as $bytes) {
                $seconds = ('wav' === $ext)
                    ? round(max(0, intval($bytes) - 44) / 48000, 3)
                    : parent::estimateDuration($bytes, $kbps);

                $out[] = round($seconds / $factor, 3);
            }

            return $out;
        }

        /**
         * 对最终音频做本地后处理（变速 + 统一编码）
         *
         * 找不到 ffmpeg / 处理失败都**不算失败**：原速原格式的音频已经落盘，
         * 顶多语速没变，不该让整篇文章白跑。
         *
         * @param string $target
         * @param string $ext 实际落盘格式（留空 = 按配置）
         * @return float 实际生效的倍率
         */
        protected function applySpeed($target, $ext = '')
        {
            $speed = $this->speedFactor();

            if ($speed >= 0.999 && $speed <= 1.001) {
                return 1.0;
            }

            $result = TtsVoice_Plugin::postProcessAudio(
                $target,
                $target,
                $speed,
                ('' !== trim((string) $ext)) ? trim((string) $ext) : $this->audioExtension()
            );

            return !empty($result['ok']) ? $speed : 1.0;
        }

        /**
         * 克隆用的模型（Base）
         *
         * 名字的读取/兜底统一放在 TtsVoice_Plugin::cloneModelName()（面板也要用同一份判断）。
         *
         * @return string
         */
        protected function cloneModel()
        {
            return TtsVoice_Plugin::cloneModelName();
        }

        /**
         * 当前这次请求实际会用的模型（只剩克隆一条路，恒为 Base）
         *
         * @return string
         */
        protected function activeModel()
        {
            return $this->cloneModel();
        }

        /**
         * 时长估算用的码率：OpenAI mp3 没有公开固定码率，按 128 kbps 粗估
         *
         * @return string
         */
        protected function outputFormatForBitrate()
        {
            return '128kbps';
        }

        /**
         * 写入任务的 voice 字段：模型 + 样本指纹便于排查
         *
         * 现在没有「预置音色名」可记了，但 ref:<指纹> 更有用 ——
         * 样本一换，旧任务立刻能被认出来（面板标「音色已过期」）。
         *
         * @return string
         */
        protected function voiceLabel()
        {
            return $this->activeModel() . ':' . TtsVoice_Plugin::jobVoiceTag();
        }

        /**
         * 鉴权自检：GET {base}/models（文档化的轻量接口，不消耗合成额度）
         *
         * @return array
         */
        public function health()
        {
            $key = trim((string) $this->opt('openaiKey', ''));

            $headers = array('Accept: application/json');

            if ('' !== $key) {
                $headers[] = 'Authorization: Bearer ' . $key;
            }

            $start = microtime(true);

            $response = $this->httpRequest(
                'GET',
                $this->endpoint() . '/models',
                $headers,
                NULL,
                $this->timeout(),
                $this->timeout()
            );

            $ms = round((microtime(true) - $start) * 1000);

            if (!$response['ok']) {
                return array('ok' => false, 'error' => $response['error'], 'ms' => $ms, 'data' => NULL);
            }

            if (200 !== $response['code']) {
                $message = $this->withProxyHint(trim(substr(strip_tags($response['body']), 0, 200)));

                return array(
                    'ok'    => false,
                    'error' => 'HTTP ' . $response['code'] . ('' === $message ? '' : ' ' . $message),
                    'ms'    => $ms,
                    'data'  => NULL,
                );
            }

            $decoded = json_decode($response['body'], true);

            /*
             * 【必须是 JSON】地址填成别的服务（NAS 管理页 / 反代首页）时，对方往往对任何
             * 路径都回 200 + HTML，以前这里照样判「通过」，而真正合成时全挂。
             */
            if (!is_array($decoded)) {
                $hint = substr(trim(strip_tags((string) $response['body'])), 0, 120);

                return array(
                    'ok'    => false,
                    'error' => 'HTTP 200 但不是 JSON（地址可能填错了）'
                               . ('' === $hint ? '' : '：' . $hint),
                    'ms'    => $ms,
                    'data'  => NULL,
                );
            }

            $count = (isset($decoded['data']) && is_array($decoded['data']))
                ? count($decoded['data'])
                : 0;

            return array(
                'ok'    => true,
                'error' => '',
                'ms'    => $ms,
                'data'  => array(
                    'models'       => $count,
                    'model'        => $this->activeModel(),
                    'mode'         => TtsVoice_Plugin::hasCloneVoice() ? 'clone(Base)' : 'no-sample',
                    'cloneModel'   => $this->cloneModel(),
                    'hasSample'    => TtsVoice_Plugin::hasCloneVoice(),
                ),
            );
        }

        /**
         * 可用音色列表
         *
         * 自建 Qwen3-TTS 没有「预置音色列表」：主机已内置默认音色，
         * 上传样本则用样本克隆。
         *
         * @return array
         */
        public function voices()
        {
            return array(
                'ok'     => true,
                'error'  => '',
                'voices' => array(),
                'note'   => 'TTS 主机已内置默认音色 —— 不上传样本也能直接生成；'
                            . '想用自己的声音，可在面板上传一段 10 秒以上的人声做克隆。',
            );
        }

        /**
         * @param int    $cid
         * @param string $title
         * @param string $text
         * @param string $sourceText
         * @return array
         */
        /**
         * 是否走异步任务队列（现在恒为 true）
         *
         * 合成跑在 TTS 主机上，网站只负责提交与轮询 —— 关掉页面也不丢进度。
         *
         * @return bool
         */
        public function isAsync()
        {
            return true;
        }

        /**
         * 提交到 TTS 主机的作业队列
         *
         * 只保留队列路径：分块、max_tokens、音色都由这里决定，
         * 接收端负责排队、逐块合成、退化重试与拼接。
         *
         * @param int    $cid
         * @param string $title
         * @param string $text
         * @param string $sourceText
         * @return array
         */
        public function submit($cid, $title, $text, $sourceText = '')
        {
            $cid    = intval($cid);
            $chunks = $this->chunkForSynth($text, $sourceText);

            if (empty($chunks)) {
                return $this->fail('正文清洗后为空，无法合成');
            }

            $remote = $this->submitRemoteJob($cid, $title, $chunks);

            if (NULL === $remote) {
                return $this->fail(
                    'TTS 主机不支持作业队列（接收端过旧或地址不对）。'
                    . '请把接收端升级到 v1.3.0+，并在设置页确认「Qwen 服务端地址」指向接收端。'
                );
            }

            return $remote;
        }

        /**
         * 提交到 TTS 主机的作业队列（receiver v1.3.0+ `/jobs`）
         *
         * 契约见 `SPEC-TO-MINI-job-queue.md`：分块/ max_tokens / 音色选模型都在这里决定，
         * 服务端只负责「串行逐块合成 + 退化重试 + 拼接 + 落盘」。
         *
         * @param int   $cid
         * @param string $title
         * @param array $chunks 已分块的纯文本数组
         * @return array|null null = 接收端不支持 /jobs，调用方应回退
         */
        protected function submitRemoteJob($cid, $title, array $chunks)
        {
            /*
             * 【音色样本是**可选**的】TTS 主机上有一份默认音色（接收端的 default 来源），
             * 所以这里**绝不因为样本缺失/未推送而拦截提交**：
             *
             *   · refAudioPath 有值（= 样本已推到 TTS 主机、拿到的是**主机上的路径**）
             *     → 带 ref_audio + source 提交，用你自己的克隆音色；
             *   · refAudioPath 为空（没上传 / 只上传到网站本机 / 换了主机导致失效）
             *     → **不带** ref_audio 提交，接收端自动用它的 <dir>/default/ref.wav。
             *
             * 注意不能把「网站本机的路径」当 ref_audio 发过去 —— 接收端读的是
             * **它自己那台机器**上的路径，发过去只会得到 "ref_audio not found"。
             * 所以这里只认 refAudioPath，不再回落到 refAudioFile()。
             */
            $refAudio = trim((string) TtsVoice_Plugin::opt('refAudioPath', ''));

            $payloadChunks = array();

            foreach ($chunks as $text) {
                $payloadChunks[] = array(
                    'text'       => (string) $text,
                    'max_tokens' => $this->maxTokensFor((string) $text),
                );
            }

            // 幂等键：同一篇 + 同一音色 + 同一分块结果 → 网络重试不会造出重复任务。
            // （接收端只对 queued/running 复用；已完成的任务不受影响，可以重新生成。）
            $fingerprint = md5($cid . '|' . TtsVoice_Plugin::jobVoiceTag() . '|'
                . count($chunks) . '|' . (string) $chunks[0]);

            $payload = array(
                'client_id'       => 'cid-' . $cid . '-' . substr($fingerprint, 0, 12),
                'model'           => $this->activeModel(),
                'response_format' => $this->outputFormat(),
                // 接收端支持就照做，不支持就忽略（v1.3.0 会忽略未知字段）。
                // 详见 SPEC §14：少了 repetition_penalty=1.05，长句会整段退化。
                'sampling'        => $this->sampling(),
                'chunks'          => $payloadChunks,
            );

            if ('' !== $refAudio) {
                // 本次用的是「哪个来源」的音色样本（receiver v1.5.0+ 会记进任务与日志，
                // 多站点共用一台 TTS 主机时便于对账）。
                $payload['source']    = TtsVoice_Plugin::refSourceId();
                $payload['ref_audio'] = $refAudio;

                $refText = TtsVoice_Plugin::refText();

                if ('' !== $refText) {
                    $payload['ref_text'] = $refText;
                }
            }
            // 没样本：不带 source/ref_audio → 接收端用 default 音色

            $response = $this->receiverRequest(
                'POST',
                '/jobs',
                json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                20
            );

            // 没配接收端 → 交给调用方回退
            if (NULL === $response) {
                return NULL;
            }

            // 连不上接收端：回退也没戏（回退也要连同一台机器），直接报到面板
            if (!$response['ok']) {
                return $this->fail('连不上 TTS 主机的作业队列：' . $response['error']);
            }

            // 老接收端没有 /jobs → 回退到浏览器驱动
            if (404 === $response['code']) {
                return NULL;
            }

            $json = json_decode((string) $response['body'], true);

            if (200 !== $response['code'] || !is_array($json) || empty($json['ok']) || empty($json['job_id'])) {
                $detail = (is_array($json) && !empty($json['error']))
                    ? (string) $json['error']
                    : $this->receiverError($response);

                return $this->fail(
                    'TTS 主机排队失败（HTTP ' . $response['code'] . '）：'
                    . ('' === $detail ? '无返回内容' : $detail)
                );
            }

            $total = count($chunks);
            $patch = array(
                // 远端 job_id 以 j- 开头；poll() 靠这个前缀判断走新路径
                'job_id'   => (string) $json['job_id'],
                'status'   => ('running' === (isset($json['status']) ? $json['status'] : '')) ? 'running' : 'queued',
                'title'    => (string) $title,
                'voice'    => TtsVoice_Plugin::jobVoiceTag(),
                'speed'    => '',
                'progress' => ('running' === (isset($json['status']) ? $json['status'] : ''))
                    ? '0/' . $total
                    : '0/' . $total,
                'duration' => '',
                'size'     => 0,
                'chapters' => array(),
                'audio'    => '',
                'error'    => '',
            );

            TtsVoice_Plugin::saveJob($cid, $patch);

            return array(
                'ok'       => true,
                'error'    => '',
                'jobId'    => $patch['job_id'],
                'status'   => $patch['status'],
                'audio'    => '',
                'duration' => 0,
                'size'     => 0,
                'chapters' => array(),
                'progress' => $patch['progress'],
                'voice'    => $patch['voice'],
                'estimate' => true,
            );
        }

        /**
         * 查一次远端任务状态；就绪则下载收尾
         *
         * 合成在 TTS 主机上跑，这里只做两件轻活：查状态；就绪后下载。
         * 所以浏览器关掉 / 页面跳走都不影响任务 —— 下次谁来查都能接上。
         *
         * @param array $job
         * @return array|null
         */
        public function poll($job)
        {
            $job    = is_array($job) ? $job : array();
            $cid    = intval(isset($job['cid']) ? $job['cid'] : 0);
            $status = (string) (isset($job['status']) ? $job['status'] : '');

            if ($cid <= 0 || in_array($status, array('ready', 'failed'), true)) {
                return null;
            }

            $jobId = isset($job['job_id']) ? (string) $job['job_id'] : '';

            // 只剩队列任务（j- 开头）。历史脏行 / 手动改库都会落到这里，直接判失败让用户重生成。
            if (0 !== strpos($jobId, 'j-')) {
                return array(
                    'status' => 'failed',
                    'error'  => '任务标识无效（不是队列任务），请重新生成',
                );
            }

            return $this->pollRemoteJob($cid, $jobId, $job);
        }

        /* ============================================================== */
        /* 服务商侧作业队列（接收端 v1.3.0+ /jobs）                        */
        /* ============================================================== */

        /**
         * 调一次 TTS 主机接收端的接口
         *
         * 只用于 /jobs/*；用 `X-TtsVoice-Token`（与上传音色样本同一把 token）。
         * 返回 null 表示「没配接收端地址」，调用方应回退到老路径。
         *
         * @param string      $method
         * @param string      $path   以 / 开头
         * @param string|null $payload JSON 字符串或 null
         * @param int         $timeout
         * @return array|null httpRequest() 的返回
         */
        protected function receiverRequest($method, $path, $payload = NULL, $timeout = 20)
        {
            $base = trim((string) TtsVoice_Plugin::refUploadUrl());

            if ('' === $base || !preg_match('#^https?://#i', $base)) {
                return NULL;
            }

            $headers = array('Accept: application/json');

            if (NULL !== $payload) {
                $headers[] = 'Content-Type: application/json';
            }

            $token = TtsVoice_Plugin::refUploadToken();

            if ('' !== $token) {
                $headers[] = 'X-TtsVoice-Token: ' . $token;
            }

            return $this->httpRequest(
                $method,
                rtrim($base, '/') . $path,
                $headers,
                $payload,
                max(5, intval($timeout)),
                5
            );
        }

        /**
         * 反向代理把「上游地址」写错时，报错很隐晦 → 补一句能照着做的提示
         *
         * 典型：Lucky / Nginx 回 `{"msg":"target url [...]: invalid port \": 8894\" after host"}`。
         * 真实原因往往是**全角冒号「：」**（不是空格），但报错里完全看不出来。
         *
         * @param string $text
         * @return string
         */
        protected function withProxyHint($text)
        {
            $text = trim((string) $text);

            if ('' === $text) {
                return $text;
            }

            if (preg_match('#invalid port|parse error|unsupported protocol scheme|invalid character#i', $text)) {
                $text .= '　【提示】这是反向代理（Lucky / Nginx 等）在解析它自己的「上游地址」时报的错，'
                    . '不是插件或 TTS 主机的问题：去检查反代规则里的目标地址，'
                    . '端口前的冒号必须是**半角「:」**（别用全角「：」），主机和端口前后也不要有空格。';
            }

            return $text;
        }

        /**
         * 从接收端（或它前面的反代）的响应里抠一句人话错误
         *
         * 反代返回的错误通常是 `{"msg":"…","ret":1}`（Lucky / Nginx 之类），
         * 接收端自己的错误是 `{"ok":false,"error":"…"}`。两种都认，
         * 再兜底截一段响应体 —— 总比只显示一个「HTTP 502」有用。
         *
         * @param array $response httpRequest() 的返回
         * @return string
         */
        protected function receiverError($response)
        {
            $body = trim((string) (isset($response['body']) ? $response['body'] : ''));
            $json = json_decode($body, true);

            if (is_array($json)) {
                foreach (array('error', 'msg', 'message') as $key) {
                    if (!empty($json[$key]) && is_string($json[$key])) {
                        return $this->withProxyHint(trim($json[$key]));
                    }
                }
            }

            /*
             * 【HTTP 200 但不是 JSON】踩过一次：把「Qwen 服务端地址」填成了 NAS 的
             * 管理界面（飞牛 fnOS），对方对任何路径都回 200 + 一整页 HTML，
             * json_decode 直接失败 —— 报错只剩一句「接收端拒绝：HTTP 200」，
             * 完全看不出是地址填错了。这里给一句能照着排查的话。
             */
            if ('' !== $body && NULL === $json) {
                $looksHtml = (false !== stripos($body, '<html') || false !== stripos($body, '<!doctype'));
                $ctype     = isset($response['headers']['content-type'])
                    ? trim((string) $response['headers']['content-type'])
                    : '';
                $snippet   = TtsVoice_Plugin::bodyExcerpt($body);

                return $this->withProxyHint(
                    '返回的不是 JSON（HTTP ' . intval(isset($response['code']) ? $response['code'] : 0)
                    . ('' !== $ctype ? '，Content-Type: ' . $ctype : '')
                    . '）'
                    . ($looksHtml ? '，而是一整页网页' : '')
                    . ' —— 「Qwen 服务端地址」很可能填错了（指到了别的服务/后台界面），'
                    . '正确值应指向接收端，例如 http://<主机>:8894/v1'
                    . ('' !== $snippet ? '。响应开头：' . $snippet : '')
                );
            }

            if ('' === $body) {
                return '';
            }

            $text = trim(strip_tags($body));
            $text = function_exists('mb_substr') ? mb_substr($text, 0, 300, 'UTF-8') : substr($text, 0, 300);

            return $this->withProxyHint($text);
        }

        /**
         * 实际交付格式与设置不一致时，生成一条「提示」
         *
         * 【为什么放在 error 字段里】jobs 表没有 notice 列，也不想为一句提示去做表结构迁移。
         * 所以约定：以 `[提示]` 开头的 error 表示**这件事成功了，但有句话要说**，
         * 面板按灰色显示、不弹错误样式；`status` 仍然是 ready。
         * （所有读 error 的地方都只在 `status === 'failed'` 时把它当失败原因，见 panel.php。）
         *
         * @param string $actual 实际落盘的扩展名
         * @return string
         */
        protected function formatNotice($actual)
        {
            $wanted = $this->audioExtension();

            if ('' === $actual || $actual === $wanted) {
                return '';
            }

            return '[提示] 服务端返回的是 ' . strtoupper($actual)
                . '（设置里写的是 ' . strtoupper($wanted) . '），已按实际格式保存 —— '
                . '播放、下载、章节时间轴都正常。多半是那台机器没有 ffmpeg，编不出 '
                . strtoupper($wanted) . '。';
        }

        /**
         * 查一次远端任务状态；就绪则下载收尾
         *
         * 【为什么不在这里合成】合成已经在 TTS 主机上跑了。这里只做「查 + 下载」，
         * 所以每次都是毫秒级，也不会再撞 nginx 的 60 秒。
         *
         * @param int    $cid
         * @param string $jobId 远端 job_id（j-…）
         * @param array  $job   本地任务行
         * @return array|null
         */
        protected function pollRemoteJob($cid, $jobId, array $job)
        {
            $response = $this->receiverRequest('GET', '/jobs/' . rawurlencode($jobId), NULL, 15);

            if (NULL === $response) {
                return NULL;
            }

            // 网络抖动：这一拍不更新，下次轮询再查（远端任务照跑）
            if (!$response['ok']) {
                return NULL;
            }

            if (404 === $response['code']) {
                return array(
                    'status' => 'failed',
                    'error'  => 'TTS 主机上找不到该任务（可能已被清理），请重新生成',
                );
            }

            $json = json_decode((string) $response['body'], true);

            if (200 === $response['code'] && (!is_array($json) || empty($json['ok']))) {
                // 200 却不是有效 JSON → 多半是反代/登录页，直接判失败（网络类错误仍留给超时兜底）
                return array(
                    'status' => 'failed',
                    'error'  => 'TTS 主机返回了非 JSON 响应：' . $this->receiverError($response),
                );
            }

            if (200 !== $response['code'] || !is_array($json)) {
                return NULL;
            }

            $remoteStatus = (string) (isset($json['status']) ? $json['status'] : '');
            $total        = max(1, intval(isset($json['total']) ? $json['total'] : 0));
            $done         = intval(isset($json['done']) ? $json['done'] : 0);

            if ('queued' === $remoteStatus) {
                return array('status' => 'queued', 'progress' => '0/' . $total);
            }

            if ('running' === $remoteStatus) {
                return array('status' => 'running', 'progress' => min($done, $total) . '/' . $total);
            }

            if ('failed' === $remoteStatus) {
                return array(
                    'status' => 'failed',
                    'error'  => (string) (isset($json['error']) && '' !== $json['error']
                        ? $json['error']
                        : 'TTS 主机合成失败'),
                );
            }

            if ('cancelled' === $remoteStatus) {
                return array('status' => 'failed', 'error' => '任务已被取消');
            }

            if ('ready' === $remoteStatus) {
                return $this->finishRemoteJob($cid, $jobId, $json);
            }

            /*
             * 未知状态（接收端改过状态名 / 反代塞了别的东西）：
             * 以前返回 NULL＝「下次再查」，任务会永远卡住且没有任何提示。
             * 现在直接判失败，并把原始状态带给用户。
             */
            return array(
                'status' => 'failed',
                'error'  => 'TTS 主机返回了未知任务状态：'
                            . ('' === $remoteStatus ? '(空)' : $remoteStatus)
                            . '；请升级接收端或重新生成',
            );
        }

        /**
         * 远端任务就绪 → 下载整段音频 → 落盘 → 算时长与章节 → 让主机清产物
         *
         * 章节时间轴要靠每块字节数：接收端在 `chunk_bytes` 里按顺序回报
         * （去 ID3 之后的长度），这里用与老路径同一个 128kbps 估算。
         *
         * @param int    $cid
         * @param string $jobId
         * @param array  $remote GET /jobs/{id} 的 data
         * @return array|null null 表示「下载失败，下次再试」（远端产物还在）
         */
        protected function finishRemoteJob($cid, $jobId, array $remote)
        {
            $post = TtsVoice_Plugin::postForTts($cid);

            if (!$post) {
                return array('status' => 'failed', 'error' => '文章不存在或已被删除');
            }

            $audio = $this->receiverRequest(
                'GET',
                '/jobs/' . rawurlencode($jobId) . '/audio',
                NULL,
                120
            );

            if (NULL === $audio || !$audio['ok'] || 200 !== $audio['code'] || '' === $audio['body']) {
                return NULL;
            }

            $clean = $this->stripId3($audio['body']);

            if ('' === $clean) {
                return array('status' => 'failed', 'error' => '从 TTS 主机下载的音频为空');
            }

            /*
             * 【必须是音频】反代/接收端出错时可能回 200 + JSON/HTML，以前会照样写盘并标 ready，
             * 播放器拿到坏文件却看不出来。这里做一次魔数校验，失败时把响应片段带出来。
             */
            if (!$this->looksLikeAudio($clean)) {
                $hint = substr(trim(strip_tags((string) $audio['body'])), 0, 160);

                return array(
                    'status' => 'failed',
                    'error'  => 'TTS 主机返回的不是音频（可能是反代错误页）。'
                                . ('' === $hint ? '' : '响应片段：' . $hint),
                );
            }

            // 接收端返回的可能是 mp3（v1.3.0 只吐 mp3），而网站配置成了 wav
            // → 以真实字节定后缀，避免 .wav 里装 mp3
            $ext      = $this->sniffExtension($clean);
            $target   = $this->audioTarget($cid, $ext);
            $relative = $this->audioRelative($cid, $ext);

            if (!$this->writeAudio(array($clean), $target)) {
                return array(
                    'status' => 'failed',
                    'error'  => '音频写入失败：' . $relative . '（检查 usr/uploads/tts 目录权限）',
                );
            }

            $chunks = $this->chunkForSynth($post['text'], $post['source']);
            $total  = count($chunks);

            $sizes = array();

            if (!empty($remote['chunk_bytes']) && is_array($remote['chunk_bytes'])) {
                foreach ($remote['chunk_bytes'] as $bytes) {
                    $sizes[] = intval($bytes);
                }
            }

            // 分块数对不上（例如网站的分块参数后来改过）→ 退化成单个「块」，至少时间轴不崩
            if ($total <= 0 || count($sizes) !== $total) {
                $sizes  = array(strlen($clean));
                $chunks = array((string) $post['text']);
                $total  = 1;
            }

            // 换过格式的话，清掉同一篇的旧格式文件（见 dropStaleAudio）
            $this->dropStaleAudio($cid, $ext);

            // 拼接完成后统一做本地变速（失败不影响出片，顶多语速是原速）
            $factor    = $this->applySpeed($target, $ext);
            $durations = $this->durationsFromSizes($sizes, $factor, $ext);

            $chapters = $this->chaptersFromHeadings($post['source'], $post['text'], $chunks, $durations);

            // 收尾完成 → 远端产物由调用方在**落库成功之后**再删（cleanup 字段）。
            // 以前在这里先删，一旦 saveJob() 失败，远端已删、本地还是 running，整篇白跑。
            return array(
                'cleanup'  => $jobId,
                'status'   => 'ready',
                'title'    => (string) $post['title'],
                'voice'    => TtsVoice_Plugin::jobVoiceTag(),
                'progress' => $total . '/' . $total,
                'duration' => (string) round(array_sum($durations), 2),
                'size'     => (int) @filesize($target),
                'chapters' => $chapters,
                'audio'    => $relative,
                'error'    => $this->formatNotice($ext),
            );
        }

        /**
         * 探测接收端作业队列（Doctor 用）
         *
         * 主链路走的是 `/jobs`（X-TtsVoice-Token），而 health() 打的是 `/v1/models`（Bearer）。
         * 两者可能一个通一个不通（token 轮换 / 反代只放行了 /v1），所以自检必须各探一次。
         *
         * @return array {ok, error, jobs}
         */
        public function probeQueue()
        {
            $response = $this->receiverRequest('GET', '/jobs', NULL, 8);

            if (NULL === $response) {
                return array('ok' => false, 'error' => '未配置接收端地址（openaiBaseUrl 为空或不是 http(s)）', 'jobs' => 0);
            }

            if (!$response['ok']) {
                return array('ok' => false, 'error' => (string) $response['error'], 'jobs' => 0);
            }

            if (200 !== $response['code']) {
                return array(
                    'ok'    => false,
                    'error' => 'HTTP ' . $response['code'] . '：' . $this->receiverError($response),
                    'jobs'  => 0,
                );
            }

            $json = json_decode((string) $response['body'], true);

            if (!is_array($json) || empty($json['ok'])) {
                return array('ok' => false, 'error' => '返回不是有效 JSON / ok=false', 'jobs' => 0);
            }

            $jobs = (isset($json['jobs']) && is_array($json['jobs'])) ? count($json['jobs']) : 0;

            return array('ok' => true, 'error' => '', 'jobs' => $jobs);
        }

        /**
         * 收尾后清理远端产物（落库成功之后调用）
         *
         * @param string $jobId
         * @return void
         */
        public function cleanup($jobId)
        {
            $jobId = (string) $jobId;

            if (0 === strpos($jobId, 'j-')) {
                $this->receiverRequest('DELETE', '/jobs/' . rawurlencode($jobId), NULL, 10);
            }
        }

        /**
         * 字节串看起来是不是音频（RIFF / ID3 / MPEG 帧同步）
         *
         * @param string $data
         * @return bool
         */
        protected function looksLikeAudio($data)
        {
            $data = (string) $data;

            if (strlen($data) < 4) {
                return false;
            }

            if ('RIFF' === substr($data, 0, 4)) {
                return true;
            }

            if ('ID3' === substr($data, 0, 3)) {
                return true;
            }

            // MPEG 音频帧同步：11 位全 1
            return "\xFF" === substr($data, 0, 1) && (ord($data[1]) & 0xE0) === 0xE0;
        }

        /**
         * 删除任务时顺手取消 TTS 主机上的远端作业
         *
         * @param array $job
         * @return bool
         */
        public function delete($job)
        {
            $jobId = (is_array($job) && isset($job['job_id'])) ? (string) $job['job_id'] : '';

            if (0 === strpos($jobId, 'j-')) {
                $this->receiverRequest('DELETE', '/jobs/' . rawurlencode($jobId), NULL, 10);
            }

            return true;
        }

    }
}

/*
 * 命名空间别名：与插件主类的「Base + 命名空间子类 + 下划线别名」结构对齐，
 * 方便后续按 TypechoPlugin\TtsVoice\Provider\* 引用。全部做存在性保护。
 */
if (!class_exists('TypechoPlugin\\TtsVoice\\Provider\\Base', false) && class_exists('TtsVoice_Provider', false)) {
    @class_alias('TtsVoice_Provider', 'TypechoPlugin\\TtsVoice\\Provider\\Base');
}

if (!class_exists('TypechoPlugin\\TtsVoice\\Provider\\Azure', false) && class_exists('TtsVoice_Provider_Azure', false)) {
    @class_alias('TtsVoice_Provider_Azure', 'TypechoPlugin\\TtsVoice\\Provider\\Azure');
}

if (!class_exists('TypechoPlugin\\TtsVoice\\Provider\\OpenAI', false) && class_exists('TtsVoice_Provider_OpenAI', false)) {
    @class_alias('TtsVoice_Provider_OpenAI', 'TypechoPlugin\\TtsVoice\\Provider\\OpenAI');
}
