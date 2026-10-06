// /tests/special_events/js/program_poster.test.mjs
//
// The programme poster (guide §14.2b) and the publish gate above it.
//
//   node --test tests/special_events/js/
//
// The poster is built rather than templated, so the thing most worth
// testing is the promise the producer was made: ALL of the programme, on
// ONE page, nothing cut off. These tests draw real posters at both sizes
// for 1 … 40 items and check that every box and every baseline lands
// inside the canvas.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

register('./vendor_loader.mjs', import.meta.url);

globalThis.document ??= {
    getElementById: () => null,
    addEventListener: () => {},
    visibilityState: 'visible',
};
globalThis.fetch ??= async () => { throw new Error('no network in tests'); };

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');
const read = (p) => readFileSync(join(repo, p), 'utf8');

const {
    POSTER_FORMATS,
    buildProgramPosterSvg,
    planPosterRows,
    posterFilename,
    posterItems,
    ellipsise,
    fitBlock,
    makeMeasurer,
    esc,
} = await import('@se/studio/program_poster.js');

const { publishBarCopy } = await import('@se/studio/tabs/program.js');

// --------------------------------------------------------------------------

const ITEMS = [
    'Doors open', 'Welcome', 'Worship', 'Ice breaker', 'The debate',
    'Karaoke round one', 'Food & fellowship', 'Testimonies', 'The word',
    'Karaoke round two', 'Games', 'Prayer', 'Goodnights',
];

function payload(count = ITEMS.length) {
    return {
        published: true,
        days: [{
            day_id: 7,
            date: 'Saturday 24 October',
            doors: 'Doors 3:30 PM',
            start: '4:00 PM',
            items: Array.from({ length: count }, (unused, index) => ({
                time: '~' + (3 + Math.floor(index / 2)) + ':' + (index % 2 ? '30' : '00') + ' PM',
                title: ITEMS[index % ITEMS.length] + (index >= ITEMS.length ? ' ' + index : ''),
                blurb: index % 3 === 0 ? 'A sentence about what happens in this bit of the night.' : '',
                host: index % 4 === 0 ? 'Pastor Ada' : '',
                featured: index % 5 === 0,
            })),
        }],
        time_mode: 'approximate',
        note: 'Times are approximate — the night runs on joy, not a stopwatch.',
        text: {
            organizer: 'Envision', title: 'Chara', edition: '2026',
            tagline: 'One night of joy, games and the word.',
            venue: 'Household of David, Lekki', url: 'hodlc.lpc.cm/e/chara',
            heading: 'The programme',
        },
        qr: 'https://hodlc.lpc.cm/e/chara',
        hero: null,
        colors: {
            primary: '#1D356A', secondary: '#D11920', accent: '#F5C518',
            bg: '#0B0D13', surface: '#151822', text: '#FFFFFF',
        },
        fonts: { display: 'Unbounded', body: 'Space Grotesk' },
        filename: 'chara-2026-programme',
    };
}

/** Every drawn box and every baseline in the source, as numbers. */
function bounds(svg) {
    const out = { maxX: 0, maxY: 0, minX: Infinity, minY: Infinity };
    const note = (x, y) => {
        out.maxX = Math.max(out.maxX, x);
        out.maxY = Math.max(out.maxY, y);
        out.minX = Math.min(out.minX, x);
        out.minY = Math.min(out.minY, y);
    };

    for (const m of svg.matchAll(/<rect ([^>]*)\/>/g)) {
        const attr = (name) => Number((m[1].match(new RegExp(name + '="([-\\d.]+)"')) || [])[1]);
        const x = attr('x'); const y = attr('y');
        if (Number.isNaN(x) || Number.isNaN(y)) continue;
        note(x + (attr('width') || 0), y + (attr('height') || 0));
        note(x, y);
    }
    for (const m of svg.matchAll(/<text ([^>]*)>/g)) {
        const attr = (name) => Number((m[1].match(new RegExp('\\b' + name + '="([-\\d.]+)"')) || [])[1]);
        if (Number.isNaN(attr('x')) || Number.isNaN(attr('y'))) continue;
        note(attr('x'), attr('y'));
    }
    for (const m of svg.matchAll(/<line ([^>]*)\/>/g)) {
        const attr = (name) => Number((m[1].match(new RegExp(name + '="([-\\d.]+)"')) || [])[1]);
        note(attr('x1'), attr('y1'));
        note(attr('x2'), attr('y2'));
    }

    return out;
}

