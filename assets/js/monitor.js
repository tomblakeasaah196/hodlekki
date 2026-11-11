/* /assets/js/monitor.js
 *
 * The system monitor's browser half (docs/monitor.md, "Browser").
 * Printed by includes/monitor/browser.php (via includes/pwa_head.php) as
 *   <script src="/assets/js/monitor.js?v=…" data-t="<day token>" data-rum="1" data-e="/api/monitor_intake.php">
 *
 * Reports, to this site only:
 *   - JavaScript errors and unhandled promise rejections, with their stack;
 *   - scripts, stylesheets and images that failed to load;
 *   - real-user speed for this page view: LCP, INP, CLS, TTFB and FCP (Core Web
 *     Vitals, measured the way Google's web-vitals library does), with the kind
 *     of device and connection.
 * Never: what anyone typed, cookies, storage, or the page's text. At most 10
 * errors a page, each sent once; speed once, when the page is hidden.
 *
 * Plain script, no dependencies, no inline code: safe under a strict CSP
 * (script-src 'self'; connect-src 'self'). Never throws into the page.
 */
(function () {
    'use strict';

    var me = document.currentScript;
    if (!me || window.__hodMonitor) {
        return;
    }
    window.__hodMonitor = true;

    var ENDPOINT = me.getAttribute('data-e') || '/api/monitor_intake.php';
    var TOKEN = me.getAttribute('data-t') || '';
    var RUM_RATE = parseFloat(me.getAttribute('data-rum'));
    if (isNaN(RUM_RATE)) {
        RUM_RATE = 1;
    }
    var MAX_ERRORS = 10;
    var started = Date.now();
    var queue = [];
    var seen = {};
    var reported = 0;
    var timer = null;

    function device() {
        var ua = navigator.userAgent || '';
        var uad = navigator.userAgentData;
        if (uad && uad.mobile === true) {
            return 'phone';
        }
        if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || (/Android/i.test(ua) && !/Mobile/i.test(ua)) ||
            (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) {
            return 'tablet';
        }
        if (/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini/i.test(ua)) {
            return 'phone';
        }
        return 'desktop';
    }

    function send(payload) {
        try {
            payload.t = TOKEN;
            payload.v = 1;
            payload.page = location.pathname;
            payload.dev = device();
            var body = JSON.stringify(payload);
            if (navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain;charset=UTF-8' }))) {
                return;
            }
            if (window.fetch) {
                fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true, credentials: 'same-origin',
                    headers: { 'Content-Type': 'text/plain;charset=UTF-8' } })['catch'](function () {});
            }
        } catch (e) { /* never into the page */ }
    }

    function flushErrors() {
        timer = null;
        if (queue.length) {
            send({ errors: queue.splice(0, queue.length) });
        }
    }

    function add(err) {
        try {
            var key = err.type + '|' + err.msg + '|' + (err.src || '') + '|' + (err.line || '');
            if (seen[key] || reported >= MAX_ERRORS) {
                return;
            }
            seen[key] = true;
            reported++;
            err.at = Date.now() - started;
            queue.push(err);
            if (!timer) {
                timer = setTimeout(flushErrors, 2000);
            }
        } catch (e) { /* never into the page */ }
    }

    function text(v, max) {
        var s = typeof v === 'string' ? v : (v && v.message ? v.message : String(v));
        return s.length > max ? s.slice(0, max) : s;
    }

    // Errors, and files that failed to load (those do not bubble: capture phase).
    window.addEventListener('error', function (ev) {
        var t = ev.target;
        if (t && t !== window && t.tagName) {
            var url = t.currentSrc || t.src || t.href || '';
            if (url) {
                add({ type: 'resource', msg: 'Could not load ' + url, src: url, tag: String(t.tagName).toLowerCase() });
            }
            return;
        }
        add({
            type: 'error',
            msg: text(ev.message || (ev.error && ev.error.message) || 'Error', 500),
            src: ev.filename || '',
            line: ev.lineno || null,
            col: ev.colno || null,
            stack: ev.error && ev.error.stack ? text(ev.error.stack, 4000) : ''
        });
    }, true);

    window.addEventListener('unhandledrejection', function (ev) {
        var r = ev.reason;
        add({
            type: 'rejection',
            msg: text(r && r.message ? (r.name ? r.name + ': ' : '') + r.message : (r === undefined ? 'Promise rejected' : r), 500),
            src: '',
            stack: r && r.stack ? text(r.stack, 4000) : ''
        });
    });

    // ---- Real-user speed (Core Web Vitals) ------------------------------------
    if (!(Math.random() < RUM_RATE) || !window.PerformanceObserver || !window.performance) {
        window.addEventListener('pagehide', flushErrors);
        return;
    }

    var vitals = {};
    var nav = (performance.getEntriesByType && performance.getEntriesByType('navigation')[0]) || null;
    var activation = nav && nav.activationStart ? nav.activationStart : 0;
    var firstHidden = document.visibilityState === 'hidden' ? 0 : Infinity;
    var restored = false;
    var sent = false;

    function observe(type, cb, opts) {
        try {
            if (PerformanceObserver.supportedEntryTypes && PerformanceObserver.supportedEntryTypes.indexOf(type) < 0) {
                return null;
            }
            var po = new PerformanceObserver(function (list) { cb(list.getEntries()); });
            var o = opts || {};
            o.type = type;
            o.buffered = true;
            po.observe(o);
            return po;
        } catch (e) {
            return null;
        }
    }

    if (nav) {
        vitals.ttfb = Math.max(0, Math.round(nav.responseStart - activation));
        vitals.nav = activation > 0 ? 'prerender' : (nav.type || 'navigate');
    }

    observe('paint', function (entries) {
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].name === 'first-contentful-paint' && entries[i].startTime < firstHidden) {
                vitals.fcp = Math.max(0, Math.round(entries[i].startTime - activation));
            }
        }
    });

    // LCP: the last candidate before the person first interacts or leaves.
    var lcpObserver = observe('largest-contentful-paint', function (entries) {
        var last = entries[entries.length - 1];
        if (last && last.startTime < firstHidden) {
            vitals.lcp = Math.max(0, Math.round(last.startTime - activation));
        }
    });
    function stopLcp() {
        if (lcpObserver) {
            try { lcpObserver.takeRecords(); lcpObserver.disconnect(); } catch (e) { /* ignore */ }
            lcpObserver = null;
        }
    }
    ['keydown', 'click', 'pointerdown'].forEach(function (type) {
        addEventListener(type, stopLcp, { once: true, capture: true });
    });

    // CLS: the largest burst of unexpected layout shifts (session windows:
    // shifts under 1 s apart, at most 5 s long).
    var clsValue = 0;
    var session = 0;
    var sessionFirst = 0;
    var sessionLast = 0;
    observe('layout-shift', function (entries) {
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            if (e.hadRecentInput) {
                continue;
            }
            if (session && e.startTime - sessionLast < 1000 && e.startTime - sessionFirst < 5000) {
                session += e.value;
            } else {
                session = e.value;
                sessionFirst = e.startTime;
            }
            sessionLast = e.startTime;
            if (session > clsValue) {
                clsValue = session;
            }
        }
        vitals.cls = Math.round(clsValue * 1000);
    });

    // INP: the slowest interactions, leaving out one in every 50 (an outlier).
    var interactions = {};
    function onEvents(entries) {
        for (var i = 0; i < entries.length; i++) {
            var e = entries[i];
            if (!e.interactionId) {
                continue;
            }
            var prev = interactions[e.interactionId];
            if (!prev || e.duration > prev) {
                interactions[e.interactionId] = e.duration;
            }
        }
        var list = [];
        for (var k in interactions) {
            if (Object.prototype.hasOwnProperty.call(interactions, k)) {
                list.push(interactions[k]);
            }
        }
        if (list.length) {
            list.sort(function (a, b) { return b - a; });
            vitals.inp = Math.round(list[Math.min(list.length - 1, Math.floor(list.length / 50))]);
        }
    }
    observe('event', onEvents, { durationThreshold: 40 });
    observe('first-input', onEvents);

    if (navigator.connection && navigator.connection.effectiveType) {
        vitals.conn = String(navigator.connection.effectiveType);
    }

    function report() {
        if (sent || restored) {
            return;
        }
        sent = true;
        stopLcp();
        var rum = {};
        var any = false;
        ['lcp', 'inp', 'cls', 'ttfb', 'fcp', 'conn', 'nav'].forEach(function (k) {
            if (vitals[k] !== undefined) {
                rum[k] = vitals[k];
                any = any || ['lcp', 'inp', 'cls', 'ttfb', 'fcp'].indexOf(k) >= 0;
            }
        });
        var payload = {};
        if (queue.length) {
            payload.errors = queue.splice(0, queue.length);
        }
        if (any) {
            payload.rum = rum;
        }
        if (payload.errors || payload.rum) {
            send(payload);
        }
    }

    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            if (firstHidden === Infinity) {
                firstHidden = performance.now();
            }
            report();
        }
    }, true);
    window.addEventListener('pagehide', report, true);
    // A page brought back from the back/forward cache is not a new load: its
    // figures were sent when it was first shown.
    window.addEventListener('pageshow', function (ev) {
        if (ev.persisted) {
            restored = true;
        }
    }, true);
}());
