<?php
/**
 * Typecho 文章朗读插件
 *
 *
 * @package TtsVoice
 * @author  zizdog
 * @version 1.7.0
 * @link    https://zizdog.com
 */

namespace {

    if (!defined('__TYPECHO_ROOT_DIR__')) {
        exit;
    }

    require_once __DIR__ . '/compat.php';
    require_once __DIR__ . '/Action.php';

    // 防止重复包含时重复声明类
    if (class_exists('TtsVoice_Plugin_Base', false)) {
        return;
    }

    class TtsVoice_Plugin_Base implements Typecho_Plugin_Interface
    {
        /** 插件版本 */
        const VERSION = '1.7.0';

        /** Action 路由名 */
        const ROUTE = 'ttsvoice';

        /** 后台菜单标题 */
        const MENU_TITLE = '朗读';

        /**
         * 挂载到哪个一级菜单下
         *
         * Typecho 的 $parentNodes = [null, 控制台, 撰写, 管理, 设置]
         * 索引 3 = 「管理」。
         *
         * 【重要】不要用 Helper::addMenu() 新建一级菜单 —— 它每次调用都会
         * 往 panelTable['parent'] 追加一个新索引，反复启用就会堆积出多个菜单。
         * 挂到已有菜单下做二级面板，并在注册前先 remove 保证幂等。
         */
        const MENU_INDEX = 3;

        /** 后台面板相对路径（相对于 usr/plugins） */
        const PANEL_FILE = 'TtsVoice/panel.php';

        /** 音频存放目录（相对于站点根） */
        const AUDIO_DIR = 'usr/uploads/tts';

        /**
         * 参考音频裁剪长度（秒）
         *
         * 音色克隆的参考音频不是越长越好：10~15 秒已经足够捕捉音色，
         * 太长反而会拖慢每次合成的编码开销（参考音频每块都要重新编码一次）。
         */
        const REF_AUDIO_SECONDS = 12;

        /**
         * 「音色」下拉里代表「自定义音色」的特殊值
         *
         * 它**不是** Qwen3-TTS 的说话人名 —— 选中它表示「用上传的样本做克隆」。
         * 只有「选中它 + 真的上传了样本」才会把请求切到 Base 模型；
         * 现在**只有克隆一条路**：音色唯一来源是「音色样本」。
         */

        /* ============================================================== */
        /* 生命周期                                                        */
        /* ============================================================== */

        /**
         * 激活插件
         *
         * @return string
         */
        public static function activate()
        {
            try {
                self::installTable();
                self::installRoute(true);
                self::installMenu();
                self::installHooks();
            } catch (Throwable $e) {
                throw new Typecho_Plugin_Exception('TtsVoice 激活失败：' . ttsv_throwable_message($e));
            }

            // 恢复上次停用前的配置。
            // 核心在停用时会把 plugin:TtsVoice 整行删掉（见 CONFIG_BACKUP 注释），
            // 所以这里必须主动恢复，否则每次停用/启用都会丢配置。
            $restored = false;

            try {
                $restored = self::restoreConfigBackup();
            } catch (Throwable $e) {
                // 恢复失败不影响启用
            }

            return $restored
                ? _t('TtsVoice 已启用，数据表已建立，<b>已恢复停用前的设置</b>。')
                : _t('TtsVoice 已启用，数据表已建立。请先到设置里填写服务地址与 API Key。');
        }

        /**
         * 注册钩子
         *
         * 三个挂载点：
         *   Widget_Archive::footer        前台自动插入播放器
         *   admin/write-post.php::option  编辑页的「生成朗读音频」按钮
         *   admin/footer.php::end         后台 JS / CSS（列表页状态列靠它注入）
         *
         * 注意：Typecho 官方没有给 manage-posts.php 提供「增加列」的钩子，
         * 所以列表页的状态列是用 JS 动态插入的（见 assets/admin.js）。
         *
         * @return void
         */
        public static function installHooks()
        {
            try {
                Typecho_Plugin::factory('Widget_Archive')->footer = array('TtsVoice_Plugin', 'renderPlayerAuto');
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                Typecho_Plugin::factory('admin/write-post.php')->option = array('TtsVoice_Plugin', 'renderEditorBox');
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                Typecho_Plugin::factory('admin/footer.php')->end = array('TtsVoice_Plugin', 'renderAdminAssets');
            } catch (Throwable $e) {
                // 忽略
            }
        }

        /**
         * 禁用插件
         *
         * @return string
         */
        public static function deactivate()
        {
            // 【第一步，必须最先做】备份配置。
            // 核心紧接着会执行 `DELETE ... WHERE name = 'plugin:TtsVoice'`
            // （var/Widget/Plugins/Edit.php:260），晚一步就备份不到了。
            $backedUp = false;

            try {
                $backedUp = self::backupConfig();
            } catch (Throwable $e) {
                // 忽略
            }

            $dropped = false;

            try {
                // 1. 移除「管理 → 朗读」面板（先于其它步骤，避免中途出错导致残留）
                Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                Helper::removeMenu(self::MENU_TITLE);
            } catch (Throwable $e) {
                // 兼容早期可能误建的一级菜单
            }

            try {
                foreach (array(self::ROUTE) as $route) {
                    Helper::removeRoute($route);
                }
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                if (!self::boolOpt('keepData', true)) {
                    self::uninstallTable();
                    $dropped = true;
                }
            } catch (Throwable $e) {
                // 忽略
            }

            /*
             * 备份失败必须**说出来**：Typecho 停用插件时会把 plugin:TtsVoice 整行删掉，
             * 没备份 = 用户所有设置直接丢。以前这里静默吞掉，用户只会看到
             * 「禁用后配置不保留」却不知道原因。
             */
            if ($backedUp) {
                $suffix = '，设置已备份，重新启用会自动恢复';
            } else {
                $suffix = '。但<b>设置备份失败</b> —— 重新启用后配置会回到默认值，'
                    . '建议先记下当前设置（地址 / 密钥 / 分块长度等）。';
            }

            return $dropped
                ? _t('TtsVoice 已停用，任务表已清除（音频文件保留在磁盘上）' . $suffix . '。')
                : _t('TtsVoice 已停用，任务数据保留在数据表中' . $suffix . '。');
        }

