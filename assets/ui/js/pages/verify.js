// /assets/ui/js/pages/verify.js
// Document verification (verify.php): post the code to
// /api/public_verify_api.php as before and show the stamped record, or say
// why it could not be verified.
(function () {
    'use strict';

    var HOD = window.HOD;
    var FIELDS = {
        'res-type': 'document_type', 'res-ref': 'reference', 'res-dept': 'department', 'res-amount': 'amount',
        'res-by': 'validated_by', 'res-role': 'role', 'res-time': 'timestamp', 'res-level': 'stamp_level',
        'res-hash': 'cryptographic_hash'
    };

    function $(id) { return document.getElementById(id); }

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
            $('verify-search').hidden = true;
            $('verify-result').hidden = false;
            $('verify-result').scrollIntoView({ block: 'start', behavior: 'smooth' });
        }).catch(function (err) {
            HOD.toast(err.code === 'NETWORK'
                ? 'Server connection failed. Please check your internet and try again.'
                : (err.message || 'Invalid verification code.'), 'danger');
        }).then(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('verify.again', function () {
        $('verify-code').value = '';
        $('verify-result').hidden = true;
        $('verify-search').hidden = false;
        $('verify-code').focus();
    });
})();
