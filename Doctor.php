<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';
require_once dirname(__FILE__) . '/Providers.php';

/**
 * TtsVoice - 环境自检
 *
 * 【为什么不放站点根目录】
 * 早期版本是独立的 doctor.php，要手动上传到站点根目录。
 * 两个问题：
 *   1. Typecho 的路由可能接管该请求，直接 404；
 *   2. 要手动传文件、用完还要记得删，容易遗留暴露环境信息的文件。
 *
 * 现在改成后台面板的一个页签：
 *   后台 → 管理 → 朗读 → 点「运行环境自检」
 * 路径是 admin/extending.php?panel=TtsVoice/panel.php&action=doctor，
 * 完全在后台框架内，不受前台路由影响，也不需要额外传文件。
 *
 * @package TtsVoice
 * @author  zizdog
 * @version 1.6.8
 */
class TtsVoice_Doctor
{
    /**
     * 运行全部检查
     *
     * @return array 每项 {name, ok, value, fix}
     */
    public static function checks()
    {
        $rows = array();

        self::checkDir($rows);
        self::checkPhp($rows);
        self::checkNetwork($rows);
        self::checkProvider($rows);
        self::checkAccessMode($rows);
        self::checkTable($rows);
        self::checkAudioDir($rows);
        self::checkActionUrl($rows);
        self::checkService($rows);
        self::checkConfig($rows);
        self::checkMount($rows);
        self::checkFfmpeg($rows);
        self::checkJobs($rows);
        self::checkVersion($rows);

        return $rows;
    }

    /**
     * 记录一条结果
     *
     * @param array  $rows
     * @param string $name
     * @param bool   $ok
     * @param string $value
     * @param string $fix
     * @return void
     */
    protected static function row(&$rows, $name, $ok, $value, $fix = '')
    {
        $rows[] = array(
            'name'  => $name,
            'ok'    => (bool) $ok,
            'value' => (string) $value,
            'fix'   => (string) $fix,
        );
    }

    /* ---------------------------------------------------------- 检查项 */

    /**
     * 插件目录名
     *
     * 目录名必须是 TtsVoice，Typecho 按「目录名_Plugin」找类，
     * 如果是 TtsVoice-master 就会报「插件不存在」或「配置信息没有找到」。
     */
    protected static function checkDir(&$rows)
    {
        $base = basename(dirname(__FILE__));

        self::row(
            $rows,
            '插件目录名',
            'TtsVoice' === $base,
            'usr/plugins/' . $base,
            'TtsVoice' === $base ? '' : '目录名必须是 TtsVoice，请去掉 -master 后缀或多余的一层嵌套'
        );
    }

    /**
     * PHP 版本
     */
    protected static function checkPhp(&$rows)
    {
        $ok = version_compare(PHP_VERSION, '7.0.0', '>=');

        self::row($rows, 'PHP 版本', $ok, PHP_VERSION, $ok ? '' : '需要 PHP 7.0 以上');
    }

    /**
     * 网络请求能力（curl 或 allow_url_fopen 二选一）
     */
    protected static function checkNetwork(&$rows)
    {
        $hasCurl  = function_exists('curl_init');
        $hasFopen = (bool) ini_get('allow_url_fopen');

        self::row(
            $rows,
            '网络请求能力',
            $hasCurl || $hasFopen,
            ($hasCurl ? 'curl 可用' : 'curl 不可用') . ' ／ ' . ($hasFopen ? 'allow_url_fopen 开启' : 'allow_url_fopen 关闭'),
            ($hasCurl || $hasFopen) ? '' : '需在 php.ini 启用 curl 扩展，或打开 allow_url_fopen'
        );
    }

