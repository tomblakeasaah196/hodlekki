// /assets/ui/js/pages/our-sermon.js
// Our sermons, PR S2: the listening page (modules/our_sermons/sermon.php).
//
//   * Our own player on an <audio> element (the feed's full MP3): play/pause,
//     15 s back/forward, a scrubber, 1× to 2×, a mini-bar that docks above the
//     phone's bottom bar once the player scrolls away, lock-screen controls
//     (Media Session) and keyboard shortcuts. The place is saved every 15 s
//     while playing, on pause and as the page goes away, so the next visit
//     carries on there.
//   * Watch: the YouTube video, created only when the member opens the tab,
//     from youtube-nocookie.com. Its time and seeking go through the player's
//     postMessage protocol (what YouTube's IFrame API uses underneath), so no
//     YouTube script ever runs in this page and script-src stays 'self'.
//   * The Bible panel: a side panel (a sheet on phones) that is NOT a dialog,
//     so the sermon keeps playing and its controls stay in reach. Chapters
//     come from the Bible module's own endpoint (/api/bible_api.php, GET
//     chapter); licensed text is reported to API.Bible's FUMS on each display,
//     as the Bible module does.
//   * Notes: private, each stamped with the moment, tap the time to hear it
//     again; references open the Bible panel; edit, delete, email.
//
// Motion is calm and only transform/opacity move; with reduced motion
// nothing slides. Every string put into the page goes through textContent or
// HOD.esc().
(function () {
    'use strict';

    var cfg = window.HOD && HOD.data ? HOD.data('osl-config') : null;
    var page = document.querySelector('[data-os-page]');
    if (!cfg || !page) return;

    var reduce = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var RATES = [1, 1.25, 1.5, 2];
    var SAVE_EVERY = 15000;
    // The YouTube player as its messages describe it (state 1 = playing).
    var yt = { frame: null, time: 0, duration: 0, state: -1, hello: 0 };
    var session = 'mediaSession' in navigator ? navigator.mediaSession : null;
    var lastPositionState = 0;

    // ------------------------------------------------------------------ helpers
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    function fmt(seconds) {
        var s = Math.max(0, Math.floor(seconds || 0));
        var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
        var mm = h > 0 && m < 10 ? '0' + m : String(m);
        return (h > 0 ? h + ':' : '') + mm + ':' + (r < 10 ? '0' : '') + r;
    }

    function stored(key, value) {
        try {
            if (value === undefined) return window.localStorage.getItem(key);
            window.localStorage.setItem(key, String(value));
        } catch (e) { /* private window: the choice simply is not kept */ }
        return null;
    }

    function post(action, data, opts) {
        return HOD.api(cfg.endpoint, Object.assign({ action: action, data: Object.assign({ sermon_id: cfg.id }, data || {}) }, opts || {}));
    }

    function icon(name) { return HOD.icon ? HOD.icon(name) : ''; }

    function say(text) {
        var el = $('[data-os-status]');
        if (el) el.textContent = text || '';
    }

    function typingIn(el) {
        return !!el && (el.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName));
    }

    // ------------------------------------------------------------------ media
    var audio = $('[data-os-audio]');
    var playerEl = $('[data-os-player]');
    var hasVideo = !!cfg.youtube;
    var source = audio ? 'audio' : (hasVideo ? 'video' : null);
    var everPlayed = false;
    var dragging = false;

    function duration() {
        if (source === 'video') return yt.duration || 0;
        var d = audio && isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
        return d || cfg.duration || 0;
    }

    function now() {
        if (source === 'video') return yt.time || 0;
        return audio ? audio.currentTime || 0 : 0;
    }

    function playing() {
        if (source === 'video') return yt.state === 1;
        return !!audio && !audio.paused && !audio.ended;
    }

    function toggle() {
        if (source === 'video') {
            if (!yt.frame) { startVideo(); return; }
            ytCommand(yt.state === 1 ? 'pauseVideo' : 'playVideo');
            return;
        }
        if (!audio) return;
        if (audio.paused) {
            var p = audio.play();
            if (p && p.catch) p.catch(function () { showError(true); });
        } else {
            audio.pause();
        }
    }

    function seekTo(seconds, andPlay) {
        var s = Math.max(0, seconds);
        var d = duration();
        if (d > 0) s = Math.min(s, Math.max(0, d - 0.5));
        if (source === 'video') {
            if (!yt.frame) { startVideo(s); return; }
            ytCommand('seekTo', [s, true]);
            yt.time = s;
            if (andPlay) ytCommand('playVideo');
            paint();
            return;
        }
        if (!audio) return;
        audio.currentTime = s;
        everPlayed = true;
        paint();
        if (andPlay && audio.paused) toggle();
    }

    function skip(delta) { seekTo(now() + delta, false); }

    function showError(on) {
        var el = $('[data-os-error]');
        if (el) el.hidden = !on;
    }

    // ------------------------------------------------------------------ painting
    var scrub = $('[data-os-scrub]');
    var track = $('[data-os-track]');
    var curEl = $('[data-os-cur]');
    var leftEl = $('[data-os-left]');
    var miniFill = $('[data-os-mini-fill]');
    var miniTime = $('[data-os-mini-time]');
    var painted = { cur: -1, d: -1 };
    var frame = 0;

    function paint() {
        if (frame) return;
        frame = window.requestAnimationFrame(function () {
            frame = 0;
            paintNow();
        });
    }

    function paintNow() {
        var d = duration();
        var t = dragging && scrub ? Number(scrub.value) : now();
        var whole = Math.floor(t);
        var p = d > 0 ? Math.min(1, t / d) : 0;
        if (source === 'audio') {
            if (track) track.style.setProperty('--os-p', String(p));
            if (scrub && !dragging) {
                if (d > 0 && Number(scrub.max) !== Math.floor(d)) scrub.max = String(Math.floor(d));
                scrub.value = String(whole);
            }
            if (whole !== painted.cur || Math.floor(d) !== painted.d) {
                if (curEl) curEl.textContent = fmt(t);
                if (leftEl) leftEl.textContent = d > 0 ? '-' + fmt(Math.max(0, d - whole)) : '';
                if (scrub) scrub.setAttribute('aria-valuetext', fmt(t) + (d > 0 ? ' of ' + fmt(d) : ''));
            }
        }
        if (miniFill) miniFill.style.setProperty('--os-p', String(p));
        if (miniTime && whole !== painted.cur) miniTime.textContent = fmt(t) + (d > 0 ? ' / ' + fmt(d) : '');
        if (Math.floor(d) !== painted.d) paintMarks();
        painted.cur = whole;
        painted.d = Math.floor(d);
        paintStamp();
        paintNowNote();
    }

    function setPlayingUi() {
        var on = playing();
        page.classList.toggle('is-playing', on);
        $$('[data-os-play]').forEach(function (b) {
            b.setAttribute('aria-label', on ? 'Pause' : 'Play');
            b.classList.toggle('is-on', on);
        });
        updateMini();
        updateMediaSession();
    }

    // ------------------------------------------------------------------ the audio element
    // A finished sermon was saved at 0, so it simply starts from the top.
    var resumeAt = Math.floor((cfg.progress && cfg.progress.position) || 0);
    var resumed = false;
    var saved = { pos: Math.floor((cfg.progress && cfg.progress.position) || 0), at: Date.now() };

    function applyResume() {
        if (resumed || !audio) return;
        resumed = true;
        var d = duration();
        if (resumeAt > 10 && (!d || resumeAt < d - 15)) {
            audio.currentTime = resumeAt;
            everPlayed = true;
            var line = $('[data-os-resume]');
            if (line) {
                $('[data-os-resume-at]', line).textContent = fmt(resumeAt);
                line.hidden = false;
            }
            paint();
        }
    }

    if (audio) {
        var rate = parseFloat(stored('hodOsSpeed') || '1');
        if (RATES.indexOf(rate) === -1) rate = 1;
        var setRate = function (r) {
            rate = r;
            audio.playbackRate = r;
            audio.defaultPlaybackRate = r;
            var b = $('[data-os-speed]');
            if (b) {
                b.textContent = String(r).replace(/^(\d)\.(\d)0?$/, '$1.$2') + '×';
                b.setAttribute('aria-label', 'Speed ' + b.textContent);
            }
            updateMediaSession();
        };
        setRate(rate);

        audio.addEventListener('loadedmetadata', function () {
            audio.playbackRate = rate;
            applyResume();
            paint();
        });
        audio.addEventListener('durationchange', paint);
        audio.addEventListener('timeupdate', function () {
            paint();
            if (playing() && Date.now() - saved.at >= SAVE_EVERY) saveProgress(false);
            positionState();
        });
        audio.addEventListener('play', function () {
            everPlayed = true;
            showError(false);
            var line = $('[data-os-resume]');
            if (line && audio.currentTime > resumeAt + 30) line.hidden = true;
            if (hasVideo && yt.frame) ytCommand('pauseVideo');
            setPlayingUi();
        });
        audio.addEventListener('pause', function () {
            setPlayingUi();
            if (!audio.ended) saveProgress(false);
        });
        audio.addEventListener('ended', function () {
            setPlayingUi();
            saveProgress(true);
            var heard = $('[data-os-heard]');
            if (heard) heard.hidden = false;
            say('Finished. Well done.');
        });
        audio.addEventListener('waiting', function () { say('Loading…'); });
        audio.addEventListener('playing', function () { say(''); });
        audio.addEventListener('error', function () { showError(true); say(''); });
        // With a <source> child the failure is reported on the source, not the element.
        var sourceEl = $('[data-os-source]', audio);
        if (sourceEl) sourceEl.addEventListener('error', function () { showError(true); say(''); });
        if (audio.readyState >= 1) applyResume();

        HOD.actions.on('os.speed', function () {
            setRate(RATES[(RATES.indexOf(rate) + 1) % RATES.length]);
            stored('hodOsSpeed', rate);
        });
        HOD.actions.on('os.restart', function (btn) {
            var line = btn.closest('[data-os-resume]');
            if (line) line.hidden = true;
            seekTo(0, false);
            var play = $('.osl-play');
            if (play) play.focus();
        });
    }

    if (scrub) {
        scrub.addEventListener('input', function () {
            dragging = true;
            paintNow();
        });
        scrub.addEventListener('change', function () {
            dragging = false;
            seekTo(Number(scrub.value), false);
        });
        scrub.addEventListener('blur', function () { dragging = false; });
    }

    HOD.actions.on('os.play', function () { toggle(); });
    HOD.actions.on('os.back', function () { skip(-15); });
    HOD.actions.on('os.fwd', function () { skip(15); });

    // ------------------------------------------------------------------ carry on where you stopped
    function progressData(finished) {
        return { sermon_id: cfg.id, position: finished ? 0 : Math.floor(audio.currentTime || 0), finished: finished ? '1' : '0' };
    }

    function saveProgress(finished) {
        if (!cfg.ready || !audio || !everPlayed) return;
        var pos = finished ? 0 : Math.floor(audio.currentTime || 0);
        saved.at = Date.now();
        if (!finished && Math.abs(pos - saved.pos) < 2) return;
        saved.pos = pos;
        HOD.api(cfg.endpoint, { action: 'progress', data: progressData(finished), quiet: true }).catch(function () { /* best effort */ });
    }

    // As the page goes away: keepalive, with the token in the body as well.
    function saveOnLeave() {
        if (!cfg.ready || !audio || !everPlayed) return;
        var pos = Math.floor(audio.currentTime || 0);
        if (Math.abs(pos - saved.pos) < 2) return;
        saved.pos = pos;
        var form = new FormData();
        var data = progressData(false);
        form.append('action', 'progress');
        Object.keys(data).forEach(function (k) { form.append(k, data[k]); });
        var token = HOD.csrf ? HOD.csrf() : '';
        form.append('_csrf', token);
        try {
            fetch(cfg.endpoint, { method: 'POST', body: form, credentials: 'same-origin', keepalive: true,
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' } });
        } catch (e) { /* the browser refused: the last save stands */ }
    }
    window.addEventListener('pagehide', saveOnLeave);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') saveOnLeave();
    });

    // ------------------------------------------------------------------ lock screen (Media Session)
    function updateMediaSession() {
        if (!session || source !== 'audio') return;
        try {
            if (!session.metadata && window.MediaMetadata) {
                session.metadata = new MediaMetadata({
                    title: cfg.title || 'Sermon',
                    artist: cfg.preacher || 'HOD Lekki',
                    album: 'Our sermons',
                    artwork: cfg.artwork ? [{ src: new URL(cfg.artwork, window.location.href).href }] : [],
                });
            }
            session.playbackState = playing() ? 'playing' : 'paused';
        } catch (e) { /* older browsers */ }
    }

    function positionState() {
        if (!session || !session.setPositionState || source !== 'audio' || Date.now() - lastPositionState < 1000) return;
        lastPositionState = Date.now();
        var d = duration();
        if (!(d > 0)) return;
        try {
            session.setPositionState({ duration: d, playbackRate: audio.playbackRate || 1, position: Math.min(d, audio.currentTime || 0) });
        } catch (e) { /* a position the browser will not take */ }
    }

    if (session && audio) {
        var handle = function (name, fn) { try { session.setActionHandler(name, fn); } catch (e) { /* not supported */ } };
        handle('play', function () { if (audio.paused) toggle(); });
        handle('pause', function () { if (!audio.paused) toggle(); });
        handle('seekbackward', function (d) { skip(-((d && d.seekOffset) || 15)); });
        handle('seekforward', function (d) { skip((d && d.seekOffset) || 15); });
        handle('seekto', function (d) { if (d && typeof d.seekTime === 'number') seekTo(d.seekTime, false); });
    }

    // ------------------------------------------------------------------ the mini-bar
    var dock = $('[data-os-dock]');
    var mini = $('[data-os-mini]');
    var playerInView = true;

    function updateMini() {
        if (!mini) return;
        var show = source === 'audio' && !playerInView && (playing() || everPlayed);
        if (show === mini.classList.contains('is-shown')) return;
        mini.classList.toggle('is-shown', show);
        if (show) mini.removeAttribute('inert');
        else mini.setAttribute('inert', '');
        if (dock) dock.classList.toggle('is-shown', show);
    }

    if (mini && playerEl && 'IntersectionObserver' in window) {
        new IntersectionObserver(function (entries) {
            playerInView = entries[0].isIntersecting;
            updateMini();
        }, { threshold: 0.15 }).observe(playerEl);
    }

    HOD.actions.on('os.top', function () {
        if (!playerEl) return;
        playerEl.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        var play = $('.osl-play', playerEl);
        if (play) play.focus({ preventScroll: true });
    });

    // ------------------------------------------------------------------ Watch (YouTube, postMessage only)
    function ytCommand(func, args) {
        if (!yt.frame || !yt.frame.contentWindow) return;
        yt.frame.contentWindow.postMessage(JSON.stringify({ event: 'command', func: func, args: args || [] }), cfg.youtubeOrigin);
    }

    function ytListen() {
        if (!yt.frame || !yt.frame.contentWindow) return;
        yt.frame.contentWindow.postMessage(JSON.stringify({ event: 'listening', id: 'osl-yt', channel: 'widget' }), cfg.youtubeOrigin);
    }

    function startVideo(at) {
        var wrap = $('[data-os-video]');
        if (!wrap || !hasVideo || yt.frame) return;
        var f = document.createElement('iframe');
        f.className = 'osl-video__frame';
        f.title = 'Sermon video';
        f.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
        f.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');
        f.src = cfg.youtubeOrigin + '/embed/' + encodeURIComponent(cfg.youtube) + '?enablejsapi=1&rel=0&modestbranding=1&playsinline=1&autoplay=1'
            + '&origin=' + encodeURIComponent(window.location.origin) + (at > 0 ? '&start=' + Math.floor(at) : '');
        f.addEventListener('load', function () {
            // The player answers once it hears "listening"; ask a few times while it boots.
            var tries = 0;
            clearInterval(yt.hello);
            ytListen();
            yt.hello = setInterval(function () {
                if (++tries > 20 || yt.state !== -1) { clearInterval(yt.hello); return; }
                ytListen();
            }, 250);
        });
        wrap.textContent = '';
        wrap.appendChild(f);
        wrap.classList.add('is-on');
        yt.frame = f;
    }

    window.addEventListener('message', function (e) {
        if (!yt.frame || e.origin !== cfg.youtubeOrigin || e.source !== yt.frame.contentWindow) return;
        var msg = e.data;
        if (typeof msg === 'string') {
            try { msg = JSON.parse(msg); } catch (x) { return; }
        }
        if (!msg || typeof msg !== 'object') return;
        if (msg.event === 'onStateChange' && typeof msg.info === 'number') yt.state = msg.info;
        if (msg.event === 'infoDelivery' && msg.info && typeof msg.info === 'object') {
            if (typeof msg.info.currentTime === 'number') yt.time = msg.info.currentTime;
            if (typeof msg.info.duration === 'number' && msg.info.duration > 0) yt.duration = msg.info.duration;
            if (typeof msg.info.playerState === 'number') yt.state = msg.info.playerState;
        }
        if (yt.state === 1 && audio && !audio.paused) audio.pause();
        if (source === 'video') {
            paint();
            setPlayingUi();
        }
    });

    function useSource(next) {
        if (next === source) return;
        if (next === 'video' && audio && !audio.paused) audio.pause();
        if (next === 'audio' && yt.frame) ytCommand('pauseVideo');
        source = next;
        painted.d = -1;
        paint();
        setPlayingUi();
    }

    HOD.actions.on('os.watch', function () { startVideo(0); });

    var tabs = $('[data-ui-tabs]', page);
    if (tabs) {
        tabs.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'watch') { useSource('video'); startVideo(0); }
            if (tab === 'listen') useSource('audio');
        });
        // A deep link (#tab=watch) may already have chosen the tab.
        var chosen = $('[role="tab"][aria-selected="true"]', tabs);
        if (chosen && chosen.getAttribute('data-tab') === 'watch') source = 'video';
    }

    // ------------------------------------------------------------------ notes
    var notes = Array.isArray(cfg.notes) ? cfg.notes.slice() : [];
    var list = $('[data-os-notelist]');
    var compose = $('form[data-action="os.note-add"]');
    var text = $('[data-os-note-text]');
    var stampBtn = $('[data-os-stamp]');
    var stampAt = $('[data-os-stamp-at]');
    var countEl = $('[data-os-count]');
    var emailOpen = $('[data-os-email-open]');
    var frozen = null;          // the moment the note being typed is stamped with
    var nowNoteId = null;

    function sortNotes() {
        notes.sort(function (a, b) {
            var an = a.at === null, bn = b.at === null;
            if (an !== bn) return an ? 1 : -1;
            if (!an && a.at !== b.at) return a.at - b.at;
            return a.id - b.id;
        });
    }

    // A link, not a button, so it wraps with the words around it; without the
    // script it opens the Bible module at that verse.
    function refButton(ref, label) {
        var b = document.createElement('a');
        b.href = '/modules/bible/#read=' + ref.b + '.' + ref.c + (ref.v ? '.' + ref.v : '');
        b.className = 'osl-ref';
        b.setAttribute('data-action', 'os.ref');
        b.setAttribute('data-b', ref.b);
        b.setAttribute('data-c', ref.c);
        b.setAttribute('data-ce', ref.ce);
        b.setAttribute('data-v', ref.v === null ? '' : ref.v);
        b.setAttribute('data-ve', ref.ve === null ? '' : ref.ve);
        b.textContent = label;
        return b;
    }

    function noteBody(note) {
        var body = document.createElement('p');
        body.className = 'osl-note__text';
        (note.segments || [{ t: note.body }]).forEach(function (seg) {
            body.appendChild(seg.ref ? refButton(seg.ref, seg.t) : document.createTextNode(seg.t));
        });
        return body;
    }

    function noteItem(note) {
        var li = document.createElement('li');
        li.className = 'osl-note';
        li.setAttribute('data-id', note.id);
        var at;
        if (note.at !== null) {
            at = document.createElement('button');
            at.type = 'button';
            at.className = 'osl-note__at ui-num';
            at.setAttribute('data-action', 'os.seek');
            at.setAttribute('data-at', note.at);
            at.setAttribute('aria-label', 'Play from ' + note.at_label);
            at.innerHTML = icon('play');
            at.appendChild(document.createTextNode(note.at_label));
        } else {
            at = document.createElement('span');
            at.className = 'osl-note__at osl-note__at--none';
            at.textContent = 'Note';
        }
        var main = document.createElement('div');
        main.className = 'osl-note__main';
        main.appendChild(noteBody(note));
        var tools = document.createElement('div');
        tools.className = 'osl-note__tools';
        tools.innerHTML =
            '<button type="button" class="ui-btn ui-btn--ghost ui-btn--icon ui-btn--sm" data-action="os.note-edit" aria-label="Edit note" data-tooltip="Edit">' + icon('pencil') + '</button>'
            + '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--icon ui-btn--sm" data-action="os.note-delete" aria-label="Delete note" data-tooltip="Delete">' + icon('trash-2') + '</button>';
        li.appendChild(at);
        li.appendChild(main);
        li.appendChild(tools);
        return li;
    }

    function renderNotes(flashId) {
        if (!list) return;
        sortNotes();
        list.textContent = '';
        notes.forEach(function (n) {
            var li = noteItem(n);
            if (n.id === flashId && !reduce) li.classList.add('is-new');
            list.appendChild(li);
        });
        var empty = $('[data-os-notes-empty]');
        if (empty) empty.hidden = notes.length > 0;
        if (emailOpen) emailOpen.disabled = notes.length === 0;
        nowNoteId = null;
        paintMarks();
        paintNowNote();
    }

    // Small ticks on the scrubber where notes sit.
    function paintMarks() {
        var marks = $('[data-os-marks]');
        if (!marks) return;
        marks.textContent = '';
        var d = source === 'audio' ? duration() : 0;
        if (!(d > 0)) return;
        notes.forEach(function (n) {
            if (n.at === null || n.at > d) return;
            var m = document.createElement('span');
            m.className = 'osl-mark';
            m.style.left = (n.at / d * 100).toFixed(3) + '%';
            marks.appendChild(m);
        });
    }

    // The note for the moment playing now, gently marked.
    function paintNowNote() {
        if (!list) return;
        var t = now();
        var id = null;
        if (playing()) {
            notes.forEach(function (n) { if (n.at !== null && n.at <= t + 0.5) id = n.id; });
        }
        if (id === nowNoteId) return;
        nowNoteId = id;
        $$('.osl-note', list).forEach(function (li) {
            li.classList.toggle('is-now', String(id) === li.getAttribute('data-id'));
        });
    }

    function stampValue() {
        if (frozen !== null) return frozen;
        return everPlayed || now() > 0 ? Math.floor(now()) : null;
    }

    function paintStamp() {
        if (!stampBtn) return;
        var v = stampValue();
        var label = v === null ? 'No time' : fmt(v);
        if (stampAt.textContent !== label) stampAt.textContent = label;
        stampBtn.classList.toggle('is-frozen', frozen !== null);
        stampBtn.setAttribute('aria-label', (v === null ? 'Not stamped yet.' : 'Stamped at ' + label + '.') + ' Tap to use the moment playing now.');
    }

    function paintCount() {
        if (!countEl || !text) return;
        var n = text.value.length;
        countEl.textContent = n > cfg.noteMax * 0.8 ? n + ' / ' + cfg.noteMax : '';
        countEl.classList.toggle('is-near', n > cfg.noteMax * 0.95);
    }

    if (text) {
        text.addEventListener('input', function () {
            if (frozen === null && text.value.trim() !== '' && (everPlayed || now() > 0)) frozen = Math.floor(now());
            if (text.value.trim() === '') frozen = null;
            paintStamp();
            paintCount();
        });
        text.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (compose.requestSubmit) compose.requestSubmit();
                else compose.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
            }
        });
    }

    HOD.actions.on('os.stamp', function () {
        frozen = everPlayed || now() > 0 ? Math.floor(now()) : null;
        paintStamp();
        if (text) text.focus();
    });

    function noteThisMoment() {
        if (!text) return;
        frozen = everPlayed || now() > 0 ? Math.floor(now()) : null;
        paintStamp();
        var card = $('[data-os-notes]');
        if (card) card.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'nearest' });
        text.focus({ preventScroll: true });
    }
    HOD.actions.on('os.mark', noteThisMoment);

    HOD.actions.on('os.note-add', function (form) {
        var btn = $('[data-os-add]', form);
        var body = text.value;
        if (body.trim() === '') {
            HOD.form.setErrors(form, { body: 'Type your note first.' });
            text.focus();
            return;
        }
        var at = stampValue();
        HOD.busy(btn, true);
        post('note_add', { body: body, at: at === null ? '' : at })
            .then(function (res) {
                var note = res.data && res.data.note;
                if (!note) return;
                notes.push(note);
                text.value = '';
                frozen = null;
                paintStamp();
                paintCount();
                renderNotes(note.id);
                say('Note added' + (note.at_label ? ' at ' + note.at_label : '') + '.');
            })
            .catch(function (err) {
                if (err && err.field === 'body') text.focus();
            })
            .finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('os.seek', function (btn) {
        var at = Number(btn.getAttribute('data-at'));
        if (!isFinite(at)) return;
        seekTo(at, true);
        if (source === 'audio' && playerEl && !playerInView) updateMini();
    });

    function findNote(el) {
        var li = el.closest('.osl-note');
        var id = li ? Number(li.getAttribute('data-id')) : 0;
        for (var i = 0; i < notes.length; i++) if (notes[i].id === id) return { li: li, note: notes[i], index: i };
        return null;
    }

    HOD.actions.on('os.note-edit', function (btn) {
        var found = findNote(btn);
        if (!found || found.li.classList.contains('is-editing')) return;
        var li = found.li;
        li.classList.add('is-editing');
        var main = $('.osl-note__main', li);
        var form = document.createElement('form');
        form.className = 'osl-note__edit';
        form.setAttribute('data-action', 'os.note-save');
        form.noValidate = true;
        var area = document.createElement('textarea');
        area.className = 'ui-textarea';
        area.name = 'body';
        area.rows = 3;
        area.maxLength = cfg.noteMax;
        area.value = found.note.body;
        area.setAttribute('aria-label', 'Edit note');
        var row = document.createElement('div');
        row.className = 'osl-note__editrow';
        row.innerHTML = '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="os.note-cancel"><span>Cancel</span></button>'
            + '<button type="submit" class="ui-btn ui-btn--primary ui-btn--sm"><span>Save note</span></button>';
        form.appendChild(area);
        form.appendChild(row);
        main.textContent = '';
        main.appendChild(form);
        area.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); renderNotes(); focusNote(found.note.id); }
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                if (form.requestSubmit) form.requestSubmit();
            }
        });
        area.focus();
        area.setSelectionRange(area.value.length, area.value.length);
    });

    function focusNote(id) {
        var li = list && $('.osl-note[data-id="' + id + '"]', list);
        var target = li && ($('[data-action="os.note-edit"]', li));
        if (target) target.focus();
    }

    HOD.actions.on('os.note-cancel', function (btn) {
        var found = findNote(btn);
        renderNotes();
        if (found) focusNote(found.note.id);
    });

    HOD.actions.on('os.note-save', function (form) {
        var found = findNote(form);
        if (!found) return;
        var area = form.querySelector('textarea');
        var btn = form.querySelector('[type="submit"]');
        if (area.value.trim() === '') {
            HOD.toast('Type your note, or delete it.', 'warning');
            area.focus();
            return;
        }
        HOD.busy(btn, true);
        HOD.api(cfg.endpoint, { action: 'note_edit', data: { id: found.note.id, body: area.value } })
            .then(function (res) {
                var note = res.data && res.data.note;
                if (note) notes[found.index] = note;
                renderNotes();
                focusNote(found.note.id);
                say('Note saved.');
            })
            .catch(function () { HOD.busy(btn, false); area.focus(); });
    });

    HOD.actions.on('os.note-delete', function (btn) {
        var found = findNote(btn);
        if (!found) return;
        HOD.confirm({ title: 'Delete this note?', message: 'It cannot be brought back.', confirmLabel: 'Delete note', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(btn, true);
                HOD.api(cfg.endpoint, { action: 'note_delete', data: { id: found.note.id } })
                    .then(function () {
                        notes = notes.filter(function (n) { return n.id !== found.note.id; });
                        renderNotes();
                        say('Note deleted.');
                        if (text) text.focus({ preventScroll: true });
                    })
                    .catch(function () { HOD.busy(btn, false); });
            });
    });

    // ------------------------------------------------------------------ email my notes
    HOD.actions.on('os.email-to', function (input) {
        var other = $('[data-os-email-other]');
        if (!other) return;
        other.hidden = input.value !== 'other';
        if (!other.hidden) {
            var field = $('input[name="email"]', other);
            if (field) field.focus();
        }
    });

    HOD.actions.on('os.email', function (form) {
        var btn = $('[data-os-email-send]');
        var values = HOD.form.values(form);
        var to = values.to === 'mine' ? 'mine' : 'other';
        if (to === 'other' && !String(values.email || '').trim()) {
            HOD.form.setErrors(form, { email: 'Type the email address.' });
            var field = $('input[name="email"]', form);
            if (field) field.focus();
            return;
        }
        HOD.busy(btn, true);
        post('email_notes', { to: to, email: values.email || '' }, { quiet: true })
            .then(function (res) {
                HOD.dialog.close('osl-email-dialog');
                HOD.toast('Notes sent to ' + ((res.data && res.data.to) || 'your email') + '.', 'success');
            })
            .catch(function (err) {
                if (err && err.field === 'email') {
                    var other = $('[data-os-email-other]');
                    if (other) other.hidden = false;
                    var radio = $('input[name="to"][value="other"]', form);
                    if (radio) radio.checked = true;
                    HOD.form.setErrors(form, { email: err.message });
                    var input = $('input[name="email"]', form);
                    if (input) input.focus();
                } else {
                    HOD.toast(err && err.message ? err.message : 'The email could not be sent. Try again later.', 'danger');
                }
            })
            .finally(function () { HOD.busy(btn, false); });
    });

    // ------------------------------------------------------------------ the Bible panel
    var panel = $('[data-os-bible]');
    var bodyEl = panel && $('[data-os-bible-body]', panel);
    var refEl = panel && $('[data-os-bible-ref]', panel);
    var copyEl = panel && $('[data-os-bible-copy]', panel);
    var fullEl = panel && $('[data-os-bible-full]', panel);
    var tSelect = panel && $('#osl-bible-t', panel);
    var translations = (cfg.bible && cfg.bible.translations) || [];
    var bible = {
        t: (function () {
            var want = stored('hodOsBibleT') || (cfg.bible && cfg.bible.translation) || 'KJV';
            return translations.some(function (x) { return x.code === want; }) ? want : 'KJV';
        })(),
        b: 0, c: 0, target: null, data: null, opener: null, seq: 0, open: false,
    };
    if (tSelect) tSelect.value = bible.t;

    function bibleLabel(ref) {
        return ref && ref.label ? ref.label : (bible.data ? bible.data.ref : 'Bible');
    }

    function reportFums(token) {
        var url = cfg.bible && cfg.bible.fums;
        if (!token || !url) return;
        var id = function (store, key) {
            try {
                var v = store.getItem(key);
                if (!v) { v = (window.crypto && crypto.randomUUID ? crypto.randomUUID().replace(/-/g, '') : String(Math.random()).slice(2)); store.setItem(key, v); }
                return v;
            } catch (e) { return String(Math.random()).slice(2); }
        };
        var img = new Image();
        img.referrerPolicy = 'no-referrer';
        img.src = url + '?t=' + encodeURIComponent(token) + '&dId=' + encodeURIComponent(id(window.localStorage, 'hodBibleFumsDevice'))
            + '&sId=' + encodeURIComponent(id(window.sessionStorage, 'hodBibleFumsSession'));
    }

    function renderChapter(data, target) {
        bible.data = data;
        bodyEl.textContent = '';
        var first = null;
        var vFrom = target && target.v ? target.v : 0;
        var vTo = target && target.v ? (target.ce > data.chapter ? 999 : (target.ve || target.v)) : 0;
        (data.verses || []).forEach(function (v) {
            (v.h || []).forEach(function (h) {
                var head = document.createElement('h3');
                head.className = 'osl-bible__h';
                head.textContent = h;
                bodyEl.appendChild(head);
            });
            var p = document.createElement('p');
            p.className = 'osl-v' + (v.q ? ' osl-v--poetry' : '');
            p.setAttribute('data-v', v.v);
            var n = document.createElement('span');
            n.className = 'osl-v__n';
            n.textContent = v.v;
            p.appendChild(n);
            p.appendChild(document.createTextNode(' ' + v.t));
            if (vFrom && v.v >= vFrom && v.v <= vTo) {
                p.classList.add('is-target');
                if (!first) first = p;
            }
            bodyEl.appendChild(p);
        });
        copyEl.textContent = data.copyright || '';
        refEl.textContent = target && target.label && target.b === data.book && target.c === data.chapter ? target.label : data.ref;
        if (fullEl) fullEl.href = '/modules/bible/#read=' + data.book + '.' + data.chapter + (vFrom ? '.' + vFrom : '') + '&t=' + encodeURIComponent(data.translation);
        var prev = $('[data-os-bible-prev]', panel), next = $('[data-os-bible-next]', panel);
        if (prev) { prev.disabled = !data.prev; prev.setAttribute('aria-label', data.prev ? 'Previous: ' + data.prev.ref : 'Previous chapter'); }
        if (next) { next.disabled = !data.next; next.setAttribute('aria-label', data.next ? 'Next: ' + data.next.ref : 'Next chapter'); }
        reportFums(data.fums_token);
        if (first) {
            window.requestAnimationFrame(function () {
                bodyEl.scrollTop = Math.max(0, first.offsetTop - bodyEl.offsetTop - 24);
            });
        } else {
            bodyEl.scrollTop = 0;
        }
    }

    function loadChapter(b, c, target) {
        var seq = ++bible.seq;
        bible.b = b;
        bible.c = c;
        bible.target = target || null;
        var slow = setTimeout(function () {
            if (seq !== bible.seq) return;
            bodyEl.innerHTML = '<div class="osl-bible__loading" aria-hidden="true"><span class="ui-skeleton ui-skeleton--text"></span><span class="ui-skeleton ui-skeleton--text"></span><span class="ui-skeleton ui-skeleton--text"></span></div>';
        }, 150);
        refEl.textContent = target && target.label ? target.label : refEl.textContent;
        return HOD.api('/api/bible_api.php', { method: 'GET', action: 'chapter', data: { t: bible.t, b: b, c: c }, quiet: true })
            .then(function (json) {
                if (seq !== bible.seq) return;
                renderChapter(json.data, target);
            })
            .catch(function (err) {
                if (seq !== bible.seq) return;
                bodyEl.textContent = '';
                var box = document.createElement('div');
                box.className = 'osl-bible__error';
                var msg = document.createElement('p');
                msg.textContent = (err && err.message) || 'That chapter could not be loaded.';
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'ui-btn ui-btn--secondary ui-btn--sm';
                retry.setAttribute('data-action', 'os.bible-retry');
                retry.innerHTML = '<span>Try again</span>';
                box.appendChild(msg);
                box.appendChild(retry);
                bodyEl.appendChild(box);
            })
            .finally(function () { clearTimeout(slow); });
    }

    function setBibleOpen(on) {
        bible.open = on;
        page.classList.toggle('is-bible-open', on);
        document.documentElement.classList.toggle('osl-bible-open', on);
        $$('[data-action="os.bible"]').forEach(function (b) { b.setAttribute('aria-expanded', on ? 'true' : 'false'); });
    }

    function openBible(target, opener) {
        if (!panel) return;
        bible.opener = opener || document.activeElement;
        var already = bible.open;
        panel.hidden = false;
        setBibleOpen(true);
        if (reduce || already) panel.classList.add('is-open');
        else window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { panel.classList.add('is-open'); }); });
        if (target) loadChapter(target.b, target.c, target);
        else if (!bible.data) loadChapter(43, 1, null);
        refEl.focus({ preventScroll: true });
    }

    function closeBible() {
        if (!panel || !bible.open) return;
        setBibleOpen(false);
        panel.classList.remove('is-open');
        var done = function () { if (!bible.open) panel.hidden = true; };
        if (reduce) done();
        else setTimeout(done, 240);
        var back = bible.opener && document.contains(bible.opener) && !panel.contains(bible.opener) ? bible.opener : $('[data-action="os.bible"]');
        if (back) back.focus({ preventScroll: true });
    }

    function refFrom(el) {
        var num = function (name) { var v = el.getAttribute(name); return v === null || v === '' ? null : Number(v); };
        var b = num('data-b'), c = num('data-c');
        if (!b || !c) return null;
        return { b: b, c: c, ce: num('data-ce') || c, v: num('data-v'), ve: num('data-ve'), label: el.textContent.trim() };
    }

    HOD.actions.on('os.bible', function (btn) {
        if (bible.open) closeBible();
        else openBible(null, btn);
    });
    HOD.actions.on('os.bible-close', closeBible);
    HOD.actions.on('os.ref', function (btn) {
        var ref = refFrom(btn);
        if (ref) openBible(ref, btn);
    });
    HOD.actions.on('os.bible-prev', function () {
        if (bible.data && bible.data.prev) loadChapter(bible.data.prev.book, bible.data.prev.chapter, null);
    });
    HOD.actions.on('os.bible-next', function () {
        if (bible.data && bible.data.next) loadChapter(bible.data.next.book, bible.data.next.chapter, null);
    });
    HOD.actions.on('os.bible-retry', function () {
        loadChapter(bible.b || 43, bible.c || 1, bible.target);
    });
    HOD.actions.on('os.bible-t', function (select) {
        bible.t = select.value;
        stored('hodOsBibleT', bible.t);
        if (bible.b) loadChapter(bible.b, bible.c, bible.target);
    });
    HOD.actions.on('os.bible-go', function (form) {
        var input = form.querySelector('input[name="q"]');
        var q = input.value.trim();
        if (!q) { input.focus(); return; }
        HOD.api(cfg.endpoint, { action: 'ref', data: { q: q }, quiet: true })
            .then(function (res) {
                var r = res.data && res.data.ref;
                if (!r) return;
                input.value = '';
                input.blur();
                loadChapter(r.b, r.c, r);
            })
            .catch(function (err) { HOD.toast((err && err.message) || 'Try a reference like John 3:16.', 'warning'); });
    });

    // ------------------------------------------------------------------ keyboard
    document.addEventListener('keydown', function (e) {
        if (e.defaultPrevented || e.altKey || e.ctrlKey || e.metaKey) return;
        var t = e.target instanceof Element ? e.target : null;
        if (e.key === 'Escape' && bible.open && !document.querySelector('dialog[open]')) {
            if (!t || !t.closest('[data-ui-info], [role="menu"]')) {
                e.preventDefault();
                closeBible();
            }
            return;
        }
        if (typingIn(t) || document.querySelector('dialog[open]')) return;
        if (t && t.getAttribute('role') === 'tab' && /^Arrow/.test(e.key)) return;
        var k = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        if (k === ' ' || k === 'k') {
            if (k === ' ' && t && t.closest('button, a, summary, [role="button"]')) return;
            if (!source) return;
            e.preventDefault();
            toggle();
        } else if (k === 'j' || k === 'ArrowLeft') {
            if (!source) return;
            e.preventDefault();
            skip(-15);
        } else if (k === 'l' || k === 'ArrowRight') {
            if (!source) return;
            e.preventDefault();
            skip(15);
        } else if (k === 'n') {
            e.preventDefault();
            noteThisMoment();
        } else if (k === 'b') {
            e.preventDefault();
            if (bible.open) closeBible();
            else openBible(null, null);
        }
    });

    // ------------------------------------------------------------------ start
    renderNotes();
    paint();
    setPlayingUi();
})();
