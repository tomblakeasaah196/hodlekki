// /assets/ui/js/pages/tribes.js
// Tribes (modules/tribes/index.php): all tribes (church-wide), my tribe and
// its members, meetings with their reports, and the duty roster. Data from
// /api/tribes_api.php through HOD.api. Names, places, titles and duties are
// typed by people, so they go into the page as TEXT (DOM nodes); markup
// strings hold only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('tribes-boot');
    if (!boot) return;
    var API = boot.api;

    var tribe = null;
    var events = [];

    /* ------------------------------------------------------------- helpers */

    function q(sel, root) { return (root || document).querySelector(sel); }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }

    function icon(name) {
        var w = document.createElement('span');
        w.innerHTML = HOD.icon(name);   // our own sprite name
        return w.firstChild;
    }

    function badge(label, tone) {
        return el('span', 'ui-badge' + (tone ? ' ui-badge--' + tone : ''), label);
    }

    function button(label, opts) {
        opts = opts || {};
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + ' ui-btn--sm');
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
    }

    function empty(title, text, iconName) {
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(icon(iconName || 'inbox'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        return box;
    }

    function listItem(title, meta, trailing) {
        var row = el('div', 'ui-list__item');
        var main = el('div', 'ui-list__main');
        main.appendChild(el('span', 'ui-list__title', title));
        if (meta) main.appendChild(el('span', 'ui-list__meta', meta));
        row.appendChild(main);
        (trailing || []).forEach(function (t) { if (t) row.appendChild(t); });
        return row;
    }

    function when(raw) {
        var d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
        return d && !isNaN(d) ? HOD.format.date(d) + ' · ' + HOD.format.time(d) : '';
    }

    function isPast(raw) {
        var d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
        return d && !isNaN(d) && d.getTime() < Date.now();
    }

    function findEvent(id) {
        return events.filter(function (e) { return String(e.id) === String(id); })[0] || null;
    }

    /* -------------------------------------------------------------- render */

    function renderAll(rows) {
        var body = q('[data-trb-all]');
        if (!body) return;
        body.textContent = '';
        if (!rows.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 5;
            td.appendChild(empty('No tribes yet', 'Use New tribe to start one.', 'users-round'));
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        rows.forEach(function (t) {
            var tr = el('tr');
            var name = cell('Tribe');
            name.textContent = t.name;
            tr.appendChild(name);
            var cap = cell('Captain');
            cap.textContent = t.captain_name || 'No captain';
            tr.appendChild(cap);
            var day = cell('Meets');
            day.textContent = t.meeting_day || '—';
            tr.appendChild(day);
            var where = cell('Where');
            where.appendChild(el('span', 'break-words', t.location_address || '—'));
            tr.appendChild(where);
            var n = cell('Members', 'is-num');
            n.textContent = HOD.format.number(t.member_count);
            tr.appendChild(n);
            body.appendChild(tr);
        });
    }

    function renderMine() {
        if (!tribe) return;
        q('[data-trb-name]').textContent = tribe.name;
        q('[data-trb-day]').textContent = tribe.meeting_day || '—';
        q('[data-trb-where]').textContent = tribe.location_address || '—';
        var box = q('[data-trb-members]');
        box.textContent = '';
        var members = tribe.members || [];
        if (!members.length) {
            box.appendChild(empty('No members yet', '', 'users'));
        }
        members.forEach(function (m) {
            box.appendChild(listItem(((m.first_name || '') + ' ' + (m.last_name || '')).trim(), m.phone || '',
                [Number(m.id) === Number(tribe.captain_id) ? badge('Captain', 'info') : null]));
        });

        var select = q('#trb-roster-form') && q('#trb-roster-form').elements.assigned_user_id;
        if (select) {
            select.textContent = '';
            select.appendChild(new Option('Choose…', ''));
            members.forEach(function (m) {
                select.appendChild(new Option(((m.first_name || '') + ' ' + (m.last_name || '')).trim(), m.id));
            });
        }
    }

    function renderMeetings() {
        var box = q('[data-trb-meetings]');
        if (!box) return;
        box.textContent = '';
        if (!events.length) {
            box.appendChild(empty('No meetings planned', boot.canRun ? 'Use Schedule to plan the next one.' : '', 'calendar'));
        }
        events.forEach(function (e) {
            var kind = e.event_category === 'Evangelism' ? 'Outreach' : 'Meeting';
            var trailing = [];
            if (boot.canRun) {
                trailing.push(button(e.report_notes ? 'Edit report' : 'Report', { icon: 'pencil', data: { action: 'trb.report', id: e.id } }));
            }
            var row = listItem(e.title, when(e.event_date), trailing);
            // Badges under the title, so a phone keeps the title readable.
            var tags = el('span', 'flex flex-wrap gap-1 mt-1');
            tags.appendChild(badge(kind, ''));
            if (e.report_notes) tags.appendChild(badge('Reported', 'success'));
            else if (isPast(e.event_date)) tags.appendChild(badge('No report', 'warning'));
            q('.ui-list__main', row).appendChild(tags);
            box.appendChild(row);
        });

        var select = q('#trb-roster-event');
        if (select) {
            var keep = select.value;
            select.textContent = '';
            select.appendChild(new Option('Choose a meeting…', ''));
            events.forEach(function (e) { select.appendChild(new Option(e.title + ' · ' + HOD.format.date(String(e.event_date).replace(' ', 'T')), e.id)); });
            if (keep && findEvent(keep)) select.value = keep;
        }
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            q('[data-trb-error]').hidden = true;
            if (res.global_data) renderAll(res.global_data);
            tribe = res.my_tribe || null;
            events = (tribe && tribe.upcoming_events) || [];
            renderMine();
            renderMeetings();
        }).catch(function () {
            q('[data-trb-error]').hidden = false;
        });
    }

    /* --------------------------------------------------------------- roster */

    function loadRoster(eventId) {
        var area = q('[data-trb-roster]');
        var none = q('[data-trb-roster-empty]');
        if (!eventId) { area.hidden = true; none.hidden = false; return Promise.resolve(); }
        area.hidden = false;
        none.hidden = true;
        var box = q('[data-trb-duties]');
        return HOD.api(API, { method: 'GET', action: 'fetch_roster', data: { event_id: eventId }, quiet: true }).then(function (res) {
            box.textContent = '';
            var rows = res.data || [];
            if (!rows.length) box.appendChild(empty('No duties yet', boot.canRun ? 'Assign the first one.' : '', 'clipboard-list'));
            rows.forEach(function (r) { box.appendChild(listItem(r.task_name, r.assigned_name || '')); });
        }).catch(function () {
            box.textContent = '';
            box.appendChild(empty('Could not load the roster', 'Choose the meeting again.', 'clipboard-list'));
        });
    }

    HOD.actions.on('trb.roster.event', function (select) { loadRoster(select.value); });

    HOD.actions.on('trb.roster.save', function (form, event) {
        var eventId = q('#trb-roster-event').value;
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.task_name || '').trim()) errors.task_name = 'Name the duty.';
        if (!v.assigned_user_id) errors.assigned_user_id = 'Choose who does it.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'assign_roster', data: { event_id: eventId, task_name: v.task_name.trim(), assigned_user_id: v.assigned_user_id } }).then(function () {
            form.elements.task_name.value = '';
            HOD.toast('Duty assigned.', { tone: 'success' });
            return loadRoster(eventId);
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* -------------------------------------------------------------- dialogs */

    function fieldError(form) {
        return function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    function reset(id) {
        var form = q('#' + id);
        form.reset();
        HOD.form.clearErrors(form);
        return form;
    }

    HOD.actions.on('trb.tribe.new', function () {
        reset('trb-tribe-form');
        HOD.dialog.open('trb-tribe-dialog');
    });

    HOD.actions.on('trb.tribe.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!(v.name || '').trim()) { HOD.form.setErrors(form, { name: 'Give the tribe a name.' }); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'create_tribe', data: {
            name: v.name.trim(), location_address: (v.location_address || '').trim(), meeting_day: v.meeting_day
        } }).then(function () {
            HOD.dialog.close('trb-tribe-dialog');
            HOD.toast('Tribe created.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('trb.activity.new', function () {
        reset('trb-activity-form');
        HOD.dialog.open('trb-activity-dialog');
    });

    HOD.actions.on('trb.activity.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.title || '').trim()) errors.title = 'Give it a title.';
        if (!v.event_date) errors.event_date = 'Choose the date and time.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        if (!tribe) return;
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'create_activity', data: {
            tribe_id: tribe.id, title: v.title.trim(), category: v.category, event_date: v.event_date
        } }).then(function () {
            HOD.dialog.close('trb-activity-dialog');
            HOD.toast('Meeting scheduled.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('trb.report', function (btn) {
        var e = findEvent(btn.getAttribute('data-id'));
        if (!e) return;
        var form = reset('trb-report-form');
        form.elements.event_id.value = e.id;
        form.elements.report_notes.value = e.report_notes || '';
        q('[data-trb-report-for]', form).textContent = e.title + (when(e.event_date) ? ' · ' + when(e.event_date) : '');
        HOD.dialog.open('trb-report-dialog');
    });

    HOD.actions.on('trb.report.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!(v.report_notes || '').trim()) { HOD.form.setErrors(form, { report_notes: 'Write what happened.' }); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_report', data: { event_id: v.event_id, report_notes: v.report_notes.trim() } }).then(function () {
            HOD.dialog.close('trb-report-dialog');
            HOD.toast('Report saved.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    load();
})();
