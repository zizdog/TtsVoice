<?php
/**
 * Typecho 在线更新 · 标准参考实现（主题 / 插件通用）
 *
 * 设计目标：一个文件、零依赖、复制进项目即可用；行为与 GardenWalk 线上一致。
 * 协议说明见 `.ai-notes/handoff/UPDATE-STANDARD.md`。
 *
 * 用法（主题）：
 *   require_once __DIR__ . '/updater.php';
 *   $U = new \TtsVoice\Updater(array(
 *       'kind'    => 'theme',              // theme | plugin（只影响默认镜像路径）
 *       'slug'    => 'gardenwalk',         // 镜像目录名 = zip 名前缀
 *       'version' => THEME_VERSION,        // 当前版本（点分数字）
 *       'root'    => __DIR__,              // 包内容覆盖到这里
 *   ));
 *   $U->handleAction();                    // 处理 ?gw-update=1 / ?gw-check=1（后台动作）
 *   echo $U->notice();                     // 在设置页渲染提示条 + 按钮
 *
 * TtsVoice：已把命名空间改为 `TtsVoice`，避免与其他插件各自 require 时撞 `Typecho\Updater`。
 * 用法（插件）：同样的代码，把 'kind' 改成 'plugin'、'root' 指向插件目录、
 *   'version' 传 `const VERSION`；`version_file` 默认 `Plugin.php`。
 *
 * 约定（由 UPDATE-STANDARD.md 规定）：
 *   · 清单 <base>/<slug>/version.json，包 <base>/<slug>/<slug>-<版本>.zip
 *   · zip 根目录 = 项目文件（允许外面多包一层目录，本类会剥掉）
 *   · 覆盖前校验：sha256 → 包内有版本文件 → 包内版本不低于当前
 *   · 覆盖后复读版本确认；**不备份旧版**（项目规则）
 */

namespace TtsVoice;

class Updater
{
    /** @var array 已合并默认值的配置 */
    private $cfg;

    /** @var array|null 本次请求内缓存的清单 */
    private $manifest = null;

    public function __construct(array $cfg = array())
    {
        $kind = (isset($cfg['kind']) && 'plugin' === $cfg['kind']) ? 'plugin' : 'theme';

        $this->cfg = array_merge(array(
            'kind'            => $kind,
            'slug'            => '',
            'base'            => '',          // 留空 = https://mirror.zizdog.com:8888/typecho/<themes|plugins>
            'version'         => '',
            'root'            => '',
            'version_file'    => ('plugin' === $kind ? 'Plugin.php' : 'functions.php'),
            'version_regex'   => ('plugin' === $kind
                                    ? "/const\\s+VERSION\\s*=\\s*'([0-9]+(?:\\.[0-9]+){0,3})'/"
                                    : "/define\\(\\s*'THEME_VERSION'\\s*,\\s*'([0-9]+(?:\\.[0-9]+){0,3})'\\s*\\)/"),
            'manifest'        => 'version.json',
            'package_pattern' => '{slug}-{version}.zip',
            'cache_dir'       => (defined('__TYPECHO_ROOT_DIR__') ? __TYPECHO_ROOT_DIR__ : sys_get_temp_dir()) . '/usr/cache/updater',
            'ttl'             => 21600,       // 6 小时
            'http_timeout'    => 6,
            'download_timeout'=> 180,
            'token'           => 'typecho-updater',
            'title'           => '',          // 提示条标题，留空用 slug
            'action_prefix'   => '',          // 留空用 slug
            'allow_reinstall' => true,
            'keep_history'    => 5,
        ), $cfg);

        if ('' === $this->cfg['base']) {
            $this->cfg['base'] = 'https://mirror.zizdog.com:8888/typecho/'
                . ('plugin' === $this->cfg['kind'] ? 'plugins' : 'themes');
        }

        $this->cfg['base'] = rtrim($this->cfg['base'], '/');

        if ('' === $this->cfg['action_prefix']) {
            $this->cfg['action_prefix'] = $this->cfg['slug'];
        }

        if ('' === $this->cfg['title']) {
            $this->cfg['title'] = $this->cfg['slug'];
        }
    }

    /** 清单地址（<base>/<slug>/version.json） */
    public function manifestUrl()
    {
        return $this->cfg['base'] . '/' . $this->cfg['slug'] . '/' . $this->cfg['manifest'];
    }

