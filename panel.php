<?php
/**
 * TtsVoice - 后台管理面板
 *
 * 路径：后台 → 管理 → 朗读（admin/extending.php?panel=TtsVoice/panel.php）
 *
 * 与 Typecho 后台统一：直接 include 后台的 header.php / menu.php / footer.php，
 * 复用后台布局、侧栏与按钮样式，不另起一套自绘 UI。
 *
 * 【关键】include 必须写在文件顶层作用域。若包在函数里 include，
 * 函数作用域拿不到外层变量，header.php 里的 $options 会变成 null，
 * 直接报 "Call to a member function adminStaticUrl() on null"。
 *
 * @package TtsVoice
 * @author  zizdog
 * @version 1.7.4
 */

if (!defined('__TYPECHO_ADMIN__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';

/**
 * 定位 Typecho 后台的公共文件（header / menu / footer）
 *
 * 只返回路径，真正的 include 在文件顶层完成。
 *
 * @param string $file
 * @return string
 */
function ttsv_admin_path($file)
{
    $candidates = array();

    // 1. 相对路径：extending.php include 本文件时按调用脚本目录解析，即 admin/
    $candidates[] = $file;

    // 2. 从当前执行脚本推导
    if (!empty($_SERVER['SCRIPT_FILENAME'])) {
        $candidates[] = dirname($_SERVER['SCRIPT_FILENAME']) . '/' . $file;
    }

    // 3. 从 Typecho 根目录推导
    if (defined('__TYPECHO_ROOT_DIR__')) {
        $candidates[] = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/admin/' . $file;
    }

    // 4. 从插件目录往上推：usr/plugins/TtsVoice → 站点根
    $candidates[] = dirname(__FILE__) . '/../../../admin/' . $file;

    // 5. 用 DOCUMENT_ROOT + SCRIPT_NAME 反推（admin 目录被改名时）
    if (!empty($_SERVER['SCRIPT_NAME']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $candidates[] = dirname(
            rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/' . ltrim((string) $_SERVER['SCRIPT_NAME'], '/')
        ) . '/' . $file;
    }

    foreach ($candidates as $path) {
        if (file_exists($path) && is_readable($path)) {
            return $path;
        }
    }

    return $file;
}

/**
 * 统一转义
 */
if (!function_exists('ttsv_esc')) {
    function ttsv_esc($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$options = Helper::options();
$user    = Typecho_Widget::widget('Widget_User');
$panel   = 'extending.php?panel=TtsVoice/panel.php';

$user->pass('administrator');

$action = TtsVoice_Plugin::actionUrl('');
$jobs   = TtsVoice_Plugin::allJobs(200);

// 列表里「标题」要能点开**原文**：一次把这批 cid 的固定链接查回来
$permalinks = TtsVoice_Plugin::permalinks(array_map(function ($job) {
    return $job['cid'];
}, $jobs));

// 统计
$stat = array('all' => 0, 'ready' => 0, 'failed' => 0, 'busy' => 0, 'size' => 0);

foreach ($jobs as $job) {
    $stat['all']++;

    if ('ready' === $job['status']) {
        $stat['ready']++;
        $stat['size'] += intval($job['size']);
    } elseif ('failed' === $job['status']) {
        $stat['failed']++;
    } elseif (in_array($job['status'], array('queued', 'running', 'done'), true)) {
        $stat['busy']++;
    }
}

/**
 * 状态圆点
 *
 * 用颜色代替文字，节省列表横向空间，hover 才显示具体状态。
 * 配色约定：
 *   绿  ready   已就绪
 *   蓝  queued/running/done  进行中（带呼吸动画）
 *   红  failed  失败
 *   灰  none    未生成
 *
 * @param array $job
 * @return string
 */
function ttsv_status_dot($job)
{
    $status = (string) $job['status'];
    $text   = TtsVoice_Plugin::statusText($status);

    // hover 显示的完整信息
    $tip = $text;

    if ('' !== $job['progress'] && '0/0' !== $job['progress']) {
        $tip .= '（' . $job['progress'] . '）';
    }

    if ('failed' === $status && '' !== $job['error']) {
        $tip .= '：' . $job['error'];
    }

    if ('ready' === $status && intval($job['duration']) > 0) {
        $tip .= '　' . round((float) $job['duration']) . ' 秒';
    }

    // 无障碍：读屏器仍需文字
    return '<span class="ttsv-dot" data-status="' . ttsv_esc($status) . '"'
        . ' data-tip="' . ttsv_esc($tip) . '"'
        . ' role="img" aria-label="' . ttsv_esc($tip) . '">'
        . '<span class="ttsv-dot-sr">' . ttsv_esc($text) . '</span>'
        . '</span>';
}

/**
 * 格式化体积
 *
 * @param int $bytes
 * @return string
 */
function ttsv_size($bytes)
{
    $bytes = (float) $bytes;

    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }

    if ($bytes >= 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }

    return intval($bytes) . ' B';
}

require_once dirname(__FILE__) . '/Doctor.php';

// 页签：action=doctor 时渲染诊断页
$panelAction = isset($_GET['action']) ? trim((string) $_GET['action']) : '';

// 必须在文件顶层 include，才能继承上面的 $options 等变量
include ttsv_admin_path('header.php');
include ttsv_admin_path('menu.php');

// 诊断页：渲染完直接收尾，不进入下面的主面板
if ('doctor' === $panelAction) {
    TtsVoice_Doctor::render();
    include ttsv_admin_path('footer.php');

    return;
}
?>

<div class="main">
    <div class="body container">
        <div class="typecho-page-title">
            <h2><?php _e('朗读音频'); ?></h2>
        </div>

        <div class="ttsv-head">
            <span class="ttsv-head-item">
                <?php
                // 1.6.0：显示当前 TTS 服务与目标地址
                $ttsvProvider = class_exists('TtsVoice_Provider_OpenAI', false)
                    ? new TtsVoice_Provider_OpenAI()
                    : NULL;
                $ttsvEndpoint = $ttsvProvider ? $ttsvProvider->endpoint() : '';
                ?>
                <?php _e('服务：'); ?>
                <code><?php echo ttsv_esc($ttsvProvider ? $ttsvProvider->label() : 'openai'); ?></code>
                <?php _e('地址：'); ?>
                <code><?php echo ttsv_esc($ttsvEndpoint); ?></code>
            </span>
            <a class="ttsv-head-btn" href="<?php echo ttsv_esc($options->adminUrl . 'options-plugin.php?config=TtsVoice'); ?>"><?php _e('插件设置'); ?></a>
            <a class="ttsv-head-btn" href="<?php echo ttsv_esc($options->adminUrl . 'extending.php?panel=TtsVoice/panel.php&action=doctor'); ?>"><?php _e('运行环境自检'); ?></a>
        </div>

        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12">

                <!-- 音色样本（参考音频） -->
                <?php $refInfo = TtsVoice_Plugin::refAudioInfo(); ?>
                <div class="typecho-list-operate clearfix">
                    <h3 style="margin:0"><?php _e('音色样本'); ?></h3>
                </div>

                <div class="typecho-table-wrap">
                    <div style="padding:14px 16px" id="ttsv-voice-box">

                        <?php if (!empty($refInfo['exists'])) { ?>
                            <p style="margin:0 0 10px">
                                <b><?php _e('当前音色样本'); ?></b>
                                <span style="color:#666;font-size:13px;margin-left:8px">
                                    <?php
                                    if ($refInfo['seconds'] > 0) {
                                        echo number_format($refInfo['seconds'], 1) . ' ' . _t('秒') . ' · ';
                                    }
                                    echo ttsv_size($refInfo['size']) . ' · ' . ttsv_esc($refInfo['tag']);

                                    // 多站点共用接收端时，这行是「我的样本存在哪」的唯一线索
                                    if (!empty($refInfo['format']['ext'])) {
                                        echo ' · ' . ttsv_esc(strtoupper($refInfo['format']['ext']));
                                    }
                                    ?>
                                </span>
                                <?php /*
                                 * 试听做成按钮 + 点播时才 new Audio()：
                                 * 不再往页面里放常驻 <audio controls> 元素，避免浏览器一进页面
                                 * 就去碰媒体引擎 / 拉文件（Safari 对 preload="none" 也不一定守规矩）。
                                 * 参考音频在 .voice 下，直链会被 nginx 拦掉，所以走带鉴权的 voice 接口。
                                 */ ?>
                                <button type="button" class="btn" id="ttsv-voice-play" style="margin-left:10px"><?php _e('▶ 试听样本'); ?></button>
                            </p>
                            <?php if (!empty($refInfo['source'])) { ?>
                            <p style="margin:0 0 10px;font-size:12px;color:#888">
                                <?php _e('来源标识'); ?>：<code><?php echo ttsv_esc($refInfo['source']); ?></code>
                                <?php _e('（样本在 TTS 主机上存于「接收端目录 / 该标识 /」下，多站点互不覆盖）'); ?>
                            </p>
                            <?php } ?>
                        <?php } else { ?>
                            <p style="margin:0 0 10px">
                                <?php _e('未上传音色样本 —— '); ?>
                                <b><?php _e('不影响使用'); ?></b>
                                <span style="font-size:13px;color:#888">
                                    <?php _e('：提交时用 TTS 主机的默认音色。想用自己的声音，可在下面上传一段 10 秒以上的人声。'); ?>
                                </span>
                            </p>
                        <?php } ?>

                        <?php
                        // 插件只有克隆一条路：模型恒为 1.7B-Base，音色只来自上面的样本。
                        // 只有一条路：Qwen3-TTS 克隆
                        if (true) {
                            $ttsvCloneModel = TtsVoice_Plugin::cloneModelName();
                            ?>
                            <p style="margin:0 0 10px;font-size:13px;color:#555">
                                <?php _e('本次合成走：'); ?>
                                <?php if (TtsVoice_Plugin::hasCloneVoice()) { ?>
                                    <b><?php _e('克隆模式'); ?></b> ·
                                    <code><?php echo ttsv_esc($ttsvCloneModel); ?></code>
                                    <?php _e('+ 上面的样本音色'); ?>
                                <?php } else { ?>
                                    <b class="ttsv-txt-danger"><?php _e('缺音色样本 —— 现在点「生成」会直接失败'); ?></b>
                                    · <code><?php echo ttsv_esc($ttsvCloneModel); ?></code>
                                <?php } ?>
                            </p>
                        <?php /* 上游模型驻留 / 冷加载状态：由 JS 请求 hoststatus 填充（接收端 v1.2.0+ 才有） */ ?>
                            <p id="ttsv-host-status" style="margin:0 0 10px;font-size:13px;color:#999"></p>
                        <?php } ?>

                        <p style="margin:0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <input type="file" id="ttsv-voice-file" accept="audio/*" />
                            <button type="button" class="btn primary" id="ttsv-voice-upload"><?php _e('上传并处理'); ?></button>
                            <?php if (!empty($refInfo['exists'])) { ?>
                                <button type="button" class="btn" id="ttsv-voice-clear"><?php _e('删除样本'); ?></button>
                                <?php if ('' !== trim((string) TtsVoice_Plugin::refUploadUrl())) { ?>
                                    <button type="button" class="btn" id="ttsv-voice-sync"><?php _e('重新同步到 TTS 主机'); ?></button>
                                <?php } ?>
                            <?php } ?>
                            <span id="ttsv-voice-msg" style="font-size:13px;color:#999"></span>
                        </p>

                        <?php
                        // 接收端地址要读**推导后**的值（refUploadUrl() 会从 openaiBaseUrl 推出来），
                        // 不能读 opt('refUploadUrl') —— 那个键早已废弃，恒为空。
                        $recvUrl = trim((string) TtsVoice_Plugin::refUploadUrl());
                        $hostOk  = ('' !== $refInfo['forHost'] && is_file($refInfo['forHost']));
                        ?>

                        <?php if ('' === $recvUrl && !$hostOk && !empty($refInfo['exists'])) { ?>
                            <p class="ttsv-note is-error">
                                <b><?php _e('网站和 TTS 不在同一台机器，但还没有配置接收端。'); ?></b><br>
                                <?php _e('Qwen 服务端只认「TTS 主机上的本地文件路径」，站点上传的文件到不了那边。请在插件设置里填写「音色样本接收端」地址，并在 TTS 主机上运行接收端脚本。'); ?>
                            </p>
                        <?php } elseif ('' !== $recvUrl) { ?>
                            <p style="margin:10px 0 0;font-size:13px;color:#666;line-height:1.7">
                                <?php _e('接收端'); ?>：<code><?php echo ttsv_esc($recvUrl); ?></code>
                                <?php _e('（上传时会自动推送到 TTS 主机）'); ?>
                            </p>
                        <?php } ?>

                        <p class="description" style="margin:10px 0 0;line-height:1.7">
                            <?php
                            printf(
                                _t('上传后会自动裁到 <b>%d 秒</b>并转成 24kHz 单声道 wav —— 参考音频不是越长越好，10~15 秒足够，太长反而拖慢每块合成的编码开销。'),
                                TtsVoice_Plugin::REF_AUDIO_SECONDS
                            );
                            ?><br>
                            <?php _e('建议用一段干净的独白：无背景音乐、无第二人说话、无明显混响，10 秒以上。'); ?><br>
                            <b><?php _e('合成超时提示'); ?></b>：<?php _e('自建 TTS 约 4.5 字/秒，一次合成的时长受 Web 服务器超时限制。若日志里出现块合成被中断，请自行调大 nginx 的'); ?>
                            <code>fastcgi_read_timeout</code><?php _e('（默认 60 秒），或把「分块长度」调小 —— 本插件不会去改你的服务器配置。'); ?>
                        </p>

                        <?php
                        $staleCount = TtsVoice_Plugin::staleJobCount();

                        if ($staleCount > 0) {
                        ?>
                            <p id="ttsv-stale-note" class="ttsv-note is-warn">
                                <?php
                                printf(
                                    _t('有 <b>%d</b> 篇音频是用<b>之前的音色</b>生成的，已标记为「音色已过期」。'),
                                    $staleCount
                                );
                                ?>
                                <?php _e('要重生成哪些由你决定：可以在下方列表里逐篇点「生成」，也可以'); ?>
                                <button type="button" class="btn" id="ttsv-regen-stale" style="padding:1px 8px;font-size:12px"><?php _e('全部重生成'); ?></button>
                            </p>
                        <?php } ?>

                    </div>
                </div>

                <!-- 连通性 -->
                <div class="typecho-list-operate clearfix">
                    <h3 style="margin:0"><?php _e('TTS 服务连接'); ?></h3>
                </div>

                <div class="typecho-table-wrap">
                    <div style="padding:14px 16px">
                        <p style="margin:0;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                            <button type="button" class="btn primary" id="ttsv-test"><?php _e('测试连接'); ?></button>
                            <button type="button" class="btn" id="ttsv-voices"><?php _e('拉取音色列表'); ?></button>
                            <button type="button" class="btn" id="ttsv-doctor"><?php _e('运行环境自检'); ?></button>
                            <span id="ttsv-health" style="font-size:13px;color:#999"><?php _e('未测试'); ?></span>
                        </p>
                        <div id="ttsv-voice-list" style="margin-top:10px;font-size:13px;color:#666"></div>
                    </div>
                </div>

                <!-- 统计 -->
                <div class="typecho-list-operate clearfix">
                    <h3 style="margin:0"><?php _e('概览'); ?></h3>
                </div>

                <div class="typecho-table-wrap">
                    <ul class="ttsv-stats">
                        <li class="ttsv-stat">
                            <b class="ttsv-stat-num"><?php echo $stat['all']; ?></b>
                            <span class="ttsv-stat-label"><?php _e('总任务'); ?></span>
                        </li>
                        <li class="ttsv-stat">
                            <b class="ttsv-stat-num"><?php echo $stat['ready']; ?></b>
                            <span class="ttsv-stat-label"><?php _e('已就绪'); ?></span>
                        </li>
                        <li class="ttsv-stat">
                            <b class="ttsv-stat-num"><?php echo $stat['busy']; ?></b>
                            <span class="ttsv-stat-label"><?php _e('进行中'); ?></span>
                        </li>
                        <li class="ttsv-stat">
                            <b class="ttsv-stat-num"><?php echo $stat['failed']; ?></b>
                            <span class="ttsv-stat-label"><?php _e('失败'); ?></span>
                        </li>
                        <li class="ttsv-stat">
                            <b class="ttsv-stat-num"><?php echo ttsv_size($stat['size']); ?></b>
                            <span class="ttsv-stat-label"><?php _e('音频占用'); ?></span>
                        </li>
                    </ul>
                </div>

                <!-- 任务列表 -->
                <div class="typecho-list-operate clearfix">
                    <h3 style="margin:0"><?php _e('任务列表'); ?>（<?php echo count($jobs); ?>）</h3>
                </div>

                <div class="typecho-table-wrap">
                    <?php if (empty($jobs)) { ?>
                        <div class="typecho-list">
                            <div class="typecho-list-table-row notice">
                                <p><?php _e('还没有任何任务。到文章编辑页点「生成朗读音频」即可。'); ?></p>
                            </div>
                        </div>
                    <?php } else { ?>
                    <table class="typecho-list-table ttsv-panel-table">
                        <colgroup>
                            <col width="6%">
                            <col width="32%">
                            <col width="10%">
                            <col width="8%">
                            <col width="8%">
                            <col width="14%">
                            <col width="8%">
                            <col width="14%">
                        </colgroup>
                        <thead>
                        <tr>
                            <th><?php _e('cid'); ?></th>
                            <th><?php _e('标题'); ?></th>
                            <th><?php _e('状态'); ?></th>
                            <th><?php _e('时长'); ?></th>
                            <th><?php _e('大小'); ?></th>
                            <th><?php _e('更新时间'); ?></th>
                            <th><?php _e('播放'); ?></th>
                            <th><?php _e('操作'); ?></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($jobs as $job) { ?>
                        <tr id="ttsv-row-<?php echo intval($job['cid']); ?>" data-cid="<?php echo intval($job['cid']); ?>">
                            <td><?php echo intval($job['cid']); ?></td>
                            <td>
                                <?php
                                /*
                                 * 标题 → **原文**（不是音频文件）。
                                 * 固定链接由 TtsVoice_Plugin::permalinks() 用 Typecho 自己的
                                 * 路由拼出来，站点改固定链接形式也会跟着变。
                                 * 查不到（草稿/已删除/不是 publish）就退化成纯文本。
                                 */
                                $permalink = isset($permalinks[$job['cid']]) ? $permalinks[$job['cid']] : '';
                                ?>
                                <?php if ('' !== $permalink) { ?>
                                <a href="<?php echo ttsv_esc($permalink); ?>" target="_blank" rel="noopener"><?php echo ttsv_esc($job['title']); ?></a>
                                <?php } else { ?>
                                <?php echo ttsv_esc($job['title']); ?>
                                <?php } ?>
                                <?php
                                /*
                                 * 失败原因：轮询时会被 JS 就地改写（见 updateJobRow）。
                                 * `[提示]` 开头的不是失败，是「合成好了但有句话要说」（比如服务端没有
                                 * ffmpeg，实际给的是 WAV），用 is-notice 显示成灰色。
                                 */
                                $errText   = (string) $job['error'];
                                $errIsNote = 0 === strpos($errText, '[提示]');
                                ?>
                                <div class="ttsv-error<?php echo $errIsNote ? ' is-notice' : ''; ?>" style="font-size:12px;<?php echo '' === $errText ? 'display:none' : ''; ?>"><?php echo ttsv_esc($errText); ?></div>
                                <?php if (TtsVoice_Plugin::jobVoiceStale($job)) { ?>
                                <br><span class="ttsv-stale-badge" title="<?php _e('这篇音频是用之前的音色样本生成的，听起来还是旧音色'); ?>"><?php _e('音色已过期'); ?></span>
                                <?php } ?>
                            </td>
                            <td class="ttsv-status-cell">
                                <?php echo ttsv_status_dot($job); ?>
                                <?php if ('' !== $job['progress'] && '0/0' !== $job['progress']) { ?>
                                <span class="ttsv-progress"><?php echo ttsv_esc($job['progress']); ?></span>
                                <?php } ?>
                            </td>
                            <td class="ttsv-duration-cell"><?php echo '' === $job['duration'] ? '—' : ttsv_esc(round((float) $job['duration'])) . 's'; ?></td>
                            <td class="ttsv-size-cell"><?php echo 0 === intval($job['size']) ? '—' : ttsv_esc(ttsv_size($job['size'])); ?></td>
                            <td class="ttsv-updated-cell"><?php echo ttsv_esc(date('Y-m-d H:i', intval($job['updated']))); ?></td>
                            <td class="ttsv-play-cell">
                                <?php if ('ready' === $job['status'] || '' !== $job['audio']) { ?>
                                <button type="button"
                                        class="ttsv-play-tri"
                                        data-act="play"
                                        data-cid="<?php echo intval($job['cid']); ?>"
                                        data-audio="<?php echo ttsv_esc(TtsVoice_Plugin::audioUrl($job['audio'])); ?>"
                                        data-title="<?php echo ttsv_esc($job['title']); ?>"
                                        title="播放"
                                        aria-label="播放"></button>
                                <?php } else { ?>
                                <span class="ttsv-play-tri is-off" title="暂无音频" aria-label="暂无音频"></span>
                                <?php } ?>
                            </td>
                            <td class="ttsv-actions">
                                <?php
                                /*
                                 * 操作只有两个：生成 ｜ 删除。
                                 *
                                 * 【为什么没有「刷新进度」】进度是**自动**的：页面里有一段
                                 * 3 秒一次的 AJAX 轮询（见下方 ttsvTick()），只请求 batch 接口、
                                 * 只改这一行的单元格，不整页刷新。手动按钮既多余又会让人以为
                                 * 「不点就不动」，所以去掉了。
                                 */
                                ?>
                                <button type="button" class="ttsv-col-btn" data-act="gen" data-cid="<?php echo intval($job['cid']); ?>"><?php _e('生成'); ?></button>
                                ｜<button type="button" class="ttsv-col-btn" data-act="del" data-cid="<?php echo intval($job['cid']); ?>" data-danger="1"><?php _e('删除'); ?></button>
                            </td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                    <?php } ?>
                </div>

            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="<?php echo ttsv_esc(TtsVoice_Plugin::assetUrl('admin.css')); ?>">
<script>
window.__TTS_PANEL__ = <?php echo json_encode(array('action' => $action, 'token' => TtsVoice_Plugin::csrfToken()), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
</script>
<script>
(function () {
    'use strict';

    var cfg = window.__TTS_PANEL__ || {};
    var ACTION = cfg.action || '';
    var TOKEN = cfg.token || '';

    if (!ACTION) {
        return;
    }

    function request(method, action, data, callback) {
        var query = '';
        var body = '';

        if (data) {
            var parts = [];

            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) {
                    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(data[k]));
                }
            }

            if ('POST' === method) {
                body = parts.join('&');
            } else {
                query = parts.join('&');
            }
        }

        // CSRF token：POST 放进 body，GET 放进 query
        if ('POST' === method) {
            body += (body ? '&' : '') + '_token=' + encodeURIComponent(TOKEN);
        } else {
            query += (query ? '&' : '') + '_token=' + encodeURIComponent(TOKEN);
        }

        var xhr = new XMLHttpRequest();

        // 必须先把 open() 调完，再设置请求头，最后 send()
        xhr.open(method, ACTION + action + (query ? '?' + query : ''), true);

        if ('POST' === method) {
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        }

        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        xhr.onreadystatechange = function () {
            if (4 !== xhr.readyState) {
                return;
            }

            var res = null;

            try {
                res = JSON.parse(xhr.responseText);
            } catch (e) {
                res = { code: 500, msg: '服务器返回异常' };
            }

            callback(res || {});
        };

        xhr.send('POST' === method ? body : null);
    }

    /**
     * 提示条 —— 复用 Typecho 后台原生的顶部提示（与 assets/admin.js 的 toast 一致）
     *
     * 后台自带 admin/common-js.php 的实现：把
     *   <div class="message popup success|notice|error"><ul><li>消息</li></ul></div>
     * 插到 .typecho-head-nav 之后，样式与动画都来自后台自带的 CSS / jQuery UI。
     * 这样不写任何自定义样式就能与后台观感一致。
     */
    function toast(msg, isError) {
        var $ = window.jQuery;

        if (!$) {
            window.alert(msg);
            return;
        }

        var type = isError ? 'error' : 'success';

        $('.message.popup.ttsv-flash').remove();

        var text = $('<div>').text(String(msg)).html();
        var $box = $('<div class="message popup ' + type + '"><ul><li>' + text + '</li></ul></div>')
            .addClass('ttsv-flash')
            .hide();

        var $head = $('.typecho-head-nav');

        if ($head.length) {
            $box.insertAfter($head);
        } else {
            $box.prependTo(document.body);
        }

        $box.slideDown(function () {
            var $t = $(this);
            var color = isError ? '#FBC2C4' : '#C6D880';
            var stay = isError ? 9000 : 5000;

            if ($.fn.effect) {
                $t.effect('highlight', { color: color }).delay(stay).fadeOut(function () {
                    $t.remove();
                });
            } else {
                $t.delay(stay).fadeOut(function () {
                    $t.remove();
                });
            }
        });
    }

    /* 测试连接 */
    var testBtn = document.getElementById('ttsv-test');
    var healthEl = document.getElementById('ttsv-health');

    if (testBtn) {
        testBtn.addEventListener('click', function () {
            testBtn.disabled = true;
            healthEl.textContent = '测试中…';
            healthEl.style.color = '';

            request('GET', 'health', { _: Date.now() }, function (res) {
                testBtn.disabled = false;

                if (0 !== res.code) {
                    healthEl.textContent = res.msg || '连接失败';
                    healthEl.style.color = '#c0392b';
                    return;
                }

                healthEl.textContent = '连接正常（' + (res.data.ms || 0) + ' ms，队列 ' + ((res.data.nas && res.data.nas.jobs) || 0) + ' 个任务）';
                healthEl.style.color = '#46a05a';

                // 顺手刷新「上游模型驻留 / 冷加载」状态
                loadHostStatus();
            });
        });
    }

    /*
     * 上游模型驻留 / 冷加载状态
     *
     * 接收端 v1.2.0+ 新增的只读 GET /voice/status：回报上游当前驻留内存的模型与
     * cold_load_seconds。模型没驻留时（Qwen 刚重启、面板还在补载）提示
     * 「正在加载约 25 秒」，免得用户把「这次慢」当成「坏了」。
     *
     * 【故意不自动调用】只在点「测试连接」时查一次。
     * 因为 /voice/status 要由接收端去问上游，而上游合成是串行的 ——
     * 正在合成时查它会排队，等于在面板每次打开时白占一个 PHP worker 和几秒等待。
     * 旧接收端 / 不可达时它返回 ok=false，这里静默不显示，不影响任何功能。
     */
    var hostEl = document.getElementById('ttsv-host-status');

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function shortModel(m) {
        var parts = String(m).split('/');
        return parts[parts.length - 1];
    }

    function loadHostStatus() {
        if (!hostEl) {
            return;
        }

        request('GET', 'hoststatus', { _: Date.now() }, function (res) {
            if (!res || 0 !== res.code) {
                hostEl.textContent = '';
                return;
            }

            var d = res.data || {};

            if (!d.ok) {
                hostEl.textContent = '';
                return;
            }

            if (d.cold) {
                hostEl.innerHTML = '⚠ TTS 模型 <code>' + escHtml(shortModel(d.needed)) + '</code> 当前未驻留内存'
                    + ' —— 可能正在冷加载（约 ' + (parseInt(d.seconds, 10) || 25) + ' 秒后自动可用）；'
                    + '若长时间如此，请检查模型名与上游日志。';
                hostEl.style.color = '#c0392b';
            } else {
                var extra = (d.models && d.models.length)
                    ? '（上游已驻留 ' + d.models.length + ' 个模型）'
                    : '';

                hostEl.innerHTML = 'TTS 主机就绪：<code>' + escHtml(shortModel(d.needed)) + '</code> 已在内存中'
                    + escHtml(extra) + '。';
                hostEl.style.color = '#46a05a';
            }
        });
    }

    /* 拉取音色 */
    var voiceBtn = document.getElementById('ttsv-voices');
    var voiceBox = document.getElementById('ttsv-voice-list');

    /* 运行环境自检：用 button + JS 跳转（原来是 a 标签，与旁边两个按钮风格不一致） */
    var doctorBtn = document.getElementById('ttsv-doctor');

    if (doctorBtn) {
        doctorBtn.addEventListener('click', function () {
            window.location.href = <?php echo json_encode(
                $options->adminUrl . 'extending.php?panel=TtsVoice/panel.php&action=doctor',
                JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP
            ); ?>;
        });
    }

    if (voiceBtn) {
        voiceBtn.addEventListener('click', function () {
            voiceBtn.disabled = true;
            voiceBox.textContent = '拉取中…';

            request('GET', 'voices', { _: Date.now() }, function (res) {
                voiceBtn.disabled = false;

                if (0 !== res.code) {
                    voiceBox.textContent = res.msg || '拉取失败';
                    return;
                }

                var voices = (res.data && res.data.voices) || {};
                var names = [];

                // 兼容 {voices:[...]} 与 {voices:{...}} 两种返回
                if (Array.isArray(voices)) {
                    names = voices;
                } else if (voices.voices) {
                    names = voices.voices;
                } else {
                    for (var k in voices) {
                        if (Object.prototype.hasOwnProperty.call(voices, k)) {
                            names.push(k);
                        }
                    }
                }

                // 自建 Qwen3-TTS 本来就没有「预置音色列表」：
                // 有样本就用样本克隆，没样本就用主机内置的默认音色 —— 空列表不是错误。
                voiceBox.textContent = names.length
                    ? '可用音色（' + names.length + '）：' + names.join('、')
                    : '无需选择音色：默认使用 TTS 主机内置音色';

                // 同步型服务的音色由配置决定，服务端会附一段说明
                if (res.data && res.data.note) {
                    voiceBox.textContent += ' ｜ ' + res.data.note;
                }
            });
        });
    }

    /* ==================== 音色样本（参考音频） ==================== */

    function voiceMsg(text, isError) {
        var box = document.getElementById('ttsv-voice-msg');

        if (box) {
            box.textContent = text || '';
            box.style.color = isError ? '#c0392b' : '#3a7ca5';
        }
    }

    var voiceUpload = document.getElementById('ttsv-voice-upload');
    var voiceFile   = document.getElementById('ttsv-voice-file');

    // 试听：参考音频在 .voice 目录下（直链会被 nginx 拦），走带鉴权的接口。
    // 点播时才 new Audio()，页面里不放常驻 <audio> 元素，也不预拉文件。
    var voicePlay = document.getElementById('ttsv-voice-play');

    if (voicePlay) {
        voicePlay.addEventListener('click', function () {
            try {
                // 注意要带 ? —— ACTION 是 .../ttsvoice/，接口名是路径段，参数要跟在 ? 后面
                new Audio(ACTION + 'voice?_token=' + encodeURIComponent(TOKEN)).play();
            } catch (e) {
                toast('试听失败：' + (e && e.message ? e.message : '浏览器不支持音频播放'), true);
            }
        });
    }

    if (voiceUpload && voiceFile) {
        // 上传走 multipart，不能复用上面的 request()（那只发 urlencoded）
        voiceUpload.addEventListener('click', function () {
            if (!voiceFile.files || !voiceFile.files.length) {
                voiceMsg('请先选择音频文件', true);
                return;
            }

            var fd = new FormData();

            fd.append('voice', voiceFile.files[0]);
            fd.append('_token', TOKEN);

            voiceUpload.disabled = true;
            voiceMsg('上传并处理中…');

            var xhr = new XMLHttpRequest();

            xhr.open('POST', ACTION + 'uploadvoice', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onreadystatechange = function () {
                if (4 !== xhr.readyState) {
                    return;
                }

                voiceUpload.disabled = false;

                var res = null;

                try {
                    res = JSON.parse(xhr.responseText);
                } catch (e) {
                    res = { code: 500, msg: '服务器返回异常' };
                }

                if (res && 0 === res.code) {
                    toast(res.msg || '音色样本已更新', false);

                    // 重新加载：样本信息、路径、每行的「音色已过期」标记都要重算
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                } else {
                    voiceMsg((res && res.msg) ? res.msg : '上传失败', true);
                    toast((res && res.msg) ? res.msg : '上传失败', true);
                }
            };

            xhr.send(fd);
        });
    }

    var voiceClear = document.getElementById('ttsv-voice-clear');
    if (voiceClear) {
        voiceClear.addEventListener('click', function () {
            if (!window.confirm('确定删除当前音色样本？\n删除后就无法生成音频了 —— 需要重新上传样本才能再生成。')) {
                return;
            }

            request('POST', 'clearvoice', {}, function (res) {
                if (res && 0 === res.code) {
                    toast(res.msg || '已删除', false);
                    window.setTimeout(function () {
                        window.location.reload();
                    }, 700);
                } else {
                    voiceMsg((res && res.msg) ? res.msg : '删除失败', true);
                }
            });
        });
    }

    /* 重新同步到 TTS 主机 */
    var voiceSync = document.getElementById('ttsv-voice-sync');

    if (voiceSync) {
        voiceSync.addEventListener('click', function () {
            voiceSync.disabled = true;
            voiceMsg('正在推送到 TTS 主机…');

            request('POST', 'syncvoice', {}, function (res) {
                voiceSync.disabled = false;

                if (res && 0 === res.code) {
                    voiceMsg(res.msg || '已同步');
                    toast(res.msg || '已同步到 TTS 主机', false);
                } else {
                    var msg = (res && res.msg) ? res.msg : '同步失败';
                    voiceMsg(msg, true);
                    toast(msg, true);
                }
            });
        });
    }

    /*
     * 全部重生成过期音频
     *
     * submit 对异步型只是「建任务」，不做合成，所以这里可以快速逐篇排队；
     * 真正的合成由后续的轮询驱动（服务端每次请求只跑一块，排队即可）。
     */
    var regenBtn = document.getElementById('ttsv-regen-stale');

    if (regenBtn) {
        regenBtn.addEventListener('click', function () {
            var badges = document.querySelectorAll('.ttsv-stale-badge');
            var cids   = [];

            for (var i = 0; i < badges.length; i++) {
                var tr = badges[i].closest ? badges[i].closest('tr') : null;

                if (!tr || !tr.id) {
                    continue;
                }

                var cid = parseInt(tr.id.replace('ttsv-row-', ''), 10);

                if (cid > 0) {
                    cids.push(cid);
                }
            }

            if (!cids.length) {
                return;
            }

            if (!window.confirm('将重新生成 ' + cids.length + ' 篇「音色已过期」的音频。\n\n合成较慢（约 4.5 字/秒），会排队执行，可以离开页面。确定吗？')) {
                return;
            }

            regenBtn.disabled = true;

            var index = 0;
            var okCount = 0;
            var failed = [];

            function finish() {
                // 无论成功失败都必须解锁，否则只能刷新页面才能再点
                regenBtn.disabled = false;

                if (failed.length) {
                    voiceMsg('已提交 ' + okCount + ' 篇，失败 ' + failed.length + ' 篇'
                        + '（' + failed.slice(0, 3).join('、') + (failed.length > 3 ? '…' : '') + '）');
                    toast('有 ' + failed.length + ' 篇提交失败：' + failed[0], true);
                } else {
                    voiceMsg('已全部提交（' + okCount + ' 篇），进度会自动刷新');
                    toast('已提交 ' + okCount + ' 篇重生成', false);
                }

                /*
                 * 这里**不再整页刷新**：提交完这些行已经是 queued，
                 * 上面的 3 秒轮询会把进度、时长、大小一并刷出来。
                 * 刷新整页只会把用户刚看到的列表重建一遍，还会丢掉滚动位置。
                 */
                ttsvTick();
            }

            function next() {
                if (index >= cids.length) {
                    finish();
                    return;
                }

                var cid = cids[index++];

                request('POST', 'submit', { cid: cid }, function (res) {
                    if (!res || 0 !== res.code) {
                        // 失败不标「排队中」，否则轮询会一直等一个不存在的任务
                        failed.push('#' + cid + (res && res.msg ? '：' + res.msg : ''));
                        next();
                        return;
                    }

                    okCount++;

                    var r = document.getElementById('ttsv-row-' + cid);

                    if (r) {
                        setDotStatus(r, 'queued', '排队中');
                    }

                    delete pollSeen[cid];
                    delete noticeSeen[cid];

                    voiceMsg('已提交 ' + index + '/' + cids.length);
                    next();
                });
            }

            next();
        });
    }

    /*
     * 刷新一条任务的进度（顺便收尾）
     *
     * 【现在的架构】合成跑在 TTS 主机的 `/jobs` 队列上（receiver v1.3.0+），
     * 跟浏览器在不在**没有关系** —— 关掉页面任务照样跑完。
     * 这里只是一个「看一眼进度」的轻量轮询（每次 `status` 都是毫秒级查询）：
     *   - 还在跑 → 更新圆点/进度，稍后再看；
     *   - 就绪   → 插件会把整段音频下载回网站并标记 ready（这一步需要有人查一次）。
     *
     * 所以文案**不能**再说「页面别关」「每块几十秒」——那是老的浏览器驱动模式的写法。
     * 同一时刻只刷一条（pumpBusy），避免一堆请求堆在一起。
     */
    /* ============================================================== */
    /* 进度：3 秒一次的 AJAX 轮询（只改单元格，不整页刷新）              */
    /* ============================================================== */

    /*
     * 为什么必须自动轮询
     *
     * 网站数据库里的 progress 只是**镜像**：合成跑在 TTS 主机上（服务商侧 /jobs 队列，
     * 或回退模式的逐块合成），网站只有被「问一次」才会去同步一次。
     * 所以如果没人问，页面就会一直停在旧数字 —— 服务商那边 9/9 了，这边还写着 0/9。
     *
     * 【以前的两个极端，都不要再走】
     *   1. 每 5 秒 location.reload()：整页刷新，列表里有 done 就永远刷下去，非常烦；
     *   2. 完全手动：不点「刷新进度」就永远不动。
     *
     * 现在的做法：一次性 batch 查询（一个请求带上所有进行中的 cid）→ 只更新那一行的
     * 圆点/进度/时长/大小/时间/播放按钮/错误行。没有进行中的任务时**自动停下**，
     * 不再发请求；用户点「生成」会重新踢一下。
     */
    var POLL_MS  = 3000;
    var pollBusy = false;
    var pollSeen = {};
    var noticeSeen = {};   // cid -> true，成功但有话要说（[提示]）的只弹一次

    function setDotStatus(row, status, text) {
        var dot = row.querySelector('.ttsv-dot');

        if (!dot) {
            return;
        }

        dot.setAttribute('data-status', status);

        var sr = dot.querySelector('.ttsv-dot-sr');

        if (sr) {
            sr.textContent = text || status;
        }

        if (text) {
            dot.setAttribute('data-tip', text);
            dot.setAttribute('aria-label', text);
        }
    }

    function rowStatus(row) {
        var dot = row.querySelector('.ttsv-dot');
        return dot ? (dot.getAttribute('data-status') || 'none') : 'none';
    }

    function allRows() {
        return document.querySelectorAll('tr[id^="ttsv-row-"]');
    }

    function busyCids() {
        var rows = allRows();
        var out  = [];

        for (var i = 0; i < rows.length; i++) {
            var st = rowStatus(rows[i]);

            if ('queued' === st || 'running' === st || 'done' === st) {
                out.push(rows[i].getAttribute('data-cid'));
            }
        }

        return out;
    }

    function fmtDuration(sec) {
        sec = parseFloat(sec);

        return (isFinite(sec) && sec > 0) ? (Math.round(sec) + 's') : '—';
    }

    function fmtSize(bytes) {
        bytes = parseInt(bytes, 10);

        if (!isFinite(bytes) || bytes <= 0) {
            return '—';
        }

        if (bytes >= 1048576) {
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        if (bytes >= 1024) {
            return (bytes / 1024).toFixed(1) + ' KB';
        }

        return bytes + ' B';
    }

    function fmtTime(ts) {
        ts = parseInt(ts, 10);

        if (!isFinite(ts) || ts <= 0) {
            return '';
        }

        var d  = new Date(ts * 1000);
        var p  = function (n) { return (n < 10 ? '0' : '') + n; };

        return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate())
            + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }

    function setText(row, selector, text) {
        var el = row.querySelector(selector);

        if (el && '' !== text && el.textContent !== text) {
            el.textContent = text;
        }
    }

    function updateJobRow(cid, job) {
        var row = document.getElementById('ttsv-row-' + cid);

        if (!row || !job) {
            return;
        }

        var st = job.status || 'none';

        setDotStatus(row, st, job.statusText);

        // 进度：元素可能还不存在（服务端只在 progress 非空时才渲染），需要就地补一个
        var text = (job.progress && '0/0' !== job.progress) ? job.progress : '';
        var prog = row.querySelector('.ttsv-progress');

        if (prog) {
            if ('' === text) {
                prog.parentNode.removeChild(prog);
            } else if (prog.textContent !== text) {
                prog.textContent = text;
            }
        } else if ('' !== text) {
            var cell = row.querySelector('.ttsv-status-cell') || (row.querySelector('.ttsv-dot') || {}).parentNode;

            if (cell) {
                prog = document.createElement('span');
                prog.className = 'ttsv-progress';
                cell.appendChild(document.createTextNode(' '));
                cell.appendChild(prog);
                prog.textContent = text;
            }
        }

        setText(row, '.ttsv-duration-cell', fmtDuration(job.duration));
        setText(row, '.ttsv-size-cell', fmtSize(job.size));
        setText(row, '.ttsv-updated-cell', fmtTime(job.updated));

        // 失败原因就地更新（就绪后自动隐藏）；`[提示]` 开头的是提示，不是错误，走灰色
        var err = row.querySelector('.ttsv-error');

        if (err) {
            err.textContent = job.error || '';
            err.style.display = job.error ? '' : 'none';
            if (job.error && 0 === job.error.indexOf('[提示]')) {
                err.classList.add('is-notice');
            } else {
                err.classList.remove('is-notice');
            }
        }

        // 就绪后点亮播放按钮
        var playCell = row.querySelector('.ttsv-play-cell');

        if (playCell) {
            var hasBtn = !!playCell.querySelector('button.ttsv-play-tri');

            if ('ready' === st && job.audioUrl) {
                var b = playCell.querySelector('button.ttsv-play-tri');

                if (!b) {
                    b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'ttsv-play-tri';
                    b.setAttribute('data-act', 'play');
                    b.setAttribute('aria-label', '播放');
                    b.setAttribute('title', '播放');
                }

                b.setAttribute('data-cid', String(cid));
                b.setAttribute('data-title', job.title || '');

                // URL 变了（重新生成 / 换了格式）才写，并在播放中时先停掉
                if (b.getAttribute('data-audio') !== job.audioUrl) {
                    b.setAttribute('data-audio', job.audioUrl);
                }

                if (!b.parentNode) {
                    playCell.innerHTML = '';
                    playCell.appendChild(b);
                }
            } else if ('ready' !== st && '' === (job.audioUrl || '') && hasBtn) {
                // 被删掉/重生成 → 熄灭
                playCell.innerHTML = '<span class="ttsv-play-tri is-off" title="暂无音频" aria-label="暂无音频"></span>';
            }
        }
    }

    function ttsvTick() {
        var cids = busyCids();

        if (!cids.length) {
            return;             // 没有进行中的任务 → 不发请求（轮询自然停摆）
        }

        if (pollBusy) {
            return;             // 上一次还没回来（回退模式一次合成要几十秒）
        }

        pollBusy = true;

        request('GET', 'batch', { cids: cids.join(','), _: Date.now() }, function (res) {
            pollBusy = false;

            if (!res || 0 !== res.code) {
                return;         // 网络抖动就静默跳过，下一个 3 秒再试
            }

            var jobs = (res.data && res.data.jobs) ? res.data.jobs : {};

            for (var key in jobs) {
                if (!Object.prototype.hasOwnProperty.call(jobs, key)) {
                    continue;
                }

                var job = jobs[key];
                var id  = parseInt(key, 10);

                updateJobRow(id, job);

                // 失败只提示一次（轮询会一直看到 failed，别每 3 秒弹一次）
                if (job && 'failed' === job.status && !pollSeen[id]) {
                    pollSeen[id] = true;
                    toast('合成失败：' + (job.error || '未知错误'), true);
                }

                // 提示也只弹一次：不是错误，正常样式
                if (job && job.error && 0 === job.error.indexOf('[提示]') && !noticeSeen[id]) {
                    noticeSeen[id] = true;
                    toast(job.error.replace('[提示] ', ''), false);
                }
            }
        });
    }

    window.setInterval(ttsvTick, POLL_MS);

    /* 任务操作 */
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.ttsv-col-btn') : null;

        if (!btn || btn.disabled) {
            return;
        }

        var cid = parseInt(btn.getAttribute('data-cid'), 10);
        var act = btn.getAttribute('data-act');

        if (!cid) {
            return;
        }

        if ('del' === act && !window.confirm('确定删除这篇的朗读音频？')) {
            return;
        }

        btn.disabled = true;

        request('POST', 'del' === act ? 'delete' : 'submit', { cid: cid }, function (res) {
            btn.disabled = false;

            if (0 !== res.code) {
                toast(res.msg || '操作失败', true);
                return;
            }

            if ('del' === act) {
                // 直接把这一行从表里拿掉，不整页刷新
                var row = document.getElementById('ttsv-row-' + cid);

                if (row && row.parentNode) {
                    row.parentNode.removeChild(row);
                }

                toast('已删除');
                return;
            }

            /*
             * 生成：提交成功。进度由上面的轮询自动跟，不需要用户再点任何东西。
             *
             * 【两种模式，文案必须分开】不能一律说「可以离开页面」：
             *   jobId 以 j- 开头 → 服务商侧 /jobs 队列（receiver v1.3.0+），关页面照样跑完；
             *   其他（如 qwen-<cid>-<ts>）→ 浏览器驱动的回退路径，**本页得开着**，
             *                                否则任务停在 queued 不动。
             */
            var remote = !!(res.data && res.data.jobId && 0 === String(res.data.jobId).indexOf('j-'));

            if (res.data && res.data.reused) {
                toast(remote ? '任务已在进行中' : '任务已在进行中（本页需保持打开）');
            } else {
                toast(remote
                    ? '已提交，合成在 TTS 主机上进行（可以离开页面）'
                    : '已提交，正在逐块合成（本页需要保持打开）');
            }

            // 立刻把这一行标成 queued 并马上问一次，别等下一个 3 秒
            var r = document.getElementById('ttsv-row-' + cid);

            if (r) {
                setDotStatus(r, 'queued', '排队中');
            }

            delete pollSeen[cid];
            delete noticeSeen[cid];
            ttsvTick();
        });
    });
})();
</script>

<?php
include ttsv_admin_path('footer.php');
