/* /assets/js/monitor_wall.js
 *
 * The live wall (modules/monitor/wall.php). Reads the key from the link's
 * fragment (#k=…), polls /live/monitor/<key>.json every 5 seconds (a static
 * file: no PHP per poll) and redraws. Every word from the snapshot is put in
 * as TEXT, never as HTML. Keeps the screen awake where the browser allows it.
 */
(function () {
    'use strict';

    var POLL_MS = 5000;
    var STALE_S = 300;
    var $ = function (name) { return document.querySelector('[data-mw="' + name + '"]'); };
    var key = (function () {
        var m = /(?:^|[#&])k=([0-9a-f]{32})(?:&|$)/.exec(window.location.hash || '');
        return m ? m[1] : null;
    })();
    var last = null;
    var lastOk = 0;
    var seen = {};
    var STATE_WORD = { ok: 'All good', warn: 'Worth a look', bad: 'Needs attention', unknown: 'Waiting' };
    var CHECK_WORD = { ok: 'OK', warn: 'Check', bad: 'Problem', unknown: 'Waiting' };

    function text(name, value) {
        var n = $(name);
        if (n) n.textContent = value;
    }

    function notice(message) {
        var n = $('notice');
        if (!n) return;
        n.hidden = !message;
        n.textContent = message || '';
    }

    function num(n) {
        return Number(n || 0).toLocaleString('en-GB');
    }

    function ago(unix) {
        if (!unix) return 'never';
        var s = Math.max(0, Math.round(Date.now() / 1000 - unix));
        if (s < 45) return 'just now';
        if (s < 5400) return Math.max(1, Math.round(s / 60)) + ' min ago';
        if (s < 129600) return Math.round(s / 3600) + ' h ago';
        return Math.round(s / 86400) + ' days ago';
    }

    function clock() {
        try {
            text('clock', new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: 'Africa/Lagos' }));
        } catch (e) {
            text('clock', new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }));
        }
        if (last) {
            var age = Math.round(Date.now() / 1000 - last.generated_at);
            text('fresh', 'updated ' + ago(last.generated_at));
            if (age > STALE_S && Date.now() - lastOk < 2 * POLL_MS) {
                notice('These figures are ' + Math.round(age / 60) + ' minutes old: the monitor has not refreshed them. Is its cron job running?');
            }
        }
    }

    function spark(values) {
        var host = $('spark');
        if (!host) return;
        while (host.firstChild) host.removeChild(host.firstChild);
        var ns = 'http://www.w3.org/2000/svg';
        var svg = document.createElementNS(ns, 'svg');
        svg.setAttribute('viewBox', '0 0 100 30');
        svg.setAttribute('preserveAspectRatio', 'none');
        var max = Math.max.apply(null, [1].concat(values));
        var w = 100 / Math.max(1, values.length);
        values.forEach(function (v, i) {
            var h = v > 0 ? Math.max(1.5, 28 * v / max) : 0.8;
            var r = document.createElementNS(ns, 'rect');
            r.setAttribute('x', (i * w + 0.3).toFixed(2));
            r.setAttribute('y', (30 - h).toFixed(2));
            r.setAttribute('width', Math.max(0.5, w - 0.6).toFixed(2));
            r.setAttribute('height', h.toFixed(2));
            r.setAttribute('class', v > 0 ? 'mw-spark__bar' : 'mw-spark__bar mw-spark__bar--zero');
            svg.appendChild(r);
        });
        host.appendChild(svg);
    }

    function draw(s) {
        last = s;
        var state = $('state');
        if (state) {
            state.setAttribute('data-state', s.overall || 'unknown');
            state.textContent = STATE_WORD[s.overall] || 'Waiting';
        }
        text('site', s.site || '');
        var e = s.errors || {};
        text('open', num(e.open));
        text('new', num(e.new_24h) + ' new in 24 hours');
        text('hour', num(e.last_hour) + ' this hour');
        spark(e.spark_24h || []);

        var up = (s.uptime || {}).site;
        if (up) {
            text('up-site', up.pct_7d !== null && up.pct_7d !== undefined ? up.pct_7d + '%' : '—');
            text('up-site-meta', (up.up_now ? 'up now' : 'DOWN at the last check') + (up.ttfb_ms ? ' · ' + up.ttfb_ms + ' ms' : '') + ' · 7 days');
        } else {
            text('up-site', '—');
            text('up-site-meta', 'no outside report yet');
        }
        var phone = (s.speed || {}).phone;
        if (phone && phone.n >= 20 && phone.lcp) {
            text('lcp', (phone.lcp / 1000).toFixed(1) + ' s');
            text('lcp-meta', 'loading, 75% of ' + num(phone.n) + ' visits');
        } else {
            text('lcp', '—');
            text('lcp-meta', 'not enough visits yet');
        }

        var list = $('latest');
        var latest = s.latest || [];
        if (list) {
            while (list.firstChild) list.removeChild(list.firstChild);
            latest.slice(0, 8).forEach(function (g) {
                var li = document.createElement('li');
                li.className = 'mw-problem mw-problem--' + (g.level === 'fatal' || g.level === 'error' ? 'bad' : 'warn');
                var key2 = g.id + ':' + g.count;
                if (lastOk && !seen[key2]) li.className += ' mw-problem--fresh';
                seen[key2] = true;
                var t = document.createElement('span');
                t.className = 'mw-problem__title';
                t.textContent = g.title;
                var m = document.createElement('span');
                m.className = 'mw-problem__meta';
                m.textContent = [g.where || '', num(g.count) + '×', g.people_24h ? g.people_24h + (g.people_24h === 1 ? ' person' : ' people') + ' today' : '',
                    g.back ? 'came back after a fix' : '', 'last ' + ago(g.last_seen)].filter(Boolean).join(' · ');
                li.appendChild(t);
                li.appendChild(m);
                list.appendChild(li);
            });
        }
        var none = $('latest-empty');
        if (none) none.hidden = latest.length > 0;

        var checks = $('checks');
        if (checks) {
            while (checks.firstChild) checks.removeChild(checks.firstChild);
            var order = { bad: 0, warn: 1, unknown: 2, ok: 3 };
            (s.checks || []).slice().sort(function (a, b) { return (order[a.state] - order[b.state]) || String(a.label).localeCompare(b.label); })
                .forEach(function (c) {
                    var li = document.createElement('li');
                    li.className = 'mw-check mw-check--' + (order[c.state] !== undefined ? c.state : 'unknown');
                    var l = document.createElement('span');
                    l.className = 'mw-check__label';
                    l.textContent = c.label;
                    var w = document.createElement('span');
                    w.className = 'mw-check__state';
                    w.textContent = CHECK_WORD[c.state] || c.state;
                    li.appendChild(l);
                    li.appendChild(w);
                    if (c.state === 'bad' || c.state === 'warn') {
                        li.title = c.detail || '';
                    }
                    checks.appendChild(li);
                });
        }

        var d = s.deploy;
        text('deploy', d ? 'Running ' + d.commit + (d.subject ? ' “' + d.subject + '”' : '') + ', deployed ' + ago(d.at) : '');
        clock();
    }

    function poll() {
        fetch('/live/monitor/' + key + '.json', { cache: 'no-store', credentials: 'omit' }).then(function (r) {
            if (r.status === 404) {
                throw Object.assign(new Error('gone'), { code: 404 });
            }
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        }).then(function (s) {
            notice('');
            draw(s);                 // before lastOk is set: nothing glows as "new" on the first draw
            lastOk = Date.now();
        }).catch(function (err) {
            if (err && err.code === 404) {
                notice('This wall link was replaced or has not been published yet. Ask an admin for the current link (System Health → Settings → Live wall).');
            } else {
                notice("Can't reach the site right now. Showing the last figures; trying again every few seconds.");
            }
        });
    }

    function stayAwake() {
        if (!('wakeLock' in navigator) || document.visibilityState !== 'visible') return;
        navigator.wakeLock.request('screen').catch(function () {});
    }

    document.addEventListener('DOMContentLoaded', function () {
        clock();
        setInterval(clock, 15000);
        if (!key) {
            notice('This link is incomplete: it needs the key after #k=. Copy the full link from System Health → Settings → Live wall.');
            var state = $('state');
            if (state) state.textContent = 'No key';
            return;
        }
        poll();
        setInterval(poll, POLL_MS);
        stayAwake();
        document.addEventListener('visibilitychange', stayAwake);
    });
})();