    /** 包地址（清单没给 package 时按约定推导） */
    public function packageUrl($version)
    {
        $name = str_replace(
            array('{slug}', '{version}'),
            array($this->cfg['slug'], $version),
            $this->cfg['package_pattern']
        );

        return $this->cfg['base'] . '/' . $this->cfg['slug'] . '/' . $name;
    }

    // ---------------------------------------------------------------- 清单

    /**
     * 拉清单（带文件缓存）
     *
     * @param bool $force 忽略缓存
     * @param bool $fast  只试主清单、短超时（设置页首屏用）
     * @return array|null
     */
    public function manifest($force = false, $fast = false)
    {
        if (null !== $this->manifest && !$force) {
            return $this->manifest;
        }

        if (!$force) {
            $cached = $this->cacheGet('manifest');

            if (is_array($cached) && isset($cached['_at']) && (time() - (int) $cached['_at']) < $this->cfg['ttl']) {
                return $this->manifest = $cached;
            }
        }

        $urls = array($this->manifestUrl(), $this->manifestUrl() . '?t=' . time());
        $raw  = null;

        foreach ($urls as $i => $url) {
            if ($fast && $i > 0) {
                break;
            }

            $raw = $this->httpGet($url, $fast ? 3 : $this->cfg['http_timeout']);

            if (null !== $raw) {
                break;
            }
        }

        if (null === $raw) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return null;
        }

        $m = $this->normalize($data);

        if (null === $m) {
            return null;
        }

        $m['_at'] = time();
        $this->cacheSet('manifest', $m);
        $this->manifest = $m;

