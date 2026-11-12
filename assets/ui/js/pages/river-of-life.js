// /assets/ui/js/pages/river-of-life.js
// River of Life (modules/river_of_life/index.php): stats, the roster by
// role and section, rehearsals, and the roll-call drawer. Data from
// /api/rol_api.php through HOD.api. Names, sections and topics are typed by
// people, so they go into the page as TEXT (DOM nodes); markup strings hold
// only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('river-of-life-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { roster: {}, rehearsals: [], available: [] };
    var roll = { id: null, rows: [] };
    var MARKS = ['Present', 'Absent', 'Excused'];
    var STATUS = { Scheduled: ['Scheduled', 'info'], Completed: ['Completed', 'success'], Cancelled: ['Cancelled', ''] };

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
        b.appendChild(el('span', opts.iconOnly ? 'sr-only' : '', label));
        if (opts.iconOnly) b.setAttribute('aria-label', label);
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
        ic.appendChild(icon(iconName || 'users'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        return box;
    }

    function safeUrl(url) {
        url = String(url || '').trim();
        return /^(\/(?!\/)|\.\.?\/)/.test(url) ? url : '';
    }

    function fullName(o) { return ((o.first_name || '') + ' ' + (o.last_name || '')).trim() || 'Unnamed'; }

    function avatar(o) {
        var a = el('span', 'ui-avatar ui-avatar--sm ui-avatar--c' + ((fullName(o).length % 6) + 1));
        var url = safeUrl(o.picture_path);
        if (url) {
            var img = el('img');
            img.src = url;
            img.alt = '';
            img.loading = 'lazy';
            a.appendChild(img);
        } else {
            var s = el('span', '', ((o.first_name || '').charAt(0) + (o.last_name || '').charAt(0)).toUpperCase() || '?');
            s.setAttribute('aria-hidden', 'true');
            a.appendChild(s);
        }
        return a;
    }

    function when(raw) {
        var d = raw ? new Date(String(raw).replace(' ', 'T')) : null;
        return d && !isNaN(d) ? HOD.format.date(d, 'long') + ' · ' + HOD.format.time(d) : '—';
    }

    /* -------------------------------------------------------------- roster */

    function renderRoster() {
        var box = q('[data-rol-roster]');
        box.textContent = '';
        Object.keys(boot.categories).forEach(function (cat) {
            var card = el('section', 'ui-card');
            var head = el('div', 'ui-card__header ui-card__header--divided');
            head.appendChild(el('h3', 'ui-h3', boot.categories[cat]));
            card.appendChild(head);
            var sections = data.roster[cat] || {};
            var names = Object.keys(sections);
            if (!names.length) {
                var body = el('div', 'ui-card__body');
                body.appendChild(empty('Nobody here yet', boot.canCreate ? 'Use Assign member.' : ''));
                card.appendChild(body);
            }
            names.forEach(function (section) {
                var label = el('p', 'ui-label px-4 pt-3', section + ' · ' + sections[section].length);
                card.appendChild(label);
                var list = el('div', 'ui-list');
                sections[section].forEach(function (m) {
                    var row = el('div', 'ui-list__item');
                    row.appendChild(avatar(m));
                    var main = el('div', 'ui-list__main');
                    main.appendChild(el('span', 'ui-list__title', fullName(m)));
                    main.appendChild(el('span', 'ui-list__meta', [m.phone, m.gender].filter(Boolean).join(' · ')));
                    row.appendChild(main);
                    if (boot.canDelete) {
                        row.appendChild(button('Remove ' + fullName(m), { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'rol.remove', id: m.roster_id, name: fullName(m) } }));
                    }
                    list.appendChild(row);
                });
                card.appendChild(list);
            });
            box.appendChild(card);
        });
    }

    /* ---------------------------------------------------------- rehearsals */

    function renderRehearsals() {
        var body = q('[data-rol-rehearsals]');
        body.textContent = '';
        if (!data.rehearsals.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 5;
            td.appendChild(empty('No rehearsals yet', boot.canCreate ? 'Use Schedule to plan one.' : '', 'calendar'));
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        data.rehearsals.forEach(function (r) {
            var tr = el('tr');
            var w = cell('When');
            w.textContent = when(r.rehearsal_date);
            tr.appendChild(w);
            var f = cell('Focus');
            f.appendChild(el('span', 'break-words', r.focus_topic));
            tr.appendChild(f);
            var s = cell('Status');
            var st = STATUS[r.status] || STATUS.Scheduled;
            s.appendChild(badge(st[0], st[1]));
            tr.appendChild(s);
            var t = cell('Turnout');
            t.textContent = HOD.format.number(r.present_count) + ' of ' + HOD.format.number(r.total_expected);
            tr.appendChild(t);
            var act = cell('', 'is-actions');
            act.appendChild(button(boot.canEdit ? 'Roll-call' : 'Attendance', { icon: 'clipboard-list', data: { action: 'rol.roll', id: r.id } }));
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.roster = res.grouped_roster || {};
            data.rehearsals = res.rehearsals || [];
            data.available = res.available_members || [];
            q('[data-rol-error]').hidden = true;
            var st = res.stats || {};
            ['total_members', 'vocalists', 'band', 'tech'].forEach(function (k) {
                var v = q('[data-stat="' + k + '"] .ui-stat__value');
                if (v) v.textContent = HOD.format.number(st[k]);
            });
            renderRoster();
            renderRehearsals();
        }).catch(function () {
            q('[data-rol-error]').hidden = false;
        });
    }

    /* ------------------------------------------------------------ roll-call */

    function renderRoll() {
        var box = q('[data-rol-roll]');
        box.textContent = '';
        var counts = { Present: 0, Absent: 0, Excused: 0 };
        roll.rows.forEach(function (c) { if (counts[c.status] !== undefined) counts[c.status]++; });
        q('[data-rol-roll-counts]').textContent = MARKS.map(function (m) {
            return HOD.format.number(counts[m]) + ' ' + m.toLowerCase();
        }).join(' · ');
        if (!roll.rows.length) {
            box.appendChild(empty('Nobody on this roll-call', 'People join it when they are on the roster before the rehearsal is scheduled.', 'clipboard-list'));
            return;
        }
        roll.rows.forEach(function (c) {
            var row = el('div', 'ui-list__item flex-wrap');
            row.appendChild(avatar(c));
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', fullName(c)));
            main.appendChild(el('span', 'ui-list__meta', c.section_name || ''));
            row.appendChild(main);
            if (boot.canEdit) {
                var seg = el('div', 'ui-segmented');
                seg.setAttribute('role', 'group');
                seg.setAttribute('aria-label', 'Attendance for ' + fullName(c));
                MARKS.forEach(function (m) {
                    var b = el('button', 'ui-segmented__item', m);
                    b.type = 'button';
                    b.setAttribute('aria-pressed', c.status === m ? 'true' : 'false');
                    b.setAttribute('data-action', 'rol.mark');
                    b.setAttribute('data-user', c.user_id);
                    b.setAttribute('data-mark', m);
                    seg.appendChild(b);
                });
                row.appendChild(seg);
            } else {
                row.appendChild(badge(c.status, c.status === 'Present' ? 'success' : c.status === 'Excused' ? 'warning' : ''));
            }
            box.appendChild(row);
        });
    }

    function loadRoll() {
        return HOD.api(API, { method: 'GET', action: 'fetch_attendance', data: { rehearsal_id: roll.id }, quiet: true }).then(function (res) {
            roll.rows = res.attendance || [];
            renderRoll();
        });
    }

    HOD.actions.on('rol.roll', function (btn) {
        var id = btn.getAttribute('data-id');
        var r = data.rehearsals.filter(function (x) { return String(x.id) === String(id); })[0];
        if (!r) return;
        roll.id = r.id;
        roll.rows = [];
        q('[data-rol-roll-for]').textContent = r.focus_topic + ' · ' + when(r.rehearsal_date);
        q('[data-rol-roll]').textContent = '';
        HOD.busy(btn, true);
        loadRoll().then(function () {
            HOD.busy(btn, false);
            HOD.dialog.open('rol-roll-dialog');
        }).catch(function () { HOD.busy(btn, false); HOD.toast('The roll-call did not load. Try again.', { tone: 'danger' }); });
    });

    HOD.actions.on('rol.mark', function (btn) {
        var userId = btn.getAttribute('data-user');
        var mark = btn.getAttribute('data-mark');
        var row = roll.rows.filter(function (c) { return String(c.user_id) === String(userId); })[0];
        if (!row || row.status === mark) return;
        var before = row.status;
        row.status = mark;          // show it at once; put it back if the save fails
        renderRoll();
        HOD.api(API, { action: 'mark_attendance', data: { rehearsal_id: roll.id, user_id: userId, status: mark } }).catch(function () {
            row.status = before;
            renderRoll();
        });
    });

    var rollDialog = document.getElementById('rol-roll-dialog');
    // The turnout column catches up once the roll-call is closed.
    if (rollDialog) rollDialog.addEventListener('ui:dialog-close', function () { if (roll.id) load(); });

    /* ------------------------------------------------- assign and schedule */

    function fieldError(form) {
        return function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    HOD.actions.on('rol.assign.new', function () {
        if (!data.available.length) {
            HOD.toast('Everyone on the team already has a section.', { tone: 'info' });
            return;
        }
        var form = q('#rol-assign-form');
        form.reset();
        HOD.form.clearErrors(form);
        var select = form.elements.user_id;
        select.textContent = '';
        select.appendChild(new Option('Choose…', ''));
        data.available.forEach(function (u) { select.appendChild(new Option(fullName(u), u.id)); });
        HOD.combobox.refresh(select);
        HOD.dialog.open('rol-assign-dialog');
    });

    HOD.actions.on('rol.assign.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!v.user_id) errors.user_id = 'Choose who.';
        if (!(v.section_name || '').trim()) errors.section_name = 'Name the section.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'assign_member', data: { user_id: v.user_id, role_category: v.role_category, section_name: v.section_name.trim() } }).then(function () {
            HOD.dialog.close('rol-assign-dialog');
            HOD.toast('Assigned.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('rol.rehearsal.new', function () {
        var form = q('#rol-rehearsal-form');
        form.reset();
        HOD.form.clearErrors(form);
        HOD.dialog.open('rol-rehearsal-dialog');
    });

    HOD.actions.on('rol.rehearsal.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!v.rehearsal_date) errors.rehearsal_date = 'Choose the date and time.';
        if (!(v.focus_topic || '').trim()) errors.focus_topic = 'Give it a focus.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'schedule_rehearsal', data: { rehearsal_date: v.rehearsal_date, focus_topic: v.focus_topic.trim() } }).then(function () {
            HOD.dialog.close('rol-rehearsal-dialog');
            HOD.toast('Rehearsal scheduled.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('rol.remove', function (btn) {
        var id = btn.getAttribute('data-id');
        HOD.confirm({
            title: 'Take off the roster?',
            message: btn.getAttribute('data-name') + ' leaves their section. They stay on the River of Life team.',
            confirmLabel: 'Remove',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'remove_member', data: { roster_id: id } }).then(function () {
                HOD.toast('Removed from the roster.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    load();
})();
