// /assets/ui/js/pages/integrations.js
// Integrations (modules/integrations/index.php): Test and Disconnect go through api/integrations_api.php.
// Connect is a plain form post (a redirect to Google), so it needs nothing here.
(function () {
    'use strict';
    var DATA = HOD.data('integrations') || {};
    if (!document.querySelector('[data-integrations]') || !DATA.api) return;

    function post(action) {
        return HOD.api(DATA.api, { data: { action: action }, json: true });
    }

    HOD.actions.on('google.test', function (button) {
        HOD.busy(button, true);
        post('google_test')
            .then(function (r) { HOD.toast(r.message, 'success'); })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });

    HOD.actions.on('google.disconnect', function (button) {
        HOD.confirm({
            title: 'Disconnect Google?',
            message: 'New Meet links stop until someone connects again. Links already made keep working.',
            confirmLabel: 'Disconnect',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            post('google_disconnect')
                .then(function () { window.location.replace(window.location.pathname); })
                .catch(function () { HOD.busy(button, false); });
        });
    });
})();