        return $m;
    }

    /**
     * 把各种写法归一（latest 包裹 / 顶层直给 / package 缺省 / 相对地址）
     *
     * @param array $data
     * @return array|null
     */
    private function normalize(array $data)
    {
        $latest = (isset($data['latest']) && is_array($data['latest'])) ? $data['latest'] : $data;
        $ver    = trim((string) (isset($latest['version']) ? $latest['version'] : ''));

        if ('' === $ver || !preg_match('/^[0-9]+(\.[0-9]+){0,3}$/', $ver)) {
            return null;
        }

        $pkg = '';

        foreach (array('package', 'download', 'zip') as $k) {
            if (!empty($latest[$k])) {
                $pkg = trim((string) $latest[$k]);
                break;
            }
        }

        if ('' === $pkg && !empty($latest['url']) && preg_match('/\.zip(\?|$)/i', (string) $latest['url'])) {
            $pkg = trim((string) $latest['url']);
        }

        if ('' === $pkg) {
            $pkg = $this->packageUrl($ver);
        } elseif (!preg_match('#^https?://#i', $pkg)) {
            $pkg = $this->cfg['base'] . '/' . $this->cfg['slug'] . '/' . ltrim($pkg, '/');
        }

        $sha = strtolower(trim((string) (isset($latest['sha256']) ? $latest['sha256'] : '')));

        if ('' !== $sha && !preg_match('/^[0-9a-f]{64}$/', $sha)) {
            $sha = '';
        }

        return array(
            'version' => $ver,
            'date'    => (string) (isset($latest['date']) ? $latest['date'] : ''),
            'notes'   => (string) (isset($latest['notes']) ? $latest['notes'] : ''),
            'force'   => !empty($latest['force']),
            'package' => $pkg,
            'sha256'  => $sha,
            'size'    => (int) (isset($latest['size']) ? $latest['size'] : 0),
            'history' => (isset($data['history']) && is_array($data['history'])) ? $data['history'] : array(),
        );
    }

    // ---------------------------------------------------------------- 状态

    /**
     * 更新状态（给 UI 用）
     *
     * @param bool $force
     * @param bool $fast
     * @return array|null
     */
    public function status($force = false, $fast = false)
    {
        $current = (string) $this->cfg['version'];

        if (!$force) {
            $cached = $this->cacheGet('status');

            if (is_array($cached) && isset($cached['checked_at']) && (time() - (int) $cached['checked_at']) < $this->cfg['ttl']) {
                return $cached;
            }
        }

        $m = $this->manifest($force, $fast);

        if (null === $m) {
            $old = $this->cacheGet('status');

            if (is_array($old)) {
                $old['checked_at'] = time();
                $old['error']      = '无法获取更新清单（镜像站不可达或清单格式不对）';
                $this->cacheSet('status', $old);

                return $old;
            }

            return null;
        }

        $has       = version_compare($m['version'], $current, '>');
        $status    = array(
            'has_update' => $has,
            'current'    => $current,
            'latest'     => $m['version'],
            'version'    => $has ? $m['version'] : $current,
            'package'    => $m['package'],
            'sha256'     => $m['sha256'],
            'size'       => $m['size'],
            'notes'      => $m['notes'],
            'force'      => $m['force'],
            'date'       => $m['date'],
            'history'    => $m['history'],
            'manifest'   => $this->manifestUrl(),
            'checked_at' => time(),
            'checked'    => date('Y-m-d H:i:s'),
        );

        $this->cacheSet('status', $status);

        return $status;
    }

    // ---------------------------------------------------------------- 执行

    /**
     * 执行更新
     *
     * 顺序即安全边界：下载 → sha256 → 解压 → 定位根 → 读版本 → 拒绝降级
     *              → 覆盖 → 复读版本 → 清缓存。**不备份旧版。**
     *
     * @param array $status
     * @return array ['success'=>bool,'msg'=>string,'version'=>string]
     */
    public function apply(array $status)
    {
        $fail = function ($msg) {
            return array('success' => false, 'msg' => $msg, 'version' => '');
        };

        if (!class_exists('ZipArchive')) {
            return $fail('PHP 缺少 zip 扩展（ZipArchive）');
        }

        $root = rtrim((string) $this->cfg['root'], '/');

        if ('' === $root || !is_dir($root)) {
            return $fail('root 目录不存在：' . $root);
        }

        $current = $this->readVersion($root . '/' . $this->cfg['version_file']);

        if ('' === $current) {
            return $fail('读不到当前版本（' . $this->cfg['version_file'] . '）');
        }

        if (!is_writable($root) || !is_writable($root . '/' . $this->cfg['version_file'])) {
            return $fail('目录不可写：' . $root);
        }

        $package = (string) (isset($status['package']) ? $status['package'] : '');

        if ('' === $package) {
            return $fail('清单里没有更新包地址');
        }

        $tmp  = $root . '/.updater-tmp-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 6);
        $zip  = $tmp . '.zip';
        $err  = '';

        if (!@mkdir($tmp, 0755, true)) {
            return $fail('无法创建临时目录：' . $tmp);
        }

        if (!$this->download($package, $zip, (string) (isset($status['sha256']) ? $status['sha256'] : ''), $err)) {
            $this->rmtree($tmp);
            @unlink($zip);

            return $fail($err);
        }

        $za = new \ZipArchive();

        if (true !== $za->open($zip)) {
            $this->rmtree($tmp);
            @unlink($zip);

            return $fail('更新包不是有效的 zip');
        }

        $za->extractTo($tmp);
        $za->close();
        @unlink($zip);

        $src = $tmp;

        if (!is_file($src . '/' . $this->cfg['version_file'])) {
            $found = '';

            foreach ((array) @scandir($tmp) as $entry) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }

                if (is_dir($tmp . '/' . $entry) && is_file($tmp . '/' . $entry . '/' . $this->cfg['version_file'])) {
                    $found = $tmp . '/' . $entry;
                    break;
                }
            }

            if ('' === $found) {
                $this->rmtree($tmp);

                return $fail('更新包里找不到 ' . $this->cfg['version_file'] . '（打包时请把项目文件放 zip 根目录）');
            }

            $src = $found;
        }

        $incoming = $this->readVersion($src . '/' . $this->cfg['version_file']);

        if ('' === $incoming) {
            $this->rmtree($tmp);

            return $fail('更新包里的版本号读不出来，已中止');
        }

        if (version_compare($incoming, $current, '<')) {
            $this->rmtree($tmp);

            return $fail('更新包版本（' . $incoming . '）比当前（' . $current . '）旧，已中止');
        }

        if (!$this->cfg['allow_reinstall'] && 0 === version_compare($incoming, $current)) {
            $this->rmtree($tmp);

            return $fail('更新包版本与当前相同（' . $current . '），已中止');
        }

        $copied = $this->copyTree($src, $root);

        $after = $this->readVersion($root . '/' . $this->cfg['version_file']);
        $this->rmtree($tmp);

        if ('' === $after || 0 !== version_compare($after, $incoming)) {
            return $fail('覆盖后版本校验失败（写入后读到 "' . $after . '"，应为 "' . $incoming . '"）。'
                . '按约定不保留旧版备份：可重新点一次更新重试，或下载同名 zip 解压覆盖。');
        }

        $this->cacheDelete('status');
        $this->cacheDelete('manifest');

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($root . '/' . $this->cfg['version_file'], true);
            @opcache_reset();
        }

        // 更新完就地写"已最新"（本请求里的版本常量还是旧值，不能再查）
        $this->cacheSet('status', array(
            'has_update' => false,
            'current'    => $after,
            'latest'     => $after,
            'version'    => $after,
            'checked_at' => time(),
            'checked'    => date('Y-m-d H:i:s'),
        ));

        return array(
            'success' => true,
            'msg'     => '已更新到 v' . $after . '（覆盖 ' . $copied . ' 个文件）',
            'version' => $after,
        );
    }

    /** 从文件里读版本号 */
    public function readVersion($file)
    {
        if (!is_file($file)) {
            return '';
        }

        $head = (string) @file_get_contents($file, false, null, 0, 8192);

        return preg_match($this->cfg['version_regex'], $head, $m) ? $m[1] : '';
    }

    // ---------------------------------------------------------------- 后台动作

    /**
     * 处理后台动作（GET + 固定 token + Notice + 302）
     *
     * 用固定后缀的 token 自校验，不用 Security::protect()（它依赖 Referer，
     * Referer 缺失时会 goBack 到首页）。动作名：?<prefix>-check=1 / ?<prefix>-update=1
     *
     * @return void
     */
    public function handleAction()
    {
        $p       = $this->cfg['action_prefix'];
        $doCheck = isset($_GET[$p . '-check']);
        $doUpd   = isset($_GET[$p . '-update']);

        if (!$doCheck && !$doUpd) {
            return;
        }

        if (!class_exists('\Typecho\Widget')) {
            return;
        }

        try {
            $sec      = \Typecho\Widget::widget('\Widget\Security');
            $expected = $sec->getToken($this->cfg['token']);
            $given    = isset($_GET['_']) ? (string) $_GET['_'] : '';

            if (!hash_equals($expected, $given)) {
                $this->redirect('校验失败：请刷新页面后重试', false);
            }

            $user = \Typecho\Widget::widget('\Widget\User');

            if (!$user->hasLogin() || !$user->pass('administrator')) {
                $this->redirect('没有权限', false);
            }

            if ($doCheck) {
                $st = $this->status(true);

                if (null === $st) {
                    $this->redirect('检查失败：拿不到更新清单', false);
                }

                $this->redirect(
                    !empty($st['has_update'])
                        ? '发现新版本 v' . $st['latest'] . '（当前 v' . $st['current'] . '）'
                        : '已是最新版本 v' . $st['current'],
                    true
                );
            }

            $st = $this->status(true);

            if (null === $st || empty($st['has_update'])) {
                $this->redirect('当前没有可用更新', null === $st ? false : true);
            }

            $r = $this->apply($st);
            $this->redirect($r['msg'], !empty($r['success']));
        } catch (\Throwable $e) {
            $this->redirect('更新异常：' . $e->getMessage(), false);
        }
    }

    private function redirect($msg, $ok)
    {
        try {
            \Typecho\Widget::widget('\Widget\Notice')->set($msg, $ok ? 'success' : 'error');
        } catch (\Throwable $e) {
            // 提示失败不影响跳转
        }

        $url = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $p   = preg_quote($this->cfg['action_prefix'], '/');
        $url = preg_replace('/[?&](' . $p . '-(?:check|update))=1/', '', $url);
        $url = preg_replace('/[?&]_=[0-9a-f]{32}/', '', $url);

        if ('' === trim($url)) {
            $url = (string) \Typecho\Widget::widget('\Widget\Options')->adminUrl . 'options-general.php';
        }

        if (!headers_sent()) {
            header('Location: ' . str_replace(array("\r", "\n"), '', $url));
        }

        exit;
    }

    /**
     * 渲染提示条（有新版本 → 黄条 + 按钮；否则 → 绿条 + 检查按钮）
     *
     * @return string HTML
     */
    public function notice()
    {
        $st = $this->status(false, true);

        if (null === $st) {
            return '';
        }

        $p    = $this->cfg['action_prefix'];
        $base = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $base = preg_replace('/[?&]' . preg_quote($p, '/') . '-(?:check|update)=1/', '', $base);
        $base = preg_replace('/[?&]_=[0-9a-f]{32}/', '', $base);
        $sep  = (false === strpos($base, '?')) ? '?' : '&';
        $tok  = '';
        $same = ' onclick="location.href=this.href;return false;"';

        try {
            $tok = \Typecho\Widget::widget('\Widget\Security')->getToken($this->cfg['token']);
        } catch (\Throwable $e) {
            $tok = '';
        }

        $checkUrl = htmlspecialchars($base . $sep . $p . '-check=1&_=' . $tok);
        $check    = '<a class="btn" target="_self" href="' . $checkUrl . '"' . $same . '>🔄 立即检查</a>';

        if (empty($st['has_update'])) {
            return '<div class="message notice" style="background:#d4edda;border:1px solid #c3e6cb;padding:12px 15px;margin:15px 0;border-radius:4px;">'
                . '<strong>✓ ' . htmlspecialchars($this->cfg['title']) . ' 已是最新版本</strong>：v'
                . htmlspecialchars((string) $st['current'])
                . ('' !== (string) $st['checked'] ? '<span style="color:#666;margin-left:10px;font-size:13px;">检查于 ' . htmlspecialchars($st['checked']) . '</span>' : '')
                . '<span style="margin-left:10px;">' . $check . '</span>'
                . '</div>';
        }

        $ver      = htmlspecialchars((string) $st['latest']);
        $updateUrl = htmlspecialchars($base . $sep . $p . '-update=1&_=' . $tok);
        $confirm  = htmlspecialchars('将下载并校验更新包，然后覆盖当前文件。确定更新到 v' . $ver . ' 吗？');

        $html = '<div class="message notice" style="background:#fff3cd;border:1px solid #ffc107;padding:12px 15px;margin:15px 0;border-radius:4px;">'
            . '<p style="margin:0 0 8px;"><strong>' . htmlspecialchars($this->cfg['title']) . ' 更新可用</strong>：当前 <strong>v'
            . htmlspecialchars((string) $st['current']) . '</strong> → 最新 <strong>v' . $ver . '</strong></p>'
            . '<p style="margin:0 0 8px;">'
            . '<a class="btn btn-primary" target="_self" href="' . $updateUrl . '"'
            . ' onclick="if(!confirm(\'' . $confirm . '\')){return false;}location.href=this.href;return false;">⚡ 一键更新到 v' . $ver . '</a> '
            . $check
            . '</p>';

        if ('' !== trim((string) $st['notes'])) {
            $html .= '<div style="font-size:13px;color:#444;">' . nl2br(htmlspecialchars((string) $st['notes'])) . '</div>';
        }

        return $html . '</div>';
    }

    // ---------------------------------------------------------------- 工具

    private function httpGet($url, $timeout)
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(3, (int) $timeout));
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Typecho-Updater/' . $this->cfg['slug']);

            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            return (false !== $body && 200 === $code && '' !== $body) ? (string) $body : null;
        }

        $ctx  = stream_context_create(array('http' => array('timeout' => (int) $timeout)));
        $body = @file_get_contents($url, false, $ctx);

        return (false === $body || '' === $body) ? null : (string) $body;
    }

    private function download($url, $dest, $sha, &$err)
    {
        $body = $this->httpGet($url, $this->cfg['download_timeout']);

        if (null === $body) {
            $err = '下载失败：' . $url;

            return false;
        }

        if ('' !== $sha && !hash_equals($sha, hash('sha256', $body))) {
            $err = 'sha256 校验不通过（清单与包不一致，可能是发布未完成）';

            return false;
        }

        if (false === @file_put_contents($dest, $body)) {
            $err = '无法写入临时文件：' . $dest;

            return false;
        }

        return true;
    }

    private function copyTree($src, $dst)
    {
        $src   = rtrim(str_replace('\\', '/', $src), '/');
        $dst   = rtrim(str_replace('\\', '/', $dst), '/');
        $count = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $f) {
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($src)), '/');

            if ('' === $rel || preg_match('#(^|/)(\.git|node_modules)(/|$)#', $rel)) {
                continue;
            }

            $target = $dst . '/' . $rel;

            if ($f->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0755, true);
                }

                if (@copy($f->getPathname(), $target)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function rmtree($dir)
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) @scandir($dir) as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                $this->rmtree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }

    private function cacheFile($key)
    {
        $dir = rtrim($this->cfg['cache_dir'], '/');

        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        return $dir . '/' . $this->cfg['slug'] . '-' . $key . '.json';
    }

    private function cacheGet($key)
    {
        $file = $this->cacheFile($key);

        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) @file_get_contents($file), true);

        return is_array($data) ? $data : null;
    }

    private function cacheSet($key, array $value)
    {
        @file_put_contents($this->cacheFile($key), json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    private function cacheDelete($key)
    {
        @unlink($this->cacheFile($key));
    }
}
