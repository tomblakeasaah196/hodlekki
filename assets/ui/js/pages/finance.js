// /assets/ui/js/pages/finance.js
// Finance & stewardship (modules/finance/index.php): this month's figures,
// balances, the ledger and the setup lists. Data from /api/finance_api.php
// through HOD.api. Names and descriptions are typed by people, so they go into
// the page as TEXT (DOM nodes); markup strings hold only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('finance-boot');
    if (!boot) return;
    var API = boot.api;

    var data = { accounts: [], funds: [], categories: [], transactions: [] };
    var KINDS = {
        account: { list: 'accounts', name: 'account_name', title: 'account', save: 'save_account', del: 'delete_account' },
        fund: { list: 'funds', name: 'fund_name', title: 'fund', save: 'save_fund', del: 'delete_fund' },
        category: { list: 'categories', name: 'category_name', title: 'category', save: 'save_category', del: 'delete_category' }
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

    function empty(title, text) {
        var box = el('div', 'ui-empty ui-empty--compact');
        var ic = el('div', 'ui-empty__icon');
        ic.appendChild(icon('inbox'));
        box.appendChild(ic);
        box.appendChild(el('p', 'ui-empty__title', title));
        if (text) box.appendChild(el('p', 'ui-empty__text', text));
        return box;
    }

    function money(v) { return HOD.format.naira(v); }

    function isIncome(tx) { return tx.transaction_type === 'Income'; }

    function signed(tx) { return (isIncome(tx) ? '+' : '−') + money(tx.amount); }

    function what(tx) { return tx.description || tx.category_name || (isIncome(tx) ? 'Money in' : 'Money out'); }

    function find(kind, id) {
        return (data[KINDS[kind].list] || []).filter(function (r) { return String(r.id) === String(id); })[0] || null;
    }

    function setStat(key, value) {
        var v = q('[data-stat="' + key + '"] .ui-stat__value');
        if (v) v.textContent = value;
    }

    /* ---------------------------------------------------------------- load */

    function load() {
        return HOD.api(API, { method: 'GET', action: 'fetch_dashboard', quiet: true }).then(function (res) {
            data.accounts = res.accounts || [];
            data.funds = res.funds || [];
            data.categories = res.categories || [];
            data.transactions = res.transactions || [];
            q('[data-fin-error]').hidden = true;
            var a = res.analytics || {};
            setStat('income', money(a.month_income));
            setStat('expense', money(a.month_expense));
            setStat('net', money(a.net_position));
            renderBalances();
            renderLatest();
            renderLedger();
            if (boot.canEdit) Object.keys(KINDS).forEach(renderSetup);
        }).catch(function () {
            q('[data-fin-error]').hidden = false;
        });
    }

    /* ------------------------------------------------------------ overview */

    function renderBalances() {
        var box = q('[data-fin-balances]');
        box.textContent = '';
        if (!data.accounts.length) {
            box.appendChild(empty('No accounts yet', boot.canEdit ? 'Add one under Setup.' : ''));
            return;
        }
        data.accounts.forEach(function (acc) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', acc.account_name));
            main.appendChild(el('span', 'ui-list__meta', acc.account_type === 'Cash' ? 'Petty cash' : 'Bank account'));
            row.appendChild(main);
            row.appendChild(el('strong', 'tabular-nums', money(acc.current_balance)));
            box.appendChild(row);
        });
    }

    function renderLatest() {
        var box = q('[data-fin-latest]');
        box.textContent = '';
        if (!data.transactions.length) {
            box.appendChild(empty('No transactions yet', boot.canCreate ? 'Use Log transaction to add the first.' : ''));
            return;
        }
        data.transactions.slice(0, 5).forEach(function (tx) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', what(tx)));
            main.appendChild(el('span', 'ui-list__meta', HOD.format.date(tx.transaction_date) + ' · ' + (tx.account_name || '')));
            row.appendChild(main);
            row.appendChild(badge(signed(tx), isIncome(tx) ? 'success' : 'danger'));
            box.appendChild(row);
        });
    }

    /* -------------------------------------------------------------- ledger */

    function cell(label, cls) {
        var td = el('td', cls || '');
        if (label) td.setAttribute('data-label', label);
        return td;
    }

    function renderLedger() {
        var body = q('[data-fin-ledger]');
        body.textContent = '';
        if (!data.transactions.length) {
            var tr = el('tr');
            var td = el('td');
            td.colSpan = 7;
            td.appendChild(empty('No transactions yet', ''));
            tr.appendChild(td);
            body.appendChild(tr);
            return;
        }
        data.transactions.forEach(function (tx) {
            var tr = el('tr');
            var date = cell('Date');
            date.textContent = HOD.format.date(tx.transaction_date);
            tr.appendChild(date);

            var type = cell('Type');
            type.appendChild(badge(isIncome(tx) ? 'In' : 'Out', isIncome(tx) ? 'success' : 'danger'));
            tr.appendChild(type);

            var whatCell = cell('What');
            var wrap = el('div');
            wrap.appendChild(el('div', '', what(tx)));
            var tags = [tx.category_name, tx.fund_name].filter(Boolean).join(' · ');
            if (tags) wrap.appendChild(el('div', 'ui-meta', tags));
            whatCell.appendChild(wrap);
            tr.appendChild(whatCell);

            var acc = cell('Account');
            acc.textContent = tx.account_name || '—';
            tr.appendChild(acc);

            var amt = cell('Amount', 'is-num tabular-nums');
            amt.textContent = signed(tx);
            tr.appendChild(amt);

            var rec = cell('Receipt');
            if (tx.receipt_href) {
                var a = el('a', 'ui-link', 'Open');
                a.href = tx.receipt_href;
                a.target = '_blank';
                a.rel = 'noopener';
                rec.appendChild(a);
            } else {
                rec.textContent = '—';
            }
            tr.appendChild(rec);

            var act = cell('', 'is-actions');
            if (boot.canDelete) {
                act.appendChild(button('Delete', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'fin.tx.delete', id: tx.id } }));
            }
            tr.appendChild(act);
            body.appendChild(tr);
        });
    }

    /* --------------------------------------------------------------- setup */

    function renderSetup(kind) {
        var box = q('[data-fin-setup="' + kind + '"]');
        if (!box) return;
        var k = KINDS[kind];
        var rows = data[k.list] || [];
        box.textContent = '';
        if (!rows.length) {
            box.appendChild(empty('None yet', ''));
            return;
        }
        rows.forEach(function (r) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('span', 'ui-list__title', r[k.name]));
            var meta = kind === 'account' ? (r.account_type === 'Cash' ? 'Petty cash' : 'Bank account') + ' · ' + money(r.current_balance)
                : kind === 'fund' ? (r.description || '')
                : (r.type === 'Expense' ? 'Money out' : 'Money in');
            if (meta) main.appendChild(el('span', 'ui-list__meta', meta));
            row.appendChild(main);
            row.appendChild(button('Edit', { variant: 'ghost', icon: 'pencil', iconOnly: true, data: { action: 'fin.setup.edit', kind: kind, id: r.id } }));
            if (boot.canDelete) {
                row.appendChild(button('Delete', { variant: 'ghost', icon: 'trash-2', iconOnly: true, data: { action: 'fin.setup.delete', kind: kind, id: r.id } }));
            }
            box.appendChild(row);
        });
    }

    /* --------------------------------------------------------- transaction */

    function fillSelect(select, rows, labelKey, placeholder) {
        var keep = select.value;
        select.textContent = '';
        if (placeholder !== null) select.appendChild(new Option(placeholder, ''));
        rows.forEach(function (r) { select.appendChild(new Option(r[labelKey], r.id)); });
        if (keep && rows.some(function (r) { return String(r.id) === keep; })) select.value = keep;
    }

    function fillCategories(form) {
        var type = form.elements.transaction_type.value;
        fillSelect(form.elements.category_id, data.categories.filter(function (c) { return c.type === type; }), 'category_name', 'None');
    }

    HOD.actions.on('fin.tx.new', function () {
        if (!data.accounts.length || !data.funds.length) {
            HOD.toast('Add an account and a fund under Setup first.', { tone: 'warning' });
            return;
        }
        var form = q('#fin-tx-form');
        form.reset();
        HOD.form.clearErrors(form);
        fillSelect(form.elements.account_id, data.accounts, 'account_name', 'Choose…');
        fillSelect(form.elements.fund_id, data.funds, 'fund_name', 'Choose…');
        fillCategories(form);
        HOD.dialog.open('fin-tx-dialog');
    });

    HOD.actions.on('fin.tx.type', function () { fillCategories(q('#fin-tx-form')); });

    HOD.actions.on('fin.tx.save', function (form, event) {
        var amount = parseFloat(form.elements.amount.value);
        var errors = {};
        if (!(amount > 0)) errors.amount = 'Type an amount above zero.';
        if (!form.elements.account_id.value) errors.account_id = 'Choose an account.';
        if (!form.elements.fund_id.value) errors.fund_id = 'Choose a fund.';
        HOD.form.clearErrors(form);
        if (Object.keys(errors).length) { HOD.form.setErrors(form, errors); return; }

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: 'save_transaction', data: new FormData(form) }).then(function () {
            HOD.dialog.close('fin-tx-dialog');
            HOD.toast('Transaction saved.', { tone: 'success' });
            return load();
        }).catch(function (err) {
            if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
        }).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('fin.tx.delete', function (btn) {
        var id = btn.getAttribute('data-id');
        HOD.confirm({
            title: 'Delete this transaction?',
            message: 'Its amount is put back on the account balance.',
            confirmLabel: 'Delete',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: 'delete_transaction', data: { transaction_id: id } }).then(function () {
                HOD.toast('Transaction deleted.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    /* ------------------------------------------------------- setup dialog */

    function openSetup(kind, row) {
        var form = q('#fin-setup-form');
        form.reset();
        HOD.form.clearErrors(form);
        form.elements.kind.value = kind;
        form.elements.id.value = row ? row.id : '';
        form.elements.name.value = row ? row[KINDS[kind].name] : '';
        Array.prototype.forEach.call(form.querySelectorAll('[data-fin-for]'), function (s) {
            s.hidden = s.getAttribute('data-fin-for') !== kind;
        });
        if (row && kind === 'account') form.elements.account_type.value = row.account_type === 'Cash' ? 'Cash' : 'Bank';
        if (row && kind === 'fund') form.elements.description.value = row.description || '';
        if (row && kind === 'category') form.elements.type.value = row.type === 'Expense' ? 'Expense' : 'Income';
        var title = KINDS[kind].title;
        q('#fin-setup-dialog-title').textContent = (row ? 'Edit ' : 'New ') + title;
        HOD.dialog.open('fin-setup-dialog');
        form.elements.name.focus();
    }

    HOD.actions.on('fin.setup.new', function (btn) { openSetup(btn.getAttribute('data-kind'), null); });

    HOD.actions.on('fin.setup.edit', function (btn) {
        var kind = btn.getAttribute('data-kind');
        var row = find(kind, btn.getAttribute('data-id'));
        if (row) openSetup(kind, row);
    });

    HOD.actions.on('fin.setup.save', function (form, event) {
        var kind = form.elements.kind.value;
        var k = KINDS[kind];
        if (!k) return;
        var name = form.elements.name.value.trim();
        HOD.form.clearErrors(form);
        if (!name) { HOD.form.setErrors(form, { name: 'Type a name.' }); return; }

        var payload = { id: form.elements.id.value };
        payload[k.name] = name;
        if (kind === 'account') payload.account_type = form.elements.account_type.value;
        if (kind === 'fund') payload.description = form.elements.description.value.trim();
        if (kind === 'category') payload.type = form.elements.type.value;

        var submit = event && event.submitter;
        HOD.busy(submit, true);
        HOD.api(API, { action: k.save, data: payload }).then(function () {
            HOD.dialog.close('fin-setup-dialog');
            HOD.toast('Saved.', { tone: 'success' });
            return load();
        }).catch(function () {}).then(function () { HOD.busy(submit, false); });
    });

    HOD.actions.on('fin.setup.delete', function (btn) {
        var kind = btn.getAttribute('data-kind');
        var row = find(kind, btn.getAttribute('data-id'));
        if (!row) return;
        HOD.confirm({
            title: 'Delete ' + KINDS[kind].title + '?',
            message: row[KINDS[kind].name],
            confirmLabel: 'Delete',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            HOD.api(API, { action: KINDS[kind].del, data: { id: row.id } }).then(function () {
                HOD.toast('Deleted.', { tone: 'success' });
                return load();
            }).catch(function () {}).then(function () { HOD.busy(btn, false); });
        });
    });

    load();
})();
