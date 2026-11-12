// /assets/ui/js/pages/charis-ops.js
// Charis (modules/charis/index.php), part 2, on top of window.Charis from
// charis.js: Events (Sunday services, event tasks, reports and the two
// sign-off stamps), Finance (budget, spending, charts, forecast, ledger),
// Library (borrows, waitlist, catalogue, books, reading the cover), and the
// monthly recap (portraits cropped with the vendored Cropper, slides drawn by
// /assets/js/celebrants_export.js).
(function () {
    'use strict';

    var HOD = window.HOD;
    var C = window.Charis;
    if (!C) return;
    var esc = HOD.esc;

    /* =================================================================== *
     * Events
     * =================================================================== */

    var eventsView = 'upcoming';
    var taskEvent = null;
    var EVENT_TONES = { Planning: 'info', Pending_Funds: 'warning', Approved: 'success', Completed: 'success' };

    function renderEvents() {
        var body = C.$('#charis-events-body');
        if (!body) return;
        var rows = C.state.events[eventsView] || [];
        body.innerHTML = rows.length ? rows.map(function (e) {
            var status = e.logistics_status || 'No_Plan';
            return '<tr>'
                + '<td data-label="Date">' + esc(e.nice_date) + '</td>'
                + '<td data-label="Event" class="ui-table__primary">' + esc(e.title) + '</td>'
                + '<td data-label="Status">' + C.badge(C.label(status === 'No_Plan' ? 'No plan' : status), EVENT_TONES[status] || 'outline') + '</td>'
                + '<td class="text-right">' + C.btn('Tasks', 'charis.tasks', { id: e.id, title: e.title, date: e.nice_date }, 'secondary', 'clipboard-list') + '</td>'
                + '</tr>';
        }).join('') : '<tr><td colspan="4"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No ' + (eventsView === 'past' ? 'past' : 'upcoming') + ' events</p></div></td></tr>';

        C.fillSelect(C.$('#charis-expense-event'), C.state.events.upcoming || [], 'No event',
            function (e) { return e.id; }, function (e) { return e.title; });
    }
    document.addEventListener('charis:dashboard', renderEvents);

    HOD.actions.on('charis.events-view', function (btn) {
        eventsView = btn.getAttribute('data-view') === 'past' ? 'past' : 'upcoming';
        C.$$('[data-action="charis.events-view"]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        renderEvents();
    });

    HOD.actions.on('charis.bulk-services', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Services created.', 'success');
            form.reset();
            C.reload.dashboard();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Tasks -------------------------------------------------------- */

    var TASK_TONES = { Completed: 'success', In_Progress: 'info' };

    function renderTasks(tasks, isAdmin) {
        var box = C.$('#charis-tasks');
        if (!tasks.length) {
            box.innerHTML = '<div class="ui-empty ui-empty--compact"><p class="ui-empty__title">' + (isAdmin ? 'No tasks yet' : 'No tasks for you') + '</p></div>';
            return;
        }
        box.innerHTML = tasks.map(function (t) {
            var actions = '';
            if (t.status !== 'Completed') {
                if (!Number(t.funds_disbursed)) actions += C.btn('Funds received', 'charis.task-funds', { id: t.id }, 'secondary', 'wallet');
                actions += C.btn('Report', 'charis.task-report-open', { id: t.id, title: t.task_title }, 'primary');
            }
            if (isAdmin) actions += C.btn('Remove', 'charis.task-delete', { id: t.id, title: t.task_title }, 'danger-ghost', 'trash-2');
            return '<article class="ui-card">'
                + '<div class="ui-card__body space-y-2">'
                + '<div class="flex items-start justify-between gap-3"><div class="min-w-0">'
                + '<p class="font-semibold text-ink">' + esc(t.task_title) + '</p>'
                + (t.task_description ? '<p class="text-sm text-ink-muted">' + esc(t.task_description) + '</p>' : '')
                + '</div>' + C.badge(C.label(t.status), TASK_TONES[t.status] || 'warning') + '</div>'
                + '<dl class="ui-dl text-sm">'
                + '<dt>Member</dt><dd>' + esc(((t.worker_first || '—') + ' ' + (t.worker_last || '')).trim()) + '</dd>'
                + '<dt>Budget</dt><dd>' + esc(C.money(t.estimated_budget)) + '</dd>'
                + (Number(t.actual_spent) > 0 ? '<dt>Spent</dt><dd>' + esc(C.money(t.actual_spent)) + '</dd>' : '')
                + (t.member_report ? '<dt>Report</dt><dd class="italic">“' + esc(t.member_report) + '”</dd>' : '')
                + '</dl>'
                + (Number(t.funds_disbursed) ? C.badge('Funds received', 'success') : '')
                + (actions ? '<div class="flex flex-wrap gap-2 pt-1">' + actions + '</div>' : '')
                + '</div></article>';
        }).join('');
    }

    function stampHtml(label, s) {
        return '<div class="ui-banner ui-banner--success"><div class="ui-banner__body">'
            + '<p class="ui-banner__title">' + esc(label) + '</p>'
            + '<p class="ui-banner__text">' + esc(s.name) + ' · ' + esc(s.date_time) + '</p>'
            + '<p class="text-xs text-ink-subtle">Verify: <span class="font-mono">' + esc(s.verify_code) + '</span></p>'
            + '</div></div>';
    }

    function renderStamps(stamp, isAdmin) {
        var hod = C.$('#charis-stamp-hod');
        var fin = C.$('#charis-stamp-fin');
        var box = C.$('#charis-stamps');
        var hodDone = !!(stamp && stamp.hod_stamp_at);
        var finDone = !!(stamp && stamp.finance_stamp_at);
        box.innerHTML = (hodDone && stamp.hod_stamp_data ? stampHtml('Signed off', stamp.hod_stamp_data) : '')
            + (finDone && stamp.finance_stamp_data ? stampHtml('Countersigned by finance', stamp.finance_stamp_data) : '');
        hod.hidden = !isAdmin || hodDone;
        fin.hidden = !isAdmin || !hodDone || finDone;
    }

    function loadTasks() {
        var box = C.$('#charis-tasks');
        box.innerHTML = '<div class="ui-skeleton ui-skeleton--block h-20"></div>';
        return C.api({ action: 'get_event_tasks', event_id: taskEvent }, { method: 'GET' }).then(function (res) {
            var isAdmin = !!res.is_admin;
            C.$('#charis-task-add').hidden = !isAdmin;
            C.$('#charis-signoff').hidden = !isAdmin;
            renderTasks(res.tasks || [], isAdmin);
            renderStamps(res.stamp || null, isAdmin);
        }).catch(function () { box.innerHTML = ''; });
    }

    HOD.actions.on('charis.tasks', function (btn) {
        taskEvent = btn.getAttribute('data-id');
        C.$('#charis-task-event').value = taskEvent;
        C.setText('#charis-event-tasks-title', btn.getAttribute('data-title'));
        C.setText('#charis-event-tasks-desc', btn.getAttribute('data-date'));
        C.$('#charis-event-pdf').setAttribute('href', '/includes/charis_event_report_pdf.php?event_id=' + encodeURIComponent(taskEvent));
        HOD.dialog.open('charis-event-tasks', btn);
        loadTasks();
    });

    HOD.actions.on('charis.task-add', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Task added.', 'success');
            form.reset();
            C.$('#charis-task-event').value = taskEvent;
            loadTasks();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('charis.task-report-open', function (btn) {
        var form = C.$('#charis-task-report-form');
        form.reset();
        C.$('#charis-report-task').value = btn.getAttribute('data-id');
        C.setText('#charis-task-report-desc', btn.getAttribute('data-title'));
        HOD.dialog.open('charis-task-report', btn);
    });

    HOD.actions.on('charis.task-report', function (form, event) {
        var btn = (event && event.submitter) || C.$('[form="charis-task-report-form"]');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Report sent.', 'success');
            HOD.dialog.close('charis-task-report');
            loadTasks();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    function confirmThen(opts, data, btn) {
        HOD.confirm(opts).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            C.api(data).then(function (res) {
                HOD.toast(res.message || 'Done.', 'success');
                if (opts.after) opts.after();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    }

    HOD.actions.on('charis.task-funds', function (btn) {
        confirmThen({ title: 'Funds received?', message: 'This records that the money for this task reached the member.', confirmLabel: 'Confirm', after: loadTasks },
            { action: 'update_event_task', task_id: btn.getAttribute('data-id'), mark_funds_received: 1 }, btn);
    });
    HOD.actions.on('charis.task-delete', function (btn) {
        confirmThen({ title: 'Remove task?', message: '“' + btn.getAttribute('data-title') + '” will be removed. This cannot be undone.', confirmLabel: 'Remove', tone: 'danger', after: loadTasks },
            { action: 'delete_event_task', task_id: btn.getAttribute('data-id') }, btn);
    });
    HOD.actions.on('charis.stamp', function (btn) {
        var finance = btn.getAttribute('data-stamp') === 'finance';
        confirmThen(finance
            ? { title: 'Countersign event?', message: 'Your finance stamp seals the event report.', confirmLabel: 'Countersign', after: loadTasks }
            : { title: 'Sign off event?', message: 'Your digital stamp goes on the event report.', confirmLabel: 'Sign off', after: loadTasks },
            { action: finance ? 'finance_stamp_event' : 'stamp_event_completion', event_id: taskEvent }, btn);
    });

    /* =================================================================== *
     * Finance
     * =================================================================== */

    var financeReady = false;

    function loadFinance() {
        financeReady = true;
        var month = C.$('#charis-fin-month').value;
        var year = C.$('#charis-fin-year').value;
        var period = new Date(Number(year), Number(month) - 1, 1).toLocaleString('en-GB', { month: 'long', year: 'numeric' });
        C.$$('[data-charis-fin-period]').forEach(function (el) { el.textContent = period; });
        return C.api({ action: 'get_finance_data', month: month, year: year }).then(function (res) {
            var budget = res.monthly_budget ? parseFloat(res.monthly_budget.amount) || 0 : 0;
            var actual = parseFloat(res.total_actual || 0) || 0;
            var variance = budget - actual;
            C.setText('#charis-fin-budget', budget ? C.money(budget) : 'Not set');
            C.setText('#charis-fin-budget-note', res.monthly_budget && res.monthly_budget.notes ? res.monthly_budget.notes : period);
            C.setText('#charis-fin-actual', C.money(actual));
            var v = C.$('#charis-fin-variance');
            v.textContent = C.money(Math.abs(variance));
            v.classList.toggle('text-success', variance >= 0);
            v.classList.toggle('text-danger', variance < 0);
            C.setText('#charis-fin-variance-note', variance >= 0 ? 'Under budget' : 'Over budget');

            drawTrend(res.trend || [], res.budget_trend || []);
            drawCategories(res.categories || []);
            renderForecast(res.forecast || []);
            renderLedger(res.ledger || []);
        }).catch(function () {
            C.$('#charis-ledger-body').innerHTML = '<tr><td colspan="5"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">Could not load this month</p><p class="ui-empty__text">Pick the month again to retry.</p></div></td></tr>';
        });
    }
    C.reload.finance = loadFinance;

    function drawTrend(trend, budgets) {
        HOD.chart(C.$('#charis-fin-bar'), {
            type: 'bar',
            data: {
                labels: trend.map(function (t) { return t.label; }),
                datasets: [
                    { label: 'Budget', data: budgets, backgroundColor: HOD.ramp[5] },
                    { label: 'Spent', data: trend.map(function (t) { return t.total; }), backgroundColor: HOD.palette[0] }
                ]
            },
            options: {
                plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ctx.dataset.label + ': ' + C.money(ctx.parsed.y); } } } },
                scales: { y: { ticks: { callback: function (v) { return '₦' + HOD.format.number(v); } } } }
            }
        }).catch(function () {});
    }

    function drawCategories(cats) {
        var canvas = C.$('#charis-fin-pie');
        var empty = C.$('[data-charis-fin-pie-empty]');
        empty.classList.toggle('hidden', cats.length > 0);
        canvas.parentNode.classList.toggle('hidden', !cats.length);
        if (!cats.length) {
            var old = window.Chart && window.Chart.getChart(canvas);
            if (old) old.destroy();
            return;
        }
        HOD.chart(canvas, {
            type: 'doughnut',
            data: {
                labels: cats.map(function (c) { return C.label(c.category); }),
                datasets: [{ data: cats.map(function (c) { return c.total; }), backgroundColor: HOD.palette, borderWidth: 0 }]
            },
            options: {
                cutout: '62%',
                plugins: { legend: { position: 'bottom' }, tooltip: { callbacks: { label: function (ctx) { return ctx.label + ': ' + C.money(ctx.parsed); } } } }
            }
        }).catch(function () {});
    }

    function renderForecast(rows) {
        var card = C.$('#charis-forecast');
        card.hidden = !rows.length;
        C.$('#charis-forecast-cards').innerHTML = rows.map(function (f) {
            return '<div class="ui-card ui-card--sunken ui-stat"><span class="ui-stat__label"><span>' + esc(C.label(f.category)) + '</span></span>'
                + '<span class="ui-stat__value text-xl">' + esc(C.money(f.projected)) + '</span></div>';
        }).join('');
    }

    var LEDGER_TONES = { Completed: 'success', Declined: 'danger' };

    function renderLedger(rows) {
        var body = C.$('#charis-ledger-body');
        body.innerHTML = rows.length ? rows.map(function (ex) {
            return '<tr>'
                + '<td data-label="Item" class="ui-table__primary">' + esc(ex.item_name) + '</td>'
                + '<td data-label="Category">' + esc(C.label(ex.category)) + '</td>'
                + '<td data-label="Requested" class="ui-num">' + esc(C.money(ex.amount_requested)) + '</td>'
                + '<td data-label="Approved" class="ui-num">' + (ex.amount_approved ? esc(C.money(ex.amount_approved)) : '—') + '</td>'
                + '<td data-label="Status">' + C.badge(C.label(ex.status), LEDGER_TONES[ex.status] || 'warning') + '</td>'
                + '</tr>';
        }).join('') : '<tr><td colspan="5"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No expenses this month</p></div></td></tr>';
    }

    HOD.actions.on('charis.finance-period', function () { loadFinance(); });

    /* =================================================================== *
     * Library
     * =================================================================== */

    var catalog = [];

    function syncBookType(form) {
        var type = form.querySelector('[name="book_type"]');
        if (!type) return;
        C.$$('.charis-physical', form).forEach(function (f) { f.classList.toggle('hidden', type.value === 'E-Book'); });
        C.$$('.charis-ebook', form).forEach(function (f) { f.classList.toggle('hidden', type.value === 'Physical'); });
    }
    HOD.actions.on('charis.book-type', function (select) { syncBookType(select.form); });

    function loadLibrary() {
        syncBookType(C.$('#charis-book-form'));
        // The API answers { catalog, active_borrows, waitlist, categories } at
        // the top level (the old page read res.data.* and always showed empty).
        return C.api({ action: 'fetch_library_admin' }, { method: 'GET' }).then(function (res) {
            catalog = res.catalog || [];
            renderBorrows(res.active_borrows || []);
            renderWaitlist(res.waitlist || []);
            renderCatalog(catalog);
            var cats = res.categories || catalog.map(function (b) { return b.category; }).filter(function (c, i, all) { return c && all.indexOf(c) === i; }).sort();
            C.$('#charis-book-categories').innerHTML = cats.map(function (c) { return '<option value="' + esc(c) + '">'; }).join('');
            C.setText('#charis-catalog-count', catalog.length + (catalog.length === 1 ? ' book' : ' books'));
        }).catch(function () {});
    }
    C.reload.library = loadLibrary;

    function renderBorrows(rows) {
        var list = C.$('#charis-borrows');
        list.innerHTML = rows.length ? rows.map(function (b) {
            var id = b.borrow_id || b.id;
            var who = C.person(b);
            var actions = '';
            if (b.status === 'Reserved') actions += C.btn('Picked up', 'charis.borrow', { act: 'confirm_book_pickup', id: id }, 'primary');
            if (b.status === 'Picked_Up' || b.status === 'Overdue') actions += C.btn('Return', 'charis.return-open', { id: id, title: b.title, member: who }, 'primary');
            if (b.extension_status === 'Pending') actions += C.btn('Approve extension', 'charis.borrow', { act: 'approve_extension', id: id }, 'secondary');
            actions += C.btn('Cancel', 'charis.borrow-cancel', { id: id, title: b.title }, 'ghost');
            return '<li class="ui-list__item flex-col items-stretch gap-2">'
                + '<div class="flex items-start justify-between gap-3"><div class="min-w-0">'
                + '<p class="font-semibold text-ink">' + esc(who) + '</p>'
                + '<p class="truncate text-sm text-ink-muted">' + esc(b.title) + '</p>'
                + '<p class="text-xs text-ink-subtle">Due ' + esc(b.due_date || '—') + '</p></div>'
                + '<div class="flex shrink-0 flex-wrap justify-end gap-1">'
                + (b.status === 'Overdue' ? C.badge('Overdue', 'danger') : C.badge(C.label(b.status), 'outline'))
                + (b.extension_status === 'Pending' ? C.badge('Wants more time', 'warning') : '') + '</div></div>'
                + '<div class="flex flex-wrap gap-2">' + actions + '</div></li>';
        }).join('') : C.emptyItem('No books out', 'Reserved and borrowed books show here.');
    }

    function renderWaitlist(rows) {
        C.$('#charis-waitlist').innerHTML = rows.length ? rows.map(function (w) {
            return '<li class="ui-list__item"><div class="ui-list__main"><span class="ui-list__title">' + esc(C.person(w)) + '</span>'
                + '<span class="ui-list__meta">' + esc(w.title) + '</span></div>' + C.badge('Waiting', 'warning') + '</li>';
        }).join('') : C.emptyItem('Nobody waiting');
    }

    function renderCatalog(rows) {
        C.$('#charis-catalog').innerHTML = rows.length ? rows.map(function (b) {
            var copies = b.book_type !== 'E-Book' ? b.available_copies + ' of ' + b.total_copies : 'E-book';
            return '<tr>'
                + '<td data-label="Title" class="ui-table__primary">' + esc(b.title) + '</td>'
                + '<td data-label="Author">' + esc(b.author) + '</td>'
                + '<td data-label="Format">' + C.badge(b.book_type === 'Physical' ? 'Printed' : b.book_type, 'outline') + '</td>'
                + '<td data-label="Copies">' + esc(copies) + '</td>'
                + '<td class="text-right"><div class="inline-flex gap-1">'
                + C.btn('Edit', 'charis.book-edit', { id: b.id }, 'ghost', 'pencil')
                + C.btn('Delete', 'charis.book-delete', { id: b.id, title: b.title }, 'danger-ghost', 'trash-2')
                + '</div></td></tr>';
        }).join('') : '<tr><td colspan="5"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">No books yet</p></div></td></tr>';
    }

    HOD.actions.on('charis.borrow', function (btn) {
        HOD.busy(btn, true);
        C.api({ action: btn.getAttribute('data-act'), borrow_id: btn.getAttribute('data-id') })
            .then(function (res) { HOD.toast(res.message || 'Done.', 'success'); loadLibrary(); })
            .catch(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('charis.borrow-cancel', function (btn) {
        confirmThen({ title: 'Cancel reservation?', message: '“' + btn.getAttribute('data-title') + '” goes back on the shelf.', confirmLabel: 'Cancel it', tone: 'danger', after: loadLibrary },
            { action: 'cancel_reservation', borrow_id: btn.getAttribute('data-id') }, btn);
    });
    HOD.actions.on('charis.return-open', function (btn) {
        C.$('#charis-return-form').reset();
        C.$('#charis-return-id').value = btn.getAttribute('data-id');
        C.setText('#charis-return-book-desc', btn.getAttribute('data-member') + ' · ' + btn.getAttribute('data-title'));
        HOD.dialog.open('charis-return-book', btn);
    });
    HOD.actions.on('charis.book-delete', function (btn) {
        confirmThen({ title: 'Delete book?', message: '“' + btn.getAttribute('data-title') + '” leaves the library for good.', confirmLabel: 'Delete', tone: 'danger', after: loadLibrary },
            { action: 'delete_book', book_id: btn.getAttribute('data-id') }, btn);
    });

    HOD.actions.on('charis.add-book', function (btn) {
        var form = C.$('#charis-book-form');
        form.reset();
        syncBookType(form);
        HOD.dialog.open('charis-add-book', btn);
    });

    HOD.actions.on('charis.book-edit', function (btn) {
        var b = catalog.filter(function (x) { return String(x.id) === btn.getAttribute('data-id'); })[0];
        if (!b) return;
        var form = C.$('#charis-edit-book-form');
        form.reset();
        C.$('#charis-edit-id').value = b.id;
        C.$('#charis-edit-title').value = b.title || '';
        C.$('#charis-edit-author').value = b.author || '';
        C.$('#charis-edit-category').value = b.category || '';
        C.$('#charis-edit-type').value = b.book_type || 'Physical';
        C.$('#charis-edit-copies').value = b.total_copies != null ? b.total_copies : '';
        C.$('#charis-edit-audio').value = b.audiobook_link || '';
        C.$('#charis-edit-time').value = b.estimated_read_time || '';
        C.$('#charis-edit-desc').value = b.description || '';
        C.setText('#charis-edit-book-desc', b.title || '');
        syncBookType(form);
        HOD.dialog.open('charis-edit-book', btn);
    });

    HOD.actions.on('charis.read-cover', function (btn) {
        var front = C.$('#charis-cover-front').files[0];
        if (!front) { HOD.toast('Add the front cover first.', 'warning'); return; }
        var fd = new FormData();
        fd.append('action', 'extract_book_metadata');
        fd.append('front_cover', front);
        var back = C.$('#charis-cover-back').files[0];
        if (back) fd.append('back_cover', back);
        HOD.busy(btn, true);
        C.api(fd).then(function (res) {
            var d = res.data || {};
            var form = C.$('#charis-book-form');
            if (d.title) form.elements.title.value = d.title;
            if (d.author) form.elements.author.value = d.author;
            if (d.description) form.elements.description.value = d.description;
            if (d.read_time) form.elements.estimated_read_time.value = d.read_time;
            HOD.toast('Filled in from the cover. Check it.', 'success');
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Monthly recap and portraits
     * =================================================================== */

    var recap = [];
    var cropper = null;
    var portraitFilter = 'none';
    var FILTERS = { none: 'none', grayscale: 'grayscale(100%)', sepia: 'sepia(70%)', brightness: 'brightness(1.3)', contrast: 'contrast(1.4)' };
    var RECAP_TONES = { Birthday: 'warning', JC_Birthday: 'success' };

    function initialsPortrait(first, last) {
        var initials = ((first || '?').charAt(0) + (last || '').charAt(0)).toUpperCase().replace(/[<>&"']/g, '');
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="460" height="820" viewBox="0 0 460 820">'
            + '<rect width="460" height="820" fill="#111827"/>'
            + '<text x="230" y="410" text-anchor="middle" dominant-baseline="central" fill="#ffffff" fill-opacity="0.35"'
            + ' font-family="Arial, Helvetica, sans-serif" font-size="200" font-weight="bold">' + initials + '</text></svg>';
        return 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(svg);
    }
    function portraitOf(v) { return C.safeUrl(v.display_picture) || initialsPortrait(v.first_name, v.last_name); }
    function recapLabel(t) { return t === 'JC_Birthday' ? 'Junior Church' : C.label(t); }

    function renderRecap() {
        var grid = C.$('#charis-recap-grid');
        if (!recap.length) {
            grid.innerHTML = '<div class="ui-empty ui-empty--compact col-span-full"><p class="ui-empty__title">No celebrants this month</p></div>';
            return;
        }
        grid.innerHTML = recap.map(function (v) {
            return '<figure class="relative aspect-[9/16] overflow-hidden rounded-ui bg-ink">'
                + '<img class="h-full w-full object-cover" src="' + esc(portraitOf(v)) + '" alt="' + esc(C.person(v)) + '" loading="lazy">'
                + '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon absolute right-2 top-2" data-action="charis.portrait" data-ref="' + esc(v.ref) + '" aria-label="Edit the portrait of ' + esc(C.person(v)) + '">' + HOD.icon('pencil') + '</button>'
                + '<figcaption class="absolute inset-x-0 bottom-0 space-y-1 bg-gradient-to-t from-black/85 to-transparent p-3 pt-10 text-white">'
                + '<div class="flex flex-wrap gap-1">' + C.badge(recapLabel(v.event_type), RECAP_TONES[v.event_type] || 'info') + '</div>'
                + '<p class="text-xs opacity-80">' + esc(v.event_date) + '</p>'
                + '<p class="font-display text-sm font-bold leading-tight">' + esc(v.first_name) + '<br>' + esc(v.last_name) + '</p>'
                + '</figcaption></figure>';
        }).join('');
    }

    function loadRecap() {
        C.$('#charis-recap-grid').innerHTML = '<div class="ui-skeleton ui-skeleton--block col-span-full h-40"></div>';
        return C.api({ action: 'get_envision_recap', month: new Date().getMonth() + 1 }).then(function (res) {
            recap = Array.isArray(res.data) ? res.data : [];
            renderRecap();
        }).catch(function () { C.$('#charis-recap-grid').innerHTML = ''; });
    }

    HOD.actions.on('charis.recap', function (btn) {
        HOD.dialog.open('charis-recap', btn);
        loadRecap();
    });

    function applyFilter() {
        C.$$('#charis-portrait img').forEach(function (img) { img.style.filter = FILTERS[portraitFilter]; });
    }

    function startCropper(src) {
        var img = C.$('#charis-cropper-img');
        if (cropper) { cropper.destroy(); cropper = null; }
        img.src = src;
        HOD.lib('cropper').then(function () {
            requestAnimationFrame(function () {
                cropper = new window.Cropper(img, { aspectRatio: 9 / 16, viewMode: 1, autoCropArea: 1, ready: applyFilter });
            });
        }).catch(function () { HOD.toast('The photo editor did not load. Try again.', 'danger'); });
    }

    HOD.actions.on('charis.portrait', function (btn) {
        var ref = btn.getAttribute('data-ref');
        var member = recap.filter(function (m) { return String(m.ref) === ref; })[0];
        C.$('#charis-portrait-ref').value = ref;
        C.$('#charis-portrait-upload').value = '';
        portraitFilter = 'none';
        C.$$('[data-action="charis.portrait-filter"]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-filter') === 'none' ? 'true' : 'false'); });
        HOD.dialog.open('charis-portrait', btn);
        startCropper(member ? portraitOf(member) : 'data:,');
    });

    HOD.actions.on('charis.portrait-upload', function (input) {
        var file = input.files && input.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) { startCropper(e.target.result); };
        reader.readAsDataURL(file);
    });

    HOD.actions.on('charis.portrait-filter', function (btn) {
        portraitFilter = FILTERS[btn.getAttribute('data-filter')] ? btn.getAttribute('data-filter') : 'none';
        C.$$('[data-action="charis.portrait-filter"]').forEach(function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        applyFilter();
    });

    HOD.actions.on('charis.portrait-save', function (btn) {
        if (!cropper) return;
        var cropped = cropper.getCroppedCanvas({ width: 800, height: 1422 });
        // The filter is drawn into the saved picture, not only shown.
        var out = document.createElement('canvas');
        out.width = cropped.width;
        out.height = cropped.height;
        var ctx = out.getContext('2d');
        ctx.filter = FILTERS[portraitFilter];
        ctx.drawImage(cropped, 0, 0);
        HOD.busy(btn, true);
        C.api({ action: 'update_celebration_picture', ref: C.$('#charis-portrait-ref').value, image_base64: out.toDataURL('image/jpeg', 0.88) }).then(function (res) {
            HOD.toast(res.message || 'Portrait saved.', 'success');
            HOD.dialog.close('charis-portrait');
            loadRecap();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    document.addEventListener('ui:dialog-close', function (e) {
        if (e.target && e.target.id === 'charis-portrait' && cropper) { cropper.destroy(); cropper = null; }
    });

    HOD.actions.on('charis.recap-export', function (btn) {
        if (!recap.length) { HOD.toast('No celebrants to export.', 'warning'); return; }
        if (!window.CelebrantsExport) { HOD.toast('The exporter did not load. Reload the page.', 'danger'); return; }
        var period = new Date().toLocaleString('en-GB', { month: 'long', year: 'numeric' });
        HOD.busy(btn, true);
        C.setLabel(btn, 'Drawing…');
        window.CelebrantsExport.renderSlides(recap, {
            periodLabel: period,
            filePrefix: 'HOD_Celebrations_' + period.replace(/\s+/g, '_'),
            onProgress: function (done, total) { C.setLabel(btn, done + ' of ' + total); }
        }).then(function (slides) {
            C.setLabel(btn, 'Saving…');
            return window.CelebrantsExport.deliverSlides(slides, 'HOD Celebrations, ' + period).then(function (how) {
                HOD.toast(how === 'shared' ? 'Slides ready to save or share.' : slides.length + (slides.length === 1 ? ' slide' : ' slides') + ' downloaded.', 'success');
            });
        }).catch(function (e) {
            HOD.toast('Export failed: ' + (e && e.message ? e.message : 'try again.'), 'danger');
        }).then(function () { HOD.busy(btn, false); C.setLabel(btn, 'Export'); });
    });

    /* =================================================================== */

    function init() {
        document.addEventListener('ui:tab', function (e) {
            var tab = e.detail && e.detail.tab;
            if (tab === 'finance' && !financeReady) loadFinance();
            if (tab === 'library') loadLibrary();
        });
        if (!C.$('#panel-finance').hidden) loadFinance();
        if (!C.$('#panel-library').hidden) loadLibrary();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
