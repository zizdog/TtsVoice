<?php
if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

require_once dirname(__FILE__) . '/compat.php';
require_once dirname(__FILE__) . '/Plugin.php';
require_once dirname(__FILE__) . '/Providers.php';

/**
 * TtsVoice - AJAX 后端
 *
 * 路由 /ttsvoice/{动作}，全部要求管理员登录：
 *   submit  从 table.contents 取标题正文 → 交给当前服务商合成
 *   status  查单篇（异步型会同步远程状态）
 *   batch   批量查，进行中的任务会同步
 *   delete  删远程任务 + 本地记录 + 本地音频
 *   voices  拉取当前服务商的可用音色
 *   health  当前服务商的连通性/鉴权自检
 *
 * 1.6.0 起具体的 TTS 调用下沉到 Providers.php（TtsVoice_Provider::make()），
 * 本类只负责鉴权、CSRF、取正文、文本清洗、任务读写与 JSON/状态码输出。
 *
 * @package TtsVoice
 * @author  zizdog
 * @version 1.6.8
 */
class TtsVoice_Action extends Typecho_Widget
{
    /**
     * Widget 初始化
     *
     * @return void
     */
    public function execute()
    {
    }

    /**
     * 兜底动作
     *
     * @return void
     */
    public function action()
    {
        $this->dispatch();
    }

    /**
     * 路由入口：/ttsvoice/[do:alpha]
     *
     * @return void
     */
    public function dispatch()
    {
        $do = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', (string) $this->param('do', '')));

        $known = array('submit', 'status', 'batch', 'delete', 'voices', 'health', 'hoststatus', 'uploadvoice', 'clearvoice', 'syncvoice', 'voice');

        if (!in_array($do, $known, true)) {
            return $this->json(array(), 404, '接口不存在：' . $do);
        }

        // 写操作仅允许 POST（以前 GET 也能触发，等于给 CSRF 开了后门）
        if (in_array($do, array('submit', 'delete', 'uploadvoice', 'clearvoice', 'syncvoice'), true) && !$this->isPost()) {
            return $this->json(array(), 405, '该操作仅支持 POST');
        }

        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        // 所有接口都校验 CSRF token（token 与会话绑定，见 Plugin::csrfToken()）
        if (!$this->csrfValid()) {
            return $this->json(array(), 403, '请求校验失败，请刷新页面后重试');
        }

        // 【关键】验完登录与 CSRF 就**立刻释放 PHP 会话锁**。
        //
        // Typecho 在后台请求里 @session_start()，而 PHP 默认的 files 会话处理器
        // 会**锁住会话文件直到请求结束**（内核里没有 session_write_close）。
        // 本插件的 status/batch 一次要跑一整块合成（几十秒），期间：
        //   同一个浏览器的其它后台请求（比如「管理 → 朗读」翻页、点菜单）
        //   会一直等这把锁 —— 表现就是「整个后台非常卡 / 打不开」。
        //
        // 这里之后只用会话做鉴权，不再读写 $_SESSION，所以可以安全提前关闭。
        if (function_exists('session_status') && PHP_SESSION_ACTIVE === session_status()) {
            @session_write_close();
        }

        switch ($do) {
            case 'submit':
                return $this->actSubmit();

            case 'status':
                return $this->actStatus();

            case 'batch':
                return $this->actBatch();

            case 'delete':
                return $this->actDelete();

            case 'voices':
                return $this->actVoices();

            case 'health':
                return $this->actHealth();

            case 'hoststatus':
                return $this->actHostStatus();

            case 'uploadvoice':
                return $this->actUploadVoice();

            case 'clearvoice':
                return $this->actClearVoice();

            case 'syncvoice':
                return $this->actSyncVoice();

            case 'voice':
                return $this->actVoice();
        }

        return $this->json(array(), 404, '接口不存在：' . $do);
    }

    /* ================================================================== */
    /* 动作                                                                */
    /* ================================================================== */

    /**
     * 提交合成
     *
     * 统一入口：拿到当前服务商，按契约调用 submit()。
     *   - 异步型（local）：先做「已在队列中就复用」短路，提交成功后由本方法落库 queued
     *   - 同步型（azure/openai）：服务商在 submit() 内写完音频并落库 ready，本方法对齐一次
     *
     * @return void
     */
    protected function actSubmit()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $cid = intval($this->post('cid', 0));

        if ($cid <= 0) {
            return $this->json(array(), 400, '缺少文章 ID');
        }

