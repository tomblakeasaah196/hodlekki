// /tests/special_events/js/theme.test.mjs
//
// The JS theme engine must agree with the PHP one on every shared vector
// (guide §13.2, §22.1). PHP renders the <style> block on every page; this
// twin drives the Studio's live preview, so a divergence means a Producer
// is shown a palette the portal will not actually render.
//
//   node --test tests/special_events/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');

const theme = await import(join(repo, 'assets/se/js/core/theme.js'));
const vectors = JSON.parse(readFileSync(join(here, '..', 'fixtures', 'theme_vectors.json'), 'utf8'));

test('hex normalisation matches PHP', () => {
    assert.equal(theme.normalizeHex('#1d356a'), '#1D356A');
    assert.equal(theme.normalizeHex('1D356A'), '#1D356A');
    assert.equal(theme.normalizeHex('#123'), '#112233');
    assert.equal(theme.normalizeHex('  #ffffff  '), '#FFFFFF');
    assert.equal(theme.normalizeHex('#12345'), null);
    assert.equal(theme.normalizeHex('#GGGGGG'), null);
    assert.equal(theme.normalizeHex('red'), null);
    assert.equal(theme.normalizeHex(123), null);
});

test('OKLCH round trips', () => {
    for (const hex of ['#1D356A', '#D11920', '#22C55E', '#FACC15', '#7C3AED', '#808080', '#000000', '#FFFFFF']) {
        const [L, C, H] = theme.hexToOklch(hex);
        assert.equal(theme.oklchToHex(L, C, H), hex, `round trip ${hex}`);
    }
});

test('contrast matches the WCAG definition', () => {
    assert.ok(Math.abs(theme.contrastRatio('#000000', '#FFFFFF') - 21) < 0.01);
    assert.ok(Math.abs(theme.contrastRatio('#D11920', '#D11920') - 1) < 0.001);
    assert.equal(theme.bestOnColor('#1D356A'), '#FFFFFF');
    assert.equal(theme.bestOnColor('#FACC15'), '#0B0B0F');
});

test('every derived token matches PHP, on every vector', () => {
    for (const [i, c] of vectors.cases.entries()) {
        const js = theme.themeDerive(c.primary, c.secondary, c.accent);

        for (const [name, expected] of Object.entries(c.tokens)) {
            assert.equal(js.tokens[name], expected,
                `case ${i} (${c.primary}/${c.secondary}/${c.accent ?? 'auto'}) token ${name}`);
        }
    }
});

test('every contrast figure matches PHP, on every vector', () => {
    for (const [i, c] of vectors.cases.entries()) {
        const js = theme.themeDerive(c.primary, c.secondary, c.accent);

        for (const [key, expected] of Object.entries(c.contrast)) {
            assert.ok(Math.abs(js.contrast[key].ratio - expected.ratio) < 0.005,
                `case ${i} ${key}: php ${expected.ratio} vs js ${js.contrast[key].ratio}`);
            assert.equal(js.contrast[key].passes, expected.passes, `case ${i} ${key} passes`);
        }
    }
});

test('the accessibility guarantees hold in JS too', () => {
    for (const [i, c] of vectors.cases.entries()) {
        const t = theme.themeDerive(c.primary, c.secondary, c.accent).tokens;

        assert.ok(theme.contrastRatio(t['--se-text'], t['--se-bg']) >= 4.5, `case ${i} body text`);
        assert.ok(theme.contrastRatio(t['--se-text-muted'], t['--se-bg']) >= 4.5, `case ${i} muted text`);
        assert.ok(theme.contrastRatio(t['--se-primary'], t['--se-bg']) >= 3.0, `case ${i} primary`);
        assert.ok(theme.contrastRatio(t['--se-on-primary'], t['--se-primary']) >= 4.5, `case ${i} on-primary`);
        assert.ok(theme.contrastRatio(t['--se-on-secondary'], t['--se-secondary']) >= 4.5, `case ${i} on-secondary`);
    }
});

test('team tokens match PHP', () => {
    for (const expected of vectors.teams) {
        const js = theme.teamTokens(expected.color, vectors.team_bg, expected.index);

        assert.equal(js.color, expected.color);
        assert.equal(js.on, expected.on);
        assert.equal(js.glow, expected.glow);
        assert.equal(js.needs_ring, expected.needs_ring);
        assert.ok(Math.abs(js.ratio_vs_bg - expected.ratio_vs_bg) < 0.005);
    }
});

test('a black team on a dark stage gets a ring and a neutral glow', () => {
    const black = theme.teamTokens('#000000', vectors.team_bg, 1);
    assert.equal(black.needs_ring, true);
    assert.ok(theme.hexToOklch(black.glow)[1] < 0.01, `glow ${black.glow} should be achromatic`);

    assert.equal(theme.teamTokens('#22C55E', vectors.team_bg, 2).needs_ring, false);
});

test('similarity warnings flag colours that look alike', () => {
    assert.deepEqual(theme.teamSimilarityWarnings(['#000000', '#E11D48', '#22C55E', '#3B82F6']), []);
    assert.equal(theme.teamSimilarityWarnings(['#D11920', '#D21921', '#22C55E']).length, 1);
});

test('the primary and secondary are never altered as -raw tokens', () => {
    // §15.2: an AI suggestion may propose an accent, but the Producer's two
    // colours must survive untouched for decorative use.
    for (const c of vectors.cases) {
        const t = theme.themeDerive(c.primary, c.secondary, c.accent).tokens;
        assert.equal(t['--se-primary-raw'], theme.normalizeHex(c.primary));
        assert.equal(t['--se-secondary-raw'], theme.normalizeHex(c.secondary));
    }
});
