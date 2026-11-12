// /assets/ui/js/pages/event-qr.js
// The Event QR poster (modules/event_qr): 1600 x 900, the event's
// registration QR on a card beside its title and details. Downloaded at twice
// that size. Data: /api/event_qr_api.php. Drawing: HOD.poster.
(function () {
    'use strict';

    var API = '/api/event_qr_api.php';
    var cfg = HOD.data('event-qr-config') || {};
    var W = 1600, H = 900;
    var canvas = document.getElementById('eq-canvas');
    var ctx = canvas.getContext('2d');
    var ev = null, assets = null, seq = 0;

    function $(s) { return document.querySelector(s); }
    function call(action, data) { return HOD.api(API, { action: action, data: data || {} }); }
    function setReady(on) { document.querySelectorAll('[data-action="eq.download"]').forEach(function (b) { b.disabled = !on; }); }
    function regUrl(e) { return e && Number(e.requires_registration) === 1 && e.registration_url ? e.registration_url : ''; }

    function render(c) {
        var P = HOD.poster;
        var brand = $('#eq-brand').value.trim() || 'HOD Lekki';
        var title = $('#eq-title').value.trim() || ev.title || 'Your event';
        var tagline = $('#eq-tagline').value.trim();
        var details = $('#eq-details').checked;
        var useBanner = $('#eq-banner').checked && assets.banner;
        var url = regUrl(ev);

        c.clearRect(0, 0, W, H);
        if (useBanner) {
            P.cover(c, assets.banner, 0, 0, W, H);
            var shade = c.createLinearGradient(0, 0, W, 0);
            shade.addColorStop(0, 'rgba(10, 14, 23, .9)');
            shade.addColorStop(.6, 'rgba(10, 14, 23, .72)');
            shade.addColorStop(1, 'rgba(10, 14, 23, .45)');
            c.fillStyle = shade;
            c.fillRect(0, 0, W, H);
            P.glow(c, W * .98, 0, 620, 'rgba(209, 25, 32, .28)');
        } else {
            P.backdrop(c, W, H);
        }

        // Brand
        c.textAlign = 'left';
        c.textBaseline = 'alphabetic';
        c.fillStyle = '#D11920';
        P.round(c, 80, 64, 56, 56, 14);
        c.fill();
        c.fillStyle = '#FFFFFF';
        c.font = '700 30px Montserrat';
        c.textAlign = 'center';
        c.fillText(brand.charAt(0).toUpperCase(), 108, 103);
        c.textAlign = 'left';
        c.font = '700 26px Montserrat';
        c.fillText(brand, 152, 92);
        c.fillStyle = 'rgba(255, 255, 255, .65)';
        c.font = '400 18px Inter';
        c.fillText('Household of David', 152, 118);

        // Words on the left
        var x = 80, maxW = 880, y = 250;
        if (tagline) {
            c.fillStyle = '#F2A1A4';
            c.font = '600 26px Inter';
            c.fillText(tagline, x, y);
            y += 30;
        }
        var size = P.fit(c, title, maxW, 3, 92, 52, '700', 'Montserrat');
        c.fillStyle = '#FFFFFF';
        P.wrap(c, title, maxW, 3).forEach(function (line, i) { c.fillText(line, x, y + size + i * size * 1.04); });
        y += size + (P.wrap(c, title, maxW, 3).length - 1) * size * 1.04 + 26;

        var cat = String(ev.category || 'Event').replace(/_/g, ' ');
        c.font = '600 20px Inter';
        var cw = c.measureText(cat).width + 36;
        c.fillStyle = 'rgba(255, 255, 255, .14)';
        P.round(c, x, y, cw, 40, 20);
        c.fill();
        c.fillStyle = '#FFFFFF';
        c.fillText(cat, x + 18, y + 27);
        y += 80;

        if (details) {
            c.font = '600 26px Inter';
            var when = [ev.date, ev.time].filter(Boolean).join(' · ');
            if (when) { c.fillStyle = 'rgba(255, 255, 255, .62)'; c.font = '500 18px Inter'; c.fillText('When', x, y); c.fillStyle = '#FFFFFF'; c.font = '600 28px Inter'; c.fillText(when, x, y + 36); y += 86; }
            if (ev.location) {
                c.fillStyle = 'rgba(255, 255, 255, .62)'; c.font = '500 18px Inter'; c.fillText('Where', x, y);
                c.fillStyle = '#FFFFFF'; c.font = '600 28px Inter';
                P.wrap(c, ev.location, maxW - 100, 2).forEach(function (line, i) { c.fillText(line, x, y + 36 + i * 36); });
            }
        }

        // The QR card on the right
        var cx = 1110, cy = 190, cwid = 410, chei = 540;
        c.save();
        c.shadowColor = 'rgba(0, 0, 0, .35)';
        c.shadowBlur = 40;
        c.shadowOffsetY = 16;
        c.fillStyle = '#FFFFFF';
        P.round(c, cx, cy, cwid, chei, 32);
        c.fill();
        c.restore();
        c.textAlign = 'center';
        c.fillStyle = '#0A0E17';
        c.font = '700 30px Montserrat';
        c.fillText('Scan to register', cx + cwid / 2, cy + 64);
        if (url && assets.qr) P.drawQr(c, assets.qr, cx + 45, cy + 96, 320, '#0A0E17');
        c.fillStyle = '#525B6E';
        c.font = '500 16px Inter';
        P.breakChars(c, url.replace(/^https?:\/\//, ''), cwid - 60, 2).forEach(function (line, i) { c.fillText(line, cx + cwid / 2, cy + 460 + i * 22); });

        // Footer
        c.textAlign = 'left';
        c.fillStyle = 'rgba(255, 255, 255, .62)';
        c.font = '500 20px Inter';
        c.fillText(brand + '  ·  ' + (cfg.host || ''), 80, H - 52);
        c.textAlign = 'right';
        c.fillText('We look forward to seeing you', W - 80, H - 52);
    }

    function redraw() {
        if (!ev || !assets) return;
        render(ctx);
    }
    HOD.actions.on('eq.redraw', redraw);

    function load(id) {
        var mine = ++seq;
        setReady(false);
        call('get_event', { event_id: id }).then(function (res) {
            if (mine !== seq) return null;
            ev = res.event;
            $('#eq-title').value = ev.title || '';
            var url = regUrl(ev);
            $('#eq-nolink').hidden = !!url;
            var banner = ev.banner_image_url && /^\/(?!\/)/.test(ev.banner_image_url) ? ev.banner_image_url : null;
            return Promise.all([HOD.poster.fonts(), url ? HOD.poster.qr(url, 'H') : null, HOD.poster.image(banner)]).then(function (r) {
                if (mine !== seq) return;
                assets = { qr: r[1], banner: r[2] };
                $('#eq-none').hidden = true;
                canvas.hidden = false;
                render(ctx);
                setReady(!!url);
            });
        }).catch(function () {});
    }

    HOD.actions.on('eq.event', function (sel) {
        if (!sel.value) { ev = null; canvas.hidden = true; $('#eq-none').hidden = false; $('#eq-nolink').hidden = true; setReady(false); return; }
        load(sel.value);
    });

    HOD.actions.on('eq.download', function (btn) {
        if (!ev || !assets) return;
        var big = document.createElement('canvas');
        big.width = W * 2;
        big.height = H * 2;
        var bctx = big.getContext('2d');
        bctx.scale(2, 2);
        render(bctx);
        HOD.busy(btn, true);
        HOD.poster.download(big, 'event-qr-' + HOD.poster.slug(ev.title), btn.getAttribute('data-kind'))
            .then(function () { HOD.toast('Poster downloaded.', 'success'); })
            .catch(function (e) { HOD.toast(e.message, 'danger'); })
            .finally(function () { HOD.busy(btn, false); });
    });

    document.getElementById('eq-form').addEventListener('submit', function (e) { e.preventDefault(); });

    call('list_events').then(function (res) {
        var sel = $('#eq-event');
        sel.innerHTML = '<option value="">Choose an event</option>' + (res.data || []).map(function (e) {
            var d = e.event_date ? new Date(String(e.event_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
            return '<option value="' + Number(e.id) + '">' + HOD.esc(e.title + (d ? ' · ' + d : '')) + '</option>';
        }).join('');
        if (cfg.eventId) { sel.value = String(cfg.eventId); if (sel.value) load(cfg.eventId); }
    }).catch(function () {});
})();
