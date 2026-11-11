// /assets/ui/js/pages/mail.js
// Mail (PR 1): "Check for new mail" reads the server now for the mailbox on screen, then reloads the list.
(function () {
    'use strict';
    var data = HOD.data('mail-page') || {};

    HOD.actions.on('mail.refresh', function (button) {
        HOD.busy(button, true);
        HOD.api(data.api, { action: 'refresh', data: { box: button.dataset.box } })
            .then(function (res) {
                var added = (res.data && res.data.added) || 0;
                HOD.toast(added ? added + ' new message' + (added === 1 ? '' : 's') + '.' : 'Up to date.', 'success');
                if (added) window.location.reload();
            })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });
})();
