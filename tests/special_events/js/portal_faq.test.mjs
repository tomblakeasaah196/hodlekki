// /tests/special_events/js/portal_faq.test.mjs
//
// The "Good to know" editor in Studio → Details (guide §13.2 S6, §13.13).
// It imports Preact, so it cannot be mounted in this headless harness; these
// are source-level guards on the four things that would silently break it:
//
//   1. the panel is actually rendered somewhere a producer can reach,
//   2. the actions it posts exist in the PHP API,
//   3. the limits it enforces are the ones the server enforces,
//   4. field error keys match, so a server message lands on the right box.
//
//   node --test tests/special_events/js/
//
// Why guards at all: before this editor existed the six questions in
// Appendix E were the only questions any event could ever show.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');
const read = (p) => readFileSync(join(repo, p), 'utf8');

const panel = read('assets/se/js/studio/portal_page.js');
const details = read('assets/se/js/studio/tabs/details.js');
const studioBoot = read('modules/special_events/index.php');
const api = read('api/special_events_api.php');
const constants = read('includes/special_events/constants.php');
const settings = read('includes/special_events/settings.php');

/** A `const SE_FOO = 123;` value out of constants.php. */
function phpConst(name) {
    const match = constants.match(new RegExp(`const\\s+${name}\\s*=\\s*(\\d+)\\s*;`));
    assert.ok(match, `${name} is not defined in constants.php`);
    return Number(match[1]);
}

test('the FAQ editor is reachable from the Details tab', () => {
    assert.match(details, /import \{ PortalPage \} from '\.\.\/portal_page\.js';/);
    assert.match(details, /<\$\{PortalPage\} \/>/);
    assert.match(panel, /export function PortalPage\(\)/);
});

test('every action the panel posts exists in the Studio API', () => {
    const posted = [...panel.matchAll(/studio\('([a-z_]+)'/g)].map((m) => m[1]);
    assert.deepEqual([...new Set(posted)].sort(), ['portal_faq_reset', 'portal_settings_save']);

    for (const action of posted) {
        assert.ok(api.includes(`case '${action}':`), `api/special_events_api.php has no case for ${action}`);
    }
});

test('the save goes through the settings patch, with event.edit', () => {
    // Both actions are capability-gated and both write the portal branch —
    // not update_event, which would demand a row_version the panel never has.
    for (const action of ['portal_settings_save', 'portal_faq_reset']) {
        const block = api.split(`case '${action}': {`)[1].split('\n    }')[0];
        assert.match(block, /se_studio_event\(\$pdo, \$body, 'event\.edit'\)/);
        assert.match(block, /se_event_settings_patch\(/);
    }
});

test('the panel enforces the same limits as the server', () => {
    const fallback = (fn) => {
        const match = panel.match(new RegExp(`const ${fn} = \\(\\) => limits\\(\\)\\.[a-z_]+ \\|\\| (\\d+);`));
        assert.ok(match, `${fn} has no fallback limit`);
        return Number(match[1]);
    };

    assert.equal(fallback('faqMax'), phpConst('SE_PORTAL_FAQ_MAX'));
    assert.equal(fallback('qMax'), phpConst('SE_PORTAL_FAQ_Q_MAX'));
    assert.equal(fallback('aMax'), phpConst('SE_PORTAL_FAQ_A_MAX'));

    // And boot really sends them, so the fallbacks stay fallbacks.
    assert.match(studioBoot, /'faq_max'\s*=>\s*SE_PORTAL_FAQ_MAX/);
    assert.match(studioBoot, /'faq_q_max'\s*=>\s*SE_PORTAL_FAQ_Q_MAX/);
    assert.match(studioBoot, /'faq_a_max'\s*=>\s*SE_PORTAL_FAQ_A_MAX/);
});

test('a field error from the server lands on the box it belongs to', () => {
    // PHP: "faq_{$index}_q"   JS: 'faq_' + index + '_q'
    assert.match(settings, /"faq_\{\$index\}_q"/);
    assert.match(settings, /"faq_\{\$index\}_a"/);
    assert.match(panel, /'faq_' \+ index \+ '_q'/);
    assert.match(panel, /'faq_' \+ index \+ '_a'/);
});

test('saving the FAQ does not throw away the rest of the tab', () => {
    // applyEvent() clears the Details draft; a side panel must not do that to
    // a half-typed tagline above it.
    assert.match(panel, /import \{[^}]*refreshEvent[^}]*\} from '\.\/state\.js';/);
    assert.ok(!/\bapplyEvent\(/.test(panel), 'the portal panel must use refreshEvent(), not applyEvent()');
});

test('an empty list is a real choice, not a reset', () => {
    // The normaliser only restores the six starters when the key is absent,
    // so the panel has to be able to send [] — and say what that means.
    assert.match(panel, /the section is hidden on the page/);
    assert.match(panel, /Restore the starter questions/);
});
