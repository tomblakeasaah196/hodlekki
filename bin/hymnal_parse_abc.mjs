// /bin/hymnal_parse_abc.mjs
//
// Stage one of the Hymnary corpus build (stage two is bin/build_hymnal.php).
//
// Reads the Open Hymnal ABC Plus scores and, for each one, writes a JSON file
// holding a cleaned copy of the ABC plus the structure stage two needs:
// the key, the meter, the voices, and every note as a diatonic step. Nothing
// here decides anything about the corpus — it only turns ABC into data.
//
// Why node, when the rest of the platform is PHP: abcjs is the same parser the
// reader uses in the browser, so the notes stage two writes sol-fa for are
// exactly the notes a chorister sees on the staff. Hand-rolling an ABC parser
// in PHP would be a second implementation to keep in step, and silently wrong
// sol-fa is worse than none. The production host never runs this — the corpus
// is generated here and committed, like includes/bible/kjv/.
//
// Usage: node bin/hymnal_parse_abc.mjs <abc-dir> <out-dir>
//   abc-dir   .abc files, named <source-id>.abc
//   out-dir   written as <source-id>.json
//
// Run from the repository root: it loads the vendored abcjs and the shared
// voice extractor by relative path.

import fs from 'node:fs';
import path from 'node:path';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);

// The SAME code the browser runs when the music team pastes a score in the
// Studio, so a hymn's notes and a Studio song's notes are extracted
// identically before either reaches hymnal_solfa_song(). See that file's
// header for why this is shared rather than written twice.
const { voicesFromTune } = require('../assets/js/hymnal/abc_voices.js');

function loadAbcjs() {
    // Prefer the bytes we ship, so the build and the browser agree.
    const vendored = path.resolve('assets/hymnal/vendor/abcjs-6.7.2/abcjs-basic-min.js');
    if (fs.existsSync(vendored)) {
        const src = fs.readFileSync(vendored, 'utf8');
        const module_ = { exports: {} };
        // The bundle is UMD; with no window/define present it assigns to
        // module.exports, and nothing in the parse path touches the DOM.
        new Function('module', 'exports', src)(module_, module_.exports);
        if (module_.exports && module_.exports.parseOnly) {
            return module_.exports;
        }
    }
    return require('abcjs');
}

const abcjs = loadAbcjs();

// ---------------------------------------------------------------------------
// Cleaning
// ---------------------------------------------------------------------------

// Directives and decorations that only mean something to abcm2ps, the
// typesetter the Open Hymnal used to make its PDFs. abcjs warns about every
// one of them, which would bury a real problem in 2,800 lines of noise, and
// the page layout ones would fight our own responsive layout anyway.
const DROP_DIRECTIVES = new Set([
    'pagewidth', 'pageheight', 'topmargin', 'botmargin', 'leftmargin', 'rightmargin',
    'scale', 'staffsep', 'sysstaffsep', 'maxsysstaffsep', 'exprabove', 'exprbelow',
    'measurebox', 'postscript', 'deco', 'setfont-1', 'setfont-2', 'setfont-3',
    'setfont-4', 'titlefont', 'subtitlefont', 'composerfont', 'vocalfont',
    'wordsfont', 'gchordfont', 'partsfont', 'textfont', 'infofont', 'tempofont',
    'annotationfont', 'footer', 'header', 'maxshrink', 'parskipfac', 'lineskipfac',
    'textoption', 'musicspace', 'vocalspace', 'textspace', 'topspace', 'titlespace',
    'subtitlespace', 'composerspace', 'wordsspace', 'partsspace', 'staffwidth',
    'indent', 'landscape', 'oneperpage', 'splittune', 'printtempo', 'graceslurs',
    'multicol', 'combinevoices',
]);

// %%beginps … %%endps wraps raw PostScript for abcm2ps. Dropping only the
// opening directive would leave the PostScript itself in the music.
const BLOCK_DIRECTIVES = { beginps: 'endps', begintext: 'endtext' };

// Rewrites inside music lines only. These are not cosmetic: a fermata tells a
// choir to hold the note, so it has to survive into the score the reader
// draws. The Open Hymnal writes abcm2ps spellings that abcjs does not know.
const MUSIC_REWRITES = [
    // abcm2ps shorthand for a fermata; abcjs spells it in full.
    ['!ferm!', '!fermata!'],
    ['!invertedferm!', '!invertedfermata!'],
    // A dashed tie in abcm2ps. abcjs has no dashed variant, so keep the tie
    // and lose only its dashes — the notes stay tied, which is what is sung.
    ['.-', '-'],
    // Likewise a dashed slur: keep the slur, lose only the dashes.
    ['.(', '('],
    // An empty decoration: a typo in the source, with nothing to preserve.
    ['!()!', ''],
];

// Custom decorations the Open Hymnal defines with %%deco to bracket the
// introduction. Without the %%deco definition abcjs reports them as unknown.
const DROP_DECORATIONS = ['sintro', 'eintro'];

function isMusicLine(line) {
    // Anything that is not a comment, a directive or an information field
    // (including w: lyric rows, which must not be rewritten).
    return !/^%/.test(line) && !/^[A-Za-z]:/.test(line);
}

