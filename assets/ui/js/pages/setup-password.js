// /assets/ui/js/pages/setup-password.js
// First-time password setup (auth/setup_password.php): switch between the
// set-password and find-my-email views, and post each to
// /api/setup_password_api.php as before. The API applies the password
// policy; this page only checks that the two passwords match.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/setup_password_api.php';
    var SUBS = {
        setup: 'Enter your registered email to set a new password.',
        lookup: 'Find your system email using your phone and first name.'
    };

    function $(id) { return document.getElementById(id); }

    function showAlert(tone, title, text) {
        var box = $('setup-alert');
        if (!tone) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="ui-banner ui-banner--' + tone + '" role="' + (tone === 'danger' ? 'alert' : 'status') + '">'
            + HOD.icon(tone === 'danger' ? 'circle-alert' : 'circle-check') + '<div class="ui-banner__body">'
            + (title ? '<p class="ui-banner__title">' + HOD.esc(title) + '</p>' : '')
            + (text ? '<p class="ui-banner__text break-all">' + HOD.esc(text) + '</p>' : '') + '</div></div>';
    }

    function show(view, sub) {
        $('setup-form').hidden = view !== 'setup';
        $('lookup-form').hidden = view !== 'lookup';
        $('setup-sub').textContent = sub || SUBS[view];
        var first = (view === 'setup' ? $('setup-form') : $('lookup-form')).querySelector('input:not([type="hidden"])');
        if (first) first.focus();
    }

    function submitter(form, event) { return (event && event.submitter) || form.querySelector('[type="submit"]'); }

    HOD.actions.on('setup.view', function (btn) {
        showAlert();
        show(btn.getAttribute('data-view'));
    });

    HOD.actions.on('setup.lookup', function (form, event) {
        var btn = submitter(form, event);
        showAlert();
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            $('setup-email').value = res.email || '';
            showAlert('success', 'Found it!', 'Your email is ' + (res.email || ''));
            show('setup', 'Your email is filled in; now set your password.');
            $('setup-password').focus();
        }).catch(function (err) {
            showAlert('danger', null, err.code === 'NETWORK' ? 'Network error. Please try again.' : err.message);
        }).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('setup.save', function (form, event) {
        if ($('setup-password').value !== $('setup-confirm').value) {
            showAlert('danger', null, 'Passwords do not match. Please try again.');
            return;
        }
        var btn = submitter(form, event);
        showAlert();
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            showAlert('success', null, res.message || 'Password set.');
            setTimeout(function () { window.location.href = '/auth/login'; }, 2000);
        }).catch(function (err) {
            showAlert('danger', null, err.code === 'NETWORK' ? 'Network error. Please try again.' : err.message);
            HOD.busy(btn, false);
        });
    });
})();