        /**
         * 配置面板
         *
         * @param Typecho_Widget_Helper_Form $form
         * @return void
         */
        /**
         * 解析表单元素类名（兼容 1.2 下划线与 1.3 命名空间）
         *
         * @param string $type Text|Radio|Textarea|Select
         * @return string
         */
        protected static function elementClass($type)
        {
            $candidates = array(
                'Typecho\\Widget\\Helper\\Form\\Element\\' . $type,
                'Typecho_Widget_Helper_Form_Element_' . $type,
            );

            foreach ($candidates as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return class_exists('Typecho_Widget_Helper_Form_Element_Text')
                ? 'Typecho_Widget_Helper_Form_Element_Text'
                : 'Typecho\\Widget\\Helper\\Form\\Element\\Text';
        }

        /**
         * 服务商下拉选项（id => label）
         *
         * @return array
         */
        protected static function providerOptions()
        {
            if (class_exists('TtsVoice_Provider', false)) {
                return TtsVoice_Provider::all();
            }

            // Providers.php 未加载时的兜底（理论上不会发生）
            return array(
                'openai' => _t('自建 Qwen3-TTS（推荐）'),
                'azure'  => _t('微软 Azure 语音'),
            );
        }

        /**
         * 解析任意 HTML 布局块类名（兼容 1.2 下划线与 1.3 命名空间）
         *
         * @return string 找不到时返回空串
         */
        protected static function layoutClass()
        {
            foreach (array('Typecho\\Widget\\Helper\\Layout', 'Typecho_Widget_Helper_Layout') as $class) {
                if (class_exists($class)) {
                    return $class;
                }
            }

            return '';
        }

        /**
         * 「按服务商显隐 + 高级区折叠」的内联脚本
         *
         * Typecho 的 Form 没有条件显示 / 折叠能力，所以在表单末尾追加一个 Layout 块，
         * 用一小段原生 JS 做两件事：
         *   1. 按 provider 的当前值显示/隐藏各自的 <ul class="typecho-option"> 行；
         *   2. 把「音色样本 / 输出格式 / 语速」搬进一个**默认收起**的 <details>（高级设置）。
         * markup 变化时静默失败，不影响保存。
         *
         * @return string
         */
        protected static function providerScript()
        {
            $azure  = json_encode(
                array('azureKey', 'azureRegion', 'azureEndpoint', 'azureVoice', 'azureStyle', 'azureRate', 'azurePitch', 'azureFormat'),
                JSON_UNESCAPED_SLASHES
            );
            // 注意：refText / openaiFormat / openaiSpeed 虽然被收进「高级」，
            // 但它们仍然只属于自建 Qwen3-TTS，所以照样列在 openai 组里参与显隐。
            $openai = json_encode(
                array(
                    'openaiBaseUrl', 'openaiKey',
                    'refText', 'openaiFormat', 'openaiSpeed',
                    'refAudioPath', 'refUploadUrlOverride', 'refUploadTokenOverride',
                ),
                JSON_UNESCAPED_SLASHES
            );
            $adv = json_encode(array('refText', 'openaiFormat', 'openaiSpeed'), JSON_UNESCAPED_SLASHES);

            return '<style>'
                . '.ttsv-adv{margin:10px 0;border:1px solid var(--ad-line,#e0e0e0);border-radius:6px;padding:0 12px}'
                . '.ttsv-adv>summary{cursor:pointer;padding:10px 2px;font-weight:600;list-style:none}'
                . '.ttsv-adv>summary::-webkit-details-marker{display:none}'
                . '.ttsv-adv>summary:before{content:"▸ ";color:var(--ad-accent,#467b96)}'
                . '.ttsv-adv[open]>summary:before{content:"▾ "}'
                . '.ttsv-adv-body{padding-bottom:10px}'
                . '</style>'
                . '<script>'
                . '(function(){'
                . 'var G={"azure":' . $azure . ',"openai":' . $openai . '};'
                . 'var ADV=' . $adv . ';'
                . 'function row(n){'
                . 'var el=document.querySelector(\'[name="\'+n+\'"]\');'
                . 'if(!el){return null;}'
                . 'if(el.closest){var c=el.closest(".typecho-option");if(c){return c;}}'
                . 'var p=el.parentNode;'
                . 'while(p&&p!==document.body){'
                . 'if(p.className&&(" "+p.className+" ").indexOf(" typecho-option ")!==-1){return p;}'
                . 'p=p.parentNode;'
                . '}'
                . 'return null;'
                . '}'
                . 'function picked(){'
                . 'var all=document.querySelectorAll(\'[name="provider"]\');'
                . 'if(!all||!all.length){return "openai";}'
                . 'if(all[0].tagName==="SELECT"){return all[0].value||"openai";}'
                . 'for(var i=0;i<all.length;i++){if(all[i].checked){return all[i].value;}}'
                . 'return "openai";'
                . '}'
                // 把「音色样本 / 输出格式 / 语速」搬进默认收起的 <details>
                . 'function buildAdv(){'
                // 【必须幂等】这段脚本会执行两次（解析时 + DOMContentLoaded），
                // 不做判断就会把已有 <details> 再套一层（实测出现「双层高级设置」）。
                . 'if(document.querySelector(".ttsv-adv")){return;}'
                . 'var vp=document.getElementById("ttsv-vp");'
                . 'var first=row(ADV[0]);'
                . 'var anchor=(vp&&vp.parentNode)?vp:first;'
                . 'if(!anchor||!anchor.parentNode){return;}'
                . 'var host=anchor.parentNode;'
                . 'var d=document.createElement("details");'
                . 'd.className="ttsv-adv";'
                . 'var sm=document.createElement("summary");'
                . 'sm.textContent="高级设置（音色样本 / 输出格式 / 语速）";'
                . 'd.appendChild(sm);'
                . 'var body=document.createElement("div");'
                . 'body.className="ttsv-adv-body";'
                . 'd.appendChild(body);'
                . 'host.insertBefore(d,anchor);'
                . 'if(vp){body.appendChild(vp);}'
                . 'for(var i=0;i<ADV.length;i++){var r=row(ADV[i]);if(r){body.appendChild(r);}}'
                . 'window.__ttsvAdv=d;'
                . '}'
                . 'function apply(){'
                // 「字段 -> 所属服务数组」反查表：用「或」语义，避免同属多服务时互相覆盖。
                . 'var O={};'
                . 'for(var pid in G){'
                . 'if(!Object.prototype.hasOwnProperty.call(G,pid)){continue;}'
                . 'for(var i=0;i<G[pid].length;i++){'
                . 'var f=G[pid][i];'
                . 'if(!O[f]){O[f]=[];}'
                . 'O[f].push(pid);'
                . '}'
                . '}'
                . 'var sel=picked();'
                . 'for(var name in O){'
                . 'if(!Object.prototype.hasOwnProperty.call(O,name)){continue;}'
                . 'var li=row(name);'
                . 'if(!li){continue;}'
                . 'li.style.display=(O[name].indexOf(sel)>=0)?"":"none";'
                . '}'
                // 音色样本块（Layout 渲染，不是 typecho-option 行）只在自建 Qwen3-TTS 下显示
                . 'var showRef=(sel==="openai");'
                . 'var vp=document.getElementById("ttsv-vp");'
                . 'if(vp){vp.style.display=showRef?"":"none";}'
                // 「高级」整块：非 Qwen 服务时藏掉（里面全是 Qwen 的东西）
                . 'var advBox=window.__ttsvAdv;'
                . 'if(advBox){advBox.style.display=showRef?"":"none";}'
                . '}'
                . 'buildAdv();'
                . 'apply();'
                . 'var nodes=document.querySelectorAll(\'[name="provider"]\');'
                . 'for(var i=0;i<nodes.length;i++){'
                . 'nodes[i].addEventListener("change",apply);'
                . '}'
                . 'if(document.readyState==="loading"){'
                . 'document.addEventListener("DOMContentLoaded",function(){buildAdv();apply();});'
                . '}'
                . '})();'
                . '</script>';
        }

        /**
         * 同步修改「本次请求」内存里的插件配置（自愈写库之后调用）
         *
         * 核心渲染设置页读的是请求开始时加载的 Options 单例；只写库的话，
         * 用户第一次打开设置页仍会看到旧值（例如 provider 已下线 → 单选一个都不选）。
         * Typecho\Config 支持 __set / ArrayAccess，这里就地改掉那一份。
         *
         * @param array $patch key => value
         * @return void
         */
        protected static function patchLiveConfig(array $patch)
        {
            if (empty($patch)) {
                return;
            }

            try {
                $cfg = Helper::options()->plugin('TtsVoice');

                foreach ($patch as $k => $v) {
                    $cfg->{$k} = $v;
                }
            } catch (Throwable $e) {
                // 拿不到就算了，用户刷新一次即可看到新值
            }
        }

        public static function config($form)
        {
            // 【自愈】清掉库里残留、但已不在白名单的配置键（老站点升级上来时救命）。
            // 核心渲染设置页会对库里每个键做 $form->getInput($key)->value($val)，
            // 而 getInput() 没有 isset 保护 —— 键不存在就 fatal（整页 500）。
            // ★ 返回值是裁剪之前的键名，下面补隐藏输入时要用（不能读库，库已被清干净）。
            $storedKeysBeforePrune = self::pruneStoredConfig();

            // 【自愈】老站点可能还存着已下线的服务商（如 1.6.7 的 local/tts-worker）→ 回落到默认
            $storedProvider = trim((string) self::opt('provider', 'openai'));
            if (!isset(self::providerOptions()[$storedProvider])) {
                try {
                    self::saveSettings(array('provider' => 'openai'));
                } catch (Throwable $e) {
                    // 存不进去也不影响渲染
                }
            }

            /*
             * 【自愈】分块方式的默认值 1.6.8 起由「按段落」改为「按字数」。
             * 老站点库里若还停在旧默认（paragraph），这里跟着换过来。
             *
             * ⚠️ 2026-10-28 修：以前是**每次打开设置页**都无条件写回 chars，
             * 导致用户主动选「按段落」保存后，下次进来又被改掉 —— 这个选项等于不可用。
             * 现在用 chunkModeMigrated 标记，只迁移一次；之后完全尊重用户选择。
             */
            $liveFix = array();

            if ('0' === (string) self::opt('chunkModeMigrated', '0')) {
                try {
                    self::saveSettings(array(
                        'chunkMode'         => 'chars',
                        'chunkModeMigrated' => '1',
                    ));
                    $liveFix['chunkMode']         = 'chars';
                    $liveFix['chunkModeMigrated'] = '1';
                } catch (Throwable $e) {
                    // 存不进去也不影响渲染
                }
            }

            if (!isset(self::providerOptions()[$storedProvider])) {
                $liveFix['provider'] = 'openai';
            }

            // 上面两次自愈只写了库；核心渲染时用的是**请求开始时加载的** options 单例，
            // 不把内存里的那份一起改掉，用户第一次打开设置页会看到旧值（甚至没有选中项）。
            self::patchLiveConfig($liveFix);

            // ---- ① TTS 服务 ----
            $clsRadio = self::elementClass('Radio');
            $provider = new $clsRadio(
                'provider',
                self::providerOptions(),
                'openai',
                _t('TTS 服务'),
                _t('用谁来合成音频。<b>Qwen3-TTS</b>：自建，用音色样本克隆音色（服务端有默认音色，不上传也能用）。'
                    . '<b>Azure</b>：云端，填 Key 即用，只能用自带音色。')
            );
            $form->addInput($provider);

            // ---- ② 自建 Qwen3-TTS：服务端地址与 Key（放最上面，填完就能用）----
            $clsText = self::elementClass('Text');
            $openaiBase = new $clsText(
                'openaiBaseUrl',
                NULL,
                'http://127.0.0.1:8894/v1',
                _t('Qwen 服务端地址'),
                _t('形如 <code>http://主机:8894/v1</code>，<b>结尾必须带 <code>/v1</code></b>。'
                    . '走接收端（8894）才支持鉴权、排队与音色样本上传。')
            );
            $form->addInput($openaiBase);

            $clsText = self::elementClass('Text');
            $openaiKey = new $clsText(
                'openaiKey',
                NULL,
                '',
                _t('Qwen 鉴权 Key'),
                _t('接收端 / 反向代理要求的密钥，作为 <code>Authorization: Bearer …</code> 发送；'
                    . '直连没开鉴权的服务可留空。只存在服务器端。')
            );
            $form->addInput($openaiKey);

            // ---- ③ 分块方式（所有服务商都生效；默认按字数）----
            $clsRadio = self::elementClass('Radio');
            $chunkMode = new $clsRadio(
                'chunkMode',
                array(
                    'chars'     => _t('按字数'),
                    'paragraph' => _t('按段落 / Markdown 标题'),
                ),
                'chars',
                _t('分块方式'),
                _t('正文怎么切块。<b>按字数</b>：在句末标点处按长度切，简单稳定。'
                    . '<b>按段落</b>：以 Markdown 标题（<code>##</code>）为界分段，语流更自然、章节时间轴更准。')
            );
            $form->addInput($chunkMode);

            // ---- ④ 分块长度 ----
            $clsText = self::elementClass('Text');
            $chunk = new $clsText(
                'chunkChars',
                NULL,
                self::defaults()['chunkChars'],
                _t('分块长度（字符）'),
                _t('单块上限字符数，超出会在句末标点处再切。留空或 0 用默认 <b>120</b>；Qwen 常用 <b>100~200</b>。')
            );
            $form->addInput($chunk);

            // ---- ⑤ 微软 Azure 语音 ----
            $clsText = self::elementClass('Text');
            $azureKey = new $clsText(
                'azureKey',
                NULL,
                '',
                _t('Azure 订阅密钥'),
                _t('Azure「语音服务」资源的密钥（KEY 1 或 KEY 2）。只存在服务器端，不会输出到前端。')
            );
            $form->addInput($azureKey);

            $clsText = self::elementClass('Text');
            $azureRegion = new $clsText(
                'azureRegion',
                NULL,
                'eastasia',
                _t('Azure 区域'),
                _t('资源所在区域，如 <code>eastasia</code>、<code>eastus</code>；留空 Endpoint 时用它拼服务地址。')
            );
            $form->addInput($azureRegion);

            $clsText = self::elementClass('Text');
            $azureEndpoint = new $clsText(
                'azureEndpoint',
                NULL,
                '',
                _t('Azure Endpoint（可选）'),
                _t('只有自定义域名 / 私有终结点 / 本地 mock 才填，结尾不要带 <code>/</code>。')
            );
            $form->addInput($azureEndpoint);

            $clsText = self::elementClass('Text');
            $azureVoice = new $clsText(
                'azureVoice',
                NULL,
                'zh-CN-XiaoxiaoNeural',
                _t('Azure 音色'),
                _t('完整音色名，如 <code>zh-CN-XiaoxiaoNeural</code>（晓晓）、<code>zh-CN-YunxiNeural</code>（云希）。')
            );
            $form->addInput($azureVoice);

            $clsText = self::elementClass('Text');
            $azureStyle = new $clsText(
                'azureStyle',
                NULL,
                '',
                _t('Azure 说话风格（可选）'),
                _t('如 <code>newscast</code>、<code>cheerful</code>；并非所有音色都支持，填错会报错。')
            );
            $form->addInput($azureStyle);

            $clsText = self::elementClass('Text');
            $azureRate = new $clsText(
                'azureRate',
                NULL,
                '0%',
                _t('Azure 语速'),
                _t('SSML <code>rate</code>，如 <code>0%</code>、<code>-10%</code>、<code>+20%</code>。')
            );
            $form->addInput($azureRate);

            $clsText = self::elementClass('Text');
            $azurePitch = new $clsText(
                'azurePitch',
                NULL,
                '0%',
                _t('Azure 音调'),
                _t('SSML <code>pitch</code>，如 <code>0%</code>、<code>-5%</code>。')
            );
            $form->addInput($azurePitch);

            $clsText = self::elementClass('Text');
            $azureFormat = new $clsText(
                'azureFormat',
                NULL,
                'audio-24khz-48kbitrate-mono-mp3',
                _t('Azure 输出格式'),
                _t('对应请求头 <code>X-Microsoft-OutputFormat</code>，默认 24kHz mp3。')
            );
            $form->addInput($azureFormat);

            // ---- ⑦ 通用设置（分隔线）----
            $sepLayout = self::layoutClass();

            if ('' !== $sepLayout) {
                try {
                    $sep = new $sepLayout();
                    $sep->html('<div class="ttsv-sep"><span>' . _t('通用设置') . '</span></div>');
                    $form->addItem($sep);
                } catch (Throwable $e) {
                    // 布局类不可用时忽略
                }
            }

            // ---- 播放器挂载方式（默认手动；手动排在前）----
            $clsRadio = self::elementClass('Radio');
            $auto = new $clsRadio(
                'autoInsert',
                array(
                    '0' => _t('手动调用'),
                    '1' => _t('自动插入'),
                ),
                '0',
                _t('播放器挂载方式'),
                _t('<b>手动</b>：在主题模板里写 <code>&lt;?php TtsVoice_Plugin::renderPlayer(); ?&gt;</code>'
                    . '（用了 PJAX / 局部刷新的主题必须选它）。'
                    . '<b>自动</b>：插件自己输出，需主题 <code>footer.php</code> 里有 <code>$this->footer()</code>。')
            );
            $form->addInput($auto);

            $clsText = self::elementClass('Text');
            $targetId = new $clsText(
                'targetId',
                NULL,
                '',
                _t('自动插入位置（DOM id）'),
                _t('仅「自动插入」时生效。填主题里某个元素的 id（不用写 <code>#</code>）；'
                    . '留空输出在页脚，id 不存在时也会留在页脚。')
            );
            $form->addInput($targetId);

            $clsRadio = self::elementClass('Radio');
            $scope = new $clsRadio(
                'scope',
                array(
                    'post'      => _t('仅文章'),
                    'post,page' => _t('文章与独立页面'),
                ),
                'post',
                _t('显示范围'),
                _t('控制哪些类型的内容页自动插入播放器。')
            );
            $form->addInput($scope);

            $clsRadio = self::elementClass('Radio');
            $chapters = new $clsRadio(
                'showChapters',
                array('1' => _t('显示'), '0' => _t('隐藏')),
                '1',
                _t('章节跳转'),
                _t('正文有小标题时，播放器下方显示可点击跳转的章节列表。')
            );
            $form->addInput($chapters);

            $clsRadio = self::elementClass('Radio');
            $download = new $clsRadio(
                'showDownload',
                array('1' => _t('显示'), '0' => _t('隐藏')),
                '1',
                _t('下载按钮'),
                _t('在播放器上显示音频下载链接（格式跟随「输出格式」）。')
            );
            $form->addInput($download);

            $clsText = self::elementClass('Text');
            $timeout = new $clsText(
                'timeout',
                NULL,
                self::defaults()['timeout'],
                _t('请求超时（秒）'),
                _t('访问 TTS 服务的连接超时，公网 / 反向代理建议 <b>15~60</b>；合成单块时按它的 4 倍放宽。')
            );
            $form->addInput($timeout);

            $clsRadio = self::elementClass('Radio');
            $verifySsl = new $clsRadio(
                'verifySsl',
                array(
                    '1' => _t('验证证书（默认）'),
                    '0' => _t('跳过验证'),
                ),
                '1',
                _t('HTTPS 证书校验'),
                _t('用 <code>https://</code> 访问 TTS 服务时是否校验证书；只有自签证书且确认是自己的服务才选「跳过验证」。')
            );
            $form->addInput($verifySsl);

            $clsRadio = self::elementClass('Radio');
            $keep = new $clsRadio(
                'keepData',
                array('1' => _t('保留'), '0' => _t('删除')),
                '1',
                _t('禁用时保留任务数据'),
                _t('选「删除」时，禁用插件会清空任务记录（磁盘上的音频文件始终保留）。')
            );
            $form->addInput($keep);

            // ---- ⑧ 高级设置（默认折叠：音色样本 / 输出格式 / 语速）----
            // 这里只放控件；折叠由 providerScript() 里那段 JS 把它们搬进 <details>。
            $voiceLayout = self::layoutClass();

            if ('' !== $voiceLayout) {
                try {
                    $picker = new $voiceLayout();
                    $picker->html(self::voicePickerHtml());
                    $form->addItem($picker);
                } catch (Throwable $e) {
                    // 布局类不可用时忽略，不影响表单保存
                }
            }

            $clsText = self::elementClass('Text');
            $refText = new $clsText(
                'refText',
                NULL,
                '',
                _t('音色样本的文本（可选）'),
                _t('上面那段样本<b>实际说了什么</b>；<b>留空最稳</b>（只用说话人嵌入）。'
                    . '填了就必须与音频逐字一致，否则句尾会冒出杂音。')
            );
            $form->addInput($refText);

            $clsText = self::elementClass('Text');
            $openaiFormat = new $clsText(
                'openaiFormat',
                NULL,
                self::defaults()['openaiFormat'],
                _t('输出格式'),
                _t('<code>wav</code>：24kHz 单声道无损（约 48 KB/s）；<code>mp3</code>：体积约 1/3，兼容性最好。'
                    . '服务端没装 ffmpeg 时会自动按 wav 出片。')
            );
            $form->addInput($openaiFormat);

            $clsText = self::elementClass('Text');
            $openaiSpeed = new $clsText(
                'openaiSpeed',
                NULL,
                self::defaults()['openaiSpeed'],
                _t('语速'),
                _t('合成后用本机 ffmpeg 变速（不改音高）。<code>1.0</code> = 原速，<code>1.25</code> = 快 25%。')
            );
            $form->addInput($openaiSpeed);

            // ---- ⑨ 无界面但必须占位的配置键 ----
            // 这些键在库里存在（或被上传流程写入），但没有可见控件；
            // 必须给它们一个 Hidden 输入，否则核心的 getInput($key)->value() 会 fatal。
            $clsHidden = self::elementClass('Hidden');

            foreach (array(
                'refAudioPath'           => '',   // 上传音色样本后由服务端返回的路径自动写入
                'refUploadUrlOverride'   => '',   // 一般留空：接收端地址从 openaiBaseUrl 推导
                'refUploadTokenOverride' => '',   // 一般留空：密钥复用 openaiKey
            ) as $hiddenKey => $hiddenDefault) {
                try {
                    $form->addInput(new $clsHidden($hiddenKey, NULL, $hiddenDefault));
                } catch (Throwable $e) {
                    // 控件类不可用时忽略
                }
            }

            // ---- ⑩ 通用兜底：库里任何没有对应控件的键，都补一个隐藏输入 ----
            // 触发场景很现实：某个版本删掉了配置项，老站点库里还留着旧键 ——
            // 核心会对它做 getInput()->value() 而 fatal。pruneStoredConfig() 要到下一次
            // 请求才在内存生效，所以首次加载仍需这里兜住。
            // ★ 必须放在 config() 最后：它按「此刻已注册的控件」判断该补哪些键。
            try {
                $registered = array_keys($form->getInputs());

                foreach ((array) $storedKeysBeforePrune as $storedKey) {
                    $storedKey = (string) $storedKey;

                    if ('' !== $storedKey && !in_array($storedKey, $registered, true)) {
                        $form->addInput(new $clsHidden($storedKey, NULL, ''));
                    }
                }
            } catch (Throwable $e) {
                // 兜底失败也不能影响表单渲染
            }

            // ---- ⑪ 条件显示（按服务商显隐）+ 高级区折叠 ----
            $layout = self::layoutClass();

            if ('' !== $layout) {
                try {
                    $item = new $layout();
                    $item->html(self::providerScript());
                    $form->addItem($item);
                } catch (Throwable $e) {
                    // 布局类不可用时忽略，不影响保存
                }
            }
        }

        /**
         * 个人配置面板（本插件不需要）
         *
         * @param Typecho_Widget_Helper_Form $form
         * @return void
         */
        public static function personalConfig($form)
        {
        }

        /**
         * 配置保存回调：规范化服务地址（去掉结尾斜杠）
         *
         * @param array $settings
         * @return void
         */
        /**
         * 配置保存：自己写库，不依赖框架
         *
         * 【为什么不交给框架自动保存】
         * 官方 HelloWorld 不定义 configHandle，靠框架自动写库。
         * 但实测点保存报 Server Error 且值没入库，
         * 自动保存链路在本环境不可用，所以主动接管。
         *
         * @param array $settings
         * @param bool  $isInit   核心启用插件时的初始化调用（此时 $settings 全是表单默认值）
         * @return void
         */
        public static function configHandle($settings, $isInit = false)
        {
            /*
             * 【启用时的第二次调用 —— 2026-10-28 修】
             *
             * Typecho 1.3 的 Edit::activate() 在插件 activate() 之后，还会：
             *   config($form) → $form->getValues() → configHandle($settings, true)
             * 而 config() 只创建「带表单默认值」的控件、不回填库值，
             * 所以那份 $settings 是 200/15/mp3/1 之类的**默认值**。
             *
             * 以前直接 saveSettings($settings)，把 activate() 里刚恢复的备份整份覆盖：
             * 停用→启用一次，Qwen Key 清空、地址回到 127.0.0.1:8894、refSourceId/模型也被抹掉，
             * 而提示还写着「已恢复停用前的设置」。
             *
             * 现在初始化调用只做「补缺失键」，已有值一律保留。
             */
            if ($isInit) {
                $current = self::rawConfig();
                $merged  = array_merge(self::defaults(), is_array($current) ? $current : array());

                self::saveSettings($merged);

                return;
            }

            // 配置保存时的清洗
            if (is_array($settings)) {
                // 目标 id：允许用户写 #id，统一去掉 #；只保留
                // [A-Za-z0-9_-] 以及 :before/:after/:prepend/:append 后缀
                if (array_key_exists('targetId', $settings)) {
                    $settings['targetId'] = self::normalizeTargetId($settings['targetId']);
                }
            }

            self::saveSettings($settings);
        }

        /**
         * 规范化「自动插入位置」
         *
         * 允许的形态：
         *   someId
         *   someId:append | someId:prepend | someId:before | someId:after
         *
         * @param string $value
         * @return string
         */
        public static function normalizeTargetId($value)
        {
            $value = ltrim(trim((string) $value), '#');

            if ('' === $value) {
                return '';
            }

            $id   = $value;
            $mode = '';

            if (preg_match('/^(.+?):(append|prepend|before|after)$/i', $value, $m)) {
                $id   = $m[1];
                $mode = ':' . strtolower($m[2]);
            }

            // 只接受合法的 HTML id / CSS 选择器片段，避免把任意字符串塞进 JS
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_:.-]*$/', $id)) {
                return '';
            }

            return $id . $mode;
        }

        /**
         * 把配置写入 options 表（三级降级）
         *
         * @param array $settings
         * @return bool
         */
        public static function saveSettings($settings)
        {
            if (!is_array($settings)) {
                $settings = array();
            }

            $name   = 'plugin:TtsVoice';
            $merged = array_merge(self::rawConfig(), $settings);

            // 按白名单裁剪：旧版本残留的键会让核心渲染设置页时 fatal
            $merged = array_intersect_key($merged, array_flip(self::configKeys()));

            // 保证每个键都存在且是标量。
            // 注意也要把 null 归一成默认值：核心的 Form::getAllRequest() 对留空的
            // 文本项会返回 null（而不是空串），否则库里会存进 NULL。
            foreach (self::defaults() as $key => $default) {
                if (
                    !array_key_exists($key, $merged)
                    || is_array($merged[$key])
                    || null === $merged[$key]
                ) {
                    $merged[$key] = $default;
                }
            }

            // 打时间戳：/启用恢复备份时据此判断备份是否过期
            $merged['configSavedAt'] = (string) time();

            $value  = serialize($merged);

            /*
             * ---- 0. 真 upsert ----
             * 配置行可能还不存在（刚激活 / 恢复备份时被核心删过），这时只走 UPDATE 会
             * 「影响 0 行 + verifySaved 失败」，三层降级全部静默失败。
             * saveOptionRow() 是先查后 update-or-insert，这里先做一次。
             */
            if (self::saveOptionRow($name, $value) && self::verifySaved($value)) {
                self::resetOptCache();

                return true;
            }

            // ---- 1. Db 构造器 ----
            try {
                $db = ttsv_db();
                $db->query(
                    $db->update('table.options')
                        ->rows(array('value' => $value))
                        ->where('name = ?', $name)
                );

                if (self::verifySaved($value)) {
                    // 配置已落库 → 让请求级缓存失效。
                    // 否则同一请求内再读 opt() 拿到的还是旧值
                    // （症状：上传音色样本后本请求里读到的 refAudioPath 仍是旧的）。
                    self::resetOptCache();

                    return true;
                }
            } catch (Throwable $e) {
                // 降级
            }

            // ---- 2. 裸 SQL（用 adapter 正确转义，兼容 Pgsql / SQLite）----
            try {
                $db     = ttsv_db();
                $prefix = method_exists($db, 'getPrefix') ? $db->getPrefix() : 'typecho_';
                $quoted = $db->getAdapter()->quoteValue($value);

                $db->query(
                    'UPDATE ' . $db->getAdapter()->quoteColumn($prefix . 'options')
                    . ' SET ' . $db->getAdapter()->quoteColumn('value') . ' = ' . $quoted
                    . ' WHERE ' . $db->getAdapter()->quoteColumn('name') . ' = '
                    . $db->getAdapter()->quoteValue($name),
                    TTSV_DB_WRITE
                );

                if (self::verifySaved($value)) {
                    // 配置已落库 → 让请求级缓存失效。
                    // 否则同一请求内再读 opt() 拿到的还是旧值
                    // （症状：上传音色样本后本请求里读到的 refAudioPath 仍是旧的）。
                    self::resetOptCache();

                    return true;
                }
            } catch (Throwable $e) {
                // 降级
            }

            // ---- 3. Helper ----
            try {
                Helper::configPlugin('TtsVoice', $merged);

                if (self::verifySaved($value)) {
                    // 配置已落库 → 让请求级缓存失效。
                    // 否则同一请求内再读 opt() 拿到的还是旧值
                    // （症状：上传音色样本后本请求里读到的 refAudioPath 仍是旧的）。
                    self::resetOptCache();

                    return true;
                }
            } catch (Throwable $e) {
                // 全部失败
            }

            return false;
        }

