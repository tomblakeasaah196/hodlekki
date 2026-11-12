// /assets/ui/js/pages/trainings.js
// Trainings, the learner's side (modules/trainings/index.php): join from the
// catalogue, leave a training you joined, mark a video / audio / reading
// done, hand in an assignment. The page is rendered on the server, so each
// change reloads it; the toast is carried across the reload.
(function () {
    'use strict';

    var API = '/api/trainings_api.php';
    var FLASH = 'hod-trn-flash';

    function flash(message) {
        try { window.sessionStorage.setItem(FLASH, message); } catch (e) { /* private mode: no toast after reload */ }
        window.location.reload();
    }

    try {
        var waiting = window.sessionStorage.getItem(FLASH);
        if (waiting) {
            window.sessionStorage.removeItem(FLASH);
            HOD.toast(waiting, 'success');
        }
    } catch (e) { /* storage blocked */ }

    function call(action, data, button) {
        HOD.busy(button, true);
        return HOD.api(API, { action: action, data: data })
            .finally(function () { HOD.busy(button, false); });
    }

    HOD.actions.on('trn.join', function (button) {
        call('join', { training_id: button.getAttribute('data-training') }, button).then(function () {
            window.location.href = '/modules/trainings/?t=' + encodeURIComponent(button.getAttribute('data-training'));
        }).catch(function () {});
    });

    HOD.actions.on('trn.leave', function (button) {
        HOD.confirm({ title: 'Leave this training?', message: 'You can join again later.', confirmLabel: 'Leave training', tone: 'danger' })
            .then(function (yes) {
                if (!yes) return;
                call('leave', { enrolment_id: button.getAttribute('data-enrolment') }, button).then(function () {
                    try { window.sessionStorage.setItem(FLASH, 'You left the training.'); } catch (e) { /* ignore */ }
                    window.location.href = '/modules/trainings/';
                }).catch(function () {});
            });
    });

    HOD.actions.on('trn.done', function (button) {
        var done = button.getAttribute('data-done') === '1';
        call('item_done', { item_id: button.getAttribute('data-item'), done: done ? 1 : 0 }, button).then(function (res) {
            flash(res.completed ? 'Training complete. Well done!' : (done ? 'Marked done.' : 'Marked not done.'));
        }).catch(function () {});
    });

    HOD.actions.on('trn.submit', function (form) {
        var button = form.querySelector('button[type="submit"]');
        var answers = Array.prototype.map.call(form.querySelectorAll('textarea[name="answers[]"]'), function (t) { return t.value; });
        HOD.busy(button, true);
        HOD.api(API, { action: 'submit_work', data: { item_id: form.elements.item_id.value, answers: answers } })
            .then(function () { flash('Handed in. Your tutor marks it next.'); })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    });
})();
