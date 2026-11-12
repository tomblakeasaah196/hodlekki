// /assets/ui/js/pages/academy.js
// HOD Academy (modules/academy/index.php): the length wizard that starts a
// training, marking handed-in work, confirming graduates. Everything goes to
// /api/trainings_api.php; the page is rendered on the server, so a change
// reloads it with its toast carried across.
(function () {
    'use strict';

    var API = '/api/trainings_api.php';
    var FLASH = 'hod-trn-flash';
    var data = HOD.data('acd-data') || { work: [] };

    function flash(message, href) {
        try { window.sessionStorage.setItem(FLASH, message); } catch (e) { /* no toast after reload */ }
        if (href) window.location.href = href; else window.location.reload();
    }
    try {
        var waiting = window.sessionStorage.getItem(FLASH);
        if (waiting) { window.sessionStorage.removeItem(FLASH); HOD.toast(waiting, 'success'); }
    } catch (e) { /* storage blocked */ }

    function values(form) {
        var out = {};
        new FormData(form).forEach(function (v, k) {
            if (/\[\]$/.test(k)) {
                k = k.slice(0, -2);
                (out[k] = out[k] || []).push(v);
            } else {
                out[k] = v;
            }
        });
        return out;
    }

    function fieldError(form, err) {
        if (err && err.field && form.elements[err.field]) {
            var e = {};
            e[err.field] = err.message;
            HOD.form.setErrors(form, e);
        }
    }

    /* ----------------------------------------------------------- wizard */

    var TEMPLATE_NAMES = { membership: 'Membership Class', maturity: 'Maturity Class' };

    function show(el, on) {
        if (!el) return;
        el.hidden = !on;
        el.querySelectorAll('input, select, textarea').forEach(function (c) { c.disabled = !on; });
    }

    function setLabel(input, text) {
        var label = input && input.id ? document.querySelector('label[for="' + CSS.escape(input.id) + '"]') : null;
        if (label && label.firstChild) label.firstChild.nodeValue = text;
    }

    function syncWizard() {
        var form = document.getElementById('acd-new-form');
        if (!form) return;
        var template = (form.querySelector('input[name="template"]:checked') || {}).value || '';
        var length = template ? 'weeks' : ((form.querySelector('input[name="length"]:checked') || {}).value || 'weeks');
        var title = form.elements.title;
        var lastAuto = form.getAttribute('data-auto-title') || '';
        if (template && (title.value === '' || title.value === lastAuto)) {
            title.value = TEMPLATE_NAMES[template];
            form.setAttribute('data-auto-title', title.value);
        } else if (!template && title.value === lastAuto) {
            title.value = '';
            form.setAttribute('data-auto-title', '');
        }
        show(form.querySelector('[data-acd-length]'), !template);
        show(form.querySelector('.acd-dated'), length !== 'self_paced');
        show(form.querySelector('.acd-count'), !template && (length === 'days' || length === 'weeks'));
        var count = form.elements.count;
        if (count && length === 'days') {
            setLabel(count, 'How many days');
            count.min = 2; count.max = 14;
            if (Number(count.value) < 2) count.value = 2;
        } else if (count) {
            setLabel(count, 'How many weeks');
            count.min = 1; count.max = 26;
        }
    }
    HOD.actions.on('acd.wizard', syncWizard);
    syncWizard();

    HOD.actions.on('acd.create', function (form) {
        var button = document.querySelector('button[form="acd-new-form"]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'create', data: values(form), quiet: false })
            .then(function (res) { flash('Training created. Add its items next.', '/modules/academy/training?id=' + encodeURIComponent(res.id)); })
            .catch(function (err) { fieldError(form, err); })
            .finally(function () { HOD.busy(button, false); });
    });

    /* ----------------------------------------------------------- marking */

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined) n.textContent = text;
        return n;
    }

    HOD.actions.on('acd.mark', function (button) {
        var w = data.work[Number(button.getAttribute('data-work'))];
        var form = document.getElementById('acd-mark-form');
        if (!w || !form) return;
        form.reset();
        form.elements.enrolment_id.value = w.enrolment_id;
        form.elements.item_id.value = w.item_id;
        form.elements.mark.max = w.max_mark;
        setLabel(form.elements.mark, 'Mark out of ' + w.max_mark);
        form.querySelector('[data-acd-mark-who]').textContent = w.name + ' · ' + w.item + ' · pass mark ' + w.pass_mark;
        var box = form.querySelector('[data-acd-mark-answers]');
        box.textContent = '';
        var prompts = w.prompts.length ? w.prompts : ['Answer'];
        prompts.forEach(function (p, i) {
            var div = el('div', 'ui-card ui-card__body');
            div.appendChild(el('p', 'ui-label', p));
            div.appendChild(el('p', 'text-ink whitespace-pre-line', w.answers[i] || ''));
            box.appendChild(div);
        });
        HOD.dialog.open('acd-mark');
    });

    HOD.actions.on('acd.saveMark', function (form) {
        var button = document.querySelector('button[form="acd-mark-form"]');
        HOD.busy(button, true);
        HOD.api(API, { action: 'mark_work', data: values(form) })
            .then(function (res) { flash(res.passed ? 'Marked. It counts as done.' : 'Marked. It goes back to them.'); })
            .catch(function (err) { fieldError(form, err); })
            .finally(function () { HOD.busy(button, false); });
    });

    /* ----------------------------------------------------------- confirming */

    HOD.actions.on('acd.all', function (box) {
        var form = box.closest('form');
        form.querySelectorAll('input[name="enrolment_ids[]"]').forEach(function (c) { c.checked = box.checked; });
    });

    HOD.actions.on('acd.confirm', function (form) {
        var ids = Array.prototype.map.call(form.querySelectorAll('input[name="enrolment_ids[]"]:checked'), function (c) { return c.value; });
        if (!ids.length) { HOD.toast('Tick the people to confirm.', 'warning'); return; }
        var button = form.querySelector('button[type="submit"]');
        HOD.confirm({
            title: ids.length === 1 ? 'Confirm 1 graduate?' : 'Confirm ' + ids.length + ' graduates?',
            message: 'Membership Class graduates become Members.',
            confirmLabel: 'Confirm graduates'
        }).then(function (yes) {
            if (!yes) return;
            HOD.busy(button, true);
            HOD.api(API, { action: 'confirm', data: { enrolment_ids: ids } })
                .then(function (res) { flash(res.confirmed === 1 ? '1 graduate confirmed.' : res.confirmed + ' graduates confirmed.'); })
                .catch(function () {})
                .finally(function () { HOD.busy(button, false); });
        });
    });
})();
