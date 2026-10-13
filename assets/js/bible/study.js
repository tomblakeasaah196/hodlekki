// /assets/js/bible/study.js
// The Bible — everything beyond the page itself: Search, My study (notes,
// journal, highlights, tags, PDF), the note editor, and Plans & streaks.
(function () {
    'use strict';

    const BB = window.HODBible;
    if (!BB) return;
    const $ = BB.$;
    const $$ = BB.$$;
    const esc = BB.esc;

    function notReadyCard(what) {
        return '<div class="bb-empty"><strong>Almost ready</strong>' + esc(what) + ' are being set up and will appear after the next update. Reading the Bible works already.</div>';
    }

    /** Escape a verse and wrap the search terms in <mark>, matching on the raw text. */
    function markTerms(text, terms) {
        const parts = (terms || []).filter(Boolean).map((t) => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/\s+/g, '\\s+'));
        if (!parts.length) return esc(text);
        const re = new RegExp('(^|[^A-Za-z])(' + parts.join('|') + ')', 'gi');
        let out = '';
        let last = 0;
        let m;
        while ((m = re.exec(text)) !== null) {
            const start = m.index + m[1].length;
            out += esc(text.slice(last, start)) + '<mark>' + esc(m[2]) + '</mark>';
            last = start + m[2].length;
            if (m[0].length === 0) re.lastIndex++;
        }
        return out + esc(text.slice(last));
    }

    /** Open a passage in the reader. */
    function openPassage(t, b, c, v) {
        BB.switchTab('read', { restore: false });
        const tr = BB.translation(t);
        const use = tr && tr.available ? t : BB.state.translation;
        BB.reader.load(use, b, c, { verse: v || null, push: true, dir: null });
    }
    BB.openPassage = openPassage;

    // =====================================================================
    // SEARCH
    // =====================================================================
    const search = { q: '', scope: 'all', page: 1, translation: null, busy: false, bookOverride: 0 };

    function fillSearchTranslations() {
        const sel = $('#bbSearchTr');
        sel.innerHTML = BB.state.translations
            .filter((t) => t.available)
            .map((t) => '<option value="' + t.code + '">' + t.code + '</option>')
            .join('');
        sel.value = search.translation || BB.state.translation;
        if (!sel.value) sel.value = 'KJV';
    }

    function searchScopeBook() {
        return search.bookOverride || BB.state.book;
    }

    function searchScope() {
        if (search.scope === 'book') return 'book:' + searchScopeBook();
        return search.scope;
    }

    async function runSearch(append) {
        const q = $('#bbSearchQ').value.trim();
        if (!q) {
            $('#bbSearchMeta').textContent = '';
            $('#bbSearchResults').innerHTML = '';
            $('#bbSearchBooks').innerHTML = '';
            $('#bbSearchMore').hidden = true;
            return;
        }
        if (search.busy) return;
        search.busy = true;
        if (!append) search.page = 1;
        search.q = q;
        search.translation = $('#bbSearchTr').value || 'KJV';
        const list = $('#bbSearchResults');
        if (!append) {
            $('#bbSearchMeta').textContent = 'Searching…';
            list.innerHTML = '';
        }
        try {
            const res = await BB.api.get('search', { q: q, t: search.translation, scope: searchScope(), page: search.page });
            if (res.fums_token) BB.fums(res.fums_token);
            let html = '';
            if (res.ref && !append) {
                html += '<button type="button" class="bb-card" data-go="' + res.ref.book + '.' + res.ref.chapter + '.' + (res.ref.verse || '') + '">'
                    + '<div class="bb-card-top"><span class="bb-card-kind">Go to</span></div>'
                    + '<p class="bb-card-title">' + esc(res.ref.label) + '</p></button>';
            }
            (res.results || []).forEach((r) => {
                html += '<button type="button" class="bb-card" data-go="' + r.book + '.' + r.chapter + '.' + r.verse + '">'
                    + '<div class="bb-card-top"><span class="bb-card-ref">' + esc(r.ref) + '</span></div>'
                    + '<p class="bb-card-text">' + markTerms(r.text, res.terms) + '</p></button>';
            });
            if (append) list.insertAdjacentHTML('beforeend', html);
            else list.innerHTML = html || '<div class="bb-empty"><strong>No verses found</strong>Try fewer words, or check the spelling. KJV words like “saith” and “thee” work too.</div>';

            const total = res.total || 0;
            if (!append) {
                const scopeLabel = { all: 'the whole Bible', ot: 'the Old Testament', nt: 'the New Testament' }[search.scope]
                    || (BB.book(searchScopeBook()) ? BB.book(searchScopeBook()).name : 'this book');
                $('#bbSearchMeta').textContent = total
                    ? total.toLocaleString() + (total === 1 ? ' verse' : ' verses') + ' in ' + scopeLabel + ' (' + res.translation + ')'
                    : (res.ref ? '' : '');
                const byBook = res.by_book || {};
                const keys = Object.keys(byBook);
                $('#bbSearchBooks').innerHTML = keys.length > 1
                    ? keys.map((k) => '<button type="button" class="bb-chip" data-book="' + k + '">' + esc((BB.book(k) || {}).name || k) + ' <em>' + byBook[k] + '</em></button>').join('')
                    : '';
            }
            $('#bbSearchMore').hidden = !(total > search.page * (res.per_page || 30));
        } catch (err) {
            $('#bbSearchMeta').textContent = '';
            list.innerHTML = '<div class="bb-empty"><strong>Search did not work</strong>' + esc(err.message) + '</div>';
        } finally {
            search.busy = false;
        }
    }

    function bindSearch() {
        $('#bbSearchForm').addEventListener('submit', (e) => { e.preventDefault(); runSearch(false); });
        $('#bbSearchQ').addEventListener('search', () => runSearch(false));
        $('#bbSearchTr').addEventListener('change', () => { if ($('#bbSearchQ').value.trim()) runSearch(false); });
        $('#bbSearchScope').addEventListener('click', (e) => {
            const b = e.target.closest('[data-scope]');
            if (!b) return;
            search.scope = b.dataset.scope;
            search.bookOverride = 0;
            const book = BB.book(BB.state.book);
            $('#bbScopeBook').textContent = book ? book.name : 'This book';
            $$('#bbSearchScope .bb-chip').forEach((x) => x.classList.toggle('is-on', x === b));
            if ($('#bbSearchQ').value.trim()) runSearch(false);
        });
        // "Psalms 59" chips under a search: narrow to that book.
        $('#bbSearchBooks').addEventListener('click', (e) => {
            const b = e.target.closest('[data-book]');
            if (!b) return;
            search.scope = 'book';
            search.bookOverride = Number(b.dataset.book);
            const book = BB.book(search.bookOverride);
            $('#bbScopeBook').textContent = book ? book.name : 'This book';
            $$('#bbSearchScope .bb-chip').forEach((x) => x.classList.toggle('is-on', x.dataset.scope === 'book'));
            runSearch(false);
        });
        $('#bbSearchResults').addEventListener('click', (e) => {
            const card = e.target.closest('[data-go]');
            if (!card) return;
            const [b, c, v] = card.dataset.go.split('.');
            openPassage(search.translation || BB.state.translation, Number(b), Number(c), v ? Number(v) : null);
        });
        $('#bbSearchMore').addEventListener('click', () => { search.page++; runSearch(true); });
    }

    // =====================================================================
    // MY STUDY (notes, journal, highlights)
    // =====================================================================
    const notes = { kind: '', q: '', tag: '', book: 0, color: '', page: 1, tags: [], loaded: false };

    function noteCard(n) {
        const date = BB.dateLabel(n.updated_at);
        const tags = n.tags.length ? '<div class="bb-card-tags">' + n.tags.map((t) => '<span class="bb-tag">#' + esc(t) + '</span>').join('') + '</div>' : '';
        if (n.kind === 'journal') {
            const refs = n.refs.length
                ? '<div class="bb-card-tags">' + n.refs.map((r) => '<button type="button" class="bb-chip" data-ref="' + r.book + '.' + r.chapter + '.' + (r.verse || '') + '">' + esc(r.ref) + '</button>').join('') + '</div>'
                : '';
            return '<article class="bb-card" data-note="' + n.id + '" tabindex="0">'
                + '<div class="bb-card-top"><span class="bb-card-kind">Journal</span><span class="bb-card-date">' + esc(date) + '</span></div>'
                + '<p class="bb-card-title">' + esc(n.title || 'Untitled entry') + '</p>'
                + (n.body ? '<p class="bb-card-body">' + esc(n.body) + '</p>' : '')
                + refs + tags + '</article>';
        }
        const a = n.anchor || {};
        return '<article class="bb-card" data-note="' + n.id + '" tabindex="0">'
            + '<div class="bb-card-top"><button type="button" class="bb-card-ref" data-ref="' + a.book + '.' + a.chapter + '.' + a.vs + '" data-t="' + esc(a.translation || '') + '">' + esc(a.ref || '') + '</button>'
            + '<span class="bb-card-kind">' + esc(a.translation || '') + '</span><span class="bb-card-date">' + esc(date) + '</span></div>'
            + (n.quote ? '<p class="bb-card-quote">' + esc(n.quote) + '</p>' : '')
            + '<p class="bb-card-body">' + esc(n.body) + '</p>' + tags + '</article>';
    }

    function hlCard(h) {
        return '<article class="bb-card bb-card-hl c-' + esc(h.color) + '" data-hl="' + h.id + '" data-ref="' + h.book + '.' + h.chapter + '.' + h.vs + '" data-t="' + esc(h.translation) + '" tabindex="0">'
            + '<div class="bb-card-top"><span class="bb-card-ref">' + esc(h.ref) + '</span><span class="bb-card-kind">' + esc(h.translation) + '</span>'
            + '<span class="bb-card-date">' + esc(BB.dateLabel(h.created_at)) + '</span></div>'
            + (h.quote ? '<p class="bb-card-text">' + esc(h.quote) + '</p>' : '<p class="bb-card-body">Open the passage to see your highlight.</p>')
            + '<div class="bb-card-tags"><button type="button" class="bb-chip" data-unhl="' + h.id + '">Remove</button></div>'
            + '</article>';
    }

    function emptyStudy() {
        if (notes.kind === 'highlights') {
            return '<div class="bb-empty"><strong>No highlights yet</strong>Select any words while reading and tap a colour.</div>';
        }
        if (notes.q || notes.tag || notes.book) {
            return '<div class="bb-empty"><strong>Nothing matches</strong>Try another word or clear the filters.</div>';
        }
        return '<div class="bb-empty"><strong>Your study starts here</strong>Select words while reading and tap the pencil to write a note, or start a journal entry for a sermon or a study.</div>';
    }

    async function loadNotes(append) {
        const list = $('#bbNotesList');
        if (!BB.ready) { list.innerHTML = notReadyCard('Notes and highlights'); return; }
        if (!append) notes.page = 1;
        try {
            let html = '';
            let more = false;
            if (notes.kind === 'highlights') {
                const res = await BB.api.get('highlights', { color: notes.color, book: notes.book, page: notes.page });
                html = res.items.map(hlCard).join('');
                more = res.total > notes.page * res.per_page;
                if (!append && !res.items.length) html = emptyStudy();
            } else {
                const res = await BB.api.get('notes', { kind: notes.kind, q: notes.q, tag: notes.tag, book: notes.book, page: notes.page });
                notes.tags = res.tags || [];
                renderTagChips();
                html = res.items.map(noteCard).join('');
                more = res.total > notes.page * res.per_page;
                if (!append && !res.items.length) html = emptyStudy();
            }
            if (append) list.insertAdjacentHTML('beforeend', html);
            else list.innerHTML = html;
            $('#bbNotesMore').hidden = !more;
            notes.loaded = true;
        } catch (err) {
            list.innerHTML = err.code === 'FEATURE_NOT_READY' ? notReadyCard('Notes and highlights') : '<div class="bb-empty"><strong>Could not load</strong>' + esc(err.message) + '</div>';
        }
    }

    function renderTagChips() {
        const box = $('#bbTagChips');
        if (notes.kind === 'highlights' || !notes.tags.length) { box.innerHTML = ''; return; }
        box.innerHTML = notes.tags.map((t) =>
            '<button type="button" class="bb-chip' + (notes.tag === t.name ? ' is-on' : '') + '" data-tag="' + esc(t.name) + '">#' + esc(t.name) + ' <em>' + t.count + '</em></button>'
        ).join('');
    }

    function exportUrl() {
        const what = { '': 'all', verse: 'notes', journal: 'journal', highlights: 'highlights' }[notes.kind] || 'all';
        const qs = new URLSearchParams({ what: what });
        if (notes.tag) qs.set('tag', notes.tag);
        if (notes.book) qs.set('book', String(notes.book));
        return '/api/bible_export.php?' + qs.toString();
    }

    function bindNotes() {
        const bookSel = $('#bbNotesBook');
        bookSel.insertAdjacentHTML('beforeend', BB.books.map((b) => '<option value="' + b.id + '">' + esc(b.name) + '</option>').join(''));
        bookSel.addEventListener('change', () => { notes.book = Number(bookSel.value); loadNotes(false); });

        $('#bbHlColors').innerHTML = '<button type="button" class="is-on" data-color="" title="All colours" style="background:conic-gradient(#FACC15,#4ADE80,#60A5FA,#F472B6,#A78BFA,#FACC15)"></button>'
            + BB.colors.map((c) => '<button type="button" class="bb-sw-' + c + '" data-color="' + c + '" title="' + c + '"></button>').join('');
        $('#bbHlColors').addEventListener('click', (e) => {
            const b = e.target.closest('[data-color]');
            if (!b) return;
            notes.color = b.dataset.color;
            $$('#bbHlColors button').forEach((x) => x.classList.toggle('is-on', x === b));
            loadNotes(false);
        });

        $('#bbNotesKind').addEventListener('click', (e) => {
            const b = e.target.closest('[data-kind]');
            if (!b) return;
            notes.kind = b.dataset.kind;
            $$('#bbNotesKind button').forEach((x) => x.classList.toggle('is-on', x === b));
            $('#bbHlColors').hidden = notes.kind !== 'highlights';
            $('#bbNotesQ').closest('.bb-search-box').hidden = notes.kind === 'highlights';
            loadNotes(false);
        });
        $('#bbNotesQ').addEventListener('input', BB.debounce(() => { notes.q = $('#bbNotesQ').value.trim(); loadNotes(false); }, 320));
        $('#bbTagChips').addEventListener('click', (e) => {
            const b = e.target.closest('[data-tag]');
            if (!b) return;
            notes.tag = notes.tag === b.dataset.tag ? '' : b.dataset.tag;
            loadNotes(false);
        });
        $('#bbNotesMore').addEventListener('click', () => { notes.page++; loadNotes(true); });
        $('#bbNewJournal').addEventListener('click', () => openJournal());
        $('#bbExportBtn').addEventListener('click', () => {
            if (!BB.ready) { BB.toast('Notes are being set up — try again after the next update.', 'info'); return; }
            window.location.href = exportUrl();
        });

        $('#bbNotesList').addEventListener('click', async (e) => {
            const un = e.target.closest('[data-unhl]');
            if (un) {
                e.stopPropagation();
                try {
                    await BB.api.post('highlight_delete', { id: Number(un.dataset.unhl) });
                    un.closest('.bb-card').remove();
                    BB.reader.invalidate();
                    BB.emit('study-changed');
                } catch (err) { BB.toast(err.message, 'error'); }
                return;
            }
            const ref = e.target.closest('[data-ref]');
            if (ref) {
                e.stopPropagation();
                const [b, c, v] = ref.dataset.ref.split('.');
                openPassage(ref.dataset.t || BB.state.translation, Number(b), Number(c), v ? Number(v) : null);
                return;
            }
            const card = e.target.closest('[data-note]');
            if (card) openNote(Number(card.dataset.note));
        });
        $('#bbNotesList').addEventListener('keydown', (e) => {
            if (e.key !== 'Enter') return;
            const card = e.target.closest('[data-note], [data-hl]');
            if (card) card.click();
        });
    }

    // =====================================================================
    // NOTE EDITOR
    // =====================================================================
    const ed = { id: 0, kind: 'verse', anchor: null, tags: [], quoteRef: '' };

    function renderEditorTags() {
        const box = $('#bbNoteTags');
        $$('.bb-tag', box).forEach((t) => t.remove());
        const input = $('#bbNoteTagInput');
        ed.tags.forEach((t) => {
            const chip = document.createElement('span');
            chip.className = 'bb-tag';
            chip.innerHTML = '#' + esc(t) + ' <button type="button" aria-label="Remove tag ' + esc(t) + '" data-untag="' + esc(t) + '">×</button>';
            box.insertBefore(chip, input);
        });
        const sugg = notes.tags.map((t) => t.name).filter((n) => !ed.tags.includes(n)).slice(0, 12);
        $('#bbTagSuggest').innerHTML = sugg.map((n) => '<button type="button" class="bb-chip" data-addtag="' + esc(n) + '">#' + esc(n) + '</button>').join('');
    }

    function cleanTag(raw) {
        return String(raw || '').toLowerCase().replace(/^#/, '').replace(/[^\p{L}\p{N}\s&-]/gu, '').replace(/\s+/g, ' ').trim().slice(0, 40);
    }

    function addTag(raw) {
        const t = cleanTag(raw);
        if (!t || ed.tags.includes(t) || ed.tags.length >= 12) return;
        ed.tags.push(t);
        renderEditorTags();
    }

    function fillEditor(o) {
        ed.id = o.id || 0;
        ed.kind = o.kind;
        ed.anchor = o.anchor || null;
        ed.tags = (o.tags || []).slice();
        const journal = o.kind === 'journal';
        $('#bibleNoteTitle').textContent = o.heading;
        const quote = $('#bbNoteQuote');
        if (o.quote) {
            quote.innerHTML = esc(o.quote) + (o.cite ? '<cite>' + esc(o.cite) + '</cite>' : '');
            quote.hidden = false;
        } else if (o.cite) {
            quote.innerHTML = '<cite>' + esc(o.cite) + '</cite>';
            quote.hidden = false;
        } else {
            quote.hidden = true;
        }
        $('#bbNoteTitleInput').hidden = !journal;
        $('#bbNoteTitleInput').value = o.title || '';
        $('#bbNoteBody').value = o.body || '';
        $('#bbNoteBody').placeholder = journal ? 'Write your study, sermon notes or prayer…' : 'What is God saying to you here?';
        $('label[for="bbNoteRefs"]').hidden = !journal;
        $('#bbNoteRefs').hidden = !journal;
        $('#bbNoteRefs').value = o.refs || '';
        $('#bbNoteTagInput').value = '';
        $('#bbNoteDelete').hidden = !ed.id;
        $('#bbNotePdf').hidden = !ed.id;
        renderEditorTags();
        BB.openModal('bibleNoteModal');
        // modal-manager.js puts focus on the dialog's Close button first. With
        // a mouse and keyboard, move on to the writing field once it has; on
        // phones leave it, so the keyboard does not cover the quoted verse.
        if (!window.matchMedia('(pointer: coarse)').matches) {
            const field = journal && !o.title ? $('#bbNoteTitleInput') : $('#bbNoteBody');
            setTimeout(() => field.focus({ preventScroll: true }), 40);
        }
    }

    function openNoteForAnchor(a) {
        if (!BB.ready) { BB.toast('Notes are being set up — try again after the next update.', 'info'); return; }
        fillEditor({
            kind: 'verse',
            heading: 'Note on ' + BB.ref(a.book, a.chapter, a.vs, a.ve),
            quote: a.text,
            cite: BB.ref(a.book, a.chapter, a.vs, a.ve) + ' · ' + a.translation,
            anchor: [a.translation, a.book, a.chapter, a.vs, a.os, a.ve, a.oe],
            tags: [],
        });
    }

    function openJournal() {
        if (!BB.ready) { BB.toast('The journal is being set up — try again after the next update.', 'info'); return; }
        const d = BB.state.data;
        fillEditor({
            kind: 'journal',
            heading: 'New journal entry',
            refs: d ? d.ref : '',
            tags: notes.tag ? [notes.tag] : [],
        });
    }

    async function openNote(id) {
        if (!id) return;
        try {
            const n = await BB.api.get('note', { id: id });
            if (n.kind === 'journal') {
                fillEditor({
                    id: n.id, kind: 'journal', heading: 'Journal entry', title: n.title, body: n.body,
                    refs: n.refs.map((r) => r.ref).join('; '), tags: n.tags,
                });
            } else {
                const a = n.anchor || {};
                fillEditor({
                    id: n.id, kind: 'verse', heading: 'Note on ' + (a.ref || ''), body: n.body, tags: n.tags,
                    quote: n.quote || quoteFromScreen(a), cite: (a.ref || '') + ' · ' + (a.translation || ''),
                });
            }
        } catch (err) {
            BB.toast(err.message, 'error');
        }
    }

    /** For licensed translations nothing is stored: cut the words from the page if it is open. */
    function quoteFromScreen(a) {
        const d = BB.state.data;
        if (!d || !a || d.book !== a.book || d.chapter !== a.chapter || d.translation !== a.translation) return '';
        const parts = [];
        d.verses.forEach((v) => {
            if (v.v < a.vs || v.v > a.ve) return;
            const ch = BB.chars(v.t);
            const s = v.v === a.vs && a.os != null ? a.os : 0;
            const e = v.v === a.ve && a.oe != null ? a.oe : ch.length;
            parts.push(ch.slice(s, e).join(''));
        });
        return parts.join(' ').trim();
    }

    async function saveNote() {
        const pending = $('#bbNoteTagInput').value;
        if (pending.trim()) { addTag(pending); $('#bbNoteTagInput').value = ''; }
        const body = {
            id: ed.id || undefined,
            kind: ed.kind,
            title: $('#bbNoteTitleInput').value,
            body: $('#bbNoteBody').value,
            tags: ed.tags,
        };
        if (ed.kind === 'journal') body.refs = $('#bbNoteRefs').value;
        if (!ed.id && ed.anchor) body.anchor = ed.anchor;
        const btn = $('#bbNoteSave');
        btn.disabled = true;
        try {
            await BB.api.post('note_save', body);
            BB.closeModal('bibleNoteModal');
            BB.toast('Saved');
            afterStudyChange();
        } catch (err) {
            BB.toast(err.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    async function deleteNote() {
        if (!ed.id) return;
        if (!window.confirm('Delete this ' + (ed.kind === 'journal' ? 'journal entry' : 'note') + '? This cannot be undone.')) return;
        try {
            await BB.api.post('note_delete', { id: ed.id });
            BB.closeModal('bibleNoteModal');
            BB.toast('Deleted');
            afterStudyChange();
        } catch (err) {
            BB.toast(err.message, 'error');
        }
    }

    /** Refresh what is on screen after a note or highlight changed. */
    function afterStudyChange() {
        BB.reader.invalidate();
        const d = BB.state.data;
        if (d) BB.reader.load(d.translation, d.book, d.chapter, { fresh: true, keepScroll: true });
        if (BB.state.view === 'notes') loadNotes(false);
        else notes.loaded = false;
    }

    function bindEditor() {
        $('#bbNoteSave').addEventListener('click', saveNote);
        $('#bbNoteDelete').addEventListener('click', deleteNote);
        $('#bbNotePdf').addEventListener('click', () => { if (ed.id) window.location.href = '/api/bible_export.php?id=' + ed.id; });
        const input = $('#bbNoteTagInput');
        input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ',') {
                e.preventDefault();
                addTag(input.value);
                input.value = '';
            } else if (e.key === 'Backspace' && !input.value && ed.tags.length) {
                ed.tags.pop();
                renderEditorTags();
            }
        });
        input.addEventListener('blur', () => { if (input.value.trim()) { addTag(input.value); input.value = ''; } });
        $('#bbNoteTags').addEventListener('click', (e) => {
            const x = e.target.closest('[data-untag]');
            if (x) { ed.tags = ed.tags.filter((t) => t !== x.dataset.untag); renderEditorTags(); return; }
            input.focus();
        });
        $('#bbTagSuggest').addEventListener('click', (e) => {
            const b = e.target.closest('[data-addtag]');
            if (b) addTag(b.dataset.addtag);
        });
        $('#bbNoteBody').addEventListener('keydown', (e) => {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') { e.preventDefault(); saveNote(); }
        });
        BB.on('study-changed', () => { notes.loaded = false; });
    }

    // =====================================================================
    // PLANS & STREAKS
    // =====================================================================
    const FLAME = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22c4.4 0 7-2.9 7-6.6 0-3.8-2.6-6.1-4.3-8.4-.5 2-1.6 3.2-2.7 3.6.3-3-1.1-6.2-3.8-8.6-.2 3.4-2.3 5.6-3.7 7.6C3.2 11.9 5 22 12 22z"/></svg>';
    let plansData = null;
    let planOpen = null;

    function streakCard(s) {
        if (!s) {
            return '<div class="bb-streak-flame">' + FLAME + '</div><div><div class="bb-streak-num">0<small>days</small></div><div class="bb-streak-sub">Finish a chapter today to start your streak.</div></div>';
        }
        const days = ['S', 'M', 'T', 'W', 'T', 'F', 'S'];
        const week = (s.week || []).map((d, i, all) => {
            const dt = new Date(d.date + 'T12:00:00');
            return '<div>' + days[dt.getDay()] + '<i class="' + (d.read ? 'on' : '') + (i === all.length - 1 ? ' today' : '') + '"></i></div>';
        }).join('');
        const sub = s.today
            ? 'You read today. Come back tomorrow to keep it going.'
            : (s.current > 0 ? 'Read a chapter today to keep your streak.' : 'Finish a chapter today to start a streak.');
        return '<div class="bb-streak-flame' + (s.today ? ' is-lit' : '') + '">' + FLAME + '</div>'
            + '<div><div class="bb-streak-num">' + s.current + '<small>' + (s.current === 1 ? 'day' : 'days') + ' in a row</small></div>'
            + '<div class="bb-streak-sub">' + esc(sub) + ' Best: ' + s.best + ' · ' + (s.chapters || 0).toLocaleString() + ' chapters read</div></div>'
            + '<div class="bb-week">' + week + '</div>';
    }

    function ring(pct) {
        const r = 26;
        const c = 2 * Math.PI * r;
        return '<div class="bb-ring"><svg viewBox="0 0 64 64"><circle class="bg" cx="32" cy="32" r="' + r + '"/>'
            + '<circle class="fg" cx="32" cy="32" r="' + r + '" stroke-dasharray="' + c.toFixed(1) + '" stroke-dashoffset="' + (c * (1 - pct / 100)).toFixed(1) + '"/></svg><b>' + pct + '%</b></div>';
    }

    function planCard(p) {
        const started = new Date(p.start_date + 'T12:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
        let meta = p.status === 'done'
            ? '<span class="bb-plan-done">Completed — well done!</span>'
            : 'Day ' + (p.next ? p.next.day : p.days) + ' of ' + p.days + ' · started ' + esc(started);
        if (p.status !== 'done' && p.behind > 0) meta += ' · ' + p.behind + (p.behind === 1 ? ' day' : ' days') + ' behind — no rush';
        let today = '';
        if (p.next) {
            today = '<div class="bb-plan-today"><div class="bb-plan-meta">Next reading</div><div class="bb-chips">'
                + p.next.chapters.map((c) => '<button type="button" class="bb-chip' + (c.read ? ' is-read' : '') + '" data-read="' + c.book + '.' + c.chapter + '">' + esc(c.ref) + '</button>').join('')
                + '</div></div>';
        }
        const firstUnread = p.next ? (p.next.chapters.find((c) => !c.read) || p.next.chapters[0]) : null;
        return '<div class="bb-plan" data-plan="' + p.id + '">'
            + ring(p.percent)
            + '<h4>' + esc(p.title) + '</h4>'
            + '<div class="bb-plan-meta">' + meta + '</div>'
            + today
            + '<div class="bb-plan-actions">'
            + (firstUnread ? '<button type="button" class="bb-btn bb-btn-primary" data-read="' + firstUnread.book + '.' + firstUnread.chapter + '">Read now</button>' : '')
            + (p.next ? '<button type="button" class="bb-btn" data-dayok="' + p.next.day + '">Mark day ' + p.next.day + ' done</button>' : '')
            + '<button type="button" class="bb-btn" data-days>All days</button>'
            + '</div></div>';
    }

    async function loadPlans() {
        const mine = $('#bbMyPlans');
        const cat = $('#bbPlanCatalog');
        if (!BB.ready) {
            $('#bbStreakCard').innerHTML = streakCard(null);
            mine.innerHTML = notReadyCard('Reading plans');
            cat.innerHTML = '';
            return;
        }
        try {
            plansData = await BB.api.get('plans');
            BB.emit('streak', plansData.streak);
            $('#bbStreakCard').innerHTML = streakCard(plansData.streak);
            const active = plansData.mine;
            mine.innerHTML = active.length
                ? active.map(planCard).join('')
                : '<div class="bb-empty"><strong>No plan yet</strong>Pick one below. Each day ticks itself off as you finish its chapters.</div>';
            const activeKeys = active.filter((p) => p.status === 'active').map((p) => p.plan_key);
            cat.innerHTML = plansData.catalog.map((c) =>
                '<div class="bb-plan-card"><h4>' + esc(c.title) + '</h4><p>' + esc(c.summary) + '</p>'
                + '<div class="bb-plan-meta">' + c.days + ' days · ' + c.chapters.toLocaleString() + ' chapters</div>'
                + (activeKeys.includes(c.key)
                    ? '<button type="button" class="bb-btn" disabled>In progress</button>'
                    : '<button type="button" class="bb-btn bb-btn-primary" data-start="' + c.key + '">Start plan</button>')
                + '</div>').join('');
        } catch (err) {
            mine.innerHTML = err.code === 'FEATURE_NOT_READY' ? notReadyCard('Reading plans') : '<div class="bb-empty"><strong>Could not load plans</strong>' + esc(err.message) + '</div>';
        }
    }

    async function setDay(id, day, done) {
        try {
            const res = await BB.api.post('plan_day', { id: id, day: day, done: done });
            if (res.streak) BB.emit('streak', res.streak);
            BB.reader.invalidate();
            return res;
        } catch (err) {
            BB.toast(err.message, 'error');
            return null;
        }
    }

    async function openPlan(id) {
        try {
            const p = await BB.api.get('plan', { id: id });
            planOpen = p;
            $('#biblePlanTitle').textContent = p.title;
            renderPlanModal(p);
            BB.openModal('biblePlanModal');
            setTimeout(() => {
                const next = $('#bbPlanBody .bb-day.is-next');
                if (next) next.scrollIntoView({ block: 'center' });
            }, 80);
        } catch (err) {
            BB.toast(err.message, 'error');
        }
    }

    function renderPlanModal(p) {
        const nextDay = p.next ? p.next.day : 0;
        $('#bbPlanBody').innerHTML = '<p class="bb-fine" style="margin-top:0">' + esc(p.summary) + ' ' + p.done_count + ' of ' + p.days + ' days done.</p><div class="bb-days">'
            + p.schedule.map((d) => {
                const dt = new Date(d.date + 'T12:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
                return '<label class="bb-day' + (d.day === nextDay ? ' is-next' : '') + '">'
                    + '<input type="checkbox" data-day="' + d.day + '"' + (d.done ? ' checked' : '') + '>'
                    + '<small>Day ' + d.day + '<br>' + esc(dt) + '</small><span>' + esc(d.label) + '</span>'
                    + '<button type="button" data-openday="' + d.day + '">Read</button></label>';
            }).join('') + '</div>';
    }

    function bindPlans() {
        $('#bbViewPlans').addEventListener('click', async (e) => {
            const start = e.target.closest('[data-start]');
            if (start) {
                start.disabled = true;
                try {
                    await BB.api.post('plan_start', { key: start.dataset.start });
                    BB.toast('Plan started — your first reading is ready');
                    loadPlans();
                } catch (err) {
                    start.disabled = false;
                    BB.toast(err.message, 'error');
                }
                return;
            }
            const read = e.target.closest('[data-read]');
            if (read) {
                const [b, c] = read.dataset.read.split('.');
                openPassage(BB.state.translation, Number(b), Number(c), null);
                return;
            }
            const card = e.target.closest('[data-plan]');
            if (!card) return;
            const id = Number(card.dataset.plan);
            const ok = e.target.closest('[data-dayok]');
            if (ok) {
                ok.disabled = true;
                const res = await setDay(id, Number(ok.dataset.dayok), true);
                if (res) BB.toast('Day ' + ok.dataset.dayok + ' done. Keep going!');
                loadPlans();
                return;
            }
            if (e.target.closest('[data-days]')) openPlan(id);
        });

        $('#bbPlanBody').addEventListener('change', async (e) => {
            const box = e.target.closest('[data-day]');
            if (!box || !planOpen) return;
            const res = await setDay(planOpen.id, Number(box.dataset.day), box.checked);
            if (res) { planOpen = res; renderPlanModal(res); }
            else box.checked = !box.checked;
            loadPlans();
        });
        $('#bbPlanBody').addEventListener('click', (e) => {
            const b = e.target.closest('[data-openday]');
            if (!b || !planOpen) return;
            e.preventDefault();
            const day = planOpen.schedule.find((d) => d.day === Number(b.dataset.openday));
            if (!day) return;
            BB.closeModal('biblePlanModal');
            openPassage(BB.state.translation, day.first[0], day.first[1], null);
        });
        $('#bbPlanStop').addEventListener('click', async () => {
            if (!planOpen) return;
            if (!window.confirm('Stop “' + planOpen.title + '”? Your progress so far is kept in your reading history.')) return;
            try {
                await BB.api.post('plan_stop', { id: planOpen.id });
                BB.closeModal('biblePlanModal');
                BB.toast('Plan stopped');
                loadPlans();
            } catch (err) {
                BB.toast(err.message, 'error');
            }
        });
        BB.on('plans-changed', () => { if (BB.state.view === 'plans') loadPlans(); });
    }

    // =====================================================================
    function onTab(name) {
        if (name === 'search') {
            fillSearchTranslations();
            const book = BB.book(searchScopeBook());
            $('#bbScopeBook').textContent = book ? book.name : 'This book';
            setTimeout(() => $('#bbSearchQ').focus(), 50);
        } else if (name === 'notes') {
            loadNotes(false);
        } else if (name === 'plans') {
            loadPlans();
        }
    }

    function bind() {
        bindSearch();
        bindNotes();
        bindEditor();
        bindPlans();
    }

    BB.study = {
        bind: bind,
        onTab: onTab,
        openNote: openNote,
        openNoteForAnchor: openNoteForAnchor,
        openJournal: openJournal,
        loadPlans: loadPlans,
    };
})();
