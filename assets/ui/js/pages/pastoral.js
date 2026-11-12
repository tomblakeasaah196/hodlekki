// /assets/ui/js/pages/pastoral.js
// Pastoral Desk (modules/pastoral/index.php): the three boxes (Q&A,
// suggestions, impressions), the new-entry dialog, and status / priority for
// leadership. Data from /api/pastoral_api.php through HOD.api. Entries and
// names are typed by people, so they go into the page as TEXT (DOM nodes);
// markup strings hold only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('pastoral-boot');
    if (!boot) return;
    var API = boot.api;

    var PRIORITIES = [[1, 'Normal'], [5, 'Medium'], [10, 'High']];
    var TONES = { Pending: 'warning', New: 'warning', Answered: 'success', Reviewed: 'info', Actioned: 'success', Archived: '' };
    var TYPE_TAB = { qa: 'qa', suggestion: 'suggestions', impression: 'impressions' };
    var loaded = {};

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

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
    }

    function priorityLabel(p) {
        var hit = PRIORITIES.filter(function (x) { return x[0] === Number(p); })[0];
        return hit ? hit[1] : 'Normal';
    }

    function inlineSelect(options, value, data, label) {
        var s = el('select', 'ui-select ui-select--sm');
        s.setAttribute('aria-label', label);
        options.forEach(function (o) { s.appendChild(new Option(o[1], o[0])); });
        s.value = String(value);
        s.setAttribute('data-prev', String(value));
        Object.keys(data).forEach(function (k) { s.setAttribute('data-' + k, data[k]); });
        return s;
    }

    /* ---------------------------------------------------------------- load */

    function from(row) {
        var wrap = el('div');
        if (Number(row.is_anonymous) === 1 && !row.is_mine) {
            wrap.appendChild(el('span', 'ui-meta', 'Anonymous'));
            return wrap;
        }
        var name = ((row.first_name || '') + ' ' + (row.last_name || '')).trim() || 'Unknown';
        wrap.appendChild(el('div', '', row.is_mine ? 'You' : name));
        if (row.is_mine && Number(row.is_anonymous) === 1) wrap.appendChild(el('div', 'ui-meta', 'Sent anonymously'));
        else if (row.phone) wrap.appendChild(el('div', 'ui-meta', row.phone));
        return wrap;
    }

    function render(tab, data) {
        var body = q('[data-pas-body="' + tab + '"]');
        body.textContent = '';
        if (!data.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 5;
            var box = el('div', 'ui-empty ui-empty--compact');
            var ic = el('div', 'ui-empty__icon');
            ic.appendChild(icon('inbox'));
            box.appendChild(ic);
            box.appendChild(el('p', 'ui-empty__title', 'Nothing here yet'));
            td.appendChild(box);
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        data.forEach(function (row) {
            var tr = el('tr');

            var who = cell('From');
            who.appendChild(from(row));
            tr.appendChild(who);

            var entry = cell('Entry');
            var eWrap = el('div');
            eWrap.appendChild(el('div', 'whitespace-pre-line break-words', row.question || row.content));
            if (row.period_label || row.period_type) {
                eWrap.appendChild(el('div', 'ui-meta', [row.period_label, row.period_type].filter(Boolean).join(' · ')));
            }
            entry.appendChild(eWrap);
            tr.appendChild(entry);

            var st = cell('Status');
            if (boot.canTriage) {
                st.appendChild(inlineSelect((boot.statuses[tab] || []).map(function (s) { return [s, s]; }), row.status,
                    { 'action-change': 'pas.status', id: row.id, tab: tab }, 'Status'));
            } else {
                st.appendChild(badge(row.status || '—', TONES[row.status]));
            }
            tr.appendChild(st);

            if (boot.isLeadership) {
                var pr = cell('Priority');
                if (boot.canPriority) {
                    pr.appendChild(inlineSelect(PRIORITIES, Number(row.priority) || 1,
                        { 'action-change': 'pas.priority', id: row.id, tab: tab }, 'Priority'));
                } else {
                    pr.appendChild(badge(priorityLabel(row.priority), Number(row.priority) >= 10 ? 'danger' : ''));
                }
                tr.appendChild(pr);
            }

            var date = cell('Date', 'is-num');
            date.textContent = row.submitted_at ? HOD.format.date(row.submitted_at) : '—';
            tr.appendChild(date);
            body.appendChild(tr);
        });
    }

    function load(tab) {
        loaded[tab] = true;
        return HOD.api(API, { method: 'GET', action: 'fetch_tab_data', data: { tab: tab }, quiet: true }).then(function (res) {
            q('[data-pas-error]').hidden = true;
            render(tab, res.data || []);
        }).catch(function () {
            q('[data-pas-error]').hidden = false;
        });
    }

    document.addEventListener('ui:tab', function (e) {
        var tab = e.detail && e.detail.tab;
        if (tab && boot.statuses[tab] && !loaded[tab]) load(tab);
    });

    /* ------------------------------------------------------ status, priority */

    function update(select, field) {
        var data = { tab: select.getAttribute('data-tab'), id: select.getAttribute('data-id') };
        data[field] = select.value;
        select.disabled = true;
        HOD.api(API, { action: 'update_entry', data: data }).then(function () {
            select.setAttribute('data-prev', select.value);
            HOD.toast('Saved.', { tone: 'success' });
            if (field === 'priority') load(data.tab);
        }).catch(function () {
            select.value = select.getAttribute('data-prev');
        }).then(function () { select.disabled = false; });
    }

    HOD.actions.on('pas.status', function (select) { update(select, 'status'); });
    HOD.actions.on('pas.priority', function (select) { update(select, 'priority'); });

    /* ----------------------------------------------------------- new entry */

    function syncType(form) {
        var impression = form.elements.type.value === 'impression';
        q('[data-pas-impression]', form).hidden = !impression;
        q('[data-pas-anon]', form).hidden = impression;   // impressions are named
        if (impression) form.elements.is_anonymous.checked = false;
    }

    HOD.actions.on('pas.new', function () {
        var form = q('#pas-form');
        form.reset();
        HOD.form.clearErrors(form);
        var active = q('[role="tab"][aria-selected="true"]');
        var tab = active ? active.getAttribute('data-tab') : 'qa';
        form.elements.type.value = tab === 'suggestions' ? 'suggestion' : tab === 'impressions' ? 'impression' : 'qa';
        syncType(form);
        HOD.dialog.open('pas-dialog');
    });

    HOD.actions.on('pas.type', function () { syncType(q('#pas-form')); });

    HOD.actions.on('pas.save', function (form, event) {
        var v = HOD.form.values(form);
        HOD.form.clearErrors(form);
        if (!(v.content || '').trim()) { HOD.form.setErrors(form, { content: 'Write your message.' }); return; }

        var data = { type: v.type, content: v.content.trim() };
        if (v.type === 'impression') {
            data.period_type = v.period_type;
            data.period_label = (v.period_label || '').trim();
        } else if (form.elements.is_anonymous.checked) {
            data.is_anonymous = 1;
        }
        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'submit_entry', data: data }).then(function () {
            HOD.dialog.close('pas-dialog');
            HOD.toast('Sent. Thank you.', { tone: 'success' });
            var tab = TYPE_TAB[v.type] || 'qa';
            var tabBtn = q('[role="tab"][data-tab="' + tab + '"]');
            loaded[tab] = true;   // the click below must not load it a second time
            if (tabBtn && tabBtn.getAttribute('aria-selected') !== 'true') tabBtn.click();
            return load(tab);
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        }).then(function () { HOD.busy(submit, false); });
    });

    var hash = (window.location.hash.match(/tab=(\w+)/) || [])[1];
    load(hash && boot.statuses[hash] ? hash : 'qa');
})();
