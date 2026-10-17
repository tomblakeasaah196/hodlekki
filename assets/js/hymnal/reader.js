/* /assets/js/hymnal/reader.js
 *
 * Congregation mode: the words, big enough to sing from at arm's length.
 *
 * This is the mode the Pastor asked for first — a member on their phone during
 * morning devotions, or the whole church when the screen is down. So it is
 * words and nothing else by default: no staff, no controls in the way, a
 * refrain set apart the way a hymn book sets it apart, and chords only if
 * somebody asks for them.
 */
(function (window, document) {
    'use strict';

    var HY = window.HODHymnal;
    if (!HY) {
        return;
    }
    var esc = HY.esc;

    /* ------------------------------------------------------------- the head */

    function headHtml(song) {
        var words = HY.words(song) || {};
        var bits = [];
        if (song.writer) {
            bits.push(esc(song.writer));
        }
        if (song.year) {
            bits.push(esc(song.year));
        }
        if (song.metre) {
            bits.push('Metre ' + esc(song.metre));
        }
        if (song.key) {
            bits.push('Key of ' + esc(song.key));
        }

        return '<header class="hy-song-head">'
            + '<span class="hy-song-num">Hymn ' + esc(song.n) + '</span>'
            + '<h1 class="hy-song-title">' + esc(words.title || song.title) + '</h1>'
            + (bits.length ? '<p class="hy-song-sub"><span>' + bits.join('</span><span>') + '</span></p>' : '')
            + '</header>';
    }

    /* ---------------------------------------------------------- the verses */

    /* One ChordPro line into chord-over-syllable markup.
     *
     * A chord belongs above the syllable it falls on, so the words keep their
     * own line length and a long chord name cannot push the sentence about.
     * That is what the absolute-positioned <i> in hymnal.css is for. */
    function chordLine(line) {
        var out = '';
        var re = /\[([^\]]*)\]/g;
        var at = 0;
        var m;
        var any = false;
        while ((m = re.exec(line)) !== null) {
            var before = line.slice(at, m.index);
            if (before) {
                out += '<span class="hy-ch">' + esc(before) + '</span>';
            }
            at = m.index + m[0].length;
            /* The chord attaches to the text that follows it, up to the next
             * chord, so it sits over the right syllable. */
            var next = re.lastIndex;
            var following = line.slice(next).split('[')[0];
            out += '<span class="hy-ch"><i>' + esc(m[1]) + '</i>' + esc(following) + '</span>';
            at = next + following.length;
            re.lastIndex = at;
            any = true;
        }
        if (at < line.length) {
            out += '<span class="hy-ch">' + esc(line.slice(at)) + '</span>';
        }
        return { html: out, hasChords: any };
    }

    /* The ChordPro text, split back into labelled sections, for the chords
     * view. The plain words come from `sections`, which is already clean. */
    function chordSections(chordpro) {
        var blocks = String(chordpro || '').trim().split(/\r?\n\s*\r?\n/);
        var label = /^(verse|chorus|refrain|pre-?chorus|bridge|tag|ending|intro|interlude|coda|vamp)(\s+\d+)?$/i;
        return blocks.map(function (block) {
            var lines = block.split(/\r?\n/).map(function (l) { return l.trim(); })
                .filter(function (l) { return l !== '' && l.charAt(0) !== '{'; });
            var head = '';
            if (lines.length && label.test(lines[0])) {
                head = lines.shift();
            }
            return { label: head, lines: lines };
        }).filter(function (s) { return s.lines.length > 0; });
    }

    function versesHtml(song) {
        var words = HY.words(song);
        if (!words || !words.sections || !words.sections.length) {
            return '<p class="hy-empty">The words for this song have not been added yet.</p>';
        }

        var showChords = !!HY.prefs().show_chords && String(words.chordpro || '').indexOf('[') !== -1;
        if (showChords) {
            return chordSections(words.chordpro).map(function (sec) {
                var kind = /chorus|refrain/i.test(sec.label) ? 'chorus' : 'verse';
                var body = sec.lines.map(function (l) {
                    return '<span class="hy-line">' + chordLine(l).html + '</span>';
                }).join('');
                return '<section class="hy-sec" data-kind="' + kind + '">'
                    + (sec.label ? '<span class="hy-sec-label">' + esc(sec.label) + '</span>' : '')
                    + '<p class="hy-sec-lines hy-chordline">' + body + '</p>'
                    + '</section>';
            }).join('');
        }

        return words.sections.map(function (sec) {
            var lines = (sec.lines || []).map(function (l) {
                return '<span class="hy-line">' + esc(l) + '</span>';
            }).join('');
            /* The verse number runs into the first line, as a hymn book does,
             * rather than sitting on a line of its own. */
            var num = sec.kind === 'verse' && sec.num
                ? '<span class="hy-verse-no">' + esc(sec.num) + '</span>'
                : '';
            var label = sec.kind === 'verse'
                ? ''
                : '<span class="hy-sec-label">' + esc(sec.label || sec.kind) + '</span>';
            return '<section class="hy-sec" data-kind="' + esc(sec.kind || 'verse') + '">'
                + label
                + '<p class="hy-sec-lines">' + num + lines + '</p>'
                + '</section>';
        }).join('');
    }

    /* ------------------------------------------------------------- painting */

    HY.renderSong = function () {
        var song = HY.state.song;
        var host = document.getElementById('hySong');
        if (!host) {
            return;
        }
        if (!song) {
            host.innerHTML = '<p class="hy-empty">Choose a hymn from the library.</p>';
            return;
        }

        var html = headHtml(song);
        if (HY.state.mode === 'choir') {
            html += HY.choirHtml ? HY.choirHtml(song) : '';
        }
        html += versesHtml(song);
        host.innerHTML = html;

        if (HY.state.mode === 'choir' && HY.choirMounted) {
            HY.choirMounted(song);
        }

        paintChrome(song);
    };

    /* The bits outside the song itself: where-you-are, the favourite button,
     * the transport, and whether the language picker is any use for this song. */
    function paintChrome(song) {
        var n = document.getElementById('hyWhereNumber');
        var t = document.getElementById('hyWhereTitle');
        if (n) {
            n.textContent = song.n;
        }
        if (t) {
            t.textContent = (HY.words(song) || song).title || song.title;
        }

        /* An icon-only heart, so its name carries the state: a filled heart
         * and "Remove from your favourites" once it is marked. */
        var fav = document.getElementById('hyFavourite');
        if (fav) {
            var on = HY.isFavourite(song.n);
            var name = on ? 'Remove from your favourites' : 'Add to your favourites';
            fav.setAttribute('aria-pressed', on ? 'true' : 'false');
            fav.setAttribute('aria-label', name);
            fav.disabled = !HY.boot.ready;
            fav.title = HY.boot.ready ? name : 'Marking favourites switches on once the Hymnary is set up.';
        }

        if (HY.share && HY.share.paint) {
            HY.share.paint(song);
        }

        /* Opening a song resets the key to as written; the options button
         * must not go on showing the last song's "+2". */
        if (HY.paintChoirSummary) {
            HY.paintChoirSummary();
        }

        var transport = document.getElementById('hyTransport');
        if (transport) {
            transport.hidden = !(HY.state.mode === 'choir' && song.has && song.has.score);
        }

        var langField = document.getElementById('hyLangField');
        var langs = song.langs ? Object.keys(song.langs) : [];
        if (langField) {
            langField.hidden = langs.length < 2;
            if (langs.length >= 2) {
                var seg = document.getElementById('hyLangs');
                if (seg) {
                    seg.innerHTML = langs.map(function (code) {
                        var on = code === HY.state.lang ? ' is-on' : '';
                        return '<button type="button" class="' + on.trim() + '" data-lang="' + esc(code) + '">'
                            + esc(song.langs[code].label || code) + '</button>';
                    }).join('');
                }
            }
        }

        /* A chords toggle is only honest when the song has chords. */
        var chords = document.getElementById('hyChords');
        if (chords) {
            var words = HY.words(song) || {};
            var has = String(words.chordpro || '').indexOf('[') !== -1;
            chords.disabled = !has;
            chords.checked = has && !!HY.prefs().show_chords;
            var wrap = chords.closest('.hy-switch');
            if (wrap) {
                wrap.title = has ? '' : 'This song has no chords yet.';
            }
        }
    }

    /* ---------------------------------------------------------------- about */

    HY.renderAbout = function () {
        var song = HY.state.song;
        var body = document.getElementById('hyAboutBody');
        if (!body || !song) {
            return;
        }
        var rows = [];
        var add = function (k, v) {
            if (v) {
                rows.push('<div class="hy-about-row"><div class="hy-about-k">' + esc(k)
                    + '</div><div class="hy-about-v">' + v + '</div></div>');
            }
        };
        add('Hymn number', esc(song.n));
        add('Words', esc(song.writer || song.attribution));
        add('Music', esc(song.composer));
        add('Arranged', esc(song.arranger));
        add('Tune', esc(song.tune));
        add('Written', esc(song.year));
        add('Metre', esc(song.metre));
        add('Key', esc(song.key));
        add('Time', esc(song.time));
        if (song.themes && song.themes.length) {
            add('Themes', song.themes.map(esc).join(', '));
        }
        if (song.scripture && song.scripture.length) {
            /* The Bible module is on this platform, so a reference is a link
             * to the passage rather than something to go and look up. */
            add('Scripture', song.scripture.map(function (ref) {
                return '<a href="/modules/bible/index.php?ref=' + encodeURIComponent(ref) + '">' + esc(ref) + '</a>';
            }).join(' · '));
        }

        var lic = song.licence || {};
        var parts = (lic.parts || []).map(function (p) {
            return '<span class="hy-part">' + esc(p.part) + ': ' + esc(p.licence)
                + (p.basis ? ' (' + esc(p.basis) + ')' : '') + '</span>';
        }).join('');
        var licence = '<div class="hy-licence"><b>' + esc(lic.label || 'Licence') + '</b>'
            + esc(lic.detail || '')
            + (lic.holder ? '<br>Held by ' + esc(lic.holder) : '')
            + (lic.ccli ? '<br>CCLI song ' + esc(lic.ccli) : '')
            + (parts ? '<div class="hy-parts">' + parts + '</div>' : '')
            + '</div>';

        var src = song.source || {};
        var source = '';
        if (src.project) {
            source = '<div class="hy-licence">Source: ' + esc(src.project)
                + (src.via ? ', via ' + esc(src.via) : '')
                + (src.url ? ' — <a href="' + esc(src.url) + '" target="_blank" rel="noopener noreferrer">see the original</a>' : '')
                + '</div>';
        }

        body.innerHTML = rows.join('') + licence + source;
    };

    HY.chordLine = chordLine;
})(window, document);