        $post = $this->content($cid);

        if (!$post) {
            return $this->json(array(), 404, '文章不存在');
        }

        // 清洗成适合朗读的纯文本：
        // 去 Markdown / HTML / 主题短代码 / 古文注音标记，公式统一读作「公式」
        $source = (string) $post['text'];
        $text   = TtsVoice_Plugin::cleanText($source);
        $title  = TtsVoice_Plugin::cleanTitle($post['title']);

        if ('' === $text) {
            return $this->json(array(), 400, '正文清洗后为空，无法合成');
        }

        $provider = $this->provider();

        // 只有异步型才有「排队中」概念；同步型每次提交都应重新生成
        if ($provider->isAsync()) {
            $existing = TtsVoice_Plugin::job($cid);

            if ($existing && in_array($existing['status'], array('queued', 'running', 'done'), true)) {
                return $this->json(array(
                    'cid'     => $cid,
                    'status'  => $existing['status'],
                    'reused'  => true,
                    'jobId'   => $existing['job_id'],
                ), 0, '任务已在进行中');
            }
        }

        // 第 4 个参数是原始 Markdown（同步型据此定位章节起点；异步型忽略）
        $result = $provider->submit($cid, $title, $text, $source);

        if (empty($result['ok'])) {
            // 不写「NAS」：现在的服务商可能是自建 Qwen3-TTS / Azure / OpenAI，用中性文案
            return $this->json(array(), 502, '提交失败：' . (isset($result['error']) ? $result['error'] : '未知错误'));
        }

        if ($provider->isAsync()) {
            $jobId  = (string) $result['jobId'];
            $status = '' === (string) $result['status'] ? 'queued' : (string) $result['status'];

            /*
             * 【不要在这里再落一次库】异步任务（Qwen3-TTS）的 submit() 已经自己写好了
             * job 行（job_id、voice 标签、progress 0/N）。以前不分服务商统一再写一次，
             * 把 voice 覆盖成本地音色名、progress 冲成 0/0 ——
             * 历史上 cid=612/682 那种脏行就是这么来的。
             * （1.6.8 起 tts-worker(local) 已移除，这里不再需要任何服务商特判。）
             */

            return $this->json(array(
                'cid'    => $cid,
                'jobId'  => $jobId,
                'status' => $status,
            ), 0, '已提交，正在合成');
        }

        // 同步型：provider 已落库；这里按返回值再对齐一次，避免半途异常导致状态不一致
        TtsVoice_Plugin::saveJob($cid, array(
            'job_id'   => '',
            'status'   => '' === (string) $result['status'] ? 'ready' : (string) $result['status'],
            'title'    => $title,
            'voice'    => (string) $result['voice'],
            'speed'    => '',
            'progress' => (string) $result['progress'],
            'duration' => (string) $result['duration'],
            'size'     => intval($result['size']),
            'chapters' => $result['chapters'],
            'audio'    => (string) $result['audio'],
            'error'    => '',
        ));

