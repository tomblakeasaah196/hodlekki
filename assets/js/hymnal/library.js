/* /assets/js/hymnal/library.js
 *
 * Finding a hymn.
 *
 * Three ways, because three different people are looking: the Pastor calls out
 * a number, a member half-remembers a line, and the choir master wants
 * everything on one shelf that has music. So a bare number jumps straight
 * there, the search reads titles and first lines, and the shelves are one tap.
 *
 * With nothing typed, the drawer shows what this member marked and what they
 * read lately first — which is what someone opening it during morning
 * devotions actually wants.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }
    var esc = HY.esc;

    var shelf = '';          /* the collection being browsed, '' for all */
    var scoreOnly = false;
    var searchTimer = null;
    var lastQuery = null;    /* so an in-flight answer cannot overwrite a newer one */

    /* ------------------------------------------------------------- shelves */

    HY.renderShelves = function () {
        var host = document.getElementById('hyShelves');
        if (!host) {
            return;
        }
        var shelves = (HY.boot.collections || []).filter(function (c) {
            /* An empty shelf is noise to a member; the Studio is where the
             * music team sees where a song is wanted. */
            return c.count > 0;
        });
        host.innerHTML = '<button type="button" class="hy-chip' + (shelf === '' ? ' is-on' : '')
            + '" data-shelf="">All ' + esc(HY.boot.counts ? HY.boot.counts.songs : '') + '</button>'
            + shelves.map(function (c) {
                return '<button type="button" class="hy-chip' + (shelf === c.slug ? ' is-on' : '')
                    + '" data-shelf="' + esc(c.slug) + '" title="' + esc(c.blurb) + '">'
                    + esc(c.name) + ' ' + esc(c.count) + '</button>';
            }).join('');
    };

    /* --------------------------------------------------------------- rows */

    function rowHtml(row) {
        var sub = [];
        if (row.first_line && row.first_line !== row.title) {
            sub.push(row.first_line);
        }
        if (row.writer) {
            sub.push(row.writer);
        }
        var tag = '';
        if (row.status && row.status !== 'published') {
            tag = '<span class="hy-hit-tag">' + esc(row.status) + '</span>';
        } else if (row.score) {
            tag = '<span class="hy-hit-tag">music</span>';
        }
        return '<button type="button" class="hy-hit" data-n="' + esc(row.n) + '">'
            + '<span class="hy-hit-n">' + esc(row.n) + '</span>'
            + '<span class="hy-hit-main">'
            + '<span class="hy-hit-title">' + esc(row.title) + '</span>'
            + (sub.length ? '<span class="hy-hit-sub">' + esc(sub.join(' · ')) + '</span>' : '')
            + '</span>' + tag + '</button>';
    }

    function group(title, rows) {
        if (!rows || !rows.length) {
            return '';
        }
        return '<p class="hy-group">' + esc(title) + '</p>' + rows.map(rowHtml).join('');
    }

    /* ------------------------------------------------------------ painting */

    function showStart() {
        var body = document.getElementById('hyLibraryBody');
        var count = document.getElementById('hyLibraryCount');
        if (!body) {
            return;
        }
        if (shelf !== '' || scoreOnly) {
            runSearch('');
            return;
        }
        HY.get('shelf').then(function (data) {
            var html = group('Your favourites', data.favourites)
                + group('Recently opened', data.recent);
            if (!html) {
                /* A member who has read nothing yet gets the start of the
                 * library rather than an empty drawer. */
                runSearch('');
                return;
            }
            body.innerHTML = html;
            if (count) {
                count.textContent = (HY.boot.counts ? HY.boot.counts.songs : '') + ' songs in the library';
            }
        }).catch(function () {
            runSearch('');
        });
    }

    function runSearch(q) {
        var body = document.getElementById('hyLibraryBody');
        var count = document.getElementById('hyLibraryCount');
        if (!body) {
            return;
        }
        var token = q + '|' + shelf + '|' + scoreOnly;
        lastQuery = token;
        HY.get('search', {
            q: q,
            collection: shelf,
            score_only: scoreOnly,
            limit: 80
        }).then(function (data) {
            if (lastQuery !== token) {
                return;   /* a newer search has already been asked for */
            }
            if (!data.rows.length) {
                body.innerHTML = '<p class="hy-empty">Nothing matched. Try a hymn number, '
                    + 'a word from the title, or a line you remember.</p>';
            } else {
                body.innerHTML = data.rows.map(rowHtml).join('');
            }
            if (count) {
                count.textContent = data.total + (data.total === 1 ? ' song' : ' songs')
                    + (data.rows.length < data.total ? ' — showing the first ' + data.rows.length : '');
            }
        }).catch(function (err) {
            if (lastQuery !== token) {
                return;
            }
            body.innerHTML = '<p class="hy-empty">' + esc(err.message || 'Search did not work.') + '</p>';
        });
    }

    HY.libraryOpened = function () {
        HY.renderShelves();
        var input = document.getElementById('hyQuery');
        var q = input ? input.value.trim() : '';
        if (q) {
            runSearch(q);
        } else {
            showStart();
        }
    };

    HY.librarySearch = function (q) {
        window.clearTimeout(searchTimer);
        var value = String(q || '').trim();
        /* A number is unambiguous, so it does not need the typing pause. */
        var delay = /^\d+$/.test(value) ? 0 : 220;
        searchTimer = window.setTimeout(function () {
            if (value === '') {
                showStart();
            } else {
                runSearch(value);
            }
        }, delay);
    };

    HY.librarySetShelf = function (slug) {
        shelf = slug || '';
        HY.renderShelves();
        var input = document.getElementById('hyQuery');
        HY.librarySearch(input ? input.value : '');
    };

    HY.librarySetScoreOnly = function (on) {
        scoreOnly = !!on;
        var btn = document.getElementById('hyScoreOnly');
        if (btn) {
            btn.setAttribute('aria-pressed', scoreOnly ? 'true' : 'false');
        }
        var input = document.getElementById('hyQuery');
        HY.librarySearch(input ? input.value : '');
    };

    /* --------------------------------------------------------- navigation */

    /* Opening a song is the one place the reader talks to the server on a tap,
     * so it keeps the current page up until the new one has arrived. */
    HY.openSong = function (n, opts) {
        opts = opts || {};
        return HY.get('song', { n: n, draft: opts.draft ? 1 : 0 }).then(function (song) {
            HY.choirStop && HY.choirStop();
            HY.state.song = song;
            HY.state.transpose = 0;
            HY.state.soloVoices = [];
            var langs = song.langs ? Object.keys(song.langs) : [];
            if (langs.indexOf(HY.state.lang) === -1 && langs.length) {
                HY.state.lang = langs.indexOf('en') !== -1 ? 'en' : langs[0];
            }
            var sel = document.getElementById('hyTranspose');
            if (sel) {
                sel.value = '0';
            }
            HY.renderSong();
            if (!opts.keepUrl && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', '?n=' + encodeURIComponent(song.n));
            }
            window.scrollTo({ top: 0, behavior: 'auto' });
            return song;
        }).catch(function (err) {
            HY.say(err.message || 'That hymn would not open.', 'error');
        });
    };

    /* Next and previous walk the library in number order, skipping gaps left
     * by retired songs rather than landing on a missing number. */
    HY.step = function (direction) {
        var song = HY.state.song;
        if (!song) {
            return;
        }
        HY.get('search', {
            q: '',
            collection: '',
            sort: 'number',
            limit: 200,
            offset: 0
        }).then(function (data) {
            var numbers = data.rows.map(function (r) { return Number(r.n); });
            var at = numbers.indexOf(Number(song.n));
            if (at === -1) {
                /* The current song is outside the first page: ask the server
                 * for the neighbour directly rather than guessing. */
                return HY.openSong(Number(song.n) + direction);
            }
            var next = numbers[at + direction];
            if (next === undefined) {
                HY.say(direction > 0 ? 'That is the last song.' : 'That is the first song.');
                return null;
            }
            return HY.openSong(next);
        }).catch(function () {
            HY.openSong(Number(song.n) + direction);
        });
    };
})(window, document);