export function cleanAbc(raw) {
    const kept = [];
    const meta = {};
    let skipUntil = null;
    for (const line of raw.split(/\r?\n/)) {
        if (skipUntil) {
            if (new RegExp('^%%\\s*' + skipUntil).test(line)) {
                skipUntil = null;
            }
            continue;
        }
        const block = /^%%\s*([A-Za-z0-9-]+)/.exec(line);
        if (block && BLOCK_DIRECTIVES[block[1]]) {
            skipUntil = BLOCK_DIRECTIVES[block[1]];
            continue;
        }
        // Open Hymnal keeps its catalogue data in %OH… comments. Harvest them
        // before dropping the comment, then let stage two decide what to use.
        const oh = /^%OH([A-Z]+)\s+(.*)$/.exec(line);
        if (oh) {
            meta[oh[1].toLowerCase()] = oh[2].trim();
            continue;
        }
        const directive = /^%%\s*([A-Za-z0-9-]+)/.exec(line);
        if (directive && DROP_DIRECTIVES.has(directive[1])) {
            continue;
        }
        if (/^%/.test(line) && !/^%%/.test(line)) {
            continue; // plain comment: the long licence preamble lives here
        }
        if (isMusicLine(line)) {
            let music = line;
            for (const [from, to] of MUSIC_REWRITES) {
                music = music.split(from).join(to);
            }
            kept.push(music);
            continue;
        }
        kept.push(line);
    }
    let abc = kept.join('\n');
    for (const deco of DROP_DECORATIONS) {
        abc = abc.split('!' + deco + '!').join('');
    }
    // Trailing "% comment" after a field (e.g. "M: 3/4 % time signature").
    abc = abc.replace(/^([A-Za-z]:[^\n%]*?)\s*%[^\n]*$/gm, '$1');
    abc = abc.replace(/\n{3,}/g, '\n\n').trim() + '\n';
    return { abc, meta };
}

// ---------------------------------------------------------------------------
// Extraction
// ---------------------------------------------------------------------------

// The w: rows under the melody carry every verse, hyphenated to fit the notes.
// Joining them back up gives the sung text in the order the music takes it,
// which is how we check the lyrics from the chord chart line up with the tune.
function extractVerses(tune) {
    const rows = [];
    for (const line of tune.lines || []) {
        for (const staff of line.staff || []) {
            const voice = (staff.voices || [])[0];
            if (!voice) continue;
            for (const el of voice) {
                if (!el.lyric) continue;
                el.lyric.forEach((syl, i) => {
                    if (!rows[i]) rows[i] = '';
                    rows[i] += syl.syllable + (syl.divider === '-' ? '\u0001' : ' ');
                });
            }
            break; // lyrics ride the top voice only
        }
    }
    return rows.map((r) =>
        r
            .split('\u0001')
            .join('')          // rejoin hyphenated syllables: "Fath- er" -> "Father"
            .replace(/\s+/g, ' ')
            .replace(/\s+([,.;:!?])/g, '$1')
            .trim()
    );
}

function titlesOf(abc) {
    return [...abc.matchAll(/^T:\s*(.+)$/gm)].map((m) => m[1].trim()).filter(Boolean);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

function main() {
    const [abcDir, outDir] = process.argv.slice(2);
    if (!abcDir || !outDir) {
        console.error('usage: node bin/hymnal_parse_abc.mjs <abc-dir> <out-dir>');
        process.exit(2);
    }
    fs.mkdirSync(outDir, { recursive: true });
    const files = fs.readdirSync(abcDir).filter((f) => f.endsWith('.abc')).sort();

    let written = 0;
    const problems = [];
    const excluded = [];
    let warnings = 0;

    for (const file of files) {
        const id = file.replace(/\.abc$/, '');
        const raw = fs.readFileSync(path.join(abcDir, file), 'utf8');
        const { abc, meta } = cleanAbc(raw);
        let tune;
        try {
            tune = abcjs.parseOnly(abc, {})[0];
        } catch (e) {
            problems.push(`${id}: threw ${e.message}`);
            continue;
        }
        if (!tune) {
            problems.push(`${id}: no tune`);
            continue;
        }
        // A score that still warns is a score we do not understand fully, and
        // a chorister reading a staff with a voice silently missing from it is
        // worse served than one told the notes are not available. So the song
        // keeps its words and loses its score, and the reason is recorded.
        const warns = tune.warnings || [];
        if (warns.length) {
            warnings += warns.length;
            const reason = String(warns[0]).replace(/<[^>]*>/g, '').slice(0, 160);
            excluded.push({ id, warnings: warns.length, reason });
            problems.push(`${id}: EXCLUDED, ${warns.length} warning(s): ${reason.slice(0, 110)}`);
            continue;
        }
        const voices = voicesFromTune(tune);
        const noteCount = voices.reduce(
            (n, v) => n + v.bars.reduce((k, b) => k + b.length, 0),
            0
        );
        if (noteCount === 0) {
            excluded.push({ id, warnings: 0, reason: 'parsed but produced no notes' });
            problems.push(`${id}: EXCLUDED, parsed but no notes`);
            continue;
        }
        fs.writeFileSync(
            path.join(outDir, id + '.json'),
            JSON.stringify({
                id,
                abc,
                oh: meta,
                titles: titlesOf(abc),
                key: tune.getKeySignature ? tune.getKeySignature() : null,
                meter: tune.getMeter ? tune.getMeter() : null,
                metaText: tune.metaText || {},
                voices,
                verses: extractVerses(tune),
                notes: noteCount,
            })
        );
        written++;
    }

    fs.writeFileSync(
        path.join(outDir, '_excluded.json'),
        JSON.stringify(excluded.sort((a, b) => a.id.localeCompare(b.id)), null, 1)
    );

    console.log(JSON.stringify({ files: files.length, written, excluded: excluded.length, warnings }, null, 0));
    if (problems.length) {
        console.log('--- excluded scores (' + problems.length + ') ---');
        problems.forEach((p) => console.log('  ' + p));
    }
    // Stage two reads _excluded.json and the test suite pins its length, so a
    // new malformed source shows up as a failing count rather than silently
    // costing a hymn its notes.
}

main();
