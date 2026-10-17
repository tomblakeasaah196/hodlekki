/* /assets/js/hymnal/core.js
 *
 * The Hymnary's shared ground: configuration, the API client, the current
 * state, and the few helpers everything else uses.
 *
 * Classic script on window.HODHymnal, like the Bible module — no bundler, no
 * modules, because the production host has no node and the platform rule is
 * jQuery and plain scripts outside Special Events.
 */
(function (window, document) {
    'use strict';

    var cfg = window.HOD_HYMNAL || {};
    var boot = cfg.boot || {};

    var HY = {
        cfg: cfg,
        boot: boot,

        /* The song on screen, and how it is being read. */
        state: {
            song: cfg.first || null,
            mode: (boot.state && boot.state.mode) || 'congregation',
            lang: (boot.state && boot.state.lang) || 'en',
            prefs: (boot.state && boot.state.prefs) || {},
            favourites: (boot.favourites || []).slice(),
            /* Voice ids currently sounding and shown. Empty means all of them. */
            soloVoices: [],
            transpose: 0
        }
    };

    /* ------------------------------------------------------------- escaping */

    /* Everything a member or the music team typed goes through here before it
     * reaches the page. Hymn text is data, never markup. */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    HY.esc = esc;

    /* ---------------------------------------------------------------- toast */

    HY.say = function (message, kind) {
        if (typeof window.Toastify !== 'function') {
            return;
        }
        window.Toastify({
            text: message,
            duration: kind === 'error' ? 6000 : 3200,
            gravity: 'top',
            position: 'center',
            close: true,
            style: {
                background: kind === 'error' ? '#D11920' : '#0A0E17',
                borderRadius: '10px',
                fontFamily: 'Inter, system-ui, sans-serif',
                fontSize: '14px'
            }
        }).showToast();
    };

    /* ------------------------------------------------------------------ api */

    var ENDPOINT = '/api/hymnal_api.php';

    function query(params) {
        var parts = [];
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (v === null || v === undefined || v === '' || v === false) {
                return;
            }
            parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v === true ? 1 : v));
        });
        return parts.length ? '?' + parts.join('&') : '';
    }

    /* A GET. Resolves with the payload's `data`, rejects with {code, message}
     * so every caller can show the server's own wording rather than inventing
     * its own. */
    HY.get = function (action, params) {
        var url = ENDPOINT + query(Object.assign({ action: action }, params || {}));
        return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(readJson);
    };

    /* A POST. JSON by default; pass a FormData and it is sent as multipart,
     * which is how a photograph of a song sheet reaches the AI reader. */
    HY.post = function (action, body) {
        var opts = { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (body instanceof window.FormData) {
            body.append('action', action);
            body.append('_csrf', cfg.csrf || '');
            opts.body = body;
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.headers['X-Hymnal-CSRF'] = cfg.csrf || '';
            opts.body = JSON.stringify(Object.assign({ action: action }, body || {}));
        }
        return window.fetch(ENDPOINT, opts).then(readJson);
    };

    function readJson(response) {
        return response.text().then(function (text) {
            var payload;
            try {
                payload = JSON.parse(text);
            } catch (e) {
                throw { code: 'ERROR', message: 'The server answered in a way we could not read.' };
            }
            if (!payload || payload.status !== 'success') {
                throw {
                    code: (payload && payload.code) || 'ERROR',
                    message: (payload && payload.message) || 'That did not work.',
                    errors: payload && payload.errors
                };
            }
            return payload.data;
        });
    }

    /* ------------------------------------------------------------- settings */

    /* Preferences are saved behind the member's back, so moving a slider does
     * not fire a request per step. They apply to the page immediately either
     * way, so nothing waits on the network. */
    var saveTimer = null;

    HY.prefs = function () {
        return HY.state.prefs || {};
    };

    HY.setPrefs = function (patch) {
        HY.state.prefs = Object.assign({}, HY.state.prefs, patch || {});
        HY.applyPrefs();
        if (!boot.ready) {
            return;
        }
        window.clearTimeout(saveTimer);
        saveTimer = window.setTimeout(function () {
            HY.post('state', { prefs: HY.state.prefs }).catch(function () {
                /* Reading must not stop because a preference did not save. */
            });
        }, 700);
    };

    HY.applyPrefs = function () {
        var root = document.getElementById('hyRoot');
        if (!root) {
            return;
        }
        var p = HY.prefs();
        root.setAttribute('data-page', p.page || 'paper');
        root.setAttribute('data-size', String(p.text_size || 3));
    };

    HY.setMode = function (mode) {
        HY.state.mode = mode === 'choir' ? 'choir' : 'congregation';
        if (boot.ready) {
            HY.post('state', { mode: HY.state.mode }).catch(function () {});
        }
    };

    /* ------------------------------------------------------------- helpers */

    HY.isFavourite = function (n) {
        return HY.state.favourites.indexOf(Number(n)) !== -1;
    };

    HY.markFavourite = function (n, on) {
        n = Number(n);
        var at = HY.state.favourites.indexOf(n);
        if (on && at === -1) {
            HY.state.favourites.push(n);
        } else if (!on && at !== -1) {
            HY.state.favourites.splice(at, 1);
        }
    };

    /* The words of the song in the language on screen, falling back to
     * whatever the song does have, so a Yoruba-only version still reads. */
    HY.words = function (song) {
        if (!song || !song.langs) {
            return null;
        }
        if (song.langs[HY.state.lang]) {
            return song.langs[HY.state.lang];
        }
        if (song.langs.en) {
            return song.langs.en;
        }
        var keys = Object.keys(song.langs);
        return keys.length ? song.langs[keys[0]] : null;
    };

    HY.on = function (id, event, handler) {
        var node = document.getElementById(id);
        if (node) {
            node.addEventListener(event, handler);
        }
        return node;
    };

    window.HODHymnal = HY;
})(window, document);
