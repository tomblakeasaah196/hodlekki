// /assets/ui/js/pages/events-attendance.js
// The Events page's Attendance tab: the roster (pending / checked in), quick
// search over the pending people, the count tiles and what is behind them,
// Verify details, and "Add to congregation" (a first timer by QR or with
// staff, or a member) which clocks the new profile straight in.
// Data: /api/events_api.php; a new member goes through
// /api/congregation_api.php (create_member), as before.
(function () {
    'use strict';

    var EV = window.HODEvents;
    if (!EV) return;
    var $ = EV.$, $$ = EV.$$, esc = HOD.esc, num = EV.num;
    var CONG_API = '/api/congregation_api.php';
    var EVENT_KEY = 'events.attendance.selectedEventId';
    var SPIRITUAL = ['Visitor', '1st_Timer', '2nd_Timer', '3rd_Timer', 'Member', 'Worker', 'Pastor', 'Non_Member'];
    var ATTENDANCE = ['New', 'Active', 'Inconsistent', 'Unknown', 'Relocated', 'Attends_Another_Church'];
    var MARITAL = ['Single', 'Married', 'Separated', 'Divorced'];

    var select = $('#att-event');
    var search = $('#att-search');
    var pop = $('#att-pop');
    var att = { eventId: '', roster: null, kpis: null, popOpen: false, verify: null, wiz: null };
    var restored = false;

    function name(p) { return (((p && p.first_name) || '') + ' ' + ((p && p.last_name) || '')).trim() || 'Unnamed'; }
    function isPhone() { return window.matchMedia('(max-width: 767px)').matches; }
    function timeOnly(v) {
        if (!v) return '—';
        var d = EV.parseDate(v);
        return isNaN(d.getTime()) ? '—' : d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    function dateTime(v) {
        if (!v) return '—';
        var d = EV.parseDate(v);
        return isNaN(d.getTime()) ? String(v) : d.toLocaleString('en-US', { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    }
    function eventLabel() { var o = select.options[select.selectedIndex]; return o && o.value ? o.textContent : 'this event'; }

    /* ---------- Choosing the event ---------- */

    function persist(id) {
        try { if (id) sessionStorage.setItem(EVENT_KEY, String(id)); else sessionStorage.removeItem(EVENT_KEY); } catch (e) { /* private mode */ }
        try {
            var url = new URL(window.location.href);
            if (id) url.searchParams.set('attendance_event', id); else url.searchParams.delete('attendance_event');
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (e) { /* old browsers */ }
    }

    function chooseEvent(id) {
        att.eventId = id || '';
        att.kpis = null;
        att.roster = null;
        closePop();
        persist(att.eventId);
        $('#att-none').hidden = !!att.eventId;
        $('#att-body').hidden = !att.eventId;
        $('#att-add').hidden = !att.eventId;
        search.disabled = !att.eventId;
        if (!att.eventId) { search.value = ''; return; }
        kpiMode(document.activeElement === search);
        loadRoster();
        loadKpis();
    }

    HOD.actions.on('att.event', function (sel) { chooseEvent(sel.value); });

    function visible() { var p = $('#panel-attendance'); return p && !p.hidden; }

    document.addEventListener('ev:loaded', function () {
        if (restored) return;
        restored = true;
        var wanted = '';
        try { wanted = new URLSearchParams(window.location.search).get('attendance_event') || ''; } catch (e) { /* old browsers */ }
        if (!wanted) { try { wanted = sessionStorage.getItem(EVENT_KEY) || ''; } catch (e) { /* private mode */ } }
        if (!wanted) return;
        var has = Array.prototype.some.call(select.options, function (o) { return o.value === String(wanted); });
        if (!has) { persist(''); return; }
        select.value = String(wanted);
        if (visible()) chooseEvent(select.value);
    });

    document.addEventListener('ui:tab', function (e) {
        if (e.detail && e.detail.tab === 'attendance' && select.value && select.value !== att.eventId) chooseEvent(select.value);
    });

    /* ---------- Counts ---------- */

    function kpiMode(searching) {
        if (!att.eventId) return;
        var hide = searching && isPhone();
        $('#att-kpis').hidden = hide;
        $('#att-kpi-toggle').hidden = !hide;
    }

    function setTile(card, label, value, sub) {
        $('[data-att-kpi-label="' + card + '"]').textContent = label;
        $('[data-att-kpi-value="' + card + '"]').innerHTML = value;
        $('[data-att-kpi-sub="' + card + '"]').innerHTML = sub;
    }

    function renderKpis(k) {
        att.kpis = k;
        if ((k.mode || 'workflow') === 'attendance') {
            var g = k.gender || {}, mix = k.member_mix || {};
            var unknown = Number(g.unknown || 0);
            setTile('card1', 'Attendance', num(k.total), 'Present' + (k.registered ? ' · ' + num(k.registered) + ' registered' : ''));
            setTile('card2', 'Gender split', '<span class="text-primary">' + num(g.male) + '</span> <span class="text-ink-subtle">/</span> <span class="text-brand-red">' + num(g.female) + '</span>',
                unknown ? '<span class="text-warning">' + num(unknown) + ' gender not on file</span>' : 'Male / female');
            setTile('card3', 'Members / timers', num(mix.members) + ' <span class="text-ink-subtle">/</span> ' + num(mix.timers), 'Members / 1st to 3rd timers');
            return;
        }
        var gr = Number(k.growth || 0);
        setTile('card1', 'Checked in', num(k.total), num(k.registered) + ' registered');
        setTile('card2', 'Today', num(k.today), gr ? EV.delta(gr, ' vs yesterday') : 'Same as yesterday');
        setTile('card3', 'Members / walk-ins', num(k.members) + ' <span class="text-ink-subtle">/</span> ' + num(k.walkins), 'Members / walk-ins');
    }

    function loadKpis() {
        var id = att.eventId;
        EV.api('attendance_kpis', { event_id: id }, { quiet: true }).then(function (res) {
            if (id !== att.eventId || !res.kpis) return;
            renderKpis(res.kpis);
        }).catch(function () {});
    }

    HOD.actions.on('att.show-kpis', function () {
        search.blur();
        kpiMode(false);
        $('#att-kpis').scrollIntoView({ block: 'nearest' });
    });

    function detailRow(r) {
        var nm = r.name || ((r.first_name || '') + ' ' + (r.last_name || '')).trim() || 'Unnamed attendee';
        return '<li class="ui-list__item"><span class="ui-list__main"><span class="ui-list__title">' + esc(nm) + '</span>' +
            '<span class="ui-list__meta">' + esc([r.phone || 'No phone', EV.statusLabel(r.spiritual_status || r.status), r.gender || '—'].join(' · ')) + '</span></span>' +
            '<span class="ui-list__end"><span class="ui-badge ui-badge--info ui-num">' + esc(dateTime(r.marked_at || r.checked_in_at || r.check_in_time)) + '</span></span></li>';
    }

    function groupList(rows) {
        return rows.length ? '<ul class="ui-list">' + rows.map(detailRow).join('') + '</ul>'
            : '<p class="ui-meta px-4 py-6 text-center">No one in this group.</p>';
    }

    HOD.actions.on('att.kpi', function (btn) {
        var key = btn.getAttribute('data-card');
        var d = att.kpis && att.kpis.details && att.kpis.details[key];
        if (!d) { HOD.toast('The counts are still loading.', 'warning'); return; }
        $('#att-kpi-title').textContent = d.title || 'Details';
        $('#att-kpi-desc').textContent = d.subtitle || 'Attendance details for this event.';
        var groups = d.groups || [];
        var html;
        if (groups.length > 1) {
            html = groups.map(function (g, i) {
                var id = 'att-kpi-g' + i;
                var rows = g.rows || [];
                return '<section class="overflow-hidden rounded-ui border border-line">' +
                    '<button type="button" class="flex w-full items-center gap-2 bg-surface px-4 py-3 text-left hover:bg-surface-hover" data-action="att.kpi-group" aria-expanded="false" aria-controls="' + id + '">' +
                        '<span class="flex-1 font-semibold text-ink">' + esc(g.label || 'Details') + '</span><span class="ui-badge ui-num">' + num(rows.length) + '</span>' +
                        HOD.icon('chevron-down', 'transition-transform') + '</button>' +
                    '<div class="border-t border-line" id="' + id + '" hidden>' + groupList(rows) + '</div></section>';
            }).join('');
        } else {
            html = groups.map(function (g) {
                var rows = g.rows || [];
                return '<section class="overflow-hidden rounded-ui border border-line"><div class="flex items-center gap-2 bg-surface-sunken px-4 py-3">' +
                    '<h3 class="flex-1 font-semibold text-ink">' + esc(g.label || 'Details') + '</h3><span class="ui-badge ui-num">' + num(rows.length) + '</span></div>' + groupList(rows) + '</section>';
            }).join('');
        }
        $('#att-kpi-body').innerHTML = html || EV.emptyHtml('users', 'No details yet', '');
        HOD.dialog.open('att-kpi', btn);
    });

    HOD.actions.on('att.kpi-group', function (btn) {
        var body = document.getElementById(btn.getAttribute('aria-controls'));
        var open = btn.getAttribute('aria-expanded') !== 'true';
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        body.hidden = !open;
        var chev = btn.querySelector('.ui-icon:last-child');
        if (chev) chev.classList.toggle('rotate-180', open);
    });

    /* ---------- Roster ---------- */

    function rosterItem(u, checked) {
        var nm = name(u);
        var hay = (nm + ' ' + (u.phone || '') + ' ' + (u.spiritual_status || '')).toLowerCase();
        var meta = EV.statusLabel(u.spiritual_status) + ' · ' + (u.phone || 'No phone');
        var actions = checked
            ? '<span class="ui-badge ui-badge--success ui-num">' + HOD.icon('clock', 'ui-icon--xs') + esc(timeOnly(u.check_in_time)) + '</span>' +
              '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="att.verify" data-uid="' + Number(u.id) + '">Verify</button>' +
              '<button type="button" class="ui-btn ui-btn--danger-ghost ui-btn--sm" data-action="att.clock-out" data-uid="' + Number(u.id) + '">Clock out</button>'
            : '<button type="button" class="ui-btn ui-btn--brand ui-btn--sm" data-action="att.clock-in" data-uid="' + Number(u.id) + '">Clock in</button>' +
              '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="att.verify" data-uid="' + Number(u.id) + '">Verify</button>';
        return '<li class="ui-list__item flex-wrap" data-roster="' + esc(hay) + '"><span class="ui-list__main"><span class="ui-list__title">' + esc(nm) + '</span>' +
            '<span class="ui-list__meta">' + esc(meta) + '</span></span><span class="ui-list__end flex flex-wrap items-center justify-end gap-1">' + actions + '</span></li>';
    }

    function renderRoster() {
        var r = att.roster;
        if (!r || r.loading) return;
        $('#att-pending-count').textContent = num(r.pending.length);
        $('#att-in-count').textContent = num(r.checkedIn.length);
        $('#att-pending').innerHTML = r.pending.length ? r.pending.map(function (u) { return rosterItem(u, false); }).join('')
            : '<li class="ui-list__item"><span class="ui-meta">Everyone is in.</span></li>';
        $('#att-in').innerHTML = r.checkedIn.length ? r.checkedIn.map(function (u) { return rosterItem(u, true); }).join('')
            : '<li class="ui-list__item"><span class="ui-meta">No one yet.</span></li>';
        filterRoster();
    }

    function filterRoster() {
        var q = (search.value || '').trim().toLowerCase();
        $$('[data-roster]').forEach(function (li) { li.hidden = !!q && li.getAttribute('data-roster').indexOf(q) === -1; });
    }

    function loadRoster() {
        var id = att.eventId;
        var hadRoster = att.roster && att.roster.eventId === id;
        att.roster = { eventId: id, loading: true, pending: [], checkedIn: [] };
        if (!hadRoster) {
            var sk = '<li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>';
            $('#att-pending').innerHTML = sk + sk + sk;
            $('#att-in').innerHTML = sk;
        }
        return EV.api('fetch_attendance_roster', { event_id: id }, { quiet: true }).then(function (res) {
            if (id !== att.eventId) return;
            att.roster = { eventId: id, loading: false, pending: res.pending || [], checkedIn: res.checked_in || [] };
            renderRoster();
            renderPop();
        }).catch(function (err) {
            if (id !== att.eventId) return;
            att.roster = { eventId: id, loading: false, pending: [], checkedIn: [] };
            $('#att-pending').innerHTML = '<li class="ui-list__item"><span class="text-sm text-danger">' + esc(err.message || 'The roster did not load. Try again.') + '</span></li>';
            $('#att-in').innerHTML = '<li class="ui-list__item"><span class="ui-meta">—</span></li>';
            renderPop();
        });
    }

    function refreshAll() { if (att.eventId) { loadRoster(); loadKpis(); } }

    function findPerson(uid) {
        if (!att.roster) return null;
        return att.roster.pending.concat(att.roster.checkedIn).filter(function (p) { return Number(p.id) === Number(uid); })[0] || null;
    }

    /** Clocks someone in; resolves with the API's reply (or null), and always refreshes. */
    function clockIn(uid, nm) {
        if (!att.eventId) { HOD.toast('Choose an event first.', 'warning'); return Promise.resolve(null); }
        return EV.api('mark_attendance', { event_id: att.eventId, user_id: uid }, { quiet: true }).then(function (res) {
            HOD.toast(nm + ' clocked in' + (res.clock_time ? ' at ' + res.clock_time : '') + '.', 'success');
            return res;
        }, function (err) {
            var r = err.response;
            if (r && r.status === 'warning') { HOD.toast(r.message, 'warning'); return r; }
            HOD.toast(err.message || 'Could not clock in.', 'danger');
            return null;
        }).then(function (res) { refreshAll(); return res; });
    }

    function clockOut(uid, nm) {
        return EV.api('clock_out', { event_id: att.eventId, user_id: uid }, { quiet: true }).then(function (res) {
            HOD.toast(nm + ' is back in Pending.', 'success');
            return res;
        }, function (err) {
            var r = err.response;
            if (r && r.status === 'warning') { HOD.toast(r.message, 'warning'); return r; }
            HOD.toast(err.message || 'Could not clock out.', 'danger');
            return null;
        }).then(function (res) { refreshAll(); return res; });
    }

    function askClockOut(nm) {
        return HOD.confirm({
            title: 'Clock out ' + nm + '?',
            message: 'Their check-in for ' + eventLabel() + ' is removed and they move back to Pending.',
            confirmLabel: 'Clock out',
            tone: 'danger'
        });
    }

    HOD.actions.on('att.clock-in', function (btn) {
        var uid = btn.getAttribute('data-uid');
        var p = findPerson(uid);
        HOD.busy(btn, true);
        clockIn(uid, p ? name(p) : 'Attendee');
    });

    HOD.actions.on('att.clock-out', function (btn) {
        var uid = btn.getAttribute('data-uid');
        var p = findPerson(uid);
        var nm = p ? name(p) : 'this person';
        askClockOut(nm).then(function (ok) {
            if (!ok) return;
            HOD.busy(btn, true);
            clockOut(uid, nm);
        });
    });

    /* ---------- Quick search (pending people only) ---------- */

    function closePop() {
        if (!att.popOpen) return;
        att.popOpen = false;
        pop.hidden = true;
        search.setAttribute('aria-expanded', 'false');
    }

    function openPop() {
        if (!att.eventId) return;
        att.popOpen = true;
        renderPop();
    }

    function popRow(u) {
        var nm = name(u);
        return '<li class="ui-list__item" data-pop-row="' + Number(u.id) + '"><span class="ui-list__main"><span class="ui-list__title">' + esc(nm) + '</span>' +
            '<span class="ui-list__meta">' + esc(EV.statusLabel(u.spiritual_status) + ' · ' + (u.phone || 'No phone')) + '</span></span>' +
            '<span class="ui-list__end flex gap-1" data-pop-actions>' +
                '<button type="button" class="ui-btn ui-btn--brand ui-btn--sm ui-btn--icon" data-action="att.pop-clock" data-uid="' + Number(u.id) + '" aria-label="Clock in ' + esc(nm) + '" data-tooltip="Clock in">' + HOD.icon('clock') + '</button>' +
                '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm ui-btn--icon" data-action="att.verify" data-uid="' + Number(u.id) + '" aria-label="Verify ' + esc(nm) + '" data-tooltip="Verify">' + HOD.icon('eye') + '</button>' +
            '</span></li>';
    }

    function renderPop() {
        if (!att.popOpen) { pop.hidden = true; return; }
        var q = (search.value || '').trim().toLowerCase();
        var html;
        if (!att.roster || att.roster.loading) {
            // During a refresh (right after a clock-in) keep the rows on screen.
            if (pop.children.length) { pop.hidden = false; return; }
            html = '<div class="flex items-center justify-center gap-2 px-4 py-6 text-sm text-ink-muted"><span class="ui-spinner" aria-hidden="true"></span>Loading roster…</div>';
        } else if (!q) {
            html = '<div class="px-4 py-5 text-center"><p class="text-sm text-ink-muted">Type to find someone waiting to clock in.</p>' +
                '<p class="ui-meta mt-1">' + EV.plural(att.roster.pending.length, 'person') .replace('persons', 'people') + ' pending</p></div>';
        } else {
            var matches = att.roster.pending.filter(function (u) { return (name(u) + ' ' + (u.phone || '')).toLowerCase().indexOf(q) > -1; });
            if (!matches.length) {
                html = '<div class="px-4 py-5 text-center"><p class="text-sm font-semibold text-ink">No pending match</p>' +
                    '<p class="ui-meta mt-1">They may be checked in already, or not on this roster.</p>' +
                    '<button type="button" class="ui-btn ui-btn--primary ui-btn--sm mt-3" data-action="att.add">' + HOD.icon('user-round-plus') + '<span>Add them</span></button></div>';
            } else {
                html = '<p class="border-b border-line bg-surface-sunken px-4 py-2 text-xs font-semibold uppercase tracking-wide text-ink-subtle">' +
                    num(matches.length) + ' pending match' + (matches.length === 1 ? '' : 'es') + '</p><ul class="ui-list">' + matches.map(popRow).join('') + '</ul>';
            }
        }
        pop.innerHTML = html;
        pop.hidden = false;
        search.setAttribute('aria-expanded', 'true');
    }

    HOD.actions.on('att.pop-clock', function (btn) {
        var uid = btn.getAttribute('data-uid');
        var p = findPerson(uid);
        var row = btn.closest('[data-pop-row]');
        if (row) {
            row.classList.add('opacity-60');
            row.querySelector('[data-pop-actions]').innerHTML = '<span class="flex items-center gap-2 text-xs text-ink-muted"><span class="ui-spinner ui-spinner--sm" aria-hidden="true"></span>Clocking in…</span>';
        }
        clockIn(uid, p ? name(p) : 'Attendee');
    });

    var kpiTimer = null;
    // A tap on a button in the list never takes focus on iOS, so the field's
    // blur arrives with no relatedTarget: hold the list open while a pointer is
    // down inside it, until its click has run (with a timeout as a backstop).
    var touching = false, touchTimer = null;

    search.addEventListener('input', function () {
        filterRoster();
        if (att.popOpen || search.value.trim() !== '') { if (att.popOpen) renderPop(); else openPop(); }
    });
    search.addEventListener('focus', function () {
        clearTimeout(kpiTimer);
        kpiMode(true);
        if (search.value.trim() !== '') openPop();
    });
    search.addEventListener('blur', function (e) {
        clearTimeout(kpiTimer);
        kpiTimer = setTimeout(function () { kpiMode(false); }, 220);
        var next = e.relatedTarget;
        if (next && (pop.contains(next) || next.closest('#att-add'))) return;
        if (touching) return;
        closePop();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && att.popOpen) closePop(); });
    document.addEventListener('pointerdown', function (e) {
        clearTimeout(touchTimer);
        if (!att.popOpen) { touching = false; return; }
        var t = e.target;
        if (pop.contains(t) || t === search || (t.closest && t.closest('#att-add'))) { touching = pop.contains(t); return; }
        touching = false;
        closePop();
    });
    document.addEventListener('pointerup', function () { clearTimeout(touchTimer); touchTimer = setTimeout(function () { touching = false; }, 600); });
    document.addEventListener('pointercancel', function () { clearTimeout(touchTimer); touchTimer = setTimeout(function () { touching = false; }, 600); });
    document.addEventListener('click', function () { clearTimeout(touchTimer); setTimeout(function () { touching = false; }, 0); });
    window.addEventListener('resize', HOD.debounce(function () { kpiMode(document.activeElement === search); }, 150));

    /* ---------- Verify details ---------- */

    function fieldHtml(id, label, control, o) {
        o = o || {};
        return '<div class="ui-field' + (o.cls ? ' ' + o.cls : '') + '"' + (o.wrapId ? ' id="' + o.wrapId + '"' : '') + (o.hidden ? ' hidden' : '') + '>' +
            '<label class="ui-label" for="' + id + '">' + esc(label) + (o.required ? '<span class="ui-label__req" aria-hidden="true">*</span>' : '') + '</label>' +
            control + (o.hint ? '<p class="ui-hint" id="' + id + '-hint">' + esc(o.hint) + '</p>' : '') +
            '<p class="ui-field-error" id="' + id + '-error" aria-live="polite"></p></div>';
    }
    function inputHtml(id, nm, type, value, attrs) {
        return '<input class="ui-input" type="' + type + '" id="' + id + '" name="' + nm + '" value="' + esc(value || '') + '" aria-describedby="' + id + '-error" ' + (attrs || '') + '>';
    }
    function selectHtml(id, nm, options, value, blank) {
        return '<select class="ui-select" id="' + id + '" name="' + nm + '" aria-describedby="' + id + '-error">' +
            (blank ? '<option value="">' + esc(blank) + '</option>' : '') +
            options.map(function (o) { return '<option value="' + esc(o) + '"' + (o === value ? ' selected' : '') + '>' + esc(EV.statusLabel(o)) + '</option>'; }).join('') + '</select>';
    }
    function setErr(id, message) {
        var el = document.getElementById(id);
        if (!el) return;
        var slot = document.getElementById(id + '-error');
        if (message) el.setAttribute('aria-invalid', 'true'); else el.removeAttribute('aria-invalid');
        if (slot) slot.textContent = message || '';
    }

    function verifyLoading() {
        $('#att-verify-title').textContent = 'Verify details';
        $('#att-verify-body').innerHTML = '<div class="flex flex-col items-center gap-3 py-10 text-ink-muted"><span class="ui-spinner ui-spinner--lg" aria-hidden="true"></span><p>Loading details…</p></div>';
        $('#att-verify-actions').innerHTML = '';
    }

    function fetchPerson(uid) {
        return EV.api('fetch_person_details', { event_id: att.eventId, user_id: uid }).then(function (res) {
            if (!res.data) throw new Error('No details');
            att.verify = res.data;
            renderVerify();
            return true;
        }).catch(function () { return false; });
    }

    function renderVerify() {
        var p = att.verify;
        if (!p) return;
        $('#att-verify-title').textContent = name(p);
        var ctx = [['Region', p.region_name], ['Tribe', p.tribe_name], ['Departments', p.departments]].filter(function (c) { return c[1]; });
        var html = '<div class="flex flex-wrap gap-2"><span class="ui-badge ui-badge--info">' + esc(EV.statusLabel(p.spiritual_status)) + '</span>' +
            (p.is_checked_in ? '<span class="ui-badge ui-badge--success">Clocked in</span>' : '') + '</div>';
        if (ctx.length) {
            html += '<div class="rounded-ui border border-line p-3"><div class="mb-2 flex items-center gap-1"><p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Church groups</p>' +
                EV.info('Read only here. Change these in Congregation.', 'About church groups') + '</div><div class="ui-chips flex-wrap">' +
                ctx.map(function (c) { return '<span class="ui-chip"><span class="text-ink-subtle">' + esc(c[0]) + ':</span>&nbsp;' + esc(c[1]) + '</span>'; }).join('') + '</div></div>';
        }
        html += '<form class="ui-form-grid" id="att-verify-form" novalidate>' +
            fieldHtml('att-vf-first', 'First name', inputHtml('att-vf-first', 'first_name', 'text', p.first_name, 'maxlength="50" autocomplete="off" required'), { required: true }) +
            fieldHtml('att-vf-last', 'Last name', inputHtml('att-vf-last', 'last_name', 'text', p.last_name, 'maxlength="50" autocomplete="off" required'), { required: true }) +
            fieldHtml('att-vf-phone', 'Phone', inputHtml('att-vf-phone', 'phone', 'tel', p.phone, 'maxlength="25" inputmode="tel"')) +
            fieldHtml('att-vf-gender', 'Gender', selectHtml('att-vf-gender', 'gender', ['Male', 'Female'], p.gender, 'Not specified')) +
            fieldHtml('att-vf-spiritual', 'Spiritual status', selectHtml('att-vf-spiritual', 'spiritual_status', SPIRITUAL, p.spiritual_status)) +
            fieldHtml('att-vf-attendance', 'Attendance status', selectHtml('att-vf-attendance', 'attendance_status', ATTENDANCE, p.attendance_status)) +
            '</form>';
        if (p.is_checked_in && p.check_in_time) {
            var by = p.checked_in_by_first ? ' by ' + name({ first_name: p.checked_in_by_first, last_name: p.checked_in_by_last }) : '';
            html += '<p class="ui-meta text-center">Clocked in ' + esc(dateTime(p.check_in_time) + by) + '</p>';
        }
        $('#att-verify-body').innerHTML = html;
        $('#att-verify-actions').innerHTML =
            '<button type="button" class="ui-btn ui-btn--secondary" data-action="att.verify-save">Save details</button>' +
            '<span class="ml-auto flex flex-wrap gap-2">' + (p.is_checked_in
                ? '<button type="button" class="ui-btn ui-btn--ghost" data-dialog-close>Keep</button><button type="button" class="ui-btn ui-btn--danger-ghost" data-action="att.verify-out">Clock out</button>'
                : '<button type="button" class="ui-btn ui-btn--brand" data-action="att.verify-in">Clock in</button>') + '</span>';
    }

    HOD.actions.on('att.verify', function (btn) {
        if (!att.eventId) { HOD.toast('Choose an event first.', 'warning'); return; }
        closePop();
        att.verify = null;
        verifyLoading();
        HOD.dialog.open('att-verify', btn);
        fetchPerson(btn.getAttribute('data-uid')).then(function (ok) { if (!ok) HOD.dialog.close('att-verify'); });
    });

    HOD.actions.on('att.verify-save', function (btn) {
        var p = att.verify;
        if (!p) return;
        var v = function (id) { return ($('#' + id).value || '').trim(); };
        var first = v('att-vf-first'), last = v('att-vf-last');
        setErr('att-vf-first', first ? '' : 'Enter the first name.');
        setErr('att-vf-last', last ? '' : 'Enter the last name.');
        if (!first || !last) { $(!first ? '#att-vf-first' : '#att-vf-last').focus(); return; }
        var data = {
            user_id: p.id, first_name: first, last_name: last, phone: v('att-vf-phone'),
            gender: $('#att-vf-gender').value || '', spiritual_status: $('#att-vf-spiritual').value || '', attendance_status: $('#att-vf-attendance').value || ''
        };
        HOD.busy(btn, true);
        EV.api('update_person_details', data).then(function () {
            HOD.toast('Details saved.', 'success');
            Object.assign(p, { first_name: first, last_name: last, phone: data.phone, gender: data.gender, spiritual_status: data.spiritual_status, attendance_status: data.attendance_status });
            renderVerify();
            refreshAll();
        }).catch(function (err) {
            if (err && err.field) setErr('att-vf-' + ({ first_name: 'first', last_name: 'last', phone: 'phone' }[err.field] || err.field), err.message);
            HOD.busy(btn, false);
        });
    });

    HOD.actions.on('att.verify-in', function (btn) {
        var p = att.verify;
        if (!p) return;
        HOD.busy(btn, true);
        clockIn(p.id, name(p)).then(function () { fetchPerson(p.id); });
    });

    HOD.actions.on('att.verify-out', function () {
        var p = att.verify;
        if (!p) return;
        askClockOut(name(p)).then(function (ok) {
            if (!ok) return;
            clockOut(p.id, name(p)).then(function () { fetchPerson(p.id); });
        });
    });

    /* ---------- Add to congregation (wizard) ---------- */

    var STEP_INDEX = { type: 0, mode: 0, qr: 0, basic: 1, profile: 2, review: 3 };
    var STEP_FIELDS = { basic: ['att-wz-first', 'att-wz-last', 'att-wz-phone'], profile: ['att-wz-email', 'att-wz-address'] };
    var FIELD_HOME = {
        first_name: ['basic', 'att-wz-first'], last_name: ['basic', 'att-wz-last'], phone: ['basic', 'att-wz-phone'], gender: ['basic', 'att-wz-gender'],
        email: ['profile', 'att-wz-email'], dob: ['profile', 'att-wz-dob'], physical_address: ['profile', 'att-wz-address']
    };
    var wizBody = $('#att-wiz-body');

    function wizDefaults() {
        return {
            step: 'type', type: null, mode: null, duplicate: null, busy: false,
            data: {
                first_name: '', last_name: '', phone: '', gender: '', email: '', dob: '', marital_status: 'Single', wedding_anniversary: '',
                physical_address: '', invited_by: '', prayer_requests: '', wants_to_join: false, wants_visitation: false,
                spiritual_status: 'Member', attendance_status: 'New', comments: ''
            }
        };
    }

    function wizHead(title, desc) {
        $('#att-wizard-title').textContent = title;
        $('#att-wizard-desc').textContent = desc;
    }

    function wizSteps() {
        var w = att.wiz;
        var at = w.step === 'success' ? 4 : STEP_INDEX[w.step];
        $$('[data-att-step]').forEach(function (li) {
            var i = Number(li.getAttribute('data-att-step'));
            li.classList.toggle('is-done', i < at);
            if (i === at) li.setAttribute('aria-current', 'step'); else li.removeAttribute('aria-current');
        });
    }

    function wizError(message, existingId) {
        var box = $('#att-wiz-error');
        if (!message) { box.hidden = true; box.innerHTML = ''; return; }
        box.innerHTML = '<div class="ui-banner ui-banner--danger">' + HOD.icon('circle-alert') + '<div class="ui-banner__body">' +
            '<p class="ui-banner__text">' + esc(message) + '</p>' +
            (existingId ? '<div class="ui-banner__actions"><button type="button" class="ui-btn ui-btn--brand ui-btn--sm" data-action="att.wiz-existing" data-uid="' + Number(existingId) + '">Clock them in</button></div>' : '') +
            '</div><button type="button" class="ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon" data-action="att.wiz-dismiss" aria-label="Dismiss">' + HOD.icon('x') + '</button></div>';
        box.hidden = false;
        wizBody.scrollTop = 0;
    }

    function choice(action, value, icon, title, text) {
        return '<button type="button" class="ui-card ui-card--interactive flex w-full items-center gap-3 p-4 text-left" data-action="' + action + '" data-value="' + value + '">' +
            '<span class="grid h-11 w-11 shrink-0 place-items-center rounded-ui bg-primary-soft text-primary">' + HOD.icon(icon) + '</span>' +
            '<span class="min-w-0 flex-1"><span class="block font-semibold text-ink">' + esc(title) + '</span><span class="block text-sm text-ink-muted">' + esc(text) + '</span></span>' +
            HOD.icon('chevron-right', 'shrink-0 text-ink-subtle') + '</button>';
    }

    function btn(label, action, variant, id) {
        return '<button type="button" class="ui-btn ui-btn--' + variant + '"' + (action ? ' data-action="' + action + '"' : ' data-dialog-close') + (id ? ' id="' + id + '"' : '') + '><span>' + esc(label) + '</span></button>';
    }

    function today() { return new Date().toISOString().slice(0, 10); }

    function collect() {
        var w = att.wiz, d = w.data;
        var v = function (id) { var el = document.getElementById(id); return el ? (el.value || '').trim() : ''; };
        if (w.step === 'basic') {
            d.first_name = v('att-wz-first'); d.last_name = v('att-wz-last'); d.phone = v('att-wz-phone'); d.gender = v('att-wz-gender');
        } else if (w.step === 'profile') {
            d.email = v('att-wz-email'); d.dob = v('att-wz-dob'); d.marital_status = v('att-wz-marital') || 'Single';
            d.wedding_anniversary = v('att-wz-anniversary'); d.physical_address = v('att-wz-address');
            if (w.type === 'first_timer') {
                d.invited_by = v('att-wz-invited'); d.prayer_requests = v('att-wz-prayer');
                d.wants_to_join = !!($('#att-wz-join') && $('#att-wz-join').checked);
                d.wants_visitation = !!($('#att-wz-visit') && $('#att-wz-visit').checked);
            } else {
                d.spiritual_status = v('att-wz-spiritual') || 'Member'; d.attendance_status = v('att-wz-attendance') || 'New'; d.comments = v('att-wz-comments');
            }
        }
    }

    function problem(id) {
        var el = document.getElementById(id);
        var raw = el ? (el.value || '').trim() : '';
        switch (id) {
            case 'att-wz-first': return raw ? '' : 'Enter the first name.';
            case 'att-wz-last': return raw ? '' : 'Enter the last name.';
            case 'att-wz-phone':
                if (!raw) return 'Enter a phone number. It stops duplicate profiles.';
                var digits = (raw.match(/\d/g) || []).length;
                return digits < 9 || digits > 15 ? 'Enter a phone number of 9 to 15 digits.' : '';
            case 'att-wz-address': return raw ? '' : 'Enter where they live. Area and street is enough.';
            case 'att-wz-email': return raw && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(raw) ? 'Check the email, or leave it blank.' : '';
        }
        return '';
    }

    function validateStep(step) {
        var first = null;
        (STEP_FIELDS[step] || []).forEach(function (id) {
            if (!document.getElementById(id)) return;
            var msg = problem(id);
            setErr(id, msg);
            if (msg && !first) first = id;
        });
        return first;
    }

    function focusField(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.focus({ preventScroll: true });
        el.scrollIntoView({ block: 'center' });
    }

    function renderWiz() {
        var w = att.wiz;
        if (!w) return;
        wizSteps();
        var d = w.data;
        var back = btn('Back', 'att.wiz-back', 'secondary');
        var cancel = '<span class="ml-auto"></span>' + btn('Cancel', null, 'ghost');
        var next = btn('Next', 'att.wiz-next', 'primary', 'att-wz-next');
        var footer = '';
        var html = '';
        var max = ' max="' + today() + '"';

        if (w.step === 'type') {
            wizHead('Add to congregation', 'Create their profile and clock them in.');
            html = '<div class="space-y-3">' +
                choice('att.wiz-type', 'first_timer', 'sprout', 'First timer', 'A guest. Starts as 1st Timer and joins the Embrace follow-up.') +
                choice('att.wiz-type', 'member', 'users', 'Member', 'Someone already part of the house, with their statuses.') + '</div>';
            footer = cancel;
        } else if (w.step === 'mode') {
            wizHead('First timer', 'How will you take their details?');
            html = '<div class="space-y-3">' +
                choice('att.wiz-mode', 'qr', 'qr-code', 'They fill it in', 'Show the QR for the "I\'m new here" form on their phone.') +
                choice('att.wiz-mode', 'staff', 'pencil', 'You fill it in', 'The same details as the public form, with them now.') + '</div>';
            footer = back + cancel;
        } else if (w.step === 'qr') {
            wizHead('Scan to connect', 'They fill in the form on their own phone.');
            var url = window.location.origin + '/connect';
            html = '<div class="space-y-4 text-center">' +
                '<div class="mx-auto w-48 rounded-ui border border-line bg-white p-3" id="att-wz-qr"><span class="ui-skeleton block h-40 w-full"></span></div>' +
                '<div class="flex items-center justify-center gap-1"><p class="font-semibold text-ink">Scan for the "I\'m new here" form</p>' +
                    EV.info('Sending the form creates their 1st Timer profile. Then search their name here to clock them in: the form is not tied to this event.', 'About the QR form') + '</div>' +
                '<label class="sr-only" for="att-wz-url">Form link</label><input class="ui-input mx-auto max-w-sm text-center ui-num" type="text" id="att-wz-url" value="' + esc(url) + '" readonly>' +
                '<div class="flex flex-wrap justify-center gap-2">' +
                    '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="att.wiz-copy">' + HOD.icon('copy') + '<span>Copy link</span></button>' +
                    '<a class="ui-btn ui-btn--secondary ui-btn--sm" href="/connect" target="_blank" rel="noopener">' + HOD.icon('external-link') + '<span>Open form</span></a>' +
                    '<button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" data-action="att.wiz-mode" data-value="staff">' + HOD.icon('pencil') + '<span>Fill it in</span></button>' +
                '</div></div>';
            footer = back + cancel;
        } else if (w.step === 'basic') {
            wizHead('Basic details', w.type === 'member' ? 'Who are we adding?' : 'Who is joining us today?');
            html = '<div class="ui-form-grid">' +
                fieldHtml('att-wz-first', 'First name', inputHtml('att-wz-first', 'first_name', 'text', d.first_name, 'maxlength="50" autocomplete="off" enterkeyhint="next"'), { required: true }) +
                fieldHtml('att-wz-last', 'Last name', inputHtml('att-wz-last', 'last_name', 'text', d.last_name, 'maxlength="50" autocomplete="off" enterkeyhint="next"'), { required: true }) +
                fieldHtml('att-wz-phone', 'Phone', inputHtml('att-wz-phone', 'phone', 'tel', d.phone, 'maxlength="25" inputmode="tel" autocomplete="off" enterkeyhint="next"'), { required: true, hint: 'Stops duplicate profiles.' }) +
                fieldHtml('att-wz-gender', 'Gender', selectHtml('att-wz-gender', 'gender', ['Male', 'Female'], d.gender, 'Not specified')) +
                '</div><div class="mt-4" id="att-wz-dup" hidden></div>';
            footer = back + cancel + next;
        } else if (w.step === 'profile') {
            if (w.type === 'first_timer') {
                wizHead('A little more', 'Where they live is needed; the rest is optional.');
                html = '<div class="ui-form">' + '<div class="ui-form-grid">' +
                    fieldHtml('att-wz-email', 'Personal email', inputHtml('att-wz-email', 'email', 'email', d.email, 'maxlength="100" inputmode="email" autocomplete="off" enterkeyhint="next"')) +
                    fieldHtml('att-wz-dob', 'Date of birth', inputHtml('att-wz-dob', 'dob', 'date', d.dob, max)) +
                    fieldHtml('att-wz-marital', 'Marital status', selectHtml('att-wz-marital', 'marital_status', MARITAL, d.marital_status)) +
                    fieldHtml('att-wz-address', 'Where they live', inputHtml('att-wz-address', 'physical_address', 'text', d.physical_address, 'maxlength="255" autocomplete="off" enterkeyhint="next"'), { required: true }) +
                    fieldHtml('att-wz-invited', 'Who invited them?', inputHtml('att-wz-invited', 'invited_by', 'text', d.invited_by, 'maxlength="150" autocomplete="off" enterkeyhint="next"')) +
                    '</div>' +
                    '<label class="ui-check"><input type="checkbox" id="att-wz-join"' + (d.wants_to_join ? ' checked' : '') + '><span>Wants HOD as their home church</span></label>' +
                    '<label class="ui-check"><input type="checkbox" id="att-wz-visit"' + (d.wants_visitation ? ' checked' : '') + '><span>Would like a call or visit</span></label>' +
                    fieldHtml('att-wz-prayer', 'Prayer request', '<textarea class="ui-textarea" id="att-wz-prayer" rows="3" maxlength="2000" aria-describedby="att-wz-prayer-error">' + esc(d.prayer_requests) + '</textarea>') +
                    '</div>';
            } else {
                wizHead('Church details', 'Their statuses, as in Congregation.');
                html = '<div class="ui-form">' + '<div class="ui-form-grid">' +
                    fieldHtml('att-wz-spiritual', 'Spiritual status', selectHtml('att-wz-spiritual', 'spiritual_status', SPIRITUAL, d.spiritual_status), { required: true }) +
                    fieldHtml('att-wz-attendance', 'Attendance status', selectHtml('att-wz-attendance', 'attendance_status', ATTENDANCE, d.attendance_status), { required: true }) +
                    fieldHtml('att-wz-email', 'Personal email', inputHtml('att-wz-email', 'email', 'email', d.email, 'maxlength="100" inputmode="email" autocomplete="off" enterkeyhint="next"'), { hint: 'Blank gives a church email.' }) +
                    fieldHtml('att-wz-dob', 'Date of birth', inputHtml('att-wz-dob', 'dob', 'date', d.dob, max)) +
                    fieldHtml('att-wz-marital', 'Marital status', selectHtml('att-wz-marital', 'marital_status', MARITAL, d.marital_status)) +
                    fieldHtml('att-wz-anniversary', 'Wedding anniversary', inputHtml('att-wz-anniversary', 'wedding_anniversary', 'date', d.wedding_anniversary), { wrapId: 'att-wz-anniversary-wrap', hidden: d.marital_status !== 'Married' }) +
                    fieldHtml('att-wz-address', 'Address', inputHtml('att-wz-address', 'physical_address', 'text', d.physical_address, 'maxlength="255" autocomplete="off" enterkeyhint="next"'), { required: true, cls: 'ui-span-full' }) +
                    '</div>' +
                    fieldHtml('att-wz-comments', 'Pastoral notes', '<textarea class="ui-textarea" id="att-wz-comments" rows="2" maxlength="1000" aria-describedby="att-wz-comments-error">' + esc(d.comments) + '</textarea>') +
                    '</div>';
            }
            footer = back + cancel + next;
        } else if (w.step === 'review') {
            wizHead('Review and add', 'Check, then add and clock them in.');
            var row = function (label, value) {
                return '<div class="flex justify-between gap-4 py-2"><dt class="text-sm text-ink-subtle">' + esc(label) + '</dt><dd class="min-w-0 break-words text-right text-sm font-medium text-ink">' +
                    (value ? esc(value) : '<span class="text-ink-subtle">Not given</span>') + '</dd></div>';
            };
            var rows = row('Name', (d.first_name + ' ' + d.last_name).trim()) + row('Phone', d.phone) + row('Gender', d.gender) + row('Email', d.email) +
                row('Date of birth', d.dob) + row('Marital status', d.marital_status) + row('Address', d.physical_address);
            if (w.type === 'first_timer') {
                rows += row('Invited by', d.invited_by) + row('Wants to join', d.wants_to_join ? 'Yes' : '') + row('Wants a visit', d.wants_visitation ? 'Yes' : '') + row('Prayer request', d.prayer_requests ? 'Given' : '');
            } else {
                rows += row('Spiritual status', EV.statusLabel(d.spiritual_status)) + row('Attendance status', EV.statusLabel(d.attendance_status)) + row('Notes', d.comments);
            }
            html = '<dl class="divide-y divide-line rounded-ui border border-line px-4">' + rows + '</dl>' +
                '<div class="ui-banner ui-banner--info mt-4">' + HOD.icon('clock') + '<div class="ui-banner__body"><p class="ui-banner__text">They are clocked into ' + esc(eventLabel()) + ' as soon as the profile is made.</p></div></div>';
            footer = back + cancel + btn('Add and clock in', 'att.wiz-submit', 'brand', 'att-wz-submit');
        }
        wizBody.innerHTML = html;
        $('#att-wiz-footer').innerHTML = footer;
        $('#att-wiz-footer').closest('.ui-dialog__footer').hidden = !footer;
        if (w.step === 'qr') drawQr();
        if (w.step === 'basic' && w.duplicate) showDuplicate();
        var firstInput = wizBody.querySelector('input:not([type="checkbox"]):not([readonly]), select');
        if (firstInput && (w.step === 'basic' || w.step === 'profile')) firstInput.focus({ preventScroll: true });
    }

    function drawQr() {
        var host = $('#att-wz-qr');
        import(HOD.config.vendor.qrcode).then(function (mod) {
            var qr = mod.qrcode(0, 'H');
            qr.addData(window.location.origin + '/connect');
            qr.make();
            host.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
            var svg = host.querySelector('svg');
            if (svg) { svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'QR code for the "I\'m new here" form'); }
        }).catch(function () { host.innerHTML = '<p class="ui-meta break-all">' + esc(window.location.origin + '/connect') + '</p>'; });
    }

    function go(step) {
        att.wiz.step = step;
        wizError('');
        renderWiz();
        wizBody.scrollTop = 0;
    }

    function openWizard(prefill, opener) {
        if (!att.eventId) { HOD.toast('Choose an event first. They are clocked into it.', 'warning'); return; }
        att.wiz = wizDefaults();
        if (prefill) {
            var parts = String(prefill).trim().split(/\s+/).filter(Boolean);
            att.wiz.data.first_name = parts[0] || '';
            att.wiz.data.last_name = parts.slice(1).join(' ');
        }
        wizError('');
        renderWiz();
        if (!$('#att-wizard').open) HOD.dialog.open('att-wizard', opener);
    }

    HOD.actions.on('att.add', function (b) {
        var q = search.value || '';
        closePop();
        openWizard(q, b);
    });
    HOD.actions.on('att.wiz-type', function (b) { att.wiz.type = b.getAttribute('data-value'); go(att.wiz.type === 'first_timer' ? 'mode' : 'basic'); });
    HOD.actions.on('att.wiz-mode', function (b) { att.wiz.mode = b.getAttribute('data-value'); go(att.wiz.mode === 'qr' ? 'qr' : 'basic'); });
    HOD.actions.on('att.wiz-dismiss', function () { wizError(''); });
    HOD.actions.on('att.wiz-copy', function () { EV.copyText(window.location.origin + '/connect', $('#att-wz-url')); });
    HOD.actions.on('att.wiz-back', function () {
        var w = att.wiz;
        collect();
        if (w.step === 'review') go('profile');
        else if (w.step === 'profile') go('basic');
        else if (w.step === 'basic') go(w.type === 'first_timer' ? 'mode' : 'type');
        else go('type');
    });

    function showDuplicate() {
        var p = att.wiz.duplicate;
        var box = $('#att-wz-dup');
        if (!box || !p) return;
        box.innerHTML = '<div class="ui-banner ui-banner--warning">' + HOD.icon('triangle-alert') + '<div class="ui-banner__body">' +
            '<p class="ui-banner__title">This phone is already on file</p>' +
            '<p class="ui-banner__text">' + esc(name(p) + ' · ' + EV.statusLabel(p.spiritual_status)) + '. Change the number, or clock them in.</p>' +
            '<div class="ui-banner__actions"><button type="button" class="ui-btn ui-btn--brand ui-btn--sm" data-action="att.wiz-dup">' + HOD.icon('clock') + '<span>Clock them in</span></button></div></div></div>';
        box.hidden = false;
        box.scrollIntoView({ block: 'nearest' });
    }

    HOD.actions.on('att.wiz-next', function (b) {
        var w = att.wiz;
        if (!w || w.busy) return;
        collect();
        var bad = validateStep(w.step);
        if (bad) { focusField(bad); return; }
        if (w.step === 'basic') {
            w.busy = true;
            HOD.busy(b, true);
            EV.api('check_attendee_phone', { phone: w.data.phone }, { quiet: true }).then(function (res) {
                if (res.found && res.person) { w.duplicate = res.person; showDuplicate(); return; }
                w.duplicate = null;
                go('profile');
            }).catch(function () {
                go('profile'); // never block the flow on a failed check
            }).finally(function () { w.busy = false; HOD.busy(b, false); });
            return;
        }
        if (w.step === 'profile') go('review');
    });

    function success(nm, res, existing) {
        var w = att.wiz;
        if (!w) return;
        w.step = 'success';
        wizSteps();
        var line1, line2;
        if (res && res.status === 'success') {
            line1 = existing ? 'Their profile is clocked in.' : 'Profile made and clocked in.';
            line2 = res.clock_time ? 'At ' + res.clock_time + ', in the Checked in list.' : 'In the Checked in list.';
        } else if (res && res.status === 'warning') {
            line1 = existing ? 'Profile kept.' : 'Profile made.';
            line2 = res.message || 'They were already checked in.';
        } else {
            line1 = existing ? 'Profile found.' : 'Profile made.';
            line2 = 'The clock-in was not confirmed. Check the Checked in list before trying again.';
        }
        wizHead('They\'re in', existing ? 'Existing profile clocked in.' : 'Profile made and clocked in.');
        wizBody.innerHTML = '<div class="space-y-3 py-4 text-center">' +
            '<span class="mx-auto grid h-16 w-16 place-items-center rounded-full bg-success-soft text-success">' + HOD.icon('circle-check', 'ui-icon--xl') + '</span>' +
            '<p class="ui-h2">' + esc(nm) + '</p><p class="text-ink-muted">' + esc(line1) + '</p><p class="ui-meta">' + esc(line2) + '</p>' +
            '<div class="flex flex-col justify-center gap-2 pt-2 sm:flex-row">' +
                '<button type="button" class="ui-btn ui-btn--secondary" data-action="att.wiz-again">Add another</button>' +
                '<button type="button" class="ui-btn ui-btn--primary" data-dialog-close>Done</button></div></div>';
        $('#att-wiz-footer').innerHTML = '';
        $('#att-wiz-footer').closest('.ui-dialog__footer').hidden = true;
        refreshAll();
    }

    HOD.actions.on('att.wiz-again', function () { openWizard(''); });

    HOD.actions.on('att.wiz-dup', function (b) {
        var p = att.wiz && att.wiz.duplicate;
        if (!p) return;
        HOD.busy(b, true);
        clockIn(p.id, name(p)).then(function (res) { success(name(p), res, true); });
    });

    HOD.actions.on('att.wiz-existing', function (b) {
        HOD.busy(b, true);
        clockIn(b.getAttribute('data-uid'), 'Existing profile').then(function () {
            HOD.dialog.close('att-wizard');
        });
    });

    HOD.actions.on('att.wiz-submit', function (b) {
        var w = att.wiz;
        if (!w || w.busy) return;
        w.busy = true;
        HOD.busy(b, true);
        var d = w.data;
        var shared = {
            first_name: d.first_name, last_name: d.last_name, phone: d.phone, gender: d.gender,
            marital_status: d.marital_status, dob: d.dob, physical_address: d.physical_address
        };
        var call = w.type === 'first_timer'
            ? EV.api('create_first_timer', Object.assign({
                email: d.email, invited_by: d.invited_by, prayer_requests: d.prayer_requests,
                wants_to_join: d.wants_to_join ? '1' : '0', wants_visitation: d.wants_visitation ? '1' : '0'
            }, shared), { quiet: true })
            // The Congregation module's own create (church email, QR hash, IDI notice).
            : HOD.api(CONG_API, { action: 'create_member', quiet: true, data: Object.assign({
                email: '', real_email: d.email, wedding_anniversary: d.marital_status === 'Married' ? d.wedding_anniversary : '',
                spiritual_status: d.spiritual_status, attendance_status: d.attendance_status, comments: d.comments
            // users.gender takes Male, Female or NULL: "Not specified" is left out (the API reads it as null).
            }, shared, { gender: d.gender || undefined }) });
        call.then(function (res) {
            var nm = (d.first_name + ' ' + d.last_name).trim() || res.name || 'New profile';
            return clockIn(res.user_id, nm).then(function (clockRes) { w.busy = false; success(nm, clockRes, false); });
        }, function (err) {
            w.busy = false;
            HOD.busy(b, false);
            var r = err.response || {};
            var home = !r.existing_user_id && r.field ? FIELD_HOME[r.field] : null;
            if (home) {
                go(home[0]);
                setErr(home[1], r.message || 'Check this field.');
                focusField(home[1]);
                HOD.toast(r.message || 'Check the marked field.', 'danger');
                return;
            }
            wizError(r.message || (err.code === 'NETWORK' ? err.message : 'The profile was not made. Try again.'), r.existing_user_id);
        });
    });

    // A marked field clears as soon as what is typed is acceptable; the
    // marriage date shows only for Married.
    wizBody.addEventListener('input', function (e) {
        var t = e.target;
        if (t.getAttribute && t.getAttribute('aria-invalid') === 'true' && !problem(t.id)) setErr(t.id, '');
        if (t.id === 'att-wz-phone' && att.wiz && att.wiz.duplicate) { att.wiz.duplicate = null; var dup = $('#att-wz-dup'); if (dup) dup.hidden = true; }
    });
    wizBody.addEventListener('change', function (e) {
        if (e.target.id === 'att-wz-marital') { var wrap = $('#att-wz-anniversary-wrap'); if (wrap) wrap.hidden = e.target.value !== 'Married'; }
    });
    // Enter moves to the next field (the phone keyboard shows "Next"); on the
    // last one it presses Next. It never submits half a form.
    wizBody.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || e.target.tagName !== 'INPUT' || e.target.type === 'checkbox' || e.target.readOnly) return;
        e.preventDefault();
        var fields = $$('input:not([type="checkbox"]):not([readonly]), select, textarea', wizBody).filter(function (el) { return el.offsetParent !== null; });
        var at = fields.indexOf(e.target);
        if (at > -1 && at < fields.length - 1) fields[at + 1].focus();
        else { var n = $('#att-wz-next'); if (n) n.click(); }
    });
    $('#att-wizard').addEventListener('ui:dialog-close', function () { att.wiz = null; });
})();