        /**
         * 校验写入是否生效
         *
         * @param string $expect
         * @return bool
         */
        protected static function verifySaved($expect)
        {
            try {
                $db  = ttsv_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ?', 'plugin:TtsVoice')
                        ->limit(1)
                );

                return !empty($row) && isset($row['value']) && $row['value'] === $expect;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 从库中读取完整配置数组
         *
         * @return array
         */
        public static function rawConfig()
        {
            $data = self::dbConfig();

            return NULL === $data ? self::defaults() : $data;
        }

        /* ============================================================== */
        /* 配置读写                                                        */
        /* ============================================================== */


        public static function defaults()
        {
            return array(
                // 服务商（1.6.0）
                'provider'     => 'openai',
                // 分块方式：chars(按字数，默认) | paragraph(按段落/标题)
                'chunkMode'    => 'chars',
                // 一次性迁移标记：1.6.8 把默认从 paragraph 改成 chars 后，
                // 旧库需要迁移一次；迁完就不再覆盖用户后来主动选的 paragraph。
                'chunkModeMigrated' => '0',
                // 120 字 ≈ 27 秒。自建 TTS 约 4.5 字/秒，必须让「一次请求」
                // 落在 nginx 默认的 60 秒 FastCGI 超时内，否则会被网关掐断。
                'chunkChars'   => '120',

                // Azure
                'azureKey'      => '',
                'azureRegion'   => 'eastasia',
                'azureEndpoint' => '',
                'azureVoice'    => 'zh-CN-XiaoxiaoNeural',
                'azureStyle'    => '',
                'azureRate'     => '0%',
                'azurePitch'    => '0%',
                'azureFormat'   => 'audio-24khz-48kbitrate-mono-mp3',

                // 自建 Qwen3-TTS（OpenAI 兼容协议）
                'openaiBaseUrl' => 'http://127.0.0.1:8894/v1',
                'openaiKey'     => '',
                // 【已从设置页去掉】改名后不再由用户选：服务类型固定 qwen3tts；
                // openaiModel / openaiCloneModel 作为隐藏键保留默认值（由「音色」决定用哪个）。
                'openaiModel'   => 'gpt-4o-mini-tts',
                'openaiFlavor'  => 'qwen3tts',
                // 【只有克隆一条路】模型固定为 1.7B-Base（支持 ref_audio 克隆）。
                // 预置音色（CustomVoice）已整体下线：openaiPresetModel / openaiVoice
                // 两个键也从配置里删掉了，音色的唯一来源是「音色样本」。
                // 留空则退回旧键 openaiModel。
                'openaiCloneModel'  => 'mlx-community/Qwen3-TTS-12Hz-1.7B-Base-8bit',
                // 【默认 wav】同一段音频的对照试听里，24kHz 单声道 WAV 明显好过
                // 128k / 192k / VBR0 的 mp3（mp3 的编码噪声被听成了「破音」）。
                // 代价是文件大（≈48 KB/s），由「输出格式」可随时切回 mp3。
                'openaiFormat'  => 'wav',
                // 【语速 = 本地变速倍率】上游 mlx-audio 的 speed 参数目前是空实现
                // （传了不生效），所以这里改成下载后本地 ffmpeg atempo 变速（不改音高）。
                // 1.0 = 原速，1.25 = 快 25%。
                'openaiSpeed'   => '1.25',
                // 【采样参数】反复对照试出来的「不退化」组合：
                // 上游 server 默认给 temperature=0.7 / top_p=0.95 / top_k=40，但把
                // repetition_penalty 压成 1.0，长句会整段退化成噪声；
                // 显式补上 rp=1.05 后 1/4 → 4/4 正常。详见 SPEC-TO-MINI-job-queue.md §14。
                'openaiTemperature' => '0.7',
                'openaiTopP'        => '0.9',
                'openaiTopK'        => '40',
                'openaiRepPenalty'  => '1.05',
                // 音色克隆：参考音频在 TTS 主机上的路径（留空 = 用本机路径）
                'refAudioPath'  => '',
                // 参考音频里实际说了什么（填了走 ICL，更像）
                'refText'       => '',
                // 音色样本接收端（TtsVoice receiver）：网站与 TTS 不同机时填写
                // 一般不用填：接收端地址/密钥会自动从 openaiBaseUrl + openaiKey 推导。
                // 只有「接收端与 TTS 不在同一台机器」时才需要这两个覆盖项。
                'refUploadUrlOverride'   => '',
                'refUploadTokenOverride' => '',
                // 【来源标识】多站点共用一个接收端时，用来隔离各自的音色样本
                // （receiver v1.5.0+ 会存到 <dir>/<source>/ref.wav）。
                // 第一次用到时自动生成「域名-指纹」并存回来，一般不用手填。
                'refSourceId'            => '',
                // 【配置的最后保存时间】内部用：停用/启用时判断「备份是不是比当前配置还旧」，
                // 避免用一份过期备份把用户刚存的设置覆盖回去。
                'configSavedAt'          => '',

                // 播放器与通用（原有）
                // 播放器挂载方式：'0'=手动调用模板函数（默认，PJAX 主题必须）| '1'=自动插入
                'autoInsert'   => '0',
                'targetId'     => '',
                'scope'        => 'post',
                'showChapters' => '1',
                'showDownload' => '1',
                'timeout'      => '50',
                'verifySsl'    => '1',
                'keepData'     => '1',
            );
        }

        /**
         * 允许写入 options 的配置键白名单
         *
         * 旧版本删掉的键如果残留在库里，核心渲染设置页时会对每个键做
         * $form->getInput($key)->value(...)，而 getInput() 没有 isset 保护，
         * 会直接 fatal。所以保存时按白名单裁剪。
         *
         * @return array
         */
        protected static function configKeys()
        {
            return array_keys(self::defaults());
        }

        /**
         * CSRF token 的固定后缀
         *
         * Security::getToken() 的结果 = md5(options.secret + 登录用户的 authCode + uid + '&' + 后缀)，
         * 所以固定后缀也能得到「与会话绑定、跨站无法伪造」的 token，
         * 而且不依赖 Referer / URL 归一化，比 Referer 方案稳。
         */
        const CSRF_SUFFIX = 'ttsvoice:admin-token';

        /**
         * 生成 CSRF token（后台页面渲染时注入给前端）
         *
         * @return string
         */
        public static function csrfToken()
        {
            try {
                return (string) Helper::security()->getToken(self::CSRF_SUFFIX);
            } catch (Throwable $e) {
                return '';
            }
        }

        /**
         * 校验前端提交的 CSRF token
         *
         * @param string $sent
         * @return bool
         */
        public static function csrfValid($sent)
        {
            $expected = self::csrfToken();

            return '' !== $expected && '' !== (string) $sent
                && hash_equals($expected, (string) $sent);
        }

        /**
         * 读配置项（带默认值）
         *
         * 【重要】首次启用时 plugin:TtsVoice 这行还不存在，
         * 直接 Helper::options()->plugin('TtsVoice') 会抛异常，
         * 所以这里做了容错。
         *
         * @param string $key
         * @param mixed  $default
         * @return mixed
         */
        /**
         * 配置缓存（请求级）
         *
         * 【为什么要做成类属性而不是方法内的 static】
         * 方法内的 static 无法从外部重置，而 saveSettings() 写库之后必须让缓存失效 ——
         * 否则同一请求内再读 opt() 拿到的还是旧值。
         * （典型症状：上传音色样本后，本请求里读到的 refAudioPath 仍是旧的。）
         */
        protected static $optCache = NULL;

        public static function opt($key, $default = '')
        {
            if (NULL === self::$optCache) {
                self::$optCache = array();

                // 【关键】优先直读数据库。
                // Helper::options()->plugin() 拿到的是 Widget_Options 单例，
                // 它在请求开始时就加载好了；saveSettings() 写库之后，
                // 同一请求内（以及部分缓存场景）它仍是旧值，
                // 结果被 defaults 覆盖 —— 表现为"填了地址却连 127.0.0.1"。
                // 数据库才是真相源，所以先查库。
                $fromDb = self::dbConfig();

                if (is_array($fromDb)) {
                    self::$optCache = $fromDb;
                } else {
                    try {
                        $value = Helper::options()->plugin('TtsVoice');

                        if (is_array($value) || is_object($value)) {
                            foreach ((array) $value as $k => $v) {
                                self::$optCache[$k] = $v;
                            }
                        }
                    } catch (Throwable $e) {
                        // 配置行不存在，用默认值
                    }
                }

                self::$optCache = array_merge(self::defaults(), self::$optCache);
            }

            return array_key_exists($key, self::$optCache) ? self::$optCache[$key] : $default;
        }

        /**
         * 让配置缓存失效
         *
         * saveSettings() 成功写库后会调用它，保证同一请求内后续读到的都是新值。
         *
         * @return void
         */
        public static function resetOptCache()
        {
            self::$optCache = NULL;
        }

        /**
         * 直接从 options 表读插件配置
         *
         * @return array|null 库中无有效数据时返回 NULL
         */
        protected static function dbConfig()
        {
            try {
                $db  = ttsv_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')
                        ->where('name = ?', 'plugin:TtsVoice')
                        ->limit(1)
                );

                if (empty($row) || !isset($row['value']) || '' === (string) $row['value']) {
                    return NULL;
                }

                $raw  = (string) $row['value'];
                $data = @unserialize($raw);

                /*
                 * 兼容两种格式：插件自己写的是 serialize()，而核心 Helper::configPlugin()
                 * 写的是 JSON（见 var/Widget/Plugins/Edit.php::configPlugin）。
                 * 核心的 Options::tryDeserialize() 两种都认，这里必须保持一致 ——
                 * 否则「恢复备份」用核心写完 JSON 后，备份额外的 prune/verify 全都读不到配置。
                 */
                if (!is_array($data)) {
                    $json = json_decode($raw, true);
                    $data = is_array($json) ? $json : NULL;
                }

                return is_array($data) ? $data : NULL;
            } catch (Throwable $e) {
                return NULL;
            }
        }

        /**
         * 布尔型配置项
         *
         * @param string $key
         * @param bool   $default
         * @return bool
         */
        public static function boolOpt($key, $default = false)
        {
            $value = self::opt($key, $default ? '1' : '0');

            return '1' === (string) $value || 1 === $value || true === $value || 'true' === strtolower((string) $value);
        }

        /**
         * 整型配置项
         *
         * @param string $key
         * @param int    $default
         * @return int
         */
        public static function intOpt($key, $default = 0)
        {
            return intval(self::opt($key, $default));
        }

        /**
         * 配置备份行的 option 名
         *
         * 【为什么需要它】Typecho **核心**在停用插件时会直接删掉
         * `plugin:TtsVoice` 这一行（var/Widget/Plugins/Edit.php:260）：
         *
         *     Plugin::deactivate($pluginName);
         *     $this->delete(...->where('name = ?', 'plugin:' . $pluginName));
         *
         * 核心不能改，所以插件自己在 deactivate() 里先把配置另存一份，
         * activate() 时再恢复。行名**故意不带 `plugin:` 前缀**，
         * 免得被核心一并删掉。
         *
         * @var string
         */
        const CONFIG_BACKUP = 'ttsvoice_config_backup';

