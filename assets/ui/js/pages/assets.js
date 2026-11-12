// /assets/ui/js/pages/assets.js
// Asset Management (modules/assets/index.php): stats, the inventory with
// search and a status filter, the activity log, and the asset and log
// dialogs. Data from /api/assets_api.php through HOD.api. Names, places and
// notes are typed by people, so they go into the page as TEXT (DOM nodes);
// markup strings hold only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('assets-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { assets: [], logs: [], departments: [], users: [] };
    var STATUS = {
        Available: ['Available', 'success'], In_Use: ['In use', 'info'], Maintenance: ['In repair', 'warning'],
        Lost: ['Missing', 'danger'], Retired: ['Retired', '']
    };
    var CONDITION = { Excellent: 'success', Good: 'info', Fair: 'warning', Poor: 'danger' };
    var ACTION = {
        'Check-Out': ['Checked out', 'info'], 'Check-In': ['Checked in', 'success'],
        Maintenance: ['Repair', 'warning'], Lost: ['Missing', 'danger']
    };

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

    function stack(main, meta) {
        var w = el('div');
        w.appendChild(el('div', '', main));
        if (meta) w.appendChild(el('div', 'ui-meta', meta));
        return w;
    }

    function emptyRow(body, cols, title, text) {
        var tr = el('tr');
        var td = el('td');
        td.colSpan = cols;
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(icon('package'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        td.appendChild(box);
        tr.appendChild(td);
        body.appendChild(tr);
    }

    function find(id) {
        return data.assets.filter(function (a) { return String(a.id) === String(id); })[0] || null;
    }

    function fullName(o) {
        return ((o.first_name || '') + ' ' + (o.last_name || '')).trim();
    }

    /* ---------------------------------------------------------- inventory */

    function renderInventory() {
        var body = q('[data-ast-body]');
        var term = (q('#ast-search').value || '').trim().toLowerCase();
        var status = q('#ast-status').value;
        var shown = data.assets.filter(function (a) {
            if (status && a.current_status !== status) return false;
            if (!term) return true;
            return [a.name, a.category, a.department_name, a.storage_location].join(' ').toLowerCase().indexOf(term) !== -1;
        });
        body.textContent = '';
        if (!shown.length) {
            emptyRow(body, 5, data.assets.length ? 'No asset matches' : 'No assets yet',
                !data.assets.length && boot.canCreate ? 'Use Add asset to register the first.' : '');
            return;
        }
        shown.forEach(function (a) {
            var tr = el('tr');
            var name = cell('Asset');
            name.appendChild(stack(a.name, (boot.categories[a.category] || a.category || 'General') + ' · ' + HOD.format.number(a.quantity) + ' pcs'));
            tr.appendChild(name);

            var dept = cell('Department');
            dept.appendChild(stack(a.department_name || 'No department', a.storage_location || ''));
            tr.appendChild(dept);

            var st = STATUS[a.current_status] || STATUS.Available;
            var s = cell('Status');
            s.appendChild(badge(st[0], st[1]));
            tr.appendChild(s);

            var c = cell('Condition');
            c.appendChild(badge(a.condition_rating || '—', CONDITION[a.condition_rating]));
            tr.appendChild(c);

            var act = cell('', 'is-actions');
            if (boot.canEdit) {
                var group = el('div', 'ui-toolbar justify-end');
                group.appendChild(button('Log', { icon: 'clipboard-list', data: { action: 'ast.log', id: a.id } }));
                group.appendChild(button('Edit', { variant: 'ghost', icon: 'pencil', iconOnly: true, data: { action: 'ast.edit', id: a.id } }));
                act.appendChild(group);
            }
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function renderLogs() {
        var body = q('[data-ast-logs]');
        body.textContent = '';
        if (!data.logs.length) {
            emptyRow(body, 5, 'No activity yet', '');
            return;
        }
        data.logs.forEach(function (l) {
            var tr = el('tr');
            var when = cell('When');
            var d = l.action_date ? new Date(String(l.action_date).replace(' ', 'T')) : null;
            when.textContent = d && !isNaN(d) ? HOD.format.date(d) + ' ' + HOD.format.time(d) : '—';
            tr.appendChild(when);

            var asset = cell('Asset');
            asset.textContent = l.asset_name || '—';
            tr.appendChild(asset);

            var a = ACTION[l.action] || [l.action, ''];
            var act = cell('Action');
            act.appendChild(badge(a[0], a[1]));
            tr.appendChild(act);

            var who = cell('Person');
            who.textContent = fullName(l) || '—';
            tr.appendChild(who);

            var notes = cell('Notes');
            notes.appendChild(el('span', 'break-words', l.notes || '—'));
            tr.appendChild(notes);
            body.appendChild(tr);
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.assets = res.assets || [];
            data.logs = res.logs || [];
            data.departments = res.departments || [];
            data.users = res.users || [];
            q('[data-ast-error]').hidden = true;
            var st = res.stats || {};
            ['total', 'in_use', 'maintenance', 'lost'].forEach(function (k) {
                var v = q('[data-stat="' + k + '"] .ui-stat__value');
                if (v) v.textContent = HOD.format.number(st[k]);
            });
            renderInventory();
            renderLogs();
        }).catch(function () {
            q('[data-ast-error]').hidden = false;
        });
    }

    HOD.actions.on('ast.filter', renderInventory);

    /* -------------------------------------------------------- asset dialog */

    function fillSelect(select, rows, label, first) {
        select.textContent = '';
        select.appendChild(new Option(first, ''));
        rows.forEach(function (r) { select.appendChild(new Option(label(r), r.id)); });
        HOD.combobox.refresh(select);
    }

    function openAsset(a) {
        var form = q('#ast-form');
        form.reset();
        HOD.form.clearErrors(form);
        fillSelect(form.elements.managing_department_id, data.departments, function (d) { return d.name; }, 'None');
        var cat = form.elements.category;
        // An older asset may carry a category from before the list: show it.
        Array.prototype.forEach.call(cat.querySelectorAll('[data-extra]'), function (o) { o.remove(); });
        if (a && a.category && !boot.categories[a.category]) {
            var extra = new Option(a.category, a.category);
            extra.setAttribute('data-extra', '');
            cat.appendChild(extra);
        }
        form.elements.asset_id.value = a ? a.id : '';
        form.elements.name.value = a ? a.name : '';
        cat.value = a ? (a.category || 'General') : 'General';
        form.elements.quantity.value = a ? a.quantity : 1;
        form.elements.managing_department_id.value = a && a.managing_department_id ? String(a.managing_department_id) : '';
        HOD.combobox.refresh(form.elements.managing_department_id);
        form.elements.storage_location.value = a ? (a.storage_location || '') : '';
        form.elements.condition_rating.value = a ? (a.condition_rating || 'Good') : 'Good';
        q('#ast-dialog-title').textContent = a ? 'Edit asset' : 'Add asset';
        HOD.dialog.open('ast-dialog');
    }

    HOD.actions.on('ast.new', function () { openAsset(null); });
    HOD.actions.on('ast.edit', function (btn) {
        var a = find(btn.getAttribute('data-id'));
        if (a) openAsset(a);
    });

    function fieldError(form) {
        return function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        };
    }

    HOD.actions.on('ast.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!(v.name || '').trim()) errors.name = 'Give it a name.';
        if (!(parseInt(v.quantity, 10) >= 1)) errors.quantity = 'At least 1.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_asset', data: {
            asset_id: v.asset_id, name: v.name.trim(), category: v.category, quantity: v.quantity,
            managing_department_id: v.managing_department_id, storage_location: (v.storage_location || '').trim(),
            condition_rating: v.condition_rating
        } }).then(function () {
            HOD.dialog.close('ast-dialog');
            HOD.toast(v.asset_id ? 'Asset saved.' : 'Asset added.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    /* ---------------------------------------------------------- log dialog */

    HOD.actions.on('ast.log', function (btn) {
        var a = find(btn.getAttribute('data-id'));
        if (!a) return;
        var form = q('#ast-log-form');
        form.reset();
        HOD.form.clearErrors(form);
        form.elements.asset_id.value = a.id;
        q('[data-ast-log-name]', form).textContent = a.name;
        fillSelect(form.elements.user_id, data.users, fullName, 'Me');
        HOD.dialog.open('ast-log-dialog');
    });

    HOD.actions.on('ast.log.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!v.log_action) { HOD.form.setErrors(form, { log_action: 'Choose what happened.' }); return; }

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'log_action', data: {
            asset_id: v.asset_id, log_action: v.log_action, user_id: v.user_id, notes: (v.notes || '').trim()
        } }).then(function () {
            HOD.dialog.close('ast-log-dialog');
            HOD.toast('Logged.', { tone: 'success' });
            return load();
        }).catch(fieldError(form)).then(function () { HOD.busy(submit, false); });
    });

    load();
})();
