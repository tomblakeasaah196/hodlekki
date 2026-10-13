// /assets/js/bible/core.js
// The Bible — shared plumbing: API calls, state, settings, small helpers.
// Every other file in assets/js/bible/ hangs off window.HODBible.
(function () {
    'use strict';

    const CFG = window.HOD_BIBLE || {};
    const boot = CFG.boot || {};
    const BB = (window.HODBible = window.HODBible || {});

    BB.cfg = CFG;
    BB.books = boot.books || [];
    BB.counts = boot.counts || {};
    BB.colors = boot.colors || ['yellow', 'green', 'blue', 'pink', 'purple'];
    BB.ready = !!boot.ready;
    BB.isAdmin = !!boot.is_admin;

    BB.state = {
        translation: (boot.state && boot.state.translation) || 'KJV',
        book: (boot.state && boot.state.book) || 43,
        chapter: (boot.state && boot.state.chapter) || 1,
        verse: (boot.state && boot.state.verse) || 1,
        prefs: Object.assign({}, (boot.state && boot.state.prefs) || {}),
        translations: boot.translations || [],
        plans: boot.plans || [],
        streak: boot.streak || null,
        data: null,          // the chapter on screen (API payload)
        view: 'read',
    };

    // ------------------------------------------------------------- events
    const bus = document.createElement('span');
    BB.on = (type, fn) => bus.addEventListener(type, (e) => fn(e.detail));
    BB.emit = (type, detail) => bus.dispatchEvent(new CustomEvent(type, { detail }));

    // ------------------------------------------------------------- helpers
    BB.$ = (sel, root) => (root || document).querySelector(sel);
    BB.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    BB.esc = function (s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    BB.debounce = function (fn, ms) {
        let t = null;
        const wrapped = function () {
            const args = arguments;
            clearTimeout(t);
            t = setTimeout(() => fn.apply(null, args), ms);
        };
        wrapped.flush = function () { clearTimeout(t); fn(); };
        return wrapped;
    };

    /** Code points, not UTF-16 units: offsets on the server count characters. */
    BB.chars = (s) => Array.from(String(s || ''));
    BB.cpLen = (s) => BB.chars(s).length;

    BB.toast = function (text, kind) {
        if (typeof window.Toastify !== 'function') return;
        const colours = { ok: '#1D356A', error: '#B91C1C', info: '#3F3A33' };
        window.Toastify({
            text: text,
            duration: kind === 'error' ? 4200 : 2400,
            gravity: 'bottom',
            position: 'center',
            className: 'bb-toast',
            style: {
                background: colours[kind || 'ok'] || colours.ok,
                color: '#fff',
                borderRadius: '999px',
                fontWeight: '600',
                fontSize: '14px',
                padding: '10px 18px',
                boxShadow: '0 10px 30px -10px rgba(0,0,0,.45)',
            },
        }).showToast();
    };

    BB.book = (id) => BB.books.find((b) => b.id === Number(id)) || null;
    BB.verseCount = (b, c) => (BB.counts[b] && BB.counts[b][c]) || 0;

    /** Mirrors bible_format_ref() in includes/bible/books.php. */
    BB.ref = function (b, c, v, ve, ce) {
        const book = BB.book(b);
        if (!book) return '';
        const name = Number(b) === 19 ? 'Psalm' : book.name;
        if (!v) {
            if (ce && ce !== c) return (Number(b) === 19 ? 'Psalms' : name) + ' ' + c + '–' + ce;
            return name + ' ' + c;
        }
        let s = name + ' ' + c + ':' + v;
        if (ce && ce !== c) s += '–' + ce + ':' + (ve || 1);
        else if (ve && ve !== v) s += '–' + ve;
        return s;
    };

    BB.translation = (code) => BB.state.translations.find((t) => t.code === code) || null;

    BB.dateLabel = function (iso) {
        if (!iso) return '';
        const d = new Date(String(iso).replace(' ', 'T'));
        if (isNaN(d)) return '';
        const today = new Date();
        const diff = Math.round((new Date(today.toDateString()) - new Date(d.toDateString())) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Yesterday';
        return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' });
    };

    // ------------------------------------------------------------- API
    function apiError(json, fallback) {
        const err = new Error((json && json.message) || fallback || 'Something went wrong.');
        err.code = (json && json.code) || 'ERROR';
        return err;
    }

    BB.api = {
        async get(action, params) {
            const qs = new URLSearchParams(Object.assign({ action: action }, params || {}));
            let res;
            try {
                res = await fetch('/api/bible_api.php?' + qs.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            } catch (e) {
                throw apiError(null, 'You seem to be offline. Check your connection and try again.');
            }
            let json = null;
            try { json = await res.json(); } catch (e) { /* not JSON */ }
            if (res.status === 401) { window.location.href = '/auth/login.php'; throw apiError(json); }
            if (!json || json.status !== 'success') throw apiError(json);
            return json.data;
        },
        async post(action, body) {
            let res;
            try {
                res = await fetch('/api/bible_api.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    // keepalive lets the last reading position save while the tab closes.
                    keepalive: true,
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Bible-CSRF': CFG.csrf || '' },
                    body: JSON.stringify(Object.assign({ action: action }, body || {})),
                });
            } catch (e) {
                throw apiError(null, 'You seem to be offline. Your change was not saved.');
            }
            let json = null;
            try { json = await res.json(); } catch (e) { /* not JSON */ }
            if (res.status === 401) { window.location.href = '/auth/login.php'; throw apiError(json); }
            if (!json || json.status !== 'success') throw apiError(json);
            return json.data;
        },
    };

    // ------------------------------------------------------------- FUMS
    // API.Bible's Fair Use Management System: every time licensed text is
    // shown, the browser reports the fumsToken that came with it — from the
    // 29-day cache or not. The device id is a random number kept on this
    // device only; the session id lasts for this tab. Neither identifies the
    // member.
    function randomId() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID().replace(/-/g, '');
        return Math.random().toString(36).slice(2) + Date.now().toString(36);
    }
    function stored(storage, key) {
        try {
            let v = storage.getItem(key);
            if (!v) { v = randomId(); storage.setItem(key, v); }
            return v;
        } catch (e) {
            return randomId();
        }
    }
    BB.fums = function (token) {
        if (!token || !CFG.fumsUrl) return;
        const dId = stored(window.localStorage, 'hodBibleFumsDevice');
        const sId = stored(window.sessionStorage, 'hodBibleFumsSession');
        const img = new Image();
        img.referrerPolicy = 'no-referrer';
        img.src = CFG.fumsUrl + '?t=' + encodeURIComponent(token) + '&dId=' + encodeURIComponent(dId) + '&sId=' + encodeURIComponent(sId);
    };

    // ------------------------------------------------------------- settings
    const app = () => document.getElementById('bibleApp');

    BB.applyPrefs = function () {
        const p = BB.state.prefs;
        const root = document.documentElement;
        root.setAttribute('data-bible-theme', p.theme || 'paper');
        root.style.setProperty('--bb-size', (p.size || 19) + 'px');
        root.style.setProperty('--bb-leading', String(p.leading || 1.8));
        const fonts = {
            serif: "'Literata', 'Iowan Old Style', Georgia, serif",
            classic: "'EB Garamond', Garamond, Georgia, serif",
            sans: "Inter, system-ui, sans-serif",
        };
        root.style.setProperty('--bb-font', fonts[p.font] || fonts.serif);
        const el = app();
        if (!el) return;
        el.dataset.font = p.font || 'serif';
        el.dataset.layout = p.layout || 'para';
        el.classList.toggle('bb-red', p.red !== false);
        el.classList.toggle('bb-no-numbers', p.numbers === false);
        el.classList.toggle('bb-no-headings', p.headings === false);
        const meta = document.querySelector('meta[name="theme-color"]');
        if (meta && p.theme === 'night') meta.setAttribute('content', '#0B1220');
    };

    const saveState = BB.debounce(function () {
        if (!BB.ready) return;
        const s = BB.state;
        BB.api.post('state', {
            translation: s.translation, book: s.book, chapter: s.chapter, verse: s.verse, prefs: s.prefs,
        }).catch(() => { /* position saving is best-effort */ });
    }, 1200);

    BB.setPrefs = function (partial) {
        Object.assign(BB.state.prefs, partial || {});
        BB.applyPrefs();
        saveState();
        BB.emit('prefs', BB.state.prefs);
    };
    BB.saveState = saveState;

    window.addEventListener('pagehide', () => { if (saveState.flush) saveState.flush(); });

    BB.applyPrefs();
})();
