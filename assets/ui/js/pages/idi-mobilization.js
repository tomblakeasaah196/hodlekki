// /assets/ui/js/pages/idi-mobilization.js
// Exousia mobilisation (idi_mobilization.php): the PIN gate, the to-do and
// contacted lists with search, follow-up notes per person, quick add and the
// list import. Every call goes to /api/mobilization_api.php as before.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/mobilization_api.php';
    var cfg = HOD.data('idi-config') || {};
    var esc = HOD.esc;

    function $(id) { return document.getElementById(id); }
    function fail(err, fallback) {
        HOD.toast(err && err.code === 'NETWORK' ? 'Connection error.' : ((err && err.message) || fallback || 'Something went wrong.'), 'danger');
    }
    function whatsapp(phone) {
        var p = String(phone || '').replace(/\D/g, '');
        if (p.charAt(0) === '0') p = '234' + p.substring(1);
        return p ? 'https://wa.me/' + p : '';
    }

    /* ----- Gate ---------------------------------------------------------- */

    HOD.actions.on('idi.pin', function (form, event) {
        var input = $('idi-pin');
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        HOD.api(API, { data: { action: 'verify_pin', pin: input.value }, quiet: true }).then(function () {
            $('idi-gate').hidden = true;
            $('idi-app').hidden = false;
            load();
        }).catch(function (err) {
            fail(err, 'That PIN did not work.');
            input.value = '';
            input.focus();
        }).then(function () { HOD.busy(btn, false); });
    });

    /* ----- Lists --------------------------------------------------------- */

    function card(c) {
        var contacted = Number(c.is_contacted) === 1;
        var wa = whatsapp(c.phone_number);
        var name = String(c.full_name || '');
        return '<article class="ui-card ui-card__body space-y-3" data-search="' + esc((name + ' ' + (c.phone_number || '')).toLowerCase()) + '">'
            + '<div class="flex items-center gap-3">'
            + '<span class="ui-avatar ui-avatar--c4"><span aria-hidden="true">' + esc((name || '?').charAt(0).toUpperCase()) + '</span></span>'
            + '<div class="min-w-0 flex-1"><h3 class="truncate font-semibold text-ink">' + esc(name) + '</h3>'
            + '<p class="text-sm text-ink-muted">' + esc(c.phone_number) + '</p></div>'
            + '<a class="ui-btn ui-btn--primary ui-btn--icon" href="tel:' + esc(c.phone_number) + '" aria-label="Call ' + esc(name) + '">' + HOD.icon('phone') + '</a>'
            + (wa ? '<a class="ui-btn ui-btn--secondary ui-btn--icon" href="' + esc(wa) + '" target="_blank" rel="noopener" aria-label="WhatsApp ' + esc(name) + '">' + HOD.icon('message-circle') + '</a>' : '')
            + '</div>'
            + '<div class="rounded-ui bg-surface-sunken p-3 text-sm"><p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Background</p>'
            + '<p class="text-ink-muted">' + (c.context ? esc(c.context) : '<span class="italic">No background yet.</span>') + '</p></div>'
            + (contacted ? '<div class="text-sm"><p class="text-xs font-semibold uppercase tracking-wide text-ink-subtle">Notes by ' + esc(c.contacted_by) + '</p>'
                + '<p class="whitespace-pre-line text-ink-muted">' + esc(c.followup_notes) + '</p></div>' : '')
            + '<details class="group rounded-ui border border-line">'
            + '<summary class="cursor-pointer px-3 py-2 text-sm font-semibold text-primary">' + (contacted ? 'Edit the note' : 'Log the call') + '</summary>'
            + '<form class="ui-form space-y-3 px-3 pb-3" data-action="idi.note" data-id="' + esc(c.id) + '" data-ui-validate novalidate>'
            + '<div class="ui-field"><label class="ui-label" for="idi-n-' + esc(c.id) + '">Call notes<span class="ui-label__req" aria-hidden="true">*</span></label>'
            + '<textarea class="ui-textarea" id="idi-n-' + esc(c.id) + '" name="followup_notes" rows="2" required placeholder="What was the outcome of the call?">' + (contacted ? esc(c.followup_notes) : '') + '</textarea>'
            + '<p class="ui-field-error" aria-live="polite"></p></div>'
            + '<div class="ui-field"><label class="ui-label" for="idi-b-' + esc(c.id) + '">Your name<span class="ui-label__req" aria-hidden="true">*</span></label>'
            + '<input class="ui-input" id="idi-b-' + esc(c.id) + '" name="contacted_by" required placeholder="e.g. Bro. John" value="' + (contacted ? esc(c.contacted_by) : '') + '">'
            + '<p class="ui-field-error" aria-live="polite"></p></div>'
            + '<button type="submit" class="ui-btn ui-btn--primary ui-btn--block"><span>Save notes</span></button>'
            + '</form></details></article>';
    }

    function render(target, rows) {
        $(target).innerHTML = rows.length ? rows.map(card).join('')
            : '<div class="ui-card"><div class="ui-empty ui-empty--compact"><p class="ui-empty__title">Nobody on this list</p></div></div>';
    }

    function load() {
        $('idi-todo').innerHTML = '<div class="ui-skeleton ui-skeleton--block h-32"></div><div class="ui-skeleton ui-skeleton--block h-32"></div>';
        return HOD.api(API, { action: 'fetch_contacts', quiet: true, raw: true }).then(function (res) {
            if (res.status === 'auth_error') { window.location.reload(); return; }
            if (res.status !== 'success') { fail({ message: res.message }, 'Could not load the list.'); return; }
            var todo = res.uncontacted || [], done = res.contacted || [];
            $('idi-count-todo').textContent = todo.length;
            $('idi-count-contacted').textContent = done.length;
            render('idi-todo', todo);
            render('idi-contacted', done);
            filter($('idi-search').value);
        }).catch(function (err) { fail(err, 'Could not load the list.'); });
    }

    function filter(q) {
        q = String(q || '').trim().toLowerCase();
        Array.prototype.forEach.call(document.querySelectorAll('[data-search]'), function (el) {
            el.hidden = q !== '' && el.getAttribute('data-search').indexOf(q) === -1;
        });
    }
    HOD.actions.on('idi.search', function (input) { filter(input.value); });

    HOD.actions.on('idi.tab', function (btn) {
        var tab = btn.getAttribute('data-tab');
        Array.prototype.forEach.call(document.querySelectorAll('[data-action="idi.tab"]'), function (b) { b.setAttribute('aria-pressed', b === btn ? 'true' : 'false'); });
        $('idi-todo').hidden = tab !== 'todo';
        $('idi-contacted').hidden = tab !== 'contacted';
    });

    HOD.actions.on('idi.note', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        var fd = new FormData(form);
        fd.append('action', 'update_note');
        fd.append('id', form.getAttribute('data-id'));
        HOD.api(API, { data: fd, quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Saved.', 'success');
            load();
        }).catch(function (err) { fail(err); HOD.busy(btn, false); });
    });

    HOD.actions.on('idi.add', function (form, event) {
        var btn = (event && event.submitter) || document.querySelector('[form="idi-add-form"]');
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Added.', 'success');
            HOD.dialog.close('idi-add');
            form.reset();
            load();
        }).catch(function (err) { fail(err, 'Failed to add.'); }).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('idi.import', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            HOD.toast(res.message || 'Imported.', 'success');
            HOD.dialog.close('idi-data');
            form.reset();
            load();
        }).catch(function (err) { fail(err, 'Upload failed.'); }).then(function () { HOD.busy(btn, false); });
    });

    if (cfg.authenticated) {
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', load);
        else load();
    }
})();
