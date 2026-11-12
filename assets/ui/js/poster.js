// /assets/ui/js/poster.js
// HOD.poster: drawing helpers for the printable QR posters (the Check-in QR
// at modules/checkin_qr and the Event QR at modules/event_qr). Posters are
// drawn on a <canvas> at print size, so the preview is exactly what downloads,
// with no inline styles (strict CSP) and no screenshot library. The QR comes
// from the vendored qrcode-generator, drawn module by module, so it stays crisp.
(function () {
    'use strict';

    var P = {};

    /** Waits for the brand faces, so the first draw already uses them. */
    P.fonts = function () {
        if (!document.fonts || !document.fonts.load) return Promise.resolve();
        return Promise.all(['700 48px Montserrat', '400 24px Inter', '600 24px Inter', '700 24px Inter'].map(function (f) {
            return document.fonts.load(f).catch(function () { return null; });
        }));
    };

    /** An image, or null when there is none or it fails (the poster draws without it). */
    P.image = function (src) {
        return new Promise(function (resolve) {
            if (!src) { resolve(null); return; }
            var img = new Image();
            img.decoding = 'async';
            img.onload = function () { resolve(img); };
            img.onerror = function () { resolve(null); };
            img.src = src;
        });
    };

    /** The QR's modules for text, at the given error-correction level. */
    P.qr = function (text, level) {
        return import(HOD.config.vendor.qrcode).then(function (mod) {
            var q = mod.qrcode(0, level || 'H');
            q.addData(String(text));
            q.make();
            return { n: q.getModuleCount(), dark: function (r, c) { return q.isDark(r, c); } };
        });
    };

    P.drawQr = function (ctx, qr, x, y, size, color) {
        var cell = size / qr.n;
        ctx.fillStyle = color || '#0A0E17';
        for (var r = 0; r < qr.n; r++) {
            for (var c = 0; c < qr.n; c++) {
                if (qr.dark(r, c)) ctx.fillRect(Math.floor(x + c * cell), Math.floor(y + r * cell), Math.ceil(cell), Math.ceil(cell));
            }
        }
    };

    P.round = function (ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    };

    /** Draws img to fill the box, cropping like object-fit: cover. */
    P.cover = function (ctx, img, x, y, w, h) {
        var s = Math.max(w / img.naturalWidth, h / img.naturalHeight);
        var sw = w / s, sh = h / s;
        ctx.drawImage(img, (img.naturalWidth - sw) / 2, (img.naturalHeight - sh) / 2, sw, sh, x, y, w, h);
    };

    /** The brand backdrop: navy with the red and blue glows. */
    P.backdrop = function (ctx, w, h) {
        var g = ctx.createLinearGradient(0, 0, w, h);
        g.addColorStop(0, '#1D356A');
        g.addColorStop(1, '#0A0E17');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, w, h);
        P.glow(ctx, w * .95, h * .02, Math.max(w, h) * .55, 'rgba(209, 25, 32, .35)');
        P.glow(ctx, w * .02, h * .98, Math.max(w, h) * .5, 'rgba(74, 107, 176, .4)');
    };

    P.glow = function (ctx, x, y, r, color) {
        var g = ctx.createRadialGradient(x, y, 0, x, y, r);
        g.addColorStop(0, color);
        g.addColorStop(1, 'rgba(0, 0, 0, 0)');
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, ctx.canvas.width, ctx.canvas.height);
    };

    /** Splits text into lines no wider than max, at most maxLines (the last one ends with …). */
    P.wrap = function (ctx, text, max, maxLines) {
        var words = String(text || '').split(/\s+/).filter(Boolean);
        var lines = [], line = '';
        words.forEach(function (word) {
            var test = line ? line + ' ' + word : word;
            if (ctx.measureText(test).width <= max || !line) line = test;
            else { lines.push(line); line = word; }
        });
        if (line) lines.push(line);
        if (maxLines && lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            var last = lines[maxLines - 1];
            while (last && ctx.measureText(last + '…').width > max) last = last.slice(0, -1);
            lines[maxLines - 1] = last.replace(/\s+$/, '') + '…';
        }
        return lines;
    };

    /** Like wrap, but breaks anywhere: for a link, which has no spaces. */
    P.breakChars = function (ctx, text, max, maxLines) {
        var lines = [], line = '';
        Array.from(String(text || '')).forEach(function (ch) {
            if (line && ctx.measureText(line + ch).width > max) { lines.push(line); line = ch; } else line += ch;
        });
        if (line) lines.push(line);
        if (maxLines && lines.length > maxLines) {
            lines = lines.slice(0, maxLines);
            var last = lines[maxLines - 1];
            while (last && ctx.measureText(last + '…').width > max) last = last.slice(0, -1);
            lines[maxLines - 1] = last + '…';
        }
        return lines;
    };

    /** The largest font size (down to min) at which text fits in max lines. */
    P.fit = function (ctx, text, max, maxLines, size, min, weight, family) {
        for (var s = size; s >= min; s -= 2) {
            ctx.font = weight + ' ' + s + 'px ' + family;
            if (P.wrap(ctx, text, max).length <= maxLines) return s;
        }
        ctx.font = weight + ' ' + min + 'px ' + family;
        return min;
    };

    /** Downloads the canvas as PNG or JPEG. */
    P.download = function (canvas, name, kind) {
        var type = kind === 'jpeg' ? 'image/jpeg' : 'image/png';
        return new Promise(function (resolve) { canvas.toBlob(resolve, type, .94); }).then(function (blob) {
            if (!blob) throw new Error('The picture could not be made.');
            var url = URL.createObjectURL(blob);
            var a = document.createElement('a');
            a.href = url;
            a.download = name + (kind === 'jpeg' ? '.jpg' : '.png');
            document.body.appendChild(a);
            a.click();
            a.remove();
            setTimeout(function () { URL.revokeObjectURL(url); }, 2000);
        });
    };

    /** "my-event-title" for file names. */
    P.slug = function (text) {
        return String(text || 'poster').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'poster';
    };

    window.HOD = window.HOD || {};
    window.HOD.poster = P;
})();