    /**
     * 当前 TTS 服务（provider）
     *
     * 1.6.0 新增。local / azure / openai 走不同代码路径，
     * 排查问题时先确认「现在到底在用哪个」。
     */
    protected static function checkProvider(&$rows)
    {
        if (!class_exists('TtsVoice_Provider', false)) {
            self::row($rows, '当前 TTS 服务', false, 'Providers.php 未加载', '缺少 usr/plugins/TtsVoice/Providers.php，请重新覆盖插件文件');

            return;
        }

        $provider = TtsVoice_Provider::make(TtsVoice_Plugin::opt('provider', 'openai'));
        $endpoint = $provider->endpoint();

        self::row(
            $rows,
            '当前 TTS 服务',
            true,
            $provider->label() . '（' . $provider->id() . '）'
                . ' ／ ' . ($provider->isAsync() ? '异步任务' : '同步直出')
                . ('' === $endpoint ? '' : ' ／ ' . $endpoint),
            ''
        );
    }

    /**
     * 数据表
     */
    protected static function checkTable(&$rows)
    {
        $ok    = false;
        $value = '';

        try {
            $db     = ttsv_db();
            $prefix = $db->getPrefix();
            $db->fetchRow($db->select()->from('table.tts_jobs')->limit(1));
            $ok    = true;
            $value = $prefix . 'tts_jobs 可访问';
        } catch (Throwable $e) {
            $value = '查询失败：' . $e->getMessage();
        }

        self::row($rows, '数据表 tts_jobs', $ok, $value, $ok ? '' : '表不存在，说明未成功激活：禁用插件后重新启用');
    }

    /**
     * 音频目录：存在 + 可写 + 实际写入测试
     *
     * 只判 is_writable 不够（某些环境父目录权限会骗人），
     * 所以再真写一个临时文件验证。
     */
    protected static function checkAudioDir(&$rows)
    {
        $dir = TtsVoice_Plugin::audioPath();

        $exists   = is_dir($dir);
        $writable = $exists && is_writable($dir);
        $writeOk  = false;

        if ($writable) {
            $test = rtrim($dir, '/') . '/.ttsv_write_test';
            $writeOk = (false !== @file_put_contents($test, 'ok'));

            if ($writeOk) {
                @unlink($test);
            }
        }

        $value = $dir . '（' . ($exists ? '存在' : '不存在')
            . '，' . ($writable ? '可写' : '不可写')
            . '，' . ($writeOk ? '写入测试通过' : '写入测试失败') . '）';

        self::row(
            $rows,
            '音频目录',
            $writeOk,
            $value,
            $writeOk ? '' : '创建并赋予写权限：mkdir -p ' . $dir . ' && chmod 755 ' . $dir
        );
    }

    /**
     * Action 接口地址（重点查 index.php 重复）
     *
     * 这是最容易踩的坑：Typecho 的 $options->index 在未开伪静态时
     * 本身已含 index.php，再拼一次就会出现 index.php/index.php/...
     */
    protected static function checkActionUrl(&$rows)
    {
        $url = TtsVoice_Plugin::actionUrl('health');
        $dup = false !== strpos($url, 'index.php/index.php');

        self::row(
            $rows,
            'Action 接口地址',
            !$dup,
            $url,
            $dup ? '出现两段 index.php —— 运行的是旧版 Plugin.php，请重新覆盖插件文件' : ''
        );
    }

