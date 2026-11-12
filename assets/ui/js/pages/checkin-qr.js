// /assets/ui/js/pages/checkin-qr.js
// The Check-in QR poster (modules/checkin_qr): the standing service QR or one
// event's QR, drawn at 1080 x 1350 (4:5) and downloaded at twice that.
// Data: /api/checkin_qr_api.php. Drawing: HOD.poster (assets/ui/js/poster.js).
(function () {
    'use strict';

    var API = '/api/checkin_qr_api.php';
    var cfg = HOD.data('checkin-qr-config') || {};
    var W = 1080, H = 1350;
    var canvas = document.getElementById('cq-canvas');
    var ctx = canvas.getContext('2d');
    var current = null;
    var seq = 0;

    function $(s) { return document.querySelector(s); }
    function call(action, data) {
        return HOD.api(API, { action: action, data: Object.assign({ _csrf: cfg.csrf }, data || {}) });
    }
    function setReady(on) {
        document.querySelectorAll('[data-action="cq.download"]').forEach(function (b) { b.disabled = !on; });
    }

    function shortUrl(url) { return String(url).replace(/^https?:\/\//, ''); }

    /** Draws the poster on c (a 2d context already scaled to W x H units). */
    function render(ctx, target, qr, banner, icon) {
                var P = HOD.poster;
                ctx.clearRect(0, 0, W, H);
                if (banner) {
                    P.cover(ctx, banner, 0, 0, W, H);
                    ctx.fillStyle = 'rgba(10, 14, 23, .62)';
                    ctx.fillRect(0, 0, W, H);
                    P.glow(ctx, W * .95, H * .02, 700, 'rgba(209, 25, 32, .3)');
                } else {
                    P.backdrop(ctx, W, H);
                }

                // The mark
                if (icon) {
                    ctx.save();
                    P.round(ctx, 80, 72, 76, 76, 18);
                    ctx.clip();
                    ctx.drawImage(icon, 80, 72, 76, 76);
                    ctx.restore();
                }
                ctx.textAlign = 'left';
                ctx.textBaseline = 'alphabetic';
                ctx.fillStyle = '#FFFFFF';
                ctx.font = '700 32px Montserrat';
                ctx.fillText('HOD Lekki', 176, 108);
                ctx.fillStyle = 'rgba(255, 255, 255, .72)';
                ctx.font = '400 22px Inter';
                ctx.fillText('Household of David', 176, 140);

                // The call
                ctx.textAlign = 'center';
                ctx.fillStyle = '#FFFFFF';
                ctx.font = '700 76px Montserrat';
                ctx.fillText('Scan to check in', W / 2, 300);
                var size = P.fit(ctx, target.title, 860, 2, 38, 28, '600', 'Inter');
                ctx.fillStyle = 'rgba(255, 255, 255, .86)';
                P.wrap(ctx, target.title, 860, 2).forEach(function (line, i) { ctx.fillText(line, W / 2, 362 + i * (size + 10)); });

                // The QR on its plate
                var plate = 600, px = (W - plate) / 2, py = 470;
                ctx.save();
                ctx.shadowColor = 'rgba(0, 0, 0, .35)';
                ctx.shadowBlur = 40;
                ctx.shadowOffsetY = 16;
                ctx.fillStyle = '#FFFFFF';
                P.round(ctx, px, py, plate, plate, 36);
                ctx.fill();
                ctx.restore();
                P.drawQr(ctx, qr, px + 40, py + 40, plate - 80, '#0A0E17');

                ctx.fillStyle = 'rgba(255, 255, 255, .62)';
                ctx.font = '500 22px Inter';
                ctx.fillText(shortUrl(target.url), W / 2, py + plate + 56);

                if (target.note) {
                    ctx.fillStyle = '#FFFFFF';
                    ctx.font = '600 28px Inter';
                    ctx.fillText(target.note, W / 2, py + plate + 118);
                }

                ctx.fillStyle = 'rgba(255, 255, 255, .6)';
                ctx.font = 'italic 400 24px Inter';
                ctx.fillText('“The LORD bless thee, and keep thee.” Numbers 6:24', W / 2, H - 72);
    }

    function draw(target) {
        var mine = ++seq;
        setReady(false);
        return Promise.all([HOD.poster.fonts(), HOD.poster.qr(target.url, 'H'), HOD.poster.image(target.banner), HOD.poster.image('/assets/pwa/icon-192.png')])
            .then(function (r) {
                if (mine !== seq) return;
                target.assets = [r[1], r[2], r[3]];
                render(ctx, target, r[1], r[2], r[3]);
                setReady(true);
            })
            .catch(function () { HOD.toast('The poster could not be drawn. Try again.', 'danger'); });
    }

    function show(target) {
        current = target;
        var link = $('#cq-link');
        link.textContent = shortUrl(target.url);
        link.href = target.url;
        $('#cq-hint').textContent = target.hint;
        return draw(target);
    }

    function service() {
        return show({
            url: cfg.checkinUrl + '?service=today',
            title: 'Sunday and Thursday services',
            note: 'One QR for every service',
            hint: 'One print for every Sunday and Thursday.',
            banner: null,
            file: 'checkin-services'
        });
    }

    HOD.actions.on('cq.target', function (sel) {
        if (sel.value === 'service') { service(); return; }
        setReady(false);
        call('get_event', { event_id: sel.value }).then(function (res) {
            var e = res.event;
            show({
                url: cfg.checkinUrl + '?event=' + encodeURIComponent(e.token),
                title: e.title,
                note: '',
                hint: 'Opens this event only.',
                banner: e.banner_image_url && /^\/(?!\/)/.test(e.banner_image_url) ? e.banner_image_url : null,
                file: 'checkin-' + HOD.poster.slug(e.title)
            });
        }).catch(function () {});
    });

    HOD.actions.on('cq.copy', function () {
        if (!current) return;
        var done = function () { HOD.toast('Link copied.', 'success'); };
        if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(current.url).then(done, function () { HOD.toast('Copy the link from the box.', 'warning'); });
    });

    HOD.actions.on('cq.download', function (btn) {
        if (!current) return;
        // Print size: drawn again at twice the preview, not scaled up.
        var big = document.createElement('canvas');
        big.width = W * 2;
        big.height = H * 2;
        var bctx = big.getContext('2d');
        bctx.scale(2, 2);
        render(bctx, current, current.assets[0], current.assets[1], current.assets[2]);
        HOD.busy(btn, true);
        HOD.poster.download(big, current.file, btn.getAttribute('data-kind'))
            .then(function () { HOD.toast('Poster downloaded.', 'success'); })
            .catch(function (e) { HOD.toast(e.message, 'danger'); })
            .finally(function () { HOD.busy(btn, false); });
    });

    call('list_events').then(function (res) {
        var group = document.getElementById('cq-events');
        group.innerHTML = (res.data || []).map(function (e) {
            var d = e.event_date ? new Date(String(e.event_date).replace(' ', 'T')).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : '';
            return '<option value="' + Number(e.id) + '">' + HOD.esc(e.title + (d ? ' · ' + d : '')) + '</option>';
        }).join('');
    }).catch(function () {});

    service();
})();
