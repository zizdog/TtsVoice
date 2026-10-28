/*
 * TtsVoice - 后台脚本
 *
 * 两件事：
 *   1. 编辑页：给「生成 / 删除」按钮绑事件，轮询单篇状态
 *   2. 列表页：Typecho 没有「增加列」的钩子，所以这里用 JS 动态注入状态列
 *
 * 依赖 window.__TTS_ADMIN__（由 PHP 在编辑页和 footer 两处合并注入）。
 */
(function () {
    'use strict';

    var cfg = window.__TTS_ADMIN__ || {};
    var ACTION = cfg.action || '';
    var TOKEN = cfg.token || '';

    if (!ACTION) {
        return;
    }

    var POLL_MS = 3000;
    var pending = {};   // cid -> true，正在轮询的任务
    var failedSeen = {}; // cid -> true，失败提示只弹一次
    var timer = null;

    /* ---------------- 工具 ---------------- */

    function post(action, data, callback) {
        var body = '';

        for (var key in data) {
            if (!Object.prototype.hasOwnProperty.call(data, key)) {
                continue;
            }

            if (body !== '') {
                body += '&';
            }

            body += encodeURIComponent(key) + '=' + encodeURIComponent(data[key]);
        }

        // CSRF token
        if (body !== '') {
            body += '&';
        }

        body += '_token=' + encodeURIComponent(TOKEN);

        var xhr = new XMLHttpRequest();

        xhr.open('POST', ACTION + action, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        var done = false;

        function finish(res) {
            if (done) {
                return;
            }

            done = true;
            callback(res || {});
        }

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

            finish(res);
        };

        /* 网络层失败必须回调：否则轮询的 inFlight 永远是 true，进度就再也不动了 */
        xhr.onerror = function () { finish({ code: -1, msg: '网络请求失败（连接中断）' }); };
        xhr.ontimeout = function () { finish({ code: -1, msg: '网络请求超时' }); };
        xhr.onabort = function () { finish({ code: -1, msg: '网络请求已取消' }); };

        xhr.send(body);
    }

    function get(action, params, callback) {
        var query = '';

        for (var key in params) {
            if (!Object.prototype.hasOwnProperty.call(params, key)) {
                continue;
            }

            if (query !== '') {
                query += '&';
            }

            query += encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
        }

        // CSRF token：status / batch 会触发 NAS 同步与音频下载，属于状态变更
        if (query !== '') {
            query += '&';
        }

        query += '_token=' + encodeURIComponent(TOKEN);

        var xhr = new XMLHttpRequest();

        xhr.open('GET', ACTION + action + '?' + query, true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

        var done = false;

        function finish(res) {
            if (done) {
                return;
            }

            done = true;
            callback(res || {});
        }

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

            finish(res);
        };

        /* 网络层失败必须回调：否则轮询的 inFlight 永远是 true，进度就再也不动了 */
        xhr.onerror = function () { finish({ code: -1, msg: '网络请求失败（连接中断）' }); };
        xhr.ontimeout = function () { finish({ code: -1, msg: '网络请求超时' }); };
        xhr.onabort = function () { finish({ code: -1, msg: '网络请求已取消' }); };

        xhr.send(null);
    }

    /**
     * 提示条 —— **直接复用 Typecho 后台原生的顶部提示**，不自己写样式
     *
     * 原生实现在 admin/common-js.php，它把这样一段插到 .typecho-head-nav 之后：
     *
     *   <div class="message popup success|notice|error"><ul><li>消息</li></ul></div>
     *
     * 样式与动画全部来自后台自带的 admin/css/style.css（.message / .popup /
     * .success / .notice / .error）与 jQuery UI（effect('highlight')）：
     * slideDown → 高亮一闪 → 停留 → fadeOut。
     *
     * 这样观感与后台其它提示完全一致，且**不需要任何自定义 CSS**
     * （以前自己写的那套「浅底卡片」和更早的「深色横条」都已删除）。
     *
     * @param {string}  msg
     * @param {boolean} isError
     * @param {boolean} isOk
     */
    function toast(msg, isError, isOk) {
        var $ = window.jQuery;

        if (!$) {
            // jQuery 没加载时（理论上不会发生）退化为最朴素的方式
            window.alert(msg);
            return;
        }

        var type = isError ? 'error' : (isOk ? 'success' : 'notice');

        // 先清掉上一条同类提示，避免连续操作刷屏
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
            var color = 'error' === type ? '#FBC2C4' : ('notice' === type ? '#FFD324' : '#C6D880');

            // 错误多留一会儿（原生是 5 秒），其余沿用原生节奏
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

    function statusText(status, job) {
        var map = {
            'none': '未生成',
            'queued': '排队中',
            'running': '合成中',
            'done': '下载中',
            'ready': '已就绪',
            'failed': '失败'
        };

        var text = map[status] || status || '未生成';

        if (job && job.progress && ('running' === status || 'done' === status)) {
            text += ' ' + job.progress;
        }

        return text;
    }

    /* ---------------- 后台试听 ---------------- */

    /**
     * 底部试听条（全局单例）
     *
     * 列表页每行都可能有多个音频，用单例避免同时播放多个。
     * 用原生 <audio controls> —— 后台环境样式复杂，自绘播放器容易冲突，
     * 原生控件最稳，且自带进度、音量、倍速。
     */
    var audition = null;
    var currentPlayBtn = null;

    function getAudition() {
        if (audition && audition.parentNode) {
            return audition;
        }

        var box = document.createElement('div');
        box.className = 'ttsv-audition';
        box.id = 'ttsv-audition';

        var title = document.createElement('span');
        title.className = 'ttsv-audition-title';

        var audio = document.createElement('audio');
        audio.controls = true;
        audio.preload = 'none';

        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'ttsv-audition-close';
        close.title = '关闭';
        close.innerHTML = '&times;';

        box.appendChild(title);
        box.appendChild(audio);
        box.appendChild(close);

        document.body.appendChild(box);
        audition = box;

        close.addEventListener('click', function () {
            stopAudition();
        });

        // 播放结束 / 停止时清掉按钮高亮
        audio.addEventListener('ended', function () {
            clearPlayBtn();
        });
        audio.addEventListener('pause', function () {
            clearPlayBtn();
        });

        return box;
    }

    function clearPlayBtn() {
        if (currentPlayBtn) {
            currentPlayBtn.classList.remove('is-playing');
            currentPlayBtn.setAttribute('title', '试听');
            currentPlayBtn = null;
        }
    }

    function stopAudition() {
        var box = audition;

        if (!box || !box.parentNode) {
            return;
        }

        var audio = box.querySelector('audio');

        if (audio) {
            audio.pause();
        }

        box.classList.remove('is-open');
        clearPlayBtn();
    }

    /**
     * 播放指定音频
     *
     * @param {string} url
     * @param {string} title
     * @param {HTMLElement} btn 触发按钮，用于切换三角/暂停图标
     */
    function playAudio(url, title, btn) {
        if (!url) {
            toast('这篇还没有音频，请先生成', true);
            return;
        }

        var box = getAudition();
        var audio = box.querySelector('audio');
        var titleEl = box.querySelector('.ttsv-audition-title');

        // 点的是同一个且正在播：暂停
        if (audio.src === url && !audio.paused) {
            audio.pause();
            return;
        }

        clearPlayBtn();

        titleEl.textContent = title || '试听';
        audio.src = url;
        box.classList.add('is-open');

        var p = audio.play();

        // 浏览器可能拒绝自动播放
        if (p && typeof p.catch === 'function') {
            p.catch(function () {
                toast('浏览器拦截了自动播放，请点播放器上的播放键', true);
            });
        }

        if (btn) {
            btn.classList.add('is-playing');
            currentPlayBtn = btn;
        }
    }

    /**
     * 试听按钮事件委托
     *
     * 【重要】必须只认 .ttsv-play-tri 这个独立类名。
     * 早期版本试听按钮沿用了 .ttsv-col-btn，而生成/删除的事件委托也是按
     * .ttsv-col-btn 抓的，两边撞车 —— 点试听会被当成 gen/regen 再次提交合成，
     * 状态从「已就绪」变回「排队中」。
     * 这里用独立类名 + stopPropagation 彻底隔离两条链路。
     */
    document.addEventListener('click', function (event) {
        var btn = event.target.closest ? event.target.closest('.ttsv-play-tri') : null;

        if (!btn || btn.classList.contains('is-off')) {
            return;
        }

        // 阻止冒泡，避免被 table 上 gen/del 的委托再处理一次
        event.preventDefault();
        event.stopPropagation();

        playAudio(btn.getAttribute('data-audio'), btn.getAttribute('data-title'), btn);
    }, true);   // 捕获阶段，先于 table 的委托执行

    /* ---------------- 编辑页 ---------------- */

    /**
     * 统一更新一个圆钮的 hover 气泡
     *
     * 气泡是真实子元素 .ttsv-tip（见 Plugin::renderEditorBox 的注释：
     * 伪元素被图标占用，且伪元素绝对定位会被 30px 的按钮压窄导致折行）。
     *
     * @param {HTMLElement} el
     * @param {string}      text
     */
    function setTip(el, text) {
        if (!el) {
            return;
        }

        el.setAttribute('data-tip', text);
        el.setAttribute('aria-label', text);

        var tip = el.querySelector('.ttsv-tip');

        if (tip) {
            tip.textContent = text;
        }
    }

    function bindEditor() {
        var genBtn = document.getElementById('ttsv-generate');
        var delBtn = document.getElementById('ttsv-remove');

        var cid = cfg.cid || (genBtn ? parseInt(genBtn.getAttribute('data-cid'), 10) : 0);

        if (!cid) {
            return;
        }

        var playBtn = document.getElementById('ttsv-play-now');
        // 第 4 个圆钮 = 状态指示（外形与按钮一致），#ttsv-status 是它内部的
        // 无障碍文字（视觉隐藏），不再有单独的状态文字标签。
        var dotEl = document.getElementById('ttsv-dot');
        var srEl = document.getElementById('ttsv-status');

        function setStatus(status, job) {
            var text = statusText(status, job);
            var tip = text;

            // 失败时把错误原因放进 hover 提示
            if ('failed' === status && job && job.error) {
                tip += '：' + job.error;
            }

            if (dotEl) {
                dotEl.setAttribute('data-status', status);
                setTip(dotEl, tip);
            }

            if (srEl) {
                srEl.textContent = tip;
            }

            // 就绪后启用播放按钮（用 disabled，不隐藏，避免布局跳动）
            if (playBtn) {
                if ('ready' === status && job && job.audioUrl) {
                    playBtn.setAttribute('data-audio', job.audioUrl);
                    playBtn.disabled = false;
                    setTip(playBtn, '播放');
                } else {
                    playBtn.setAttribute('data-audio', '');
                    playBtn.disabled = true;
                    setTip(playBtn, '暂无音频');
                }
            }
        }

        if (playBtn) {
            playBtn.addEventListener('click', function () {
                if (playBtn.disabled) {
                    return;
                }

                playAudio(playBtn.getAttribute('data-audio'), '本篇朗读', playBtn);
            });
        }

        if (genBtn) {
            genBtn.addEventListener('click', function () {
                // 【不要往按钮里塞文字】它是 30×30 的圆形图标按钮，
                // textContent 会把图标盖住、文字还会溢出圆形。
                // 忙状态用 .is-busy 的呼吸动画表示，文字放 hover 气泡。
                genBtn.disabled = true;
                genBtn.classList.add('is-busy');
                setTip(genBtn, '提交中…');

                post('submit', { cid: cid }, function (res) {
                    genBtn.disabled = false;
                    genBtn.classList.remove('is-busy');
                    setTip(genBtn, '生成朗读音频');

                    if (0 !== res.code) {
                        toast(res.msg || '提交失败', true);
                        return;
                    }

                    toast('已提交，正在合成', false, true);
                    setStatus('queued');
                    startPolling();
                });
            });
        }

        if (delBtn) {
            delBtn.addEventListener('click', function () {
                if (!window.confirm('确定删除这篇的朗读音频？')) {
                    return;
                }

                post('delete', { cid: cid }, function (res) {
                    if (0 !== res.code) {
                        toast(res.msg || '删除失败', true);
                        return;
                    }

                    toast('已删除');
                    setStatus('none');
                });
            });
        }

        // 编辑页的单篇轮询
        window.__TTS_EDITOR_SYNC__ = function (job) {
            if (job) {
                setStatus(job.status, job);
            }
        };

        startPolling();
    }

    /* ---------------- 列表页 ---------------- */

    /**
     * 当前页是不是文章管理页
     *
     * 后台多个页面都用同构的列表表格，只有文章管理页该注入音频列。
     *
     * @return {boolean}
     */
    function isManagePosts() {
        var path = (window.location.pathname || '');

        // 常规：/admin/manage-posts.php
        if (path.indexOf('manage-posts.php') !== -1) {
            return true;
        }

        // 伪静态后台：/admin/manage-posts 或带分页
        if (/\/manage-posts(\/|$|\?)/.test(path)) {
            return true;
        }

        // 兜底：URL 里带 panel 的一律不是（那是扩展面板，如本插件的朗读页）
        return false;
    }

    function bindList() {
        var table = document.querySelector('table.typecho-list-table');

        if (!table) {
            return;
        }

        // 【重要】必须只认文章管理页。
        // 后台所有列表（插件管理、评论、分类…）都用 table.typecho-list-table，
        // 早期版本见 table 就注入，结果插件管理页、本插件自己的朗读面板
        // 都被加了「音频/播放」两个空列。
        //
        // 三重判定，任一不满足就退出：
        //   1. 表格不能是本插件面板自己的（PHP 已渲染好播放列）
        //   2. 页面必须是 manage-posts.php
        //   3. 表里确实有 input[name="cid[]"]
        if (table.classList.contains('ttsv-panel-table')) {
            return;
        }

        if (!isManagePosts()) {
            return;
        }

        var hasPost = table.querySelector('tbody tr input[name="cid[]"]');

        if (!hasPost) {
            return;
        }

        var head = table.querySelector('thead tr');

        // 文章管理页加三列：
        //   「音频」列 —— 状态圆点（绿=已就绪 灰=未生成 蓝=进行中 红=失败）
        //   「播放」列 —— 三角播放按钮
        //   「生成」列 —— 生成 / 重新生成（直接在这里提交，不用进编辑页）
        //
        // 注意：加表头必须放在确认有文章行之后，
        // 否则没有文章时也会多出几个空表头。
        if (head && !head.querySelector('.ttsv-head-audio')) {
            // 给表格打标记，CSS 才能针对性地修列宽
            table.classList.add('ttsv-injected');

            var thAudio = document.createElement('th');
            thAudio.className = 'ttsv-head-audio';
            thAudio.textContent = '音频';
            head.appendChild(thAudio);

            var thPlay = document.createElement('th');
            thPlay.className = 'ttsv-head-play';
            thPlay.textContent = '播放';
            head.appendChild(thPlay);

            var thGen = document.createElement('th');
            thGen.className = 'ttsv-head-gen';
            thGen.textContent = '生成';
            head.appendChild(thGen);
        }

        var rows = table.querySelectorAll('tbody tr');
        var found = false;

        for (var i = 0; i < rows.length; i++) {
            var row = rows[i];
            var input = row.querySelector('input[name="cid[]"]');

            if (!input) {
                continue;
            }

            var cid = parseInt(input.value, 10);

            if (!cid) {
                continue;
            }

            if (row.querySelector('.ttsv-audio-cell') || row.querySelector('.ttsv-play-cell')) {
                continue;
            }

            // 音频列：状态圆点
            var tdAudio = document.createElement('td');
            tdAudio.className = 'ttsv-audio-cell';
            tdAudio.setAttribute('data-cid', String(cid));
            tdAudio.innerHTML =
                '<span class="ttsv-dot" data-status="none" data-tip="未生成">' +
                '<span class="ttsv-dot-sr">未生成</span></span>';

            // 播放列：三角按钮
            var tdPlay = document.createElement('td');
            tdPlay.className = 'ttsv-play-cell';
            tdPlay.setAttribute('data-cid', String(cid));
            tdPlay.innerHTML =
                '<button type="button" class="ttsv-play-tri is-off" data-act="play"' +
                ' data-cid="' + cid + '" data-audio="" data-title=""' +
                ' title="暂无音频" aria-label="暂无音频"></button>';

            // 生成列：没有音色样本时直接禁用（插件只支持自定义音色，点了也必然失败）
            var tdGen = document.createElement('td');
            tdGen.className = 'ttsv-gen-cell';
            tdGen.setAttribute('data-cid', String(cid));

            var genBtn = document.createElement('button');
            genBtn.type = 'button';
            genBtn.className = 'ttsv-col-btn';
            genBtn.setAttribute('data-act', 'gen');
            genBtn.setAttribute('data-cid', String(cid));
            genBtn.textContent = '生成';

            /*
             * 【不要在这里 disabled】
             * 之前没有样本就把按钮禁掉，结果用户点下去**一点反馈都没有**，
             * 看起来就是「按钮坏了」。改成：按钮照常可点，点了立刻用 toast
             * 告诉用户为什么不行（下面 gen 分支里判断）。
             */
            if (false === cfg.hasSample) {
                genBtn.setAttribute('data-need-sample', '1');
                genBtn.setAttribute('title', cfg.needSample || '请先在插件设置里上传音色样本');
            }

            tdGen.appendChild(genBtn);

            row.appendChild(tdAudio);
            row.appendChild(tdPlay);
            row.appendChild(tdGen);
            found = true;
        }

        if (!found) {
            return;
        }

        /*
         * 【必须用**捕获阶段 + document**，不能挂在 table 上】
         *
         * 文章列表的行被 Typecho 的 `jQuery.fn.tableSelectable` 绑过点击：
         *     .click(function (e) {
         *         目标 tagName 属于 ["input","textarea","a","button","i"]
         *         且不是 checkbox  →  e.stopPropagation();
         *         否则            →  切换该行的勾选框
         *     })
         * 而我们的「生成」正是一个 <button> —— 冒泡到 table 之前就被 stopPropagation()
         * 掐断了，**table 上的委托永远收不到事件**。
         *
         * 症状：按钮看得见、点了毫无反应（连请求都不发，nginx 日志里没有 POST /ttsvoice/submit）。
         * 试听用的 .ttsv-play-tri 之所以没事，就是因为它当初就注册在 document 的捕获阶段。
         *
         * 所以这里照做：document + capture。左侧的 .ttsv-play-tri 处理函数会先跑，
         * 它只对播放按钮 stopPropagation，同一个节点上的监听器不受影响。
         */
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

            if ('gen' === act) {
                // 没有音色样本：不动网络，直接把原因说清楚（比静默禁用友好）
                if (false === cfg.hasSample || btn.getAttribute('data-need-sample')) {
                    toast(cfg.needSample || '请先在插件设置里上传音色样本', true);
                    return;
                }

                btn.disabled = true;
                btn.textContent = '提交中…';

                post('submit', { cid: cid }, function (res) {
                    btn.disabled = false;

                    if (0 !== res.code) {
                        btn.textContent = '生成';
                        toast(res.msg || '提交失败', true);
                        return;
                    }

                    // 【不要改 data-act】以前这里改成 'regen'，下一次点击就再也匹配不上
                    // 下面的 'gen' 分支了 —— 按钮点一次就"死"。只改文案。
                    btn.textContent = '生成中…';
                    toast('已提交，正在合成');

                    // 作废提交前发出的那一拍，避免旧响应把状态覆盖回去 / 停掉轮询
                    pollSeq++;
                    inFlight = false;
                    startPolling();
                });
            } else if ('del' === act) {
                if (!window.confirm('确定删除这篇的朗读音频？')) {
                    return;
                }

                post('delete', { cid: cid }, function (res) {
                    if (0 !== res.code) {
                        toast(res.msg || '删除失败', true);
                        return;
                    }

                    toast('已删除');
                    renderListJob(cid, null);
                });
            }
        }, true);   // ★ 捕获阶段：先于 tableSelectable 的行点击处理

        startPolling();
    }

    function renderListJob(cid, job) {
        // 文章管理页注入三个单元格：音频（圆点）+ 播放（三角）+ 生成（按钮）
        var audioCell = document.querySelector('.ttsv-audio-cell[data-cid="' + cid + '"]');
        var playCell = document.querySelector('.ttsv-play-cell[data-cid="' + cid + '"]');
        var genCell = document.querySelector('.ttsv-gen-cell[data-cid="' + cid + '"]');

        if (!audioCell && !playCell && !genCell) {
            return;
        }

        var dot = audioCell ? audioCell.querySelector('.ttsv-dot') : null;
        var playBtn = playCell ? playCell.querySelector('.ttsv-play-tri') : null;

        var status = job ? job.status : 'none';

        // 就绪后启用三角按钮
        if (playBtn) {
            if ('ready' === status && job && job.audioUrl) {
                playBtn.setAttribute('data-audio', job.audioUrl);
                playBtn.setAttribute('data-title', job.title || '');
                playBtn.setAttribute('title', '播放');
                playBtn.setAttribute('aria-label', '播放');
                playBtn.classList.remove('is-off');
            } else {
                playBtn.setAttribute('data-audio', '');
                playBtn.setAttribute('title', '暂无音频');
                playBtn.setAttribute('aria-label', '暂无音频');
                playBtn.classList.add('is-off');
            }
        }

        if (dot) {
            var tip = statusText(status, job);

            if ('failed' === status && job && job.error) {
                tip += '：' + job.error;
            }

            if ('ready' === status && job && job.duration) {
                tip += '　' + Math.round(parseFloat(job.duration) || 0) + ' 秒';
            }

            dot.setAttribute('data-status', status);
            dot.setAttribute('data-tip', tip);
            dot.setAttribute('aria-label', tip);

            var sr = dot.querySelector('.ttsv-dot-sr');

            if (sr) {
                sr.textContent = tip;
            }
        }

        // 失败必须**主动**说一声：之前只有圆点变红、原因藏在 hover 气泡里，
        // 用户看到的就是「点了没反应、也不报错」。每个 cid 只提示一次。
        if ('failed' === status) {
            if (!failedSeen[cid]) {
                failedSeen[cid] = true;
                toast('合成失败：' + ((job && job.error) ? job.error : '未知错误'), true);
            }
        } else {
            delete failedSeen[cid];
        }

        // 生成按钮：按状态换文案 / 禁用
        if (genCell) {
            var gen = genCell.querySelector('.ttsv-col-btn');

            if (gen) {
                if (false === cfg.hasSample) {
                    // 保持可点：点了会弹提示说明原因
                    gen.disabled = false;
                    gen.textContent = '生成';
                } else if ('queued' === status || 'running' === status || 'done' === status) {
                    // 正在跑：禁用，避免重复提交（同一篇重复提交会被服务端按 client_id 复用）
                    gen.disabled = true;
                    gen.textContent = '生成中…';
                } else {
                    gen.disabled = false;
                    gen.textContent = ('ready' === status) ? '重新生成' : '生成';
                }
            }
        }
    }

    /* ---------------- 轮询 ---------------- */

    /*
     * 【并发闸门】
     *
     * 异步合成（自建 Qwen3-TTS）的每次轮询要「跑一块」，服务端耗时几十秒。
     * 而轮询间隔只有 3 秒 —— 用 setInterval 会让请求不断堆积，变成十几个请求
     * 同时在合成，互相抢 GPU、内存飙升。
     *
     * 所以：同一时刻只允许一个轮询在飞，上一次回来之后才发下一次。
     */
    var inFlight = false;

    /*
     * 轮询序号：提交/手动刷新时 +1。
     * 旧请求（提交前发出的那一拍）回来时序号已经变了，直接丢弃 ——
     * 否则它可能带着「ready」把刚置的 queued 覆盖回去，并把轮询 stop 掉。
     */
    var pollSeq = 0;

    function startPolling() {
        if (timer) {
            return;
        }

        timer = setInterval(poll, POLL_MS);
        poll();
    }

    function stopPolling() {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }

    function poll() {
        // 上一个还没回来就跳过这一拍，避免请求堆积
        if (inFlight) {
            return;
        }

        inFlight = true;

        var mySeq = pollSeq;

        // 编辑页：单篇
        if (cfg.cid && typeof window.__TTS_EDITOR_SYNC__ === 'function') {
            get('status', { cid: cfg.cid, _: Date.now() }, function (res) {
                inFlight = false;

                if (mySeq !== pollSeq) {
                    return;   // 过期响应
                }

                if (-1 === res.code) {
                    stopPolling();
                    toast(res.msg || '网络请求失败，已停止自动刷新', true);

                    return;
                }

                if (0 !== res.code || !res.data) {
                    if (403 === res.code) {
                        stopPolling();
                        toast('登录状态已失效，请刷新页面', true);
                    }

                    return;
                }

                window.__TTS_EDITOR_SYNC__(res.data.job);

                var status = res.data.status;

                if ('ready' === status || 'failed' === status || 'none' === status) {
                    stopPolling();
                }
            });

            return;
        }

        // 列表页：批量
        var tds = document.querySelectorAll('.ttsv-audio-cell');
        var cids = [];

        for (var i = 0; i < tds.length; i++) {
            var cid = parseInt(tds[i].getAttribute('data-cid'), 10);

            if (cid) {
                cids.push(cid);
            }
        }

        if (!cids.length) {
            stopPolling();
            return;
        }

        get('batch', { cids: cids.join(','), _: Date.now() }, function (res) {
            inFlight = false;

            if (mySeq !== pollSeq) {
                return;   // 过期响应
            }

            if (-1 === res.code) {
                stopPolling();
                toast(res.msg || '网络请求失败，已停止自动刷新', true);

                return;
            }

            if (0 !== res.code || !res.data || !res.data.jobs) {
                if (403 === res.code) {
                    stopPolling();
                    toast('登录状态已失效，请刷新页面', true);
                }

                return;
            }

            var jobs = res.data.jobs;
            var active = false;

            for (var cid in jobs) {
                if (!Object.prototype.hasOwnProperty.call(jobs, cid)) {
                    continue;
                }

                renderListJob(parseInt(cid, 10), jobs[cid]);

                var status = jobs[cid] ? jobs[cid].status : 'none';

                if ('queued' === status || 'running' === status || 'done' === status) {
                    active = true;
                }
            }

            if (!active) {
                stopPolling();
            }
        });
    }

    /* ---------------- 设置页：音色样本上传 ---------------- */
    /*
     * 配置表单本身没有文件上传控件，所以「音色」那一块是自绘的（见 Plugin::voicePickerHtml）。
     * 这里绑定它的上传/删除。复用 __TTS_ADMIN__ 里的 action 与 CSRF token。
     *
     * 上传走 multipart，不能复用上面的 get()（那个只发 urlencoded）。
     * 上传成功后整页刷新：样本信息、指纹、「音色已过期」标记都要重算。
     */
    function bindVoicePicker() {
        var box = document.getElementById('ttsv-vp');

        if (!box) {
            return;
        }

        var fileInput = document.getElementById('ttsv-vp-file');
        var uploadBtn = document.getElementById('ttsv-vp-upload');
        var clearBtn  = document.getElementById('ttsv-vp-clear');
        var msgBox    = document.getElementById('ttsv-vp-msg');
        var audio     = document.getElementById('ttsv-vp-audio');

        function say(text, isError) {
            if (msgBox) {
                msgBox.textContent = text || '';
                msgBox.style.color = isError ? '#c0392b' : '#3a7ca5';
            }
        }

        // 试听：样本存在点开头的 .voice 目录里，直链会被 nginx 拦掉，走带鉴权的接口
        if (audio) {
            audio.src = ACTION + 'voice?_token=' + encodeURIComponent(TOKEN);
        }

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function () {
                if (!fileInput.files || !fileInput.files.length) {
                    say('请先选择音频文件', true);
                    return;
                }

                var fd = new FormData();
                fd.append('voice', fileInput.files[0]);
                fd.append('_token', TOKEN);

                uploadBtn.disabled = true;
                say('上传并处理中…');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', ACTION + 'uploadvoice', true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onreadystatechange = function () {
                    if (4 !== xhr.readyState) {
                        return;
                    }

                    uploadBtn.disabled = false;

                    var res = null;
                    try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

                    if (res && 0 === res.code) {
                        say(res.msg || '已更新，正在刷新…');
                        window.setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        say((res && res.msg) ? res.msg : '上传失败', true);
                    }
                };

                xhr.send(fd);
            });
        }

        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                if (!window.confirm('确定删除本站的音色样本？\n\n只删「网站本机」的样本文件（并清空已推送路径）。\nTTS 主机的默认音色、以及其他站点的样本都不会被动到。')) {
                    return;
                }

                var body = '_token=' + encodeURIComponent(TOKEN);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ACTION + 'clearvoice', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onreadystatechange = function () {
                    if (4 !== xhr.readyState) {
                        return;
                    }

                    var res = null;
                    try { res = JSON.parse(xhr.responseText); } catch (e) { res = null; }

                    if (res && 0 === res.code) {
                        say('已删除，正在刷新…');
                        window.setTimeout(function () { window.location.reload(); }, 700);
                    } else {
                        say((res && res.msg) ? res.msg : '删除失败', true);
                    }
                };

                xhr.send(body);
            });
        }
    }

    /* ---------------- 启动 ---------------- */

    bindEditor();
    bindList();
    bindVoicePicker();
})();
