// /assets/ui/js/pages/dashboard.js
// The home dashboard (index.php). Cards come from /api/dashboard_api.php;
// tapping one opens the detail drawer with its breakdown and a way into the
// module. While today's Sunday service is live the numbers refresh every
// minute, only while the tab is visible and no detail is open.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/dashboard_api.php';
    var LIVE_REFRESH_MS = 60000;
    var ICONS = {
        workforce: 'users', first_timers: 'user-round-plus', pending_qa: 'circle-help', sms: 'wallet',
        sunday: 'chart-column', team: 'users-round', units: 'building-2', unit_workers: 'users', alerts: 'bell'
    };
    var TONES = { success: 'ui-badge--success', warning: 'ui-badge--warning', danger: 'ui-badge--danger' };
    var DELTA = { up: 'ui-delta ui-delta--up', down: 'ui-delta ui-delta--down', flat: 'ui-delta ui-delta--flat' };
    var DELTA_ICON = { up: 'trending-up', down: 'trending-down', flat: 'minus' };

    var cardsEl, errorEl, noticesEl, detailEl, detailLink;
    var refreshTimer = null;
    var detailRequest = 0;

    function esc(v) { return HOD.esc(v); }

    // Same-site paths only, as push_message_from_notification() allows.
    function safeHref(href) {
        return typeof href === 'string' && href.charAt(0) === '/' && href.charAt(1) !== '/' && href.indexOf('\\') === -1;
    }

    function cardHtml(card, live) {
        var trend = DELTA[card.trend] ? card.trend : 'flat';
        var liveBadge = card.key === 'sunday' && live ? ' <span class="ui-badge ui-badge--danger ui-badge--dot">Live</span>' : '';
        // A rising or falling number colours its note and carries an arrow;
        // anything else is a plain caption.
        var moving = card.trend === 'up' || card.trend === 'down';
        var note = !card.note ? '' : moving
            ? '<span class="ui-stat__meta"><span class="' + DELTA[trend] + '">' + HOD.icon(DELTA_ICON[trend]) + '<span>' + esc(card.note) + '</span></span></span>'
            : '<span class="ui-stat__meta">' + esc(card.note) + '</span>';
        // A status badge from the server (the SMS wallet: OK / Low / Empty / Check).
        var badge = card.badge && TONES[card.tone]
            ? ' <span class="ui-badge ui-badge--dot ' + TONES[card.tone] + ' ml-auto">' + esc(card.badge) + '</span>' : '';
        var value = typeof card.display === 'string' ? card.display : HOD.format.number(card.value);
        var inner =
            '<span class="ui-stat__label">' + HOD.icon(ICONS[card.key] || 'chart-column') + '<span>' + esc(card.title) + '</span>' + liveBadge + badge + '</span>' +
            '<span class="ui-stat__value">' + esc(value) + '</span>' +
            note;
        // A card with its own page (the SMS wallet opens SMS Studio) is a link.
        if (safeHref(card.href)) {
            return '<a class="ui-card ui-stat" href="' + esc(card.href) + '">' + inner + '</a>';
        }
        return '<button type="button" class="ui-card ui-stat" data-action="dash.detail" data-card="' + esc(card.key) + '" data-title="' + esc(card.title) + '" aria-haspopup="dialog">' +
            inner +
        '</button>';
    }

    function renderCards(res) {
        var cards = Array.isArray(res.cards) ? res.cards : [];
        // Five cards: 3 + 2 on a laptop, all five in a row from 1280 px.
        cardsEl.classList.toggle('lg:grid-cols-3', cards.length === 5);
        cardsEl.classList.toggle('xl:grid-cols-5', cards.length === 5);
        cardsEl.innerHTML = cards.map(function (c) { return cardHtml(c, !!res.live); }).join('');
        cardsEl.removeAttribute('aria-busy');
    }

    function renderNotices(res) {
        var list = Array.isArray(res.announcements) ? res.announcements : [];
        if (!list.length) {
            noticesEl.innerHTML =
                '<div class="ui-empty ui-empty--compact">' +
                    '<div class="ui-empty__icon">' + HOD.icon('megaphone') + '</div>' +
                    '<p class="ui-empty__title">No notices for the coming services</p>' +
                    '<p class="ui-empty__text">Publish one and it appears here and on members’ portals.</p>' +
                '</div>';
            return;
        }
        noticesEl.innerHTML = '<ul class="ui-list">' + list.map(function (a) {
            return '<li class="ui-list__item items-start">' +
                '<span class="ui-list__icon">' + HOD.icon('megaphone') + '</span>' +
                '<span class="ui-list__main">' +
                    '<span class="flex flex-wrap items-center gap-2"><span class="ui-list__title">' + esc(a.title) + '</span>' +
                    '<span class="ui-badge ui-badge--primary">' + esc(a.event_name) + ' · ' + esc(a.date_label) + '</span></span>' +
                    '<span class="mt-1 block text-sm leading-relaxed text-ink-muted line-clamp-3">' + esc(a.content) + '</span>' +
                '</span>' +
            '</li>';
        }).join('') + '</ul>';
    }

    function showError() {
        cardsEl.innerHTML = '';
        cardsEl.removeAttribute('aria-busy');
        errorEl.hidden = false;
        errorEl.innerHTML =
            '<div class="ui-banner ui-banner--warning" role="alert">' + HOD.icon('cloud-off') +
                '<div class="ui-banner__body"><p class="ui-banner__title">The numbers did not load</p>' +
                '<p class="ui-banner__text">Check your connection. If you were signed out, sign in again.</p>' +
                '<div class="ui-banner__actions"><button type="button" class="ui-btn ui-btn--sm" data-action="dash.reload">' + HOD.icon('refresh-cw') + '<span>Try again</span></button></div>' +
            '</div></div>';
        noticesEl.innerHTML = '';
    }

    function scheduleRefresh(live) {
        clearTimeout(refreshTimer);
        if (!live) return;
        refreshTimer = setTimeout(function () {
            var detail = document.getElementById('dash-detail');
            if (document.visibilityState === 'visible' && !(detail && detail.open)) load(false);
            else scheduleRefresh(true);
        }, LIVE_REFRESH_MS);
    }

    function load(initial) {
        HOD.api(API, { method: 'GET', quiet: true }).then(function (res) {
            errorEl.hidden = true;
            renderCards(res);
            renderNotices(res);
            scheduleRefresh(!!res.live);
        }).catch(function () {
            if (initial) showError();
            else scheduleRefresh(true); // a missed live refresh keeps the last numbers
        });
    }

    /* ---------- detail drawer ---------- */

    function detailHtml(res) {
        var html = res.summary ? '<p class="ui-lead mb-5">' + esc(res.summary) + '</p>' : '';
        (res.sections || []).forEach(function (section) {
            html += '<section class="mb-6"><h3 class="ui-h3 mb-2">' + esc(section.heading) + '</h3>';
            var rows = section.rows || [];
            if (!rows.length) {
                html += '<p class="ui-meta">' + esc(section.empty || 'Nothing to show yet.') + '</p>';
            } else {
                html += '<ul class="ui-list rounded-ui-lg border border-line">' + rows.map(function (row) {
                    var value = row.value === undefined || row.value === null ? ''
                        : '<span class="ui-list__end font-semibold text-ink ui-num">' + esc(HOD.format.number(row.value)) + '</span>';
                    var inner = '<span class="ui-list__main"><span class="ui-list__title">' + esc(row.label) + '</span>' +
                        (row.hint ? '<span class="ui-list__meta">' + esc(row.hint) + '</span>' : '') + '</span>' + value;
                    return safeHref(row.href)
                        ? '<li><a class="ui-list__item" href="' + esc(row.href) + '">' + inner + '</a></li>'
                        : '<li class="ui-list__item">' + inner + '</li>';
                }).join('') + '</ul>';
            }
            if (section.note) html += '<p class="ui-meta mt-2">' + esc(section.note) + '</p>';
            html += '</section>';
        });
        return html;
    }

    function loadingHtml() {
        var rows = '';
        for (var i = 0; i < 3; i++) rows += '<li class="ui-list__item"><span class="ui-skeleton ui-skeleton--text w-1/2"></span></li>';
        return '<span class="ui-skeleton ui-skeleton--text w-3/4"></span><ul class="ui-list mt-4 rounded-ui-lg border border-line">' + rows + '</ul>';
    }

    function openDetail(key, title) {
        var request = ++detailRequest;
        document.getElementById('dash-detail-title').textContent = title || 'Details';
        detailEl.innerHTML = loadingHtml();
        detailLink.hidden = true;
        HOD.dialog.open('dash-detail');

        HOD.api(API, { method: 'GET', action: 'detail', data: { card: key }, quiet: true }).then(function (res) {
            if (request !== detailRequest) return;
            document.getElementById('dash-detail-title').textContent = res.title || title || 'Details';
            detailEl.innerHTML = detailHtml(res);
            if (res.link && safeHref(res.link.href)) {
                detailLink.href = res.link.href;
                detailLink.querySelector('span').textContent = res.link.label || 'Open';
                detailLink.hidden = false;
            }
        }).catch(function (err) {
            if (request !== detailRequest) return;
            detailEl.innerHTML =
                '<div class="ui-empty ui-empty--compact"><div class="ui-empty__icon">' + HOD.icon('cloud-off') + '</div>' +
                '<p class="ui-empty__title">These details did not load</p>' +
                '<p class="ui-empty__text">' + esc(err && err.code === 'HTTP_200' ? err.message : 'Check your connection, then try again.') + '</p>' +
                '<div class="ui-empty__actions"><button type="button" class="ui-btn ui-btn--sm" data-action="dash.detail" data-card="' + esc(key) + '" data-title="' + esc(title) + '">Try again</button></div></div>';
        });
    }

    /* ---------- Connect QR ---------- */

    var qrDrawn = false;
    function showQr() {
        HOD.dialog.open('dash-qr');
        if (qrDrawn) return;
        var cfg = HOD.data('dash-config') || {};
        var holder = document.querySelector('[data-dash-qr]');
        import(HOD.config.vendor.qrcode).then(function (mod) {
            var qr = mod.qrcode(0, 'M');
            qr.addData(cfg.connectUrl || (window.location.origin + '/connect'));
            qr.make();
            holder.innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
            var svg = holder.querySelector('svg');
            if (svg) {
                svg.setAttribute('class', 'block h-48 w-48');
                svg.querySelectorAll('[fill="#000000"], path').forEach(function (p) { if (p.getAttribute('fill') !== '#ffffff') p.setAttribute('fill', '#0A0E17'); });
            }
            qrDrawn = true;
        }).catch(function () {
            holder.innerHTML = '<p class="ui-meta p-6">The QR code could not be drawn. Use the button below instead.</p>';
        });
    }

    HOD.ready(function () {
        cardsEl = document.querySelector('[data-dash-cards]');
        errorEl = document.querySelector('[data-dash-error]');
        noticesEl = document.querySelector('[data-dash-notices]');
        detailEl = document.querySelector('[data-dash-detail]');
        detailLink = document.querySelector('[data-dash-detail-link]');
        if (!cardsEl) return;

        HOD.actions.on('dash.detail', function (el) { openDetail(el.getAttribute('data-card'), el.getAttribute('data-title')); });
        HOD.actions.on('dash.reload', function () { errorEl.hidden = true; load(true); });
        HOD.actions.on('dash.qr', showQr);
        load(true);
    });
})();
