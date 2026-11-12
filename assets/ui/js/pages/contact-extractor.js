// /assets/ui/js/pages/contact-extractor.js
// The Contact extractor (modules/contact_extractor): loads every unique phone
// number on request, shows where they came from, and splits them into batches
// with a chosen separator, each one copyable.
// Data: /api/contact_extractor_api.php (fetch_contacts).
(function () {
    'use strict';

    var API = '/api/contact_extractor_api.php';
    var SEPARATORS = { semicolon: ';', comma: ',', newline: '\n' };
    var contacts = [];
    var sep = 'semicolon';
    var batches = [];

    function $(s) { return document.querySelector(s); }
    function num(n) { return HOD.format.number(n || 0); }

    function copy(text, done) {
        function fallback() {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.className = 'sr-only';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); HOD.toast(done, 'success'); } catch (e) { HOD.toast('Copy did not work. Try again.', 'warning'); }
            ta.remove();
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { HOD.toast(done, 'success'); }, fallback);
        } else fallback();
    }

    /** Roughly equal batches, in order. */
    function split(list, n) {
        n = Math.max(1, Math.min(50, n || 1, list.length || 1));
        var size = Math.ceil(list.length / n), out = [];
        for (var i = 0; i < list.length; i += size) out.push(list.slice(i, i + size));
        return out;
    }

    function render() {
        batches = split(contacts, parseInt($('#ce-count').value, 10));
        $('#ce-batches').innerHTML = batches.map(function (b, i) {
            return '<li class="flex items-center justify-between gap-3 px-4 py-3 lg:px-6">' +
                '<div><p class="font-medium text-ink">Batch ' + (i + 1) + '</p><p class="ui-meta ui-num">' + num(b.length) + ' numbers · ' + HOD.esc(b[0]) + ' to ' + HOD.esc(b[b.length - 1]) + '</p></div>' +
                '<button type="button" class="ui-btn ui-btn--secondary ui-btn--sm" data-action="ce.copy" data-i="' + i + '">' + HOD.icon('copy') + '<span>Copy</span></button></li>';
        }).join('') || '<li class="px-4 py-3 lg:px-6"><p class="ui-meta">No numbers to split.</p></li>';
    }

    HOD.actions.on('ce.load', function (btn) {
        HOD.busy(btn, true);
        HOD.api(API, { action: 'fetch_contacts' }).then(function (res) {
            contacts = res.contacts || [];
            var s = res.source_breakdown || {};
            $('[data-ce="total"]').textContent = num(res.count);
            ['users', 'registrations', 'mobilization', 'guests'].forEach(function (k) { $('[data-ce="' + k + '"]').textContent = num(s[k]); });
            $('#ce-start').hidden = true;
            $('#ce-body').hidden = false;
            render();
        }).catch(function () {}).finally(function () { HOD.busy(btn, false); });
    });

    HOD.actions.on('ce.sep', function (btn) {
        sep = btn.getAttribute('data-sep');
        document.querySelectorAll('[data-action="ce.sep"]').forEach(function (b) { b.setAttribute('aria-pressed', String(b === btn)); });
    });
    HOD.actions.on('ce.split', render);

    HOD.actions.on('ce.copy', function (btn) {
        var b = batches[Number(btn.getAttribute('data-i'))];
        if (b) copy(b.join(SEPARATORS[sep]), 'Batch ' + (Number(btn.getAttribute('data-i')) + 1) + ' copied.');
    });
    HOD.actions.on('ce.copy-all', function () {
        if (contacts.length) copy(contacts.join(SEPARATORS[sep]), num(contacts.length) + ' numbers copied.');
    });
})();
