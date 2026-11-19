// /assets/ui/js/pages/training-live.js
// Trainings' live classes on Meet (docs/INTEGRATIONS.md):
// - the learner's Trainings page: each press of Join class is logged for attendance; the link opens as usual
//   (in a new tab), so the log never stands between the learner and the class;
// - the Academy's Meet page (modules/academy/live.php): make, update or remove the Meet link, bring attendance
//   in, mark present or absent, and place the participants under "Needs a look".
(function () {
    'use strict';
    var API = '/api/training_live_api.php';

    document.addEventListener('click', function (e) {
        var link = e.target.closest ? e.target.closest('[data-live-join]') : null;
        if (!link) return;
        HOD.api(API, { data: { action: 'join', item: Number(link.getAttribute('data-live-join')) }, json: true, quiet: true }).catch(function () {});
    });

    var root = document.querySelector('[data-training-live]');
    var DATA = HOD.data('training-live') || {};
    if (!root || !DATA.item) return;

    // Arrived from the builder after saving an online class: say how the Meet link went
    try {
        var arrived = window.sessionStorage.getItem('hod:live-flash');
        if (arrived) {
            window.sessionStorage.removeItem('hod:live-flash');
            HOD.toast(arrived, /^Saved\. (The Meet link is ready\.)?$/.test(arrived) ? 'success' : 'warning');
        }
    } catch (e) { /* storage blocked */ }

    function post(action, extra, button) {
        var body = { action: action, item: DATA.item };
        Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
        if (button) HOD.busy(button, true);
        return HOD.api(DATA.api || API, { data: body, json: true })
            .then(function (r) {
                if (r && r.message) HOD.toast(r.message, 'success');
                window.location.reload();
            })
            .catch(function () { if (button) HOD.busy(button, false); });
    }

    HOD.actions.on('live.create', function (b) { post('meet_create', {}, b); });
    HOD.actions.on('live.update', function (b) { post('meet_update', {}, b); });
    HOD.actions.on('live.sync', function (b) { post('sync', {}, b); });
    HOD.actions.on('live.cancel', function (b) {
        HOD.confirm({ title: 'Remove the Meet link?', message: 'The calendar event is deleted. Learners lose the link.',
            confirmLabel: 'Remove link', tone: 'danger' }).then(function (yes) { if (yes) post('meet_cancel', {}, b); });
    });
    HOD.actions.on('live.present', function (b) {
        post('present', { user: Number(b.dataset.user), present: b.dataset.present === '1' }, b);
    });
    HOD.actions.on('live.assign', function (b) {
        var pick = root.querySelector('[data-live-pick="' + b.dataset.row + '"]');
        if (!pick || !pick.value) { HOD.toast('Choose the learner first.', 'warning'); if (pick) pick.focus(); return; }
        post('assign', { row: Number(b.dataset.row), user: Number(pick.value) }, b);
    });
    HOD.actions.on('live.ignore', function (b) { post('ignore', { row: Number(b.dataset.row) }, b); });
    HOD.actions.on('live.reopen', function (b) { post('reopen', { row: Number(b.dataset.row) }, b); });
    HOD.actions.on('live.copy', function (b) {
        var url = b.dataset.url || '';
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () { HOD.toast('Link copied.', 'success'); }, function () { HOD.toast(url, 'info'); });
        } else {
            HOD.toast(url, 'info');
        }
    });
})();