        /**
         * 读写任意 options 行（upsert）
         *
         * Typecho 的 Helper::setOption() 只 UPDATE 不 INSERT，
         * 所以这里自己判断再写。
         *
         * @param string $name
         * @param string $value
         * @return bool
         */
        protected static function saveOptionRow($name, $value)
        {
            try {
                $db  = ttsv_db();
                $row = $db->fetchRow(
                    $db->select()->from('table.options')->where('name = ?', $name)->limit(1)
                );

                if (is_array($row) && !empty($row)) {
                    $db->query(
                        $db->update('table.options')
                            ->rows(array('value' => $value))
                            ->where('name = ?', $name)
                    );

                    return true;
                }

                $db->query(
                    $db->insert('table.options')
                        ->rows(array('name' => $name, 'user' => 0, 'value' => $value))
                );

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 读任意 options 行
         *
         * @param string $name
         * @return string
         */
        protected static function readOptionRow($name)
        {
            try {
                $db  = ttsv_db();
                $row = $db->fetchRow(
                    $db->select('value')->from('table.options')->where('name = ?', $name)->limit(1)
                );

                return (is_array($row) && isset($row['value'])) ? (string) $row['value'] : '';
            } catch (Throwable $e) {
                return '';
            }
        }

        /**
         * 停用前把当前配置备份起来
         *
         * @return bool
         */
        public static function backupConfig()
        {
            $current = self::dbConfig();

            if (!is_array($current) || empty($current)) {
                return false;
            }

            return self::saveOptionRow(self::CONFIG_BACKUP, serialize(array(
                'time' => time(),
                'data' => $current,
            )));
        }

        /**
         * 启用时恢复上次停用前的配置
         *
         * 时序说明（已核对核心源码）：
         *   1. 启用插件时核心先 require 本文件 → 文件末尾的自愈 writeDefaultConfig()
         *      发现 `plugin:TtsVoice` 行不存在，写入一份**默认值**；
         *   2. 随后核心调用本插件的 activate()（核心 activate() 本身**不写配置**）
         *      → 这里用备份覆盖掉刚才那份默认值。
         *
         * 所以必须**无条件**用备份覆盖，不能"当前有值就跳过"——
         * 第 1 步写进去的就是默认值，跳过等于没恢复。
         *
         * @return bool
         */
        public static function restoreConfigBackup()
        {
            $raw = self::readOptionRow(self::CONFIG_BACKUP);

            if ('' === $raw) {
                return false;
            }

            $saved = @unserialize($raw);

            if (!is_array($saved) || !isset($saved['data']) || !is_array($saved['data'])) {
                return false;
            }

            /*
             * 【关键】备份比当前配置旧 → 不要恢复。
             *
             * 踩过的坑：停用时若 backupConfig() 失败（例如当时读不到配置），
             * 库里会留着一份**很旧**的备份；下次启用时无条件用它覆盖，
             * 用户刚填没多久的设置就被"还原"成老值 —— 表现出来就是
             * 「设置保存了、但禁用一次就没了」。
             */
            $live    = self::dbConfig();
            $liveAt  = (is_array($live) && isset($live['configSavedAt'])) ? intval($live['configSavedAt']) : 0;
            $backAt  = isset($saved['time']) ? intval($saved['time']) : 0;

            if ($liveAt > 0 && $backAt > 0 && $backAt < $liveAt) {
                return false;
            }

            // 与默认值合并，保证新增的设置项也有值
            $merged = array_merge(self::defaults(), $saved['data']);

            // 只保留白名单内的键，避免历史遗留键让设置页 fatal
            $merged = array_intersect_key($merged, array_flip(self::configKeys()));

            try {
                Helper::configPlugin('TtsVoice', $merged);

                return true;
            } catch (Throwable $e) {
                return self::saveOptionRow('plugin:TtsVoice', serialize($merged));
            }
        }

        /**
         * 自愈：确保 options 表里有 plugin:TtsVoice 这一行
         *
         * 放在文件末尾（类定义之后）执行。因为 Typecho 渲染设置页时是
         * 「先 require 本文件 → 再调用 Options->plugin()」，
         * 这一步正好赶在核心报错之前完成。
         *
         * 也覆盖了「用旧版激活过、之后才覆盖文件」的情况 —— 那时
         * activate() 不会重跑，只有这里能补救。
         *
         * @return bool
         */
        public static function writeDefaultConfig()
        {
            try {
                $db      = ttsv_db();
                $name    = 'plugin:TtsVoice';
                $default = serialize(self::defaults());

                // 用最朴素的写法，避开各版本对 COUNT(1) AS num 的解析差异
                $row = $db->fetchRow(
                    $db->select()->from('table.options')->where('name = ?', $name)->limit(1)
                );

                if (is_array($row) && !empty($row)) {
                    if (isset($row['value']) && '' !== (string) $row['value']) {
                        return false;
                    }

                    // 行在但值为空：更新
                    $db->query(
                        $db->update('table.options')
                            ->rows(array('value' => $default))
                            ->where('name = ?', $name)
                    );

                    return true;
                }

                $db->query(
                    $db->insert('table.options')
                        ->rows(array('name' => $name, 'user' => 0, 'value' => $default))
                );

                return true;
            } catch (Throwable $e) {
                return self::writeDefaultConfigRaw();
            }
        }

        /**
         * 裸 SQL 兜底写入默认配置
         *
         * @return bool
         */
        protected static function writeDefaultConfigRaw()
        {
            try {
                $db     = ttsv_db();
                $prefix = method_exists($db, 'getPrefix') ? $db->getPrefix() : 'typecho_';
                $name   = 'plugin:TtsVoice';
                $value  = addslashes(serialize(self::defaults()));

                $row = $db->fetchRow(
                    $db->select()->from('table.options')->where('name = ?', $name)->limit(1)
                );

                if (is_array($row) && !empty($row)) {
                    if (isset($row['value']) && '' !== (string) $row['value']) {
                        return false;
                    }

                    $db->query(
                        "UPDATE `{$prefix}options` SET `value` = '{$value}' WHERE `name` = '{$name}'",
                        TTSV_DB_WRITE
                    );

                    return true;
                }

                $db->query(
                    "INSERT INTO `{$prefix}options` (`name`, `user`, `value`) VALUES ('{$name}', 0, '{$value}')",
                    TTSV_DB_WRITE
                );

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /* ============================================================== */
        /* 路由与菜单                                                      */
        /* ============================================================== */

        /**
         * 注册 Action 路由：/ttsvoice/{动作}
         *
         * @param bool $force
         * @return bool
         */
        public static function installRoute($force = false)
        {
            try {
                $table = (array) Helper::options()->routingTable;
                $url   = isset($table[self::ROUTE]['url']) ? (string) $table[self::ROUTE]['url'] : '';

                if ('' !== $url && false !== strpos($url, '/ttsvoice/') && !$force) {
                    return false;
                }

                Helper::addRoute(self::ROUTE, '/ttsvoice/[do:alpha]', 'TtsVoice_Action', 'dispatch');

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 注册后台面板：管理 → 朗读
         *
         * 先 remove 再 add，保证幂等（启用多少次都只有一项）。
         *
         * @return void
         */
        public static function installMenu()
        {
            try {
                Helper::removePanel(self::MENU_INDEX, self::PANEL_FILE);
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                Helper::removeMenu(self::MENU_TITLE);
            } catch (Throwable $e) {
                // 忽略
            }

            try {
                Helper::addPanel(
                    self::MENU_INDEX,
                    self::PANEL_FILE,
                    self::MENU_TITLE,
                    '生成与管理文章朗读音频',
                    'administrator'
                );
            } catch (Throwable $e) {
                // 忽略
            }
        }

        /* ============================================================== */
        /* 地址生成                                                        */
        /* ============================================================== */

        /**
         * 生成站内地址
         *
         * 【关键】不要额外再拼 index.php。
         * Typecho 的 $options->index 在「未开启伪静态」时，
         * 值本身已经是 http://域名/index.php（见 Widget/Options::___index）。
         * 多拼一次就会出现 index.php/index.php/... 的重复。
         *
         * @param string $path
         * @return string
         */
        public static function urlFor($path)
        {
            $options = Helper::options();
            $base    = (string) $options->index;

            if ('' === $base) {
                $base = (string) $options->siteUrl;
            }

            // 未开伪静态、但基址里没有 index.php 时才补（兼容版本差异）
            if (empty($options->rewrite) && !preg_match('#index\.php(/|$)#', $base)) {
                $base = Typecho_Common::url('index.php', $base);
            }

            $url = Typecho_Common::url($path, $base);

            // 兜底：无论已有几段，统一折叠成一段
            return preg_replace('#(index\.php/)+#', 'index.php/', $url);
        }

        /**
         * Action 接口地址
         *
         * @param string $do
         * @return string
         */
        public static function actionUrl($do = '')
        {
            $do = preg_replace('/[^a-zA-Z0-9_]/', '', (string) $do);

            return self::urlFor('ttsvoice/' . $do);
        }

        /**
         * 插件资源地址
         *
         * @param string $file
         * @return string
         */
        public static function assetUrl($file)
        {
            $file = ltrim($file, '/');
            $url  = rtrim(Helper::options()->pluginUrl, '/') . '/TtsVoice/assets/' . $file;

            // 带 mtime 版本号：改完 CSS / JS 用户刷新即生效，
            // 否则浏览器会一直用缓存的旧样式。
            $path = __DIR__ . '/assets/' . $file;

            if (is_file($path)) {
                $url .= '?v=' . filemtime($path);
            }

            return $url;
        }

        /**
         * 音频存放目录的绝对路径
         *
         * @return string
         */
        public static function audioPath()
        {
            return rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . self::AUDIO_DIR;
        }

        /**
         * 音频文件 URL
         *
         * @param string $file 相对路径，如 usr/uploads/tts/123.mp3
         * @return string
         */
        public static function audioUrl($file)
        {
            return Typecho_Common::url(str_replace('\\', '/', (string) $file), Helper::options()->siteUrl);
        }

        /* ============================================================== */
        /* 参考音频（音色克隆）                                            */
        /* ============================================================== */

        /**
         * 参考音频存放目录（绝对路径）
         *
         * 【为什么用点开头的目录】参考音频是你的原声 —— 一旦能被 URL 公开下载，
         * 任何人都可以拿去克隆你的声音。放在 usr/uploads/tts/_voice 这种普通
         * 目录里是能直接下载的（本机实测 HTTP 200），所以改用 .voice：
         * 点开头的路径会被 nginx 常见的 dotfile 规则拦掉（本机实测 403）。
         *
         * 面板里的试听不走这个路径，改由 Action 的 voice 接口带鉴权转发，
         * 所以管理员照样听得到。
         *
         * Qwen 服务端读的是磁盘上的本地路径，与文件能否通过 HTTP 访问无关。
         *
         * @return string
         */
        public static function refAudioDir()
        {
            return self::audioPath() . '/.voice';
        }

        /**
         * 处理后的参考音频绝对路径
         *
         * @return string
         */
        public static function refAudioFile()
        {
            return self::refAudioDir() . '/ref.wav';
        }

        /**
         * 接收端地址 —— 从 openaiBaseUrl 自动推导，不再单独填
         *
         * 【为什么去掉这个设置项】接收端和 Qwen 服务是**同一个端口上的同一个服务**
         * （receiver.py 同时提供 /voice 和 /v1/*）。所以再让用户填一遍 host:port
         * 纯属重复劳动，还容易填不一致。
         *
         * openaiBaseUrl 形如 http://host:8894/v1 → 去掉结尾的 /v1 就是接收端地址。
         * 真要分开（接收端与 TTS 不同机）时，用 refUploadUrlOverride 覆盖。
         *
         * @return string
         */
        public static function refUploadUrl()
        {
            $override = trim((string) self::opt('refUploadUrlOverride', ''));

            if ('' !== $override) {
                return rtrim($override, '/');
            }

            $base = trim((string) self::opt('openaiBaseUrl', ''));

            if ('' === $base) {
                return '';
            }

            return (string) preg_replace('#/v\d+$#i', '', rtrim($base, '/'));
        }

        /**
         * 接收端密钥 —— 默认与 openaiKey 相同
         *
         * 同理：receiver.py 的**同一个 --token** 同时管上传和代理，
         * 所以默认复用 openaiKey，不让用户填两遍。要分开时用 refUploadTokenOverride。
         *
         * @return string
         */
        public static function refUploadToken()
        {
            $override = trim((string) self::opt('refUploadTokenOverride', ''));

            return '' !== $override ? $override : trim((string) self::opt('openaiKey', ''));
        }

        /**
         * 把库里存着的配置裁剪到白名单之内（自愈）
         *
         * 核心渲染配置页时会遍历**库里存的每个键**去 getInput()，
         * 而 getInput() 没有 isset 保护 —— 键不在表单里就是 fatal。
         * 所以历史版本删掉的键必须清掉，否则老站点升级后设置页直接 500。
         *
         * 只在真的需要裁剪时才写库。
         *
         * @return array 裁剪**之前**的键名列表
         *               —— 调用方要用它给「已被清掉但核心仍持有」的键补隐藏输入，
         *               所以必须在这个方法清库**之前**把键名取出来。
         */
        protected static function pruneStoredConfig()
        {
            try {
                $stored = self::dbConfig();

                if (!is_array($stored) || empty($stored)) {
                    return array();
                }

                $keysBefore = array_map('strval', array_keys($stored));

                $allow  = array_flip(self::configKeys());
                $pruned = array_intersect_key($stored, $allow);

                if (count($pruned) === count($stored)) {
                    return $keysBefore;   // 没有多余键，不用动
                }

                // 直接写回裁剪后的配置（不能走 saveSettings，那会与默认值合并后再写，
                // 对「只想去掉多余键」这件事来说没必要，而且这里要避免递归）
                $db = ttsv_db();
                $db->query(
                    $db->update('table.options')
                        ->rows(array('value' => serialize($pruned)))
                        ->where('name = ?', 'plugin:TtsVoice')
                );

                self::resetOptCache();
            } catch (Throwable $e) {
                // 自愈失败不影响页面渲染
            }

            return isset($keysBefore) ? $keysBefore : array();
        }

        /**
         * 体积格式化（内部小工具）
         *
         * @param int|float $bytes
         * @return string
         */
        protected static function humanSize($bytes)
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

        /**
         * 「音色」设置块：当前样本 + 上传/删除按钮
         *
         * 【为什么自绘 HTML】Typecho 的插件配置表单没有文件上传控件，
         * 而这里要的正是「点按钮上传」。所以用一个 Layout 块塞进自绘 UI，
         * 事件交给 assets/admin.js —— 配置页本来就加载了它，
         * 而且 renderAdminAssets() 已经注入了 window.__TTS_ADMIN__（含 action 与 CSRF token）。
         *
         * 【不上传会怎样】不选样本时合成会自动回退到「默认音色」，
         * 所以这个块完全是可选的，不会挡住只想用预置音色的人。
         *
         * @return string
         */
        public static function voicePickerHtml()
        {
            $info = self::refAudioInfo();
            $url  = self::refUploadUrl();
            $tok  = self::refUploadToken();
            $has  = !empty($info['exists']);

            $h = '<div class="ttsv-voice-picker" id="ttsv-vp">';

            // ---- 当前状态 ----
            if ($has) {
                $meta = array();

                if ($info['seconds'] > 0) {
                    $meta[] = number_format($info['seconds'], 1) . ' ' . _t('秒');
                }

                $meta[] = self::humanSize($info['size']);

                /*
                 * 【必须显示真实格式】本机有没有 ffmpeg 会导致同一个上传动作产出不同格式：
                 *   有 → 一律转成 24kHz 单声道 wav；
                 *   没有 → **原样收下**（真实格式，通常是 mp3），交给 TTS 主机侧归一化。
                 * 而 TTS 主机侧如果也没有 ffmpeg，接收端会**拒收非 wav**（实测：
                 * HTTP 400「本机没找到 ffprobe，只能校验 wav」）。所以格式必须让用户看见，
                 * 否则「音色已上传」四个字会让人以为万事大吉。
                 */
                $ext = (is_array($info['format']) && !empty($info['format']['ext']))
                    ? strtoupper((string) $info['format']['ext'])
                    : '';

                if ('' !== $ext) {
                    $meta[] = $ext;
                }

                $h .= '<div class="ttsv-vp-row">'
                    . '<span class="ttsv-vp-badge is-ok">' . _t('已上传音色样本') . '</span>'
                    . '<span class="ttsv-vp-meta">'
                    . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES, 'UTF-8')
                    . ' · ' . htmlspecialchars((string) $info['tag'], ENT_QUOTES, 'UTF-8')
                    . '</span>'
                    . '<audio id="ttsv-vp-audio" controls preload="none"></audio>'
                    . '</div>';

                /*
                 * 【如实说明】样本只在**网站本机**、还没推到 TTS 主机（refAudioPath 为空）时，
                 * 提交是**不会**带 ref_audio 的 —— 服务端会用它的默认音色。
                 * 不写清楚的话，「已上传音色样本」会让人以为用的就是自己的声音。
                 */
                if ('' === trim((string) self::opt('refAudioPath', ''))) {
                    $h .= '<div class="ttsv-vp-row"><span class="ttsv-vp-meta" style="color:#b26a00">'
                        . _t('这份样本<b>只在网站本机</b>，还没推到 TTS 主机 —— '
                            . '现在提交会用主机的<b>默认音色</b>。'
                            . '想用自己的声音：到「管理 → 朗读」点「重新同步到 TTS 主机」，或在下面重新上传一次。')
                        . '</span></div>';
                }

                // 本机没有 ffmpeg → 样本没被裁剪/归一化，这里说清「会怎样」
                if ('' === self::ffmpegPath()) {
                    $isWav = ('WAV' === $ext);

                    $h .= '<div class="ttsv-vp-row"><span class="ttsv-vp-meta" style="color:#b26a00">'
                        . ($isWav
                            ? _t('本站没有 ffmpeg：这个样本<b>没有被裁剪，也没转成 24kHz 单声道</b>。'
                                . 'TTS 主机侧若装了 ffmpeg 会自行归一化，通常不影响使用。')
                            : _t('本站没有 ffmpeg，样本是 <b>' . $ext . '</b> 原样上传的（没被裁剪/转码）。'
                                . '<b>TTS 主机侧若也没装 ffmpeg，接收端会拒收</b>（它只能校验 wav）。'
                                . '给<b>任意一侧</b>装上 ffmpeg 即可：本站装上后重新上传，会自动转成 24kHz 单声道 WAV。'))
                        . '</span></div>';
                }
            } else {
                $h .= '<div class="ttsv-vp-row">'
                    . '<span class="ttsv-vp-badge is-off">' . _t('未上传') . '</span>'
                    . '<span class="ttsv-vp-meta">'
                    . '<span>' . _t('未上传音色样本 —— <b>不影响使用</b>，提交时会用 TTS 主机的默认音色。') . '</span>'
                    . '</span>'
                    . '</div>';
            }

            // ---- 操作按钮 ----
            $h .= '<div class="ttsv-vp-row">'
                . '<input type="file" id="ttsv-vp-file" accept="audio/*">'
                . '<button type="button" class="btn primary" id="ttsv-vp-upload">'
                . ($has ? _t('更换音色样本') : _t('上传音色样本'))
                . '</button>';

            if ($has) {
                $h .= '<button type="button" class="btn" id="ttsv-vp-clear">' . _t('删除样本') . '</button>';
            }

            $h .= '<span id="ttsv-vp-msg"></span>'
                . '</div>';

            // ---- 说明 ----
            $h .= '<p class="description ttsv-vp-desc">'
                . sprintf(
                    _t('上传后会自动裁到 <b>%d 秒</b>并转成 24kHz 单声道 wav；'
                        . '建议用一段无背景音乐、无第二人说话的独白，10 秒以上。<br>'
                        . '<b>只有当上面「音色」选的是「自定义音色」、且这里上传了样本时，'
                        . '插件才会用它来克隆你的音色</b>（本插件只支持自定义音色，样本是必填项）。<br>'
                        . '样本里说的内容<b>不用写下来</b>（下面那项「音色样本的文本」保持留空即可，'
                        . '内容对不上反而会引入杂音）。<br>'
                        . '若本站没有 ffmpeg，上传的样本会<b>原样推送</b>（不裁剪、不转码）；'
                        . 'TTS 主机侧也没有 ffmpeg 时它只能校验 wav，非 wav 会被拒收 —— '
                        . '此时给它装 ffmpeg，或在本站装 ffmpeg 后重新上传即可。'),
                    self::REF_AUDIO_SECONDS
                );

            if ('' !== $url) {
                $h .= '<br>' . _t('上传会推送到')
                    . ' <code>' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '</code>'
                    . ('' === $tok
                        ? '（<span style="color:#c0392b">' . _t('服务端未启用鉴权，不发送密钥') . '</span>）'
                        : '（' . _t('已带密钥') . '）')
                    . '　' . _t('地址由上面的「Qwen 服务端地址」自动推导，不用另填。');
            } else {
                $h .= '<br><span style="color:#c0392b">'
                    . _t('还没填上面的「Qwen 服务端地址」，样本无法推送到 TTS 主机。')
                    . '</span>';
            }

            $h .= '</p></div>';

            return $h;
        }

        /**
         * 参考音频指纹的请求级缓存
         *
         * 面板每行都要判「音色已过期」，原实现会**每行都 md5 一次**同一个文件。
         * 这里按「路径 + mtime + 大小」缓存最后一条：同一请求内算一次就够，
         * 文件真被换了（上传）时 key 会变，自动重算。
         *
         * @var array
         */
        protected static $refHashCache = array();

        /**
         * 音色样本的「来源标识」
         *
         * 【为什么需要】接收端按 `<目录>/<来源标识>/ref.wav` 存样本。多站点共用一台
         * TTS 主机时，如果都用固定的 ref.wav，后上传的站会把前一个站的样本覆盖掉。
         *
         * 取值规则：`<域名>-<站点指纹8位>`，形如 `blog-example-com-3f9a1c2e`
         *   - 用域名是为了在接收端/面板的日志里一眼看懂是哪个站；
         *   - 用 `siteUrl` 的 md5 前 8 位是为了区分「同一个域名下的多个站点」；
         *   - **不带点**：接收端可能先按文件名规则清洗，带点会被当扩展名切掉。
         *
         * 生成一次就写回配置（`refSourceId`），所以改域名/改站点地址都不会换目录。
         *
         * @return string
         */
        public static function refSourceId()
        {
            $saved = trim((string) self::opt('refSourceId', ''));

            if ('' !== $saved) {
                return $saved;
            }

            $siteUrl = '';
            $host    = '';

            try {
                $siteUrl = (string) Helper::options()->siteUrl;
                $host    = (string) parse_url($siteUrl, PHP_URL_HOST);
            } catch (Throwable $e) {
                // 拿不到就用兜底值
            }

            if ('' === $host) {
                $host = 'site';
            }

            // 只留 [A-Za-z0-9._-]，点换成横线（见上面的说明）
            $host = strtolower($host);
            $host = preg_replace('/[^a-z0-9._-]/', '-', $host);
            $host = str_replace('.', '-', $host);
            $host = trim($host, '-');
            $host = substr($host, 0, 40);

            if ('' === $host) {
                $host = 'site';
            }

            $id = $host . '-' . substr(md5($siteUrl . '|' . $host), 0, 8);

            // 写回配置：失败也不影响本次使用（下次再生成，值是一样的）
            try {
                self::saveSettings(array('refSourceId' => $id));
            } catch (Throwable $e) {
                // 忽略
            }

            return $id;
        }

        /**
         * 参考音频的指纹
         *
         * 用「处理之后」的文件算 md5 —— 这样同一段录音重复上传得到同样的指纹，
         * 「音色已过期」的判定才不会因为重传而误报。
         *
         * @return string 空字符串表示还没有参考音频
         */
        public static function refAudioHash()
        {
            $file = self::refAudioFile();

            if (!is_file($file)) {
                return '';
            }

            $key = $file . '|' . (int) @filemtime($file) . '|' . (int) @filesize($file);

            if (array_key_exists($key, self::$refHashCache)) {
                return self::$refHashCache[$key];
            }

            $hash = @md5_file($file);
            $hash = is_string($hash) ? $hash : '';

            // 只留最后一条：请求内文件被替换时不会读到旧值
            self::$refHashCache = array($key => $hash);

            return $hash;
        }

        /**
         * 当前参考音频的「标签」——入库到 tts_jobs.voice 用
         *
         * 形如 ref:a1b2c3d4（取指纹前 8 位）。历史数据里这个字段存的是预置音色名，
         * 两者靠前缀区分，不会互相误判。
         *
         * @return string
         */
        public static function refVoiceTag()
        {
            /*
             * 只认「已推到 TTS 主机」的样本（refAudioPath）。
             * 网站本机留着旧样本、但没推到主机时，任务里显示「默认音色」才与实际一致
             * （此时提交不带 ref_audio，接收端用的是它自己的 default 音色）。
             */
            if ('' === trim((string) self::opt('refAudioPath', ''))) {
                return '';
            }

            $hash = self::refAudioHash();

            return '' === $hash ? '' : 'ref:' . substr($hash, 0, 8);
        }

        /**
         * 当前有没有可用的音色样本（上传并处理后的文件在不在）
         *
         * 【语义】只表示「网站本机留着一份样本文件」，用于界面展示与试听。
         * 1.6.8 起样本是**可选**的：TTS 主机有自己的默认音色，没有样本也能合成；
         * 真正决定「这次用不用克隆音色」的是 refAudioPath（主机上的路径）。
         *
         * @return bool
         */
        public static function hasCloneVoice()
        {
            return '' !== self::refAudioHash();
        }

        /**
         * 能不能出音频（= 有没有音色样本）
         *
         * 保留这个别名是为了调用点读起来更顺；语义与 hasCloneVoice() 完全一致。
         *
         * @return bool
         */
        public static function usesCloneVoice()
        {
            return self::hasCloneVoice();
        }

        /**
         * 没上传样本时的统一提示（设置页与生成流程共用同一句话）
         *
         * @return string
         */
        public static function needSampleMessage()
        {
            // 1.6.8 起音色样本**可选**：TTS 主机上有默认音色，不上传也能生成。
            // 这条文案只在「想说明为什么用的是默认音色」时展示。
            return '当前使用 TTS 主机的默认音色；'
                . '想用自己的声音，可到「设置 → TtsVoice → 高级设置」上传一段 10 秒以上的人声。';
        }

        /**
         * 克隆（Base）模型名
         *
         * 设置页不暴露这个选项（插件只有克隆一条路），键留在库里，这里读它；
         * 键为空时退回旧键 openaiModel，再退回内置默认名。
         *
         * @return string
         */
        public static function cloneModelName()
        {
            $model = trim((string) self::opt('openaiCloneModel', ''));

            if ('' !== $model) {
                return $model;
            }

            $legacy = trim((string) self::opt('openaiModel', ''));

            return '' === $legacy ? 'mlx-community/Qwen3-TTS-12Hz-1.7B-Base-8bit' : $legacy;
        }

        /**
         * 这次合成实际要用的模型名（现在恒为 Base）
         *
         * @return string
         */
        public static function neededModelName()
        {
            return self::cloneModelName();
        }

        /**
         * 查询 TTS 主机（接收端 v1.2.0+）的上游状态
         *
         * `GET {接收端}/voice/status`，带 `X-TtsVoice-Token`。只读接口。
         * 返回里 `models` 是**上游当前驻留内存**的模型列表，`cold_load_seconds` 是冷加载耗时。
         *
         * 【降级】接收端是旧版本、没配地址、或不可达时，一律返回 ok=false，
         * 调用方应当静默忽略 —— 不能因为查不到状态就挡住正常合成。
         *
         * @param int $timeout 秒；本接口很轻，默认 4 秒足够
         * @return array {ok, error, version, reachable, models, modelsLoaded, coldLoadSeconds}
         */
        public static function ttsHostStatus($timeout = 4)
        {
            $empty = array(
                'ok'              => false,
                'error'           => '',
                'version'         => '',
                'reachable'       => false,
                'models'          => array(),
                'modelsLoaded'    => 0,
                'coldLoadSeconds' => 25,
            );

            $url = trim((string) self::refUploadUrl());

            if ('' === $url || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) {
                return $empty;
            }

            $headers = array('Accept: application/json');
            $token   = self::refUploadToken();

            if ('' !== $token) {
                $headers[] = 'X-TtsVoice-Token: ' . $token;
            }

            $seconds = max(1, intval($timeout));

            $ch = curl_init(rtrim($url, '/') . '/voice/status');

            curl_setopt_array($ch, array(
                CURLOPT_HTTPGET        => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => min(3, $seconds),
                CURLOPT_TIMEOUT        => $seconds,
                CURLOPT_USERAGENT      => 'TtsVoice',
            ));

            $raw   = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err   = (string) curl_error($ch);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            if (false === $raw || 200 !== $code) {
                $empty['error'] = (false === $raw)
                    ? ('连不上 TTS 接收端：' . ('' !== $err ? $err : '未知错误'))
                    : ('接收端返回 HTTP ' . $code);

                return $empty;
            }

            $json = json_decode((string) $raw, true);

            if (!is_array($json) || empty($json['ok'])) {
                $empty['error'] = (is_array($json) && !empty($json['error']))
                    ? (string) $json['error']
                    : '接收端 /voice/status 返回格式无法解析';

                return $empty;
            }

            $models = array();

            if (isset($json['models']) && is_array($json['models'])) {
                foreach ($json['models'] as $item) {
                    if (is_string($item) && '' !== trim($item)) {
                        $models[] = trim($item);
                        continue;
                    }

                    if (is_array($item)) {
                        foreach (array('id', 'model', 'name') as $k) {
                            if (isset($item[$k]) && is_string($item[$k]) && '' !== trim($item[$k])) {
                                $models[] = trim($item[$k]);
                                break;
                            }
                        }
                    }
                }
            }

            return array(
                'ok'              => true,
                'error'           => '',
                'version'         => isset($json['version']) ? (string) $json['version'] : '',
                'reachable'       => !empty($json['reachable']),
                'models'          => $models,
                'modelsLoaded'    => isset($json['models_loaded']) ? intval($json['models_loaded']) : count($models),
                'coldLoadSeconds' => isset($json['cold_load_seconds']) ? intval($json['cold_load_seconds']) : 25,
            );
        }

        /**
         * 这次要用的模型在 TTS 主机上是不是冷的（没驻留内存）
         *
         * @param array|null $status 传 null 时自己查一次 /voice/status
         * @return array {known:bool, cold:bool, needed:string, seconds:int, models:array, error:string}
         */
        public static function coldModelInfo($status = NULL)
        {
            $needed = self::neededModelName();

            $out = array(
                'known'   => false,
                'cold'    => false,
                'needed'  => $needed,
                'seconds' => 25,
                'models'  => array(),
                'error'   => '',
            );

            if (NULL === $status) {
                $status = self::ttsHostStatus();
            }

            if (empty($status['ok'])) {
                $out['error'] = isset($status['error']) ? (string) $status['error'] : '';

                return $out;
            }

            $out['known']   = true;
            $out['seconds'] = isset($status['coldLoadSeconds']) ? (int) $status['coldLoadSeconds'] : 25;
            $out['models']  = isset($status['models']) ? (array) $status['models'] : array();
            $out['cold']    = !in_array($needed, $out['models'], true);

            return $out;
        }

        /**
         * 冷加载的人话提示
         *
         * @param bool $force 调用方已经拿到确凿证据（如响应头 X-TtsVoice-Model-Cold）时传 true，
         *                    此时即使 /voice/status 刚好查不到（或模型已加载完）也给提示
         * @return string 不需要提示时返回空串
         */
        public static function coldModelHint($force = false)
        {
            $info = self::coldModelInfo();
            $cold = !empty($info['cold']);

            if (!$force && (empty($info['known']) || !$cold)) {
                return '';
            }

            $seconds = (int) $info['seconds'];

            if ($seconds <= 0) {
                $seconds = 25;
            }

            // $force = 有响应头这种确凿证据；否则只是「没在驻留列表里」，留一点余地
            return '（TTS 模型 ' . $info['needed']
                . ($force ? ' 本次为冷加载' : ' 当前未驻留内存（可能是冷加载）')
                . '，约 ' . $seconds . ' 秒后自动可用；这不是故障，稍后重试即可）';
        }

        /**
         * 写入 tts_jobs.voice 的标签
         *
         * 现在只有克隆一条路，所以恒为 ref:<样本指纹> —— 样本一换，
         * 旧任务的这个标签就对不上，面板据此标「音色已过期」（提示重生成）。
         * 没有样本时给一个固定值，方便排查。
         *
         * @return string
         */
        public static function jobVoiceTag()
        {
            $tag = self::refVoiceTag();

            return '' === $tag ? '默认音色' : $tag;
        }

        /**
         * 传给 TTS 主机的参考音频路径
         *
         * 【为什么可配置】mlx-audio 服务端是 `os.path.exists(ref_audio)` ——
         * ref_audio 必须是「跑 TTS 的那台机器」上的本地路径，不能是 URL 或 base64。
         * 所以网站和 TTS 不在同一台机器时，这里要填 TTS 主机上的路径。
         * 留空则用本机绝对路径（同机 / 共享存储时正确）。
         *
         * @return string
         */
        public static function refAudioForHost()
        {
            $custom = trim((string) self::opt('refAudioPath', ''));

            return '' !== $custom ? $custom : self::refAudioFile();
        }

        /**
         * 参考文本（可选）
         *
         * 填了走 ICL 模式，音色更像；留空则只用说话人嵌入。
         *
         * @return string
         */
        public static function refText()
        {
            return trim((string) self::opt('refText', ''));
        }

        /**
         * 处理上传的参考音频：裁到 REF_AUDIO_SECONDS 秒、转 24kHz 单声道 wav
         *
         * 参考音频的规格直接决定克隆效果，让用户自己准备容易出错，
         * 所以统一在这里用 ffmpeg 规整一遍。
         *
         * @param string $tmpFile 上传的临时文件
         * @return array {ok:bool, error:string, file:string, seconds:float}
         */
        public static function processRefAudio($tmpFile)
        {
            $tmpFile = (string) $tmpFile;

            if ('' === $tmpFile || !is_file($tmpFile)) {
                return array('ok' => false, 'error' => '上传文件不存在', 'file' => '', 'seconds' => 0);
            }

            $dir = self::refAudioDir();

            if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return array('ok' => false, 'error' => '无法创建目录 ' . $dir, 'file' => '', 'seconds' => 0);
            }

            $target = self::refAudioFile();
            $ffmpeg = self::ffmpegPath();

            /*
             * 【没有 ffmpeg 时：原样收下，但**必须带上真实的格式】
             *
             * 这是踩过的坑：插件以前不管收到什么都告诉接收端「这是 ref.wav」，
             * 而上游 mlx-audio 是**按扩展名选解码器**的
             * （见 mlx_audio/audio_io.py: .m4a/.aac/.ogg/... 才用 ffmpeg，其余走 miniaudio）。
             * 个人站基本没有 ffmpeg、录音基本是 mp3 —— 于是 mp3 的字节被当成 .wav 交给
             * miniaudio，解不开，最后表现为合成时报 `IncompleteRead(0 bytes read)`。
             *
             * 所以这里先嗅探真实格式：认不出来就直接拒绝（让用户换个格式），
             * 比推上去等合成时炸掉强得多。
             */
            if ('' === $ffmpeg) {
                $format = self::sniffAudioFormat($tmpFile);

                if (NULL === $format) {
                    return array(
                        'ok'      => false,
                        'error'   => '识别不出这个音频格式。这台服务器上没有 ffmpeg，无法自动转码，'
                                     . '请上传 wav / mp3 / m4a 之一。',
                        'file'    => '',
                        'seconds' => 0,
                    );
                }

                if (!@copy($tmpFile, $target)) {
                    return array('ok' => false, 'error' => '无法写入 ' . $target, 'file' => '', 'seconds' => 0);
                }

                return array(
                    'ok'      => true,
                    'error'   => '',
                    'file'    => $target,
                    'seconds' => self::probeSeconds($target),
                    'format'  => $format,
                    'warning' => '这台服务器上没有 ffmpeg，已跳过裁剪与格式转换（样本将原样上传，'
                                 . '由 TTS 主机侧归一化）。',
                );
            }

            $cmd = escapeshellarg($ffmpeg)
                . ' -y -loglevel error'
                . ' -i ' . escapeshellarg($tmpFile)
                . ' -t ' . self::REF_AUDIO_SECONDS
                . ' -ar 24000 -ac 1 -c:a pcm_s16le'
                . ' ' . escapeshellarg($target)
                . ' 2>&1';

            $output = array();
            $code   = 0;
            @exec($cmd, $output, $code);

            if (0 !== $code || !is_file($target) || filesize($target) <= 44) {
                @unlink($target);

                $detail = trim(implode(' ', array_slice($output, -3)));

                return array(
                    'ok'      => false,
                    'error'   => '音频处理失败' . ('' !== $detail ? '：' . $detail : ''),
                    'file'    => '',
                    'seconds' => 0,
                );
            }

            return array(
                'ok'      => true,
                'error'   => '',
                'file'    => $target,
                'seconds' => self::probeSeconds($target),
                // 转码后的产物就是标准 WAV，直接写死，省一次嗅探
                'format'  => array('ext' => 'wav', 'mime' => 'audio/wav'),
            );
        }

        /**
         * 把一段响应体压成「能塞进报错里的一小段」
         *
         * 【为什么需要】接收端地址填错时（例如指到了 NAS 的网页后台），
         * 对方会回 200 + 一整页 HTML，报错只剩「接收端拒绝：HTTP 200」，
         * 完全看不出是地址问题。带上 Content-Type 和响应开头，一眼就能认出来。
         * 做法与 Doctor.php 里那处 HTTP 自检一致。
         *
         * @param string $body
         * @param int    $limit
         * @return string
         */
        public static function bodyExcerpt($body, $limit = 160)
        {
            $text = (string) $body;

            // 先整段去掉 <script> / <style>（它们的**内容**不是标签，strip_tags 去不掉），
            // 否则截出来全是 CSS，等于没截。
            $text = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $text);
            $text = is_string($text) ? $text : '';

            // HTML 页面优先取 <title>，那通常就是「对面是谁」的答案
            $title = '';
            if (preg_match('#<title[^>]*>(.*?)</title>#is', $text, $m)) {
                $title = trim(preg_replace('/\s+/u', ' ', strip_tags($m[1])));
            }

            $text = trim(strip_tags($text));

            // 压掉换行/连续空白，免得报错框被撑开
            $text = preg_replace('/\s+/u', ' ', $text);
            $text = is_string($text) ? trim($text) : '';

            if ('' !== $title && 0 !== strpos($text, $title)) {
                $text = 'title=' . $title . ' ｜ ' . $text;
            }
            $text = is_string($text) ? trim($text) : '';

            if ('' === $text) {
                return '';
            }

            return function_exists('mb_substr')
                ? mb_substr($text, 0, $limit, 'UTF-8')
                : substr($text, 0, $limit);
        }

        /**
         * 嗅探音频文件的真实格式（不看扩展名，看magic bytes）
         *
         * 【为什么必须嗅探】上游 mlx-audio 按**扩展名**挑解码器：
         *   .m4a/.m4b/.mp4/.aac/.ogg/.opus/.webm/.caf → ffmpeg
         *   其余（含 .mp3/.wav）                        → miniaudio
         * 名字和内容一旦不一致（比如 mp3 的字节叫 ref.wav），就会解不开。
         * 这里只做「够用就好」的判断：能认出常见容器即可，认不出返回 null。
         *
         * @param string $file
         * @return array|null {ext, mime}；无法识别返回 null
         */
        public static function sniffAudioFormat($file)
        {
            $fh = @fopen($file, 'rb');

            if (false === $fh) {
                return NULL;
            }

            $head = (string) @fread($fh, 4096);
            @fclose($fh);

            if (strlen($head) < 4) {
                return NULL;
            }

            $b0 = ord($head[0]);
            $b1 = ord($head[1]);

            // RIFF....WAVE
            if ('RIFF' === substr($head, 0, 4) && 'WAVE' === substr($head, 8, 4)) {
                return array('ext' => 'wav', 'mime' => 'audio/wav');
            }

            if ('fLaC' === substr($head, 0, 4)) {
                return array('ext' => 'flac', 'mime' => 'audio/flac');
            }

            if ('OggS' === substr($head, 0, 4)) {
                // Opus 也在 Ogg 容器里（看有没有 OpusHead）
                if (false !== strpos(substr($head, 0, 128), 'OpusHead')) {
                    return array('ext' => 'opus', 'mime' => 'audio/ogg');
                }

                return array('ext' => 'ogg', 'mime' => 'audio/ogg');
            }

            // ....ftyp（m4a / mp4）
            if ('ftyp' === substr($head, 4, 4)) {
                return array('ext' => 'm4a', 'mime' => 'audio/mp4');
            }

            if ("\x1a\x45\xdf\xa3" === substr($head, 0, 4)) {
                return array('ext' => 'webm', 'mime' => 'audio/webm');
            }

            if ('caff' === substr($head, 0, 4)) {
                return array('ext' => 'caf', 'mime' => 'audio/x-caf');
            }

            // ID3 标签头，或裸 MP3 帧同步（11 个 1）
            if ('ID3' === substr($head, 0, 3) || (0xFF === $b0 && 0xE0 === ($b1 & 0xE0))) {
                return array('ext' => 'mp3', 'mime' => 'audio/mpeg');
            }

            // ADTS AAC：0xFFF?（layer 必须是 00）
            if (0xFF === $b0 && 0xF6 === ($b1 & 0xF6)) {
                return array('ext' => 'aac', 'mime' => 'audio/aac');
            }

            return NULL;
        }

        /**
         * 找 ffmpeg 可执行文件
         *
         * 【public 的理由】Doctor（体检页）要把它显示成一行状态，让用户一眼看到
         * 「这台机器没有 ffmpeg」——这正是「服务端编不出 MP3，只能给 WAV」的根因，
         * 藏起来用户就只能靠猜。
         *
         * @return string 找不到返回空字符串
         */
        public static function ffmpegPath()
        {
            $candidates = array(
                '/opt/homebrew/bin/ffmpeg',
                '/usr/local/bin/ffmpeg',
                '/usr/bin/ffmpeg',
            );

            foreach ($candidates as $path) {
                if (is_file($path) && is_executable($path)) {
                    return $path;
                }
            }

            // 退而求其次：交给 PATH
            $found = @trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));

            return ('' !== $found && is_file($found)) ? $found : '';
        }

        /**
         * 读音频时长（秒）
         *
         * @param string $file
         * @return float
         */
        protected static function probeSeconds($file)
        {
            $ffprobe = str_replace('ffmpeg', 'ffprobe', self::ffmpegPath());

            if ('' === $ffprobe || !is_file($ffprobe)) {
                return 0;
            }

            $out = @trim((string) @shell_exec(
                escapeshellarg($ffprobe)
                . ' -v error -show_entries format=duration -of csv=p=0 '
                . escapeshellarg($file) . ' 2>/dev/null'
            ));

            return is_numeric($out) ? round((float) $out, 2) : 0;
        }

        /**
         * 本地后处理：变速 + 统一编码（in-place）
         *
         * 【为什么放本地】上游 mlx-audio 的 `speed` 参数是空实现（传了不生效），
         * 想加快语速只能在拿到音频后自己动手。这里用 ffmpeg 的 atempo：
         * 时间轴伸缩但**不改音高**（不会变花栗鼠），单次只支持 0.5~2.0，
         * 超出就串联多个 atempo。
         *
         * 顺带把采样率/声道/编码统一成 24kHz 单声道（和参考音频一致），
         * 这样 MP3/WAV 混用、上游换了 fmt 都不会让播放器踩坑。
         *
         * @param string $input  源文件（可以是目标路径本身）
         * @param string $output 目标路径（后缀必须与 $format 一致）
         * @param float  $speed  倍率，1.0 = 不变速
         * @param string $format wav | mp3
         * @return array {ok:bool, error:string, seconds:float}
         */
        public static function postProcessAudio($input, $output, $speed = 1.0, $format = 'wav')
        {
            $input  = (string) $input;
            $output = (string) $output;
            $speed  = floatval($speed);

            if ($speed <= 0) {
                $speed = 1.0;
            }

            if (!is_file($input) || filesize($input) <= 0) {
                return array('ok' => false, 'error' => '后处理失败：源文件不存在', 'seconds' => 0);
            }

            $ffmpeg = self::ffmpegPath();

            if ('' === $ffmpeg) {
                return array('ok' => false, 'error' => '后处理失败：未找到 ffmpeg', 'seconds' => 0);
            }

            // atempo 单次上限 2.0 / 下限 0.5 → 串联到覆盖目标倍率
            $filters = array();
            $remain  = $speed;

            while ($remain > 2.0) {
                $filters[] = 'atempo=2.0';
                $remain   /= 2.0;
            }

            while ($remain < 0.5) {
                $filters[] = 'atempo=0.5';
                $remain   /= 0.5;
            }

            $filters[] = 'atempo=' . rtrim(rtrim(number_format($remain, 6, '.', ''), '0'), '.');

            $wav = (0 === strpos(strtolower(trim((string) $format)), 'wav'));

            $codec = $wav
                ? '-ar 24000 -ac 1 -c:a pcm_s16le'
                : '-ar 24000 -ac 1 -c:a libmp3lame -b:a 128k';

            // 临时文件必须带对的扩展名 —— ffmpeg 靠后缀推断封装格式，
            // 叫 .pp 的话它会报 "Unable to find a suitable output format"。
            $tmp = $output . '.pp.' . ($wav ? 'wav' : 'mp3');

            // 输出到临时文件，成功了再原子替换 —— 失败时原文件还在，不会丢音频
            $cmd = escapeshellarg($ffmpeg)
                . ' -y -loglevel error'
                . ' -i ' . escapeshellarg($input)
                . ' -filter:a ' . escapeshellarg(implode(',', $filters))
                . ' ' . $codec
                . ' ' . escapeshellarg($tmp)
                . ' 2>&1';

            $lines = array();
            $code  = 0;
            @exec($cmd, $lines, $code);

            if (0 !== $code || !is_file($tmp) || filesize($tmp) <= 44) {
                @unlink($tmp);

                $detail = trim(implode(' ', array_slice($lines, -3)));

                return array(
                    'ok'      => false,
                    'error'   => '后处理失败' . ('' !== $detail ? '：' . $detail : ''),
                    'seconds' => 0,
                );
            }

            // $input 可能就是 $output 本身；用完原文件就没价值了
            if (realpath($input) !== realpath($output) && $input !== $output) {
                @unlink($input);
            }

            if (!@rename($tmp, $output)) {
                @unlink($tmp);

                return array('ok' => false, 'error' => '后处理失败：无法写入 ' . $output, 'seconds' => 0);
            }

            @chmod($output, 0644);

            return array('ok' => true, 'error' => '', 'seconds' => self::probeSeconds($output));
        }

        /**
         * 参考音频的信息（面板展示用）
         *
         * @return array
         */
        public static function refAudioInfo()
        {
            $file = self::refAudioFile();

            if (!is_file($file)) {
                return array(
                    'exists' => false, 'hash' => '', 'tag' => '',
                    'size' => 0, 'seconds' => 0, 'updated' => 0,
                    'url' => '', 'forHost' => self::refAudioForHost(),
                    'source' => self::refSourceId(),
                    'format' => NULL,
                );
            }

            return array(
                'exists'  => true,
                'hash'    => self::refAudioHash(),
                'tag'     => self::refVoiceTag(),
                'size'    => (int) @filesize($file),
                'seconds' => self::probeSeconds($file),
                'updated' => (int) @filemtime($file),
                // 不提供直链：参考音频在 .voice 下，直链会被 nginx 拦掉（这正是我们要的）。
                // 面板的试听走 Action 的 voice 接口，带管理员鉴权。
                'url'     => '',
                'forHost' => self::refAudioForHost(),
                // 多站点共用接收端时用它对账：样本会存到 <接收端目录>/<来源标识>/
                'source'  => self::refSourceId(),
                'format'  => self::sniffAudioFormat($file),
            );
        }

        /**
         * 把本机处理好的音色样本推送到 TTS 主机
         *
         * 【为什么需要】Qwen 服务端读参考音频用的是 os.path.exists(ref_audio)，
         * 只认「跑 TTS 那台机器上的本地路径」。网站和 TTS 不同机时，
         * 站点上上传的文件必须真的落到 TTS 主机上 —— 由那边的小接收端
         * （voice-receiver/receiver.py）接收，并回传绝对路径。
         *
         * 走裸 body 而不是 multipart：接收端就不用解析 multipart，零依赖，
         * 也避开了 Python 3.13 移除 cgi 模块的坑。
         *
         * 「接收端地址」没配置时直接跳过 —— 那是同机 / 共享存储的情况，
         * 用本机路径就够了（refAudioForHost 会自动返回本机绝对路径）。
         *
         * @return array {ok, error, path, skipped, size}
         */
        public static function pushRefAudio()
        {
            $fail = function ($error) {
                return array(
                    'ok'      => false,
                    'error'   => $error,
                    'path'    => '',
                    'skipped' => false,
                    'size'    => 0,
                    'source'  => '',
                    'format'  => '',
                );
            };

            $url = self::refUploadUrl();

            if ('' === $url) {
                return array(
                    'ok'      => true,
                    'error'   => '',
                    'path'    => '',
                    'skipped' => true,
                    'size'    => 0,
                    'source'  => self::refSourceId(),
                    'format'  => '',
                );
            }

            if (!preg_match('#^https?://#i', $url)) {
                return $fail('接收端地址必须以 http:// 或 https:// 开头');
            }

            $file = self::refAudioFile();

            if (!is_file($file)) {
                return $fail('本机还没有音色样本');
            }

            $body = @file_get_contents($file);

            if (false === $body || '' === $body) {
                return $fail('读取音色样本失败：' . $file);
            }

            if (!function_exists('curl_init')) {
                return $fail('PHP 缺少 curl 扩展，无法推送到 TTS 主机');
            }

            $token = self::refUploadToken();

            /*
             * 【如实报格式】以前这里写死 audio/wav + ref.wav，而站点上没有 ffmpeg 时
             * 上传的其实是 mp3 —— 上游按扩展名选解码器（.wav → miniaudio），
             * 于是解 mp3 失败，合成时才炸成 IncompleteRead。现在按真实字节报。
             * 嗅不出来（极罕见）才退回 wav，让接收端去判。
             */
            $format = self::sniffAudioFormat($file);
            $ext    = (NULL !== $format) ? $format['ext'] : 'wav';
            $mime   = (NULL !== $format) ? $format['mime'] : 'audio/wav';

            $headers = array(
                'Content-Type: ' . $mime,
                'X-TtsVoice-Name: ref.' . $ext,
                // 来源标识：接收端据此把样本隔离到 <dir>/<source>/ 下，
                // 多站点共用一个接收端时彼此不会覆盖（receiver v1.5.0+）。
                'X-TtsVoice-Source: ' . self::refSourceId(),
                // 关掉 100-continue：几百 KB 的文件不值得多一次往返
                'Expect:',
            );

            if ('' !== $token) {
                $headers[] = 'X-TtsVoice-Token: ' . $token;
            }

            $ch = curl_init(rtrim($url, '/') . '/voice');

            curl_setopt_array($ch, array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_USERAGENT      => 'TtsVoice',
            ));

            $raw   = curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err   = (string) curl_error($ch);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

            if (false === $raw) {
                return $fail('连接接收端失败：' . ('' !== $err ? $err : '未知错误') . '（' . $url . '）');
            }

            $json = json_decode((string) $raw, true);

            if (200 !== $code || !is_array($json) || empty($json['ok'])) {
                $msg = (is_array($json) && !empty($json['error'])) ? (string) $json['error'] : ('HTTP ' . $code);

                /*
                 * 【HTTP 200 但不是 JSON】踩过一次：把「Qwen 服务端地址」填成了
                 * NAS 的管理界面（飞牛 fnOS），它对任何路径都回 200 + 一整页 HTML，
                 * 报错只剩「接收端拒绝：HTTP 200」，完全看不出是地址填错。
                 */
                if (NULL === $json && '' !== trim((string) $raw)) {
                    $bodyTxt = (string) $raw;
                    $isHtml  = (false !== stripos($bodyTxt, '<html') || false !== stripos($bodyTxt, '<!doctype'));
                    $snippet = self::bodyExcerpt($bodyTxt);

                    $msg = '返回的不是 JSON（HTTP ' . $code
                        . ('' !== $ctype ? '，Content-Type: ' . $ctype : '')
                        . '）'
                        . ($isHtml ? '，而是一整页网页' : '')
                        . ' —— 「Qwen 服务端地址」很可能填错了（指到了别的服务/后台界面），'
                        . '它应指向接收端，例如 http://<主机>:8894/v1'
                        . ('' !== $snippet ? '。响应开头：' . $snippet : '');
                }

                // 鉴权类错误给一条能照着做的提示
                if (in_array($code, array(401, 403), true)) {
                    $msg = ('' === $token)
                        ? '接收端要鉴权，但「接收端密钥」是空的 —— 请填上它要求的密钥'
                        : '密钥被拒绝 —— 检查「接收端密钥」是否与接收端启动参数 --token 完全一致';
                }

                return $fail('接收端拒绝：' . $msg);
            }

            $path = isset($json['path']) ? trim((string) $json['path']) : '';

            if ('' === $path) {
                return $fail('接收端没有返回路径');
            }

            return array(
                'ok'      => true,
                'error'   => '',
                'path'    => $path,
                'skipped' => false,
                'size'    => isset($json['size']) ? (int) $json['size'] : 0,
                // 接收端 v1.5.0 会回传最终来源目录与归一化信息；老版本没有就退回本地的判断
                'source'  => isset($json['source']) && '' !== trim((string) $json['source'])
                    ? trim((string) $json['source'])
                    : self::refSourceId(),
                'format'  => isset($json['format']) && '' !== trim((string) $json['format'])
                    ? trim((string) $json['format'])
                    : $ext,
            );
        }

