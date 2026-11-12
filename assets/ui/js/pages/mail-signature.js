// /assets/ui/js/pages/mail-signature.js
// My signature (modules/mail/signature.php): a live preview of the church's design with the numbers being typed,
// then save. Previews come from api/mail_compose_api.php (sig_preview), built on the server from escaped values.
(function () {
    'use strict';
    var DATA = HOD.data('mail-signature') || {};
    var root = document.querySelector('[data-signature]');
    if (!root || !DATA.api) return;
    var numbers = root.querySelector('[data-sig-numbers]');
    var church = root.querySelector('[data-sig-church]');
    var timer = null;
    var seq = 0;

    /** Inline styles move to CSSOM: this strict-CSP page refuses style attributes from markup */
    function paint(target, html) {
        target.textContent = '';
        if (!html) return;
        var doc = new DOMParser().parseFromString('<div>' + html + '</div>', 'text/html');
        var styled = [];
        doc.body.querySelectorAll('[style]').forEach(function (n) { styled.push([n, n.getAttribute('style')]); });
        styled.forEach(function (pair) { pair[0].removeAttribute('style'); });
        var originals = doc.body.firstChild.querySelectorAll('*');
        var copy = document.importNode(doc.body.firstChild, true);
        var copies = copy.querySelectorAll('*');
        var index = new Map();
        Array.prototype.forEach.call(originals, function (n, i) { index.set(n, copies[i]); });
        styled.forEach(function (pair) { var c = index.get(pair[0]); if (c) c.style.cssText = pair[1]; });
        while (copy.firstChild) target.appendChild(copy.firstChild);
    }
    function values(form) {
        var out = {};
        if (!form) return out;
        Array.prototype.forEach.call(form.elements, function (el) {
            if (!el.name) return;
            out[el.name] = el.type === 'checkbox' ? el.checked : el.value;
        });
        return out;
    }
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var mine = ++seq;
            var body = Object.assign({ action: 'sig_preview' }, values(numbers));
            if (DATA.admin && church) body.church = values(church);
            HOD.api(DATA.api, { data: body, json: true, quiet: true }).then(function (r) {
                if (mine !== seq) return;
                paint(root.querySelector('[data-sig-preview="full"]'), r.full);
                paint(root.querySelector('[data-sig-preview="slim"]'), r.slim);
            }).catch(function () { /* the next keystroke tries again */ });
        }, 250);
    }
    function saveForm(form, action, extra) {
        var button = form.querySelector('[type="submit"]');
        HOD.busy(button, true);
        HOD.api(DATA.api, { data: Object.assign({ action: action }, values(form), extra || {}), json: true })
            .then(function (r) { HOD.toast(r.message, 'success'); preview(); })
            .catch(function () {})
            .finally(function () { HOD.busy(button, false); });
    }

    [numbers, church].forEach(function (form) {
        if (!form) return;
        form.addEventListener('input', preview);
        form.addEventListener('change', preview);
    });
    numbers.addEventListener('submit', function (e) {
        e.preventDefault();
        saveForm(numbers, 'sig_numbers');
    });
    if (church) {
        church.addEventListener('submit', function (e) {
            e.preventDefault();
            saveForm(church, 'sig_church', { show_logo: church.elements.show_logo.checked });
        });
    }
    HOD.actions.on('sig.reset', function (button) {
        HOD.confirm({ title: 'Reset church part?', message: 'Everyone\'s signature goes back to the church name, site and logo.', confirmLabel: 'Reset' })
            .then(function (ok) {
                if (!ok) return;
                HOD.busy(button, true);
                HOD.api(DATA.api, { data: { action: 'sig_church_reset' }, json: true })
                    .then(function (r) { HOD.toast(r.message, 'success'); window.location.reload(); })
                    .catch(function () {})
                    .finally(function () { HOD.busy(button, false); });
            });
    });

    preview();
})();
