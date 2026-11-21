// /assets/ui/js/pages/test-entry.js
// The QR entry to a standalone test (test.php): one step on screen at a time. Each step posts to
// /api/test_entry_api.php, which answers with the next step: choose | password | set_password | email | code |
// office | go. The server keeps the state between steps (includes/trainings/entry.php); this page only shows them.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/test_entry_api.php';
    var root = document.querySelector('[data-test-entry]');
    if (!root) return;
    var item = root.getAttribute('data-test-entry');

    function one(sel) { return root.querySelector(sel); }
    function all(sel) { return Array.prototype.slice.call(root.querySelectorAll(sel)); }

    function alertBox(text) {
        var box = document.getElementById('te-alert');
        box.innerHTML = text ? '<div class="ui-banner ui-banner--danger" role="alert">' + HOD.icon('circle-alert') +
            '<div class="ui-banner__body"><p class="ui-banner__text">' + HOD.esc(text) + '</p></div></div>' : '';
    }

    function show(step) {
        all('[data-te-step]').forEach(function (el) { el.hidden = el.getAttribute('data-te-step') !== step; });
        var panel = one('[data-te-step="' + step + '"]');
        var first = panel && panel.querySelector('input:not([type="hidden"]), button');
        if (first && step !== 'go') window.setTimeout(function () { first.focus(); }, 30);
    }

    function next(res) {
        alertBox('');
        if (res.name) all('[data-te-welcome]').forEach(function (h) { h.textContent = 'Welcome, ' + res.name; });
        if (res.step === 'choose') {
            one('[data-te-people]').innerHTML = (res.people || []).map(function (p) {
                return '<button type="button" class="ui-btn ui-btn--secondary ui-btn--block" data-action="te.choose" data-i="' + p.i + '">' + HOD.esc(p.name) + '</button>';
            }).join('');
        }
        if (res.step === 'office') one('[data-te-office]').textContent = res.message || 'Please see the church office.';
        if (res.step === 'code') one('[data-te-to]').textContent = res.to || 'your email';
        if (res.step === 'go') {
            show('go');
            window.location.href = res.go;
            return;
        }
        show(res.step);
    }

    function send(action, data, btn) {
        if (btn) HOD.busy(btn, true);
        return HOD.api(API, { action: action, json: true, data: Object.assign({ t: item }, data || {}), quiet: true })
            .then(next)
            .catch(function (err) {
                if (err && err.code === 'EXPIRED') { show('phone'); }
                var field = err && err.field ? root.querySelector('[name="' + err.field + '"]:not([hidden])') : null;
                alertBox(err && err.code === 'NETWORK' ? 'No connection. Try again.' : (err && err.message) || 'Something went wrong. Try again.');
                if (field) field.focus();
            })
            .finally(function () { if (btn) HOD.busy(btn, false); });
    }

    function value(form, name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
    }
    function submitter(form, event) { return (event && event.submitter) || form.querySelector('[type="submit"]'); }

    HOD.actions.on('te.lookup', function (form, event) {
        send('lookup', { phone: value(form, 'phone') }, submitter(form, event));
    });
    HOD.actions.on('te.choose', function (btn) {
        send('choose', { i: btn.getAttribute('data-i') }, btn);
    });
    HOD.actions.on('te.signin', function (form, event) {
        send('signin', { password: value(form, 'password') }, submitter(form, event));
    });
    HOD.actions.on('te.set', function (form, event) {
        if (value(form, 'password') !== value(form, 'confirm')) { alertBox('The two passwords differ.'); return; }
        send('set_password', { password: value(form, 'password'), confirm: value(form, 'confirm') }, submitter(form, event));
    });
    HOD.actions.on('te.email', function (form, event) {
        send('email', { email: value(form, 'email') }, submitter(form, event));
    });
    HOD.actions.on('te.no-email', function (btn) {
        send('email_skip', {}, btn);
    });
    HOD.actions.on('te.forgot', function (btn) {
        send('forgot', {}, btn);
    });
    HOD.actions.on('te.reset', function (form, event) {
        if (value(form, 'password') !== value(form, 'confirm')) { alertBox('The two passwords differ.'); return; }
        send('reset', { code: value(form, 'code'), password: value(form, 'password'), confirm: value(form, 'confirm') }, submitter(form, event));
    });
    HOD.actions.on('te.restart', function () {
        alertBox('');
        var phone = document.getElementById('te-phone');
        if (phone) phone.value = '';
        show('phone');
    });
})();