        return $this->json(array(
            'cid'      => $cid,
            'status'   => (string) $result['status'],
            'audio'    => (string) $result['audio'],
            'provider' => $provider->id(),
        ), 0, '合成完成，已就绪');
    }

    /**
     * 查单篇（含同步）
     *
     * 【修复】早期版本这里只读本地表，编辑页轮询可能卡在旧状态。
     * 现在与 batch() 一致，会先调 syncJob()。
     *
     * @return void
     */
    protected function actStatus()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $cid = intval($this->param('cid', 0));

        if (0 === $cid) {
            $cid = intval($this->post('cid', 0));
        }

        if ($cid <= 0) {
            return $this->json(array(), 400, '缺少文章 ID');
        }

        $job = $this->syncJob($cid);

        return $this->json(array(
            'cid'      => $cid,
            'status'   => $job ? $job['status'] : 'none',
            'job'      => $job ? $this->publicJob($job) : NULL,
            'statusText' => TtsVoice_Plugin::statusText($job ? $job['status'] : 'none'),
        ));
    }

    /**
     * 批量查（列表页轮询用）
     *
     * @return void
     */
    protected function actBatch()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $raw  = (string) $this->param('cids', '');

        if ('' === $raw) {
            $raw = (string) $this->post('cids', '');
        }

        $cids = array_values(array_filter(array_map('intval', preg_split('/[,\s]+/', $raw))));

        if (empty($cids)) {
            return $this->json(array('jobs' => array()));
        }

        $jobs = array();

        foreach ($cids as $cid) {
            // 只同步「仍在进行中」的任务，已完成或已就绪的直接用本地数据
            $job = TtsVoice_Plugin::job($cid);

            if ($job && in_array($job['status'], array('queued', 'running', 'done'), true)) {
                $job = $this->syncJob($cid, $job);
            }

            $jobs[$cid] = $job ? $this->publicJob($job) : NULL;
        }

        return $this->json(array('jobs' => $jobs));
    }

    /**
     * 删除任务（远程任务 + 本地记录 + 本地音频）
     *
     * @return void
     */
    protected function actDelete()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $cid = intval($this->post('cid', 0));

        if (0 === $cid) {
            $cid = intval($this->param('cid', 0));
        }

        if ($cid <= 0) {
            return $this->json(array(), 400, '缺少文章 ID');
        }

        $job = TtsVoice_Plugin::job($cid);

        if ($job) {
            // 远程任务：local 会 DELETE /v1/jobs/{id}；同步型没有远程任务
            try {
                $this->provider()->delete($job);
            } catch (Throwable $e) {
                // 远程删除失败不阻塞本地清理
            }

            // 本地音频：只删本插件自己生成的文件
            if ('' !== $job['audio']) {
                $abs = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . ltrim($job['audio'], '/');

                if (file_exists($abs) && is_writable($abs)) {
                    @unlink($abs);
                }
            }
        }

        TtsVoice_Plugin::deleteJob($cid);

        return $this->json(array('cid' => $cid, 'status' => 'none'), 0, '已删除');
    }

    /**
     * 拉取当前服务商的可用音色
     *
     * @return void
     */
    protected function actVoices()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $provider = $this->provider();
        $result   = $provider->voices();

        if (empty($result['ok'])) {
            return $this->json(array(), 502, '拉取失败：' . (isset($result['error']) ? $result['error'] : '未知错误'));
        }

        return $this->json(array(
            'voices'   => $result['voices'],
            'note'     => isset($result['note']) ? (string) $result['note'] : '',
            'provider' => $provider->id(),
        ));
    }

    /**
     * 健康检查（供后台面板测试连通性）
     *
     * @return void
     */
    protected function actHealth()
    {
        if (!$this->guard()) {
            return $this->json(array(), 403, '仅管理员可操作');
        }

        $provider = $this->provider();
        $result   = $provider->health();

        if (empty($result['ok'])) {
            return $this->json(array(
                'ok'       => false,
                'error'    => (string) $result['error'],
                'ms'       => intval($result['ms']),
                'provider' => $provider->id(),
            ), 502, '无法连接服务：' . (string) $result['error']);
        }

        return $this->json(array(
            'ok'       => true,
            'ms'       => intval($result['ms']),
            'nas'      => $result['data'],
            'provider' => $provider->id(),
        ), 0, '连接正常');
    }

    /**
     * TTS 主机上游状态（只读）
     *
     * 转发接收端 v1.2.0+ 的 `GET /voice/status`：上游当前驻留内存的模型、
     * 冷加载耗时。面板据此在合成前提示「模型正在加载约 25 秒」。
     *
     * 【旧接收端 / 不可达时】返回 ok=false，但**不算错误**（HTTP 200、code=0），
     * 让面板静默降级 —— 不能因为查不到状态就挡住正常合成。
     *
     * @return void
     */
    protected function actHostStatus()
    {
        $status = TtsVoice_Plugin::ttsHostStatus();
        $info   = TtsVoice_Plugin::coldModelInfo($status);

        return $this->json(array(
            'ok'        => !empty($status['ok']),
            'error'     => isset($status['error']) ? (string) $status['error'] : '',
            'version'   => isset($status['version']) ? (string) $status['version'] : '',
            'reachable' => !empty($status['reachable']),
            'models'    => isset($status['models']) ? $status['models'] : array(),
            // 本次合成要用的模型 & 它冷不冷
            'needed'    => $info['needed'],
            'known'     => !empty($info['known']),
            'cold'      => !empty($info['cold']),
            'seconds'   => (int) $info['seconds'],
        ), 0, '');
    }

    /* ================================================================== */
    /* 核心：任务状态同步（委托给当前服务商）                                */
    /* ================================================================== */

    /**
     * 当前服务商
     *
     * @return TtsVoice_Provider
     */
    protected function provider()
    {
        return TtsVoice_Provider::make(TtsVoice_Plugin::opt('provider', 'openai'));
    }

    /**
     * 上传音色样本（参考音频）
     *
     * 上传后由 TtsVoice_Plugin::processRefAudio() 统一处理：裁到固定秒数、
     * 转 24kHz 单声道 wav。参考音频的规格直接决定克隆效果，让用户自己准备容易出错。
     *
     * 音色变了以后，之前用旧音色生成的音频就「过期」了 —— 这里只统计数量并回报，
     * 具体重生成哪些由用户在面板上决定（不自动重跑，避免换一次音色就把整站任务排满）。
     *
     * @return void
     */
    protected function actUploadVoice()
    {
        if (!isset($_FILES['voice']) || !is_array($_FILES['voice'])) {
            return $this->json(array(), 400, '没有收到上传文件');
        }

        $file = $_FILES['voice'];
        $err  = isset($file['error']) ? intval($file['error']) : UPLOAD_ERR_NO_FILE;

        if (UPLOAD_ERR_OK !== $err) {
            return $this->json(array(), 400, '上传失败：' . TtsVoice_Plugin::uploadErrorText($err));
        }

        $tmp = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';

        if ('' === $tmp || !is_uploaded_file($tmp)) {
            return $this->json(array(), 400, '上传文件无效');
        }

        // 参考音频裁完只有几百 KB；给 20MB 的余量足够容纳无损原文件
        if (isset($file['size']) && intval($file['size']) > 20 * 1024 * 1024) {
            return $this->json(array(), 400, '文件过大（上限 20MB）');
        }

        $before = TtsVoice_Plugin::refAudioHash();
        $result = TtsVoice_Plugin::processRefAudio($tmp);

        if (empty($result['ok'])) {
            return $this->json(array(), 500, isset($result['error']) ? $result['error'] : '处理失败');
        }

        $after   = TtsVoice_Plugin::refAudioHash();
        $changed = ($before !== $after);

        // 配了接收端就顺手推到 TTS 主机，并把返回的远端路径写进配置 ——
        // 这样「网站与 TTS 不同机」时路径是全自动的，不用用户自己去 mini 上查。
        $sync = TtsVoice_Plugin::syncRefAudio();

        /*
         * 拼提示语。三块内容，按「一定要说」到「顺带说」排：
         *   1. 本地处理结果（已更新 / 与原先相同）
         *   2. 推送到 TTS 主机成没成 —— 失败时后面还要补一句「本站能做什么」
         *      （接收端的报错只讲它自己那边，用户容易以为只能去 TTS 主机装 ffmpeg）
         *   3. processRefAudio 的 warning（本机没 ffmpeg → 样本没裁剪/没转码）。
         *      这是「要提醒但不该报错」的话，所以放在括号里，成功失败都要说。
         */
        $warning = isset($result['warning']) ? trim((string) $result['warning']) : '';
        $msg     = $changed ? '音色样本已更新' : '音色样本已更新（与原先相同）';

        if (empty($sync['ok'])) {
            $msg .= '，但推送到 TTS 主机失败：' . $sync['error'];

            $hint = TtsVoice_Plugin::refPushFailHint();

            if ('' !== $hint) {
                $msg .= '。' . $hint;
            }
        } elseif (empty($sync['skipped'])) {
            $msg .= '，已推送到 TTS 主机';
        }

        if ('' !== $warning) {
            $msg .= '（' . $warning . '）';
        }

        return $this->json(array(
            'info'    => TtsVoice_Plugin::refAudioInfo(),
            'changed' => $changed,
            'stale'   => $changed ? TtsVoice_Plugin::staleJobCount() : 0,
            'warning' => isset($result['warning']) ? (string) $result['warning'] : '',
            'sync'    => $sync,
        ), empty($sync['ok']) ? 207 : 0, $msg);
    }

    /**
     * 重新把本机音色样本推送到 TTS 主机
     *
     * 换过接收端地址、或接收端当时不在线，用这个补推一次。
     *
     * @return void
     */
    protected function actSyncVoice()
    {
        $sync = TtsVoice_Plugin::syncRefAudio();

        if (empty($sync['ok'])) {
            $msg = '推送失败：' . $sync['error'];
            $hint = TtsVoice_Plugin::refPushFailHint();

            if ('' !== $hint) {
                $msg .= '。' . $hint;
            }

            return $this->json(array('sync' => $sync), 502, $msg);
        }

        if (!empty($sync['skipped'])) {
            return $this->json(array('sync' => $sync), 0, '未配置接收端，无需推送（同机 / 共享存储）');
        }

        $msg = '已推送到 TTS 主机：' . $sync['path'];

        if (empty($sync['saved'])) {
            $msg .= '（但路径没能写进配置，请手动填写）';
        }

        return $this->json(array(
            'sync' => $sync,
            'info' => TtsVoice_Plugin::refAudioInfo(),
        ), 0, $msg);
    }

    /**
     * 删除音色样本
     *
     * @return void
     */
    protected function actClearVoice()
    {
        $file = TtsVoice_Plugin::refAudioFile();

        if (is_file($file) && !@unlink($file)) {
            return $this->json(array(), 500, '删除失败：' . $file);
        }

        // 【连带清掉路径配置】样本没了，路径留着只会让状态变得含糊。
        // 清掉后 hasCloneVoice() 立刻为 false —— 而样本是**唯一的音色来源**，
        // 所以此后「生成」会被直接拒绝，直到重新上传样本为止。
        TtsVoice_Plugin::saveSettings(array('refAudioPath' => ''));

        return $this->json(array(
            'info' => TtsVoice_Plugin::refAudioInfo(),
        ), 0, '音色样本已删除');
    }

    /**
     * 试听音色样本（带鉴权转发）
     *
     * 参考音频存在点开头的 .voice 目录里，直链会被 nginx 的 dotfile 规则拦掉
     * （这正是我们要的 —— 原声被公开下载就能被拿去克隆）。
     * 但面板里还得能试听，所以由这里带管理员鉴权 + CSRF token 转发。
     *
     * 注意：这里直接吐二进制，不能走 json()。
     *
     * @return void
     */
    protected function actVoice()
    {
        $file = TtsVoice_Plugin::refAudioFile();

        if (!is_file($file)) {
            return $this->json(array(), 404, '还没有音色样本');
        }

        $size = (int) @filesize($file);

        if ($size <= 0) {
            return $this->json(array(), 500, '音色样本文件为空');
        }

        try {
            $this->response->setStatus(200);
            $this->response->setContentType('audio/wav');
            $this->response->setHeader('Content-Length', (string) $size);
            $this->response->setHeader('Content-Disposition', 'inline; filename="voice-sample.wav"');
            // private：允许浏览器自己缓存，但禁止中间代理留存
            $this->response->setHeader('Cache-Control', 'private, max-age=60');
            $this->response->setHeader('X-Content-Type-Options', 'nosniff');
        } catch (Throwable $e) {
            if (!headers_sent()) {
                header('Content-Type: audio/wav');
                header('Content-Length: ' . $size);
                header('Cache-Control: private, max-age=60');
            }
        }

        @readfile($file);
        exit;
    }

    /**
     * 同步一条任务：把服务商返回的补丁写回本地并返回最新记录
     *
     * 异步型（local）会查远程任务，done 时下载音频；
     * 同步型（azure/openai）的 poll() 返回 null，等同于直接读本地。
     *
     * @param int       $cid
     * @param array|null $job 可选，传入已知记录避免重复查询
     * @return array|null
     */
    protected function syncJob($cid, $job = NULL)
    {
        $cid = intval($cid);

        if (NULL === $job) {
            $job = TtsVoice_Plugin::job($cid);
        }

        if (!$job) {
            return $job;
        }

        $provider = $this->provider();

        try {
            $patch = $provider->poll($job);
        } catch (Throwable $e) {
            // 服务商实现异常不应影响接口可用性
            return $job;
        }

        if (is_array($patch) && !empty($patch)) {
            TtsVoice_Plugin::saveJob($cid, $patch);

            return TtsVoice_Plugin::job($cid);
        }

        return $job;
    }

    /* ================================================================== */
    /* 工具                                                                */
    /* ================================================================== */
    /**
     * 读取文章内容
     *
     * @param int $cid
     * @return array|null
     */
    protected function content($cid)
    {
        try {
            $db  = ttsv_db();
            $row = $db->fetchRow(
                $db->select('title', 'text')->from('table.contents')
                    ->where('cid = ?', intval($cid))
                    ->limit(1)
            );

            return is_array($row) && !empty($row) ? $row : NULL;
        } catch (Throwable $e) {
            return NULL;
        }
    }

    /**
     * 对外暴露的任务字段（不返回正文）
     *
     * @param array $job
     * @return array
     */
    protected function publicJob($job)
    {
        return array(
            'cid'      => intval($job['cid']),
            'jobId'    => (string) $job['job_id'],
            'status'   => (string) $job['status'],
            'statusText' => TtsVoice_Plugin::statusText($job['status']),
            'title'    => (string) $job['title'],
            'voice'    => (string) $job['voice'],
            'speed'    => (string) $job['speed'],
            'progress' => (string) $job['progress'],
            'duration' => (string) $job['duration'],
            'size'     => intval($job['size']),
            'audio'    => (string) $job['audio'],
            'audioUrl' => '' === $job['audio'] ? '' : TtsVoice_Plugin::audioUrl($job['audio']),
            'error'    => (string) $job['error'],
            'updated'  => intval($job['updated']),
        );
    }

    /**
     * 管理员校验
     *
     * @return bool
     */
    protected function guard()
    {
        try {
            $user = Typecho_Widget::widget('Widget_User');

            return $user && $user->hasLogin() && $user->pass('administrator', true);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 当前请求是否为 POST
     *
     * @return bool
     */
    protected function isPost()
    {
        try {
            return (bool) $this->request->isPost();
        } catch (Throwable $e) {
            return isset($_SERVER['REQUEST_METHOD']) && 'POST' === strtoupper($_SERVER['REQUEST_METHOD']);
        }
    }

    /**
     * 校验 CSRF token
     *
     * 前端从 window.__TTS_ADMIN__.token / window.__TTS_PANEL__.token 取值，
     * 以 _token 参数提交（POST body 或 GET query 均可）。
     *
     * @return bool
     */
    protected function csrfValid()
    {
        $sent = '';

        if (isset($_POST['_token'])) {
            $sent = (string) $_POST['_token'];
        } elseif (isset($_GET['_token'])) {
            $sent = (string) $_GET['_token'];
        }

        try {
            return TtsVoice_Plugin::csrfValid($sent);
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * 读 POST 参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function post($key, $default = NULL)
    {
        return isset($_POST[$key]) ? $_POST[$key] : $default;
    }

    /**
     * 读路由 / GET 参数
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function param($key, $default = NULL)
    {
        try {
            $value = $this->request->get($key, NULL);

            if (NULL !== $value && '' !== $value) {
                return $value;
            }
        } catch (Throwable $e) {
            // 忽略
        }

        $fromRoute = $this->parameter->{$key};

        return NULL === $fromRoute || '' === $fromRoute ? $default : $fromRoute;
    }

    /**
     * 输出 JSON 并结束
     *
     * 同时把业务码映射成真实的 HTTP 状态码 —— 以前 403/404/502 一律返回 200，
     * 协议层完全看不出失败，监控和排查都很痛苦。
     *
     * @param array  $data
     * @param int    $code 0 表示成功
     * @param string $msg
     * @return void
     */
    protected function json($data = array(), $code = 0, $msg = '')
    {
        $status = $this->httpStatus($code);

        // 【必须走 Typecho 的 Response】
        // Common::init() 用 ob_start 注册了回调，输出结束时会调用
        // Response::sendHeaders()，其中 header('HTTP/1.1 <自身 status>', true) 会覆盖状态码。
        // 只调 http_response_code() 会被它改回 200。
        try {
            $this->response->setStatus($status);
        } catch (Throwable $e) {
            if (!headers_sent()) {
                http_response_code($status);
            }
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }

        echo json_encode(array(
            'code' => intval($code),
            'msg'  => (string) $msg,
            'data' => NULL === $data ? array() : $data,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    }

    /**
     * 业务码 → HTTP 状态码
     *
     * @param int $code
     * @return int
     */
    protected function httpStatus($code)
    {
        $code = intval($code);

        if (0 === $code) {
            return 200;
        }

        /*
         * 【2xx 原样放行】以前只放行 0/4xx/5xx，其余一律 return 500 ——
         * 于是「部分成功」的 207（音色样本存好了、但推送 TTS 主机失败）
         * 在 nginx 里变成 500，看起来像 PHP 崩了，其实只是推送那一步没过。
         * 排查时被这个假 500 带偏过一次（2026-09-15）。
         */
        if ($code >= 200 && $code <= 299) {
            return $code;
        }

        if (in_array($code, array(400, 401, 403, 404, 405, 409, 422, 429), true)) {
            return $code;
        }

        if ($code >= 500 && $code <= 599) {
            return $code;
        }

        return 500;
    }
}