        /**
         * 推送音色样本并把返回的远端路径写进配置
         *
         * 这让「网站与 TTS 不同机」时的路径完全自动化 —— 用户不需要自己
         * 去 mini 上查路径再手填。
         *
         * @return array 同 pushRefAudio()，另加 saved 字段
         */
        public static function syncRefAudio()
        {
            $result = self::pushRefAudio();

            if (empty($result['ok']) || !empty($result['skipped'])) {
                return $result + array('saved' => false);
            }

            $path = (string) $result['path'];

            // 交给插件自己的写配置流程：它会与现有配置合并、按白名单裁剪、
            // 把 null 归一成默认值，并验证确实写进去了。
            $saved = false;

            try {
                $saved = (bool) self::saveSettings(array('refAudioPath' => $path));
            } catch (Throwable $e) {
                $saved = false;
            }

            return $result + array('saved' => $saved);
        }

        /**
         * 推送样本失败时，补一句「本站能做什么」
         *
         * 【为什么要补】接收端的报错是站在**它自己**角度说的
         * （实测原文：「本机没找到 ffprobe，只能校验 wav。请装 ffmpeg（brew install ffmpeg）
         * 后重试，或直接上传 24kHz/16bit/单声道 wav」）—— 用户看完只会想到
         * 「那我去 TTS 主机装」，却不知道**本站装 ffmpeg 更简单**：
         * 本站有 ffmpeg 时，上传的 mp3 会先被转成 24kHz 单声道 wav 再推，接收端就不需要 ffmpeg 了。
         *
         * 样本已经归一化过（本机有 ffmpeg，格式一定是 wav）时不补话，避免噪音。
         *
         * @return string 需要补的说明；不需要则为空串
         */
        public static function refPushFailHint()
        {
            $info   = self::refAudioInfo();
            $format = (is_array($info['format']) && !empty($info['format']['ext']))
                ? strtoupper((string) $info['format']['ext'])
                : '';

            return self::refPushHintText('' !== self::ffmpegPath(), $format);
        }

