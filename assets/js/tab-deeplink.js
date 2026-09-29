// /assets/js/tab-deeplink.js
// Shared deep-link helper for authenticated module pages.
//
// Conventions supported in the URL hash:
//   #tab=<id>            -> calls the page's own switchTab('<id>') so palette
//                           links (and bookmarks) can land on a specific tab.
//   #q=<term>            -> pre-fills the page's roster/list search input
//                           (currently #searchRoster or [data-deeplink-search])
//                           and fires input/keyup so existing filters run.
//   #tab=<id>&q=<term>   -> both, in that order.
//
// Modules keep their existing client-side switchTab() implementations; this
// file only invokes them. Retries cover late-defined functions and rosters
// that hydrate over AJAX after DOMContentLoaded.
(function () {
    'use strict';

    function parseHash() {
        var raw = window.location.hash || '';
        if (raw.charAt(0) === '#') raw = raw.slice(1);
        if (!raw) return null;
        try {
            return new URLSearchParams(raw);
        } catch (e) {
            return null;
        }
    }

    function tryTab(tabId, attempt) {
        attempt = attempt || 0;
        if (typeof window.switchTab === 'function') {
            try {
                window.switchTab(tabId);
            } catch (e) {
                // A bad/unknown tab id must never break the page.
                if (window.console && console.warn) console.warn('tab-deeplink:', e);
            }
            return;
        }
        if (attempt < 4) {
            setTimeout(function () { tryTab(tabId, attempt + 1); }, 250 * (attempt + 1));
        }
    }

    function fireSearchEvents(input) {
        ['input', 'keyup', 'change'].forEach(function (type) {
            input.dispatchEvent(new Event(type, { bubbles: true }));
        });
    }

    function trySearch(term, attempt) {
        attempt = attempt || 0;
        var input = document.querySelector('[data-deeplink-search]') ||
            document.getElementById('searchRoster');
        if (!input) {
            if (attempt < 3) {
                setTimeout(function () { trySearch(term, attempt + 1); }, 400 * (attempt + 1));
            }
            return;
        }
        input.value = term;
        fireSearchEvents(input);
        // Rosters usually hydrate via AJAX after load — re-apply the filter
        // a few times so rows that arrive late get filtered too. Back off
        // immediately if the user starts typing something else.
        [800, 2000, 4000].forEach(function (delay) {
            setTimeout(function () {
                if (input.value === term) fireSearchEvents(input);
            }, delay);
        });
    }

    function apply() {
        var params = parseHash();
        if (!params) return;
        var tab = params.get('tab');
        var q = params.get('q');
        if (tab) tryTab(tab);
        if (q) trySearch(q);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', apply);
    } else {
        apply();
    }
    window.addEventListener('hashchange', apply);
})();
