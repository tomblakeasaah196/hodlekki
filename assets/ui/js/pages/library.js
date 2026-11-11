// /assets/ui/js/pages/library.js
// The Charis Library (modules/library/index.php, public layout): browse and
// search the catalogue, open a book (read the PDF, listen to the audiobook,
// reserve a copy, join the waitlist, nudge borrowers, share), and for
// signed-in members their borrowed books, waitlist and reading history.
// Requests go to /api/library_api.php with the same shapes as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/library_api.php';
    var esc = HOD.esc;
    var cfg = HOD.data('library-config') || {};

    var state = { books: [], user: {}, loggedIn: !!cfg.loggedIn, category: '', search: '', current: null };
    var readerPing = null;
    var autoOpen = Number(new URLSearchParams(window.location.search).get('book_id')) || 0;

    function $(sel, scope) { return (scope || document).querySelector(sel); }
    function label(s) { return String(s || '').replace(/_/g, ' '); }
    /** Files and links from the database: only web addresses and paths on this site. */
    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/|\.\.?\/)/i.test(url) ? url : '';
    }
    function cover(path, alt) {
        var url = safeUrl(path);
        return url
            ? '<img class="h-full w-full object-cover motion-safe:transition-transform motion-safe:duration-300 group-hover:scale-[1.03]" src="' + esc(url) + '" alt="' + esc(alt || '') + '" loading="lazy" decoding="async">'
            : '<span class="grid h-full w-full place-items-center text-ink-subtle">' + HOD.icon('book-open') + '</span>';
    }
    function thumb(path) {
        var url = safeUrl(path);
        return '<span class="block h-16 w-11 flex-none overflow-hidden rounded-ui-sm bg-surface-sunken ring-1 ring-line">'
            + (url ? '<img class="h-full w-full object-cover" src="' + esc(url) + '" alt="" loading="lazy">' : '') + '</span>';
    }
    function hasEbook(b) { return b.book_type === 'E-Book' || b.book_type === 'Both'; }
    function hasPhysical(b) { return b.book_type === 'Physical' || b.book_type === 'Both'; }
    function badges(b) {
        var out = [];
        if (hasEbook(b)) out.push('<span class="ui-badge ui-badge--primary">E-book</span>');
        if (b.audiobook_link) out.push('<span class="ui-badge ui-badge--info">Audio</span>');
        if (hasPhysical(b)) out.push(Number(b.available_copies) > 0 ? '<span class="ui-badge ui-badge--success">On the shelf</span>' : '<span class="ui-badge">Waitlist</span>');
        return out.join('');
    }
    function youtubeId(url) {
        var m = /^.*(youtu.be\/|v\/|u\/\w\/|embed\/|watch\?v=|&v=)([^#&?]*).*/.exec(String(url || ''));
        return m && m[2].length === 11 ? m[2] : null;
    }
    function goSignIn() { window.location.href = cfg.loginUrl || '/auth/login.php'; }

    /* ---------- catalogue ---------- */

    function renderCategories(categories) {
        var all = [''].concat(categories || []);
        $('[data-library-categories]').innerHTML = all.map(function (c) {
            return '<button type="button" class="ui-chip" data-action="library.category" data-category="' + esc(c) + '" aria-pressed="' + (c === state.category ? 'true' : 'false') + '">' + esc(c || 'All') + '</button>';
        }).join('');
    }

    function renderGrid() {
        var grid = $('[data-library-grid]');
        grid.removeAttribute('aria-busy');
        var books = state.books;
        $('[data-library-empty]').hidden = books.length > 0;
        grid.hidden = books.length === 0;
        grid.innerHTML = books.map(function (b) {
            return '<button type="button" class="group block min-w-0 rounded-ui-lg text-left" data-action="library.open" data-id="' + esc(b.id) + '">'
                + '<span class="relative block aspect-[2/3] overflow-hidden rounded-ui-lg bg-surface-sunken ring-1 ring-line">' + cover(b.cover_image_path, '') + '</span>'
                + '<span class="mt-2 block truncate text-sm font-semibold text-ink">' + esc(b.title) + '</span>'
                + '<span class="block truncate text-xs text-ink-muted">' + esc(b.author || '') + '</span>'
                + '<span class="mt-1.5 flex flex-wrap gap-1">' + badges(b) + '</span>'
                + '</button>';
        }).join('');
    }

    function renderMine() {
        if (!state.loggedIn || !$('#library-mine')) return;
        var u = state.user || {};
        var none = function (text) { return '<li class="ui-list__item text-sm text-ink-subtle">' + esc(text) + '</li>'; };

        $('[data-library-borrowed]').innerHTML = (u.active_borrows || []).length ? u.active_borrows.map(function (b) {
            var tone = b.status === 'Overdue' ? 'ui-badge--danger' : (b.status === 'Picked_Up' ? 'ui-badge--success' : 'ui-badge--info');
            var end = '';
            if (b.status === 'Picked_Up' && b.extension_status === 'None') end = '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="library.extend" data-id="' + esc(b.id) + '">Ask for more time</button>';
            else if (b.extension_status === 'Pending') end = '<span class="ui-badge ui-badge--warning">More time asked</span>';
            return '<li class="ui-list__item">' + thumb(b.cover_image_path)
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(b.title) + '</span>'
                + '<span class="ui-list__meta">' + (b.due_date ? 'Due ' + esc(HOD.format.date(b.due_date)) + ' · ' : '') + '<span class="ui-badge ' + tone + '">' + esc(label(b.status)) + '</span></span></span>'
                + (end ? '<span class="ui-list__end">' + end + '</span>' : '') + '</li>';
        }).join('') : none('No books borrowed right now.');

        $('[data-library-waitlist]').innerHTML = (u.waitlist || []).length ? u.waitlist.map(function (w) {
            var ready = w.status === 'Notified';
            return '<li class="ui-list__item">' + thumb(w.cover_image_path)
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(w.title) + '</span>'
                + '<span class="ui-list__meta">' + (ready ? 'Kept for you for 48 hours: collect it from the library desk' : 'Waiting for a copy') + '</span></span>'
                + (ready ? '<span class="ui-list__end"><span class="ui-badge ui-badge--success">Ready</span></span>' : '') + '</li>';
        }).join('') : none('You are not waiting for any book.');

        $('[data-library-history]').innerHTML = (u.reading_progress || []).length ? u.reading_progress.map(function (p) {
            var buttons = '';
            if (p.ebook_file_path) buttons += '<button type="button" class="ui-btn ui-btn--soft ui-btn--sm" data-action="library.resume" data-id="' + esc(p.book_id) + '">Read</button>';
            if (p.audiobook_link) buttons += '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="library.open" data-id="' + esc(p.book_id) + '">Listen</button>';
            return '<li class="ui-list__item">' + thumb(p.cover_image_path)
                + '<span class="ui-list__main"><span class="ui-list__title">' + esc(p.title) + '</span>'
                + '<span class="ui-list__meta">' + esc(p.author || '') + (p.last_page_read ? ' · page ' + esc(p.last_page_read) : '') + '</span></span>'
                + (buttons ? '<span class="ui-list__end flex gap-1">' + buttons + '</span>' : '') + '</li>';
        }).join('') : none('Books you read or listen to will appear here.');
    }

    var loading = null;
    function load() {
        if (loading) loading.abort();
        loading = new AbortController();
        $('[data-library-grid]').setAttribute('aria-busy', 'true');
        return HOD.api(API, {
            method: 'GET', action: 'fetch_library', quiet: true, signal: loading.signal,
            data: { search: state.search, category: state.category }
        }).then(function (res) {
            state.books = res.books || [];
            state.user = res.user_data || {};
            state.loggedIn = !!res.is_logged_in;
            renderCategories(res.categories);
            renderGrid();
            renderMine();
            if (autoOpen) {
                var id = autoOpen;
                autoOpen = 0;
                if (find(id)) openBook(id);
            }
        }).catch(function (err) {
            if (err && err.name === 'AbortError') return;
            $('[data-library-grid]').removeAttribute('aria-busy');
            HOD.toast(err.message || 'The library did not load. Check your connection and try again.', 'danger');
        });
    }

    function find(id) {
        id = Number(id);
        return state.books.filter(function (b) { return Number(b.id) === id; })[0] || null;
    }

    /* ---------- one book ---------- */

    function actionButton(text, action, variant, icon, id) {
        return '<button type="button" class="ui-btn ui-btn--' + variant + ' ui-btn--block" data-action="' + action + '" data-id="' + esc(id) + '">'
            + (icon ? HOD.icon(icon) : '') + '<span>' + esc(text) + '</span></button>';
    }

    function bookActions(b) {
        var out = [];
        var u = state.user || {};
        var ebook = hasEbook(b) && safeUrl(b.ebook_file_path);
        if (ebook) {
            var prog = (u.reading_progress || []).filter(function (p) { return Number(p.book_id) === Number(b.id); })[0];
            var page = prog ? Number(prog.last_page_read) || 1 : 1;
            out.push('<div class="grid gap-2 sm:grid-cols-2">'
                + actionButton(page > 1 ? 'Resume at page ' + page : 'Read now', 'library.read', 'primary', 'book-open', b.id)
                + '<a class="ui-btn ui-btn--secondary ui-btn--block" href="' + esc(ebook) + '" download>' + HOD.icon('download') + '<span>Download PDF</span></a>'
                + '</div>');
        }
        if (b.audiobook_link && youtubeId(b.audiobook_link)) {
            out.push(actionButton('Listen to the audiobook', 'library.listen', ebook ? 'secondary' : 'primary', 'music', b.id));
        }
        if (hasPhysical(b)) {
            var mine = (u.active_borrows || []).some(function (x) { return Number(x.book_id) === Number(b.id); });
            var waiting = (u.waitlist || []).some(function (x) { return Number(x.book_id) === Number(b.id); });
            if (!state.loggedIn) {
                out.push('<a class="ui-btn ui-btn--' + (ebook ? 'secondary' : 'primary') + ' ui-btn--block" href="' + esc(cfg.loginUrl || '/auth/login.php') + '">Sign in to borrow a copy</a>');
            } else if (mine) {
                out.push('<div class="ui-banner ui-banner--success"><div class="ui-banner__body"><p class="ui-banner__text">You have a copy of this book.</p></div></div>');
            } else if (waiting) {
                out.push('<div class="ui-banner ui-banner--info"><div class="ui-banner__body"><p class="ui-banner__text">You are on the waitlist for this book.</p></div></div>');
            } else if (Number(b.available_copies) > 0) {
                out.push(actionButton('Reserve a copy', 'library.borrow', ebook ? 'secondary' : 'primary', 'calendar-check', b.id));
            } else {
                out.push('<p class="text-sm text-ink-muted">No copies on the shelf right now.</p>'
                    + '<div class="grid gap-2 sm:grid-cols-2">'
                    + actionButton('Join the waitlist', 'library.waitlist', ebook ? 'secondary' : 'primary', 'clock', b.id)
                    + actionButton('Nudge borrowers', 'library.nudge', 'ghost', 'bell', b.id)
                    + '</div>');
            }
        }
        out.push(actionButton('Share this book', 'library.share', 'ghost', 'send', b.id));
        return out.join('');
    }

    function openBook(id, opener) {
        var b = find(id);
        if (!b) return;
        state.current = b;
        $('#library-book-title').textContent = b.title;
        $('#library-book-desc').textContent = b.author || '';
        $('[data-library-cover]').innerHTML = cover(b.cover_image_path, b.title);
        $('[data-library-meta]').textContent = [b.category, b.estimated_read_time].filter(Boolean).join(' · ');
        $('[data-library-badges]').innerHTML = badges(b);
        $('[data-library-synopsis]').textContent = b.description || 'No synopsis yet.';
        stopAudio();
        $('[data-library-actions]').innerHTML = bookActions(b);
        HOD.dialog.open('library-book', opener);
    }

    function stopAudio() {
        var player = $('[data-library-player]');
        player.innerHTML = '';
        player.hidden = true;
    }

    function listen() {
        var b = state.current;
        var vid = b && youtubeId(b.audiobook_link);
        if (!vid) { HOD.toast('This audiobook link does not work. Please tell the library team.', 'warning'); return; }
        var player = $('[data-library-player]');
        player.innerHTML = '<iframe class="block aspect-video w-full border-0" src="https://www.youtube-nocookie.com/embed/' + encodeURIComponent(vid)
            + '?autoplay=1&rel=0" title="Audiobook: ' + esc(b.title) + '" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe>';
        player.hidden = false;
    }

    function saveProgress(bookId, page) {
        if (!state.loggedIn) return;
        HOD.api(API, { action: 'save_progress', data: { book_id: bookId, page: page }, quiet: true }).catch(function () {});
    }

    function read(bookId) {
        var b = find(bookId) || {};
        var prog = ((state.user || {}).reading_progress || []).filter(function (p) { return Number(p.book_id) === Number(bookId); })[0];
        var url = safeUrl(b.ebook_file_path || (prog && prog.ebook_file_path));
        if (!url) return;
        var page = prog ? Number(prog.last_page_read) || 1 : 1;
        var title = b.title || (prog && prog.title) || 'Book';
        saveProgress(bookId, page);

        // Phones and tablets read PDFs best in their own viewer.
        if (window.matchMedia('(max-width: 767px), (pointer: coarse)').matches) {
            window.open(url + '#page=' + page, '_blank', 'noopener');
            return;
        }
        if ($('#library-book').open) HOD.dialog.close('library-book');
        $('#library-reader-title').textContent = title;
        $('#library-reader-desc').textContent = b.author || (prog && prog.author) || '';
        $('[data-library-frame]').src = url + '#page=' + page;
        HOD.dialog.open('library-reader');
        clearInterval(readerPing);
        if (state.loggedIn) readerPing = setInterval(function () { saveProgress(bookId, page); }, 30000);
    }

    /** Borrow, waitlist, nudge, extension: the same POSTs as before. */
    function act(button, action, data, then) {
        HOD.busy(button, true);
        HOD.api(API, { action: action, data: data, quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Done.', 'success');
            if (then === 'close') HOD.dialog.close('library-book');
            if (then) load();
        }).catch(function (err) {
            if (err && err.response && err.response.status === 'auth_required') { goSignIn(); return; }
            HOD.toast((err && err.message) || 'That did not work. Please try again.', 'danger');
        }).finally(function () { HOD.busy(button, false); });
    }

    function share(id) {
        var b = find(id);
        if (!b) return;
        var url = window.location.origin + window.location.pathname + '?book_id=' + encodeURIComponent(b.id);
        if (navigator.share) {
            navigator.share({
                title: b.title + ' | HOD Library',
                text: 'Grow in grace and knowledge! Explore this resource from the Household of David Lekki.',
                url: url
            }).catch(function () {});
        } else if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                HOD.toast('Link copied. Paste it in WhatsApp or anywhere else.', 'success');
            }, function () { HOD.prompt({ title: 'Copy this link', label: 'Link', value: url, confirmLabel: 'Done' }); });
        } else {
            HOD.prompt({ title: 'Copy this link', label: 'Link', value: url, confirmLabel: 'Done' });
        }
    }

    /* ---------- wire up ---------- */

    function init() {
        HOD.actions.on('library.search', function (form) {
            state.search = form.q.value.trim();
            load();
        });
        HOD.actions.on('library.category', function (btn) {
            state.category = btn.getAttribute('data-category') || '';
            document.querySelectorAll('[data-action="library.category"]').forEach(function (c) {
                c.setAttribute('aria-pressed', c === btn ? 'true' : 'false');
            });
            load();
        });
        HOD.actions.on('library.open', function (btn) { openBook(btn.getAttribute('data-id'), btn); });
        HOD.actions.on('library.read', function (btn) { read(btn.getAttribute('data-id')); });
        HOD.actions.on('library.resume', function (btn) { read(btn.getAttribute('data-id')); });
        HOD.actions.on('library.listen', listen);
        HOD.actions.on('library.borrow', function (btn) { act(btn, 'borrow_book', { book_id: btn.getAttribute('data-id') }, 'close'); });
        HOD.actions.on('library.waitlist', function (btn) { act(btn, 'join_waitlist', { book_id: btn.getAttribute('data-id') }, 'close'); });
        HOD.actions.on('library.nudge', function (btn) { act(btn, 'nudge_borrowers', { book_id: btn.getAttribute('data-id') }, null); });
        // The borrowing row's own id (the old page sent an undefined borrow_id).
        HOD.actions.on('library.extend', function (btn) { act(btn, 'request_extension', { borrow_id: btn.getAttribute('data-id') }, 'reload'); });
        HOD.actions.on('library.share', function (btn) { share(btn.getAttribute('data-id')); });
        HOD.actions.on('library.mine', function (btn) {
            var box = $('#library-mine');
            box.hidden = !box.hidden;
            btn.setAttribute('aria-expanded', box.hidden ? 'false' : 'true');
            if (!box.hidden) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        $('#library-book').addEventListener('ui:dialog-close', function () {
            stopAudio();
            state.current = null;
            if (window.location.search.indexOf('book_id=') !== -1 && window.history.replaceState) {
                window.history.replaceState({}, document.title, window.location.pathname);
            }
        });
        $('#library-reader').addEventListener('ui:dialog-close', function () {
            clearInterval(readerPing);
            $('[data-library-frame]').removeAttribute('src');
        });

        load();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