        /**
         * refPushFailHint() 的纯逻辑部分（便于单测：本机有 ffmpeg 时上面那条分支根本走不到）
         *
         * 【纯文本】这段会经由 JSON 交给设置页 JS 用 textContent 显示，
         * 所以不能带 HTML 标签（带了会被原样显示成 <b>…</b>）。
         *
         * @param bool   $hasFfmpeg 本站有没有 ffmpeg
         * @param string $format    本机样本的真实格式（大写，如 WAV / MP3）
         * @return string 需要补的说明；不需要则为空串
         */
        public static function refPushHintText($hasFfmpeg, $format)
        {
            // 本站有 ffmpeg → 样本一定是归一化过的 24kHz 单声道 WAV，
            // 接收端即使没有 ffprobe 也只会校验 wav，不存在这个问题
            if ($hasFfmpeg) {
                return '';
            }

            if ('WAV' === $format) {
                return '';
            }

            return '【本站也没有 ffmpeg】样本是 ' . ('' === $format ? '未归一化的音频' : $format)
                . ' 原样推的，而 TTS 主机只能校验 wav。两条路任选一条：'
                . '① 在本站装 ffmpeg 后重新上传（会自动转成 24kHz 单声道 WAV，最省事）；'
                . '② 在 TTS 主机装 ffmpeg 后点「重新推送」。';
        }

        /**
         * 按 cid 取回文章的标题与清洗后正文
         *
         * 异步 provider 的 poll() 只拿得到任务行（没有正文），
         * 而分块是「按正文决定」的 —— 所以每次轮询都要把正文读回来重算一遍。
         * 分块是确定性的（同样的正文 + 同样的 chunkChars → 同样的块），
         * 因此不需要把分块结果存进数据库，也就不必改表结构。
         *
         * @param int $cid
         * @return array|null {cid, title, text, source}
         */
        public static function postForTts($cid)
        {
            $cid = intval($cid);

            if ($cid <= 0) {
                return null;
            }

            try {
                $db  = ttsv_db();
                $row = $db->fetchRow(
                    $db->select('title', 'text')->from('table.contents')
                        ->where('cid = ?', $cid)
                        ->limit(1)
                );
            } catch (Throwable $e) {
                return null;
            }

            if (!is_array($row) || empty($row)) {
                return null;
            }

            $source = (string) $row['text'];

            return array(
                'cid'    => $cid,
                'title'  => self::cleanTitle(isset($row['title']) ? $row['title'] : ''),
                'text'   => self::cleanText($source),
                'source' => $source,
            );
        }

        /**
         * 列出所有「已生成音频」的任务
         *
         * @return array cid => row
         */
        public static function readyJobs()
        {
            $result = array();

            try {
                $db   = ttsv_db();
                $rows = $db->fetchAll(
                    $db->select()->from(self::table())->where('status = ?', 'ready')
                );

                foreach ((array) $rows as $row) {
                    $row                         = self::normalize($row);
                    $result[intval($row['cid'])] = $row;
                }
            } catch (Throwable $e) {
                // 忽略
            }

            return $result;
        }

