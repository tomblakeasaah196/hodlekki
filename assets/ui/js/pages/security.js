// /assets/ui/js/pages/security.js
// Security Centre (modules/security/index.php): accounts and the manage
// dialog, live sessions, sign-in activity and the audit trail. Data from
// /api/security_api.php through HOD.api. Names, emails, devices and audit
// details come from people and browsers, so they go into the page as TEXT
// (DOM nodes); markup strings hold only our own words.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;
    var boot = HOD.data('security-boot');
    if (!boot || !boot.ready) return;
    var API = boot.api;

    var state = { page: 1, user: null, detail: null, loaded: { accounts: true } };

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
        var b = el('button', 'ui-btn ' + (opts.variant ? 'ui-btn--' + opts.variant : 'ui-btn--secondary') + ' ui-btn--sm');
        b.type = 'button';
        if (opts.icon) b.appendChild(icon(opts.icon));
        b.appendChild(el('span', '', label));
        Object.keys(opts.data || {}).forEach(function (k) { b.setAttribute('data-' + k, opts.data[k]); });
        return b;
    }

    function call(action, data, quiet) {
        return HOD.api(API, { action: action, data: data || {}, quiet: !!quiet });
    }

    function parse(raw) {
        if (!raw) return null;
        var d = new Date(String(raw).replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function when(raw) {
        var d = parse(raw);
        return d ? HOD.format.date(d) + ' ' + HOD.format.time(d) : '—';
    }

    function ago(raw) {
        var d = parse(raw);
        if (!d) return '—';
        var s = Math.max(0, Math.floor((Date.now() - d.getTime()) / 1000));
        if (s < 60) return 'just now';
        if (s < 3600) return Math.floor(s / 60) + ' min ago';
        if (s < 86400) return Math.floor(s / 3600) + ' h ago';
        if (s < 2592000) return Math.floor(s / 86400) + ' days ago';
        return when(raw);
    }

    function fullName(o, fallback) {
        return ((o.first_name || '') + ' ' + (o.last_name || '')).trim() || fallback || 'Unnamed';
    }

    function words(v) {
        return String(v || '').replace(/_/g, ' ');
    }

    function isLocked(lockedUntil) {
        var d = parse(lockedUntil);
        return !!d && d > new Date();
    }

    function statusBadge(status, lockedUntil) {
        if (isLocked(lockedUntil)) return badge('Locked', 'warning');
        if (status === 'suspended') return badge('Suspended', 'warning');
        if (status === 'revoked') return badge('Revoked', 'danger');
        return badge('Active', 'success');
    }

    function avatar(name, picture) {
        var a = el('span', 'ui-avatar ui-avatar--sm');
        var initials = name.split(/\s+/).filter(Boolean).slice(0, 2).map(function (w) { return w.charAt(0).toUpperCase(); }).join('');
        if (picture) {
            var img = el('img', 'nav-avatar-img');
            img.alt = '';
            img.decoding = 'async';
            img.addEventListener('error', function () { a.textContent = initials; });
            img.src = picture;
            a.appendChild(img);
        } else {
            a.setAttribute('aria-hidden', 'true');
            a.textContent = initials;
        }
        return a;
    }

    function cell(tr, text, label, cls) {
        var td = el('td', cls || '');
        if (text instanceof Node) td.appendChild(text); else td.textContent = text;
        if (label) td.setAttribute('data-label', label);
        tr.appendChild(td);
        return td;
    }

    function messageRow(body, cols, text, loading) {
        body.textContent = '';
        var tr = el('tr');
        var td = el('td');
        td.colSpan = cols;
        if (loading) {
            var w = el('div', 'ui-loading');
            w.appendChild(el('span', 'ui-spinner'));
            w.appendChild(el('span', '', text));
            td.appendChild(w);
        } else {
            td.appendChild(el('p', 'ui-meta', text));
        }
        tr.appendChild(td);
        body.appendChild(tr);
    }

    /* ------------------------------------------------------------ overview */

    function setStat(key, value) {
        var n = document.querySelector('[data-stat="' + key + '"] .ui-stat__value');
        if (n) n.textContent = HOD.format.number(value || 0);
    }

    function loadOverview() {
        call('fetch_overview', {}, true).then(function (res) {
            var d = res.data || {};
            setStat('active', d.active_accounts);
            setStat('blocked', Number(d.suspended_accounts || 0) + Number(d.revoked_accounts || 0));
            setStat('sessions', d.live_sessions);
            setStat('failures', d.failures_today);
        }).catch(function () {});
    }

    /* ------------------------------------------------------------ accounts */

    var accountsBody = document.querySelector('[data-sec-accounts]');
    var rangeEl = document.querySelector('[data-sec-range]');
    var pagerEl = document.querySelector('[data-sec-pager]');

    function loadAccounts() {
        messageRow(accountsBody, 5, 'Loading accounts…', true);
        call('fetch_accounts', {
            search: document.getElementById('sec-account-search').value || '',
            filter: document.getElementById('sec-account-filter').value || 'all',
            page: state.page
        }).then(function (res) {
            var rows = res.data || [];
            var pg = res.pagination || null;
            drawPager(pg, rows.length);
            if (!rows.length) {
                messageRow(accountsBody, 5, 'No accounts match. Try another word or filter.');
                return;
            }
            accountsBody.textContent = '';
            rows.forEach(function (u) {
                var name = fullName(u);
                var tr = el('tr');
                var who = el('div', 'ui-list__item');
                who.appendChild(avatar(name, u.picture_path));
                var main = el('div', 'ui-list__main');
                main.appendChild(el('p', 'ui-table__primary', name));
                main.appendChild(el('p', 'ui-table__secondary', u.email || 'no sign-in email'));
                who.appendChild(main);
                cell(tr, who, 'Member');

                var st = el('div', 'ui-stack ui-stack--sm');
                st.appendChild(statusBadge(u.account_status, u.locked_until));
                var flags = el('div', 'ui-chips');
                if (Number(u.must_change_password) === 1) flags.appendChild(badge('Reset pending', 'warning'));
                if (Number(u.no_password) === 1) flags.appendChild(badge('No password', 'info'));
                if (Number(u.live_sessions) > 0) flags.appendChild(badge(u.live_sessions + ' online', 'success'));
                if (flags.childNodes.length) st.appendChild(flags);
                cell(tr, st, 'Status');

                var roles = el('div', 'ui-chips');
                (u.active_roles || '').split(',').filter(Boolean).forEach(function (r) { roles.appendChild(badge(words(r), 'primary')); });
                (u.frozen_roles || '').split(',').filter(Boolean).forEach(function (r) { roles.appendChild(badge(words(r) + ' (frozen)')); });
                if (!roles.childNodes.length) roles.appendChild(el('span', 'ui-meta', 'Member'));
                cell(tr, roles, 'Roles');

                var last = el('div');
                last.appendChild(el('p', '', u.last_login_at ? ago(u.last_login_at) : 'Never'));
                if (u.last_login_ip) last.appendChild(el('p', 'ui-table__secondary', u.last_login_ip));
                cell(tr, last, 'Last sign-in');

                var act = el('td', 'is-actions');
                act.appendChild(button('Manage', { variant: 'secondary', data: { action: 'sec.manage', id: u.id } }));
                tr.appendChild(act);
                accountsBody.appendChild(tr);
            });
        }).catch(function () {
            messageRow(accountsBody, 5, 'Accounts did not load. Reload the page to try again.');
        });
    }

    function drawPager(pg, shown) {
        pagerEl.textContent = '';
        if (!pg) {
            rangeEl.textContent = HOD.format.number(shown) + ' shown';
            return;
        }
        state.page = pg.page;
        rangeEl.textContent = pg.total === 0 ? 'No accounts match'
            : HOD.format.number(pg.from) + '–' + HOD.format.number(pg.to) + ' of ' + HOD.format.number(pg.total) + ' · problems first';
        if (pg.pages <= 1) return;
        var pages = [1, pg.pages, pg.page - 1, pg.page, pg.page + 1]
            .filter(function (n, i, a) { return n >= 1 && n <= pg.pages && a.indexOf(n) === i; })
            .sort(function (a, b) { return a - b; });
        var prevBtn = button('Previous', { variant: 'ghost', icon: 'chevron-left', data: { action: 'sec.page', page: Math.max(1, pg.page - 1) } });
        prevBtn.disabled = pg.page <= 1;
        pagerEl.appendChild(prevBtn);
        var last = 0;
        pages.forEach(function (n) {
            if (n - last > 1) pagerEl.appendChild(el('span', 'ui-meta', '…'));
            var b = button(String(n), { variant: n === pg.page ? 'primary' : 'ghost', data: { action: 'sec.page', page: n } });
            if (n === pg.page) b.setAttribute('aria-current', 'page');
            pagerEl.appendChild(b);
            last = n;
        });
        var nextBtn = button('Next', { variant: 'ghost', icon: 'chevron-right', data: { action: 'sec.page', page: Math.min(pg.pages, pg.page + 1) } });
        nextBtn.disabled = pg.page >= pg.pages;
        pagerEl.appendChild(nextBtn);
    }

    HOD.actions.on('sec.accounts.search', function () { state.page = 1; loadAccounts(); });
    HOD.actions.on('sec.page', function (btn) {
        var p = parseInt(btn.getAttribute('data-page'), 10);
        if (!p || p === state.page) return;
        state.page = p;
        loadAccounts();
        document.getElementById('panel-accounts').scrollIntoView({ block: 'start' });
    });

    /* ------------------------------------------------------- manage dialog */

    var dlg = document.getElementById('sec-manage-dialog');
    var q = function (sel) { return dlg.querySelector(sel); };

    HOD.actions.on('sec.manage', function (btn) {
        state.user = btn.getAttribute('data-id');
        q('[data-sec-loading]').hidden = false;
        q('[data-sec-body]').hidden = true;
        dlg.querySelectorAll('form').forEach(function (f) { f.reset(); });
        q('#sec-reason').value = '';
        HOD.dialog.open('sec-manage-dialog');
        loadManage();
    });

    function loadManage() {
        if (!state.user) return;
        call('fetch_user_detail', { user_id: state.user }).then(function (res) {
            state.detail = res.data;
            drawManage(res.data);
        }).catch(function () { HOD.dialog.close('sec-manage-dialog'); });
    }

    function listInto(box, items, empty, build) {
        box.textContent = '';
        if (!items.length) {
            box.appendChild(el('p', 'ui-list__item ui-meta', empty));
            return;
        }
        items.forEach(function (it) { box.appendChild(build(it)); });
    }

    function drawManage(d) {
        var u = d.user;
        q('[data-sec-name]').textContent = fullName(u, 'Member');
        q('[data-sec-email]').textContent = u.email || 'no sign-in email';
        var st = q('[data-sec-status]');
        st.textContent = '';
        st.appendChild(statusBadge(u.account_status, u.locked_until));
        q('[data-sec-loading]').hidden = true;
        q('[data-sec-body]').hidden = false;

        var blocked = !d.can_act || !boot.canEdit;
        q('[data-sec-blocked]').hidden = !blocked;
        q('[data-sec-actions]').hidden = blocked;
        if (blocked) return;

        var locked = isLocked(u.locked_until);
        q('[data-fact="last_login"]').textContent = u.last_login_at ? ago(u.last_login_at) : 'Never';
        q('[data-fact="password"]').textContent = Number(u.no_password) === 1 ? 'Never set' : (u.password_changed_at ? ago(u.password_changed_at) : 'Unknown');
        q('[data-fact="failures"]').textContent = (Number(u.failed_login_count) || 0) + (locked ? ' · locked' : '');
        q('[data-fact="devices"]').textContent = String((d.sessions || []).length);

        var roles = q('[data-sec-roles]');
        roles.textContent = '';
        var chips = el('div', 'ui-chips');
        var frozen = 0;
        (d.roles || []).forEach(function (r) {
            var isFrozen = Number(r.is_frozen) === 1;
            if (isFrozen) frozen++;
            chips.appendChild(badge(words(r.role_name) + (isFrozen ? ' (frozen)' : ''), isFrozen ? '' : 'primary'));
        });
        if (!chips.childNodes.length) chips.appendChild(el('span', 'ui-meta', 'No roles: an ordinary member.'));
        roles.appendChild(chips);
        q('[data-sec-frozen]').hidden = frozen === 0;

        var reasonNow = q('[data-sec-reason-now]');
        reasonNow.hidden = !u.status_reason;
        reasonNow.textContent = u.status_reason ? 'Current reason: ' + u.status_reason : '';
        dlg.querySelectorAll('[data-act="status"]').forEach(function (b) {
            b.disabled = b.getAttribute('data-value') === u.account_status;
        });

        // Only an old @domain name can be renamed; nobody gets a new one.
        var hasName = !!u.email && String(u.email).toLowerCase().slice(-(boot.domain.length + 1)) === '@' + boot.domain;
        q('[data-sec-email-now]').textContent = hasName
            ? 'Now: ' + u.email + (u.real_email ? ' · personal: ' + u.real_email : '')
            : (u.email ? 'Signs in with ' + u.email + (u.phone ? ' or their phone.' : '.')
                : (u.phone ? 'Signs in with their phone.' : 'No phone or email: they cannot sign in yet.'));
        dlg.querySelectorAll('[data-action="sec.email"] input, [data-action="sec.email"] button').forEach(function (c) {
            c.disabled = !hasName;
        });

        q('[data-sec-unlock]').disabled = !locked;
        var force = q('[data-sec-force]');
        var forcing = Number(u.must_change_password) === 1;
        force.setAttribute('data-value', forcing ? '0' : '1');
        force.querySelector('span').textContent = forcing ? 'Cancel forced change' : 'Force change';

        listInto(q('[data-sec-devices]'), d.sessions || [], 'No live devices.', function (s) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('p', 'ui-list__title', s.device || 'Unknown device'));
            main.appendChild(el('p', 'ui-list__meta', (s.ip_address || '—') + ' · ' + ago(s.last_seen_at)));
            row.appendChild(main);
            var end = el('div', 'ui-list__end');
            end.appendChild(button('Sign out', { variant: 'danger-ghost', data: { action: 'sec.session.revoke', session: s.id } }));
            row.appendChild(end);
            return row;
        });
        listInto(q('[data-sec-attempts]'), d.attempts || [], 'No attempts recorded.', function (a) {
            var ok = Number(a.was_successful) === 1;
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            var t = el('p', 'ui-list__title');
            t.appendChild(badge(ok ? 'Success' : words(a.failure_reason || 'failed'), ok ? 'success' : 'danger'));
            main.appendChild(t);
            main.appendChild(el('p', 'ui-list__meta', (a.ip_address || '') + ' · ' + (a.device || '') + ' · ' + ago(a.created_at)));
            row.appendChild(main);
            return row;
        });
        listInto(q('[data-sec-history]'), d.audit || [], 'Nothing logged yet.', function (l) {
            var row = el('div', 'ui-list__item');
            var main = el('div', 'ui-list__main');
            main.appendChild(el('p', 'ui-list__title', words(l.action)));
            main.appendChild(el('p', 'ui-list__meta', (l.actor_label || 'System') + ' · ' + ago(l.created_at)));
            row.appendChild(main);
            return row;
        });
    }

    function afterWrite(res) {
        HOD.toast(res.message, 'success');
        refresh();
        if (state.user) loadManage();
    }

    HOD.actions.on('sec.act', function (btn) {
        var act = btn.getAttribute('data-act');
        var value = btn.getAttribute('data-value') || '';
        var uid = state.user;
        if (!uid) return;
        var reason = (q('#sec-reason').value || '').trim();
        var payload = null;
        var ask = null;

        if (act === 'status') {
            if (value !== 'active' && reason === '') {
                HOD.form.setErrors(q('#sec-reason').closest('.ui-card'), { reason: 'Write a reason first.' });
                q('#sec-reason').focus();
                return;
            }
            ask = value === 'revoked'
                ? { title: 'Revoke this account?', message: 'Signed out everywhere, blocked from signing in, and every role frozen.', confirmLabel: 'Revoke account', tone: 'danger' }
                : (value === 'suspended'
                    ? { title: 'Suspend this account?', message: 'Signed out everywhere and blocked until reinstated.', confirmLabel: 'Suspend account', tone: 'danger' }
                    : { title: 'Reinstate this account?', message: 'They can sign in again.', confirmLabel: 'Reinstate' });
            payload = ['set_status', { user_id: uid, account_status: value, reason: reason }];
        } else if (act === 'force_logout') {
            ask = { title: 'Sign out everywhere?', message: 'Every device they use is signed out now.', confirmLabel: 'Sign out' };
            payload = ['force_logout', { user_id: uid }];
        } else if (act === 'unlock_account') {
            payload = ['unlock_account', { user_id: uid }];
        } else if (act === 'force_change') {
            payload = ['set_force_change', { user_id: uid, force: value }];
        } else if (act === 'restore_roles') {
            ask = { title: 'Restore frozen roles?', message: 'Every role the revoke froze comes back.', confirmLabel: 'Restore roles' };
            payload = ['restore_roles', { user_id: uid }];
        }
        if (!payload) return;
        (ask ? HOD.confirm(ask) : Promise.resolve(true)).then(function (yes) {
            if (!yes) return;
            HOD.busy(btn, true);
            call(payload[0], payload[1]).then(afterWrite).catch(function () {}).finally(function () { HOD.busy(btn, false); });
        });
    });

    HOD.actions.on('sec.suggest', function (btn) {
        HOD.busy(btn, true);
        call('suggest_password').then(function (res) {
            q('#sec-new-pw').value = res.data.password;
            q('#sec-confirm-pw').value = res.data.password;
            HOD.toast('Strong password filled in. Note it before you save.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('sec.reset', function (form) {
        var v = HOD.form.values(form);
        if (!v.new_password || !v.confirm_password) {
            HOD.form.setErrors(form, { new_password: !v.new_password ? 'Type the new password.' : '', confirm_password: !v.confirm_password ? 'Type it again.' : '' });
            return;
        }
        if (v.new_password !== v.confirm_password) {
            HOD.form.setErrors(form, { confirm_password: 'The two passwords differ.' });
            return;
        }
        HOD.confirm({ title: 'Set this password?', message: 'Every device they are signed in on is signed out.', confirmLabel: 'Set password' })
            .then(function (yes) {
                if (!yes) return;
                call('reset_password', {
                    user_id: state.user, new_password: v.new_password, confirm_password: v.confirm_password,
                    require_change: form.elements.require_change.checked ? 1 : 0,
                    reason: (q('#sec-reason').value || '').trim()
                }).then(function (res) { form.reset(); afterWrite(res); }).catch(function (err) {
                    if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
                });
            });
    });

    function withDomain(v) {
        v = String(v || '').trim().toLowerCase();
        return v === '' || v.indexOf('@') !== -1 ? v : v + '@' + boot.domain;
    }

    HOD.actions.on('sec.email', function (form) {
        var v = HOD.form.values(form);
        if (!v.new_email || !v.confirm_email) {
            HOD.form.setErrors(form, { new_email: !v.new_email ? 'Type the new sign-in email.' : '', confirm_email: !v.confirm_email ? 'Type it again.' : '' });
            return;
        }
        if (withDomain(v.new_email) !== withDomain(v.confirm_email)) {
            HOD.form.setErrors(form, { confirm_email: 'The two addresses differ.' });
            return;
        }
        HOD.confirm({
            title: 'Change the sign-in email?',
            message: 'New address: ' + withDomain(v.new_email) + '. The old one stops working now; the password stays.',
            confirmLabel: 'Save email'
        }).then(function (yes) {
            if (!yes) return;
            call('change_email', {
                user_id: state.user, new_email: v.new_email, confirm_email: v.confirm_email,
                sign_out_everywhere: form.elements.sign_out_everywhere.checked ? 1 : 0,
                reason: (q('#sec-reason').value || '').trim()
            }).then(function (res) { form.reset(); afterWrite(res); }).catch(function (err) {
                if (err && err.field) HOD.form.setErrors(form, (function (o) { o[err.field] = err.message; return o; })({}));
            });
        });
    });

    HOD.actions.on('sec.session.revoke', function (btn) {
        HOD.confirm({ title: 'Sign this device out?', message: 'They will need to sign in again on it.', confirmLabel: 'Sign out' })
            .then(function (yes) {
                if (!yes) return;
                HOD.busy(btn, true);
                call('revoke_session', { session_id: btn.getAttribute('data-session') }).then(afterWrite)
                    .catch(function () {}).finally(function () { HOD.busy(btn, false); });
            });
    });

    /* ------------------------------------------------------------ sessions */

    var sessionsBody = document.querySelector('[data-sec-sessions]');

    function loadSessions() {
        messageRow(sessionsBody, 6, 'Loading sessions…', true);
        call('fetch_sessions').then(function (res) {
            var rows = res.data || [];
            if (!rows.length) {
                messageRow(sessionsBody, 6, 'Nobody has signed in for the last 7 days.');
                return;
            }
            sessionsBody.textContent = '';
            rows.forEach(function (s) {
                var tr = el('tr');
                var who = el('div');
                who.appendChild(el('p', 'ui-table__primary', fullName(s, 'Unknown')));
                if (s.email) who.appendChild(el('p', 'ui-table__secondary', s.email));
                cell(tr, who, 'Member');
                cell(tr, s.device || '—', 'Device');
                cell(tr, s.ip_address || '—', 'IP');
                cell(tr, when(s.created_at), 'Signed in');
                cell(tr, ago(s.last_seen_at), 'Last active');
                var act = el('td', 'is-actions');
                if (boot.canEdit) act.appendChild(button('Sign out', { variant: 'danger-ghost', data: { action: 'sec.session.revoke', session: s.id } }));
                tr.appendChild(act);
                sessionsBody.appendChild(tr);
            });
        }).catch(function () { messageRow(sessionsBody, 6, 'Sessions did not load. Try Refresh.'); });
    }

    HOD.actions.on('sec.sessions.refresh', loadSessions);

    /* ------------------------------------------------------------- sign-ins */

    var loginsBody = document.querySelector('[data-sec-logins]');

    function loadLogins() {
        messageRow(loginsBody, 5, 'Loading sign-ins…', true);
        call('fetch_login_history', {
            filter: document.getElementById('sec-login-filter').value || 'all',
            search: document.getElementById('sec-login-search').value || ''
        }).then(function (res) {
            var rows = res.data || [];
            if (!rows.length) {
                messageRow(loginsBody, 5, 'No sign-ins match.');
                return;
            }
            loginsBody.textContent = '';
            rows.forEach(function (a) {
                var ok = Number(a.was_successful) === 1;
                var tr = el('tr');
                cell(tr, when(a.created_at), 'When');
                var who = el('div');
                who.appendChild(el('p', 'ui-table__primary', fullName(a, a.email || 'Unknown')));
                who.appendChild(el('p', 'ui-table__secondary', (a.email || '') + (a.context && a.context !== 'login' ? ' · ' + words(a.context) : '')));
                cell(tr, who, 'Who');
                cell(tr, badge(ok ? 'Success' : (words(a.failure_reason) || 'Failed'), ok ? 'success' : 'danger'), 'Result');
                cell(tr, a.device || '—', 'Device');
                cell(tr, a.ip_address || '—', 'IP');
                loginsBody.appendChild(tr);
            });
        }).catch(function () { messageRow(loginsBody, 5, 'Sign-ins did not load. Reload the page to try again.'); });
    }

    HOD.actions.on('sec.logins.search', loadLogins);

    /* ---------------------------------------------------------------- audit */

    var auditBody = document.querySelector('[data-sec-audit]');

    function loadAudit() {
        messageRow(auditBody, 5, 'Loading the audit trail…', true);
        call('fetch_audit_log', { search: document.getElementById('sec-audit-search').value || '' }).then(function (res) {
            var rows = res.data || [];
            if (!rows.length) {
                messageRow(auditBody, 5, 'Nothing logged that matches.');
                return;
            }
            auditBody.textContent = '';
            rows.forEach(function (l) {
                var tr = el('tr');
                cell(tr, when(l.created_at), 'When');
                cell(tr, badge(words(l.action), /revok|suspend|lock|fail|breakglass/i.test(l.action || '') ? 'danger' : 'info'), 'Action');
                cell(tr, l.actor_label || 'System', 'By');
                cell(tr, fullName({ first_name: l.target_first, last_name: l.target_last }, '—'), 'Member');
                var det = el('div');
                det.appendChild(el('p', '', l.details || ''));
                if (l.ip_address) det.appendChild(el('p', 'ui-table__secondary', l.ip_address));
                cell(tr, det, 'Details');
                auditBody.appendChild(tr);
            });
        }).catch(function () { messageRow(auditBody, 5, 'The audit trail did not load. Reload the page to try again.'); });
    }

    HOD.actions.on('sec.audit.search', loadAudit);

    /* ---------------------------------------------------------------- tabs */

    function refresh() {
        loadOverview();
        loadAccounts();
        if (state.loaded.sessions) loadSessions();
        if (state.loaded.audit) loadAudit();
    }

    document.addEventListener('ui:tab', function (e) {
        var tab = e.detail && e.detail.tab;
        if (!tab || state.loaded[tab]) return;
        state.loaded[tab] = true;
        if (tab === 'sessions') loadSessions();
        if (tab === 'logins') loadLogins();
        if (tab === 'audit') loadAudit();
    });

    loadOverview();
    loadAccounts();
    var hash = (window.location.hash.match(/tab=(\w+)/) || [])[1];
    if (hash && hash !== 'accounts') document.dispatchEvent(new CustomEvent('ui:tab', { detail: { tab: hash } }));
})();
