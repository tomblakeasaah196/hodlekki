// /assets/ui/js/pages/verify.js
// Document verification (verify.php): post the code to
// /api/public_verify_api.php as before and show the stamped record, or say
// why it could not be verified. A Trainings certificate's reply carries its
// own labels and, when it was revoked or replaced, revoked: true (shown red).
// ?code=… (a certificate's QR) arrives filled in and is checked at once.
(function () {
    'use strict';

    var HOD = window.HOD;
    var FIELDS = {
        'res-type': 'document_type', 'res-ref': 'reference', 'res-dept': 'department', 'res-amount': 'amount',
        'res-by': 'validated_by', 'res-role': 'role', 'res-time': 'timestamp', 'res-level': 'stamp_level',
        'res-hash': 'cryptographic_hash'
    };

    function $(id) { return document.getElementById(id); }

    // The labels as the page prints them, to put back for the next document.
    var DEFAULT_LABELS = {};
    document.querySelectorAll('[data-label]').forEach(function (dt) { DEFAULT_LABELS[dt.getAttribute('data-label')] = dt.textContent; });

    function showStatus(data) {
        var labels = data.labels || {};
        document.querySelectorAll('[data-label]').forEach(function (dt) {
            var key = dt.getAttribute('data-label');
            dt.textContent = labels[key] || DEFAULT_LABELS[key];
        });
        var banner = $('res-banner');
        banner.classList.toggle('bg-success-soft', !data.revoked);
        banner.classList.toggle('text-success', !data.revoked);
        banner.classList.toggle('bg-danger-soft', !!data.revoked);
        banner.classList.toggle('text-danger', !!data.revoked);
        $('res-status').textContent = data.revoked ? 'Not valid: ' + String(data.verification_status || 'revoked').toLowerCase() : 'Valid & Binding';
    }

    HOD.actions.on('verify.upper', function (input) {
        var at = input.selectionStart;
        input.value = input.value.toUpperCase();
        try { input.setSelectionRange(at, at); } catch (e) { /* not a text field */ }
    });

    HOD.actions.on('verify.check', function (form, event) {
        var btn = (event && event.submitter) || form.querySelector('[type="submit"]');
        HOD.busy(btn, true);
        HOD.api('/api/public_verify_api.php', { data: new FormData(form), quiet: true }).then(function (res) {
            if (!res.data) throw new Error('Invalid verification code.');
            Object.keys(FIELDS).forEach(function (id) { $(id).textContent = res.data[FIELDS[id]] == null ? '' : String(res.data[FIELDS[id]]); });
            showStatus(res.data);
            $('verify-search').hidden = true;
            $('verify-result').hidden = false;
            $('verify-result').scrollIntoView({ block: 'start', behavior: 'smooth' });
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK'
                ? 'Server connection failed. Please check your internet and try again.'
                : (err.message || 'Invalid verification code.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    var form = $('verify-form');
    if (form && form.hasAttribute('data-autoverify') && typeof form.requestSubmit === 'function') {
        form.requestSubmit();
    }

    HOD.actions.on('verify.again', function () {
        $('verify-code').value = '';
        $('verify-result').hidden = true;
        $('verify-search').hidden = false;
        $('verify-code').focus();
    });
})();
