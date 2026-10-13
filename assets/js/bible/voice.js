// /assets/js/bible/voice.js
// The Bible — voice reader, built on the browser's own speech
// (speechSynthesis). Free, no data, and the voice is whatever the phone or
// computer has installed.
//
// It speaks one verse at a time (long verses in sentence-sized pieces,
// because Chrome silently stops utterances longer than ~15 seconds), so the
// verse being read can glow and the page can follow it. A watchdog moves on
// if a browser forgets to fire `end`, which some Android voices do.
(function () {
    'use strict';

    const BB = window.HODBible;
    if (!BB) return;
    const $ = BB.$;
    const synth = window.speechSynthesis;
    const supported = !!(synth && window.SpeechSynthesisUtterance);

    const st = {
        playing: false,
        paused: false,
        verses: [],
        idx: 0,
        chunks: [],
        chunk: 0,
        announce: false,
        token: 0,
        watchdog: null,
        gapTimer: null,
        mem: null,            // { from, to, times, gap, round } — indexes into verses
        sleep: '0',           // '0' | 'chapter' | minutes
        sleepAt: 0,
        sleepTimer: null,
        sleepTick: null,
        lock: null,
        selfNav: false,       // the reader itself is turning the page
        userScrolledAt: 0,
        key: '',
    };

    const RATES = [0.8, 0.9, 1, 1.1, 1.25, 1.5];

    /** 1 → "1.0×", 1.25 → "1.25×", 0.9 → "0.9×". */
    function fmtRate(r) {
        r = Number(r) || 1;
        return (Math.abs(r - Math.round(r)) < 0.001 ? r.toFixed(1) : String(Number(r.toFixed(2)))) + '×';
    }

    // ------------------------------------------------------------------ voices

    function voices() {
        if (!supported) return [];
        return synth.getVoices().filter((v) => /^en(-|_|$)/i.test(v.lang));
    }

    function pickVoice() {
        const list = voices();
        const want = BB.state.prefs.voice;
        if (want) {
            const hit = list.find((v) => v.voiceURI === want || v.name === want);
            if (hit) return hit;
        }
        const order = ['en-NG', 'en-GB', 'en-US', 'en-AU', 'en-ZA', 'en-IE'];
        for (const lang of order) {
            const local = list.find((v) => v.lang.replace('_', '-') === lang && v.localService);
            if (local) return local;
            const any = list.find((v) => v.lang.replace('_', '-') === lang);
            if (any) return any;
        }
        return list[0] || null;
    }

    // ------------------------------------------------------------------ text

    /** Capitals read as words, not letters: LORD → Lord, I AM THAT I AM → I Am That I Am. */
    function speakable(text) {
        return String(text)
            .replace(/\b([A-Z])([A-Z]+)\b/g, (m, a, b) => a + b.toLowerCase())
            .replace(/[—–]/g, ', ')
            .replace(/\s+/g, ' ')
            .trim();
    }

    /** Sentence-sized pieces of at most ~220 characters. */
    function chunksOf(text) {
        const parts = text.match(/[^.;:!?]+[.;:!?]*[”’"')\]]*\s*/g) || [text];
        const out = [];
        let buf = '';
        parts.forEach((p) => {
            if ((buf + p).length > 220 && buf) { out.push(buf.trim()); buf = ''; }
            if (p.length > 220) {
                // Split at commas, keeping the comma (no lookbehind: older
                // Safari cannot parse one, and that would sink the whole file).
                p.split(/,\s+/).map((q, i, all) => (i < all.length - 1 ? q + ',' : q)).forEach((q) => {
                    if ((buf + ' ' + q).length > 220 && buf) { out.push(buf.trim()); buf = ''; }
                    buf += (buf ? ' ' : '') + q;
                });
            } else {
                buf += p;
            }
        });
        if (buf.trim()) out.push(buf.trim());
        return out;
    }

    // ------------------------------------------------------------------ speech

    function say(text, done) {
        const token = st.token;
        const u = new SpeechSynthesisUtterance(text);
        const v = pickVoice();
        if (v) { u.voice = v; u.lang = v.lang; } else { u.lang = 'en-GB'; }
        u.rate = Number(BB.state.prefs.rate) || 1;
        u.pitch = 1;
        let finished = false;
        const finish = () => {
            if (finished || token !== st.token) return;
            finished = true;
            clearTimeout(st.watchdog);
            done();
        };
        u.onend = finish;
        u.onerror = (e) => {
            if (token !== st.token) return;
            if (e && (e.error === 'interrupted' || e.error === 'canceled')) return;
            finish();
        };
        clearTimeout(st.watchdog);
        st.watchdog = setTimeout(() => {
            if (token !== st.token) return;
            synth.cancel();
            finish();
        }, (text.length * 95) / (u.rate || 1) + 5000);
        if (synth.paused) synth.resume();
        synth.speak(u);
    }

    function clearGlow() {
        BB.$$('.vt.bb-reading').forEach((el) => el.classList.remove('bb-reading'));
    }

    function glow(v) {
        clearGlow();
        const el = BB.reader.verseEl(v);
        if (!el) return;
        el.classList.add('bb-reading');
        if (Date.now() - st.userScrolledAt > 4000) BB.reader.scrollToVerse(v, { smooth: true, center: true });
    }

    function speakCurrent() {
        if (!st.playing || st.paused) return;
        const data = BB.state.data;
        if (st.announce) {
            st.announce = false;
            updateInfo();
            const book = BB.book(data.book);
            const name = data.book === 19 ? 'Psalm ' + data.chapter : (book ? book.name : '') + ', chapter ' + data.chapter;
            say(name + '.', () => speakCurrent());
            return;
        }
        const verse = st.verses[st.idx];
        if (!verse) { chapterEnd(); return; }
        if (st.chunk === 0) {
            st.chunks = chunksOf(speakable(verse.t));
            glow(verse.v);
            updateInfo();
        }
        const piece = st.chunks[st.chunk];
        if (piece == null) { afterVerse(); return; }
        say(piece, () => {
            st.chunk++;
            if (st.chunk < st.chunks.length) speakCurrent();
            else afterVerse();
        });
    }

    function afterVerse() {
        st.chunk = 0;
        if (st.mem) {
            if (st.idx >= st.mem.to) {
                if (st.mem.times === 0 || st.mem.round < st.mem.times) {
                    st.mem.round++;
                    const token = st.token;
                    clearTimeout(st.gapTimer);
                    st.gapTimer = setTimeout(() => {
                        if (token !== st.token) return;
                        st.idx = st.mem.from;
                        speakCurrent();
                    }, st.mem.gap * 1000);
                    updateInfo();
                    return;
                }
                const times = st.mem.times;
                stop();
                BB.toast('Repeated ' + times + ' times. Well done!');
                return;
            }
        }
        st.idx++;
        speakCurrent();
    }

    async function chapterEnd() {
        const data = BB.state.data;
        BB.reader.markRead('voice');
        if (st.sleep === 'chapter') {
            stop();
            BB.toast('Sleep timer: end of chapter. Sleep well.');
            return;
        }
        if (BB.state.prefs.continue !== false && data && data.next) {
            st.selfNav = true;
            const ok = await BB.reader.load(BB.state.translation, data.next.book, data.next.chapter, { dir: 'next' });
            st.selfNav = false;
            if (!ok || !st.playing) { stop(); return; }
            st.verses = BB.state.data.verses;
            st.key = keyOf(BB.state.data);
            st.idx = 0;
            st.chunk = 0;
            st.announce = true;
            speakCurrent();
            return;
        }
        stop();
        BB.toast('Finished ' + (data ? data.ref : 'the chapter'));
    }

    function keyOf(d) {
        return d ? d.translation + ':' + d.book + ':' + d.chapter : '';
    }

    // ------------------------------------------------------------------ controls

    async function wake(on) {
        try {
            if (on && 'wakeLock' in navigator && !st.lock) {
                st.lock = await navigator.wakeLock.request('screen');
                st.lock.addEventListener('release', () => { st.lock = null; });
            } else if (!on && st.lock) {
                await st.lock.release();
                st.lock = null;
            }
        } catch (e) { /* not allowed here — reading still works */ }
    }

    /**
     * Start reading. opts.from = verse number; opts.mem = {from, to, times, gap} (verse numbers).
     */
    function start(opts) {
        opts = opts || {};
        if (!supported) {
            BB.toast('This browser cannot read aloud. Try Chrome, Edge or Safari.', 'error');
            return;
        }
        const data = BB.state.data;
        if (!data) return;
        if (BB.switchTab && BB.state.view !== 'read') BB.switchTab('read');
        synth.cancel();
        st.token++;
        clearTimeout(st.gapTimer);

        st.verses = data.verses;
        st.key = keyOf(data);
        const indexOf = (n) => Math.max(0, st.verses.findIndex((v) => v.v === Number(n)));
        let from = opts.from || BB.reader.topVerse() || 1;
        if (opts.mem) {
            const a = indexOf(opts.mem.from);
            const b = Math.max(a, indexOf(opts.mem.to));
            st.mem = { from: a, to: b, times: Number(opts.mem.times) || 0, gap: Number(opts.mem.gap) || 2, round: 1 };
            from = opts.mem.from;
        } else {
            st.mem = null;
        }
        st.idx = indexOf(from);
        st.chunk = 0;
        st.announce = !st.mem && st.idx === 0;
        st.playing = true;
        st.paused = false;
        showPlayer(true);
        wake(true);
        mediaSession();
        speakCurrent();
    }

    function pause() {
        if (!st.playing) return;
        st.paused = true;
        st.token++;
        clearTimeout(st.watchdog);
        clearTimeout(st.gapTimer);
        synth.cancel();
        updateInfo();
        wake(false);
    }

    function resume() {
        if (!st.playing) return;
        st.paused = false;
        st.token++;
        updateInfo();
        wake(true);
        speakCurrent();
    }

    function step(delta) {
        if (!st.playing) return;
        st.token++;
        synth.cancel();
        st.idx = Math.max(0, Math.min(st.verses.length - 1, st.idx + delta));
        st.chunk = 0;
        st.announce = false;
        if (st.paused) { st.paused = false; }
        speakCurrent();
    }

    function stop() {
        st.token++;
        st.playing = false;
        st.paused = false;
        st.mem = null;
        clearTimeout(st.watchdog);
        clearTimeout(st.gapTimer);
        setSleep('0', true);
        if (supported) synth.cancel();
        clearGlow();
        showPlayer(false);
        wake(false);
    }

    function setSleep(mode, silent) {
        st.sleep = String(mode);
        clearTimeout(st.sleepTimer);
        clearInterval(st.sleepTick);
        st.sleepAt = 0;
        const minutes = Number(mode);
        if (minutes > 0) {
            st.sleepAt = Date.now() + minutes * 60000;
            st.sleepTimer = setTimeout(() => {
                stop();
                BB.toast('Sleep timer ended. Sleep well.');
            }, minutes * 60000);
            st.sleepTick = setInterval(updateTimerChip, 20000);
            if (!silent) BB.toast('Reading will stop in ' + minutes + ' minutes');
        } else if (mode === 'chapter' && !silent) {
            BB.toast('Reading will stop at the end of this chapter');
        }
        BB.$$('#bbSleep button').forEach((b) => b.classList.toggle('is-on', b.dataset.sleep === st.sleep));
        updateTimerChip();
    }

    // ------------------------------------------------------------------ UI

    function showPlayer(on) {
        const p = $('#bbPlayer');
        if (!p) return;
        p.hidden = !on;
        $('#bbListenBtn').classList.toggle('is-on', on);
        updateInfo();
    }

    function updateInfo() {
        const p = $('#bbPlayer');
        if (!p || p.hidden) return;
        p.classList.toggle('is-paused', st.paused);
        $('#bbPlPlay').setAttribute('aria-label', st.paused ? 'Play' : 'Pause');
        const data = BB.state.data;
        const verse = st.verses[st.idx];
        $('#bbPlRef').textContent = data && verse ? BB.ref(data.book, data.chapter, verse.v) : (data ? data.ref : '—');
        let sub = (data ? data.translation : '') + ' · ' + (st.paused ? 'Paused' : 'Reading');
        if (st.mem) {
            sub = 'Memorising · ' + (st.mem.times ? 'round ' + st.mem.round + ' of ' + st.mem.times : 'round ' + st.mem.round);
        }
        $('#bbPlSub').textContent = sub;
        $('#bbPlRate').textContent = fmtRate(BB.state.prefs.rate);
        updateTimerChip();
    }

    function updateTimerChip() {
        const chip = $('#bbPlTimer');
        if (!chip) return;
        const label = chip.querySelector('span');
        let text = '';
        if (st.sleep === 'chapter') text = 'End';
        else if (st.sleepAt) text = Math.max(1, Math.round((st.sleepAt - Date.now()) / 60000)) + 'm';
        label.textContent = text;
        chip.classList.toggle('is-on', !!text);
    }

    function cycleRate() {
        const cur = Number(BB.state.prefs.rate) || 1;
        const i = RATES.findIndex((r) => Math.abs(r - cur) < 0.01);
        const next = RATES[(i + 1) % RATES.length];
        BB.setPrefs({ rate: next });
        updateInfo();
        if (st.playing && !st.paused) { st.token++; synth.cancel(); speakCurrent(); }
    }

    function cycleSleep() {
        const order = ['0', 'chapter', '15', '30', '60'];
        const i = order.indexOf(st.sleep);
        setSleep(order[(i + 1) % order.length]);
    }

    function fillVoiceModal() {
        const sel = $('#bbVoiceSelect');
        const list = voices();
        const chosen = pickVoice();
        sel.innerHTML = list.length
            ? list.map((v) => '<option value="' + BB.esc(v.voiceURI) + '"' + (chosen && chosen.voiceURI === v.voiceURI ? ' selected' : '') + '>'
                + BB.esc(v.name.replace(/^(Microsoft|Google)\s+/, '')) + ' — ' + BB.esc(v.lang) + (v.localService ? '' : ' (online)') + '</option>').join('')
            : '<option value="">Your device’s default voice</option>';
        $('#bbVoiceSupport').textContent = supported
            ? 'Uses your device’s built-in voices — free, and no data is used. More voices can be added in your phone’s text-to-speech settings.'
            : 'This browser cannot read aloud. Try Chrome, Edge or Safari.';
        const rate = Number(BB.state.prefs.rate) || 1;
        $('#bbVoiceRate').value = rate;
        $('#bbRateOut').textContent = fmtRate(rate);
        $('#bbVoiceContinue').checked = BB.state.prefs.continue !== false;

        const data = BB.state.data;
        const opts = (data ? data.verses : []).map((v) => '<option value="' + v.v + '">' + v.v + '</option>').join('');
        $('#bbMemFrom').innerHTML = opts;
        $('#bbMemTo').innerHTML = opts;
        const anchor = BB.reader.selectionAnchor();
        const from = anchor ? anchor.vs : (BB.reader.topVerse() || 1);
        $('#bbMemFrom').value = String(from);
        $('#bbMemTo').value = String(anchor ? anchor.ve : from);
        BB.$$('#bbSleep button').forEach((b) => b.classList.toggle('is-on', b.dataset.sleep === st.sleep));
    }

    function openSettings() {
        fillVoiceModal();
        BB.openModal('bibleVoiceModal');
    }

    function mediaSession() {
        if (!('mediaSession' in navigator)) return;
        const data = BB.state.data;
        try {
            navigator.mediaSession.metadata = new window.MediaMetadata({
                title: data ? data.ref : 'The Bible',
                artist: data ? data.translation : '',
                album: 'HOD Lekki — The Bible',
            });
            navigator.mediaSession.setActionHandler('play', resume);
            navigator.mediaSession.setActionHandler('pause', pause);
            navigator.mediaSession.setActionHandler('nexttrack', () => step(1));
            navigator.mediaSession.setActionHandler('previoustrack', () => step(-1));
        } catch (e) { /* optional */ }
    }

    function bind() {
        if (supported && typeof synth.addEventListener === 'function') {
            synth.addEventListener('voiceschanged', () => {
                if (!$('#bibleVoiceModal').classList.contains('hidden')) fillVoiceModal();
            });
        }
        synth && synth.getVoices();

        $('#bbListenBtn').addEventListener('click', () => {
            if (st.playing) { if (st.paused) resume(); else pause(); return; }
            start({});
        });
        $('#bbPlPlay').addEventListener('click', () => (st.paused ? resume() : pause()));
        $('#bbPlPrev').addEventListener('click', () => step(-1));
        $('#bbPlNext').addEventListener('click', () => step(1));
        $('#bbPlClose').addEventListener('click', stop);
        $('#bbPlRate').addEventListener('click', cycleRate);
        $('#bbPlTimer').addEventListener('click', cycleSleep);
        $('#bbPlSettings').addEventListener('click', openSettings);

        $('#bbVoiceSelect').addEventListener('change', (e) => BB.setPrefs({ voice: e.target.value }));
        $('#bbVoiceRate').addEventListener('input', (e) => {
            const r = Number(e.target.value);
            $('#bbRateOut').textContent = fmtRate(r);
            BB.setPrefs({ rate: r });
            updateInfo();
        });
        $('#bbVoiceContinue').addEventListener('change', (e) => BB.setPrefs({ continue: e.target.checked }));
        $('#bbSleep').addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (b) setSleep(b.dataset.sleep);
        });
        $('#bbVoiceTest').addEventListener('click', () => {
            if (!supported) return;
            const wasPlaying = st.playing && !st.paused;
            if (wasPlaying) pause();
            st.token++;
            synth.cancel();
            say('The Lord is my shepherd; I shall not want.', () => {});
        });
        $('#bbMemStart').addEventListener('click', () => {
            const from = Number($('#bbMemFrom').value);
            const to = Math.max(from, Number($('#bbMemTo').value));
            BB.closeModal('bibleVoiceModal');
            start({ mem: { from: from, to: to, times: Number($('#bbMemTimes').value), gap: Number($('#bbMemGap').value) } });
        });

        // Follow-along pauses for 4 seconds whenever the member scrolls.
        const m = document.querySelector('main');
        if (m) {
            ['wheel', 'touchmove'].forEach((ev) => m.addEventListener(ev, () => { st.userScrolledAt = Date.now(); }, { passive: true }));
        }

        BB.on('chapter', (e) => {
            if (!st.playing) return;
            const key = keyOf(e.data);
            if (key === st.key) {
                // Same chapter repainted: keep the glow on the verse being read.
                const verse = st.verses[st.idx];
                if (verse) {
                    const el = BB.reader.verseEl(verse.v);
                    if (el) el.classList.add('bb-reading');
                }
                st.verses = e.data.verses;
                return;
            }
            if (!st.selfNav) stop();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible' && st.playing && !st.paused) wake(true);
        });
        window.addEventListener('pagehide', () => { if (supported) synth.cancel(); });
    }

    BB.voice = { start: start, stop: stop, pause: pause, resume: resume, bind: bind, openSettings: openSettings, supported: supported };
})();
