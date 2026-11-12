// /assets/ui/js/pages/change-password.js
// Change password (auth/change_password.php): show/hide the three fields,
// check the two new ones match, and post to /api/profile_api.php
// (change_password, signing other devices out). A forced change then goes to
// the page the member was opening; otherwise back to My Profile → Security.
(function () {
    'use strict';

    var HOD = window.HOD;
    var cfg = HOD.data('pw-config') || {};
    var FIELDS = ['pw-current', 'pw-new', 'pw-confirm'];

    HOD.actions.on('pw.show', function (box) {
        FIELDS.forEach(function (id) { document.getElementById(id).type = box.checked ? 'text' : 'password'; });
    });

    HOD.actions.on('pw.save', function (form, event) {
        if (document.getElementById('pw-new').value !== document.getElementById('pw-confirm').value) {
            HOD.toast('The new password and its confirmation do not match.', 'danger');
            return;
        }
        var btn = (event && event.submitter) || document.getElementById('pw-submit');
        HOD.busy(btn, true);
        HOD.api('/api/profile_api.php', { data: new FormData(form), quiet: true }).then(function () {
            HOD.toast('Password updated. Taking you in...', 'success');
            setTimeout(function () { window.location.href = cfg.after || '/index.php'; }, 1200);
        }).catch(function (err) {
            HOD.busy(btn, false);
            HOD.toast(err.code === 'NETWORK' ? 'Network error. Please try again.' : (err.message || 'Could not update the password.'), 'danger');
        });
    });
})();