        /**
         * 判断某条任务是不是「音色已过期」
         *
         * 只有用参考音频克隆出来的音频才谈得上过期：
         *   1. 任务已 ready 且真的产出了音频文件
         *   2. 记录的音色是 ref:xxxxxxxx —— 也就是克隆音色
         *      （历史数据里这个字段存的是预置音色名，靠前缀区分，不会误判）
         *   3. 而这个指纹和当前参考音频对不上
         *
         * 换了音色样本之后，这些音频听起来还是「上一个人的声音」，
         * 所以标记出来让用户自己决定重生成哪些 —— 不自动重跑，
         * 否则换一次音色就会把整站任务排满。
         *
         * @param array $job
         * @return bool
         */
        public static function jobVoiceStale($job)
        {
            if (!is_array($job)) {
                return false;
            }

            if ('ready' !== (string) (isset($job['status']) ? $job['status'] : '')) {
                return false;
            }

            if ('' === (string) (isset($job['audio']) ? $job['audio'] : '')) {
                return false;
            }

            $used = (string) (isset($job['voice']) ? $job['voice'] : '');

            // 现在只有克隆：voice 标签恒为样本指纹。
            if (0 !== strpos($used, 'ref:')) {
                return false;
            }

            return $used !== self::refVoiceTag();
        }

        /**
         * 统计「音色已过期」的任务数
         *
         * @return int
         */
        public static function staleJobCount()
        {
            $count = 0;

            foreach (self::readyJobs() as $job) {
                if (self::jobVoiceStale($job)) {
                    $count++;
                }
            }

            return $count;
        }

        /**
         * 上传错误码的中文说明
         *
         * @param int $code
         * @return string
         */
        public static function uploadErrorText($code)
        {
            switch (intval($code)) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    return '文件超过服务器允许的上传大小（检查 php.ini 的 upload_max_filesize / post_max_size）';
                case UPLOAD_ERR_PARTIAL:
                    return '文件只上传了一部分，请重试';
                case UPLOAD_ERR_NO_FILE:
                    return '没有选择文件';
                case UPLOAD_ERR_NO_TMP_DIR:
                    return '服务器缺少临时目录';
                case UPLOAD_ERR_CANT_WRITE:
                    return '服务器写入磁盘失败';
                case UPLOAD_ERR_EXTENSION:
                    return '被 PHP 扩展拦截';
            }

