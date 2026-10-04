// /tests/special_events/js/copywriter.test.mjs
//
// The Studio copywriter panel (guide §15.8). It imports Preact, so it cannot
// be mounted in this headless harness; these are source-level guards on the
// three things a regression would silently break:
//
//   1. the human-review promise ("nothing is applied automatically"),
//   2. the purpose list staying in step with SE_COPYWRITE_PURPOSES in PHP,
//   3. the help text that tells the crew what to expect back.
//
//   node --test tests/special_events/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');

const source = readFileSync(join(repo, 'assets/se/js/studio/copywriter.js'), 'utf8');
const details = readFileSync(join(repo, 'assets/se/js/studio/tabs/details.js'), 'utf8');
const constants = readFileSync(join(repo, 'includes/special_events/constants.php'), 'utf8');

/** The PHP purpose list, read straight out of constants.php. */
function phpPurposes() {
    const block = constants.split('const SE_COPYWRITE_PURPOSES = [')[1].split('];')[0];
    return [...block.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
}

test('every PHP copywriting purpose is offered in the Studio', () => {
    const offered = [...source.matchAll(/\{ value: '([a-z_]+)'/g)].map((m) => m[1]);
    assert.deepEqual(offered, phpPurposes());
});

test('every purpose has a hint explaining what comes back', () => {
    const block = source.split('const PURPOSE_HINTS = {')[1].split('\n};')[0];
    for (const purpose of phpPurposes()) {
        assert.ok(block.includes(`${purpose}:`), `missing hint for ${purpose}`);
    }
});

test('the description hint promises three distinct angles and a full paragraph', () => {
    const hint = source.split('description:')[1].split('activity_blurb:')[0];
    assert.match(hint, /70–110 words|70-110 words/);
    assert.match(hint, /different angle/);
});

test('the description panel lists the Markdown that is actually supported', () => {
    for (const feature of ['headings', 'bold', 'italic', 'inline code', 'links',
        'bullet lists', 'numbered lists', 'quotes', 'dividers', 'paragraphs']) {
        assert.ok(source.includes(feature), `copywriter help is missing: ${feature}`);
        assert.ok(details.toLowerCase().includes(feature.split(' ')[0]),
            `details help is missing: ${feature}`);
    }
    assert.ok(source.includes('Raw HTML and images are not allowed'));
    assert.ok(details.includes('Raw HTML and images are not allowed'));
});

test('suggestions are never applied automatically', () => {
    assert.ok(source.includes('Nothing is applied automatically'));
    // The only ways a variant leaves this component are an explicit click on
    // "Copy"/"Use this" — there must be no auto-apply call on load.
    assert.ok(!/useEffect\s*\(/.test(source));
    assert.ok(source.includes('onClick=${() => pick(text)}'));
});

test('description options show a word count against the 120-word limit', () => {
    assert.ok(source.includes('wordCount'));
    assert.ok(source.includes('120-word limit'));
});
