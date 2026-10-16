// /assets/js/bible/app.js
// The Bible — start-up and the pieces that tie the module together: tabs,
// dialogs, the book/chapter picker, translations, display settings and the
// administrators' API.Bible status card.
(function () {
    'use strict';

    const BB = window.HODBible;
    if (!BB) return;
    const $ = BB.$;
    const $$ = BB.$$;
    const esc = BB.esc;

    // ------------------------------------------------------------------ dialogs
    // modal-manager.js does focus, inerting and scroll lock; a dialog is open
    // when it does not carry .hidden. They close only through a visible button.
    BB.openModal = function (id) {
        const el = document.getElementById(id);
        if (el) el.classList.remove('hidden');
        if (BB.reader) BB.reader.hideBar();
    };
    BB.closeModal = function (id) {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
    };
    document.addEventListener('click', (e) => {
        const close = e.target.closest('[data-close]');
        if (!close) return;
        const modal = close.closest('[id$="Modal"]');
        if (modal) modal.classList.add('hidden');
    });

    // ------------------------------------------------------------------ tabs
    const VIEWS = ['read', 'search', 'notes', 'plans'];

    /**
     * Show a tab. opts.restore = false when the caller is about to load a
     * chapter itself (so the old reading position is not scrolled back to
     * after the new chapter has painted).
     */
    BB.switchTab = function (name, opts) {
        opts = opts || {};
        if (!VIEWS.includes(name)) name = 'read';
        const was = BB.state.view;
        BB.state.view = name;
        $$('.bb-view').forEach((v) => { v.hidden = v.dataset.view !== name; });
        $$('.bb-tabs [data-tab]').forEach((b) => {
            const on = b.dataset.tab === name;
            b.classList.toggle('is-active', on);
            b.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        const app = document.getElementById('bibleApp');
        app.dataset.view = name;
        app.classList.remove('bb-chrome-hidden');
        $('#bbProgress').style.width = name === 'read' ? $('#bbProgress').style.width : '0';
        BB.reader.hideBar();
        if (name !== 'read') {
            const m = document.querySelector('main');
            if (m) m.scrollTo({ top: 0 });
        } else if (BB.state.data && was !== 'read' && opts.restore !== false) {
            requestAnimationFrame(() => BB.reader.scrollToVerse(BB.state.verse || 1, { flash: false }));
        }
        if (BB.study) BB.study.onTab(name);
    };
    // assets/js/tab-deeplink.js calls window.switchTab for "#tab=notes" links
    // (the command palette lists The Bible's tabs).
    window.switchTab = (name) => BB.switchTab(name);

    // ------------------------------------------------------------------ streak chip
    function showStreak(s) {
        BB.state.streak = s;
        const chip = $('#bbStreakChip');
        if (!s || (!s.current && !s.today)) { chip.hidden = true; return; }
        chip.hidden = false;
        chip.classList.toggle('is-lit', !!s.today);
        $('#bbStreakNum').textContent = s.current;
        chip.title = s.current + (s.current === 1 ? ' day' : ' days') + ' in a row' + (s.today ? ' — read today' : ' — read today to keep it');
    }
    BB.on('streak', showStreak);

    // ------------------------------------------------------------------ book picker
    const picker = { testament: 'OT', book: null };

    function renderBooks() {
        const groups = {};
        BB.books.filter((b) => b.testament === picker.testament).forEach((b) => {
            (groups[b.group] = groups[b.group] || []).push(b);
        });
        $('#bbBookGrid').innerHTML = Object.keys(groups).map((g) =>
            '<div class="bb-book-group"><h5>' + esc(g) + '</h5><div>'
            + groups[g].map((b) => '<button type="button" data-book="' + b.id + '" class="' + (b.id === BB.state.book ? 'is-current' : '') + '">' + esc(b.name) + '</button>').join('')
            + '</div></div>'
        ).join('');
        $$('#bbTestament button').forEach((x) => x.classList.toggle('is-on', x.dataset.t === picker.testament));
    }

    // Centre the current book in the picker's scroll area (or go to the top
    // when it is not in the listed testament).
    function scrollToCurrentBook() {
        const body = $('#bbBookGrid').closest('.bb-sheet-body');
        if (!body) return;
        const cur = $('#bbBookGrid .is-current');
        if (!cur) { body.scrollTop = 0; return; }
        const offset = cur.getBoundingClientRect().top - body.getBoundingClientRect().top;
        body.scrollTop += offset - (body.clientHeight - cur.offsetHeight) / 2;
    }

    function showChapters(bookId) {
        const b = BB.book(bookId);
        if (!b) return;
        // One-chapter books go straight in.
        if (b.chapters === 1) {
            BB.closeModal('bibleBookModal');
            BB.switchTab('read', { restore: false });
            BB.reader.load(BB.state.translation, b.id, 1, { push: true });
            return;
        }
        picker.book = b.id;
        $('#bbChapterBook').textContent = b.name;
        let html = '';
        for (let c = 1; c <= b.chapters; c++) {
            html += '<button type="button" data-chapter="' + c + '" class="' + (b.id === BB.state.book && c === BB.state.chapter ? 'is-current' : '') + '">' + c + '</button>';
        }
        $('#bbChapterGrid').innerHTML = html;
        $('#bbBookGrid').hidden = true;
        $('#bbTestament').hidden = true;
        $('#bbChapterPane').hidden = false;
    }

    function openPicker() {
        const cur = BB.book(BB.state.book);
        picker.testament = cur ? cur.testament : 'OT';
        $('#bbGotoInput').value = '';
        renderBooks();
        $('#bbBookGrid').hidden = false;
        $('#bbTestament').hidden = false;
        $('#bbChapterPane').hidden = true;
        BB.openModal('bibleBookModal');
        // One-chapter books have no chapter grid (showChapters would close the
        // picker again), so they leave the book list showing.
        if (cur && cur.chapters > 1) showChapters(cur.id);
        else scrollToCurrentBook();
    }

    async function goTo(text) {
        const q = text.trim();
        if (!q) return;
        // A bare book name: show its chapters.
        const lower = q.toLowerCase();
        const exact = BB.books.find((b) => b.name.toLowerCase() === lower || b.short.toLowerCase().replace(/\s/g, '') === lower.replace(/\s/g, ''));
        if (exact && !/\d+\s*$/.test(q.replace(/^[123]\s*/, ''))) { showChapters(exact.id); return; }
        try {
            const res = await BB.api.get('search', { q: q, t: 'KJV' });
            if (res.ref) {
                BB.closeModal('bibleBookModal');
                BB.switchTab('read', { restore: false });
                BB.reader.load(BB.state.translation, res.ref.book, res.ref.chapter, { verse: res.ref.verse || null, push: true });
                return;
            }
            const starts = BB.books.filter((b) => b.name.toLowerCase().startsWith(lower));
            if (starts.length === 1) { showChapters(starts[0].id); return; }
            BB.toast('We could not find “' + q + '”. Try “John 3” or “Psalm 23:1”.', 'error');
        } catch (err) {
            BB.toast(err.message, 'error');
        }
    }

    function bindPicker() {
        $('#bbRefBtn').addEventListener('click', openPicker);
        $('#bbTestament').addEventListener('click', (e) => {
            const b = e.target.closest('[data-t]');
            if (!b) return;
            picker.testament = b.dataset.t;
            renderBooks();
            scrollToCurrentBook();
        });
        $('#bbBookGrid').addEventListener('click', (e) => {
            const b = e.target.closest('[data-book]');
            if (b) showChapters(Number(b.dataset.book));
        });
        $('#bbChapterBack').addEventListener('click', () => {
            $('#bbChapterPane').hidden = true;
            $('#bbBookGrid').hidden = false;
            $('#bbTestament').hidden = false;
            renderBooks();
            scrollToCurrentBook();
        });
        $('#bbChapterGrid').addEventListener('click', (e) => {
            const b = e.target.closest('[data-chapter]');
            if (!b) return;
            BB.closeModal('bibleBookModal');
            BB.switchTab('read', { restore: false });
            BB.reader.load(BB.state.translation, picker.book, Number(b.dataset.chapter), { push: true });
        });
        $('#bbGotoForm').addEventListener('submit', (e) => { e.preventDefault(); goTo($('#bbGotoInput').value); });
    }

    // ------------------------------------------------------------------ translations
    function renderTranslations() {
        $('#bbTrList').innerHTML = BB.state.translations.map((t) => {
            const sub = t.available ? (t.reason || (t.source === 'local' ? 'Public domain · loads instantly' : 'Licensed through API.Bible')) : t.reason;
            return '<button type="button" class="bb-tr-item' + (t.code === BB.state.translation ? ' is-current' : '') + '" data-code="' + t.code + '"' + (t.available ? '' : ' disabled') + '>'
                + '<span class="bb-tr-code">' + esc(t.code) + '</span>'
                + '<span><span class="bb-tr-name">' + esc(t.name) + '</span><div class="bb-tr-sub">' + esc(sub) + '</div></span></button>';
        }).join('');
        $('#bbAdminOpen').hidden = !BB.isAdmin;
    }

    function bindTranslations() {
        $('#bbTrBtn').addEventListener('click', () => { renderTranslations(); BB.openModal('bibleTranslationModal'); });
        $('#bbTrList').addEventListener('click', (e) => {
            const b = e.target.closest('[data-code]');
            if (!b || b.disabled) return;
            BB.closeModal('bibleTranslationModal');
            const code = b.dataset.code;
            if (code === BB.state.translation && BB.state.data) return;
            const verse = BB.state.view === 'read' ? BB.reader.topVerse() : BB.state.verse;
            BB.switchTab('read', { restore: false });
            BB.reader.load(code, BB.state.book, BB.state.chapter, { verse: verse > 1 ? verse : null, flash: false });
        });
        $('#bbAdminOpen').addEventListener('click', openAdmin);
    }

    // ------------------------------------------------------------------ display
    function syncDisplay() {
        const p = BB.state.prefs;
        $$('#bbThemes button').forEach((b) => b.classList.toggle('is-on', b.dataset.theme === (p.theme || 'paper')));
        $('#bbSize').value = p.size || 19;
        $('#bbSizeOut').textContent = (p.size || 19) + 'px';
        $$('#bbFont button').forEach((b) => b.classList.toggle('is-on', b.dataset.font === (p.font || 'serif')));
        $$('#bbLeading button').forEach((b) => b.classList.toggle('is-on', Math.abs(Number(b.dataset.leading) - (p.leading || 1.8)) < 0.05));
        $$('#bbLayout button').forEach((b) => b.classList.toggle('is-on', b.dataset.layout === (p.layout || 'para')));
        $('#bbOptRed').checked = p.red !== false;
        $('#bbOptNumbers').checked = p.numbers !== false;
        $('#bbOptHeadings').checked = p.headings !== false;
    }

    function bindDisplay() {
        $('#bbDisplayBtn').addEventListener('click', () => { syncDisplay(); BB.openModal('bibleDisplayModal'); });
        const seg = (sel, key, cast) => $(sel).addEventListener('click', (e) => {
            const b = e.target.closest('button');
            if (!b) return;
            const val = cast ? cast(b.dataset[key]) : b.dataset[key];
            BB.setPrefs({ [key]: val });
            syncDisplay();
        });
        seg('#bbThemes', 'theme');
        seg('#bbFont', 'font');
        seg('#bbLeading', 'leading', Number);
        seg('#bbLayout', 'layout');
        $('#bbSize').addEventListener('input', (e) => {
            const v = Number(e.target.value);
            $('#bbSizeOut').textContent = v + 'px';
            BB.state.prefs.size = v;
            BB.applyPrefs();
        });
        $('#bbSize').addEventListener('change', (e) => BB.setPrefs({ size: Number(e.target.value) }));
        $('#bbOptRed').addEventListener('change', (e) => BB.setPrefs({ red: e.target.checked }));
        $('#bbOptNumbers').addEventListener('change', (e) => BB.setPrefs({ numbers: e.target.checked }));
        $('#bbOptHeadings').addEventListener('change', (e) => BB.setPrefs({ headings: e.target.checked }));
    }

    // ------------------------------------------------------------------ admin
    async function openAdmin() {
        BB.closeModal('bibleTranslationModal');
        $('#bbAdminBody').innerHTML = '<p class="bb-fine">Checking…</p>';
        BB.openModal('bibleAdminModal');
        await renderAdmin('admin_status');
    }

    async function renderAdmin(action) {
        try {
            const s = action === 'admin_refresh' ? await BB.api.post(action, {}) : await BB.api.get(action);
            const resolved = Object.keys(s.resolved).map((code) => {
                const r = s.resolved[code];
                return '<li><b>' + esc(code) + '</b>: ' + (r ? '<span class="bb-ok">ready</span> — ' + esc(r.name) + ' <small>(' + esc(r.id) + (r.pinned ? ', pinned in .env' : '') + ')</small>' : '<span class="bb-bad">not found on this key</span>') + '</li>';
            }).join('');
            const cache = (s.cache || []).map((c) => esc(c.translation) + ': ' + c.n + ' chapters').join(' · ') || 'empty';
            $('#bbAdminBody').innerHTML = '<div class="bb-admin"><dl>'
                + '<dt>API key</dt><dd>' + (s.key_set ? '<span class="bb-ok">set</span>' : '<span class="bb-bad">missing — add BIBLE_API_KEY to .env</span>') + '</dd>'
                + '<dt>Calls this month</dt><dd>' + s.used.toLocaleString() + ' of ' + s.cap.toLocaleString() + ' (' + esc(s.month) + ')</dd>'
                + '<dt>Catalogue</dt><dd>' + (s.catalog_at ? 'checked ' + esc(s.catalog_at) : 'not fetched yet') + (s.catalog_err ? ' — <span class="bb-bad">' + esc(s.catalog_err) + '</span>' : '') + '</dd>'
                + '<dt>Cache</dt><dd>' + cache + ' (kept ' + s.cache_days + ' days)</dd>'
                + '</dl><ul>' + resolved + '</ul>'
                + (s.available && s.available.length
                    ? '<details><summary>' + s.available.length + ' English Bibles this key can read</summary><ul>'
                        + s.available.map((a) => '<li>' + esc(a) + '</li>').join('') + '</ul></details>'
                    : '')
                + '</div>';
        } catch (err) {
            $('#bbAdminBody').innerHTML = '<p class="bb-fine">' + esc(err.message) + '</p>';
        }
    }

    // ------------------------------------------------------------------ start
    function parseHash() {
        const raw = (window.location.hash || '').replace(/^#/, '');
        if (!raw) return {};
        try {
            const p = new URLSearchParams(raw);
            const out = {};
            const read = p.get('read');
            if (read) {
                const [b, c, v] = read.split('.').map(Number);
                if (b && c) Object.assign(out, { book: b, chapter: c, verse: v || null });
            }
            const t = p.get('t');
            if (t) out.translation = t.toUpperCase();
            if (p.get('tab')) out.tab = p.get('tab');
            return out;
        } catch (e) {
            return {};
        }
    }

    function start() {
        BB.reader.bind();
        if (BB.voice) BB.voice.bind();
        if (BB.share) BB.share.bind();
        if (BB.study) BB.study.bind();
        bindPicker();
        bindTranslations();
        bindDisplay();

        $$('.bb-tabs [data-tab]').forEach((b) => b.addEventListener('click', () => BB.switchTab(b.dataset.tab)));
        $('#bbStreakChip').addEventListener('click', () => BB.switchTab('plans'));
        showStreak(BB.state.streak);

        const h = parseHash();
        const s = BB.state;
        const first = BB.cfg.first;
        let t = s.translation;
        if (h.translation) {
            const tr = BB.translation(h.translation);
            if (tr && tr.available) t = h.translation;
        }
        const b = h.book || s.book;
        const c = h.chapter || s.chapter;
        const v = h.verse || (h.book ? null : (s.verse > 1 ? s.verse : null));

        const useFirst = first && first.translation === t && first.book === b && first.chapter === c;
        BB.reader.load(t, b, c, { data: useFirst ? first : null, verse: v, flash: !!h.verse });

        window.addEventListener('popstate', () => {
            const p = parseHash();
            if (p.book && p.chapter) {
                BB.switchTab('read', { restore: false });
                BB.reader.load(p.translation || BB.state.translation, p.book, p.chapter, { verse: p.verse || null });
            }
        });

        if (h.tab && h.tab !== 'read') BB.switchTab(h.tab);
        $('#bbAdminRefresh').addEventListener('click', () => renderAdmin('admin_refresh'));
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
    else start();
})();
