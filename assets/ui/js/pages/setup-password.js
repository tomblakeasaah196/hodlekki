// /assets/ui/js/pages/setup-password.js
// First-time password setup (auth/setup_password.php): post the form to
// /api/setup_password_api.php. The email or the phone number on the record
// finds the account; when two accounts share the number, the API answers
// NAME_NEEDED and the first-name field appears. The API applies the password
// policy; this page only checks that the two passwords match.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/setup_password_api.php';

    function $(id) { return document.getElementById(id); }

    function showAlert(tone, text) {
        var box = $('setup-alert');
        if (!tone) { box.innerHTML = ''; return; }
        box.innerHTML = '<div class="ui-banner ui-banner--' + tone + '" role="' + (tone === 'danger' ? 'alert' : 'status') + '">'
            + HOD.icon(tone === 'danger' ? 'circle-alert' : 'circle-check') + '<div class="ui-banner__body">'
            + '<p class="ui-banner__text">' + HOD.esc(text) + '</p></div></div>';
    }

    HOD.actions.on('setup.save', function (form, event) {
        if ($('setup-password').value !== $('setup-confirm').value) {
            showAlert('danger', 'Passwords do not match. Please try again.');
            return;
        }
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        showAlert();
        HOD.busy(btn, true);
        HOD.api(API, { data: new FormData(form), quiet: true }).then(function (res) {
            showAlert('success', res.message || 'Password set.');
            setTimeout(function () { window.location.href = '/auth/login'; }, 2000);
        }).catch(function (err) {
            showAlert('danger', err.code === 'NETWORK' ? 'Network error. Please try again.' : err.message);
            HOD.busy(btn, false);
            if (err.code === 'NAME_NEEDED') {
                $('setup-name').hidden = false;
                $('setup-first').required = true;
                $('setup-first').focus();
            }
        });
    });
})();
