// /assets/ui/js/pages/departments.js
// Departments (modules/departments/index.php): the dashboard of departments,
// one department's leaders and roster, create / edit, add and move people,
// leadership seats, ministry years, the "Start new year" worksheet and the
// downloads. Data from /api/department_api.php through HOD.api; the A4 sheet
// is drawn by window.HODDepartmentExport (assets/js/department_export.js).
// Names and descriptions come from people, so they go in as TEXT (DOM nodes).
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('departments-boot');
    if (!boot) return;
    var API = boot.api;
    var LEADERS = boot.leaders;   // seat key => {value, label, field, prefix}
    var LEADER_KEYS = Object.keys(LEADERS);
    var LEADER_VALUES = LEADER_KEYS.map(function (k) { return LEADERS[k].value; });

    var state = {
        yearId: null, year: null, years: [], activeYearId: null, permissions: {},
        departments: [], allDepts: [], people: { pastors: [], directors: [], workers: [] },
        deptId: null, dept: null, detail: null, activeUserIds: [], exportData: null, rollover: null, liveHeadcount: 0
    };

    /* ------------------------------------------------------------- helpers */

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
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + (opts.size === 'md' ? '' : ' ui-btn--sm'));
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function call(action, data, opts) {
        return HOD.api(API, Object.assign({ action: action, data: data || {} }, opts || {}));
    }

    function words(v) { return String(v || '').replace(/_/g, ' '); }
    function name(p) { return p ? ((p.first_name || '') + ' ' + (p.last_name || '')).trim() : ''; }
    function seatLabel(v) { return boot.seatLabels[v] || words(v); }
    function dateText(d) { return d ? HOD.format.date(d) : '—'; }

    function setOptions(select, options, value, keep) {
        select.textContent = '';
        options.forEach(function (o) {
            var opt = el('option', '', o[1]);
            opt.value = o[0];
            select.appendChild(opt);
        });
        if (keep && value && !options.some(function (o) { return String(o[0]) === String(value); })) {
            var extra = el('option', '', keep);
            extra.value = value;
            select.appendChild(extra);
        }
        select.value = value === undefined || value === null ? '' : String(value);
        HOD.combobox.refresh(select);
    }

    function peopleOptions(list, blank) {
        return [['', blank || '']].concat(list.map(function (p) {
            return [String(p.id), name(p) + (p.gender ? ' · ' + p.gender : '')];
        }));
    }

    function show(sel, on) {
        document.querySelectorAll(sel).forEach(function (n) { n.hidden = !on; });
    }

    /* ----------------------------------------------------------- dashboard */

    var grid = document.querySelector('[data-dept-grid]');
    var yearSelect = document.getElementById('dept-year');

    function loadDashboard(yearId) {
        var data = {};
        if (yearId) data.ministry_year_id = yearId;
        return call('fetch_dashboard', data).then(function (res) {
            state.yearId = res.year.id;
            state.year = res.year;
            state.years = res.ministry_years || [];
            state.activeYearId = res.active_year_id;
            state.permissions = res.permissions || {};
            state.departments = res.master_departments || [];
            state.allDepts = res.all_depts || [];
            state.liveHeadcount = state.permissions.live_headcount || 0;
            state.people = { pastors: res.pastors || [], directors: res.directors || [], workers: res.all_workers || [] };
            drawYears();
            drawStats();
            drawCards();
            applyPermissions();
            return res;
        }).catch(function () {
            grid.textContent = '';
            grid.appendChild(el('p', 'ui-meta', 'Departments did not load. Reload the page to try again.'));
        });
    }

    function drawYears() {
        setOptions(yearSelect, state.years.map(function (y) {
            return [String(y.id), 'Ministry Year ' + y.label + (Number(y.is_active) === 1 ? ' · live' : ' · archived') + ' (' + y.people + ')'];
        }), state.yearId);
    }

    function setStat(key, v) {
        var n = document.querySelector('[data-stat="' + key + '"] .ui-stat__value');
        if (n) n.textContent = HOD.format.number(v || 0);
    }

    function drawStats() {
        var primary = 0, secondary = 0;
        state.departments.forEach(function (d) { primary += d.primary_count; secondary += d.secondary_count; });
        setStat('departments', state.departments.length);
        setStat('primary', primary);
        setStat('secondary', secondary);
        setStat('people', state.liveHeadcount);
    }

    function drawCards() {
        grid.textContent = '';
        if (!state.departments.length) {
            var e = el('div', 'ui-empty ui-empty--compact');
            e.appendChild(el('p', 'ui-empty__title', 'No departments yet'));
            e.appendChild(el('p', 'ui-empty__text', 'Add the first one with New department.'));
            grid.appendChild(e);
            return;
        }
        state.departments.forEach(function (d) {
            var card = el('button', 'ui-card');
            card.type = 'button';
            card.setAttribute('data-action', 'dept.open');
            card.setAttribute('data-id', d.id);
            var body = el('div', 'ui-card__body ui-stack ui-stack--sm');
            var top = el('div', 'ui-toolbar');
            var t = el('div', 'ui-toolbar__grow');
            t.appendChild(el('p', 'ui-h3', d.name));
            t.appendChild(el('p', 'ui-meta', words(d.type)));
            top.appendChild(t);
            if (d.target_demographic && d.target_demographic !== 'All') top.appendChild(badge(boot.demographics[d.target_demographic] || words(d.target_demographic), 'brand'));
            body.appendChild(top);
            var lead = LEADER_KEYS.map(function (k) {
                var l = d.leaders ? d.leaders[LEADERS[k].value] : null;
                return l ? LEADERS[k].label + ': ' + name(l) : '';
            }).filter(Boolean);
            body.appendChild(el('p', 'ui-meta', lead.length ? lead.join(' · ') : 'No leaders appointed yet'));
            var chips = el('div', 'ui-chips');
            chips.appendChild(badge(d.primary_count + ' primary', 'primary'));
            chips.appendChild(badge(d.secondary_count + ' secondary'));
            if (d.sub_units && d.sub_units.length) chips.appendChild(badge(d.sub_units.length + (d.sub_units.length === 1 ? ' sub-unit' : ' sub-units'), 'info'));
            body.appendChild(chips);
            card.appendChild(body);
            grid.appendChild(card);
        });
    }

    function applyPermissions() {
        var p = state.permissions || {};
        var archived = !!(state.year && state.year.archived);
        show('[data-perm="new"]', !!p.can_manage_all && !archived);
        show('[data-perm="rollover"]', !!p.can_rollover);
        show('[data-perm="years"]', !!p.can_edit_year);
        show('[data-dept-archived]', archived);
        // The year-end summary and Roles & permissions → Seats link to #rollover.
        if (p.can_rollover && window.location.hash === '#rollover' && !state.rolloverOpened) {
            state.rolloverOpened = true;
            openRollover();
        }
    }

    function showDashboard() {
        state.deptId = null;
        show('[data-dept-detail]', false);
        show('[data-dept-dashboard]', true);
    }

    HOD.actions.on('dept.year', function () {
        showDashboard();
        loadDashboard(yearSelect.value);
    });
    HOD.actions.on('dept.live', function () { showDashboard(); loadDashboard(state.activeYearId); });
    HOD.actions.on('dept.back', function () { showDashboard(); loadDashboard(state.yearId); });
    HOD.actions.on('dept.open', function (btn) { openDepartment(btn.getAttribute('data-id')); });

    /* -------------------------------------------------------------- detail */

    function openDepartment(id, yearId) {
        state.deptId = Number(id);
        return call('fetch_department_details', { department_id: id, ministry_year_id: yearId || state.yearId }).then(function (res) {
            drawDetail(res);
            show('[data-dept-dashboard]', false);
            show('[data-dept-detail]', true);
            window.scrollTo(0, 0);
        }).catch(function () {});
    }

    function drawDetail(res) {
        var info = res.info;
        state.dept = info;
        state.detail = res;
        state.activeUserIds = (res.active_user_ids || []).map(String);
        var canManage = !!(res.permissions && res.permissions.can_manage);

        document.querySelector('[data-dept-name]').textContent = info.name;
        document.querySelector('[data-dept-desc]').textContent = info.description || 'No description yet.';
        var badges = document.querySelector('[data-dept-badges]');
        badges.textContent = '';
        badges.appendChild(badge(words(info.type), 'primary'));
        badges.appendChild(badge(boot.demographics[info.target_demographic] || words(info.target_demographic)));
        badges.appendChild(badge(res.year.heading));
        if (res.year.archived) badges.appendChild(badge('Archived: read-only', 'warning'));
        show('[data-dept-manage]', canManage);

        // Leadership seats, each with a one-click change.
        var seats = document.querySelector('[data-dept-seats]');
        seats.textContent = '';
        LEADER_KEYS.forEach(function (k) {
            var L = LEADERS[k];
            var holderId = info[L.prefix + '_id'];
            var holder = holderId ? ((info[L.prefix + '_fname'] || '') + ' ' + (info[L.prefix + '_lname'] || '')).trim() : '';
            var card = el('div', 'ui-card');
            var body = el('div', 'ui-card__body ui-toolbar');
            var main = el('div', 'ui-toolbar__grow');
            main.appendChild(el('p', 'ui-meta', L.label));
            main.appendChild(el('p', 'ui-h3', holder || 'Vacant'));
            body.appendChild(main);
            var canChange = canManage && !(k === 'pastor_in_charge' && !res.permissions.is_rank);
            if (canChange) body.appendChild(button('Change', { icon: 'pencil', data: { action: 'dept.seat', seat: k } }));
            card.appendChild(body);
            seats.appendChild(card);
        });

        var subsCard = document.querySelector('[data-dept-subunits-card]');
        var subs = document.querySelector('[data-dept-subunits]');
        subs.textContent = '';
        subsCard.hidden = !(res.sub_units || []).length;
        (res.sub_units || []).forEach(function (s) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('p', 'ui-list__title', s.name));
            main.appendChild(el('p', 'ui-list__meta', s.active_members + ' serving'));
            row.appendChild(main);
            var end = el('div', 'ui-list__end');
            end.appendChild(button('Open', { variant: 'ghost', icon: 'chevron-right', data: { action: 'dept.open', id: s.id } }));
            row.appendChild(end);
            subs.appendChild(row);
        });

        var sub = res.sub_unit_counts || { primary: 0, secondary: 0 };
        document.querySelector('[data-dept-primary-count]').textContent = res.primary.length + (sub.primary ? ' + ' + sub.primary + ' in sub-units' : '');
        document.querySelector('[data-dept-secondary-count]').textContent = res.secondary.length + (sub.secondary ? ' + ' + sub.secondary + ' in sub-units' : '');
        drawRoster(document.querySelector('[data-dept-primary]'), res.primary, 'Secondary', canManage);
        drawRoster(document.querySelector('[data-dept-secondary]'), res.secondary, 'Primary', canManage);
    }

    function drawRoster(body, list, other, canManage) {
        body.textContent = '';
        if (!list.length) {
            var tr0 = el('tr');
            var td0 = el('td');
            td0.colSpan = 5;
            td0.appendChild(el('p', 'ui-meta', 'Nobody here yet this ministry year.'));
            tr0.appendChild(td0);
            body.appendChild(tr0);
            return;
        }
        list.forEach(function (r) {
            var tr = el('tr');
            var who = el('th', '', '');
            who.scope = 'row';
            who.appendChild(el('p', 'ui-table__primary', name(r)));
            if (r.gender) who.appendChild(el('p', 'ui-table__secondary', r.gender));
            who.setAttribute('data-label', 'Member');
            tr.appendChild(who);
            var seat = el('td');
            seat.setAttribute('data-label', 'Seat');
            seat.appendChild(r.role_in_dept === boot.subUnitHead || LEADER_VALUES.indexOf(r.role_in_dept) !== -1
                ? badge(seatLabel(r.role_in_dept), 'brand') : el('span', 'ui-meta', seatLabel(r.role_in_dept)));
            tr.appendChild(seat);
            var phone = el('td', '', r.phone || '—');
            phone.setAttribute('data-label', 'Phone');
            tr.appendChild(phone);
            var joined = el('td', '', dateText(r.joined_at));
            joined.setAttribute('data-label', 'Joined');
            tr.appendChild(joined);
            var act = el('td', 'is-actions');
            if (canManage) {
                act.appendChild(button('Make ' + other, { variant: 'ghost', data: { action: 'dept.move', record: r.record_id, to: other } }));
                act.appendChild(button('Remove', { variant: 'danger-ghost', data: { action: 'dept.remove', record: r.record_id, who: name(r) } }));
            }
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function reloadDetail() { return openDepartment(state.deptId, state.yearId); }

    HOD.actions.on('dept.move', function (btn) {
        HOD.busy(btn, true);
        call('switch_membership', { record_id: btn.getAttribute('data-record'), membership_type: btn.getAttribute('data-to') })
            .then(function (res) { HOD.toast(res.message, 'success'); reloadDetail(); })
            .catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('dept.remove', function (btn) {
        HOD.confirm({
            title: 'Remove from the department?',
            message: btn.getAttribute('data-who') + ' leaves the roster and the downloads. The record is kept; add them back any time.',
            confirmLabel: 'Remove', tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            call('remove_worker', { record_id: btn.getAttribute('data-record') })
                .then(function (res) { HOD.toast(res.message, 'success'); reloadDetail(); }).catch(function () {});
        });
    });

    /* ---------------------------------------------------- leadership seat */

    var seatForm = document.getElementById('dept-seat-form');

    function seatPool(key) {
        if (key === 'pastor_in_charge') return state.people.pastors;
        if (key === 'director') return state.people.directors.concat(state.people.pastors);
        return state.people.workers;
    }

    HOD.actions.on('dept.seat', function (btn) {
        var key = btn.getAttribute('data-seat');
        var L = LEADERS[key];
        var info = state.dept;
        var holder = info[L.prefix + '_id'] ? ((info[L.prefix + '_fname'] || '') + ' ' + (info[L.prefix + '_lname'] || '')).trim() : '';
        seatForm.reset();
        seatForm.elements.role.value = L.value;
        document.getElementById('dept-seat-dialog-title').textContent = 'Change ' + L.label;
        seatForm.querySelector('[data-dept-seat-now]').textContent = holder
            ? 'Now: ' + holder + '. They are relieved and taken off this roster, and told.'
            : 'This seat is vacant.';
        setOptions(seatForm.elements.user_id, peopleOptions(seatPool(key)), '');
        HOD.dialog.open('dept-seat-dialog');
    });

    HOD.actions.on('dept.seat.save', function (form) {
        var vacate = form.elements.vacate.checked;
        var userId = vacate ? '' : form.elements.user_id.value;
        if (!vacate && !userId) {
            HOD.form.setErrors(form, { user_id: 'Pick a person, or leave the seat vacant.' });
            return;
        }
        call('set_leader', { department_id: state.deptId, role: form.elements.role.value, user_id: userId })
            .then(function (res) { HOD.dialog.close('dept-seat-dialog'); HOD.toast(res.message, 'success'); reloadDetail(); })
            .catch(function () {});
    });

    /* ------------------------------------------------- create / edit dept */

    var editForm = document.getElementById('dept-edit-form');

    function fillParents(exceptId) {
        setOptions(editForm.elements.parent_id, [['', 'Nothing: a main department']].concat(state.allDepts.filter(function (d) {
            return String(d.id) !== String(exceptId || '');
        }).map(function (d) { return [String(d.id), d.name]; })), '');
    }

    function fillLeaders(info) {
        var pools = { pastor_in_charge: state.people.pastors, director: state.people.directors, hod: state.people.workers };
        LEADER_KEYS.forEach(function (k) {
            var L = LEADERS[k];
            var select = editForm.elements[L.field];
            var id = info ? info[L.prefix + '_id'] : '';
            var holder = info && id ? ((info[L.prefix + '_fname'] || '') + ' ' + (info[L.prefix + '_lname'] || '')).trim() : '';
            // The sitting leader stays selectable even when outside the pool, so
            // saving the form never vacates a seat by accident.
            setOptions(select, peopleOptions(pools[k], '—'), id || '', holder);
        });
        // The Pastor-in-charge seat is only for those who may appoint it; a
        // disabled field is not sent, so the API leaves the seat alone.
        var canPastor = !!(state.permissions.can_manage_all || (state.detail && state.detail.permissions && state.detail.permissions.is_rank));
        var pastor = editForm.elements[LEADERS.pastor_in_charge.field];
        pastor.disabled = !canPastor;
        pastor.closest('.ui-field').hidden = !canPastor;
        HOD.combobox.refresh(pastor);
    }

    HOD.actions.on('dept.new', function () {
        editForm.reset();
        editForm.elements.department_id.value = '';
        editForm.elements.ministry_year_id.value = state.activeYearId || '';
        fillParents();
        fillLeaders(null);
        document.getElementById('dept-edit-dialog-title').textContent = 'New department';
        HOD.dialog.open('dept-edit-dialog');
    });

    HOD.actions.on('dept.edit', function () {
        var info = state.dept;
        if (!info) return;
        editForm.reset();
        editForm.elements.department_id.value = info.id;
        editForm.elements.ministry_year_id.value = state.yearId;
        editForm.elements.name.value = info.name;
        editForm.elements.description.value = info.description || '';
        editForm.elements.type.value = info.type;
        editForm.elements.target_demographic.value = info.target_demographic;
        fillParents(info.id);
        editForm.elements.parent_id.value = info.parent_id || '';
        fillLeaders(info);
        document.getElementById('dept-edit-dialog-title').textContent = 'Edit settings';
        HOD.dialog.open('dept-edit-dialog');
    });

    HOD.actions.on('dept.save', function (form) {
        var v = HOD.form.values(form);
        if (!String(v.name || '').trim()) {
            HOD.form.setErrors(form, { name: 'Give the department a name.' });
            return;
        }
        var isNew = !v.department_id;
        var submit = document.querySelector('[form="dept-edit-form"][type="submit"]');
        HOD.busy(submit, true);
        call('save_department', v).then(function (res) {
            HOD.dialog.close('dept-edit-dialog');
            HOD.toast(res.message, 'success');
            return isNew ? loadDashboard(state.yearId) : reloadDetail();
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        }).finally(function () { HOD.busy(submit, false); });
    });

    /* ----------------------------------------------------------- add member */

    var addForm = document.getElementById('dept-add-form');

    HOD.actions.on('dept.add', function () {
        if (!state.deptId) return;
        addForm.reset();
        addForm.elements.department_id.value = state.deptId;
        addForm.querySelector('[data-dept-add-where]').textContent = state.dept.name + ' · ' + state.detail.year.heading;
        var pool = state.people.workers.filter(function (p) { return state.activeUserIds.indexOf(String(p.id)) === -1; });
        setOptions(addForm.elements.user_id, peopleOptions(pool), '');
        HOD.dialog.open('dept-add-dialog');
    });

    HOD.actions.on('dept.add.save', function (form) {
        var v = HOD.form.values(form);
        if (!v.user_id) {
            HOD.form.setErrors(form, { user_id: 'Pick the person to add.' });
            return;
        }
        var submit = document.querySelector('[form="dept-add-form"][type="submit"]');
        HOD.busy(submit, true);
        // "warning" (serving in many departments) is a success with a caution.
        call('assign_worker', v, { raw: true, quiet: true }).then(function (res) {
            if (res.status !== 'success' && res.status !== 'warning') {
                HOD.form.setErrors(form, { user_id: res.message || 'That did not save. Try again.' });
                return;
            }
            HOD.dialog.close('dept-add-dialog');
            HOD.toast(res.message, res.status === 'warning' ? 'warning' : 'success');
            reloadDetail();
        }).catch(function (err) {
            HOD.form.setErrors(form, { user_id: (err && err.message) || 'That did not save. Try again.' });
        }).finally(function () { HOD.busy(submit, false); });
    });

    /* ------------------------------------------------------------ downloads */

    var dlSummary = document.querySelector('[data-dept-download-summary]');

    HOD.actions.on('dept.download', function () {
        state.exportData = null;
        dlSummary.textContent = 'Preparing the roster…';
        HOD.dialog.open('dept-download-dialog');
        call('fetch_export_dataset', { ministry_year_id: state.yearId }, { quiet: true }).then(function (res) {
            state.exportData = res.data;
            var t = res.data.totals;
            dlSummary.textContent = res.data.year.heading + ': ' + t.departments + ' departments · ' + t.primary + ' primary · '
                + t.secondary + ' secondary · ' + t.people + ' people.';
        }).catch(function () { dlSummary.textContent = 'The roster did not load. Close and try again.'; });
    });

    HOD.actions.on('dept.download.file', function (btn) {
        var format = btn.getAttribute('data-format');
        window.location.href = boot.exportUrl + '?format=' + encodeURIComponent(format) + '&ministry_year_id=' + encodeURIComponent(state.yearId);
        HOD.toast(format === 'csv' ? 'CSV download started.' : 'Excel download started.', 'success');
    });

    HOD.actions.on('dept.download.image', function (btn) {
        if (!state.exportData) {
            HOD.toast('The roster is still loading. Try again in a moment.', 'warning');
            return;
        }
        if (!window.HODDepartmentExport) {
            HOD.toast('The image tool did not load. Reload the page.', 'danger');
            return;
        }
        HOD.busy(btn, true);
        window.HODDepartmentExport.render(state.exportData).then(function (result) {
            return window.HODDepartmentExport.download(result.canvas, result.filename).then(function () { return result; });
        }).then(function (result) {
            HOD.dialog.close('dept-download-dialog');
            HOD.toast(result.overflows ? 'Saved. A very large roster: the sheet is squeezed to fit one page.'
                : 'Saved ' + result.nameCount + ' names on one A4 page.', result.overflows ? 'warning' : 'success');
        }).catch(function (err) {
            HOD.toast('The image could not be drawn. ' + (err && err.message ? err.message : ''), 'danger');
        }).finally(function () { HOD.busy(btn, false); });
    });

    /* ------------------------------------------------------- ministry years */

    var yearsList = document.querySelector('[data-dept-years]');
    var yearForm = document.getElementById('dept-year-form');

    function drawYearsList() {
        yearsList.textContent = '';
        if (!state.years.length) {
            yearsList.appendChild(el('p', 'ui-list__item ui-meta', 'No ministry years yet.'));
            return;
        }
        state.years.forEach(function (y) {
            var live = Number(y.is_active) === 1;
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            var t = el('p', 'ui-list__title', 'Ministry Year ' + y.label + ' ');
            t.appendChild(badge(live ? 'Live' : 'Archived', live ? 'success' : ''));
            main.appendChild(t);
            main.appendChild(el('p', 'ui-list__meta', y.period + ' · ' + y.people + ' on the roster'));
            row.appendChild(main);
            var end = el('div', 'ui-list__end');
            end.appendChild(button('Edit', { variant: 'ghost', icon: 'pencil', data: { action: 'dept.year.edit', id: y.id } }));
            if (!live) end.appendChild(button('Make live', { variant: 'ghost', icon: 'circle-check', data: { action: 'dept.year.live', id: y.id } }));
            row.appendChild(end);
            yearsList.appendChild(row);
        });
    }

    function yearById(id) {
        for (var i = 0; i < state.years.length; i++) if (String(state.years[i].id) === String(id)) return state.years[i];
        return null;
    }

    HOD.actions.on('dept.years', function () {
        yearForm.reset();
        yearForm.elements.id.value = '';
        drawYearsList();
        HOD.dialog.open('dept-years-dialog');
    });

    HOD.actions.on('dept.year.edit', function (btn) {
        var y = yearById(btn.getAttribute('data-id'));
        if (!y) return;
        yearForm.elements.id.value = y.id;
        yearForm.elements.label.value = y.label;
        yearForm.elements.start_date.value = y.start_date;
        yearForm.elements.end_date.value = y.end_date;
        yearForm.elements.is_active.checked = Number(y.is_active) === 1;
        yearForm.elements.label.focus();
    });

    HOD.actions.on('dept.year.live', function (btn) {
        var y = yearById(btn.getAttribute('data-id'));
        if (!y) return;
        HOD.confirm({
            title: 'Make this year live?',
            message: 'The year live today becomes archived and read-only. Rosters stay with their own year.',
            confirmLabel: 'Make live'
        }).then(function (yes) {
            if (!yes) return;
            call('save_ministry_year', { id: y.id, label: y.label, start_date: y.start_date, end_date: y.end_date, is_active: 1 })
                .then(function (res) {
                    HOD.toast(res.message, 'success');
                    HOD.dialog.close('dept-years-dialog');
                    showDashboard();
                    loadDashboard();
                }).catch(function () {});
        });
    });

    HOD.actions.on('dept.year.save', function (form) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!v.label) errors.label = 'Give the year a label.';
        if (!v.start_date) errors.start_date = 'Pick the first day.';
        if (!v.end_date) errors.end_date = 'Pick the last day.';
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }
        call('save_ministry_year', { id: v.id || '', label: v.label, start_date: v.start_date, end_date: v.end_date, is_active: form.elements.is_active.checked ? 1 : 0 })
            .then(function (res) {
                HOD.toast(res.message, 'success');
                form.reset();
                form.elements.id.value = '';
                return loadDashboard(state.yearId).then(drawYearsList);
            }).catch(function () {});
    });

    /* ------------------------------------------------- start new year sheet */

    var roBody = document.querySelector('[data-dept-ro-body]');
    var roSummary = document.querySelector('[data-dept-ro-summary]');
    var roLabel = document.getElementById('dept-ro-label');
    var roStart = document.getElementById('dept-ro-start');
    var roEnd = document.getElementById('dept-ro-end');

    function blankEntry() {
        var seats = {};
        LEADER_VALUES.forEach(function (v) { seats[v] = ''; });
        return { seats: seats, members: [] };
    }

    function openRollover() {
        state.rollover = null;
        roBody.textContent = '';
        var l = el('div', 'ui-loading');
        l.appendChild(el('span', 'ui-spinner'));
        l.appendChild(el('span', '', 'Loading the roster…'));
        roBody.appendChild(l);
        roSummary.textContent = '';
        HOD.dialog.open('dept-rollover-dialog');
        call('fetch_rollover_worksheet').then(function (res) {
            var byId = {};
            (res.candidates || []).forEach(function (c) { byId[String(c.id)] = c; });
            state.rollover = { from: res.from_year, departments: res.departments || [], people: res.people || {},
                candidates: res.candidates || [], byId: byId, worksheet: {} };
            roLabel.value = res.suggested.label;
            roStart.value = res.suggested.start_date;
            roEnd.value = res.suggested.end_date;
            document.querySelector('[data-dept-ro-from]').textContent = 'From ' + res.from_year.heading + ' (' + res.from_year.period + ').';
            preset('members');
        }).catch(function () { HOD.dialog.close('dept-rollover-dialog'); });
    }

    function preset(mode) {
        var r = state.rollover;
        if (!r) return;
        document.querySelectorAll('[data-action="dept.ro.preset"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b.getAttribute('data-mode') === mode ? 'true' : 'false');
        });
        var ws = {};
        r.departments.forEach(function (d) {
            var entry = blankEntry();
            if (mode === 'carry') {
                LEADER_VALUES.forEach(function (v) {
                    var seat = d.leaders ? d.leaders[v] : null;
                    entry.seats[v] = seat ? String(seat.user_id) : '';
                });
            }
            if (mode !== 'empty') {
                Object.keys(r.people).forEach(function (uid) {
                    (r.people[uid] || []).forEach(function (row) {
                        if (String(row.department_id) !== String(d.id) || LEADER_VALUES.indexOf(row.role_in_dept) !== -1) return;
                        entry.members.push({ user_id: String(uid), membership: row.membership_type, role: row.role_in_dept });
                    });
                });
                entry.members.sort(function (a, b) { return name(r.byId[a.user_id]).localeCompare(name(r.byId[b.user_id])); });
            }
            ws[d.id] = entry;
        });
        r.worksheet = ws;
        drawSheet();
    }

    HOD.actions.on('dept.ro.preset', function (btn) { preset(btn.getAttribute('data-mode')); });
    HOD.actions.on('dept.rollover', openRollover);

    function candidateOptions(blank) {
        return [['', blank]].concat(state.rollover.candidates.map(function (c) {
            return [String(c.id), name(c) + (c.spiritual_status ? ' · ' + words(c.spiritual_status) : '')];
        }));
    }

    function drawSheet() {
        var r = state.rollover;
        var seatOpts = candidateOptions('Vacant');
        var addOpts = candidateOptions('');
        roBody.textContent = '';
        var mains = r.departments.filter(function (d) { return !d.parent_id; });
        if (!mains.length) roBody.appendChild(el('p', 'ui-meta', 'No departments.'));
        mains.forEach(function (d) {
            var card = el('section', 'ui-card');
            var head = el('div', 'ui-card__header ui-card__header--divided ui-toolbar');
            var t = el('div', 'ui-toolbar__grow');
            t.appendChild(el('h3', 'ui-h3', d.name));
            var subs = r.departments.filter(function (s) { return String(s.parent_id) === String(d.id); });
            t.appendChild(el('p', 'ui-meta', words(d.type) + (subs.length ? ' · ' + subs.length + (subs.length === 1 ? ' sub-unit' : ' sub-units') : '')));
            head.appendChild(t);
            head.appendChild(el('span', 'ui-meta', countPlaced(r.worksheet[d.id]) + ' placed'));
            head.appendChild(button('Clear', { variant: 'ghost', data: { action: 'dept.ro.clear', dept: d.id } }));
            card.appendChild(head);
            var body = el('div', 'ui-card__body ui-stack');
            body.appendChild(sheetBlock(d.id, seatOpts, addOpts));
            subs.forEach(function (s) {
                var sub = el('div', 'ui-stack ui-stack--sm');
                sub.appendChild(el('p', 'ui-list__title', '↳ ' + s.name));
                sub.appendChild(sheetBlock(s.id, seatOpts, addOpts));
                body.appendChild(sub);
            });
            card.appendChild(body);
            roBody.appendChild(card);
        });
        roBody.querySelectorAll('select[data-ui-combobox]').forEach(function (s) { HOD.combobox.enhance(s); });
        updateSummary();
    }

    function sheetBlock(deptId, seatOpts, addOpts) {
        var entry = state.rollover.worksheet[deptId] || (state.rollover.worksheet[deptId] = blankEntry());
        var wrap = el('div', 'ui-stack ui-stack--sm');
        var seats = el('div', 'ui-form-grid ui-form-grid--3');
        LEADER_KEYS.forEach(function (k) {
            var L = LEADERS[k];
            var f = el('div', 'ui-field');
            var id = 'ro-' + deptId + '-' + k;
            var lab = el('label', 'ui-label', L.label);
            lab.htmlFor = id;
            f.appendChild(lab);
            var s = el('select', 'ui-select');
            s.id = id;
            s.setAttribute('data-action-change', 'dept.ro.seat');
            s.setAttribute('data-dept', deptId);
            s.setAttribute('data-seat', L.value);
            seatOpts.forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; s.appendChild(opt); });
            s.value = entry.seats[L.value] || '';
            f.appendChild(s);
            seats.appendChild(f);
        });
        wrap.appendChild(seats);

        var chips = el('div', 'ui-chips');
        if (!entry.members.length) chips.appendChild(el('span', 'ui-meta', 'No members yet.'));
        entry.members.forEach(function (m) {
            var c = state.rollover.byId[m.user_id];
            var chip = el('span', 'ui-chip');
            var tog = el('button', 'ui-badge' + (m.membership === 'Secondary' ? '' : ' ui-badge--primary'), m.membership === 'Secondary' ? 'S' : 'P');
            tog.type = 'button';
            tog.setAttribute('data-action', 'dept.ro.toggle');
            tog.setAttribute('data-dept', deptId);
            tog.setAttribute('data-user', m.user_id);
            tog.setAttribute('aria-label', 'Switch ' + name(c) + ' to ' + (m.membership === 'Secondary' ? 'Primary' : 'Secondary'));
            chip.appendChild(tog);
            chip.appendChild(el('span', '', ' ' + name(c) + (m.role && m.role !== boot.memberSeat ? ' · ' + seatLabel(m.role) : '') + ' '));
            var x = el('button', 'ui-btn ui-btn--ghost ui-btn--sm ui-btn--icon');
            x.type = 'button';
            x.setAttribute('data-action', 'dept.ro.out');
            x.setAttribute('data-dept', deptId);
            x.setAttribute('data-user', m.user_id);
            x.setAttribute('aria-label', 'Take ' + name(c) + ' out');
            x.appendChild(icon('x'));
            chip.appendChild(x);
            chips.appendChild(chip);
        });
        wrap.appendChild(chips);

        var add = el('div', 'ui-toolbar');
        var pick = el('select', 'ui-select');
        pick.setAttribute('data-ui-combobox', '');
        pick.setAttribute('aria-label', 'Person to add');
        pick.setAttribute('data-ro-add', deptId);
        addOpts.forEach(function (o) { var opt = el('option', '', o[1]); opt.value = o[0]; pick.appendChild(opt); });
        var grow = el('div', 'ui-toolbar__grow');
        grow.appendChild(pick);
        add.appendChild(grow);
        var type = el('select', 'ui-select');
        type.setAttribute('aria-label', 'Membership');
        type.setAttribute('data-ro-type', deptId);
        ['Primary', 'Secondary'].forEach(function (v) { var opt = el('option', '', v); opt.value = v; type.appendChild(opt); });
        add.appendChild(type);
        add.appendChild(button('Add', { icon: 'plus', data: { action: 'dept.ro.add', dept: deptId } }));
        wrap.appendChild(add);
        return wrap;
    }

    function countPlaced(entry) {
        if (!entry) return 0;
        return entry.members.length + Object.keys(entry.seats).filter(function (k) { return entry.seats[k]; }).length;
    }

    function updateSummary() {
        var ws = state.rollover ? state.rollover.worksheet : {};
        var people = 0, primary = 0, secondary = 0, seats = 0;
        Object.keys(ws).forEach(function (id) {
            var e = ws[id];
            people += e.members.length;
            e.members.forEach(function (m) { if (m.membership === 'Secondary') secondary++; else primary++; });
            seats += Object.keys(e.seats).filter(function (k) { return e.seats[k]; }).length;
        });
        roSummary.textContent = people + ' members (' + primary + ' primary, ' + secondary + ' secondary) · ' + seats + ' seats filled';
    }

    HOD.actions.on('dept.ro.seat', function (s) {
        state.rollover.worksheet[s.getAttribute('data-dept')].seats[s.getAttribute('data-seat')] = s.value;
        updateSummary();
    });
    HOD.actions.on('dept.ro.clear', function (btn) {
        state.rollover.worksheet[btn.getAttribute('data-dept')] = blankEntry();
        drawSheet();
    });
    HOD.actions.on('dept.ro.toggle', function (btn) {
        state.rollover.worksheet[btn.getAttribute('data-dept')].members.forEach(function (m) {
            if (m.user_id === btn.getAttribute('data-user')) m.membership = m.membership === 'Secondary' ? 'Primary' : 'Secondary';
        });
        drawSheet();
    });
    HOD.actions.on('dept.ro.out', function (btn) {
        var e = state.rollover.worksheet[btn.getAttribute('data-dept')];
        e.members = e.members.filter(function (m) { return m.user_id !== btn.getAttribute('data-user'); });
        drawSheet();
    });
    HOD.actions.on('dept.ro.add', function (btn) {
        var deptId = btn.getAttribute('data-dept');
        var pick = roBody.querySelector('[data-ro-add="' + deptId + '"]');
        var type = roBody.querySelector('[data-ro-type="' + deptId + '"]');
        if (!pick || !pick.value) {
            HOD.toast('Search for a person first, then press Add.', 'warning');
            return;
        }
        var e = state.rollover.worksheet[deptId];
        if (!e.members.some(function (m) { return m.user_id === pick.value; })) {
            e.members.push({ user_id: pick.value, membership: type.value });
        }
        drawSheet();
    });

    HOD.actions.on('dept.ro.commit', function (btn) {
        var r = state.rollover;
        if (!r) return;
        var label = roLabel.value.trim(), start = roStart.value, end = roEnd.value;
        if (!label || !start || !end) {
            HOD.toast('Give the new year a label and both dates.', 'warning');
            return;
        }
        var assignments = [];
        Object.keys(r.worksheet).forEach(function (deptId) {
            var e = r.worksheet[deptId];
            Object.keys(e.seats).forEach(function (v) {
                if (e.seats[v]) assignments.push({ user_id: e.seats[v], department_id: deptId, role_in_dept: v, membership_type: 'Primary' });
            });
            e.members.forEach(function (m) {
                assignments.push({ user_id: m.user_id, department_id: deptId, role_in_dept: m.role || boot.memberSeat, membership_type: m.membership });
            });
        });
        HOD.confirm({
            title: 'Make Ministry Year ' + label + ' live?',
            message: (assignments.length ? assignments.length + ' places filled. ' : 'Nobody is placed yet. ')
                + 'The current year becomes archived and read-only; everyone placed is told.',
            confirmLabel: 'Go live'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('commit_rollover', { label: label, start_date: start, end_date: end, assignments: JSON.stringify(assignments) })
                .then(function (res) {
                    HOD.dialog.close('dept-rollover-dialog');
                    HOD.toast(res.message, 'success');
                    showDashboard();
                    loadDashboard(res.data.ministry_year_id);
                }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    });

    loadDashboard();
})();
