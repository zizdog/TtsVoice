/*
 * TtsVoice - 前台播放器
 *
 * IIFE 包裹，var 为主，字符串用单引号。
 * 依赖 DOM 结构由 Plugin::renderPlayer() 输出。
 */
(function () {
    'use strict';

    var player = document.getElementById('ttsv-player');
    var audio = document.getElementById('ttsv-audio');

    if (!player || !audio) {
        return;
    }

    var playBtn = document.getElementById('ttsv-play');
    var track = document.getElementById('ttsv-track');
    var loaded = document.getElementById('ttsv-loaded');
    var played = document.getElementById('ttsv-played');
    var thumb = document.getElementById('ttsv-thumb');
    var currentEl = document.getElementById('ttsv-current');
    var totalEl = document.getElementById('ttsv-total');
    var rateBtn = document.getElementById('ttsv-rate');
    var muteBtn = document.getElementById('ttsv-mute');
    var chapterBtns = document.querySelectorAll('.ttsv-chapter-btn');

    var RATES = [1.0, 1.25, 1.5, 2.0, 0.75];
    var rateIndex = 0;
    var dragging = false;

    /* ---------------- 工具 ---------------- */

    function formatTime(sec) {
        if (!isFinite(sec) || sec < 0) {
            sec = 0;
        }

        var s = Math.floor(sec % 60);
        var m = Math.floor(sec / 60) % 60;
        var h = Math.floor(sec / 3600);

        if (h > 0) {
            return h + ':' + pad(m) + ':' + pad(s);
        }

        return m + ':' + pad(s);
    }

    function pad(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function setProgress(ratio) {
        if (ratio < 0) ratio = 0;
        if (ratio > 1) ratio = 1;

        var percent = (ratio * 100) + '%';

        if (played) played.style.width = percent;
        if (thumb) thumb.style.left = percent;
    }

    /* ---------------- 播放控制 ---------------- */

    function togglePlay() {
        if (audio.paused) {
            var p = audio.play();

            // 部分浏览器 play() 返回 Promise，被拦截时要吞掉错误
            if (p && typeof p['catch'] === 'function') {
                p['catch'](function () {});
            }
        } else {
            audio.pause();
        }
    }

    if (playBtn) {
        playBtn.addEventListener('click', togglePlay);
    }

    audio.addEventListener('play', function () {
        player.classList.add('is-playing');
    });

    audio.addEventListener('pause', function () {
        player.classList.remove('is-playing');
    });

    audio.addEventListener('ended', function () {
        player.classList.remove('is-playing');
        setProgress(0);
        audio.currentTime = 0;
    });

    /* ---------------- 进度 ---------------- */

    audio.addEventListener('timeupdate', function () {
        if (dragging || !audio.duration) {
            return;
        }

        setProgress(audio.currentTime / audio.duration);

        if (currentEl) {
            currentEl.textContent = formatTime(audio.currentTime);
        }

        highlightChapter(audio.currentTime);
    });

    audio.addEventListener('loadedmetadata', function () {
        if (totalEl) {
            totalEl.textContent = formatTime(audio.duration);
        }
    });

    audio.addEventListener('progress', function () {
        if (!audio.duration || !audio.buffered || !audio.buffered.length) {
            return;
        }

        var end = audio.buffered.end(audio.buffered.length - 1);

        if (loaded) {
            loaded.style.width = ((end / audio.duration) * 100) + '%';
        }
    });

    /* ---------------- 拖动进度条 ---------------- */

    function seekFromEvent(event) {
        if (!audio.duration) {
            return;
        }

        var rect = track.getBoundingClientRect();
        var x = event.clientX - rect.left;
        var ratio = x / rect.width;

        if (ratio < 0) ratio = 0;
        if (ratio > 1) ratio = 1;

        setProgress(ratio);
        audio.currentTime = ratio * audio.duration;

        if (currentEl) {
            currentEl.textContent = formatTime(audio.currentTime);
        }
    }

    if (track) {
        track.addEventListener('mousedown', function (event) {
            dragging = true;
            seekFromEvent(event);
        });

        track.addEventListener('mousemove', function (event) {
            if (dragging) {
                seekFromEvent(event);
            }
        });
    }

    document.addEventListener('mouseup', function () {
        dragging = false;
    });

    // 触摸支持
    if (track) {
        track.addEventListener('touchstart', function (event) {
            dragging = true;
            seekFromEvent(event.touches[0]);
        });

        track.addEventListener('touchmove', function (event) {
            if (dragging) {
                seekFromEvent(event.touches[0]);
            }
        });

        track.addEventListener('touchend', function () {
            dragging = false;
        });
    }

    /* ---------------- 倍速 ---------------- */

    if (rateBtn) {
        rateBtn.addEventListener('click', function () {
            rateIndex = (rateIndex + 1) % RATES.length;
            audio.playbackRate = RATES[rateIndex];
            rateBtn.innerHTML = RATES[rateIndex].toFixed(2).replace(/0$/, '') + '&times;';
        });
    }

    /* ---------------- 静音 ---------------- */

    if (muteBtn) {
        muteBtn.addEventListener('click', function () {
            audio.muted = !audio.muted;
            player.classList.toggle('is-muted', audio.muted);
        });
    }

    /* ---------------- 章节 ---------------- */

    function highlightChapter(time) {
        if (!chapterBtns.length) {
            return;
        }

        var current = null;

        for (var i = 0; i < chapterBtns.length; i++) {
            var start = parseFloat(chapterBtns[i].getAttribute('data-start')) || 0;

            if (time + 0.25 >= start) {
                current = chapterBtns[i];
            }
        }

        for (var j = 0; j < chapterBtns.length; j++) {
            chapterBtns[j].classList.toggle('is-current', chapterBtns[j] === current);
        }
    }

    for (var i = 0; i < chapterBtns.length; i++) {
        chapterBtns[i].addEventListener('click', function () {
            var start = parseFloat(this.getAttribute('data-start')) || 0;

            audio.currentTime = start;
            highlightChapter(start);

            if (audio.paused) {
                var p = audio.play();

                if (p && typeof p['catch'] === 'function') {
                    p['catch'](function () {});
                }
            }
        });
    }

    /* ---------------- 快捷键：空格播放/暂停 ---------------- */

    document.addEventListener('keydown', function (event) {
        // 输入态不拦截
        var tag = (event.target && event.target.tagName || '').toLowerCase();

        if ('input' === tag || 'textarea' === tag || event.target.isContentEditable) {
            return;
        }

        if (' ' === event.key || 'Spacebar' === event.key) {
            event.preventDefault();
            togglePlay();
        }
    });
})();
