// /assets/js/global-search.js
// Global command palette (Ctrl/Cmd+K) for the authenticated app shell.
//
// Reads the RBAC-filtered navigation index emitted by includes/header.php as
// window.HOD_SEARCH_INDEX (modules, module tabs, quick actions), fuzzy-matches
// it client-side, and augments results with live people/event lookups from
// /api/global_search_api.php (which enforces its own role checks).
(function () {
    'use strict';

    var RECENTS_KEY = 'hod_gs_recents_v1';
    var RECENTS_MAX = 6;
    var GROUP_ORDER = [
        '__recent__', 'Command Center', 'Growth & Retention', 'Specialized Units',
        'Core Operations', 'Security & Stewardship', 'Actions', 'People', 'Events'
    ];

    var overlay, panel, input, results, spinner;
    var isOpen = false;
    var items = [];            // static index (nav + tabs + actions)
    var liveResults = { people: [], events: [] };
    var flatVisible = [];      // currently rendered, selectable entries
    var activeIndex = 0;
    var debounceTimer = null;
    var liveSeq = 0;
    var lastLiveQuery = '';

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
    function iconSvg(pathD, cls) {
        if (!pathD) {
            pathD = 'M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z';
        }
        return '<svg class="' + (cls || 'w-[18px] h-[18px]') + ' shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">' +
            '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="' + esc(pathD) + '"></path></svg>';
    }

    function highlight(label, q) {
        if (!q) return esc(label);
        var idx = label.toLowerCase().indexOf(q.toLowerCase());
        if (idx === -1) return esc(label);
        return esc(label.slice(0, idx)) +
            '<mark class="bg-yellow-100 text-inherit rounded-sm px-0">' + esc(label.slice(idx, idx + q.length)) + '</mark>' +
            esc(label.slice(idx + q.length));
    }

    function rowHtml(entry, flatIdx, q) {
        var it = entry.item;
        var active = flatIdx === activeIndex;
        var sub = it.sub ? '<span class="text-[11px] text-gray-400 truncate">' + esc(it.sub) + '</span>' : '';
        return '<button type="button" data-gs-idx="' + flatIdx + '" role="option" aria-selected="' + active + '" class="w-full flex items-center gap-3 px-4 py-2.5 text-left transition-colors ' +
            (active ? 'bg-hodBlue/[0.06] text-hodBlue' : 'text-gray-700 hover:bg-gray-50') + '">' +
            '<span class="w-8 h-8 rounded-lg flex items-center justify-center shrink-0 ' + (active ? 'bg-hodBlue text-white' : 'bg-gray-100 text-gray-500') + '">' +
            iconSvg(it.icon) + '</span>' +
            '<span class="flex-1 min-w-0 flex flex-col">' +
            '<span class="text-sm font-semibold truncate">' + highlight(it.label, q) + '</span>' + sub +
            '</span>' +
            (it.type === 'tab' ? '<span class="text-[9px] font-bold uppercase tracking-wider text-gray-400 bg-gray-100 rounded-md px-1.5 py-0.5 shrink-0">Tab</span>' : '') +
            (active ? '<svg class="w-4 h-4 text-hodBlue/60 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>' : '') +
            '</button>';
    }

    function emptyStateHtml(q) {
        return '<div class="px-6 py-12 text-center">' +
            '<div class="w-14 h-14 mx-auto mb-3 rounded-2xl bg-gray-100 flex items-center justify-center text-gray-400">' +
            iconSvg('M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z', 'w-6 h-6') + '</div>' +
            '<p class="text-sm font-bold text-gray-700">No matches for &ldquo;' + esc(q) + '&rdquo;</p>' +
            '<p class="text-xs text-gray-400 mt-1">Try a module name, a tab, a person or an event.</p></div>';
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
                    item: { label: p.label, sub: p.sub, url: p.url, group: 'People', icon: 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z' }
                });
            });
            liveResults.events.forEach(function (ev) {
                (groups.Events = groups.Events || []).push({
                    item: { label: ev.label, sub: ev.sub, url: ev.url, group: 'Events', icon: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z' }
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
            html += '<p class="px-4 pt-4 pb-1.5 text-[10px] font-bold uppercase tracking-widest text-gray-400">' + esc(title) + '</p>';
            list.forEach(function (entry) {
                var idx = flatVisible.length;
                flatVisible.push(entry);
                html += rowHtml(entry, idx, q);
            });
        });

        if (!flatVisible.length) {
            html = q ? emptyStateHtml(q)
                : '<div class="px-6 py-10 text-center text-xs text-gray-400 font-semibold">Start typing to search…</div>';
        }
        results.innerHTML = html;

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
        spinner.classList.remove('hidden');
        var body = new URLSearchParams({ action: 'search', q: q });
        fetch('/api/global_search_api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (seq !== liveSeq) return; // stale response
            spinner.classList.add('hidden');
            if (res && res.status === 'success' && res.data) {
                liveResults = { people: res.data.people || [], events: res.data.events || [] };
                if (input.value.trim().toLowerCase() === q.toLowerCase()) render();
            }
        }).catch(function () {
            if (seq === liveSeq) spinner.classList.add('hidden');
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
        spinner.classList.add('hidden');
        activeIndex = 0;
        overlay.classList.remove('hidden');
        document.documentElement.classList.add('overflow-hidden');
        requestAnimationFrame(function () {
            overlay.classList.remove('opacity-0');
            panel.classList.remove('scale-95', '-translate-y-2');
        });
        input.value = '';
        render();
        setTimeout(function () { input.focus(); }, 30);
    }

    function close() {
        if (!isOpen) return;
        isOpen = false;
        overlay.classList.add('opacity-0');
        panel.classList.add('scale-95', '-translate-y-2');
        document.documentElement.classList.remove('overflow-hidden');
        setTimeout(function () { if (!isOpen) overlay.classList.add('hidden'); }, 200);
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
        items   = Array.isArray(window.HOD_SEARCH_INDEX) ? window.HOD_SEARCH_INDEX : [];

        // Platform-appropriate shortcut hints
        var kbd = isMac() ? '\u2318K' : 'Ctrl K';
        document.querySelectorAll('[data-gs-kbd]').forEach(function (el) { el.textContent = kbd; });

        document.querySelectorAll('[data-gs-open]').forEach(function (el) {
            el.addEventListener('click', function (e) { e.preventDefault(); open(); });
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
