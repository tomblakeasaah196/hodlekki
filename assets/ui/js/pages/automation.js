// /assets/ui/js/pages/automation.js
// The Automation tab (Embrace, Assimilation, Charis): one panel, rendered on the
// server (includes/campaigns/automation_tab.php). Every action goes through
// /api/automation_api.php; answers that change the panel carry it again and it
// is swapped in place. The email preview is a sandboxed frame on
// /api/email_preview.php (its own CSP; never srcdoc).
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/automation_api.php';
    var GSM = /^[A-Za-z0-9 \r\n@£$¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ!"#¤%&'()*+,\-./:;<=>?¡ÄÖÑÜ§¿äöñüà^{}\\[~\]|€]*$/;

    function panel(module) { return document.getElementById('automation-' + module); }

    function swap(module, res) {
        var el = panel(module);
        if (el && res && res.html) el.outerHTML = res.html;
    }

    function post(module, action, data) {
        return HOD.api(API, { action: action, data: Object.assign({ module: module }, data || {}) });
    }

    function save(form) {
        var module = form.getAttribute('data-module');
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        post(module, 'save', HOD.form.values(form)).then(function (res) {
            swap(module, res);
            HOD.toast(res.message || 'Saved.', 'success');
        }).catch(function () {
            HOD.busy(button, false);
        });
    }

    function askOn(button) {
        var module = button.getAttribute('data-module');
        var dialogId = 'auto-' + module + '-enable';
        var line = document.querySelector('#' + dialogId + ' [data-automation-enable-line]');
        HOD.busy(button, true);
        post(module, 'switch_preview').then(function (res) {
            line.textContent = res.line || '';
            HOD.dialog.open(dialogId, button);
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function enable(button) {
        var module = button.getAttribute('data-module');
        var dialogId = 'auto-' + module + '-enable';
        var picked = document.querySelector('input[name="' + dialogId.replace('-enable', '') + '-start"]:checked');
        HOD.busy(button, true);
        post(module, 'enable', { start: picked ? picked.value : 'fresh' }).then(function (res) {
            HOD.dialog.close(dialogId);
            swap(module, res);
            HOD.toast(res.message || 'Switched on.', 'success');
        }).catch(function () { HOD.busy(button, false); });
    }

    function off(button) {
        var module = button.getAttribute('data-module');
        HOD.confirm({
            title: 'Switch it off?',
            message: 'Nothing more is sent until it is switched on again.',
            confirmLabel: 'Switch off',
            tone: 'danger'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            post(module, 'disable').then(function (res) {
                swap(module, res);
                HOD.toast(res.message || 'Switched off.', 'success');
            }).catch(function () { HOD.busy(button, false); });
        });
    }

    function preview(button) {
        var module = button.getAttribute('data-module');
        var dialogId = 'auto-' + module + '-preview';
        var frame = document.querySelector('#' + dialogId + ' [data-automation-frame]');
        var name = document.querySelector('#' + dialogId + ' [data-automation-preview-name]');
        var row = button.closest('[data-automation-step]');
        var title = row ? row.querySelector('.ui-h3') : null;
        var week = button.getAttribute('data-week') || '1';
        var series = row && row.querySelector('details');
        name.textContent = (title ? title.textContent : '') + (series ? ', week ' + week : '');
        frame.src = '/api/email_preview.php?module=' + encodeURIComponent(module)
            + '&step=' + encodeURIComponent(button.getAttribute('data-step'))
            + '&week=' + encodeURIComponent(week);
        HOD.dialog.open(dialogId, button);
    }

    function width(button) {
        var dialog = button.closest('dialog');
        var frame = dialog.querySelector('[data-automation-frame]');
        var narrow = button.getAttribute('data-width') === '375';
        frame.classList.toggle('max-w-[375px]', narrow);
        frame.classList.toggle('max-w-[640px]', !narrow);
        dialog.querySelectorAll('[data-action="automation.width"]').forEach(function (b) {
            b.setAttribute('aria-pressed', b === button ? 'true' : 'false');
        });
    }

    function test(button) {
        HOD.busy(button, true);
        post(button.getAttribute('data-module'), 'test', {
            step: button.getAttribute('data-step'),
            week: button.getAttribute('data-week') || '1'
        }).then(function (res) {
            HOD.toast(res.message || 'Test sent.', 'success');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function who(button) {
        var item = button.closest('[data-automation-run]');
        var list = item.querySelector('[data-automation-run-people]');
        var open = button.getAttribute('aria-expanded') === 'true';
        if (open) {
            list.hidden = true;
            button.setAttribute('aria-expanded', 'false');
            return;
        }
        HOD.busy(button, true);
        post(button.getAttribute('data-module'), 'run_people', { run: button.getAttribute('data-run') }).then(function (res) {
            var rows = (res.people || []).map(function (p) {
                return '<tr><td>' + HOD.esc(p.name) + '</td><td>' + HOD.esc(p.step) + '</td><td>' + HOD.esc(p.result) + '</td></tr>';
            }).join('');
            list.innerHTML = rows
                ? '<div class="ui-table-wrap"><table class="ui-table ui-table--compact"><thead><tr><th scope="col">Person</th><th scope="col">Step</th><th scope="col">Result</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
                : '<p class="ui-meta">Nobody on record.</p>';
            list.hidden = false;
            button.setAttribute('aria-expanded', 'true');
        }).catch(function () {}).finally(function () { HOD.busy(button, false); });
    }

    function smsCount(field) {
        var target = document.getElementById(field.getAttribute('data-count-target'));
        if (!target) return;
        var text = field.value || '';
        var len = Array.from(text).length;
        var gsm = GSM.test(text);
        var one = gsm ? 160 : 70;
        var many = gsm ? 153 : 67;
        var pages = len === 0 ? 0 : (len <= one ? 1 : Math.ceil(len / many));
        target.textContent = len + ' characters · ' + pages + (pages === 1 ? ' text' : ' texts');
    }

    HOD.ready(function () {
        if (!document.querySelector('[data-automation]')) return;
        HOD.actions.on('automation.save', save);
        HOD.actions.on('automation.on', askOn);
        HOD.actions.on('automation.enable', enable);
        HOD.actions.on('automation.off', off);
        HOD.actions.on('automation.preview', preview);
        HOD.actions.on('automation.width', width);
        HOD.actions.on('automation.test', test);
        HOD.actions.on('automation.run', who);
        HOD.actions.on('automation.sms-count', smsCount);
    });
})();
