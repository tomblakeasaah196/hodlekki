// /assets/ui/js/pages/charis.js
// Charis (modules/charis/index.php), part 1: the shared helpers (window.Charis)
// and the Welfare tab: birthdays and life events with their worker
// assignments, AWOL monitoring (views, search, the active rule and its
// editor), case management with voice notes and "Tidy", welfare notes, the
// archive and the AWOL report. charis-ops.js adds Events, Finance, Library
// and the monthly recap on top of window.Charis.
//
// Every request goes to /api/charis_api.php with the module's own CSRF token
// in the X-Charis-CSRF header (the API refuses a POST without it).
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/charis_api.php';
    var cfg = HOD.data('charis-config') || {};
    var esc = HOD.esc;

    var C = window.Charis = {
        cfg: cfg,
        reload: {},
        state: {
            isAdmin: !!cfg.manager, isPastor: false, me: 0, canConfigure: !!cfg.canConfigure,
            workers: [], members: [], events: { upcoming: [], past: [] }, welfare: [],
            awolConfig: null, awolHistory: [], qualifying: 0
        }
    };

    /* =================================================================== *
     * Shared helpers
     * =================================================================== */

    C.api = function (data, opts) {
        opts = opts || {};
        return HOD.api(API, {
            method: opts.method || 'POST',
            data: data,
            quiet: opts.quiet,
            headers: { 'X-Charis-CSRF': cfg.csrf || '' }
        });
    };
    C.$ = function (sel, scope) { return (scope || document).querySelector(sel); };
    C.$$ = function (sel, scope) { return Array.prototype.slice.call((scope || document).querySelectorAll(sel)); };
    C.label = function (s) { return String(s || '').replace(/_/g, ' '); };
    C.person = function (p) { return ((p.first_name || '') + ' ' + (p.last_name || '')).trim(); };
    /** A celebrant as the church says it: "Min. Bolanle Ade", or a couple's "Pastor Ebele & Min. Bolanle Uzo-Peters" (includes/couples.php). */
    C.celebrant = function (p) { return p.display_name || C.person(p); };
    /** Event type for people: a wedding anniversary always says so. */
    C.eventLabel = function (t) { return t === 'Wedding_Anniversary' || t === 'Anniversary' ? 'Wedding anniversary' : C.label(t); };
    C.parseDate = function (v) {
        if (!v) return null;
        var d = new Date(String(v).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    };
    C.when = function (v, withTime) {
        var d = C.parseDate(v);
        if (!d) return String(v || '');
        return withTime ? HOD.format.date(d) + ', ' + HOD.format.time(d) : HOD.format.date(d);
    };
    C.money = function (v) { return HOD.format.naira(v); };
    C.safeUrl = function (url) {
        url = String(url || '').trim();
        return /^(https?:\/\/|\/(?!\/)|data:image\/)/i.test(url) ? url : '';
    };
    /** A small button that runs a page action; data is written as data-* attributes. */
    C.btn = function (label, action, data, variant, icon) {
        var attrs = '';
        Object.keys(data || {}).forEach(function (k) {
            attrs += ' data-' + k + '="' + esc(data[k] == null ? '' : String(data[k])) + '"';
        });
        return '<button type="button" class="ui-btn ui-btn--sm ui-btn--' + (variant || 'secondary') + '" data-action="' + action + '"' + attrs + '>'
            + (icon ? HOD.icon(icon) : '') + '<span>' + esc(label) + '</span></button>';
    };
    C.badge = function (text, tone) {
        return '<span class="ui-badge' + (tone ? ' ui-badge--' + tone : '') + '">' + esc(text) + '</span>';
    };
    C.emptyItem = function (title, text) {
        return '<li><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">' + esc(title) + '</p>'
            + (text ? '<p class="ui-empty__text">' + esc(text) + '</p>' : '') + '</div></li>';
    };
    C.avatar = function (first, last, pic) {
        var name = ((first || '') + ' ' + (last || '')).trim() || '?';
        var h = 0;
        for (var i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) >>> 0;
        var url = C.safeUrl(pic);
        return '<span class="ui-avatar ui-avatar--sm ui-avatar--c' + ((h % 6) + 1) + '">'
            + (url ? '<img src="' + esc(url) + '" alt="" loading="lazy">'
                : '<span aria-hidden="true">' + esc(((first || '?').charAt(0) + (last || '').charAt(0)).toUpperCase()) + '</span>')
            + '</span>';
    };
    C.fillSelect = function (select, rows, placeholder, valueOf, labelOf) {
        if (!select) return;
        var keep = select.value;
        select.innerHTML = '<option value="">' + esc(placeholder) + '</option>' + rows.map(function (r) {
            return '<option value="' + esc(valueOf(r)) + '">' + esc(labelOf(r)) + '</option>';
        }).join('');
        if (keep) select.value = keep;
        HOD.combobox.refresh(select);
    };
    C.setText = function (sel, text) { var el = C.$(sel); if (el) el.textContent = text; };
    /** Sets a ui_button's label (the last <span>, after the icon). */
    C.setLabel = function (btn, text) {
        var spans = btn ? btn.querySelectorAll('span:not(.ui-btn__spinner)') : [];
        if (spans.length) spans[spans.length - 1].textContent = text;
    };

    /**
     * Forms with data-action="charis.form": send, toast, close the dialog,
     * reset, and reload what data-reload names (dashboard | finance | library).
     */
    HOD.actions.on('charis.form', function (form, event) {
        var btn = (event && event.submitter) || C.$('[type="submit"][form="' + form.id + '"]') || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            var dialog = form.getAttribute('data-close') || (form.closest('dialog') && form.closest('dialog').id);
            if (dialog) HOD.dialog.close(dialog);
            form.reset();
            var reload = form.getAttribute('data-reload');
            if (reload && C.reload[reload]) C.reload[reload]();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* =================================================================== *
     * Dashboard: people, celebrations, welfare cases
     * =================================================================== */

    function loadDashboard() {
        return C.api({ action: 'fetch_dashboard' }, { method: 'GET' }).then(function (res) {
            var s = C.state;
            s.isAdmin = !!res.is_charis_admin;
            s.isPastor = !!res.is_pastor;
            s.me = Number(res.current_user_id) || 0;
            s.canConfigure = !!res.can_configure_awol;
            s.awolConfig = res.awol_config || null;
            s.awolHistory = res.awol_config_history || [];
            s.qualifying = res.qualifying_services_count != null ? Number(res.qualifying_services_count)
                : Number(res.awol_summary ? res.awol_summary.total_qualifying_services : 0) || 0;
            s.workers = res.charis_workers || [];
            s.members = res.members || [];
            s.events = { upcoming: res.upcoming_events || [], past: res.past_events || [] };
            s.welfare = res.welfare_checks || [];

            var workerName = function (w) { return C.person(w); };
            var id = function (r) { return r.id; };
            C.fillSelect(C.$('#charis-worker-select'), s.workers, 'Pick a worker', id, workerName);
            C.fillSelect(C.$('#charis-case-worker'), s.workers, 'Pick a worker', id, workerName);
            C.fillSelect(C.$('#charis-task-worker'), s.workers, 'Pick a member', id, workerName);
            C.fillSelect(C.$('#charis-member-select'), s.members, 'Pick a member', id, workerName);

            renderBirthdays(res.birthdays || []);
            renderLifeEvents(res.anniversaries || []);
            renderRule();
            renderWelfare();
            document.dispatchEvent(new CustomEvent('charis:dashboard'));
        }).catch(function () {
            var list = C.$('#charis-welfare-list');
            if (list) list.innerHTML = C.emptyItem('Could not load Charis', 'Reload the page to try again.');
        });
    }
    C.reload.dashboard = loadDashboard;

    function setCount(key, n) {
        var el = C.$('[data-charis-count="' + key + '"]');
        if (el) el.textContent = HOD.format.number(n);
    }

    /* ----- Birthdays and life events ----------------------------------- */

    function celebrationAction(item, type) {
        var s = C.state;
        var canManage = s.isAdmin || Number(item.assigned_worker_id) === s.me;
        if (item.assignment_id) {
            if (item.assignment_status === 'Completed') return C.badge('Done', 'success');
            if (!canManage) return C.badge(item.assignment_status === 'Flyer_Posted' ? 'Flyer posted' : 'Assigned', 'outline');
            return item.assignment_status === 'Flyer_Posted'
                ? C.btn('Mark done', 'charis.celebration-status', { id: item.assignment_id, status: 'Completed' }, 'soft')
                : C.btn('Flyer posted', 'charis.celebration-status', { id: item.assignment_id, status: 'Flyer_Posted' }, 'secondary');
        }
        if (!s.isAdmin) return '';
        return C.btn('Assign', 'charis.assign-worker', {
            target: item.target_user_id, type: type, date: item.event_date, when: item.formatted_date || '', name: C.celebrant(item)
        }, 'secondary', 'user-round-plus');
    }

    function celebrationItem(item, meta, type, download) {
        var pic = C.safeUrl(item.picture_path);
        return '<li class="ui-list__item flex-wrap">'
            + C.avatar(item.first_name, item.last_name, pic)
            + '<div class="ui-list__main"><span class="ui-list__title">' + esc(C.celebrant(item)) + '</span>'
            + '<span class="ui-list__meta">' + esc(meta) + '</span></div>'
            + '<div class="ui-list__end">'
            + (download && pic ? '<a class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" href="' + esc(pic) + '" download aria-label="Download photo of ' + esc(C.celebrant(item)) + '">' + HOD.icon('download') + '</a>' : '')
            + celebrationAction(item, type) + '</div></li>';
    }

    function renderBirthdays(rows) {
        var list = C.$('#charis-birthdays-list');
        setCount('birthdays', rows.length);
        if (!list) return;
        list.innerHTML = rows.length ? rows.map(function (b) {
            return celebrationItem(b, [b.formatted_date, b.phone].filter(Boolean).join(' · '), 'Birthday', true);
        }).join('') : C.emptyItem('No birthdays coming up', 'Nothing this month or next.');
    }

    function renderLifeEvents(rows) {
        var list = C.$('#charis-life-list');
        setCount('life', rows.length);
        if (!list) return;
        list.innerHTML = rows.length ? rows.map(function (a) {
            return celebrationItem(a, [a.formatted_date, C.eventLabel(a.event_type)].filter(Boolean).join(' · '), a.event_type, false);
        }).join('') : C.emptyItem('No life events coming up', 'Log one with Log event.');
    }

    HOD.actions.on('charis.assign-worker', function (btn) {
        C.$('#charis-assign-target').value = btn.getAttribute('data-target');
        C.$('#charis-assign-type').value = btn.getAttribute('data-type');
        C.$('#charis-assign-date').value = btn.getAttribute('data-date');
        C.setText('#charis-assign-name', btn.getAttribute('data-name'));
        C.setText('#charis-assign-badge', [C.label(btn.getAttribute('data-type')), btn.getAttribute('data-when')].filter(Boolean).join(' · '));
        HOD.dialog.open('charis-assign-worker', btn);
    });

    HOD.actions.on('charis.celebration-status', function (btn) {
        HOD.busy(btn, true);
        C.api({ action: 'update_assignment_status', assignment_id: btn.getAttribute('data-id'), status: btn.getAttribute('data-status') })
            .then(function (res) { HOD.toast(res.message || 'Updated.', 'success'); loadDashboard(); })
            .catch(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('charis.life-type', function (select) {
        var other = select.value === 'Other';
        var field = C.$('#charis-life-custom');
        if (!field) return;
        field.closest('.ui-field').classList.toggle('hidden', !other);
        field.required = other;
    });

    /* =================================================================== *
     * AWOL monitoring
     * =================================================================== */

    var TAB_LABELS = { my_cases: 'My cases', awol_list: 'AWOL list', manual_flags: 'Manual flags', archive: 'Archive' };
    var welfareTab = 'my_cases';
    var welfareQuery = '';
    var archive = null;
    var notesByUser = {};

    function searchKey(s) {
        return String(s == null ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, ' ').trim();
    }
    function matches(row) {
        var q = searchKey(welfareQuery);
        if (!q) return true;
        if (!/[a-z]/.test(q)) {
            var digits = q.replace(/\D/g, '');
            if (digits.length >= 3) return String(row.phone || '').replace(/\D/g, '').indexOf(digits) !== -1;
        }
        var name = searchKey(C.person(row));
        return q.split(' ').every(function (part) { return name.indexOf(part) !== -1; });
    }
    function rowsFor(tab) {
        var data = C.state.welfare;
        if (tab === 'my_cases') return data.filter(function (w) { return Number(w.worker_id) === C.state.me && w.assignment_status === 'Assigned'; });
        if (tab === 'awol_list') return data.filter(function (w) { return w.alert_type === 'AWOL'; });
        if (tab === 'manual_flags') return data.filter(function (w) { return w.alert_type === 'Manual_Check'; });
        if (tab === 'archive') return archive || [];
        return data;
    }
    function awolDate(iso) {
        var d = iso ? new Date(String(iso) + 'T00:00:00') : null;
        if (!d || isNaN(d.getTime())) return String(iso || '');
        return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
    }

    function listEl() { return C.$('#charis-welfare-list'); }

    function noMatchHtml() {
        var elsewhere = Object.keys(TAB_LABELS).filter(function (t) { return t !== welfareTab; }).map(function (t) {
            var rows = t === 'archive' ? archive : rowsFor(t);
            return { tab: t, count: Array.isArray(rows) ? rows.filter(matches).length : 0 };
        }).filter(function (x) { return x.count > 0; });
        return '<li><div class="ui-empty ui-empty--compact">'
            + '<p class="ui-empty__title">No match in ' + esc(TAB_LABELS[welfareTab]) + '</p>'
            + '<div class="ui-empty__actions flex flex-wrap justify-center gap-2">'
            + elsewhere.map(function (x) { return C.btn(x.count + ' in ' + TAB_LABELS[x.tab], 'charis.welfare-tab', { 'welfare-tab': x.tab }, 'soft'); }).join('')
            + C.btn('Clear search', 'charis.welfare-clear', {}, 'ghost')
            + '</div></div></li>';
    }

    function welfareAction(w) {
        var s = C.state;
        var name = C.person(w);
        if (welfareTab === 'my_cases') {
            return C.btn('Manage', 'charis.case', { user: w.target_user_id, followup: w.followup_id || 0, name: name, phone: w.phone || '' }, 'primary');
        }
        if (w.assignment_status === 'Assigned') {
            return '<span class="text-sm text-ink-muted">With ' + esc(w.worker_fname || 'a worker') + '</span>'
                + (s.isAdmin ? C.btn('Reassign', 'charis.assign-case', { user: w.target_user_id, followup: w.followup_id || 0 }, 'ghost') : '');
        }
        if (w.assignment_status === 'Requested') {
            return s.isAdmin
                ? '<span class="text-sm text-ink-muted">' + esc(w.worker_fname || 'A worker') + ' asked</span>' + C.btn('Approve', 'charis.approve-case', { id: w.assignment_id }, 'primary')
                : C.badge('Awaiting approval', 'warning');
        }
        return s.isAdmin
            ? C.btn('Assign', 'charis.assign-case', { user: w.target_user_id, followup: w.followup_id || 0 }, 'primary')
            : C.btn('Ask to handle', 'charis.request-case', { user: w.target_user_id, followup: w.followup_id || 0 }, 'secondary');
    }

    function welfareDetails(w) {
        if (w.alert_type !== 'AWOL') {
            return '<p class="text-sm text-ink-muted"><span class="font-medium text-ink">Reason:</span> ' + esc(w.flag_reason || w.notes || 'Manual welfare check') + '</p>';
        }
        var weeks = Number(w.weeks_away || 0);
        var headline = w.never_attended
            ? 'No attendance since they registered'
            : 'Away since <strong class="font-semibold text-ink">' + esc(awolDate(w.last_attended_date)) + '</strong>'
                + (weeks > 0 ? ' · ' + weeks + (weeks === 1 ? ' week' : ' weeks') : '');
        var strip = Array.isArray(w.attendance_strip) ? w.attendance_strip : [];
        var stripHtml = strip.length
            ? '<div class="flex items-center gap-1" role="img" aria-label="Recent Sundays, oldest first: '
                + esc(strip.map(function (x) { return x.present ? 'present' : 'missed'; }).join(', ')) + '">'
                + strip.map(function (x) {
                    return '<span class="inline-block h-2.5 w-2.5 rounded-full ' + (x.present ? 'bg-success' : 'bg-danger') + '" title="'
                        + esc(awolDate(x.date) + ': ' + (x.present ? 'present' : 'missed')) + '"></span>';
                }).join('')
                + '<span class="ml-1 text-xs text-ink-subtle">recent Sundays</span></div>'
            : '';
        var dates = Array.isArray(w.missed_service_dates) ? w.missed_service_dates : [];
        var datesHtml = dates.length
            ? '<details class="text-sm"><summary class="cursor-pointer text-ink-muted">' + dates.length + (dates.length === 1 ? ' missed date' : ' missed dates') + '</summary>'
                + '<p class="mt-1 text-ink-muted">' + dates.map(function (d) { return esc(awolDate(d)); }).join(' · ') + '</p></details>'
            : '';
        return '<p class="text-sm text-ink-muted">' + headline + '</p>' + stripHtml + datesHtml;
    }

    function renderWelfare() {
        var list = listEl();
        if (!list) return;
        setCount('awol', C.state.welfare.filter(function (w) { return w.alert_type === 'AWOL'; }).length);
        if (welfareTab === 'archive') { if (archive) renderArchive(); return; }

        var tabRows = rowsFor(welfareTab);
        var rows = tabRows.filter(matches);
        notesByUser = {};
        if (tabRows.length && !rows.length) { list.innerHTML = noMatchHtml(); return; }
        if (!rows.length) {
            if (welfareTab === 'awol_list' && !C.state.qualifying) list.innerHTML = C.emptyItem('No qualifying services yet', 'No Sunday service in the period matches the rule.');
            else if (welfareTab === 'awol_list') list.innerHTML = C.emptyItem('Nobody on the AWOL list', 'Everyone watched has been in church recently.');
            else if (welfareTab === 'manual_flags') list.innerHTML = C.emptyItem('No manual flags');
            else list.innerHTML = C.emptyItem('No cases for you', 'Cases assigned to you show here.');
            return;
        }

        list.innerHTML = rows.map(function (w) {
            var isAwol = w.alert_type === 'AWOL';
            var streak = Number(w.consecutive_missed != null ? w.consecutive_missed : (w.missed_count || 0));
            var badges = (isAwol ? C.badge(streak + (streak === 1 ? ' Sunday' : ' Sundays'), 'danger') : C.badge('Manual flag', 'warning'))
                + (isAwol && w.tier === 'Dormant' ? C.badge('Dormant', 'outline') : '');
            var notes = w.secure_notes || [];
            var canEdit = w.assignment_status === 'Assigned' && (C.state.isAdmin || Number(w.worker_id) === C.state.me);
            notesByUser[w.target_user_id] = { notes: notes, name: C.person(w), canEdit: canEdit };
            return '<li class="ui-list__item flex-col items-stretch gap-2">'
                + '<div class="flex items-start justify-between gap-3">'
                + '<div class="min-w-0"><p class="flex flex-wrap items-center gap-2 font-semibold text-ink">' + esc(C.person(w))
                + (w.spiritual_status ? C.badge(C.label(w.spiritual_status), 'outline') : '') + '</p>'
                + '<p class="truncate text-sm text-ink-subtle">' + esc([w.phone || 'No phone', w.physical_address].filter(Boolean).join(' · ')) + '</p></div>'
                + '<div class="flex shrink-0 flex-wrap justify-end gap-1">' + badges + '</div></div>'
                + welfareDetails(w)
                + '<div class="flex flex-wrap items-center gap-2">' + welfareAction(w)
                + C.btn((canEdit ? 'Notes' : 'View notes') + ' (' + notes.length + ')', 'charis.notes', { user: w.target_user_id }, 'ghost', 'message-square-text')
                + '</div></li>';
        }).join('');
    }

    function loadArchive() {
        var list = listEl();
        list.innerHTML = '<li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>';
        return C.api({ action: 'fetch_welfare_archive' }, { method: 'GET', quiet: true }).then(function (res) {
            archive = Array.isArray(res.data) ? res.data : [];
            if (welfareTab === 'archive') renderArchive();
        }).catch(function (err) {
            list.innerHTML = C.emptyItem('Could not load the archive', err.message);
        });
    }

    function renderArchive() {
        var list = listEl();
        var all = archive || [];
        var rows = all.filter(matches);
        if (all.length && !rows.length) { list.innerHTML = noMatchHtml(); return; }
        if (!rows.length) { list.innerHTML = C.emptyItem('No closed cases yet'); return; }
        list.innerHTML = rows.map(function (a) {
            var notes = (a.secure_notes || []).map(function (n) {
                return '<p><span class="font-medium text-ink">' + esc(n.author_display_name || C.person(n) || 'Unknown') + ':</span> ' + esc(n.note_text) + '</p>';
            }).join('') || '<p class="text-ink-subtle">No notes you can see.</p>';
            return '<li class="ui-list__item flex-col items-stretch gap-2">'
                + '<div class="flex items-start justify-between gap-3"><div class="min-w-0">'
                + '<p class="font-semibold text-ink">' + esc(C.person(a)) + '</p>'
                + '<p class="text-sm text-ink-subtle">' + esc([a.phone, 'Closed ' + (C.parseDate(a.archive_date) ? HOD.format.date(C.parseDate(a.archive_date)) : '')].filter(Boolean).join(' · ')) + '</p>'
                + '</div>' + C.badge('Resolved', 'success') + '</div>'
                + '<p class="text-sm text-ink-muted">Worker: ' + esc(a.worker_fname || 'Unassigned') + '</p>'
                + '<div class="space-y-1 rounded-ui bg-surface-sunken p-3 text-sm text-ink-muted">' + notes + '</div></li>';
        }).join('');
    }

    function selectWelfareTab(tab) {
        welfareTab = TAB_LABELS[tab] ? tab : 'my_cases';
        C.$$('#charis-awol [data-welfare-tab]').forEach(function (b) {
            if (b.classList.contains('ui-segmented__item')) b.setAttribute('aria-pressed', b.getAttribute('data-welfare-tab') === welfareTab ? 'true' : 'false');
        });
        if (welfareTab === 'archive') { if (archive) renderArchive(); else loadArchive(); }
        else renderWelfare();
    }

    HOD.actions.on('charis.welfare-tab', function (btn) { selectWelfareTab(btn.getAttribute('data-welfare-tab')); });
    HOD.actions.on('charis.welfare-search', HOD.debounce(function (input) {
        welfareQuery = input.value;
        if (welfareTab === 'archive') { if (archive) renderArchive(); } else renderWelfare();
    }, 150));
    HOD.actions.on('charis.welfare-clear', function () {
        var input = C.$('#charis-welfare-search');
        input.value = '';
        welfareQuery = '';
        selectWelfareTab(welfareTab);
        input.focus();
    });

    HOD.actions.on('charis.awol-focus', function () {
        if (!C.state.awolConfig && C.state.canConfigure) { openAwolConfig(true); return; }
        var card = C.$('#charis-awol');
        card.scrollIntoView({ behavior: 'smooth', block: 'start' });
        card.focus({ preventScroll: true });
    });

    /* ----- The active rule --------------------------------------------- */

    var RULE_KEY = 'charis.awolRuleCardOpen';
    var ruleOpen = false;
    try { ruleOpen = localStorage.getItem(RULE_KEY) === '1'; } catch (e) { /* private mode */ }

    function applyRuleOpen() {
        var toggle = C.$('[data-action="charis.rule-toggle"]');
        if (!toggle) return;
        toggle.setAttribute('aria-expanded', ruleOpen ? 'true' : 'false');
        var chevron = toggle.querySelector('.ui-icon:last-child');
        if (chevron) chevron.classList.toggle('rotate-180', ruleOpen);
        C.$('#charis-awol-rule-body').hidden = !ruleOpen;
    }

    function renderRule() {
        var c = C.state.awolConfig;
        var d = cfg.defaults || {};
        var count = C.state.welfare.filter(function (w) { return w.alert_type === 'AWOL'; }).length;
        C.setText('[data-charis-rule-count]', count + ' matching');
        var pills = C.$('[data-charis-rule-pills]');
        if (!c) {
            C.setText('[data-charis-rule-summary]', (d.consecutive || 3) + ' Sundays in a row (default)');
            pills.innerHTML = '<span class="ui-chip">Default: ' + esc(d.consecutive || 3) + ' Sundays missed in a row</span>';
            applyRuleOpen();
            return;
        }
        var n = c.consecutive_threshold != null ? c.consecutive_threshold : c.services_missed;
        C.setText('[data-charis-rule-summary]', n + ' Sundays in a row');
        pills.innerHTML = [
            'Missed: <strong>' + esc(n) + ' Sundays in a row</strong>',
            'Dormant after: <strong>' + esc(c.archive_weeks != null ? c.archive_weeks : d.archiveWeeks) + ' weeks</strong>',
            'Services: <strong>' + esc((c.service_types || []).map(C.label).join(', ')) + '</strong>',
            'Statuses: <strong>' + esc((c.spiritual_statuses || []).map(C.label).join(', ')) + '</strong>',
            'Qualifying held: <strong>' + esc(C.state.qualifying) + '</strong>'
        ].map(function (t) { return '<span class="ui-chip">' + t + '</span>'; }).join('');
        applyRuleOpen();
    }

    HOD.actions.on('charis.rule-toggle', function () {
        ruleOpen = !ruleOpen;
        try { localStorage.setItem(RULE_KEY, ruleOpen ? '1' : '0'); } catch (e) { /* private mode */ }
        applyRuleOpen();
    });

    /* ----- The rule editor --------------------------------------------- */

    function ruleDefaults() {
        var d = cfg.defaults || {};
        return {
            consecutive_threshold: d.consecutive || 3,
            archive_weeks: d.archiveWeeks || 12,
            service_rules: {
                Sunday_Service: { counts_absence: true, resets_clock: true },
                Midweek_Service: { counts_absence: false, resets_clock: true }
            },
            spiritual_statuses: ['Member', 'Worker', 'Pastor']
        };
    }

    function collectRules() {
        var rules = {};
        C.$$('#charis-awol-config-form [data-service]').forEach(function (box) {
            var svc = box.getAttribute('data-service');
            rules[svc] = rules[svc] || {};
            rules[svc][box.getAttribute('data-rule') === 'counts' ? 'counts_absence' : 'resets_clock'] = box.checked;
        });
        return rules;
    }

    function updatePreview() {
        var missed = parseInt(C.$('#charis-awol-threshold').value, 10) || 1;
        var weeks = parseInt(C.$('#charis-awol-archive').value, 10) || 1;
        var rules = collectRules();
        C.$('#charis-awol-rules').value = JSON.stringify(rules);
        var counting = Object.keys(rules).filter(function (k) { return rules[k].counts_absence; }).map(C.label);
        var resetting = Object.keys(rules).filter(function (k) { return rules[k].resets_clock; }).map(C.label);
        var statuses = C.$$('#charis-awol-statuses input:checked').map(function (b) { return C.label(b.value); });
        C.setText('#charis-awol-preview',
            'Flag ' + (statuses.length ? statuses.join(', ') : 'nobody (pick a status)') + ' after ' + missed + ' missed '
            + (counting.length ? counting.join(' or ') : 'services (none count yet)') + (missed === 1 ? '' : 's') + ' in a row. '
            + 'Attending ' + (resetting.length ? resetting.join(' or ') : 'nothing') + ' resets the count. '
            + 'Dormant after ' + weeks + (weeks === 1 ? ' week' : ' weeks') + ' away.');
    }

    function renderHistory() {
        var box = C.$('#charis-awol-history');
        var rows = (C.state.awolHistory || []).slice(0, 5);
        box.innerHTML = rows.length ? rows.map(function (h) {
            var types = Array.isArray(h.service_types) ? h.service_types.map(C.label).join(', ') : '';
            var statuses = Array.isArray(h.spiritual_statuses) ? h.spiritual_statuses.map(C.label).join(', ') : '';
            return '<div class="rounded-ui bg-surface-sunken p-3 text-sm">'
                + '<p class="flex justify-between gap-2"><span class="font-semibold text-ink">' + esc(h.created_by_name || 'Admin') + '</span>'
                + '<span class="text-ink-subtle">' + esc(C.when(h.created_at, true)) + '</span></p>'
                + '<p class="text-ink-muted">' + esc(h.consecutive_threshold != null ? h.consecutive_threshold : h.services_missed) + ' in a row across ' + esc(types)
                + (h.archive_weeks ? ', Dormant after ' + esc(h.archive_weeks) + ' weeks' : '') + '</p>'
                + '<p class="text-ink-muted">Statuses: ' + esc(statuses) + '</p>'
                + (h.change_reason ? '<p class="italic text-ink-subtle">“' + esc(h.change_reason) + '”</p>' : '')
                + '</div>';
        }).join('') : '<p class="text-sm text-ink-subtle">No changes yet.</p>';
    }

    function openAwolConfig(first) {
        var defaults = ruleDefaults();
        var c = C.state.awolConfig || defaults;
        C.$('#charis-awol-threshold').value = c.consecutive_threshold || c.services_missed || defaults.consecutive_threshold;
        C.$('#charis-awol-archive').value = c.archive_weeks || defaults.archive_weeks;
        var rules = (c.service_rules && typeof c.service_rules === 'object') ? c.service_rules : defaults.service_rules;
        C.$$('#charis-awol-config-form [data-service]').forEach(function (box) {
            var rule = rules[box.getAttribute('data-service')] || {};
            box.checked = !!(box.getAttribute('data-rule') === 'counts' ? rule.counts_absence : rule.resets_clock);
        });
        var statuses = Array.isArray(c.spiritual_statuses) && c.spiritual_statuses.length ? c.spiritual_statuses : defaults.spiritual_statuses;
        C.$$('#charis-awol-statuses input').forEach(function (box) { box.checked = statuses.indexOf(box.value) !== -1; });
        C.$('#charis-awol-first').value = first ? '1' : '0';
        C.setText('#charis-awol-config-title', first ? 'Set the AWOL rule' : 'Change the AWOL rule');
        C.$('#charis-awol-reason').value = '';
        updatePreview();
        renderHistory();
        HOD.dialog.open('charis-awol-config');
    }

    HOD.actions.on('charis.awol-config', function () { openAwolConfig(false); });
    HOD.actions.on('charis.awol-preview', updatePreview);
    HOD.actions.on('charis.awol-save', function (form, event) {
        updatePreview();
        var btn = (event && event.submitter) || C.$('[form="charis-awol-config-form"][type="submit"]');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Rule saved.', 'success');
            if (res.config) C.state.awolConfig = res.config;
            if (Array.isArray(res.history)) C.state.awolHistory = res.history;
            HOD.dialog.close('charis-awol-config');
            loadDashboard();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Cases: manage, assign, request, approve --------------------- */

    function intlPhone(phone) {
        var clean = String(phone || '').replace(/\D/g, '');
        if (!clean) return '';
        if (clean.indexOf('234') === 0) return '234' + clean.slice(3).replace(/^0/, '');
        return clean.charAt(0) === '0' ? '234' + clean.slice(1) : '234' + clean;
    }

    function setLink(id, href) {
        var a = C.$(id);
        a.setAttribute('href', href || '#');
        a.classList.toggle('pointer-events-none', !href);
        a.classList.toggle('opacity-50', !href);
        if (href) a.removeAttribute('aria-disabled'); else a.setAttribute('aria-disabled', 'true');
    }

    HOD.actions.on('charis.case', function (btn) {
        var uid = btn.getAttribute('data-user');
        var fid = btn.getAttribute('data-followup') || '0';
        C.$('#charis-case-form').reset();
        C.$('#charis-resolve-form').reset();
        resetDictation('charis-case-notes');
        resetDictation('charis-resolve-notes');
        C.setText('#charis-case-desc', btn.getAttribute('data-name'));
        C.$('#charis-case-user').value = uid;
        C.$('#charis-case-followup').value = fid;
        C.$('#charis-resolve-user').value = uid;
        C.$('#charis-resolve-followup').value = fid;
        var phone = intlPhone(btn.getAttribute('data-phone'));
        setLink('#charis-case-call', phone ? 'tel:+' + phone : '');
        setLink('#charis-case-whatsapp', phone ? 'https://wa.me/' + phone : '');
        HOD.dialog.open('charis-case', btn);
    });

    HOD.actions.on('charis.assign-case', function (btn) {
        C.$('#charis-assign-case-target').value = btn.getAttribute('data-user');
        C.$('#charis-assign-case-followup').value = btn.getAttribute('data-followup') || '0';
        C.$('#charis-case-worker').value = '';
        HOD.dialog.open('charis-assign-case', btn);
    });

    function quickPost(btn, data) {
        HOD.busy(btn, true);
        C.api(data).then(function (res) { HOD.toast(res.message || 'Done.', 'success'); loadDashboard(); })
            .catch(function () { HOD.busy(btn, false); });
    }
    HOD.actions.on('charis.request-case', function (btn) {
        quickPost(btn, { action: 'request_welfare_case', target_user_id: btn.getAttribute('data-user'), followup_id: btn.getAttribute('data-followup') || 0 });
    });
    HOD.actions.on('charis.approve-case', function (btn) {
        quickPost(btn, { action: 'approve_welfare_case', assignment_id: btn.getAttribute('data-id') });
    });

    /* ----- Welfare notes ----------------------------------------------- */

    var notesFor = null;

    function resetNoteForm() {
        C.$('#charis-note-id').value = '';
        C.$('#charis-note-text').value = '';
        C.$('#charis-note-pastor').checked = false;
        C.setText('label[for="charis-note-text"]', 'New note');
        C.setLabel(C.$('#charis-note-save'), 'Save note');
        C.$('#charis-note-cancel').hidden = true;
        resetDictation('charis-note-text');
    }

    function renderNotes() {
        var info = notesByUser[notesFor] || { notes: [], name: '', canEdit: false };
        var box = C.$('#charis-notes-list');
        box.innerHTML = info.notes.length ? info.notes.map(function (n) {
            var vis = [];
            try { vis = JSON.parse(n.visible_to) || []; } catch (e) { vis = []; }
            var pastorOnly = vis.indexOf('Pastors') !== -1 && vis.indexOf('All') === -1;
            var mine = info.canEdit && Number(n.author_id) === C.state.me;
            return '<article class="rounded-ui border border-line p-3">'
                + '<div class="mb-1 flex flex-wrap items-center gap-2 text-sm"><span class="font-semibold text-ink">' + esc(n.author_display_name || C.person(n) || 'Unknown') + '</span>'
                + '<span class="text-ink-subtle">' + esc(C.when(n.created_at, true)) + '</span>'
                + (pastorOnly ? C.badge('Pastors only', 'brand') : '') + '</div>'
                + '<p class="whitespace-pre-line text-sm text-ink-muted">' + esc(n.note_text) + '</p>'
                + (mine ? '<div class="mt-2">' + C.btn('Edit', 'charis.note-edit', { id: n.id }, 'ghost', 'pencil') + '</div>' : '')
                + '</article>';
        }).join('') : '<p class="text-sm text-ink-subtle">No notes yet.</p>';
    }

    HOD.actions.on('charis.notes', function (btn) {
        notesFor = btn.getAttribute('data-user');
        var info = notesByUser[notesFor] || { canEdit: false, name: '' };
        C.$('#charis-note-target').value = notesFor;
        C.setText('#charis-notes-desc', info.name);
        resetNoteForm();
        C.$('#charis-note-form').hidden = !info.canEdit;
        C.$('#charis-note-pastor-wrap').hidden = !info.canEdit || !C.state.isPastor;
        renderNotes();
        HOD.dialog.open('charis-notes', btn);
    });

    HOD.actions.on('charis.note-edit', function (btn) {
        var info = notesByUser[notesFor] || { notes: [] };
        var note = info.notes.filter(function (n) { return String(n.id) === btn.getAttribute('data-id'); })[0];
        if (!note) return;
        var vis = [];
        try { vis = JSON.parse(note.visible_to) || []; } catch (e) { vis = []; }
        C.$('#charis-note-id').value = note.id;
        C.$('#charis-note-text').value = note.note_text;
        C.$('#charis-note-pastor').checked = vis.indexOf('Pastors') !== -1 && vis.indexOf('All') === -1;
        C.setText('label[for="charis-note-text"]', 'Edit your note');
        C.setLabel(C.$('#charis-note-save'), 'Update note');
        C.$('#charis-note-cancel').hidden = false;
        C.$('#charis-note-text').focus();
    });
    HOD.actions.on('charis.note-reset', resetNoteForm);

    HOD.actions.on('charis.note-save', function (form, event) {
        var btn = (event && event.submitter) || C.$('#charis-note-save');
        HOD.busy(btn, true);
        C.api(new FormData(form)).then(function (res) {
            HOD.toast(res.message || 'Note saved.', 'success');
            HOD.dialog.close('charis-notes');
            loadDashboard().then(function () { if (welfareTab === 'archive') loadArchive(); });
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Voice notes and "Tidy" -------------------------------------- */

    var dictation = null;
    var dictationTarget = null;

    function dictationUi(target, on) {
        var hint = C.$('[data-dictate-hint="' + target + '"]');
        if (hint) hint.hidden = !on;
        var mic = C.$('[data-dictate-mic="' + target + '"]');
        if (mic) {
            mic.setAttribute('aria-pressed', on ? 'true' : 'false');
            mic.classList.toggle('ui-btn--danger', on);
            mic.classList.toggle('animate-pulse', on);
            mic.classList.toggle('ui-btn--soft', !on);
        }
    }
    function stopDictation() {
        if (dictation) { try { dictation.stop(); } catch (e) { /* already stopped */ } }
    }
    function resetDictation(target) {
        if (dictationTarget === target) stopDictation();
        dictationUi(target, false);
        var review = C.$('[data-dictate-review="' + target + '"]');
        if (review) { review.hidden = true; delete review.dataset.clean; }
    }

    HOD.actions.on('charis.dictate', function (btn) {
        var target = btn.getAttribute('data-target');
        var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SR) { HOD.toast('Voice typing needs Chrome. Type instead.', 'warning'); return; }
        if (dictationTarget === target) { stopDictation(); return; }
        if (dictation) {
            var prev = dictationTarget;
            try { dictation.abort(); } catch (e) { /* ignore */ }
            dictation = null;
            dictationTarget = null;
            dictationUi(prev, false);
        }
        var r = new SR();
        r.lang = 'en-NG';
        r.interimResults = false;
        r.continuous = true;
        r.onresult = function (evt) {
            var text = '';
            for (var i = evt.resultIndex; i < evt.results.length; i++) {
                if (evt.results[i].isFinal) text += evt.results[i][0].transcript;
            }
            text = text.trim();
            if (!text) return;
            var field = document.getElementById(target);
            var before = String(field.value || '').replace(/\s*$/, '');
            field.value = before ? before + ' ' + text : text;
        };
        r.onend = function () {
            if (dictation === r) { dictation = null; dictationTarget = null; }
            dictationUi(target, false);
        };
        r.onerror = function (evt) {
            if (evt && evt.error === 'no-speech') return;
            HOD.toast(evt && evt.error === 'not-allowed' ? 'The microphone is blocked. Allow it, or type instead.' : 'Microphone problem. Type instead.', 'warning');
        };
        try {
            r.start();
            dictation = r;
            dictationTarget = target;
            var review = C.$('[data-dictate-review="' + target + '"]');
            if (review) review.hidden = true;
            dictationUi(target, true);
        } catch (e) {
            HOD.toast('The microphone is blocked. Type instead.', 'danger');
        }
    });

    HOD.actions.on('charis.tidy', function (btn) {
        var target = btn.getAttribute('data-target');
        stopDictation();
        var field = document.getElementById(target);
        var notes = String(field.value || '').trim();
        if (!notes) { HOD.toast('Write or speak the notes first.', 'warning'); field.focus(); return; }
        HOD.busy(btn, true);
        C.api({ action: 'clean_welfare_notes', notes: notes, kind: btn.getAttribute('data-kind') || 'update' }, { quiet: true }).then(function (res) {
            var clean = res.data && res.data.notes_clean;
            var review = C.$('[data-dictate-review="' + target + '"]');
            if (!clean || !review) return;
            review.querySelector('[data-dictate-clean-text]').textContent = clean;
            review.dataset.clean = clean;
            review.hidden = false;
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK' ? 'Network problem. Your notes are safe.' : err.message, 'warning');
        }).then(function () { HOD.busy(btn, false); });
    });
    HOD.actions.on('charis.tidy-use', function (btn) {
        var target = btn.getAttribute('data-target');
        var review = C.$('[data-dictate-review="' + target + '"]');
        if (review && review.dataset.clean) document.getElementById(target).value = review.dataset.clean;
        resetDictation(target);
    });
    HOD.actions.on('charis.tidy-drop', function (btn) { resetDictation(btn.getAttribute('data-target')); });

    document.addEventListener('ui:dialog-close', stopDictation);

    /* ----- The AWOL report --------------------------------------------- */

    var reportMode = 'month';
    HOD.actions.on('charis.report-mode', function (btn) {
        reportMode = btn.getAttribute('data-mode') === 'range' ? 'range' : 'month';
        C.$$('[data-action="charis.report-mode"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b === btn ? 'true' : 'false');
        });
        C.$$('[data-report-fields]').forEach(function (box) { box.hidden = box.getAttribute('data-report-fields') !== reportMode; });
    });
    HOD.actions.on('charis.report-download', function () {
        var params = new URLSearchParams({ mode: reportMode });
        if (reportMode === 'month') {
            params.set('month', C.$('#charis-rpt-month').value);
            params.set('year', C.$('#charis-rpt-year').value);
        } else {
            var from = C.$('#charis-rpt-from').value;
            var to = C.$('#charis-rpt-to').value;
            if (!from || !to) { HOD.toast('Pick both dates.', 'warning'); return; }
            params.set('date_start', from);
            params.set('date_end', to);
        }
        window.open('/includes/charis_awol_pdf.php?' + params.toString(), '_blank', 'noopener');
        HOD.dialog.close('charis-awol-report');
    });

    /* =================================================================== */

    function init() {
        applyRuleOpen();
        loadDashboard();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
