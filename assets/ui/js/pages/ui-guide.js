// /assets/ui/js/pages/ui-guide.js
// The Design System page (modules/ui_guide). Also the pattern every rebuilt
// page follows: no inline script or handler — markup names an action with
// data-action, and this file says what the action does.
(function () {
    'use strict';

    var HOD = window.HOD;
    if (!HOD || !HOD.actions) return;

    HOD.actions.on('guide.toast', function (button) {
        var tone = button.getAttribute('data-tone');
        if (tone === 'danger') {
            HOD.toast("We couldn't save the roster. Check your connection and try again — nothing was lost.", 'danger');
        } else if (tone === 'undo') {
            HOD.toast('Chiamaka Eze moved to the archive.', {
                tone: 'success',
                action: { label: 'Undo', run: function () { HOD.toast('Chiamaka Eze is back on the active list.', 'info'); } }
            });
        } else {
            HOD.toast('Attendance saved for Sunday 15 November.', 'success');
        }
    });

    HOD.actions.on('guide.confirm', function () {
        HOD.confirm({
            title: 'Delete Folasade Bello?',
            message: 'Her attendance history and follow-up notes are deleted with her record. This cannot be undone.',
            confirmLabel: 'Delete member',
            tone: 'danger',
            requireText: 'DELETE'
        }).then(function (yes) {
            HOD.toast(yes ? 'Folasade Bello deleted (not really — this is the design system).' : 'Nothing was deleted.', yes ? 'success' : 'info');
        });
    });

    HOD.actions.on('guide.prompt', function () {
        HOD.prompt({
            title: 'Send this requisition back',
            message: 'The requester sees your reason and can edit and resubmit.',
            label: 'Reason',
            multiline: true,
            required: true,
            confirmLabel: 'Send back'
        }).then(function (reason) {
            if (reason) HOD.toast('Sent back with your reason.', 'success');
        });
    });

    HOD.actions.on('guide.busy', function (button) {
        HOD.busy(button, true);
        setTimeout(function () {
            HOD.busy(button, false);
            HOD.toast('Changes saved.', 'success');
        }, 1400);
    });

    HOD.actions.on('guide.save', function (form) {
        var values = HOD.form.values(form);
        HOD.toast('Saved ' + values.first_name + ' ' + values.last_name + ' (demo only).', 'success');
        form.reset();
    });

    // Chips and segmented controls in the guide toggle like the real ones.
    document.addEventListener('click', function (event) {
        var chip = event.target.closest('.ui-chips .ui-chip, .ui-segmented__item');
        if (!chip) return;
        var group = chip.parentElement;
        group.querySelectorAll('[aria-pressed]').forEach(function (el) { el.setAttribute('aria-pressed', el === chip ? 'true' : 'false'); });
    });
})();
