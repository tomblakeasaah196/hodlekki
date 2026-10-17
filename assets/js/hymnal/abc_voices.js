/* /assets/js/hymnal/abc_voices.js
 *
 * Pull the voices out of a parsed abcjs tune, and name them.
 *
 * ONE implementation, used in two places on purpose:
 *
 *   - bin/hymnal_parse_abc.mjs requires it when generating the corpus;
 *   - assets/js/hymnal/studio.js loads it in the browser when the music team
 *     pastes a score.
 *
 * Both then hand the result to the same PHP function, hymnal_solfa_song(), so
 * a hymn's sol-fa and a Studio song's sol-fa come out of identical arithmetic
 * over identically-extracted notes. Writing this twice is exactly how the two
 * would drift, and a chorister would have no way of knowing which was wrong.
 *
 * Hence the UMD wrapper: node gets it through require(), the browser gets
 * window.HYAbcVoices, and there is no build step either way.
 */
(function (root, factory) {
    'use strict';
    if (typeof module === 'object' && module && module.exports) {
        module.exports = factory();
    } else {
        root.HYAbcVoices = factory();
    }
})(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var SATB = [
        ['S', 'Soprano'],
        ['A', 'Alto'],
        ['T', 'Tenor'],
        ['B', 'Bass']
    ];

    /* Walk the staves and collect, per voice, the notes bar by bar.
     *
     * abcjs reports a pitch as a DIATONIC step with 0 = middle C, which is
     * already what tonic sol-fa wants, so nothing here converts to semitones.
     */
    function collect(tune) {
        var staves = [];
        (tune.lines || []).forEach(function (line) {
            if (!line.staff) {
                return;
            }
            line.staff.forEach(function (staff, si) {
                if (!staves[si]) {
                    staves[si] = { clef: (staff.clef || {}).type || '', voices: [] };
                }
                (staff.voices || []).forEach(function (voice, vi) {
                    if (!staves[si].voices[vi]) {
                        staves[si].voices[vi] = [[]];
                    }
                    var bars = staves[si].voices[vi];
                    voice.forEach(function (el) {
                        if (el.el_type === 'bar') {
                            if (bars[bars.length - 1].length > 0) {
                                bars.push([]);
                            }
                            return;
                        }
                        if (el.el_type !== 'note') {
                            return;
                        }
                        var bar = bars[bars.length - 1];
                        if (el.rest) {
                            bar.push({ r: 1 });
                            return;
                        }
                        if (!el.pitches || !el.pitches.length) {
                            return;
                        }
                        /* Each SATB part is monophonic; where the engraver
                         * wrote a chord anyway, the top note is the one that
                         * voice sings. */
                        var top = el.pitches[el.pitches.length - 1];
                        var note = { p: top.pitch };
                        if (top.accidental) {
                            note.a = top.accidental;
                        }
                        if (el.startTie) {
                            note.ts = 1;
                        }
                        if (el.endTie) {
                            note.te = 1;
                        }
                        bar.push(note);
                    });
                });
            });
        });
        return staves;
    }

    /* Two staves of two voices is a hymn-book SATB score. Four staves of one
     * voice each is the same thing laid out differently. Anything else we will
     * not guess at: an unlabelled part is better than a tenor line labelled
     * "Alto", because a chorister would rehearse the wrong line. */
    function isSatb(staves) {
        var shape = staves.map(function (s) { return s.voices.length; });
        if (shape.length === 2 && shape[0] === 2 && shape[1] === 2) {
            return true;
        }
        return shape.length === 4 && shape.every(function (n) { return n === 1; });
    }

    /**
     * The named, flat voice list.
     *
     * @param  {object} tune an abcjs tune from renderAbc() or parseOnly()
     * @return {Array}  [{id, name, clef, bars: [[{p,a,ts,te}|{r:1}]]}]
     */
    function voicesFromTune(tune) {
        var staves = collect(tune).filter(function (s) { return s && s.voices.length; });
        var satb = isSatb(staves);
        var out = [];
        staves.forEach(function (staff, si) {
            staff.voices.forEach(function (bars) {
                var i = out.length;
                var bl = bars.filter(function (b) { return b.length > 0; });
                if (!bl.length) {
                    return;
                }
                var name = satb && SATB[i] ? SATB[i] : ['V' + (i + 1), 'Part ' + (i + 1)];
                out.push({ id: name[0], name: name[1], clef: staff.clef, bars: bl, staff: si });
            });
        });
        return out;
    }

    return {
        voicesFromTune: voicesFromTune,
        isSatb: isSatb
    };
});
