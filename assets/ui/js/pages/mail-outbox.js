// /assets/ui/js/pages/mail-outbox.js
// Outbox (modules/mail/outbox.php): cancel a scheduled send (it goes back to a draft) or delete a draft.
(function () {
    'use strict';
    var DATA = HOD.data('mail-outbox') || {};
    if (!DATA.api) return;

    function call(button, action, done) {
        HOD.busy(button, true);
        HOD.api(DATA.api, { data: { action: action, outboxId: button.dataset.id }, json: true })
            .then(done)
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    }

    HOD.actions.on('outbox.cancel', function (button) {
        call(button, 'undo', function (r) {
            HOD.toast(r.undone ? 'Back to drafts.' : r.message, r.undone ? 'success' : 'warning');
            window.location.reload();
        });
    });

    HOD.actions.on('outbox.delete', function (button) {
        HOD.confirm({ title: 'Delete this message?', message: 'It leaves the server\'s Drafts too.', confirmLabel: 'Delete', tone: 'danger' })
            .then(function (ok) {
                if (!ok) return;
                call(button, 'discard', function (r) {
                    HOD.toast(r.message, r.discarded ? 'success' : 'warning');
                    var row = button.closest('[data-row]');
                    if (r.discarded && row) row.remove();
                });
            });
    });
})();