            return '错误码 ' . intval($code);
        }

        /* ============================================================== */
        /* 数据表                                                          */
        /* ============================================================== */
        /**
         * 表名
         *
         * @return string
         */
        public static function table()
        {
            return 'table.tts_jobs';
        }

        /**
         * 建表
         *
         * 注意：MySQL 的 text 字段不允许有默认值，所以 chapters / error
         * 这两列不能写 DEFAULT。
         *
         * @return bool
         */
        public static function installTable()
        {
            $db     = ttsv_db();
            $prefix = $db->getPrefix();
            $driver = self::driver();

            if ('sqlite' === $driver) {
                $sql = array(
                    "CREATE TABLE IF NOT EXISTS {$prefix}tts_jobs (
                        cid INTEGER PRIMARY KEY,
                        job_id TEXT NOT NULL DEFAULT '',
                        status TEXT NOT NULL DEFAULT 'none',
                        title TEXT NOT NULL DEFAULT '',
                        voice TEXT NOT NULL DEFAULT '',
                        speed TEXT NOT NULL DEFAULT '',
                        progress TEXT NOT NULL DEFAULT '',
                        duration TEXT NOT NULL DEFAULT '',
                        size INTEGER NOT NULL DEFAULT 0,
                        chapters TEXT,
                        error TEXT,
                        audio TEXT NOT NULL DEFAULT '',
                        created INTEGER NOT NULL DEFAULT 0,
                        updated INTEGER NOT NULL DEFAULT 0
                    )",
                    "CREATE INDEX IF NOT EXISTS idx_tts_jobs_status ON {$prefix}tts_jobs (status)",
                );
            } elseif ('pgsql' === $driver) {
                $sql = array(
                    "CREATE TABLE IF NOT EXISTS {$prefix}tts_jobs (
                        cid INTEGER PRIMARY KEY,
                        job_id VARCHAR(64) NOT NULL DEFAULT '',
                        status VARCHAR(16) NOT NULL DEFAULT 'none',
                        title VARCHAR(255) NOT NULL DEFAULT '',
                        voice VARCHAR(64) NOT NULL DEFAULT '',
                        speed VARCHAR(8) NOT NULL DEFAULT '',
                        progress VARCHAR(16) NOT NULL DEFAULT '',
                        duration VARCHAR(16) NOT NULL DEFAULT '',
                        size INTEGER NOT NULL DEFAULT 0,
                        chapters TEXT,
                        error TEXT,
                        audio VARCHAR(255) NOT NULL DEFAULT '',
                        created INTEGER NOT NULL DEFAULT 0,
                        updated INTEGER NOT NULL DEFAULT 0
                    )",
                    "CREATE INDEX IF NOT EXISTS idx_tts_jobs_status ON {$prefix}tts_jobs (status)",
                );
            } else {
                $charset = defined('__TYPECHO_DB_CHARSET__') ? __TYPECHO_DB_CHARSET__ : 'utf8mb4';
                $collate = 'utf8mb4_general_ci';

                if (false !== stripos($charset, 'utf8mb4')) {
                    $collate = 'utf8mb4_general_ci';
                } elseif (false !== stripos($charset, 'utf8')) {
                    $collate = 'utf8_general_ci';
                } else {
                    $collate = '';
                }

                $collateSql = '' === $collate ? '' : " COLLATE={$collate}";

                $sql = array(
                    "CREATE TABLE IF NOT EXISTS `{$prefix}tts_jobs` (
                        `cid` int(10) unsigned NOT NULL,
                        `job_id` varchar(64) NOT NULL DEFAULT '',
                        `status` varchar(16) NOT NULL DEFAULT 'none',
                        `title` varchar(255) NOT NULL DEFAULT '',
                        `voice` varchar(64) NOT NULL DEFAULT '',
                        `speed` varchar(8) NOT NULL DEFAULT '',
                        `progress` varchar(16) NOT NULL DEFAULT '',
                        `duration` varchar(16) NOT NULL DEFAULT '',
                        `size` int(10) unsigned NOT NULL DEFAULT 0,
                        `chapters` text,
                        `error` text,
                        `audio` varchar(255) NOT NULL DEFAULT '',
                        `created` int(10) unsigned NOT NULL DEFAULT 0,
                        `updated` int(10) unsigned NOT NULL DEFAULT 0,
                        PRIMARY KEY (`cid`),
                        KEY `idx_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET={$charset}{$collateSql}",
                );
            }

            foreach ($sql as $query) {
                $db->query($query, TTSV_DB_WRITE);
            }

            return true;
        }

        /**
         * 删表
         *
         * @return bool
         */
        public static function uninstallTable()
        {
            $db     = ttsv_db();
            $prefix = $db->getPrefix();

            $db->query("DROP TABLE IF EXISTS {$prefix}tts_jobs", TTSV_DB_WRITE);

            return true;
        }

        /**
         * 数据库驱动名
         *
         * @return string mysql|sqlite|pgsql
         */
        public static function driver()
        {
            try {
                $db      = ttsv_db();
                $adapter = get_class($db->getAdapter());

                if (false !== stripos($adapter, 'SQLite')) {
                    return 'sqlite';
                }

                if (false !== stripos($adapter, 'Pgsql') || false !== stripos($adapter, 'Pdo_Pgsql')) {
                    return 'pgsql';
                }
            } catch (Throwable $e) {
                // 忽略
            }

            return 'mysql';
        }

        /* ============================================================== */
        /* 数据访问                                                        */
        /* ============================================================== */

        /**
         * 读取一条任务
         *
         * @param int $cid
         * @return array|null
         */
        public static function job($cid)
        {
            $cid = intval($cid);

            if ($cid <= 0) {
                return NULL;
            }

            try {
                $db = ttsv_db();
                $row = $db->fetchRow(
                    $db->select()->from(self::table())->where('cid = ?', $cid)->limit(1)
                );

                return is_array($row) && !empty($row) ? self::normalize($row) : NULL;
            } catch (Throwable $e) {
                return NULL;
            }
        }

        /**
         * 批量读取任务
         *
         * @param array $cids
         * @return array cid => row
         */
        public static function jobs($cids)
        {
            $cids = array_values(array_filter(array_map('intval', (array) $cids)));

            if (empty($cids)) {
                return array();
            }

            $result = array();

            try {
                $db   = ttsv_db();
                $mark = implode(',', $cids);
                $rows = $db->fetchAll(
                    $db->select()->from(self::table())->where("cid IN ({$mark})")
                );

                foreach ((array) $rows as $row) {
                    $row = self::normalize($row);
                    $result[intval($row['cid'])] = $row;
                }
            } catch (Throwable $e) {
                // 忽略
            }

            return $result;
        }

        /**
         * 写入 / 更新任务
         *
         * @param int   $cid
         * @param array $data
         * @return bool
         */
        public static function saveJob($cid, $data)
        {
            $cid  = intval($cid);
            $data = self::filterFields($data);

            if ($cid <= 0 || empty($data)) {
                return false;
            }

            $data['updated'] = time();

            try {
                $db     = ttsv_db();
                $exists = self::job($cid);

                if ($exists) {
                    $db->query($db->update(self::table())->rows($data)->where('cid = ?', $cid));
                } else {
                    $data['cid']     = $cid;
                    $data['created'] = time();
                    $db->query($db->insert(self::table())->rows($data));
                }

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 删除任务记录
         *
         * @param int $cid
         * @return bool
         */
        public static function deleteJob($cid)
        {
            $cid = intval($cid);

            if ($cid <= 0) {
                return false;
            }

            try {
                $db = ttsv_db();
                $db->query($db->delete(self::table())->where('cid = ?', $cid));

                return true;
            } catch (Throwable $e) {
                return false;
            }
        }

        /**
         * 全部任务（按更新时间倒序）
         *
         * @param int $limit
         * @return array
         */
        public static function allJobs($limit = 100)
        {
            try {
                $db   = ttsv_db();
                $rows = $db->fetchAll(
                    $db->select()->from(self::table())
                        ->order('updated', TTSV_SORT_DESC)
                        ->limit(max(1, intval($limit)))
                );

                $result = array();

                foreach ((array) $rows as $row) {
                    $result[] = self::normalize($row);
                }

                return $result;
            } catch (Throwable $e) {
                return array();
            }
        }

        /**
         * 一批文章的**前台固定链接**（cid => url）
         *
         * 面板列表里「标题」应该点开原文，而不是音频文件。逐条走 Widget 太重，
         * 这里一次查回 slug/type，再用 Typecho 自己的路由拼 —— 站点改了固定链接
         * 形式（`routingTable`）也会跟着变，不用在这个插件里另写一套规则。
         *
         * @param array $cids
         * @return array 查不到的 cid 不会出现在结果里
         */
        public static function permalinks(array $cids)
        {
            $cids = array_values(array_unique(array_filter(array_map('intval', $cids))));

            if (empty($cids)) {
                return array();
            }

            try {
                $db   = ttsv_db();
                $rows = $db->fetchAll(
                    $db->select('cid', 'slug', 'type')
                        ->from('table.contents')
                        ->where('cid IN ?', $cids)
                        ->where('status = ?', 'publish')
                );
            } catch (Throwable $e) {
                return array();
            }

            $prefix = Helper::options()->siteUrl;
            $result = array();

            foreach ((array) $rows as $row) {
                $route = ('page' === $row['type']) ? 'page' : 'post';
                $url   = Typecho\Router::url($route, array('slug' => (string) $row['slug']), $prefix);

                if ('' !== $url && '#' !== $url) {
                    $result[intval($row['cid'])] = $url;
                }
            }

            return $result;
        }

        /**
         * 字段白名单
         *
         * @param array $data
         * @return array
         */
        protected static function filterFields($data)
        {
            $allow  = array('job_id', 'status', 'title', 'voice', 'speed', 'progress', 'duration', 'size', 'chapters', 'error', 'audio');
            $result = array();

            foreach ($allow as $key) {
                if (!array_key_exists($key, $data)) {
                    continue;
                }

                $value = $data[$key];

                if (is_array($value)) {
                    $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                $result[$key] = (string) $value;
            }

            return $result;
        }

        /**
         * 规范化一行数据
         *
         * @param array $row
         * @return array
         */
        protected static function normalize($row)
        {
            $row['cid']      = intval($row['cid']);
            $row['size']     = intval($row['size']);
            $row['created']  = intval($row['created']);
            $row['updated']  = intval($row['updated']);
            $row['chapters'] = self::decodeJson(isset($row['chapters']) ? $row['chapters'] : '');

            return $row;
        }

        /**
         * 解码 JSON
         *
         * @param mixed $value
         * @return array
         */
        public static function decodeJson($value)
        {
            if (is_array($value)) {
                return $value;
            }

            if (NULL === $value || '' === $value || !is_string($value)) {
                return array();
            }

            $data = json_decode($value, true);

            return is_array($data) ? $data : array();
        }

        /* ============================================================== */
        /* 前台：播放器                                                    */
        /* ============================================================== */

        /**
         * 【主题调用】取一份播放器数据包
         *
         * 这是给主题自定义渲染用的核心方法。一次调用拿到
         * 音频信息、章节列表，以及现成的播放器 HTML 片段。
         *
         * 三种用法：
         *   1. 什么都不做 —— 自动插入（默认）
         *   2. renderPlayer() —— 手动决定插入位置，用内置结构
         *   3. player() —— 拿数据自己写 HTML
         *
         * @param int   $cid   文章 ID，默认取当前文章
         * @param array $opts  css => false 不输出自带样式
         * @return array
         *   ready     是否可播放（只有为 true 时才该渲染播放器）
         *   cid       文章 ID
         *   audioUrl  音频地址
         *   chapters  章节数组 [{title, start}]
         *   duration  时长（秒）
         *   size      文件字节数
         *   html      现成的播放器 HTML（含 audio 标签）
         *   css       样式 link 片段
         *   js        脚本片段（必须输出一次，否则交互失效）
         */
        public static function player($cid = 0, $opts = array())
        {
            $opts = is_array($opts) ? $opts : array();
            $cid  = intval($cid);

            if ($cid <= 0) {
                $cid = self::currentCid();
            }

            $empty = array(
                'ready'    => false,
                'cid'      => $cid,
                'audioUrl' => '',
                'chapters' => array(),
                'duration' => 0,
                'size'     => 0,
                'html'     => '',
                'css'      => '',
                'js'       => '',
            );

            if ($cid <= 0) {
                return $empty;
            }

            $job = self::job($cid);

            if (!$job || 'ready' !== $job['status'] || '' === $job['audio']) {
                return $empty;
            }

            // 音频文件可能被手动清理掉了
            $absPath = rtrim(__TYPECHO_ROOT_DIR__, '/') . '/' . ltrim($job['audio'], '/');

            if (!file_exists($absPath)) {
                return $empty;
            }

            $chapters = ('1' === self::opt('showChapters', '1')) ? $job['chapters'] : array();
            $withCss  = !array_key_exists('css', $opts) || $opts['css'];

            return array(
                'ready'    => true,
                'cid'      => $cid,
                'audioUrl' => self::audioUrl($job['audio']),
                'chapters' => $chapters,
                'duration' => floatval($job['duration']),
                'size'     => intval($job['size']),
                'html'     => self::playerHtml($job, $chapters),
                'css'      => $withCss
                    ? '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl('player.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n"
                    : '',
                'js'       => '<script src="' . htmlspecialchars(self::assetUrl('player.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . "\n",
            );
        }

        /**
         * 【主题调用】输出播放器（用内置结构）
         *
         * 在主题模板里手动调用时可以决定插入位置，例如放在文章标题下方。
         * 会自动跳过「未生成 / 失败 / 音频缺失」的情况。
         *
         * @param array $opts css => false 则不加载自带样式
         * @return void
         */
        public static function renderPlayer($opts = array())
        {
            // 同一次请求内只输出一次。
            // 历史上「主题手动调用 + footer 钩子自动插入」同时开启会把播放器渲染两遍，
            // 产生重复 id（player.js 只会绑定第一个），这里做一次幂等保护。
            static $rendered = false;

            if ($rendered) {
                return;
            }

            $opts = is_array($opts) ? $opts : array();
            $data = self::player(0, $opts);

            if (!$data['ready']) {
                return;
            }

            $rendered = true;

            echo $data['css'];
            echo $data['html'];

            // 自动插入模式下，若配置了目标 id，则把播放器移动到该位置
            if (!empty($opts['relocate'])) {
                echo self::relocateScript();
            }

            echo $data['js'];
        }

        /**
         * 「自动插入位置」的搬运脚本
         *
         * 服务端无法知道主题 DOM 里目标元素的位置（footer 钩子渲染时页面早已输出完毕），
         * 所以先照常输出在页脚，再用一小段 JS 把节点搬到目标 id 内部/前后。
         * 目标 id 不存在时什么都不做 —— 播放器就留在页脚，内容不会丢。
         *
         * 同时注册到主题的 PageInit（若存在），使 PJAX 之后也能重新定位。
         *
         * @return string
         */
        public static function relocateScript()
        {
            $target = self::normalizeTargetId(self::opt('targetId', ''));

            if ('' === $target) {
                return '';
            }

            $id   = $target;
            $mode = 'append';

            if (preg_match('/^(.+?):(append|prepend|before|after)$/i', $target, $m)) {
                $id   = $m[1];
                $mode = strtolower($m[2]);
            }

            // 把「搬运」写成一段可复用的函数，初次加载与 PJAX 后各调用一次
            $js = '(function(){'
                . 'var T=' . json_encode($id) . ',M=' . json_encode($mode) . ';'
                . 'function move(){'
                . 'var el=document.getElementById("ttsv-player");'
                . 'if(!el)return;'
                . 'var host=document.getElementById(T);'
                . 'if(!host)return;'
                . 'if(host!==el&&host.contains(el))return;'
                . 'if(M==="before"){if(host.parentNode)host.parentNode.insertBefore(el,host);}'
                . 'else if(M==="after"){if(host.parentNode)host.parentNode.insertBefore(el,host.nextSibling);}'
                . 'else if(M==="prepend"){host.insertBefore(el,host.firstChild);}'
                . 'else{host.appendChild(el);}'
                . '}'
                . 'move();'
                . 'if(window.GardenWalk&&window.GardenWalk.registerInit){'
                . 'window.GardenWalk.registerInit("ttsvRelocate",move);'
                . '}'
                . 'window.addEventListener("pjax:ready",move);'
                . '})();';

            return '<script>' . $js . '</script>' . "\n";
        }

        /**
         * 生成播放器 HTML
         *
         * renderPlayer() 与 player() 共用这一段，
         * 保证「手动调用」与「拿数据自己拼」两条路径的结构完全一致。
         *
         * @param array $job
         * @param array $chapters
         * @return string
         */
        protected static function playerHtml($job, $chapters)
        {
            $url      = self::audioUrl($job['audio']);
            $download = '1' === self::opt('showDownload', '1');
            $esc      = function ($value) {
                return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            };

            $html = '<div class="ttsv-player" id="ttsv-player" data-cid="' . intval($job['cid']) . '">' . "\n";

            $html .= '<div class="ttsv-bar">' . "\n";
            $html .= '<button type="button" class="ttsv-btn ttsv-play" id="ttsv-play" aria-label="播放">'
                . '<span class="ttsv-icon-play">&#9654;</span><span class="ttsv-icon-pause">&#10073;&#10073;</span>'
                . '</button>' . "\n";

            $html .= '<div class="ttsv-track" id="ttsv-track">'
                . '<div class="ttsv-loaded" id="ttsv-loaded"></div>'
                . '<div class="ttsv-played" id="ttsv-played"></div>'
                . '<div class="ttsv-thumb" id="ttsv-thumb"></div>'
                . '</div>' . "\n";

            $html .= '<span class="ttsv-time"><span id="ttsv-current">0:00</span> / <span id="ttsv-total">0:00</span></span>' . "\n";

            $html .= '<button type="button" class="ttsv-btn ttsv-rate" id="ttsv-rate" title="播放速度">1.0&times;</button>' . "\n";

            $html .= '<button type="button" class="ttsv-btn ttsv-mute" id="ttsv-mute" aria-label="静音">'
                . '<span class="ttsv-icon-vol">&#128266;</span><span class="ttsv-icon-muted">&#128263;</span>'
                . '</button>' . "\n";

            if ($download) {
                $html .= '<a class="ttsv-btn ttsv-download" href="' . $esc($url) . '" download title="下载音频">&#8681;</a>' . "\n";
            }

            $html .= '</div>' . "\n";

            if (!empty($chapters)) {
                $html .= '<ul class="ttsv-chapters" id="ttsv-chapters">' . "\n";

                foreach ($chapters as $chapter) {
                    $title = isset($chapter['title']) ? $chapter['title'] : '';
                    $start = isset($chapter['start']) ? (float) $chapter['start'] : 0;

                    if ('' === $title) {
                        continue;
                    }

                    $html .= '<li class="ttsv-chapter">'
                        . '<button type="button" class="ttsv-chapter-btn" data-start="' . $esc($start) . '">'
                        . $esc($title)
                        . '</button></li>' . "\n";
                }

                $html .= '</ul>' . "\n";
            }

            $html .= '<audio id="ttsv-audio" preload="metadata" src="' . $esc($url) . '"></audio>' . "\n";
            $html .= '</div>' . "\n";

            return $html;
        }

        /**
         * 当前文章 ID（不在内容页时返回 0）
         *
         * @return int
         */
        public static function currentCid()
        {
            try {
                $archive = Typecho_Widget::widget('Widget_Archive');
            } catch (Throwable $e) {
                return 0;
            }

            if (!$archive || empty($archive->cid)) {
                return 0;
            }

            // 只在允许的内容类型上生效
            $scope = self::opt('scope', 'post');
            $allow = array_filter(array_map('trim', explode(',', (string) $scope)));

            if (empty($allow)) {
                $allow = array('post');
            }

            foreach ($allow as $type) {
                if ($archive->is($type)) {
                    return intval($archive->cid);
                }
            }

            return 0;
        }

        /**
         * 页脚钩子：自动插入播放器
         *
         * 挂在 Widget_Archive::footer 上，由主题 footer.php 里的
         * $this->footer() 触发。若设为「手动调用」则不挂载。
         *
         * @return void
         */
        public static function renderPlayerAuto()
        {
            if (!self::boolOpt('autoInsert', true)) {
                return;
            }

            self::renderPlayer(array('relocate' => true));
        }

        /* ============================================================== */
        /* 后台：按钮与资源                                                */
        /* ============================================================== */

        /**
         * 编辑页：在「选项」区插入生成按钮
         *
         * 挂在 admin/write-post.php::option 上。
         *
         * @param mixed $post 文章对象，个别版本可能不传
         * @return void
         */
        public static function renderEditorBox($post = NULL)
        {
            $cid = 0;

            if (is_object($post) && isset($post->cid)) {
                $cid = intval($post->cid);
            } elseif (isset($_GET['cid'])) {
                $cid = intval($_GET['cid']);
            }

            $job = $cid > 0 ? self::job($cid) : NULL;
            $status = $job ? $job['status'] : 'none';
            $audioUrl = ($job && '' !== $job['audio']) ? self::audioUrl($job['audio']) : '';

            echo '<section class="ttsv-editor-box" id="ttsv-editor-box">';
            echo '<h4>朗读音频</h4>';

            // 四个圆钮横向平均分布：生成 / 删除 / 播放 / 状态
            // 前三个是 <button>，第四个是等外形的状态圆钮（不可点击，
            // 颜色 + 呼吸动画表示进度），不再另外显示状态文字。
            //
            // 气泡提示用**真实子元素** .ttsv-tip，而不是 ::after：
            //   · 伪元素 ::before / ::after 已经被图标占用，没法再画气泡；
            //   · 伪元素绝对定位时包含块就是 30×30 的按钮本身，
            //     max-width 会被限制成 30px，几个字也会被折成好几行。
            echo '<div class="ttsv-editor-line">';

            echo '<button type="button" class="ttsv-ibtn ttsv-ibtn-gen" id="ttsv-generate" data-cid="' . $cid . '"'
                . ' data-tip="生成朗读音频" aria-label="生成朗读音频">'
                . '<span class="ttsv-tip">生成朗读音频</span></button>';

            echo '<button type="button" class="ttsv-ibtn ttsv-ibtn-del" id="ttsv-remove" data-cid="' . $cid . '"'
                . ' data-tip="删除音频" aria-label="删除音频">'
                . '<span class="ttsv-tip">删除音频</span></button>';

            $playTip = ('ready' === $status) ? '播放' : '暂无音频';

            echo '<button type="button" class="ttsv-ibtn ttsv-ibtn-play" id="ttsv-play-now" data-cid="' . $cid . '"'
                . ' data-audio="' . htmlspecialchars($audioUrl, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-tip="' . $playTip . '"'
                . ('ready' === $status ? '' : ' disabled')
                . ' aria-label="' . $playTip . '">'
                . '<span class="ttsv-tip">' . $playTip . '</span></button>';

            echo '<span class="ttsv-ibtn ttsv-ibtn-status" id="ttsv-dot"'
                . ' role="img"'
                . ' data-status="' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '"'
                . ' data-tip="' . htmlspecialchars(self::statusText($status), ENT_QUOTES, 'UTF-8') . '"'
                . ' aria-label="' . htmlspecialchars(self::statusText($status), ENT_QUOTES, 'UTF-8') . '">'
                . '<span class="ttsv-dot-sr" id="ttsv-status">'
                . htmlspecialchars(self::statusText($status), ENT_QUOTES, 'UTF-8') . '</span>'
                . '<span class="ttsv-tip">' . htmlspecialchars(self::statusText($status), ENT_QUOTES, 'UTF-8') . '</span>'
                . '</span>';

            echo '</div>';

            echo '<p class="ttsv-editor-tip">生成过程在 NAS 上完成，页面会自动显示进度，无需刷新。</p>';
            echo '</section>';

            // 把 cid 与接口地址注入全局，供 admin.js 使用
            echo '<script>window.__TTS_ADMIN__ = window.__TTS_ADMIN__ || {};'
                . 'window.__TTS_ADMIN__.cid = ' . $cid . ';'
                . 'window.__TTS_ADMIN__.action = ' . json_encode(self::actionUrl(''), JSON_UNESCAPED_SLASHES) . ';'
                . '</script>';
        }

        /**
         * 后台页脚：注入 JS / CSS
         *
         * 【重要】编辑页也会加载 footer，所以这里必须用「合并」而非覆盖，
         * 否则会冲掉编辑页写入的 cid（见交接文档 §5.7）。
         *
         * @return void
         */
        public static function renderAdminAssets()
        {
            $token = '';

            try {
                $token = self::csrfToken();
            } catch (Throwable $e) {
                // 拿不到 token 时留空，服务端会拒绝写操作
            }

            echo '<link rel="stylesheet" href="' . htmlspecialchars(self::assetUrl('admin.css'), ENT_QUOTES, 'UTF-8') . '">' . "\n";

            // 默认值在前、已有值在后，避免覆盖 renderEditorBox() 写入的 cid
            echo '<script>window.__TTS_ADMIN__ = Object.assign('
                . json_encode(array(
                    'action' => self::actionUrl(''),
                    'cid'    => 0,
                    // 【1.6.8】音色样本可选（服务端有默认音色），所以这里恒为 true ——
                    // 面板的「生成」按钮不再因为没有样本而被禁用。
                    'hasSample' => true,
                    'sampleUploaded' => self::hasCloneVoice(),
                    'needSample' => self::needSampleMessage(),
                ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                . ', window.__TTS_ADMIN__ || {});'
                . 'window.__TTS_ADMIN__.token = ' . json_encode($token) . ';'
                . '</script>' . "\n";

            echo '<script src="' . htmlspecialchars(self::assetUrl('admin.js'), ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
        }

        /**
         * 状态文案
         *
         * @param string $status
         * @return string
         */
        public static function statusText($status)
        {
            $map = array(
                'none'    => '未生成',
                'queued'  => '排队中',
                'running' => '合成中',
                'done'    => '合成完成，下载中',
                'ready'   => '已就绪',
                'failed'  => '失败',
            );

            return isset($map[$status]) ? $map[$status] : $status;
        }

        /* ============================================================== */
        /* 文本清洗：把 Markdown / HTML / 短代码还原成「适合朗读的纯文本」   */
        /* ============================================================== */

        /**
         * 清洗文章原文，产出适合 TTS 朗读的纯文本
         *
         * 送入 NAS 的必须是「能被读出来的人类语言」。原文里混着 Markdown 标记、
         * HTML、主题短代码、古文注音标记与 LaTeX 公式，直接朗读会念出一堆符号。
         *
         * 处理顺序是刻意的，不能随意调换：
         *   1. 代码块先剥 —— 否则代码里的 $ 会被当成公式
         *   2. 公式在去 Markdown 行内标记之前处理 —— 公式里常含 _ * \ 等字符
         *   3. 短代码在 Markdown 之前处理 —— gallery 内部是媒体语法，无朗读价值
         *
         * @param string $text 数据库里的原始 text 列
         * @return string
         */
        public static function cleanText($text)
        {
            $text = (string) $text;

            if ('' === $text) {
                return '';
            }

            // 0. BOM / 换行归一
            $text = str_replace(array("\xEF\xBB\xBF", "\r\n", "\r"), array('', "\n", "\n"), $text);

            // 1. 剥 Typecho 的 Markdown 哨兵（原始 text 列带这个前缀）
            $text = preg_replace('/^\s*<!--markdown-->\s*/', '', $text);

            // 2. 去代码（必须早于公式处理）
            $text = preg_replace('/^[ \t]{0,3}~~~[^\n]*\n.*?\n[ \t]{0,3}~~~[ \t]*$/ms', "\n", $text);
            $text = preg_replace('/^[ \t]{0,3}```[^\n]*\n.*?\n[ \t]{0,3}```[ \t]*$/ms', "\n", $text);
            $text = preg_replace('/<pre\b[^>]*>.*?<\/pre>/is', "\n", $text);
            $text = preg_replace('/<code\b[^>]*>.*?<\/code>/is', ' ', $text);
            $text = preg_replace('/`{1,2}[^`\n]+`{1,2}/u', ' ', $text);
            $text = preg_replace('/^(?: {4}|\t).*$/m', '', $text);

            // 3. 短代码
            //    整块丢弃（内部是媒体语法，朗读无意义）
            $text = preg_replace('/\[gallery\b[^\]]*\].*?\[\/gallery\]/is', "\n", $text);
            $text = preg_replace('/\[player\b[^\]]*\/?\]/i', "\n", $text);
            //    只丢标签、保留内部文字（这些短代码包的是正文）
            $text = preg_replace(
                '/\[\/?(?:box|collapse|tabs|tab|ancient|video|audio)\b[^\]]*\]/i',
                "\n",
                $text
            );
            //    兜底：其余未知短代码标签一律丢弃（与主题 excerpt 的处理保持一致）
            $text = preg_replace('/\[\/?[a-z][a-z0-9_-]*(?:\s[^\]]*)?\]/i', ' ', $text);

            // 4. 古文阅读器注音标记：【字】（拼音｜注释）→ 只留字词
            $text = preg_replace('/【([^】]*)】(?:[（(][^）)]*[）)])?/u', '$1', $text);

            // 5. 公式 → 读作「公式」
            //    用 $b 拼正则，避免多层反斜杠转义出错
            $b = '\\';
            $mathPatterns = array(
                '/\$\$.+?\$\$/s',                                                    // $$...$$（块级）
                '/\$(?!\s)(?:[^\$\n]{1,200}?)(?<!\s)\$/u',                            // $...$（行内）
                '/' . $b . $b . '\[.+?' . $b . $b . '\]/s',                           // \[...\]
                '/' . $b . $b . '\(.+?' . $b . $b . '\)/s',                           // \(...\)
                '/' . $b . $b . 'begin\{(?:equation|align|eqnarray|gather|multline)\*?\}'
                    . '.+?' . $b . $b . 'end\{(?:equation|align|eqnarray|gather|multline)\*?\}/s',
            );

            foreach ($mathPatterns as $pattern) {
                $text = preg_replace($pattern, '公式', $text);
            }

            // 6. HTML → 文本
            $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
            $text = preg_replace(
                '/<\/(?:p|div|li|tr|h[1-6]|blockquote|section|article|figure|figcaption|dd|dt|td|th)>/i',
                "\n",
                $text
            );
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            // 7. Markdown 行内标记
            $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $text);        // 图片
            $text = preg_replace('/!\[[^\]]*\]\[[^\]]*\]/u', ' ', $text);       // 引用式图片
            $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);      // 行内链接 → 保留文字
            $text = preg_replace('/\[([^\]]*)\]\[[^\]]*\]/u', '$1', $text);     // 引用式链接
            $text = preg_replace('/^[ \t]{0,3}\[[^\]]+\]:[ \t]*\S+.*$/m', '', $text);  // 引用定义
            $text = preg_replace('/\[\^[^\]]*\]/u', '', $text);                 // 脚注引用
            $text = preg_replace('/^[ \t]{0,3}#{1,6}[ \t]*/m', '', $text);      // 标题
            $text = preg_replace('/^[ \t]{0,3}>[ \t]?/m', '', $text);           // 引用
            $text = preg_replace('/^[ \t]{0,3}(?:[-*+]|\d+\.)[ \t]+/m', '', $text);  // 列表
            $text = preg_replace('/^[ \t]{0,3}(?:[-*_][ \t]*){3,}$/m', '', $text);   // 水平线
            $text = preg_replace('/^[ \t]{0,3}\|?[ \t:|-]+\|[ \t:|-]*$/m', '', $text); // 表格分隔行
            $text = str_replace('|', ' ', $text);                               // 表格竖线
            $text = preg_replace('/(\*\*|__|~~)(.*?)\1/su', '$2', $text);       // 粗体 / 删除线
            $text = preg_replace('/(?<![\w*])\*(?!\s)([^*\n]+?)(?<!\s)\*(?![\w*])/u', '$1', $text);
            $text = preg_replace('/(?<![\w_])_(?!\s)([^_\n]+?)(?<!\s)_(?![\w_])/u', '$1', $text);
            $text = str_replace(array('**', '__', '~~'), '', $text);            // 残留标记

            // 8. 空白整理
            $text = preg_replace('/[ \t]+/u', ' ', $text);
            $text = preg_replace('/ *\n */u', "\n", $text);
            $text = preg_replace('/\n{3,}/u', "\n\n", $text);

            return trim($text);
        }

        /**
         * 标题清洗（比正文轻，保留标点与括号）
         *
         * 核心把 title 存成 htmlspecialchars 过的值，这里解码后交给 NAS。
         *
         * @param string $title
         * @return string
         */
        public static function cleanTitle($title)
        {
            $title = html_entity_decode(strip_tags((string) $title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = preg_replace('/\s+/u', ' ', $title);

            return trim($title);
        }
    }
}

namespace TypechoPlugin\TtsVoice {
    if (!class_exists('TypechoPlugin\TtsVoice\Plugin', false)) {
        /**
         * Typecho 1.3+ 只认这个命名空间下的 Plugin 类
         */
        class Plugin extends \TtsVoice_Plugin_Base
        {
        }
    }
}

namespace {
    if (!class_exists('TtsVoice_Plugin', false)) {
        /**
         * Typecho 1.2.x 只认 目录名_Plugin 这个类名
         */
        class TtsVoice_Plugin extends \TtsVoice_Plugin_Base
        {
        }
    }

    /**
     * 自愈：确保 options 表里有 plugin:TtsVoice 这一行
     *
     * 核心渲染设置页时会先 require 本文件、再调用 Options->plugin()，
     * 所以这一步正好赶在报错之前完成。
     */
    try {
        TtsVoice_Plugin_Base::writeDefaultConfig();
    } catch (Throwable $e) {
        // 静默失败，不能因为自愈逻辑影响页面
    }
}