// --------------------------------------------------------------------------

test('both shapes exist: A4 to print, 16:9 for a screen', () => {
    assert.deepEqual(Object.keys(POSTER_FORMATS).sort(), ['a4', 'screen']);
    assert.equal(POSTER_FORMATS.a4.width / POSTER_FORMATS.a4.height < 1, true, 'A4 is portrait');
    assert.equal(POSTER_FORMATS.screen.width / POSTER_FORMATS.screen.height, 16 / 9);
    // A4 at 300 dpi, so it can actually be printed.
    assert.equal(POSTER_FORMATS.a4.width, 2480);
    assert.equal(POSTER_FORMATS.a4.height, 3508);
});

test('the client and the server agree on the two sizes', () => {
    const cards = read('includes/special_events/cards.php');
    const block = cards.slice(cards.indexOf('SE_PROGRAM_POSTER_SIZES'));

    for (const format of Object.values(POSTER_FORMATS)) {
        assert.ok(
            new RegExp(`'w' => ${format.width}, 'h' => ${format.height}`).test(block),
            `${format.key} is ${format.width}×${format.height} in the browser but not in cards.php`
        );
    }
});

test('the whole programme is drawn on one page, at both sizes', () => {
    for (const format of ['a4', 'screen']) {
        for (const count of [1, 3, 6, 9, 13, 20, 28, 40]) {
            const data = payload(count);
            const svg = buildProgramPosterSvg(data, { format, dayId: 7 });
            const spec = POSTER_FORMATS[format];
            const inside = bounds(svg);

            assert.ok(
                inside.maxY <= spec.height,
                `${format} with ${count} items ran ${Math.round(inside.maxY - spec.height)}px off the bottom`
            );
            assert.ok(inside.maxX <= spec.width + 1, `${format}/${count} ran off the right`);
            assert.ok(inside.minY >= 0 && inside.minX >= 0, `${format}/${count} ran off the top or left`);

            // and every single item is actually on it
            for (const item of data.days[0].items) {
                const first = item.title.split(' ')[0];
                assert.ok(svg.includes(esc(first)), `${format}/${count} lost "${item.title}"`);
            }
        }
    }
});

test('rows never overflow their region, and long lists go two columns', () => {
    const metrics = {
        gap: 20, minRow: 100, maxRow: 300, columnBreak: 15, maxColumns: 2,
        titleRatio: 0.34, titleMin: 30, titleMax: 80, timeMin: 24, timeMax: 60, blurbAt: 180,
    };

    for (let count = 1; count <= 40; count++) {
        const avail = 2400;
        const plan = planPosterRows(count, avail, metrics);
        const used = plan.rowH * plan.perColumn + plan.gap * (plan.perColumn - 1);

        assert.equal(plan.overflow, false, `${count} items overflowed`);
        assert.ok(used <= avail + 1, `${count} items used ${used} of ${avail}`);
        assert.ok(plan.rowH > 0);
        assert.equal(plan.columns, count > 15 ? 2 : 1);
        assert.ok(plan.perColumn * plan.columns >= count, 'every item has a slot');
    }
});

test('a short programme does not become six enormous slabs', () => {
    const plan = planPosterRows(3, 2400, {
        gap: 20, minRow: 100, maxRow: 300, columnBreak: 15, maxColumns: 2,
        titleRatio: 0.34, titleMin: 30, titleMax: 80, timeMin: 24, timeMax: 60, blurbAt: 180,
    });

    assert.equal(plan.rowH, 300);
    assert.equal(plan.titleSize, 80, 'type is capped too');
});

