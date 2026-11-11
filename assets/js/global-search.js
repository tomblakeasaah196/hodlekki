// /assets/js/global-search.js
// Global command palette (Ctrl/Cmd+K) for the authenticated app shell.
//
// Reads the access-filtered index the shell prints from the navigation
// registry (includes/ui/nav.php → the #hod-ui JSON block: modules, module
// tabs, quick actions), fuzzy-matches it client-side, and augments results
// with live people/event lookups from /api/global_search_api.php (which
// enforces its own role checks). Icons are names in the sprite.
(function () {
    'use strict';

    var RECENTS_KEY = 'hod_gs_recents_v1';
    var RECENTS_MAX = 6;
    var GROUP_ORDER = [
        '__recent__', 'Command Center', 'Growth & Retention', 'Specialized Units',
        'Core Operations', 'Security & Stewardship', 'System', 'Actions', 'People', 'Events'
    ];
    var SPRITE = '/assets/ui/icons.svg';

    var overlay, panel, input, results, spinner;
    var isOpen = false;
    var items = [];            // static index (nav + tabs + actions)
    var liveResults = { people: [], events: [] };
    var flatVisible = [];      // currently rendered, selectable entries
    var activeIndex = 0;
    var debounceTimer = null;
    var liveSeq = 0;
    var lastLiveQuery = '';
    var opener = null;

    // ------------------------------------------------------------------
    // Utilities
    // ------------------------------------------------------------------
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function isMac() {
        return /Mac|iPhone|iPad|iPod/.test(navigator.platform || '');
    }

    // ------------------------------------------------------------------
    // Fuzzy matching
    // ------------------------------------------------------------------
    function subsequenceScore(needle, haystack) {
        // Returns 0 when not a subsequence, otherwise a density score 0..1.
        var h = 0, matched = 0, firstHit = -1, lastHit = -1;
        for (var n = 0; n < needle.length; n++) {
            var found = haystack.indexOf(needle[n], h);
            if (found === -1) return 0;
            if (firstHit === -1) firstHit = found;
            lastHit = found;
            h = found + 1;
            matched++;
        }
        var span = (lastHit - firstHit + 1) || 1;
        return matched / span; // tighter clusters score higher
    }

    function scoreItem(item, q) {
        var label = item.label.toLowerCase();
        var best = 0;

        if (label === q) best = 120;
        else if (label.indexOf(q) === 0) best = 100;
        else if (label.indexOf(' ' + q) !== -1 || label.indexOf('(' + q) !== -1) best = 90;
        else if (label.indexOf(q) !== -1) best = 80;

        if (best < 70 && item.keywords) {
            for (var i = 0; i < item.keywords.length; i++) {
                var kw = item.keywords[i];
                if (kw === q || kw.indexOf(q) === 0) { best = Math.max(best, 70); break; }
                if (kw.indexOf(q) !== -1) best = Math.max(best, 60);
            }
        }

        if (best === 0 && q.length >= 2) {
            var sub = subsequenceScore(q, label.replace(/[^a-z0-9]/g, ''));
            if (sub > 0) best = 30 + Math.round(sub * 25);
        }

        if (best > 0 && item.type === 'tab') best -= 2; // modules edge out their tabs on ties
        return best;
    }

    // ------------------------------------------------------------------
    // Recents (nav/tab/action ids only; no personal data stored)
    // ------------------------------------------------------------------
    function readRecents() {
        try {
            var raw = JSON.parse(localStorage.getItem(RECENTS_KEY) || '[]');
            return Array.isArray(raw) ? raw : [];
        } catch (e) { return []; }
    }

    function pushRecent(id) {
        if (!id) return;
        try {
            var list = readRecents().filter(function (x) { return x !== id; });
            list.unshift(id);
            localStorage.setItem(RECENTS_KEY, JSON.stringify(list.slice(0, RECENTS_MAX)));
        } catch (e) { /* private mode etc. — recents are optional */ }
    }

    function resolveRecents() {
        var byId = {};
        items.forEach(function (it) { if (it.id) byId[it.id] = it; });
        return readRecents()
            .map(function (id) { return byId[id]; })
            .filter(Boolean);
    }

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------
    function iconSvg(name) {
        return '<svg class="ui-icon" aria-hidden="true" focusable="false"><use href="' + esc(SPRITE) + '#' +
            esc(name || 'search') + '"></use></svg>';
    }

    function highlight(label, q) {
        if (!q) return esc(label);
        var idx = label.toLowerCase().indexOf(q.toLowerCase());
        if (idx === -1) return esc(label);
        return esc(label.slice(0, idx)) +
            '<mark>' + esc(label.slice(idx, idx + q.length)) + '</mark>' +
            esc(label.slice(idx + q.length));
    }

    function rowHtml(entry, flatIdx, q) {
        var it = entry.item;
        var active = flatIdx === activeIndex;
        var sub = it.sub || (it.type === 'tab' ? 'Tab' : '');
        return '<button type="button" class="ui-palette__item" id="gs-opt-' + flatIdx + '" data-gs-idx="' + flatIdx + '" role="option" aria-selected="' + active + '">' +
            '<span class="ui-palette__icon">' + iconSvg(it.icon) + '</span>' +
            '<span class="ui-palette__text">' +
            '<span class="ui-palette__label">' + highlight(it.label, q) + '</span>' +
            (sub ? '<span class="ui-palette__sub">' + esc(sub) + '</span>' : '') +
            '</span>' +
            (active ? iconSvg('chevron-right') : '') +
            '</button>';
    }

    function emptyStateHtml(q) {
        return '<div class="ui-empty ui-empty--compact">' +
            '<div class="ui-empty__icon">' + iconSvg('search') + '</div>' +
            '<p class="ui-empty__title">No matches for “' + esc(q) + '”</p>' +
            '<p class="ui-empty__text">Try a module name, a tab, a person or an event.</p></div>';
    }

    function buildEntries(q) {
        var groups = {}; // groupName -> [{item}]
        var qNorm = q.toLowerCase().trim();

        if (!qNorm) {
            var recents = resolveRecents();
            if (recents.length) {
                groups.__recent__ = recents.map(function (it) { return { item: it }; });
            }
            items.forEach(function (it) {
                if (it.type === 'tab') return; // browse mode: modules & actions only
                (groups[it.group] = groups[it.group] || []).push({ item: it });
            });
        } else {
            var scored = [];
            items.forEach(function (it) {
                var s = scoreItem(it, qNorm);
                if (s > 0) scored.push({ item: it, score: s });
            });
            scored.sort(function (a, b) { return b.score - a.score; });
            scored.forEach(function (e) {
                var g = groups[e.item.group] = groups[e.item.group] || [];
                if (g.length < 6) g.push(e);
            });
            liveResults.people.forEach(function (p) {
                (groups.People = groups.People || []).push({
                    item: { label: p.label, sub: p.sub, url: p.url, group: 'People', icon: 'user-round' }
                });
            });
            liveResults.events.forEach(function (ev) {
                (groups.Events = groups.Events || []).push({
                    item: { label: ev.label, sub: ev.sub, url: ev.url, group: 'Events', icon: 'calendar' }
                });
            });
        }
        return groups;
    }

    function render() {
        var q = input.value.trim();
        var groups = buildEntries(q);
        var html = '';
        flatVisible = [];

        // Pre-count so the active row can be clamped before rows render
        var total = 0;
        GROUP_ORDER.forEach(function (g) { total += (groups[g] || []).length; });
        if (activeIndex >= total) activeIndex = Math.max(0, total - 1);

        GROUP_ORDER.forEach(function (g) {
            var list = groups[g];
            if (!list || !list.length) return;
            var title = g === '__recent__' ? 'Recent' : g;
            html += '<p class="ui-palette__group">' + esc(title) + '</p>';
            list.forEach(function (entry) {
                var idx = flatVisible.length;
                flatVisible.push(entry);
                html += rowHtml(entry, idx, q);
            });
        });

        if (!flatVisible.length) {
            html = q ? emptyStateHtml(q)
                : '<p class="ui-loading">Start typing to search.</p>';
        }
        results.innerHTML = html;
        input.setAttribute('aria-activedescendant', flatVisible.length ? 'gs-opt-' + activeIndex : '');

        var activeEl = results.querySelector('[data-gs-idx="' + activeIndex + '"]');
        if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
    }

    // ------------------------------------------------------------------
    // Live data search
    // ------------------------------------------------------------------
    function fetchLive(q) {
        var seq = ++liveSeq;
        if (q.length < 2) {
            liveResults = { people: [], events: [] };
            return;
        }
        spinner.hidden = false;
        var body = new URLSearchParams({ action: 'search', q: q });
        fetch('/api/global_search_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (seq !== liveSeq) return; // stale response
            spinner.hidden = true;
            if (res && res.status === 'success' && res.data) {
                liveResults = { people: res.data.people || [], events: res.data.events || [] };
                if (input.value.trim().toLowerCase() === q.toLowerCase()) render();
            }
        }).catch(function () {
            if (seq === liveSeq) spinner.hidden = true;
        });
    }

    // ------------------------------------------------------------------
    // Navigation
    // ------------------------------------------------------------------
    function go(entry) {
        var it = entry.item;
        if (!it.url) return;
        if (it.id) pushRecent(it.id);
        close();

        var target = new URL(it.url, window.location.origin);
        var samePage = target.pathname === window.location.pathname;
        if (samePage && target.hash) {
            // Same page: swap the hash and let tab-deeplink.js react to hashchange.
            if (window.location.hash === target.hash) {
                window.dispatchEvent(new HashChangeEvent('hashchange'));
            } else {
                window.location.hash = target.hash;
            }
        } else {
            window.location.href = it.url;
        }
    }

    // ------------------------------------------------------------------
    // Open / close
    // ------------------------------------------------------------------
    function open() {
        if (isOpen || !overlay) return;
        isOpen = true;
        liveResults = { people: [], events: [] };
        lastLiveQuery = '';
        liveSeq++; // invalidate any in-flight fetch from a previous session
        spinner.hidden = true;
        activeIndex = 0;
        opener = document.activeElement;
        overlay.hidden = false;
        input.value = '';
        render();
        setTimeout(function () { input.focus(); }, 30);
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        overlay.hidden = true;
        if (opener && opener.isConnected && opener.focus) opener.focus({ preventScroll: true });
    }

    function toggle() { isOpen ? close() : open(); }

    // ------------------------------------------------------------------
    // Wiring
    // ------------------------------------------------------------------
    function init() {
        overlay = document.getElementById('gsOverlay');
        if (!overlay) return;
        panel   = document.getElementById('gsPanel');
        input   = document.getElementById('gsInput');
        results = document.getElementById('gsResults');
        spinner = document.getElementById('gsSpinner');
        var boot = null;
        try { boot = JSON.parse((document.getElementById('hod-ui') || {}).textContent || 'null'); } catch (e) { boot = null; }
        items = boot && boot.search && Array.isArray(boot.search.index) ? boot.search.index
            : (Array.isArray(window.HOD_SEARCH_INDEX) ? window.HOD_SEARCH_INDEX : []);
        if (boot && boot.search && Array.isArray(boot.search.groups)) GROUP_ORDER = boot.search.groups;
        if (boot && boot.sprite) SPRITE = boot.sprite;

        // Platform-appropriate shortcut hints
        var kbd = isMac() ? '\u2318K' : 'Ctrl K';
        document.querySelectorAll('[data-gs-kbd]').forEach(function (el) { el.textContent = kbd; });

        document.querySelectorAll('[data-gs-open]').forEach(function (el) {
            el.addEventListener('click', function (e) { e.preventDefault(); open(); });
        });
        document.querySelectorAll('[data-gs-close]').forEach(function (el) {
            el.addEventListener('click', function (e) { e.preventDefault(); close(); });
        });

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && !e.altKey && (e.key === 'k' || e.key === 'K')) {
                e.preventDefault();
                toggle();
                return;
            }
            if (!isOpen) return;
            if (e.key === 'Escape') { e.preventDefault(); close(); }
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                if (!flatVisible.length) return;
                var dir = e.key === 'ArrowDown' ? 1 : -1;
                activeIndex = (activeIndex + dir + flatVisible.length) % flatVisible.length;
                render();
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (flatVisible[activeIndex]) go(flatVisible[activeIndex]);
            } else if (e.key === 'Tab') {
                e.preventDefault(); // keep focus in the palette
            }
        });

        input.addEventListener('input', function () {
            activeIndex = 0;
            render();
            clearTimeout(debounceTimer);
            var q = input.value.trim();
            if (q === lastLiveQuery) return;
            lastLiveQuery = q;
            debounceTimer = setTimeout(function () { fetchLive(q); }, 250);
        });

        results.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-gs-idx]');
            if (!btn) return;
            var entry = flatVisible[parseInt(btn.getAttribute('data-gs-idx'), 10)];
            if (entry) go(entry);
        });

        results.addEventListener('mousemove', function (e) {
            var btn = e.target.closest('[data-gs-idx]');
            if (!btn) return;
            var idx = parseInt(btn.getAttribute('data-gs-idx'), 10);
            if (idx !== activeIndex) { activeIndex = idx; render(); }
        });

        overlay.addEventListener('mousedown', function (e) {
            if (!panel.contains(e.target)) close();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
