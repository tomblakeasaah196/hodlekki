// /assets/ui/js/pages/mail-mailboxes.js
// Mailboxes (docs/MAIL.md): every change is queued on the server and done by the minute cron, so after a
// request the page waits for the job, then reloads. Names and addresses go into the page as text only.
(function () {
    'use strict';
    var cfg = HOD.data('mail-mailboxes') || {};

    function post(action, payload, form) {
        if (form) HOD.form.clearErrors(form);
        return HOD.api(cfg.api, { action: action, data: payload }).catch(function (err) {
            if (form && err && err.field) {
                var errors = {};
                errors[err.field] = err.message;
                HOD.form.setErrors(form, errors);
            }
            throw err;
        });
    }

    // Wait for a queued job (the minute cron), then reload so the table shows the new state.
    function waitForJob(jobId, label) {
        HOD.toast(label + ': within a minute.', 'info');
        var tries = 0;
        (function poll() {
            tries++;
            HOD.api(cfg.api + '?action=job&id=' + encodeURIComponent(jobId), { method: 'GET', quiet: true })
                .then(function (res) {
                    var job = res.data || {};
                    if (job.status === 'done') { HOD.toast(label + ': done.', 'success'); window.location.reload(); return; }
                    if (job.status === 'failed') { HOD.toast(label + ': ' + (job.error || 'the server refused it.'), 'danger'); window.location.reload(); return; }
                    if (tries < 40) setTimeout(poll, 5000);
                    else window.location.reload();
                })
                .catch(function () { if (tries < 40) setTimeout(poll, 5000); });
        })();
    }

    function submitForm(form, action, extra, label) {
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        var payload = HOD.form.values(form);
        Object.keys(extra || {}).forEach(function (k) { payload[k] = extra[k]; });
        post(action, payload, form)
            .then(function (res) {
                var dialog = form.closest('dialog');
                if (dialog) HOD.dialog.close(dialog.id);
                if (res.data && res.data.job_id) waitForJob(res.data.job_id, label);
                else { HOD.toast(label + ': saved.', 'success'); window.location.reload(); }
            })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    }

    HOD.actions.on('mb.create', function (form) { submitForm(form, 'create_shared', {}, 'New mailbox'); });
    HOD.actions.on('mb.personal', function (form) { submitForm(form, 'issue_personal', {}, 'Personal mailbox'); });
    HOD.actions.on('mb.bringin.save', function (form) { submitForm(form, 'bring_in', {}, 'Bring in'); });
    HOD.actions.on('mb.reserved', function (form) { submitForm(form, 'reserved_save', {}, 'Personal slots'); });

    HOD.actions.on('mb.bringin', function (button) {
        var form = document.querySelector('#mb-bringin form');
        form.reset();
        form.elements.email.value = button.dataset.email;
        form.querySelector('[data-mb-bringin-email]').textContent = button.dataset.email;
        HOD.dialog.open('mb-bringin');
    });

    // Issue a personal mailbox: find the person, show whether they may hold one, suggest the address.
    HOD.actions.on('mb.people', function (input) {
        var list = document.querySelector('[data-mb-people]');
        var q = input.value.trim();
        list.textContent = '';
        if (q.length < 2) return;
        HOD.api(cfg.api + '?action=people&q=' + encodeURIComponent(q), { method: 'GET', quiet: true }).then(function (res) {
            (res.data || []).forEach(function (p) {
                var item = document.createElement('button');
                item.type = 'button';
                item.className = 'ui-list__item';
                item.setAttribute('role', 'option');
                item.disabled = !p.eligible;
                var main = document.createElement('span');
                main.className = 'ui-list__main';
                var title = document.createElement('span');
                title.className = 'ui-list__title';
                title.textContent = p.name;
                var meta = document.createElement('span');
                meta.className = 'ui-list__meta';
                meta.textContent = p.eligible ? p.suggested : 'Not allowed a personal mailbox.';
                main.appendChild(title);
                main.appendChild(meta);
                item.appendChild(main);
                item.addEventListener('click', function () {
                    var form = input.form;
                    form.elements.user_id.value = p.id;
                    form.elements.local_part.value = p.suggested.split('@')[0];
                    input.value = p.name;
                    list.textContent = '';
                });
                list.appendChild(item);
            });
        }).catch(function () {});
    });

    function act(id, what, label, extra) {
        var payload = { id: id, what: what };
        Object.keys(extra || {}).forEach(function (k) { payload[k] = extra[k]; });
        return post('act', payload).then(function (res) { waitForJob(res.data.job_id, label); });
    }

    HOD.actions.on('mb.act', function (button) {
        var what = button.dataset.what;
        var words = {
            reset: ['Reset this password?', button.dataset.email, 'Reset password', null],
            suspend: ['Suspend this mailbox?', 'Mail still arrives; nobody can read it.', 'Suspend mailbox', 'danger'],
            resume: ['Resume this mailbox?', button.dataset.email, 'Resume mailbox', null]
        }[what];
        HOD.confirm({ title: words[0], message: words[1], confirmLabel: words[2], tone: words[3] || undefined }).then(function (yes) {
            if (yes) act(button.dataset.id, what, words[2]).catch(function () {});
        });
    });

    HOD.actions.on('mb.aiplan', function (button) {
        var on = button.dataset.confirmed === '1';
        HOD.confirm(on
            ? { title: 'Record paid plan?', message: 'Only when the owner confirmed the Gemini project is on a paid plan.', confirmLabel: 'Record paid plan' }
            : { title: 'Withdraw paid plan?', message: 'AI stops everywhere until it is recorded again.', confirmLabel: 'Withdraw', tone: 'danger' }
        ).then(function (yes) {
            if (!yes) return;
            post('ai_plan', { confirmed: on ? '1' : '0' }).then(function () { window.location.reload(); }).catch(function () {});
        });
    });

    HOD.actions.on('mb.aibox', function (button) {
        HOD.busy(button, true);
        post('ai_mailbox', { id: button.dataset.id, on: button.dataset.on })
            .then(function () { window.location.reload(); })
            .catch(function () { HOD.busy(button, false); });
    });
    HOD.actions.on('mb.remove', function (button) {
        var email = button.dataset.email;
        HOD.confirm({
            title: 'Remove ' + email + '?',
            message: 'This deletes its mail. Type the address to confirm.',
            confirmLabel: 'Remove mailbox', tone: 'danger', requireText: email
        }).then(function (yes) {
            if (yes) act(button.dataset.id, 'remove', 'Remove mailbox', { confirm: email }).catch(function () {});
        });
    });

    HOD.actions.on('mb.quota', function (button) {
        HOD.prompt({ title: 'Set storage', label: 'Limit in MB (250 to 10240)', required: true }).then(function (value) {
            if (value) act(button.dataset.id, 'set_quota', 'Storage limit', { quota_mb: value }).catch(function () {});
        });
    });

    HOD.actions.on('mb.autoreply', function (button) {
        var form = document.querySelector('#mb-autoreply form');
        form.reset();
        form.elements.id.value = button.dataset.id;
        HOD.dialog.open('mb-autoreply');
    });
    HOD.actions.on('mb.autoreply.save', function (form) {
        var v = HOD.form.values(form);
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        post('act', { id: v.id, what: 'auto_reply_on', subject: v.subject, body: v.body, days: v.days }, form)
            .then(function (res) { HOD.dialog.close('mb-autoreply'); waitForJob(res.data.job_id, 'Automatic reply'); })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });
    HOD.actions.on('mb.autoreply.off', function () {
        var form = document.querySelector('#mb-autoreply form');
        act(form.elements.id.value, 'auto_reply_off', 'Automatic reply off').then(function () { HOD.dialog.close('mb-autoreply'); }).catch(function () {});
    });

    // Grants editor: one row per line (kind, which, seat, level), rebuilt from the server's answer.
    var grants = { id: null, rows: [], options: null };

    function select(name, options, value) {
        var s = document.createElement('select');
        s.className = 'ui-select';
        s.setAttribute('aria-label', name);
        options.forEach(function (o) {
            var opt = document.createElement('option');
            opt.value = o[0];
            opt.textContent = o[1];
            if (String(o[0]) === String(value)) opt.selected = true;
            s.appendChild(opt);
        });
        return s;
    }

    function drawGrants() {
        var box = document.querySelector('[data-mb-grants]');
        box.textContent = '';
        if (!grants.rows.length) {
            var p = document.createElement('p');
            p.className = 'ui-hint';
            p.textContent = 'Nobody yet. Add a line.';
            box.appendChild(p);
        }
        var o = grants.options;
        grants.rows.forEach(function (g, i) {
            var row = document.createElement('div');
            row.className = 'ui-toolbar';
            var type = select('Who', [['seat', 'Department seat'], ['department', 'Whole department'], ['role', 'Role'], ['user', 'Person (user id)']], g.subject_type);
            var which;
            if (g.subject_type === 'role') which = select('Role', o.roles.map(function (r) { return [r.id, r.label]; }), g.subject_id);
            else if (g.subject_type === 'user') { which = document.createElement('input'); which.className = 'ui-input'; which.type = 'number'; which.value = g.subject_id || ''; which.setAttribute('aria-label', 'User id'); }
            else which = select('Department', o.departments.map(function (d) { return [d.id, d.label]; }), g.subject_id);
            var seat = select('Seat', o.seats.map(function (s) { return [s.key, s.label]; }), g.seat_type);
            seat.hidden = g.subject_type !== 'seat';
            var level = select('Level', Object.keys(o.levels).filter(function (k) { return k !== 'owner'; }).map(function (k) { return [k, o.levels[k]]; }), g.level || 'read');
            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'ui-btn ui-btn--danger-ghost ui-btn--sm';
            remove.textContent = 'Remove';
            type.addEventListener('change', function () { g.subject_type = type.value; g.subject_id = 0; drawGrants(); });
            which.addEventListener('change', function () { g.subject_id = parseInt(which.value, 10) || 0; });
            seat.addEventListener('change', function () { g.seat_type = seat.value; });
            level.addEventListener('change', function () { g.level = level.value; });
            remove.addEventListener('click', function () { grants.rows.splice(i, 1); drawGrants(); });
            if (!g.subject_id && which.value) g.subject_id = parseInt(which.value, 10) || 0;
            if (g.subject_type === 'seat' && !g.seat_type) g.seat_type = seat.value;
            if (!g.level) g.level = level.value;
            [type, which, seat, level, remove].forEach(function (el) { row.appendChild(el); });
            box.appendChild(row);
        });
    }

    HOD.actions.on('mb.grants', function (button) {
        grants.id = button.dataset.id;
        HOD.api(cfg.api + '?action=grants&id=' + encodeURIComponent(grants.id), { method: 'GET' }).then(function (res) {
            grants.rows = res.data.grants;
            grants.options = res.data.options;
            drawGrants();
            HOD.dialog.open('mb-grants');
        }).catch(function () {});
    });
    HOD.actions.on('mb.grants.add', function () {
        grants.rows.push({ subject_type: 'seat', subject_id: 0, seat_type: '', level: 'read' });
        drawGrants();
    });
    HOD.actions.on('mb.grants.save', function (button) {
        HOD.busy(button, true);
        post('grants_save', { id: grants.id, grants: JSON.stringify(grants.rows) })
            .then(function () { HOD.toast('Access saved.', 'success'); HOD.dialog.close('mb-grants'); window.location.reload(); })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });

    // Exports
    HOD.actions.on('mb.export', function (button) {
        HOD.prompt({ title: 'Request export', label: 'Why is this mail needed?', required: true, multiline: true })
            .then(function (reason) {
                if (!reason) return;
                post('export_request', { id: button.dataset.id, reason: reason })
                    .then(function () { HOD.toast('Request sent.', 'success'); window.location.reload(); })
                    .catch(function () {});
            });
    });
    HOD.actions.on('mb.approve', function (button) {
        HOD.confirm({ title: 'Approve this export?', message: 'Your approval is recorded.', confirmLabel: 'Approve export' })
            .then(function (yes) {
                if (!yes) return;
                post('export_approve', { request_id: button.dataset.request, side: button.dataset.side })
                    .then(function () { HOD.toast('Approval saved.', 'success'); window.location.reload(); })
                    .catch(function () {});
            });
    });
    HOD.actions.on('mb.refuse', function (button) {
        HOD.confirm({ title: 'Refuse this export?', message: 'The mail stays unread.', confirmLabel: 'Refuse export', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                post('export_refuse', { request_id: button.dataset.request })
                    .then(function () { window.location.reload(); })
                    .catch(function () {});
            });
    });
    HOD.actions.on('mb.download', function (button) {
        HOD.busy(button, true);
        HOD.api(cfg.export + '?request=' + encodeURIComponent(button.dataset.request), { method: 'GET' })
            .then(function (res) {
                (res.data || []).forEach(function (folder) {
                    var a = document.createElement('a');
                    a.href = cfg.export + '?request=' + encodeURIComponent(button.dataset.request) + '&folder=' + encodeURIComponent(folder);
                    a.className = 'ui-btn ui-btn--ghost ui-btn--sm';
                    a.textContent = folder;
                    a.setAttribute('download', '');
                    button.parentNode.appendChild(a);
                });
                button.hidden = true;
            })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });
})();
