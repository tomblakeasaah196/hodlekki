// /assets/ui/js/legacy.js
// ===========================================================================
// Bridges for pages not yet rebuilt on the shared components. Loaded (sync,
// after jQuery) only on pages in legacy mode — see ui_page_config().
//
// Toastify: ~96 calls across the modules build their own toasts with their
// own colours. This stand-in keeps their code working and shows the shared
// toast instead, picking the tone from the colour they asked for, so every
// module's messages look the same from the day the foundation ships and the
// jsdelivr dependency is gone. Delete this file with the last legacy page.
// ===========================================================================
(function () {
    'use strict';

    var pending = [];

    function hueOf(css) {
        var m = /#([0-9a-f]{3}|[0-9a-f]{6})\b/i.exec(css || '');
        var r, g, b;
        if (m) {
            var hex = m[1].length === 3 ? m[1].replace(/./g, '$&$&') : m[1];
            r = parseInt(hex.slice(0, 2), 16); g = parseInt(hex.slice(2, 4), 16); b = parseInt(hex.slice(4, 6), 16);
        } else {
            m = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/i.exec(css || '');
            if (!m) return null;
            r = +m[1]; g = +m[2]; b = +m[3];
        }
        var max = Math.max(r, g, b), min = Math.min(r, g, b);
        if (max - min < 24) return null; // grey / navy-ish: no strong hue
        var h;
        if (max === r) h = ((g - b) / (max - min)) % 6;
        else if (max === g) h = (b - r) / (max - min) + 2;
        else h = (r - g) / (max - min) + 4;
        return (h * 60 + 360) % 360;
    }

    function toneOf(opts) {
        var css = String(((opts.style && (opts.style.background || opts.style.backgroundColor)) || opts.backgroundColor || '') + ' ' + (opts.className || ''));
        if (/error|danger|fail|red/i.test(css)) return 'danger';
        if (/success|green/i.test(css)) return 'success';
        if (/warn|amber|orange|yellow/i.test(css)) return 'warning';
        var h = hueOf(css);
        if (h === null) return 'info';
        if (h < 20 || h >= 330) return 'danger';
        if (h < 60) return 'warning';
        if (h < 170) return 'success';
        return 'info';
    }

    function show(opts) {
        if (window.HOD && typeof window.HOD.toast === 'function') {
            var t = window.HOD.toast(opts.text || opts.node && opts.node.textContent || '', {
                tone: toneOf(opts),
                duration: opts.duration && opts.duration > 0 ? Math.max(opts.duration, 3000) : undefined
            });
            return t;
        }
        pending.push(opts);
        return null;
    }

    window.Toastify = function (opts) {
        opts = opts || {};
        var handle = null;
        return {
            showToast: function () { handle = show(opts); return this; },
            hideToast: function () { if (handle) handle.close(); }
        };
    };

    document.addEventListener('DOMContentLoaded', function () {
        var queued = pending;
        pending = [];
        queued.forEach(show);
    });
})();