test('blurbs are dropped before anything is cut off', () => {
    const roomy = planPosterRows(6, 2400, {
        gap: 20, minRow: 100, maxRow: 400, columnBreak: 15, maxColumns: 2,
        titleRatio: 0.34, titleMin: 30, titleMax: 80, timeMin: 24, timeMax: 60, blurbAt: 180, blurbColumns: 1,
    });
    const tight = planPosterRows(26, 2400, {
        gap: 20, minRow: 100, maxRow: 400, columnBreak: 15, maxColumns: 2,
        titleRatio: 0.34, titleMin: 30, titleMax: 80, timeMin: 24, timeMax: 60, blurbAt: 180, blurbColumns: 1,
    });

    assert.equal(roomy.showBlurbs, true);
    assert.equal(tight.showBlurbs, false);
});

test('the poster is valid, self-contained SVG', () => {
    const svg = buildProgramPosterSvg(payload(), { format: 'a4', dayId: 7 });

    assert.match(svg, /^<svg xmlns="http:\/\/www\.w3\.org\/2000\/svg"/);
    assert.match(svg, /width="2480" height="3508"/);
    assert.ok(svg.endsWith('</svg>'));
    // Nothing may be fetched while the canvas draws it (§14.2 step 5).
    assert.equal(/href="(?!data:)/.test(svg), false, 'no remote references');
});

test('the hero photo is the background when there is one', () => {
    const withHero = buildProgramPosterSvg(payload(), {
        format: 'screen', dayId: 7, heroDataUri: 'data:image/jpeg;base64,AAAA',
    });
    const without = buildProgramPosterSvg(payload(), { format: 'screen', dayId: 7 });

    assert.match(withHero, /<image [^>]*preserveAspectRatio="xMidYMid slice"/);
    assert.match(withHero, /href="data:image\/jpeg;base64,AAAA"/);
    assert.equal(without.includes('<image'), false, 'no hero, no broken image');
    // The palette still fills the page, so the text is never on white.
    assert.match(without, /fill="#0B0D13"/);
});

test('fonts and the QR are inlined by the caller, not fetched by the SVG', () => {
    const svg = buildProgramPosterSvg(payload(), {
        format: 'a4', dayId: 7,
        fontCss: '@font-face{font-family:Unbounded;src:url(data:font/woff2;base64,AA)}',
        qrMarkup: '<g id="qr"><rect x="10" y="10" width="20" height="20"/></g>',
    });

    assert.match(svg, /<style><!\[CDATA\[@font-face/);
    assert.ok(svg.includes('<g id="qr">'));
});

test('text from the database cannot break the SVG', () => {
    const data = payload(2);
    data.days[0].items[0].title = 'Tea & "biscuits" <script>';
    const svg = buildProgramPosterSvg(data, { format: 'a4', dayId: 7 });

    assert.equal(svg.includes('<script>'), false);
    assert.ok(svg.includes('Tea &amp; &quot;biscuits&quot; &lt;script&gt;'));
});

test('order-only programmes number the rows instead of timing them', () => {
    const data = payload(4);
    data.days[0].items = data.days[0].items.map((item) => ({ ...item, time: '' }));
    const svg = buildProgramPosterSvg(data, { format: 'a4', dayId: 7 });

    assert.ok(svg.includes('>01<'), 'the first row is numbered');
    assert.ok(svg.includes('>04<'));
});

test('one day per poster, and the right one', () => {
    const data = payload(3);
    data.days.push({ day_id: 8, date: 'Sunday 25 October', doors: '', start: '', items: [
        { time: '10:00 AM', title: 'Sunday service', blurb: '', host: '', featured: false },
    ] });

    assert.equal(posterItems(data, 8).items.length, 1);
    assert.equal(posterItems(data, 7).items.length, 3);
    assert.equal(posterItems(data, 999).day.day_id, 7, 'an unknown day falls back to the first');

    const svg = buildProgramPosterSvg(data, { format: 'a4', dayId: 8 });
    assert.ok(svg.includes('Sunday service'));
    assert.equal(svg.includes('Ice breaker'), false, 'the other day is not on this poster');
});

test('the file is named after the event and the shape', () => {
    assert.equal(posterFilename('chara-2026-programme', 'a4'), 'chara-2026-programme-a4.jpg');
    assert.equal(posterFilename('Chara 2026!', 'screen', 'png'), 'chara-2026-screen.png');
    assert.equal(posterFilename('', 'a4'), 'programme-a4.jpg');
});

test('long text shrinks, then ellipsises — it never spills', () => {
    const measure = makeMeasurer();
    const short = ellipsise('Worship', 1000, 40, measure, 'sans-serif', 400);
    const long = ellipsise('A very long item title that will not fit anywhere at all', 120, 40, measure, 'sans-serif', 400);

    assert.equal(short, 'Worship');
    assert.ok(long.endsWith('…'));
    assert.ok(measure(long, 40, 'sans-serif', 400) <= 120);

    const block = fitBlock('A rather long event title', 600, 2, 200, 60, measure, 'sans-serif', 800);
    assert.ok(block.lines.length <= 2);
    assert.ok(block.size <= 200 && block.size >= 60);
});

// --------------------------------------------------------------------------
// The publish gate
// --------------------------------------------------------------------------

test('the publish bar says which state the page is in', () => {
    const on = publishBarCopy(true, 12);
    const off = publishBarCopy(false, 12);

    assert.match(on.state, /Published/);
    assert.match(on.action, /Hide/);
    assert.match(off.title, /hidden/i);
    assert.match(off.action, /Publish/);
    // Crew screens are explicitly not gated, and the bar says so.
    assert.match(off.hint, /stage|host|lobby/i);
});

test('published with nothing public says so rather than lying', () => {
    assert.match(publishBarCopy(true, 0).hint, /nothing public/i);
});

test('the Studio actions exist on the server', () => {
    const api = read('api/special_events_api.php');
    const tab = read('assets/se/js/studio/tabs/program.js');
    const poster = read('assets/se/js/studio/program_poster.js');

    for (const action of ['program_publish', 'program_poster_data']) {
        assert.ok(api.includes(`case '${action}'`), `${action} is missing from the API`);
    }
    assert.ok(tab.includes("studio('program_publish'"), 'the tab posts program_publish');
    assert.ok(poster.includes("studio('program_poster_data'"), 'the card asks for its own data');
    // The publish bar is at the top of the tab, above the run of show.
    assert.ok(tab.indexOf('<${PublishBar}') < tab.indexOf('title="Run of show"'));
});

test('the portal hides an unpublished programme entirely', () => {
    const portal = read('includes/special_events/portal.php');
    const settings = read('includes/special_events/settings.php');

    assert.match(portal, /se_portal_program_public/);
    assert.match(portal, /settings\['program'\]\['published'\]/);
    // The spec default is `true` so that an event written before the key
    // existed keeps the page it had; a NEW event is created with the gate
    // shut instead. program_publish_test.php owns that pair in full.
    assert.match(settings, /'published'\s*=>\s*\['type' => 'bool', 'default' => true\]/);
    assert.match(settings, /function se_settings_for_new_event/);
    assert.match(read('includes/special_events/events.php'), /se_settings_for_new_event\(/);
    // …and the live snapshots keep feeding the crew screens.
    const live = read('includes/special_events/live.php');
    assert.equal(live.includes("['program']['published']"), false);
});

test('JPEG is a real option in the rasteriser', () => {
    const svg = read('assets/se/js/core/svg.js');

    assert.match(svg, /rasterise\(svgSource, width, height, options = \{\}\)/);
    assert.match(svg, /image\/jpeg/);
    assert.match(svg, /canvas\.toBlob\(\s*\(out\)/);
    assert.ok(read('assets/se/js/studio/program_poster.js').includes("'image/jpeg'"));
});