    /**
     * 服务连通性
     *
     * 统一走 provider->health()（Azure 做一次极短合成，Qwen 走 GET {base}/models），
     * 都是带鉴权的真实检查。1.6.8 起 tts-worker(local) 已移除，
     * 不再有「NAS /health 探测」这条老路径。
     */
    protected static function checkService(&$rows)
    {
        if (!class_exists('TtsVoice_Provider', false)) {
            self::row($rows, '服务连通性', false, 'Provider 层未加载', '插件文件不完整，请重新上传 usr/plugins/TtsVoice/');

            return;
        }

        $provider = TtsVoice_Provider::make(TtsVoice_Plugin::opt('provider', 'openai'));
        $result   = $provider->health();
        $ms       = intval($result['ms']);
        $name     = '服务连通性（' . $provider->label() . '）';

        if (!empty($result['ok'])) {
            $detail = '鉴权检查通过（HTTP 200）';

            if (!empty($result['data'])) {
                $detail .= ' ｜ ' . json_encode($result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            self::row($rows, $name, true, $detail . '（' . $ms . ' ms）', '');

            return;
        }

        self::row(
            $rows,
            $name,
            false,
            $result['error'] . '（' . $ms . ' ms）',
            '检查 API Key / 区域 / Endpoint / 服务地址与网络（异地需先组内网）'
        );
    }

    /**
     * 访问模式：内网 / 公网 HTTPS
     *
     * 走公网时超时和证书是两个高频问题，这里提前提示。
     */
    protected static function checkAccessMode(&$rows)
    {
        // 1.6.0：不同 provider 的目标地址不同，统一从 provider->endpoint() 取
        $provider = class_exists('TtsVoice_Provider', false)
            ? TtsVoice_Provider::make(TtsVoice_Plugin::opt('provider', 'openai'))
            : NULL;

        $url     = $provider ? (string) $provider->endpoint() : '';
        $isHttps = 0 === stripos($url, 'https://');
        $isLocal = (bool) preg_match('#(127\.0\.0\.1|localhost|192\.168\.|10\.\d+\.|172\.(1[6-9]|2\d|3[01])\.)#i', $url);
        $timeout = TtsVoice_Plugin::intOpt('timeout', 15);

        if ('' === $url) {
            self::row($rows, '访问模式', false, '未配置服务地址', '到插件设置填写服务地址 / Endpoint');

            return;
        }

        $mode = $isHttps ? 'HTTPS' : 'HTTP（明文）';
        $net  = $isLocal ? '内网' : '公网';

        $tips = array();

        if ($isHttps && !$isLocal) {
            $tips[] = '公网 HTTPS：确认证书有效，自签证书需在插件设置里关闭证书校验';
        }

        if (!$isLocal && $timeout < 30) {
            $tips[] = '公网访问建议把「请求超时」调到 30 以上（当前 ' . $timeout . ' 秒）';
        }

        if (!$isHttps && !$isLocal) {
            $tips[] = '公网用明文 HTTP，音频与密钥会被窃听，强烈建议套 HTTPS';
        }

        self::row(
            $rows,
            '访问模式',
            true,
            $mode . ' ／ ' . $net . ($provider ? ' ／ ' . $provider->id() : ''),
            implode('；', $tips)
        );
    }

    /**
     * 插件配置行
     */
    protected static function checkConfig(&$rows)
    {
        $ok    = false;
        $value = '';

        try {
            $db  = ttsv_db();
            $row = $db->fetchRow(
                $db->select()->from('table.options')->where('name = ?', 'plugin:TtsVoice')->limit(1)
            );

            $ok    = is_array($row) && isset($row['value']) && '' !== (string) $row['value'];
            $value = $ok ? 'plugin:TtsVoice 已写入' : '缺失或为空（刷新任意页面会自动补写）';
        } catch (Throwable $e) {
            $value = '查询失败：' . $e->getMessage();
        }

        self::row($rows, '插件配置行', $ok, $value, $ok ? '' : '若反复缺失，禁用后重新启用插件');
    }

    /**
     * 本机 ffmpeg（决定「能不能真变速」「能不能编 mp3」）
     *
     * 没有 ffmpeg **不是故障**：合成照跑、音频照出，只是
     *   1) 设置的语速不会生效（无法 atempo，出原速）；
     *   2) 音色样本不做 12 秒/24k/单声道 规整，按原样上传；
     *   3) 服务端只吐 WAV 时插件会照收（面板会显示一条 [提示]）。
     * 所以这一行只是「告诉你为什么听起来没变快 / 为什么是 WAV」，别当成错误。
     */
    protected static function checkFfmpeg(&$rows)
    {
        $path = TtsVoice_Plugin::ffmpegPath();

        if ('' === $path) {
            self::row(
                $rows,
                '本机 ffmpeg',
                false,
                '未找到（插件仍可正常工作）',
                '没装 ffmpeg 不影响出片：语速设置不生效（出原速），音色样本原样上传，'
                . '音频格式以服务端实际返回的为准（可能退回 WAV，面板会提示）。'
                . '想要变速 / 统一编码，在服务器上装 ffmpeg 即可（macOS: brew install ffmpeg）'
            );

            return;
        }

        $version = @trim((string) @shell_exec(
            escapeshellarg($path) . ' -version 2>/dev/null | head -n 1'
        ));

        self::row(
            $rows,
            '本机 ffmpeg',
            true,
            $path . ('' === $version ? '' : '（' . $version . '）'),
            ''
        );
    }

    /**
     * 播放器挂载方式
     */
    protected static function checkMount(&$rows)
    {
        $auto = TtsVoice_Plugin::boolOpt('autoInsert', true);

        self::row(
            $rows,
            '播放器挂载方式',
            true,
            $auto
                ? '自动插入（文章末尾，依赖主题 footer.php 里的 $this->footer()）'
                : '手动调用（需在主题模板里写 TtsVoice_Plugin::renderPlayer()）',
            $auto
                ? '若播放器没出现，说明主题没调用 $this->footer()。可改为「手动调用」并在模板里加 <?php TtsVoice_Plugin::renderPlayer(); ?>，或在插件设置里填写「自动插入位置」的 DOM id'
                : ''
        );
    }

    /**
     * 任务统计
     */
    protected static function checkJobs(&$rows)
    {
        try {
            $db   = ttsv_db();
            $list = $db->fetchAll($db->select('cid', 'status')->from('table.tts_jobs'));

            $stat = array('ready' => 0, 'busy' => 0, 'failed' => 0, 'other' => 0);

            foreach ((array) $list as $item) {
                $status = isset($item['status']) ? (string) $item['status'] : '';

                if ('ready' === $status) {
                    $stat['ready']++;
                } elseif (in_array($status, array('queued', 'running', 'done'), true)) {
                    $stat['busy']++;
                } elseif ('failed' === $status) {
                    $stat['failed']++;
                } else {
                    $stat['other']++;
                }
            }

            $value = '共 ' . count($list) . ' 条：已就绪 ' . $stat['ready']
                . '，进行中 ' . $stat['busy']
                . '，失败 ' . $stat['failed'];

            self::row($rows, '任务统计', true, $value, '');
        } catch (Throwable $e) {
            self::row($rows, '任务统计', false, '查询失败：' . $e->getMessage(), '');
        }
    }

    /**
     * 版本号
     */
    protected static function checkVersion(&$rows)
    {
        self::row($rows, '插件版本', true, TtsVoice_Plugin::VERSION, '');
    }

    /**
     * 手动测试某篇文章
     *
     * @param int $cid
     * @return array
     */
    public static function testArticle($cid)
    {
        $cid  = intval($cid);
        $data = TtsVoice_Plugin::player($cid, array('css' => false));

        if ($cid <= 0) {
            return array('ok' => false, 'value' => '请输入文章 ID');
        }

        if (!$data['ready']) {
            $job = TtsVoice_Plugin::job($cid);

            $status = $job ? TtsVoice_Plugin::statusText($job['status']) : '无任务记录';

            return array('ok' => false, 'value' => '不可播放（当前状态：' . $status . '）');
        }

        return array(
            'ok'    => true,
            'value' => '可播放：' . $data['audioUrl']
                . '（' . round($data['duration']) . ' 秒，'
                . round($data['size'] / 1024) . ' KB，'
                . count($data['chapters']) . ' 个章节）',
        );
    }

    /* ---------------------------------------------------------- 输出 */

    /**
     * 渲染诊断页（内容区，不含 header / footer）
     *
     * @return void
     */
    public static function render()
    {
        $rows  = self::checks();
        $pass  = 0;

        foreach ($rows as $row) {
            if ($row['ok']) {
                $pass++;
            }
        }

        $testCid  = isset($_GET['cid']) ? intval($_GET['cid']) : 0;
        $testInfo = $testCid > 0 ? self::testArticle($testCid) : NULL;

        $backUrl = Helper::options()->adminUrl . 'extending.php?panel=TtsVoice/panel.php';
        $esc     = function ($value) {
            return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        };
        ?>
        <div class="main">
            <div class="body container">
                <div class="typecho-page-title">
                    <h2>运行环境自检</h2>
                    <p style="margin:4px 0 0;font-size:13px">
                        <a href="<?php echo $esc($backUrl); ?>">&larr; 返回朗读面板</a>
                    </p>
                </div>

                <div class="row typecho-page-main" role="main">
                    <div class="col-mb-12">

                        <div class="typecho-list-operate clearfix">
                            <h3 style="margin:0">
                                检查结果：通过 <b style="color:<?php echo $pass === count($rows) ? '#46a05a' : '#c0392b'; ?>"><?php echo $pass; ?></b> / <?php echo count($rows); ?> 项
                            </h3>
                            <p style="margin:6px 0 0;font-size:12px;color:#999">
                                本页只读取环境与配置，不会修改任何数据。
                            </p>
                        </div>

                        <div class="typecho-table-wrap">
                            <table class="typecho-list-table">
                                <colgroup>
                                    <col width="18%">
                                    <col width="8%">
                                    <col width="74%">
                                </colgroup>
                                <thead>
                                <tr>
                                    <th>检查项</th>
                                    <th>结果</th>
                                    <th>详情</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row) { ?>
                                <tr>
                                    <td><?php echo $esc($row['name']); ?></td>
                                    <td style="color:<?php echo $row['ok'] ? '#46a05a' : '#c0392b'; ?>;font-weight:600">
                                        <?php echo $row['ok'] ? '通过' : '异常'; ?>
                                    </td>
                                    <td>
                                        <?php echo $esc($row['value']); ?>
                                        <?php if (!$row['ok'] && '' !== $row['fix']) { ?>
                                        <span style="display:block;margin-top:3px;font-size:12px;color:#c0392b">
                                            处理建议：<?php echo $esc($row['fix']); ?>
                                        </span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- 手动测试某篇文章 -->
                        <div class="typecho-list-operate clearfix">
                            <h3 style="margin:0">测试指定文章</h3>
                        </div>

                        <div class="typecho-table-wrap">
                            <div style="padding:14px 16px">
                                <form method="get" action="" style="margin:0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                    <input type="hidden" name="panel" value="TtsVoice/panel.php">
                                    <input type="hidden" name="action" value="doctor">
                                    <input type="number" name="cid" min="1" placeholder="文章 ID（cid）"
                                           value="<?php echo $testCid > 0 ? $testCid : ''; ?>"
                                           style="width:180px;padding:6px 10px;border:1px solid #d9d9d9;border-radius:4px">
                                    <button type="submit" class="btn primary">检查</button>
                                    <span style="font-size:12px;color:#999">文章 ID 可在编辑页地址栏的 cid= 后面看到</span>
                                </form>

                                <?php if (NULL !== $testInfo) { ?>
                                <p style="margin:12px 0 0;padding:10px 12px;border-radius:6px;font-size:13px;
                                          background:<?php echo $testInfo['ok'] ? '#eaf6ec' : '#fdeeee'; ?>;
                                          color:<?php echo $testInfo['ok'] ? '#2d7a3e' : '#c0392b'; ?>">
                                    <b>cid=<?php echo $testCid; ?></b>：
                                    <?php echo $esc($testInfo['value']); ?>
                                </p>
                                <?php } ?>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
