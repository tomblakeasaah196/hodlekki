// /assets/ui/js/pages/mail-settings.js
// My mail settings (modules/mail/settings.php): each change is saved at once through api/mail_team_api.php.
(function () {
    'use strict';
    var DATA = HOD.data('mail-settings') || {};
    var root = document.querySelector('[data-mail-settings]');
    if (!root || !DATA.api) return;

    function save(body, control, undo) {
        control.disabled = true;
        HOD.api(DATA.api, { data: body, json: true })
            .then(function () { HOD.toast('Saved.', 'success'); })
            .catch(function () { undo(); })
            .finally(function () { control.disabled = false; });
    }

    root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.matches('[data-push]')) {
            var before = t.getAttribute('data-was') || t.querySelector('option[selected]').value;
            save({ action: 'prefs_push', box: Number(t.getAttribute('data-push')), push: t.value }, t, function () { t.value = before; });
            t.setAttribute('data-was', t.value);
        } else if (t.matches('[data-general="summary"]')) {
            save({ action: 'prefs_general', summary: t.checked }, t, function () { t.checked = !t.checked; });
        } else if (t.matches('[data-personal]')) {
            var personal = {};
            personal[t.getAttribute('data-personal')] = t.checked;
            save({ action: 'prefs_general', personal: personal }, t, function () { t.checked = !t.checked; });
        }
    });
})();
