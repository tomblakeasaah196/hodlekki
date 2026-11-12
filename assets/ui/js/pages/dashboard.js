// /assets/ui/js/pages/dashboard.js
// The home dashboard (index.php). Cards come from /api/dashboard_api.php;
// tapping one opens the detail drawer with its breakdown and a way into the
// module. While today's Sunday service is live the numbers refresh every
// minute, only while the tab is visible and no detail is open; a refresh
// updates the cards in place (no entrance, no count, focus kept).
// Motion (app.css "Home dashboard"): on the first load only, and only when
// the reader has not asked for reduced motion, the cards rise in one after
// another (.dash-enter) and their numbers count up from 0.
(function () {
    'use strict';

    var HOD = window.HOD;
    var API = '/api/dashboard_api.php';
    var LIVE_REFRESH_MS = 60000;
    var COUNT_MS = 600;
    var STAGGER_MS = 70;
    var ENTRANCE_MS = 1100; // the longest delay plus the rise, then .dash-enter goes
    var ICONS = {
        workforce: 'users', first_timers: 'user-round-plus', pending_qa: 'circle-help', sms: 'wallet',
        sunday: 'chart-column', team: 'users-round', units: 'building-2', unit_workers: 'users', alerts: 'bell'
    };
    // A calm hue per card so the eye can tell them apart (never a status:
    // the SMS badge and the Sunday trend keep green, amber and red).
    var HUES = {
        workforce: 'dash-kpi--navy', first_timers: 'dash-kpi--rose', pending_qa: 'dash-kpi--violet',
        sunday: 'dash-kpi--gold', sms: 'dash-kpi--teal', team: 'dash-kpi--navy', units: 'dash-kpi--teal',
        unit_workers: 'dash-kpi--navy', alerts: 'dash-kpi--violet'
    };
    var reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
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

    function motionOk() {
        return !(reducedMotion && reducedMotion.matches);
    }

    function cardClass(card) {
        return 'ui-card dash-kpi ' + (HUES[card.key] || 'dash-kpi--navy') + (card.needs ? ' dash-kpi--needs' : '');
    }

    function cardValue(card) {
        return typeof card.display === 'string' ? card.display : HOD.format.number(card.value);
    }

    function cardInner(card, live) {
        var trend = DELTA[card.trend] ? card.trend : 'flat';
        // One status at the top right: Live (today's service), the SMS
        // wallet's OK / Low / Empty / Check, or "Needs you".
        var status = '';
        if (card.key === 'sunday' && live) status += '<span class="ui-badge ui-badge--danger ui-badge--dot">Live</span>';
        if (card.badge && TONES[card.tone]) status += '<span class="ui-badge ui-badge--dot ' + TONES[card.tone] + '">' + esc(card.badge) + '</span>';
        if (card.needs) status += '<span class="dash-needs">Needs you</span>';
        // A rising or falling number colours its note and carries an arrow;
        // anything else is a plain caption.
        var moving = card.trend === 'up' || card.trend === 'down';
        var note = !card.note ? '' : moving
            ? '<span class="dash-kpi__note"><span class="' + DELTA[trend] + '">' + HOD.icon(DELTA_ICON[trend]) + '<span>' + esc(card.note) + '</span></span></span>'
            : '<span class="dash-kpi__note">' + esc(card.note) + '</span>';
        // Read in this order: what it is, its status, the number, the note.
        return '<span class="dash-kpi__chip">' + HOD.icon(ICONS[card.key] || 'chart-column') + '</span>' +
            '<span class="dash-kpi__title">' + esc(card.title) + '</span>' +
            (status ? '<span class="dash-kpi__status">' + status + '</span>' : '') +
            '<span class="dash-kpi__value" data-dash-value>' + esc(cardValue(card)) + '</span>' +
            note;
    }

    function cardHtml(card, live) {
        var inner = cardInner(card, live);
        // A card with its own page (the SMS wallet opens SMS Studio) is a link.
        if (safeHref(card.href)) {
            return '<a class="' + cardClass(card) + '" data-dash-key="' + esc(card.key) + '" href="' + esc(card.href) + '">' + inner + '</a>';
        }
        return '<button type="button" class="' + cardClass(card) + '" data-dash-key="' + esc(card.key) + '" data-action="dash.detail" data-card="' + esc(card.key) + '" data-title="' + esc(card.title) + '" aria-haspopup="dialog">' +
            inner +
        '</button>';
    }

    // Counts a value such as "1,204" or the wallet's naira up from 0, then
    // shows the server's own text. Anything else (a dash, a decimal) is left
    // as it is.
    function countUp(el, text, delay) {
        var m = /^(\D*)(\d[\d,]*)(\D*)$/.exec(text);
        var target = m ? Number(m[2].replace(/,/g, '')) : 0;
        if (!m || !(target > 0)) return;
        el.textContent = m[1] + '0' + m[3];
        setTimeout(function () {
            var start = null;
            function step(now) {
                if (start === null) start = now;
                var p = Math.min(1, (now - start) / COUNT_MS);
                var eased = 1 - Math.pow(1 - p, 3);
                el.textContent = p < 1 ? m[1] + HOD.format.number(Math.round(target * eased)) + m[3] : text;
                if (p < 1) window.requestAnimationFrame(step);
            }
            window.requestAnimationFrame(step);
        }, delay);
    }

    // The cards on screen, when they are the same cards (key, order, link or
    // button) as these.
    function sameCards(cards) {
        var shown = cardsEl.querySelectorAll('[data-dash-key]');
        if (shown.length !== cards.length) return null;
        for (var i = 0; i < cards.length; i++) {
            var tag = safeHref(cards[i].href) ? 'A' : 'BUTTON';
            if (shown[i].getAttribute('data-dash-key') !== cards[i].key || shown[i].tagName !== tag) return null;
        }
        return shown;
    }

    function renderCards(res, initial) {
        var cards = Array.isArray(res.cards) ? res.cards : [];
        var live = !!res.live;
        var shown = initial ? null : sameCards(cards);
        if (shown) {
            // A live refresh: new numbers in the same cards.
            cards.forEach(function (c, i) {
                shown[i].className = cardClass(c);
                if (shown[i].tagName === 'A') shown[i].setAttribute('href', c.href);
                else shown[i].setAttribute('data-title', c.title);
                shown[i].innerHTML = cardInner(c, live);
            });
            return;
        }
        // Five cards: 3 + 2 on a laptop, all five in a row from 1280 px.
        cardsEl.classList.toggle('lg:grid-cols-3', cards.length === 5);
        cardsEl.classList.toggle('xl:grid-cols-5', cards.length === 5);
        cardsEl.innerHTML = cards.map(function (c) { return cardHtml(c, live); }).join('');
        cardsEl.removeAttribute('aria-busy');
        if (!initial || !motionOk()) return;
        cardsEl.classList.add('dash-enter');
        cardsEl.querySelectorAll('[data-dash-value]').forEach(function (el, i) {
            countUp(el, cardValue(cards[i]), i * STAGGER_MS);
        });
        setTimeout(function () { cardsEl.classList.remove('dash-enter'); }, ENTRANCE_MS);
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
            renderCards(res, initial);
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
