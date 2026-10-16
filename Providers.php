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
 * @version 1.6.8
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
        /* 工厂                                                            */
        /* ============================================================== */

        /**
         * 按标识创建服务商
         *
         * @param string $id
         * @return TtsVoice_Provider
         */
        public static function make($id)
        {
            $id = strtolower(trim((string) $id));

            $map = array(
                'azure'  => 'TtsVoice_Provider_Azure',
                'openai' => 'TtsVoice_Provider_OpenAI',
            );

            if (isset($map[$id]) && class_exists($map[$id], false)) {
                return new $map[$id]();
            }

            // 1.6.8 起 tts-worker(local) 已彻底移除：未知/历史服务商一律回落到 Qwen3-TTS
            // （老站点库里可能还存着 'local'，设置页保存后会被自愈成 'openai'）
            return new TtsVoice_Provider_OpenAI();
        }

        /**
         * 配置下拉用：id => label
         *
         * 顺序就是界面上的顺序：**自建 Qwen3-TTS 放第一个**（默认，主力方案），Azure 第二。
         *
         * @return array
         */
        public static function all()
        {
            return array(
                'openai' => _t('自建 Qwen3-TTS（推荐）'),
                'azure'  => _t('微软 Azure 语音'),
            );
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
         * 同步型合成的超时：一次请求里要等整块音频生成，按配置倍数放大
         *
         * @return int
         */
        protected function syncTimeout()
        {
            return max(30, $this->timeout() * 4);
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
                        'method'        => $method,
                        'header'        => implode("\r\n", $headers),
                        'content'       => NULL === $payload ? '' : $payload,
                        'timeout'       => max(1, intval($timeout)),
                        'ignore_errors' => true,
                    ),
                    'ssl' => array(
                        'verify_peer'       => $verify,
                        'verify_peer_name'  => $verify,
                        'allow_self_signed' => !$verify,
                    ),
                ));

                $raw = @file_get_contents($url, false, $context);

                if (false === $raw) {
                    $err = '请求失败';
                } elseif (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header[0])) {
                    if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
                        $code = (int) $m[1];
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
         * 拼接分块音频
         *
         * mp3：逐块剥掉 ID3 后首尾相接即可（stripId3 已保证入库的块是裸流）。
         * wav：每块都是一份完整的 RIFF 文件（自带 44 字节头），直接 implode
         *      会在流中间插入非法头，播放器放到第一块结束就停 —— 所以这里
         *      只取各块的 PCM，再用第一块的 fmt 重新封一个头。
         *
         * 不假设采样率/位深：fmt 子块原样复制，只改长度字段。
         *
         * @param array $pieces
         * @return string
         */
        protected function mergePieces(array $pieces)
        {
            if (count($pieces) <= 1) {
                return implode('', $pieces);
            }

            $fmt = '';
            $pcm = '';

            foreach ($pieces as $piece) {
                $part = $this->wavPcm($piece);

                if (NULL === $part) {
                    // 有一块不是 wav（混了格式）→ 退回裸拼，至少不丢数据
                    return implode('', $pieces);
                }

                if ('' === $fmt) {
                    $fmt = $part['fmt'];
                }

                $pcm .= $part['pcm'];
            }

            if ('' === $fmt || '' === $pcm) {
                return implode('', $pieces);
            }

            return $this->wavFile($fmt, $pcm);
        }

        /**
         * 从一个 RIFF/WAVE 文件里取出 fmt 子块与 PCM 数据
         *
         * @param string $data
         * @return array|null {fmt:string, pcm:string}
         */
        protected function wavPcm($data)
        {
            $data = (string) $data;
            $len  = strlen($data);

            if ($len < 12 || 'RIFF' !== substr($data, 0, 4) || 'WAVE' !== substr($data, 8, 4)) {
                return NULL;
            }

            $offset = 12;
            $fmt    = '';
            $pcm    = '';

            while ($offset + 8 <= $len) {
                $id  = substr($data, $offset, 4);
                $size = unpack('V', substr($data, $offset + 4, 4));
                $size = intval($size[1]);

                $body = substr($data, $offset + 8, $size);

                if ('fmt ' === $id && '' === $fmt) {
                    $fmt = $body;
                } elseif ('data' === $id) {
                    $pcm .= $body;
                }

                // 子块按偶数字节对齐
                $offset += 8 + $size + ($size % 2);
            }

            if ('' === $fmt || '' === $pcm) {
                return NULL;
            }

            return array('fmt' => $fmt, 'pcm' => $pcm);
        }

        /**
         * 按 fmt 子块重新封装一份 RIFF/WAVE
         *
         * @param string $fmt
         * @param string $pcm
         * @return string
         */
        protected function wavFile($fmt, $pcm)
        {
            $fmt = (string) $fmt;
            $pcm = (string) $pcm;

            $body = 'WAVE'
                . 'fmt ' . pack('V', strlen($fmt)) . $fmt . ((strlen($fmt) % 2) ? "\x00" : '')
                . 'data' . pack('V', strlen($pcm)) . $pcm;

            return 'RIFF' . pack('V', strlen($body)) . $body;
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
    }

    /**
     * 微软 Azure 语音（同步 REST）
     */
    class TtsVoice_Provider_Azure extends TtsVoice_Provider
    {
        /**
         * @return string
         */
        public function id()
        {
            return 'azure';
        }

        /**
         * @return string
         */
        public function label()
        {
            return _t('微软 Azure 语音');
        }

        /**
         * @return string
         */
        public function endpoint()
        {
            $endpoint = trim((string) $this->opt('azureEndpoint', ''));

            if ('' !== $endpoint) {
                return rtrim($endpoint, '/');
            }

            $region = trim((string) $this->opt('azureRegion', 'eastasia'));

            if ('' === $region) {
                $region = 'eastasia';
            }

            return 'https://' . $region . '.tts.speech.microsoft.com';
        }

        /**
         * 当前音色
         *
         * @return string
         */
        protected function voice()
        {
            $voice = trim((string) $this->opt('azureVoice', 'zh-CN-XiaoxiaoNeural'));

            return '' === $voice ? 'zh-CN-XiaoxiaoNeural' : $voice;
        }

        /**
         * 输出格式
         *
         * @return string
         */
        protected function outputFormat()
        {
            $format = trim((string) $this->opt('azureFormat', 'audio-24khz-48kbitrate-mono-mp3'));

            return '' === $format ? 'audio-24khz-48kbitrate-mono-mp3' : $format;
        }

        /**
         * 轻量鉴权自检：合成一个极短文本（200 即视为可用）
         *
         * @return array
         */
        public function health()
        {
            $start = microtime(true);
            $test  = $this->synthesize('。');
            $ms    = round((microtime(true) - $start) * 1000);

            if (!$test['ok']) {
                return array('ok' => false, 'error' => $test['error'], 'ms' => $ms, 'data' => NULL);
            }

            return array(
                'ok'    => true,
                'error' => '',
                'ms'    => $ms,
                'data'  => array('voice' => $this->voice(), 'format' => $this->outputFormat(), 'bytes' => strlen($test['audio'])),
            );
        }

        /**
         * Azure 不提供按 Key 拉音色的列表接口（公开列表在文档里），
         * 这里只回显当前配置并给出说明。
         *
         * @return array
         */
        public function voices()
        {
            return array(
                'ok'     => true,
                'error'  => '',
                'voices' => array($this->voice()),
                'note'   => 'Azure 音色由「azureVoice」配置决定，完整列表见 Azure 语音文档（zh-CN-XiaoxiaoNeural 等）；此处仅回显当前配置。',
            );
        }

        /**
         * 逐块合成 → 去 ID3 → 拼接 → 落库
         *
         * @param int    $cid
         * @param string $title
         * @param string $text
         * @param string $sourceText
         * @return array
         */
        public function submit($cid, $title, $text, $sourceText = '')
        {
            return $this->synthesizeAll($cid, $title, $text, $sourceText);
        }

        /**
         * @param array $job
         * @return array|null
         */
        public function poll($job)
        {
            // 同步服务提交时已经 ready，无需轮询
            return null;
        }

        /**
         * 同步服务没有「远程任务」，删除只需要清理本地（由 Action 负责）
         *
         * @param array $job
         * @return bool
         */
        public function delete($job)
        {
            return true;
        }

        /**
         * 单块合成
         *
         * @param string $text
         * @return array {ok:bool, error:string, audio:string}
         */
        protected function synthesize($text)
        {
            $key = trim((string) $this->opt('azureKey', ''));

            if ('' === $key) {
                return array('ok' => false, 'error' => '未配置 azureKey', 'audio' => '');
            }

            $headers = array(
                'Ocp-Apim-Subscription-Key: ' . $key,
                'Content-Type: application/ssml+xml',
                'X-Microsoft-OutputFormat: ' . $this->outputFormat(),
                'User-Agent: TtsVoice',
            );

            $response = $this->httpRequest(
                'POST',
                $this->endpoint() . '/cognitiveservices/v1',
                $headers,
                $this->ssml($text),
                $this->syncTimeout(),
                $this->timeout()
            );

            if (!$response['ok']) {
                return array('ok' => false, 'error' => $response['error'], 'audio' => '');
            }

            if (200 !== $response['code']) {
                $message = trim(substr(strip_tags($response['body']), 0, 300));

                return array(
                    'ok'    => false,
                    'error' => 'HTTP ' . $response['code'] . ('' === $message ? '' : ' ' . $message),
                    'audio' => '',
                );
            }

            if ('' === $response['body']) {
                return array('ok' => false, 'error' => 'HTTP 200 但音频为空', 'audio' => '');
            }

            return array('ok' => true, 'error' => '', 'audio' => $response['body']);
        }

        /**
         * 生成 SSML 请求体
         *
         * @param string $text
         * @return string
         */
        protected function ssml($text)
        {
            $voice = $this->voice();

            $rate = trim((string) $this->opt('azureRate', '0%'));
            $rate = '' === $rate ? '0%' : $rate;

            $pitch = trim((string) $this->opt('azurePitch', '0%'));
            $pitch = '' === $pitch ? '0%' : $pitch;

            $style = trim((string) $this->opt('azureStyle', ''));

            $esc = function ($value) {
                return htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');
            };

            $inner = "<prosody rate='" . $esc($rate) . "' pitch='" . $esc($pitch) . "'>" . $esc($text) . "</prosody>";

            if ('' !== $style) {
                $inner = "<mstts:express-as style='" . $esc($style) . "'>" . $inner . "</mstts:express-as>";
            }

            return "<speak version='1.0' xml:lang='zh-CN'"
                . " xmlns='http://www.w3.org/2001/10/synthesis'"
                . " xmlns:mstts='https://www.w3.org/2001/mstts'>"
                . "<voice name='" . $esc($voice) . "'>" . $inner . "</voice></speak>";
        }

        /**
         * 同步服务通用流程：分块 → 合成 → 拼接 → 写库
         *
         * @param int    $cid
         * @param string $title
         * @param string $text
         * @param string $sourceText
         * @param int    $fallbackKbps
         * @param bool   $estimate    时长是否为估算（OpenAI 用）
         * @return array
         */
        protected function synthesizeAll($cid, $title, $text, $sourceText = '', $fallbackKbps = 48, $estimate = false)
        {
            $cid    = intval($cid);
            $chunks = $this->chunkForSynth($text, $sourceText);

            if (empty($chunks)) {
                return $this->fail('正文清洗后为空，无法合成');
            }

            $pieces = array();
            $sizes  = array();
            $total  = count($chunks);

            foreach ($chunks as $i => $chunk) {
                $result = $this->synthesizeChunk($chunk);

                if (!$result['ok']) {
                    return $this->fail('第 ' . ($i + 1) . '/' . $total . ' 块合成失败：' . $result['error']);
                }

                // synthesizeChunk 已经去过 ID3
                $clean = (string) $result['audio'];

                if ('' === $clean) {
                    return $this->fail('第 ' . ($i + 1) . '/' . $total . ' 块音频为空');
                }

                $pieces[] = $clean;
                $sizes[]  = strlen($clean);
            }

            $relative = $this->audioRelative($cid);
            $target   = $this->audioTarget($cid);

            if (!$this->writeAudio($pieces, $target)) {
                return $this->fail('音频写入失败：' . $relative . '（检查 usr/uploads/tts 目录权限）');
            }

            $kbps = $this->bitrateKbps($this->outputFormatForBitrate(), $fallbackKbps);

            $durations = array();

            foreach ($sizes as $bytes) {
                $durations[] = $this->estimateDuration($bytes, $kbps);
            }

            $duration = round(array_sum($durations), 2);
            $size     = (int) @filesize($target);
            $chapters = $this->chaptersFromHeadings($sourceText, $text, $chunks, $durations);

            $patch = array(
                'job_id'   => '',
                'status'   => 'ready',
                'title'    => (string) $title,
                'voice'    => $this->voiceLabel(),
                'speed'    => '',
                'progress' => $total . '/' . $total,
                'duration' => (string) $duration,
                'size'     => $size,
                'chapters' => $chapters,
                'audio'    => $relative,
                'error'    => '',
            );

            // 同步型在 submit 内直接落库为 ready
            TtsVoice_Plugin::saveJob($cid, $patch);

            return array(
                'ok'       => true,
                'error'    => '',
                'jobId'    => '',
                'status'   => 'ready',
                'audio'    => $relative,
                'duration' => $duration,
                'size'     => $size,
                'chapters' => $chapters,
                'progress' => $total . '/' . $total,
                'voice'    => $this->voiceLabel(),
                'estimate' => (bool) $estimate,
            );
        }

        /**
         * 用于解析码率的格式（Azure 取 azureFormat）
         *
         * @return string
         */
        protected function outputFormatForBitrate()
        {
            return $this->outputFormat();
        }

        /**
         * 写入任务的 voice 字段
         *
         * @return string
         */
        protected function voiceLabel()
        {
            return $this->voice();
        }

        /**
         * 统一失败返回
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
    }

    /**
     * OpenAI 兼容 TTS（同步 REST，POST {base}/audio/speech）
     */
    class TtsVoice_Provider_OpenAI extends TtsVoice_Provider_Azure
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
         * 服务类型 —— 固定为本地 Qwen3-TTS
         *
         * 设置页已去掉「服务类型」选项（2026-09-13，用户要求），这个服务商现在就是自建 Qwen3-TTS。
         * 库里残留的 openaiFlavor 键不再影响行为（隐藏键，仅为兼容核心的表单渲染）。
         *
         * @return string
         */
        protected function flavor()
        {
            return 'qwen3tts';
        }

        /**
         * 该服务类型的**真实**音色列表（实测/官方文档）
         *
         * @return array
         */
        protected function builtinVoices()
        {
            if ('openai' === $this->flavor()) {
                return array('alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer',
                             'ash', 'ballad', 'coral', 'sage', 'verse');
            }

            // 自建 Qwen3-TTS：**没有预置音色**。插件只支持克隆，
            // 音色完全由「音色样本」决定，所以这里没有任何候选名。
            return array();
        }

        /**
         * @return string
         */
        protected function voice()
        {
            // 注意：自建 Qwen3-TTS 现在**恒走克隆**，这个 voice() 只服务于
            // 云端 OpenAI 分支（flavor 不是 qwen3tts）。openaiVoice 这个键已经从
            // 配置里删掉，所以这里基本只会走到默认值。
            $voice    = trim((string) $this->opt('openaiVoice', 'alloy'));
            $fallback = ('openai' === $this->flavor()) ? 'alloy' : 'uncle_fu';

            // 兼容老站点库里残留的 'custom'：它不是真实说话人名，直接换成默认值。
            if ('' === $voice || 'custom' === strtolower($voice)) {
                return $fallback;
            }

            return $voice;
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
            return array(
                'temperature'        => floatval($this->opt('openaiTemperature', '0.7')),
                'top_p'              => floatval($this->opt('openaiTopP', '0.9')),
                'top_k'              => intval($this->opt('openaiTopK', '40')),
                'repetition_penalty' => floatval($this->opt('openaiRepPenalty', '1.05')),
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
         * 按实际字节判断容器格式
         *
         * 【为什么需要】接收端如果还没升级（v1.3.0 只吐 mp3），而网站配置成了 wav，
         * 就会把 mp3 字节写进 `.wav` 文件 —— 后缀和内容不一致，播放器可能不认，
         * 章节时长也会按 48000 B/s 算错。所以收尾时一律以**真实字节**为准。
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
         * @return string
         */
        protected function model()
        {
            $model = trim((string) $this->opt('openaiModel', 'gpt-4o-mini-tts'));

            return '' === $model ? 'gpt-4o-mini-tts' : $model;
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
         * 当前这次请求实际会用的模型
         *
         * 自建 Qwen3-TTS：**恒为 Base**（克隆是唯一的模式 —— 预置音色已下线，
         * 音色只能来自参考音频）。云端 OpenAI 分支（flavor 不是 qwen3tts）仍用 openaiModel。
         *
         * @return string
         */
        protected function activeModel()
        {
            if ('qwen3tts' !== $this->flavor()) {
                return $this->model();
            }

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
            $key   = trim((string) $this->opt('openaiKey', ''));
            $local = ('qwen3tts' === $this->flavor());

            // 自建 Qwen3-TTS 通常不鉴权，允许 Key 留空
            if ('' === $key && !$local) {
                return array('ok' => false, 'error' => '未配置 openaiKey', 'ms' => 0, 'data' => NULL);
            }

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
            $count   = (is_array($decoded) && isset($decoded['data']) && is_array($decoded['data']))
                ? count($decoded['data'])
                : 0;

            return array(
                'ok'    => true,
                'error' => '',
                'ms'    => $ms,
                'data'  => array(
                    'models'       => $count,
                    'model'        => $this->activeModel(),
                    'mode'         => ('qwen3tts' === $this->flavor())
                        ? (TtsVoice_Plugin::hasCloneVoice() ? 'clone(Base)' : 'no-sample')
                        : 'openai',
                    'cloneModel'   => $this->cloneModel(),
                    'hasSample'    => TtsVoice_Plugin::hasCloneVoice(),
                ),
            );
        }

        /**
         * @return array
         */
        public function voices()
        {
            $flavor  = $this->flavor();

            // 自建 Qwen3-TTS：只有克隆，没有可枚举的预置音色
            if ('qwen3tts' === $flavor) {
                return array(
                    'ok'     => true,
                    'error'  => '',
                    'voices' => array(),
                    'note'   => '自建 Qwen3-TTS 只支持「自定义音色」：没有预置音色列表，'
                                . '音色完全由上传的「音色样本」决定。',
                );
            }

            $builtin = $this->builtinVoices();
            $label   = ('openai' === $flavor) ? 'OpenAI 官方' : '自定义';

            return array(
                'ok'     => true,
                'error'  => '',
                'voices' => $builtin,
                'note'   => $label . '音色 ' . count($builtin) . ' 个',
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
         * 是否走异步任务模型
         *
         * 自建 Qwen3-TTS 在本机 GPU 上约等于实时（实测 RTF≈1.15，3000 字要 11 分钟）。
         * 同步等完会把 PHP / nginx 的连接一直挂着，用户还得在文章页干等 —— 所以走异步。
         *
         * 真正的 OpenAI 云端服务很快，保持同步更简单，也省一次轮询往返。
         *
         * @return bool
         */
        public function isAsync()
        {
            return ('qwen3tts' === $this->flavor());
        }

        /**
         * 提交任务
         *
         * 异步（qwen3tts）：**不在这个请求里合成**，只切块 + 落一条 queued 任务就返回。
         * 真正的合成交给 poll() —— 浏览器轮询一次就合成一块。
         *
         * 所以文章页点「生成」是立刻返回的，不用等 11 分钟；
         * 而且关掉页面也不丢进度（状态都在 tts_jobs 表里，下次打开接着跑）。
         *
         * @param int    $cid
         * @param string $title
         * @param string $text
         * @param string $sourceText
         * @return array
         */
        public function submit($cid, $title, $text, $sourceText = '')
        {
            if (!$this->isAsync()) {
                // 云端 OpenAI：mp3 无固定码率，时长按 128 kbps 估算（返回里标 estimate）
                return $this->synthesizeAll($cid, $title, $text, $sourceText, 128, true);
            }

            $cid    = intval($cid);
            $chunks = $this->chunkForSynth($text, $sourceText);

            if (empty($chunks)) {
                return $this->fail('正文清洗后为空，无法合成');
            }

            // ① 首选：交给 TTS 主机上的队列（接收端 v1.3.0+ 的 /jobs）。
            //    合成跑在服务商侧，浏览器/网站关掉任务也会继续 → 不再依赖后台页面在线。
            //    返回 null 表示「这台接收端没有 /jobs」（老版本，或直连 Qwen），走下面的回退。
            $remote = $this->submitRemoteJob($cid, $title, $chunks);

            if (NULL !== $remote) {
                return $remote;
            }

            // ② 回退：老的「浏览器驱动、每次轮询跑一块」
            return $this->submitLocalJob($cid, $title, $chunks);
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
             * 【1.6.8 起音色样本可选】TTS 主机上有一份默认音色（接收端的 default 来源），
             * 所以「网站没上传样本」不再拦：不带 ref_audio / source 提交，
             * 接收端会自动回落到它自己的 <dir>/default/ref.wav。
             *
             * 上传过样本时保持原行为：ref_audio 必须是 **TTS 主机上** 的路径
             * （接收端会校验存在性），并带上 source 以便多站点对账。
             */
            $refAudio = trim((string) TtsVoice_Plugin::refAudioForHost());

            if ('' !== $refAudio
                && '' === trim((string) TtsVoice_Plugin::opt('refAudioPath', ''))
                && '' !== trim((string) TtsVoice_Plugin::refUploadUrl())) {
                // 样本只在**网站这台机器**上、还没推到 TTS 主机 —— 提前说清怎么修，
                // 否则远端只会回一句 "ref_audio not found"。
                return $this->fail(
                    '音色样本还没推送到 TTS 主机（当前只有网站本机的路径）。'
                    . '请到「设置 → TtsVoice → 音色样本」点「重新同步到 TTS 主机」，或重新上传一次样本。'
                );
            }

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

            // 老路径可能留下过块文件，清掉免得占地方
            $this->clearParts($cid);

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
         * 老路径：浏览器驱动，每次 poll() 在本请求里跑一块（接收端没有 /jobs 时的回退）
         *
         * @param int    $cid
         * @param string $title
         * @param array  $chunks
         * @return array
         */
        protected function submitLocalJob($cid, $title, array $chunks)
        {
            // 【1.6.8 起样本可选】没上传样本就不带 ref_audio，由接收端用它自己的
            // 默认音色（<dir>/default/ref.wav）；上传过才校验路径。
            //
            // 参考音频必须在「TTS 主机」上真的存在。
            //
            // 【跨机时不要在本机 is_file】refAudioPath 是 **TTS 主机上**的路径，
            // 网站和 TTS 不在同一台机器时，本机当然看不到这个文件 ——
            // 硬判 is_file 会把「跨机 + 远端文件其实存在」误判成失败。
            // 所以：配了接收端就交给服务端判（它读的是自己本机路径）；
            // 只有「同机 / 共享存储」（没配接收端）才在本机提前挡一下。
            $refFile = TtsVoice_Plugin::refAudioForHost();

            if ('' !== $refFile
                && '' === trim((string) TtsVoice_Plugin::refUploadUrl())
                && !is_file($refFile)) {
                return $this->fail(
                    '音色样本文件不存在：' . $refFile . '。请到「设置 → TtsVoice → 音色样本」重新上传。'
                );
            }

            // 清掉上一轮的半成品，避免和这次混在一起
            $this->clearParts($cid);

            $total = count($chunks);
            $patch = array(
                'job_id'   => 'qwen-' . $cid . '-' . time(),
                'status'   => 'queued',
                'title'    => (string) $title,
                'voice'    => TtsVoice_Plugin::jobVoiceTag(),
                'speed'    => '',
                'progress' => '0/' . $total,
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
                'status'   => 'queued',
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
         * 轮询推进（异步泵）
         *
         * 每次被调用只做**一块**：合成 → 去 ID3 → 存成单独的块文件 → 进度 +1。
         *
         * 块文件分开存（而不是追加到同一个文件）是为了收尾时能拿到每块的字节数 ——
         * 章节时间轴要靠它算每块的时长。代价是多几个小文件，收尾时统一清掉。
         *
         * 分块默认 180 字（约 40 秒），稳稳落在 nginx 默认的 60 秒 FastCGI 超时内。
         *
         * @param array $job
         * @return array|null
         */
        public function poll($job)
        {
            if (!$this->isAsync()) {
                return null;
            }

            $job    = is_array($job) ? $job : array();
            $cid    = intval(isset($job['cid']) ? $job['cid'] : 0);
            $status = (string) (isset($job['status']) ? $job['status'] : '');

            if ($cid <= 0 || in_array($status, array('ready', 'failed'), true)) {
                return null;
            }

            // 【服务商侧队列】（接收端 v1.3.0+ 的 /jobs）
            // 合成在 TTS 主机上跑，这里只做两件轻活：查状态；就绪后下载收尾。
            // 所以浏览器关掉 / 页面跳走都不影响任务 —— 下次谁来查都能接上。
            $jobId = isset($job['job_id']) ? (string) $job['job_id'] : '';

            if (0 === strpos($jobId, 'j-')) {
                return $this->pollRemoteJob($cid, $jobId, $job);
            }

            // 【每次 HTTP 请求只推进一块】
            //
            // 面板列表页走的是 batch 接口，一次会查很多篇。如果每篇都推进一块，
            // 10 篇就是 10 块 ≈ 400 秒，必然撞上 nginx 默认的 60 秒 FastCGI 超时。
            // 用请求级 static 限流：本次请求已经干过活就不再干，剩下的留给下一次轮询
            // —— 轮询本来就是持续在跑的，整体速度不受影响，只是把工作摊平。
            static $pumpedThisRequest = false;

            if ($pumpedThisRequest) {
                return null;
            }

            // poll() 只拿得到任务行，没有正文 —— 按 cid 读回来重算分块。
            // 分块是确定性的（同样的正文 + 同样的 chunkChars → 同样的块），
            // 所以不用把分块结果存进数据库，也就不用改表结构。
            $post = TtsVoice_Plugin::postForTts($cid);

            if (!$post) {
                return array('status' => 'failed', 'error' => '文章不存在或已被删除');
            }

            $chunks = $this->chunkForSynth($post['text'], $post['source']);
            $total  = count($chunks);

            if (0 === $total) {
                return array('status' => 'failed', 'error' => '正文清洗后为空');
            }

            // 进度存在 progress 的 "done/total" 里
            $done = 0;

            if (preg_match('#^(\d+)/#', (string) (isset($job['progress']) ? $job['progress'] : ''), $m)) {
                $done = intval($m[1]);
            }

            // 块都齐了 → 收尾（正常情况下这里是兜底，不会走到）
            if ($done >= $total) {
                return $this->finishAsync($cid, $post, $chunks);
            }

            $result = $this->synthesizeChunk($chunks[$done]);

            // 从这一刻起本次请求已经占用了「一块」的预算，后面的任务不再推进
            $pumpedThisRequest = true;

            if (empty($result['ok'])) {
                return array(
                    'status' => 'failed',
                    'error'  => '第 ' . ($done + 1) . '/' . $total . ' 块合成失败：'
                                . (isset($result['error']) ? $result['error'] : '未知错误'),
                );
            }

            // synthesizeChunk 返回的已经是去掉 ID3 的音频
            $clean = (string) $result['audio'];

            if ('' === $clean) {
                return array('status' => 'failed', 'error' => '第 ' . ($done + 1) . '/' . $total . ' 块音频为空');
            }

            $part = $this->partFile($cid, $done);
            $dir  = dirname($part);

            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return array('status' => 'failed', 'error' => '无法创建目录 ' . $dir);
            }

            if (false === @file_put_contents($part, $clean)) {
                return array('status' => 'failed', 'error' => '音频写入失败：' . $part);
            }

            $done++;

            // 还有块没跑完 → 只报进度，等下一次轮询继续
            if ($done < $total) {
                return array('status' => 'running', 'progress' => $done . '/' . $total);
            }

            return $this->finishAsync($cid, $post, $chunks);
        }

        /**
         * 收尾：拼接所有块 → 落成最终音频 → 算时长与章节 → 清掉临时块
         *
         * @param int   $cid
         * @param array $post
         * @param array $chunks
         * @return array
         */
        protected function finishAsync($cid, array $post, array $chunks)
        {
            $total  = count($chunks);
            $pieces = array();
            $sizes  = array();

            for ($i = 0; $i < $total; $i++) {
                $part = $this->partFile($cid, $i);

                if (!is_file($part)) {
                    return array(
                        'status' => 'failed',
                        'error'  => '第 ' . ($i + 1) . '/' . $total . ' 块缺失，请重新生成',
                    );
                }

                $data = @file_get_contents($part);

                if (false === $data || '' === $data) {
                    return array('status' => 'failed', 'error' => '第 ' . ($i + 1) . ' 块读取失败');
                }

                $pieces[] = $data;
                $sizes[]  = strlen($data);
            }

            // 以第一块的**真实字节**定容器（上游/接收端可能不按 response_format 给）
            $ext      = $this->sniffExtension($pieces[0]);
            $relative = $this->audioRelative($cid, $ext);
            $target   = $this->audioTarget($cid, $ext);

            if (!$this->writeAudio($pieces, $target)) {
                return array(
                    'status' => 'failed',
                    'error'  => '音频写入失败：' . $relative . '（检查 usr/uploads/tts 目录权限）',
                );
            }

            // 换过格式的话，清掉同一篇的旧格式文件（见 dropStaleAudio）
            $this->dropStaleAudio($cid, $ext);

            // 拼接完成后统一做本地变速（失败不影响出片，顶多语速是原速）
            $factor    = $this->applySpeed($target, $ext);
            $durations = $this->durationsFromSizes($sizes, $factor, $ext);

            $chapters = $this->chaptersFromHeadings($post['source'], $post['text'], $chunks, $durations);

            $this->clearParts($cid);

            return array(
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
         * 典型：Lucky / Nginx 回 `{"msg":"target url [...]: invalid port \": 8899\" after host"}`。
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
                    . '正确值应指向接收端，例如 http://<主机>:8899/v1'
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
         * 一块文本要多少 max_tokens
         *
         * 标定见基类常量：`max(400, 字数 × 10)`。
         * 网站与 TTS 主机两侧必须用同一个公式，否则退化判定和截断行为会对不上。
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

            if (200 !== $response['code'] || !is_array($json) || empty($json['ok'])) {
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

            return NULL;
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

            // 收尾完成 → 让 TTS 主机清掉产物（best effort，失败不影响本地）
            $this->receiverRequest('DELETE', '/jobs/' . rawurlencode($jobId), NULL, 10);

            $this->clearParts($cid);

            return array(
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

        /**
         * 临时块目录
         *
         * @return string
         */
        protected function partDir()
        {
            return TtsVoice_Plugin::audioPath() . '/_part';
        }

        /**
         * 单块临时文件路径
         *
         * @param int $cid
         * @param int $index
         * @return string
         */
        protected function partFile($cid, $index)
        {
            return $this->partDir() . '/' . intval($cid) . '.' . intval($index) . '.bin';
        }

        /**
         * 清掉某个任务的全部临时块
         *
         * @param int $cid
         * @return void
         */
        protected function clearParts($cid)
        {
            $files = @glob($this->partDir() . '/' . intval($cid) . '.*.bin');

            if (is_array($files)) {
                foreach ($files as $file) {
                    @unlink($file);
                }
            }
        }

        /**
         * 单块合成
         *
         * @param string $text
         * @return array {ok:bool, error:string, audio:string}
         */
        protected function synthesizeAs($text, $maxTokens, $format)
        {
            $key   = trim((string) $this->opt('openaiKey', ''));
            $local = ('qwen3tts' === $this->flavor());

            // 自建 Qwen3-TTS 通常不鉴权，允许 Key 留空
            if ('' === $key && !$local) {
                return array('ok' => false, 'error' => '未配置 openaiKey', 'audio' => '');
            }

            $payload = array(
                'input'           => (string) $text,
                'response_format' => (string) $format,
                // 自建 Qwen3-TTS：上游的 speed 是空实现（传了不生效），变速交给
                // 本地 ffmpeg atempo，这里恒为 1.0，免得将来上游实现了变成双重变速。
                // 云端 OpenAI：speed 是真的（0.25~4.0），照旧传。
                'speed'           => $local ? 1.0 : $this->speedFactor(),
            );

            // 自建 Qwen3-TTS：显式给出采样参数，绕开上游「repetition_penalty 被压成 1.0」
            // 导致的整段退化。云端 OpenAI 不认这些字段，不能带。
            if ($local) {
                $payload += $this->sampling();
            }

            // 显式给出 max_tokens：
            //   1. 封顶「退化时一路生成到上限」的损失
            //   2. 让退化可判定 —— 退化输出时长会正好等于 max_tokens × 0.08
            // 详见基类里 SECONDS_PER_TOKEN / TOKENS_PER_CHAR 的注释。
            if ($maxTokens > 0) {
                $payload['max_tokens'] = (int) $maxTokens;
            }

            // 【模型与音色：自建 Qwen3-TTS 恒为克隆】
            //
            // Base + ref_audio，**绝不带 voice**（Base 会静默忽略 voice，
            // 让人以为「克隆没生效」）。参考文本 ref_text 可选：填了走 ICL，
            // 内容必须与音频逐字一致，否则会引入杂音（详见设置页说明）。
            //
            // 云端 OpenAI 没有「克隆」这个概念，仍用 openaiModel + voice。
            if ($local) {
                $payload['model']     = $this->cloneModel();
                $payload['ref_audio'] = TtsVoice_Plugin::refAudioForHost();

                $refText = TtsVoice_Plugin::refText();

                if ('' !== $refText) {
                    $payload['ref_text'] = $refText;
                }
            } else {
                $payload['model'] = $this->model();
                $payload['voice'] = $this->voice();
            }

            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $headers = array(
                'Content-Type: application/json',
                // 跟着 response_format 走：上游按 Accept 协商时，声明 mp3 却要 wav 会被拒
                'Accept: ' . ('wav' === strtolower((string) $format) ? 'audio/wav' : 'audio/mpeg'),
            );

            if ('' !== $key) {
                array_unshift($headers, 'Authorization: Bearer ' . $key);
            }

            $response = $this->httpRequest(
                'POST',
                $this->endpoint() . '/audio/speech',
                $headers,
                $payload,
                $this->syncTimeout(),
                $this->timeout()
            );

            if (!$response['ok']) {
                // 网络错误/超时最常见的原因之一就是「模型在冷加载」。
                // 接收端 v1.2.0+ 的 /voice/status 能告诉我们当前哪个模型没驻留，
                // 查得到就补一句人话提示 —— 区分「这次慢是冷加载」和「真的坏了」。
                return array(
                    'ok'    => false,
                    'error' => $response['error'] . TtsVoice_Plugin::coldModelHint(),
                    'audio' => '',
                    'cold'  => false,
                );
            }

            if (200 !== $response['code']) {
                $message = trim(substr(strip_tags($response['body']), 0, 300));

                // 鉴权类错误单独给一条能照着做的提示。
                // 自建服务常见两种情况：开了鉴权但插件没填密钥，或填的密钥对不上。
                // （服务端如果没开鉴权，插件留空即可，根本不会走到这里。）
                if (in_array($response['code'], array(401, 403), true)) {
                    $sent = trim((string) $this->opt('openaiKey', ''));

                    $hint = ('' === $sent)
                        ? '服务端要鉴权，但「Qwen 鉴权 Key」是空的 —— 请填上它要求的密钥；'
                          . '若服务端本来没开鉴权，检查是不是中间的反向代理在强制校验。'
                        : '密钥被拒绝 —— 检查「Qwen 鉴权 Key」与服务端是否完全一致（注意多余空格）。';

                    return array(
                        'ok'    => false,
                        'error' => 'HTTP ' . $response['code'] . ' 鉴权失败：' . $hint
                                   . ('' === $message ? '' : '　服务端返回：' . $message),
                        'audio' => '',
                        'cold'  => false,
                    );
                }

                return array(
                    'ok'    => false,
                    'error' => 'HTTP ' . $response['code'] . ('' === $message ? '' : ' ' . $message)
                               . ($this->isColdResponse($response) ? TtsVoice_Plugin::coldModelHint(true) : ''),
                    'audio' => '',
                    'cold'  => $this->isColdResponse($response),
                );
            }
            if ('' === $response['body']) {
                return array('ok' => false, 'error' => 'HTTP 200 但音频为空', 'audio' => '', 'cold' => false);
            }

            return array(
                'ok'     => true,
                'error'  => '',
                'audio'  => $response['body'],
                'format' => (string) $format,
                // 接收端在转发前判定的「本次是否冷加载」（v1.2.0+ 才有的响应头）
                'cold'   => $this->isColdResponse($response),
            );
        }

        /**
         * 合成一块（对外入口）：**请求的格式拿不到就退回 WAV**
         *
         * 【为什么要有这个兜底】服务端（接收端 / mlx-audio 那台机器）如果没有 ffmpeg，
         * 就编不出 mp3 —— 以前这种情况下整块直接失败，用户拿不到任何音频。
         * 而 WAV 是**不需要 ffmpeg 就能产出**的（上游直接写 PCM）。
         * 所以：按设置的格式请求 → 如果服务端明确表示「这个格式我编不出来」→
         * 自动改用 wav 再试一次，成功就照用，并在结果里带一句 notice 让面板提醒。
         *
         * 队列模式（/jobs）不需要这层：那边返回什么格式，网站收尾时会按**真实字节**
         * 定后缀（见 sniffExtension），本来就不会因为格式不符而失败。
         *
         * @param string $text
         * @param int    $maxTokens
         * @return array
         */
        protected function synthesize($text, $maxTokens = 0)
        {
            $wanted = $this->outputFormat();
            $result = $this->synthesizeAs($text, $maxTokens, $wanted);

            // 已经要的就是 wav，或者成功了，就没什么好退的
            if (!empty($result['ok']) || 'wav' === strtolower($wanted)) {
                return $result;
            }

            // 只有「看起来是格式/编码问题」才值得换 wav 重试；鉴权、超时之类重试没意义
            if (!$this->isFormatUnsupported($result)) {
                return $result;
            }

            $retry = $this->synthesizeAs($text, $maxTokens, 'wav');

            if (!empty($retry['ok'])) {
                $retry['notice'] = '服务端编不出 ' . strtoupper($wanted)
                    . '（多半是那台机器没装 ffmpeg），已自动改用 WAV —— 播放与下载都正常，'
                    . '只是体积会大一些。';

                return $retry;
            }

            // 换 wav 也不行：把**第一次**的错误报出来（那才是根因）
            return $result;
        }

        /**
         * 失败原因看起来是不是「这个格式编不出来」
         *
         * 只看关键词，宁可多试一次 wav（代价是一次请求），也不要漏掉兜底。
         *
         * @param array $result synthesizeAs() 的返回
         * @return bool
         */
        protected function isFormatUnsupported($result)
        {
            $hay = strtolower((string) (isset($result['error']) ? $result['error'] : ''));
            $hay .= ' ' . strtolower((string) (isset($result['detail']) ? $result['detail'] : ''));

            foreach (array('ffmpeg', 'unsupported', 'response_format', 'format not',
                           'encoder', 'encode', '无法编码', '编码失败', '转码') as $needle) {
                if (false !== strpos($hay, $needle)) {
                    return true;
                }
            }

            return false;
        }

        /**
         * 响应头里有没有「本次冷加载」标记
         *
         * 接收端 v1.2.0+ 会在合成响应上带 `X-TtsVoice-Model-Cold`（转发前判定）。
         * 旧接收端没有这个头，返回 false —— 不影响任何行为。
         *
         * @param array $response httpRequest() 的返回
         * @return bool
         */
        protected function isColdResponse($response)
        {
            if (empty($response['headers']) || !is_array($response['headers'])) {
                return false;
            }

            $value = strtolower(trim((string) (isset($response['headers']['x-ttsvoice-model-cold'])
                ? $response['headers']['x-ttsvoice-model-cold']
                : '')));

            if ('' === $value) {
                return false;
            }

            return !in_array($value, array('0', 'false', 'no', 'off'), true);
        }

        /**
         * 合成一块，带「退化检测 + 重试」
         *
         * 【为什么会退化】模型有个确定性的失败模式：一直生成静音 token 直到把
         * max_tokens 用尽。随机采样只是**有时能逃出来**（实测约 2/3），
         * 所以重试是有效的；而 temperature=0（贪心解码）每次都会掉进去，绝不能设。
         *
         * 【判据为什么可靠】退化输出的时长**正好**等于 max_tokens × 0.08 秒
         * （12.5 token/秒），不是「超过某个经验值」，而是撞到硬上限 —— 几乎不会误判。
         *
         * @param string $text
         * @return array {ok, error, audio(已去 ID3), seconds, attempts, degenerate}
         */
        protected function synthesizeChunk($text)
        {
            // 与提交给 TTS 主机队列的 max_tokens 用同一个公式（maxTokensFor）
            $maxTokens  = $this->maxTokensFor($text);
            $capSeconds = $maxTokens * self::SECONDS_PER_TOKEN;
            $kbps = $this->bitrateKbps($this->outputFormatForBitrate(), 128);

            $lastError = '';
            $attempt = 0;

            while ($attempt < self::SYNTH_ATTEMPTS) {
                $attempt++;

                $result = $this->synthesize($text, $maxTokens);

                if (empty($result['ok'])) {
                    $lastError = isset($result['error']) ? (string) $result['error'] : '未知错误';

                    // 鉴权/配置类错误重试也没用，直接退出
                    if (false !== strpos($lastError, '鉴权失败')) {
                        break;
                    }

                    continue;
                }

                $clean = $this->stripId3($result['audio']);

                if ('' === $clean) {
                    $lastError = '音频为空';
                    continue;
                }

                $seconds = $this->estimateDuration(strlen($clean), $kbps, $this->sniffExtension($clean));

                if ($seconds >= $capSeconds * self::DEGENERATE_RATIO) {
                    // 顶到上限 → 判定退化（整段静音），换一次采样重来
                    $lastError = '输出退化（' . round($seconds) . ' 秒，顶到 ' . round($capSeconds) . ' 秒上限）';
                    continue;
                }

                return array(
                    'ok'         => true,
                    'error'      => '',
                    'audio'      => $clean,
                    'seconds'    => $seconds,
                    'attempts'   => $attempt,
                    'degenerate' => false,
                    // 接收端 v1.2.0+ 的 X-TtsVoice-Model-Cold（本次是否冷加载）
                    'cold'       => !empty($result['cold']),
                    // 服务端编不出设置里的格式、自动退回 WAV 时的说明（见 synthesize()）
                    'notice'     => isset($result['notice']) ? (string) $result['notice'] : '',
                );
            }

            return array(
                'ok'         => false,
                'error'      => ('' === $lastError ? '未知错误' : $lastError)
                                . '（已重试 ' . $attempt . ' 次）',
                'audio'      => '',
                'seconds'    => 0,
                'attempts'   => $attempt,
                'degenerate' => true,
            );
        }

        /**
         * 覆盖父类，避免把 Azure 的 SSML 逻辑误用到 OpenAI
         *
         * @param string $text
         * @return string
         */
        protected function ssml($text)
        {
            return (string) $text;
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
