// /assets/ui/js/pages/roles.js
// Roles & permissions (modules/roles/index.php): the matrix, roles, people
// (roles held, seats, effective access, view as), seats & ownership, and the
// history. Data from /api/roles_api.php through HOD.api. Names and labels come
// from people and admins, so everything is put in the page as TEXT (DOM
// nodes); markup strings hold only our own words and HOD.esc()'d values.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('roles-boot');
    if (!boot) return;
    var API = boot.api;
    var LEVELS = boot.levels;            // ['—', 'View', 'Create', 'Edit', 'Delete']
    var LETTER = ['—', 'V', 'C', 'E', 'D'];
    var SCOPE_WORD = boot.scopeWords;     // own → Own, dept → Dept, church → All
    var LEVEL_KEYS = ['none', 'view', 'create', 'edit', 'delete'];

    var state = {
        matrix: null,       // perm_matrix_data()
        roles: null,        // {roles, presets}
        seats: null,
        person: null,       // perm_person_overview()
        history: { offset: 0, filters: {} },
        cell: null,         // {holder, module} open in the dialog
        column: null
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

    function button(label, opts) {
        opts = opts || {};
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + (opts.size === 'sm' ? ' ui-btn--sm' : ''));
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function badge(label, tone) {
        return el('span', 'ui-badge' + (tone ? ' ui-badge--' + tone : ''), label);
    }

    function plural(n, one, many) {
        return HOD.format.number(n) + ' ' + (Number(n) === 1 ? one : (many || one + 's'));
    }

    function dateText(ymd) {
        return ymd ? HOD.format.date(ymd + 'T12:00:00') : '';
    }

    function call(action, data, quiet) {
        return HOD.api(API, { action: action, data: data || {}, quiet: !!quiet });
    }

    function failed(err) {
        // HOD.api already showed the message; offer the fix when there is one.
        if (err && err.response && err.response.link) {
            HOD.toast(err.response.message, { tone: 'warning', action: { label: 'Open profile', run: function () { window.location.href = err.response.link; } } });
        }
    }

    function loading(box, words) {
        box.textContent = '';
        var w = el('div', 'ui-loading');
        w.appendChild(el('span', 'ui-spinner'));
        w.appendChild(el('span', '', words));
        box.appendChild(w);
    }

    function empty(box, title, text) {
        box.textContent = '';
        var w = el('div', 'ui-empty ui-empty--compact');
        w.appendChild(el('p', 'ui-empty__title', title));
        if (text) w.appendChild(el('p', 'ui-empty__text', text));
        box.appendChild(w);
    }

    function setOptions(select, options, value) {
        select.textContent = '';
        options.forEach(function (o) {
            var opt = el('option', '', o[1]);
            opt.value = o[0];
            if (String(o[0]) === String(value)) opt.selected = true;
            select.appendChild(opt);
        });
    }

    function moduleDef(key) {
        var list = state.matrix ? state.matrix.modules : [];
        for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
        return null;
    }

    function holderDef(key) {
        var list = state.matrix ? state.matrix.holders : [];
        for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
        return null;
    }

    /* -------------------------------------------------------------- matrix */

    var matrixBox = document.querySelector('[data-roles-matrix]');
    var columnSelect = document.getElementById('roles-matrix-column');
    var matrixSearch = document.getElementById('roles-matrix-search');

    function loadMatrix() {
        return call('matrix').then(function (data) {
            state.matrix = data;
            var keep = columnSelect.value;
            setOptions(columnSelect, [['', 'Every column']].concat(data.holders.map(function (h) {
                return [h.key, h.label + (h.kind === 'seat' ? ' (seat)' : '')];
            })), keep);
            fillModuleFilter(data.modules);
            drawMatrix();
        }).catch(function () {
            empty(matrixBox, 'Matrix not loaded', 'Reload the page to try again.');
        });
    }

    function cellText(c) {
        if (!c || (!c.level && !c.approve && !c.export && !c.manage)) return null;
        var extras = (c.approve ? 'a' : '') + (c.export ? 'x' : '') + (c.manage ? 'm' : '');
        return { main: LETTER[c.level] + (extras ? ' ' + extras : ''), scope: SCOPE_WORD[c.scope] || '' };
    }

    function cellTone(c) {
        return c.scope === 'church' ? 'primary' : (c.scope === 'dept' ? 'info' : '');
    }

    function drawMatrix() {
        var m = state.matrix;
        if (!m) return;
        var q = (matrixSearch.value || '').trim().toLowerCase();
        var only = columnSelect.value;
        var holders = m.holders.filter(function (h) { return !only || h.key === only; });
        var table = el('table', 'ui-table ui-table--compact roles-matrix');
        var head = el('thead');
        var hr = el('tr');
        var corner = el('th', 'is-head', 'Module');
        corner.scope = 'col';
        hr.appendChild(corner);
        holders.forEach(function (h) {
            var th = el('th', 'is-head');
            th.scope = 'col';
            if (boot.canManage && !h.breakglass) {
                var b = button(h.label, { variant: 'ghost', size: 'sm', data: { action: 'roles.column', holder: h.key } });
                b.setAttribute('aria-label', 'Column actions for ' + h.label);
                th.appendChild(b);
            } else {
                th.appendChild(el('span', 'ui-table__primary', h.label));
            }
            th.appendChild(el('span', 'ui-table__secondary', h.breakglass ? 'Everything'
                : (h.kind === 'seat' ? 'Seat · ' : (h.kind === 'role' ? 'Role · ' : '')) + plural(h.people, 'person', 'people')));
            hr.appendChild(th);
        });
        head.appendChild(hr);
        table.appendChild(head);

        var body = el('tbody');
        var shown = 0;
        Object.keys(m.groups).forEach(function (g) {
            var mods = m.modules.filter(function (mod) {
                return mod.group === g && (!q || mod.label.toLowerCase().indexOf(q) !== -1 || mod.key.indexOf(q) !== -1);
            });
            if (!mods.length) return;
            var gr = el('tr', 'roles-matrix__group');
            var gth = el('th', '', m.groups[g]);
            gth.scope = 'colgroup';
            gth.colSpan = holders.length + 1;
            gr.appendChild(gth);
            body.appendChild(gr);
            mods.forEach(function (mod) {
                shown++;
                var tr = el('tr');
                var th = el('th', '', mod.label);
                th.scope = 'row';
                tr.appendChild(th);
                holders.forEach(function (h) {
                    var td = el('td');
                    td.setAttribute('data-label', h.label);
                    var c = (m.cells[h.key] || {})[mod.key];
                    var t = cellText(c);
                    if (h.breakglass) {
                        td.appendChild(el('span', 'ui-meta', 'All'));
                    } else if (boot.canManage) {
                        var b = el('button', 'ui-badge roles-cell' + (t ? (cellTone(c) ? ' ui-badge--' + cellTone(c) : '') : ''));
                        b.type = 'button';
                        b.setAttribute('data-action', 'roles.cell');
                        b.setAttribute('data-holder', h.key);
                        b.setAttribute('data-module', mod.key);
                        b.setAttribute('aria-label', mod.label + ', ' + h.label + ': ' + (t ? LEVELS[c.level] + ' ' + t.scope : 'no access'));
                        b.textContent = t ? t.main + ' · ' + t.scope : '—';
                        td.appendChild(b);
                    } else {
                        td.appendChild(t ? badge(t.main + ' · ' + t.scope, cellTone(c)) : el('span', 'ui-meta', '—'));
                    }
                    tr.appendChild(td);
                });
                body.appendChild(tr);
            });
        });
        table.appendChild(body);
        matrixBox.textContent = '';
        if (!shown) {
            empty(matrixBox, 'No module found', 'Try another word.');
            return;
        }
        matrixBox.appendChild(table);
    }

    HOD.actions.on('roles.matrix.filter', drawMatrix);

    // One cell: the dialog.
    var cellForm = document.getElementById('roles-cell-form');

    function mineRank(module, ability) {
        var mine = state.matrix.mine;
        if (mine === null) return 3;
        return (mine[module] && mine[module][ability]) || 0;
    }

    HOD.actions.on('roles.cell', function (btn) {
        var holder = btn.getAttribute('data-holder');
        var module = btn.getAttribute('data-module');
        var mod = moduleDef(module);
        var h = holderDef(holder);
        if (!mod || !h) return;
        var c = (state.matrix.cells[holder] || {})[module] || { level: 0, approve: 0, export: 0, manage: 0, scope: 'church', view_scope: null };
        state.cell = { holder: holder, module: module };
        cellForm.querySelector('[data-cell-where]').textContent = mod.label + ' · ' + h.label;
        cellForm.querySelectorAll('[data-level-option]').forEach(function (label) {
            var idx = LEVEL_KEYS.indexOf(label.getAttribute('data-level-option'));
            var input = label.querySelector('input');
            label.hidden = idx > mod.max;
            input.checked = idx === c.level;
            input.disabled = idx > 0 && mineRank(module, LEVEL_KEYS[idx]) === 0;
        });
        var anyExtra = false;
        cellForm.querySelectorAll('[data-extra-option]').forEach(function (label) {
            var key = label.getAttribute('data-extra-option');
            var has = mod.extras.indexOf(key) !== -1;
            var input = label.querySelector('input');
            label.hidden = !has;
            anyExtra = anyExtra || has;
            input.checked = !!c[key];
            input.disabled = mineRank(module, key) === 0;
        });
        cellForm.querySelector('[data-cell-extras]').hidden = !anyExtra;
        var scope = cellForm.elements.scope;
        var view = cellForm.elements.view_scope;
        scope.value = c.scope || 'church';
        view.value = c.view_scope || '';
        // Everyone has no department.
        [scope, view].forEach(function (s) {
            Array.prototype.forEach.call(s.options, function (o) { o.disabled = h.kind === 'everyone' && o.value === 'dept'; });
        });
        cellForm.querySelector('[data-cell-impact]').textContent = h.kind === 'everyone'
            ? 'Changes access for every signed-in member.'
            : 'Changes access for ' + plural(h.people, 'person', 'people') + ' now.';
        HOD.dialog.open('roles-cell-dialog');
    });

    HOD.actions.on('roles.cell.save', function (form) {
        if (!state.cell) return;
        var v = HOD.form.values(form);
        var data = {
            holder: state.cell.holder, module: state.cell.module, level: v.level || 'none',
            approve: v.approve ? 1 : 0, export: v.export ? 1 : 0, manage: v.manage ? 1 : 0,
            scope: v.scope || 'church', view_scope: v.view_scope || ''
        };
        var submit = document.querySelector('[form="roles-cell-form"][type="submit"]');
        HOD.busy(submit, true);
        call('cell_set', data).then(function (res) {
            state.matrix.cells[state.cell.holder] = state.matrix.cells[state.cell.holder] || {};
            state.matrix.cells[state.cell.holder][state.cell.module] = res.cell;
            HOD.dialog.close('roles-cell-dialog');
            HOD.toast(res.message, 'success');
            drawMatrix();
        }).catch(failed).finally(function () { HOD.busy(submit, false); });
    });

    // A whole column.
    var columnForm = document.getElementById('roles-column-form');
    HOD.actions.on('roles.column', function (btn) {
        var holder = btn.getAttribute('data-holder');
        var h = holderDef(holder);
        if (!h) return;
        state.column = holder;
        columnForm.querySelector('[data-column-where]').textContent = h.label;
        setOptions(columnForm.elements.from, state.matrix.holders.filter(function (x) {
            return x.key !== holder && !x.breakglass;
        }).map(function (x) { return [x.key, x.label + (x.kind === 'seat' ? ' (seat)' : '')]; }));
        columnForm.querySelector('[data-column-impact]').textContent = h.kind === 'everyone'
            ? 'Changes access for every signed-in member.' : 'Changes access for ' + plural(h.people, 'person', 'people') + ' now.';
        HOD.dialog.open('roles-column-dialog');
    });

    HOD.actions.on('roles.column.save', function (form) {
        var v = HOD.form.values(form);
        var h = holderDef(state.column);
        var words = { copy: 'Copy into', readonly: 'Make read-only:', clear: 'Clear' }[v.op] || 'Change';
        HOD.confirm({
            title: 'Change this column?',
            message: words + ' ' + (h ? h.label : '') + '. Every changed cell is kept in History.',
            confirmLabel: 'Apply', tone: v.op === 'clear' ? 'danger' : undefined
        }).then(function (yes) {
            if (!yes) return;
            call('column', { holder: state.column, op: v.op, from: v.from || '' }).then(function (res) {
                HOD.dialog.close('roles-column-dialog');
                HOD.toast(res.message, 'success');
                loadMatrix();
            }).catch(failed);
        });
    });

    /* --------------------------------------------------------------- roles */

    var rolesBox = document.querySelector('[data-roles-list]');
    var roleForm = document.getElementById('roles-role-form');

    function loadRoles() {
        loading(rolesBox, 'Loading roles…');
        return call('roles').then(function (data) {
            state.roles = data;
            drawRoles();
        }).catch(function () { empty(rolesBox, 'Roles not loaded', 'Reload the page to try again.'); });
    }

    function drawRoles() {
        rolesBox.textContent = '';
        state.roles.roles.forEach(function (r) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            var title = el('p', 'ui-list__title', r.label);
            [[r.breakglass, 'Everything', 'brand'], [r.system && !r.breakglass, 'Built in', ''], [r.archived, 'Archived', '']].forEach(function (b) {
                if (!b[0]) return;
                title.appendChild(document.createTextNode(' '));
                title.appendChild(badge(b[1], b[2]));
            });
            main.appendChild(title);
            main.appendChild(el('p', 'ui-list__meta', (r.description ? r.description + ' · ' : '') + plural(r.holders, 'holder')));
            row.appendChild(main);
            var end = el('div', 'ui-list__end');
            if (!r.archived) end.appendChild(button('View as', { variant: 'ghost', size: 'sm', icon: 'eye', data: { action: 'roles.viewas', kind: 'role', id: r.id } }));
            if (boot.canManage && !r.breakglass) {
                end.appendChild(button('Edit', { variant: 'ghost', size: 'sm', icon: 'pencil', data: { action: 'roles.role.edit', id: r.id } }));
                end.appendChild(button(r.archived ? 'Restore' : 'Archive', { variant: 'ghost', size: 'sm', data: { action: 'roles.role.archive', id: r.id, archive: r.archived ? 0 : 1 } }));
            }
            row.appendChild(end);
            rolesBox.appendChild(row);
        });
    }

    function roleById(id) {
        var list = state.roles ? state.roles.roles : [];
        for (var i = 0; i < list.length; i++) if (String(list[i].id) === String(id)) return list[i];
        return null;
    }

    function openRoleDialog(role) {
        roleForm.reset();
        roleForm.elements.role_id.value = role ? role.id : '';
        roleForm.elements.label.value = role ? role.label : '';
        roleForm.elements.description.value = role ? role.description : '';
        var start = roleForm.querySelector('.roles-start-field');
        start.hidden = !!role;
        var opts = state.roles.presets.map(function (p) { return ['preset:' + p.key, p.label]; });
        state.roles.roles.forEach(function (r) { if (!r.archived && !r.breakglass) opts.push(['copy:' + r.id, 'Copy of ' + r.label]); });
        setOptions(roleForm.elements.start, opts, 'preset:blank');
        document.getElementById('roles-role-dialog-title').textContent = role ? 'Edit role' : 'New role';
        HOD.dialog.open('roles-role-dialog');
    }

    HOD.actions.on('roles.new', function () {
        (state.roles ? Promise.resolve() : loadRoles()).then(function () { if (state.roles) openRoleDialog(null); });
    });
    HOD.actions.on('roles.role.edit', function (btn) { openRoleDialog(roleById(btn.getAttribute('data-id'))); });

    HOD.actions.on('roles.role.save', function (form) {
        var v = HOD.form.values(form);
        if (!v.label || v.label.trim().length < 2) {
            HOD.form.setErrors(form, { label: 'Give the role a name.' });
            return;
        }
        var data = { label: v.label, description: v.description || '' };
        var action = 'role_create';
        if (v.role_id) {
            action = 'role_update';
            data.role_id = v.role_id;
        } else {
            var start = String(v.start || 'preset:blank').split(':');
            if (start[0] === 'copy') data.copy_from = start[1]; else data.preset = start[1];
        }
        call(action, data).then(function (res) {
            HOD.dialog.close('roles-role-dialog');
            HOD.toast(res.message, 'success');
            loadRoles();
            loadMatrix();
        }).catch(function (err) {
            if (err && err.code === 'label') HOD.form.setErrors(form, { label: err.message });
        });
    });

    HOD.actions.on('roles.role.archive', function (btn) {
        var r = roleById(btn.getAttribute('data-id'));
        var archive = btn.getAttribute('data-archive') === '1';
        HOD.confirm({
            title: archive ? 'Archive this role?' : 'Restore this role?',
            message: (r ? r.label : '') + (archive ? ' leaves the matrix. History keeps it.' : ' comes back to the matrix.'),
            confirmLabel: archive ? 'Archive role' : 'Restore role', tone: archive ? 'danger' : undefined
        }).then(function (yes) {
            if (!yes) return;
            call('role_archive', { role_id: btn.getAttribute('data-id'), archive: archive ? 1 : 0 }).then(function (res) {
                HOD.toast(res.message, 'success');
                loadRoles();
                loadMatrix();
            }).catch(failed);
        });
    });

    /* -------------------------------------------------------- view as */

    HOD.actions.on('roles.viewas', function (btn) {
        call('view_as_start', { kind: btn.getAttribute('data-kind'), id: btn.getAttribute('data-id') }).then(function () {
            window.location.href = '/index.php';
        }).catch(failed);
    });

    /* -------------------------------------------------------------- people */

    var resultsCard = document.querySelector('[data-roles-people-results]');
    var resultsBox = resultsCard.querySelector('.ui-list');
    var personBox = document.querySelector('[data-roles-person]');
    var assignForm = document.getElementById('roles-assign-form');

    HOD.actions.on('roles.people.search', function (input) {
        var q = input.value.trim();
        if (q.length < 2) {
            resultsCard.hidden = true;
            return;
        }
        call('people_search', { q: q }, true).then(function (data) {
            resultsBox.textContent = '';
            resultsCard.hidden = false;
            if (!data.people.length) {
                resultsBox.appendChild(el('p', 'ui-list__item ui-meta', 'Nobody found. Try part of the name.'));
                return;
            }
            data.people.forEach(function (p) {
                var b = el('button', 'ui-list__item');
                b.type = 'button';
                b.setAttribute('data-action', 'roles.person.open');
                b.setAttribute('data-id', p.id);
                var main = el('span', 'ui-list__main');
                main.appendChild(el('span', 'ui-list__title', p.name));
                main.appendChild(el('span', 'ui-list__meta', p.status));
                b.appendChild(main);
                resultsBox.appendChild(b);
            });
        }).catch(function () {});
    });

    HOD.actions.on('roles.person.open', function (btn) {
        resultsCard.hidden = true;
        openPerson(btn.getAttribute('data-id'));
    });

    function openPerson(id) {
        loading(personBox, 'Loading…');
        return call('person', { user_id: id }).then(function (data) {
            state.person = data;
            drawPerson();
        }).catch(function () { empty(personBox, 'Not loaded', 'Search again to retry.'); });
    }

    function stateBadge(s) {
        var map = { live: ['Live', 'success'], future: ['Not started', 'info'], ended: ['Ended', 'warning'], frozen: ['Frozen', 'danger'], archived: ['Archived', ''] };
        var b = map[s] || [s, ''];
        return badge(b[0], b[1]);
    }

    function card(title, actions) {
        var c = el('section', 'ui-card');
        var h = el('div', 'ui-card__header ui-card__header--divided');
        h.appendChild(el('h2', 'ui-h3', title));
        if (actions) h.appendChild(actions);
        c.appendChild(h);
        var body = el('div', 'ui-card__body ui-card__body--flush');
        c.appendChild(body);
        return { card: c, body: body };
    }

    function drawPerson() {
        var p = state.person;
        personBox.textContent = '';
        var wrap = el('div', 'ui-stack');

        var top = el('div', 'ui-toolbar');
        var who = el('div', 'ui-toolbar__grow');
        who.appendChild(el('h2', 'ui-h2', p.user.name));
        who.appendChild(el('p', 'ui-meta', [p.user.status, p.breakglass ? 'Super Admin: everything' : ''].filter(Boolean).join(' · ')));
        top.appendChild(who);
        var tools = el('div', 'ui-toolbar__end');
        if (p.user.id !== boot.me) tools.appendChild(button('View as', { icon: 'eye', data: { action: 'roles.viewas', kind: 'person', id: p.user.id } }));
        if (boot.canManage) tools.appendChild(button('Give role', { variant: 'primary', icon: 'user-round-plus', data: { action: 'roles.assign.open' } }));
        top.appendChild(tools);
        wrap.appendChild(top);

        // Roles held.
        var roles = card('Roles');
        var list = el('div', 'ui-list');
        if (!p.roles.length) list.appendChild(el('p', 'ui-list__item ui-meta', 'No roles. Seats below still give access.'));
        p.roles.forEach(function (r) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            var t = el('p', 'ui-list__title', r.label + (r.custom_title ? ' · ' + r.custom_title : ''));
            t.appendChild(document.createTextNode(' '));
            t.appendChild(stateBadge(r.state));
            main.appendChild(t);
            var when = r.breakglass ? 'Never ends' : [r.starts_on ? 'from ' + dateText(r.starts_on) : '', r.ends_on ? 'until ' + dateText(r.ends_on) : 'no end date'].filter(Boolean).join(' ');
            main.appendChild(el('p', 'ui-list__meta', when));
            row.appendChild(main);
            if (boot.canManage) {
                var end = el('div', 'ui-list__end');
                end.appendChild(button('Dates', { variant: 'ghost', size: 'sm', icon: 'calendar', data: { action: 'roles.assign.dates', role: r.role_id } }));
                if (r.ends_on) end.appendChild(button('Renew', { variant: 'ghost', size: 'sm', icon: 'refresh-cw', data: { action: 'roles.role.renew', role: r.role_id } }));
                end.appendChild(button('Remove', { variant: 'danger-ghost', size: 'sm', icon: 'x', data: { action: 'roles.assign.remove', role: r.role_id } }));
                row.appendChild(end);
            }
            list.appendChild(row);
        });
        roles.body.appendChild(list);
        wrap.appendChild(roles.card);

        // Seats (from the Departments roster).
        var seats = card('Seats');
        var slist = el('div', 'ui-list');
        if (!p.seats.length) slist.appendChild(el('p', 'ui-list__item ui-meta', 'No seats this ministry year.'));
        p.seats.forEach(function (s) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('p', 'ui-list__title', s.seat + ', ' + s.department));
            main.appendChild(el('p', 'ui-list__meta', s.ends_on ? 'until ' + dateText(s.ends_on) : 'no end date'));
            row.appendChild(main);
            if (s.can_renew) {
                var end = el('div', 'ui-list__end');
                end.appendChild(button('Renew', { variant: 'ghost', size: 'sm', icon: 'refresh-cw', data: { action: 'roles.seat.renew', record: s.record_id } }));
                row.appendChild(end);
            }
            slist.appendChild(row);
        });
        seats.body.appendChild(slist);
        wrap.appendChild(seats.card);

        // Effective access, with the reason.
        var access = card('Access');
        if (!p.access.length) {
            access.body.appendChild(el('p', 'ui-card__body ui-meta', 'Nothing beyond their own pages.'));
        } else {
            var tw = el('div', 'ui-table-wrap');
            var table = el('table', 'ui-table ui-table--compact ui-table--stack');
            var thead = el('thead');
            var hr = el('tr');
            ['Module', 'Level', 'Reaches', 'Because of'].forEach(function (w) { var th = el('th', '', w); th.scope = 'col'; hr.appendChild(th); });
            thead.appendChild(hr);
            table.appendChild(thead);
            var tb = el('tbody');
            p.access.forEach(function (a) {
                var tr = el('tr');
                var lvl = a.level ? LEVELS[LEVEL_KEYS.indexOf(a.level)] : (p.breakglass ? 'Everything' : '—');
                if (a.extras.length) lvl += ' + ' + a.extras.join(', ');
                [[a.label, 'Module'], [lvl, 'Level'], [a.scope || (p.breakglass ? 'Church' : ''), 'Reaches'],
                    [a.why.length ? a.why.join('; ') : (p.breakglass ? 'Super Admin' : ''), 'Because of']].forEach(function (cell, i) {
                    var td = el(i === 0 ? 'th' : 'td', i === 0 ? 'ui-table__primary' : '', cell[0]);
                    if (i === 0) td.scope = 'row';
                    td.setAttribute('data-label', cell[1]);
                    tr.appendChild(td);
                });
                tb.appendChild(tr);
            });
            table.appendChild(tb);
            tw.appendChild(table);
            access.body.appendChild(tw);
        }
        wrap.appendChild(access.card);
        personBox.appendChild(wrap);
    }

    function openAssign(mode, roleRow) {
        var p = state.person;
        assignForm.reset();
        assignForm.elements.user_id.value = p.user.id;
        assignForm.elements.mode.value = mode;
        var roleField = assignForm.elements.role_id.closest('.ui-field');
        roleField.hidden = mode !== 'add';
        if (mode === 'add') {
            var held = p.roles.map(function (r) { return r.role_id; });
            setOptions(assignForm.elements.role_id, state.roles.roles.filter(function (r) {
                return !r.archived && held.indexOf(r.id) === -1;
            }).map(function (r) { return [r.id, r.label]; }));
        } else {
            setOptions(assignForm.elements.role_id, [[roleRow.role_id, roleRow.label]], roleRow.role_id);
            assignForm.elements.starts_on.value = roleRow.starts_on || '';
            assignForm.elements.ends_on.value = roleRow.ends_on || '';
            assignForm.elements.custom_title.value = roleRow.custom_title || '';
        }
        document.getElementById('roles-assign-dialog-title').textContent = mode === 'add' ? 'Give a role' : 'Role dates';
        HOD.dialog.open('roles-assign-dialog');
    }

    function personRole(id) {
        var list = state.person ? state.person.roles : [];
        for (var i = 0; i < list.length; i++) if (String(list[i].role_id) === String(id)) return list[i];
        return null;
    }

    HOD.actions.on('roles.assign.open', function () {
        (state.roles ? Promise.resolve() : loadRoles()).then(function () { if (state.roles) openAssign('add'); });
    });
    HOD.actions.on('roles.assign.dates', function (btn) { openAssign('dates', personRole(btn.getAttribute('data-role'))); });

    HOD.actions.on('roles.assign.save', function (form) {
        var v = HOD.form.values(form);
        var action = v.mode === 'add' ? 'user_role_add' : 'user_role_dates';
        call(action, v).then(function (res) {
            HOD.dialog.close('roles-assign-dialog');
            HOD.toast(res.message, 'success');
            openPerson(v.user_id);
        }).catch(failed);
    });

    HOD.actions.on('roles.assign.remove', function (btn) {
        var r = personRole(btn.getAttribute('data-role'));
        if (!r) return;
        HOD.confirm({
            title: 'Remove this role?',
            message: state.person.user.name + ' loses ' + r.label + ' now and is told.',
            confirmLabel: 'Remove role', tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            call('user_role_remove', { user_id: state.person.user.id, role_id: r.role_id }).then(function (res) {
                HOD.toast(res.message, 'success');
                openPerson(state.person.user.id);
            }).catch(failed);
        });
    });

    HOD.actions.on('roles.role.renew', function (btn) {
        HOD.busy(btn, true);
        call('user_role_renew', { user_id: state.person.user.id, role_id: btn.getAttribute('data-role') }).then(function (res) {
            HOD.toast(res.message, 'success');
            openPerson(state.person.user.id);
        }).catch(failed).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('roles.seat.renew', function (btn) {
        HOD.busy(btn, true);
        call('seat_renew', { record_id: btn.getAttribute('data-record') }).then(function (res) {
            HOD.toast(res.message, 'success');
            if (state.person) openPerson(state.person.user.id);
            if (state.seats) loadSeats();
        }).catch(failed).finally(function () { HOD.busy(btn, false); });
    });

    /* ------------------------------------------------- end dates (switch) */

    HOD.actions.on('roles.expiry.review', function () {
        var list = document.querySelector('[data-ui-tabs]');
        if (list && HOD.tabs) HOD.tabs.select(list, 'seats');
        document.dispatchEvent(new CustomEvent('ui:tab', { detail: { tab: 'seats' } }));
    });

    HOD.actions.on('roles.expiry.on', function (btn) {
        HOD.confirm({
            title: 'Enforce end dates?',
            message: 'Seats then end with their ministry year unless renewed. Holders are told 14 days before.',
            confirmLabel: 'Enforce end dates'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call('expiry_on').then(function (res) {
                HOD.toast(res.message, 'success');
                window.location.reload();
            }).catch(failed).finally(function () { HOD.busy(btn, false); });
        });
    });

    HOD.actions.on('roles.seat.end', function (btn) {
        HOD.confirm({
            title: 'End this seat?',
            message: 'They come off the roster and are told. Add them back in Departments any time.',
            confirmLabel: 'End seat', tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            call('seat_end', { record_id: btn.getAttribute('data-record') }).then(function (res) {
                HOD.toast(res.message, 'success');
                loadSeats();
            }).catch(failed);
        });
    });

    HOD.actions.on('roles.past.renew', function (btn) {
        HOD.busy(btn, true);
        call('user_role_renew', { user_id: btn.getAttribute('data-user'), role_id: btn.getAttribute('data-role') }).then(function (res) {
            HOD.toast(res.message, 'success');
            loadSeats();
        }).catch(failed).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('roles.past.remove', function (btn) {
        HOD.confirm({ title: 'Remove this role?', message: 'They lose it now and are told.', confirmLabel: 'Remove role', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                call('user_role_remove', { user_id: btn.getAttribute('data-user'), role_id: btn.getAttribute('data-role') }).then(function (res) {
                    HOD.toast(res.message, 'success');
                    loadSeats();
                }).catch(failed);
            });
    });

    /* --------------------------------------------------------------- seats */

    var seatsBox = document.querySelector('[data-roles-seats]');
    var seatForm = document.getElementById('roles-seat-form');
    var ownerForm = document.getElementById('roles-owner-form');

    function loadSeats() {
        loading(seatsBox, 'Loading seats…');
        return call('seats').then(function (data) {
            state.seats = data;
            drawSeats();
        }).catch(function () { empty(seatsBox, 'Seats not loaded', 'Reload the page to try again.'); });
    }

    function drawSeats() {
        var s = state.seats;
        seatsBox.textContent = '';
        var wrap = el('div', 'ui-stack');

        if (s.year && s.year.seats_ending > 0 && s.year.days_left <= 45) {
            var ban = el('div', 'ui-banner ui-banner--warning');
            ban.setAttribute('role', 'status');
            ban.appendChild(icon('triangle-alert'));
            var bb = el('div', 'ui-banner__body');
            bb.appendChild(el('p', 'ui-banner__title', plural(s.year.seats_ending, 'seat') + ' end on ' + dateText(s.year.end_date)));
            bb.appendChild(el('p', 'ui-banner__text', 'Roll over the ministry year to carry them on.'));
            if (s.can_roll) {
                var acts = el('div', 'ui-banner__actions');
                var a = el('a', 'ui-btn ui-btn--primary ui-btn--sm', 'Roll over year');
                a.href = '/modules/departments/index.php#rollover';
                acts.appendChild(a);
                bb.appendChild(acts);
            }
            ban.appendChild(bb);
            wrap.appendChild(ban);
        }

        // End dates off: what is past its end, and the switch (refused while any remain).
        if (!s.expiry_enforced) {
            var past = card(s.blockers.length ? 'Past their end date' : 'End dates are off',
                boot.canManage ? button('Enforce end dates', {
                    variant: 'primary', size: 'sm', data: { action: 'roles.expiry.on' }
                }) : null);
            if (boot.canManage && s.blockers.length) {
                var sw = past.card.querySelector('[data-action="roles.expiry.on"]');
                sw.disabled = true;
                sw.setAttribute('aria-describedby', 'roles-expiry-why');
            }
            var pl = el('div', 'ui-list');
            if (!s.blockers.length) {
                pl.appendChild(el('p', 'ui-list__item ui-meta', 'Nothing is past its end date. Switching on ends nobody\'s access.'));
            } else {
                pl.appendChild(el('p', 'ui-list__item ui-meta', 'Renew or end each one; then switch end dates on.')).id = 'roles-expiry-why';
            }
            s.blockers.forEach(function (b) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-list__title', b.name));
                main.appendChild(el('p', 'ui-list__meta', b.what + ' · ended ' + dateText(b.ends_on)));
                row.appendChild(main);
                var end = el('div', 'ui-list__end');
                if (b.kind === 'seat') {
                    end.appendChild(button('Renew', { variant: 'ghost', size: 'sm', icon: 'refresh-cw', data: { action: 'roles.seat.renew', record: b.id } }));
                    end.appendChild(button('End seat', { variant: 'danger-ghost', size: 'sm', icon: 'x', data: { action: 'roles.seat.end', record: b.id } }));
                } else if (boot.canManage) {
                    end.appendChild(button('Renew', { variant: 'ghost', size: 'sm', icon: 'refresh-cw', data: { action: 'roles.past.renew', user: b.user_id, role: b.id } }));
                    end.appendChild(button('Remove', { variant: 'danger-ghost', size: 'sm', icon: 'x', data: { action: 'roles.past.remove', user: b.user_id, role: b.id } }));
                }
                row.appendChild(end);
                pl.appendChild(row);
            });
            past.body.appendChild(pl);
            wrap.appendChild(past.card);
        }

        // Seat types.
        var types = card('Seat types');
        var tw = el('div', 'ui-table-wrap');
        var table = el('table', 'ui-table ui-table--compact ui-table--stack');
        var hr = el('tr');
        ['Seat', 'Rank', 'Most per department', 'Covers sub-units', 'May appoint', 'People', ''].forEach(function (w) {
            var th = el('th', '', w); th.scope = 'col'; hr.appendChild(th);
        });
        var thead = el('thead');
        thead.appendChild(hr);
        table.appendChild(thead);
        var tb = el('tbody');
        s.types.forEach(function (t) {
            if (t.archived) return;
            var tr = el('tr');
            [[t.label, 'Seat'], [t.rank, 'Rank'], [t.max_holders || 'No limit', 'Most per department'],
                [t.covers_sub_units ? 'Yes' : 'No', 'Covers sub-units'], [t.can_appoint ? 'Yes' : 'No', 'May appoint'],
                [HOD.format.number(t.people), 'People']].forEach(function (c, i) {
                var td = el(i === 0 ? 'th' : 'td', i === 0 ? 'ui-table__primary' : (i === 1 || i === 5 ? 'is-num' : ''), c[0]);
                if (i === 0) td.scope = 'row';
                td.setAttribute('data-label', c[1]);
                tr.appendChild(td);
            });
            var act = el('td', 'is-actions');
            if (boot.canManage) act.appendChild(button('Edit', { variant: 'ghost', size: 'sm', icon: 'pencil', data: { action: 'roles.seat.edit', key: t.key } }));
            tr.appendChild(act);
            tb.appendChild(tr);
        });
        table.appendChild(tb);
        tw.appendChild(table);
        types.body.appendChild(tw);
        wrap.appendChild(types.card);

        // Seats in use per department.
        var used = card('Seats per department');
        var uw = el('div', 'ui-table-wrap');
        var ut = el('table', 'ui-table ui-table--compact');
        var uhr = el('tr');
        var c0 = el('th', '', 'Department'); c0.scope = 'col'; uhr.appendChild(c0);
        var live = s.types.filter(function (t) { return !t.archived; });
        live.forEach(function (t) { var th = el('th', '', t.label); th.scope = 'col'; uhr.appendChild(th); });
        var uhead = el('thead'); uhead.appendChild(uhr); ut.appendChild(uhead);
        var ub = el('tbody');
        s.departments.forEach(function (d) {
            var tr = el('tr');
            var th = el('th', '', d.name); th.scope = 'row'; tr.appendChild(th);
            live.forEach(function (t) {
                var cell = d.seats[t.key];
                var td = el('td');
                if (boot.canManage) {
                    var lab = el('label', 'ui-check');
                    var cb = el('input');
                    cb.type = 'checkbox';
                    cb.checked = cell.enabled;
                    cb.setAttribute('data-action-change', 'roles.deptseat');
                    cb.setAttribute('data-dept', d.id);
                    cb.setAttribute('data-key', t.key);
                    cb.setAttribute('aria-label', d.name + ' uses ' + t.label);
                    lab.appendChild(cb);
                    lab.appendChild(el('span', 'ui-meta', String(cell.people)));
                    td.appendChild(lab);
                } else {
                    td.appendChild(el('span', cell.enabled ? '' : 'ui-meta', cell.enabled ? String(cell.people) : 'Off'));
                }
                tr.appendChild(td);
            });
            ub.appendChild(tr);
        });
        ut.appendChild(ub);
        uw.appendChild(ut);
        used.body.appendChild(uw);
        wrap.appendChild(used.card);

        // Module owners.
        var owners = card('Module owners');
        var ol = el('div', 'ui-list');
        var names = {};
        s.departments.forEach(function (d) { names[d.id] = d.name; });
        s.modules.forEach(function (m) {
            if (m.partitioned) return;   // partitioned modules apply in every department
            var ids = s.owners[m.key] || [];
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('p', 'ui-list__title', m.label));
            main.appendChild(el('p', 'ui-list__meta', ids.length ? ids.map(function (id) { return id === 0 ? 'Every department' : (names[id] || '#' + id); }).join(', ') : 'No department'));
            row.appendChild(main);
            if (boot.canManage) {
                var end = el('div', 'ui-list__end');
                end.appendChild(button('Edit', { variant: 'ghost', size: 'sm', icon: 'pencil', data: { action: 'roles.owner.edit', module: m.key } }));
                row.appendChild(end);
            }
            ol.appendChild(row);
        });
        owners.body.appendChild(ol);
        wrap.appendChild(owners.card);
        seatsBox.appendChild(wrap);
    }

    function seatType(key) {
        var list = state.seats ? state.seats.types : [];
        for (var i = 0; i < list.length; i++) if (list[i].key === key) return list[i];
        return null;
    }

    HOD.actions.on('roles.seat.edit', function (btn) {
        var t = seatType(btn.getAttribute('data-key'));
        if (!t) return;
        seatForm.reset();
        seatForm.elements.seat_key.value = t.key;
        seatForm.elements.label.value = t.label;
        seatForm.elements.seat_rank.value = t.rank;
        seatForm.elements.max_holders.value = t.max_holders || '';
        seatForm.elements.covers_sub_units.checked = t.covers_sub_units;
        seatForm.elements.can_appoint.checked = t.can_appoint;
        HOD.dialog.open('roles-seat-dialog');
    });

    HOD.actions.on('roles.seat.save', function (form) {
        var v = HOD.form.values(form);
        v.covers_sub_units = form.elements.covers_sub_units.checked ? 1 : 0;
        v.can_appoint = form.elements.can_appoint.checked ? 1 : 0;
        call('seat_type_set', v).then(function (res) {
            HOD.dialog.close('roles-seat-dialog');
            HOD.toast(res.message, 'success');
            loadSeats();
            loadMatrix();
        }).catch(failed);
    });

    HOD.actions.on('roles.deptseat', function (cb) {
        cb.disabled = true;
        call('dept_seat_set', { department_id: cb.getAttribute('data-dept'), seat_key: cb.getAttribute('data-key'), enabled: cb.checked ? 1 : 0 })
            .then(function (res) { HOD.toast(res.message, 'success'); })
            .catch(function () { cb.checked = !cb.checked; })
            .finally(function () { cb.disabled = false; });
    });

    HOD.actions.on('roles.owner.edit', function (btn) {
        var key = btn.getAttribute('data-module');
        var s = state.seats;
        var ids = (s.owners[key] || []).map(String);
        var mod = null;
        s.modules.forEach(function (m) { if (m.key === key) mod = m; });
        ownerForm.elements.module.value = key;
        ownerForm.querySelector('[data-owner-where]').textContent = mod ? mod.label : key;
        var list = ownerForm.querySelector('[data-owner-list]');
        list.textContent = '';
        [{ id: 0, name: 'Every department' }].concat(s.departments).forEach(function (d) {
            var lab = el('label', 'ui-check');
            var cb = el('input');
            cb.type = 'checkbox';
            cb.name = 'dept[]';
            cb.value = d.id;
            cb.checked = ids.indexOf(String(d.id)) !== -1;
            lab.appendChild(cb);
            lab.appendChild(el('span', '', d.name));
            list.appendChild(lab);
        });
        HOD.dialog.open('roles-owner-dialog');
    });

    HOD.actions.on('roles.owner.save', function (form) {
        var key = form.elements.module.value;
        var had = (state.seats.owners[key] || []).map(String);
        var now = [];
        form.querySelectorAll('input[name="dept[]"]:checked').forEach(function (cb) { now.push(cb.value); });
        var calls = [];
        now.forEach(function (id) { if (had.indexOf(id) === -1) calls.push({ module: key, department_id: id, owns: 1 }); });
        had.forEach(function (id) { if (now.indexOf(id) === -1) calls.push({ module: key, department_id: id, owns: 0 }); });
        calls.reduce(function (p, c) { return p.then(function () { return call('owner_set', c); }); }, Promise.resolve())
            .then(function () {
                HOD.dialog.close('roles-owner-dialog');
                HOD.toast('Owners saved.', 'success');
                loadSeats();
            }).catch(function () { loadSeats(); });
    });

    /* ------------------------------------------------------------- history */

    var historyBox = document.querySelector('[data-roles-history]');
    var historyMore = document.querySelector('[data-roles-history-more]');
    var historyModule = document.getElementById('roles-history-module');
    var TYPE_WORDS = {
        'grant.set': 'Matrix cell', 'role.create': 'Role created', 'role.rename': 'Role renamed', 'role.archive': 'Role archived',
        'user_role.add': 'Role given', 'user_role.remove': 'Role removed', 'user_role.dates': 'Role dates', 'role.expire': 'Role ended',
        'seat.renew': 'Seat renewed', 'seat.expire': 'Seat ended', 'seat_type.set': 'Seat type', 'dept_seat.set': 'Department seats',
        'owner.set': 'Module owner', 'view_as.start': 'View as', 'view_as.stop': 'Stopped view as'
    };

    function fillModuleFilter(mods) {
        if (historyModule.options.length > 1) return;
        mods.forEach(function (m) { var o = el('option', '', m.label); o.value = m.key; historyModule.appendChild(o); });
    }

    function diffText(before, after) {
        if (!before && !after) return '';
        var keys = {};
        Object.keys(before || {}).concat(Object.keys(after || {})).forEach(function (k) { keys[k] = true; });
        var parts = [];
        Object.keys(keys).forEach(function (k) {
            var b = before ? before[k] : undefined;
            var a = after ? after[k] : undefined;
            if (JSON.stringify(b) === JSON.stringify(a)) return;
            var fmt = function (v) {
                if (v === undefined || v === null || v === '') return '—';
                if (k === 'level') return LEVELS[v] || v;
                if (typeof v === 'boolean') return v ? 'yes' : 'no';
                return String(v);
            };
            parts.push(k.replace(/_/g, ' ') + ': ' + fmt(b) + ' → ' + fmt(a));
        });
        return parts.join(' · ');
    }

    function loadHistory(reset) {
        if (reset) {
            state.history.offset = 0;
            loading(historyBox, 'Loading history…');
        }
        var data = Object.assign({ offset: state.history.offset }, state.history.filters);
        return call('history', data).then(function (res) {
            if (reset) historyBox.textContent = '';
            if (reset && !res.rows.length) {
                empty(historyBox, 'No changes found', 'Widen the dates or pick every change.');
            }
            res.rows.forEach(function (h) {
                var row = el('div', 'ui-list__item');
                var main = el('div', 'ui-list__main');
                var what = [TYPE_WORDS[h.type] || h.type, h.subject, h.role, h.seat, h.department, h.module].filter(Boolean).join(' · ');
                main.appendChild(el('p', 'ui-list__title', what));
                var d = diffText(h.before, h.after);
                if (d) main.appendChild(el('p', 'ui-list__meta', d));
                main.appendChild(el('p', 'ui-list__meta', h.at.slice(0, 16) + ' · ' + h.actor + (h.reason ? ' · ' + h.reason : '')));
                row.appendChild(main);
                historyBox.appendChild(row);
            });
            state.history.offset += res.rows.length;
            historyMore.hidden = !res.more;
        }).catch(function () { if (reset) empty(historyBox, 'History not loaded', 'Reload the page to try again.'); });
    }

    HOD.actions.on('roles.history.filter', function (form) {
        var v = HOD.form.values(form);
        state.history.filters = { type: v.type || '', module: v.module || '', from: v.from || '', to: v.to || '' };
        loadHistory(true);
    });
    HOD.actions.on('roles.history.more', function () { loadHistory(false); });

    /* ---------------------------------------------------------------- tabs */

    var loaded = { matrix: true };
    document.addEventListener('ui:tab', function (e) {
        var tab = e.detail && e.detail.tab;
        if (!tab || loaded[tab]) return;
        loaded[tab] = true;
        if (tab === 'roles') loadRoles();
        if (tab === 'seats') loadSeats();
        if (tab === 'history') loadHistory(true);
        if (tab === 'people' && !state.roles) loadRoles();
    });

    loadMatrix();
    var hash = (window.location.hash.match(/tab=(\w+)/) || [])[1];
    if (hash && hash !== 'matrix') {
        document.dispatchEvent(new CustomEvent('ui:tab', { detail: { tab: hash } }));
    }
})();
