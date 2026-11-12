// /assets/ui/js/pages/announcements.js
// Announcements (modules/announcements/index.php): the register, the notice
// dialog, approve / archive / delete. Data from /api/announcements_api.php
// through HOD.api. Titles, messages and event names are typed by people, so
// they go into the page as TEXT (DOM nodes); markup strings hold only our own
// words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('announcements-boot');
    if (!boot) return;
    var API = boot.api;

    var rows = [];
    var events = [];
    var TONES = { Pending: 'warning', Approved: 'success', Archived: '' };

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

    function find(id) {
        return rows.filter(function (r) { return String(r.id) === String(id); })[0] || null;
    }

    function canEdit(a) {
        return boot.canApprove || (Number(a.submitted_by) === Number(boot.userId) && a.status === 'Pending');
    }

    function eventLabel(e) {
        var d = e.event_date ? HOD.format.date(e.event_date) : '';
        return (e.title || 'Event') + (d ? ' · ' + d : '');
    }

    /* --------------------------------------------------------------- table */

    function render() {
        var body = q('[data-ann-body]');
        var term = (q('#ann-search').value || '').trim().toLowerCase();
        var status = q('#ann-status').value;
        var shown = rows.filter(function (a) {
            if (status && a.status !== status) return false;
            if (!term) return true;
            return [a.title, a.content, a.event_name].join(' ').toLowerCase().indexOf(term) !== -1;
        });
        body.textContent = '';
        if (!shown.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 4;
            var box = el('div', 'ui-empty ui-empty--compact');
            var ic = el('div', 'ui-empty__icon');
            ic.appendChild(icon('megaphone'));
            box.appendChild(ic);
            box.appendChild(el('p', 'ui-empty__title', rows.length ? 'No notice matches' : 'No notices yet'));
            if (!rows.length && boot.canCreate) box.appendChild(el('p', 'ui-empty__text', 'Use New notice to draft the first.'));
            td.appendChild(box);
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        shown.forEach(function (a) {
            var tr = el('tr');

            var ev = cell('Event');
            var evWrap = el('div');
            evWrap.appendChild(el('div', '', a.event_date ? HOD.format.date(a.event_date) : '—'));
            if (a.event_name) evWrap.appendChild(el('div', 'ui-meta', a.event_name));
            ev.appendChild(evWrap);
            tr.appendChild(ev);

            var notice = cell('Notice');
            var nWrap = el('div');
            nWrap.appendChild(el('div', 'font-semibold', a.title));
            nWrap.appendChild(el('div', 'ui-meta line-clamp-2', a.content));
            notice.appendChild(nWrap);
            tr.appendChild(notice);

            var st = cell('Status');
            st.appendChild(badge(a.status || 'Pending', TONES[a.status]));
            tr.appendChild(st);

            var act = cell('', 'is-actions');
            var group = el('div', 'ui-toolbar justify-end');
            if (boot.canApprove && a.status === 'Pending') {
                group.appendChild(button('Approve', { icon: 'check', data: { action: 'ann.status', id: a.id, status: 'Approved' } }));
            }
            if (boot.canApprove && a.status === 'Approved') {
                group.appendChild(button('Archive', { icon: 'archive', data: { action: 'ann.status', id: a.id, status: 'Archived' } }));
            }
            if (canEdit(a)) {
                group.appendChild(button('Edit', { variant: 'ghost', icon: 'pencil', iconOnly: true, data: { action: 'ann.edit', id: a.id } }));
            }
            if (boot.canDelete) {
                group.appendChild(button('Delete', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'ann.delete', id: a.id } }));
            }
            act.appendChild(group);
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_announcements', quiet: true }).then(function (res) {
            rows = res.data || [];
            q('[data-ann-error]').hidden = true;
            render();
        }).catch(function () {
            q('[data-ann-error]').hidden = false;
        });
    }

    function loadEvents() {
        return HOD.api(API, { method: 'GET', action: 'fetch_events', quiet: true }).then(function (res) {
            events = res.data || [];
        }).catch(function () { events = []; });
    }

    HOD.actions.on('ann.filter', render);

    /* -------------------------------------------------------------- dialog */

    function fillEvents(select, current) {
        select.textContent = '';
        select.appendChild(new Option('Choose…', ''));
        var list = events.slice();
        // An older notice's event may have dropped out of the list: keep it.
        if (current && current.target_event_id && !list.some(function (e) { return String(e.id) === String(current.target_event_id); })) {
            list.unshift({ id: current.target_event_id, title: current.event_name, event_date: current.event_date });
        }
        list.forEach(function (e) { select.appendChild(new Option(eventLabel(e), e.id)); });
        if (current) select.value = String(current.target_event_id || '');
    }

    function openDialog(a) {
        var form = q('#ann-form');
        form.reset();
        HOD.form.clearErrors(form);
        form.elements.id.value = a ? a.id : '';
        fillEvents(form.elements.target_event_id, a);
        form.elements.title.value = a ? a.title : '';
        form.elements.content.value = a ? a.content : '';
        q('#ann-dialog-title').textContent = a ? 'Edit notice' : 'New notice';
        HOD.dialog.open('ann-dialog');
    }

    HOD.actions.on('ann.new', function (btn) {
        HOD.busy(btn, true);
        loadEvents().then(function () {
            HOD.busy(btn, false);
            if (!events.length) {
                HOD.toast('No event is coming up to attach it to.', { tone: 'warning' });
                return;
            }
            openDialog(null);
        });
    });

    HOD.actions.on('ann.edit', function (btn) {
        var a = find(btn.getAttribute('data-id'));
        if (!a) return;
        HOD.busy(btn, true);
        loadEvents().then(function () { HOD.busy(btn, false); openDialog(a); });
    });

    HOD.actions.on('ann.save', function (form, event) {
        var v = HOD.form.values(form);
        var errors = {};
        if (!v.target_event_id) errors.target_event_id = 'Choose the event.';
        if (!(v.title || '').trim()) errors.title = 'Give it a title.';
        if (!(v.content || '').trim()) errors.content = 'Write the message.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_announcement', data: {
            id: v.id, target_event_id: v.target_event_id, title: v.title.trim(), content: v.content.trim()
        } }).then(function () {
            HOD.dialog.close('ann-dialog');
            HOD.toast(v.id ? 'Notice saved.' : 'Sent for approval.', { tone: 'success' });
            return load();
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        }).then(function () { HOD.busy(submit, false); });
    });

    /* ------------------------------------------------------ status, delete */

    HOD.actions.on('ann.status', function (btn) {
        var a = find(btn.getAttribute('data-id'));
        var status = btn.getAttribute('data-status');
        if (!a) return;
        HOD.busy(btn, true);
        HOD.api(API, { action: 'update_status', data: { id: a.id, status: status } }).then(function () {
            HOD.toast(status === 'Approved' ? 'Approved.' : 'Archived.', { tone: 'success' });
            return load();
        }).catch(function () {}).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('ann.delete', function (btn) {
        var a = find(btn.getAttribute('data-id'));
        if (!a) return;
        HOD.confirm({
            title: 'Delete this notice?',
            message: a.title,
            confirmLabel: 'Delete',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_announcement', data: { id: a.id } }).then(function () {
                HOD.toast('Notice deleted.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    load();
})();
