// /tests/special_events/js/checkin_suggest.test.mjs
//
// The verse suggestion picker (guide §15.7). It mounts Preact, so these are
// source-level guards on the promises a regression would silently break:
//
//   1. suggestions open in the picker instead of being claimed "saved",
//   2. adding goes through the verses_accept round-trip (job id included),
//   3. edited references re-fetch the KJV text instead of trusting the AI,
//   4. the "suggested by AI" badge is fed by a real, migrated column,
//   5. the human-review promise: nothing is usable until a person adds it.
//
//   node --test tests/special_events/js/

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = join(here, '..', '..', '..');

const checkin = readFileSync(join(repo, 'assets/se/js/studio/tabs/checkin.js'), 'utf8');
const versesPhp = readFileSync(join(repo, 'includes/special_events/verses.php'), 'utf8');
const api = readFileSync(join(repo, 'api/special_events_api.php'), 'utf8');
const constants = readFileSync(join(repo, 'includes/special_events/constants.php'), 'utf8');

test('the picker opens when suggestions arrive', () => {
    assert.ok(checkin.includes('setPickerOpen(true)'),
        'the modal must open itself — a banner alone is how this feature got lost');
    assert.ok(checkin.includes('role="dialog"') && checkin.includes('aria-modal="true"'),
        'the picker must be a real dialog');
    assert.ok(checkin.includes("e.key === 'Escape'"), 'Esc must close the picker');
});

test('adding goes through verses_accept and carries the audit job id', () => {
    assert.ok(api.includes("case 'verses_accept':"), 'the API must route verses_accept');
    assert.ok(checkin.includes("studio('verses_accept'"), 'the Studio must call verses_accept');
    assert.ok(/job_id:\s*picker\.jobId/.test(checkin),
        'saves must name the suggestion job so the audit trail says which round they came from');
});

test('an edited reference re-fetches the KJV text', () => {
    assert.ok(checkin.includes("studio('bible_lookup'"),
        'editing a reference in the picker must re-fetch the KJV text from the server');
    assert.ok(versesPhp.includes("// Client-sent") || /looked up AGAIN/i.test(versesPhp),
        'verses_accept must re-lookup every pick server-side and ignore client text');
});

test('the dishonest placeholder copy is gone', () => {
    assert.ok(!checkin.includes('Refresh the list'),
        'nothing was saved by the suggest call, so there is no list to refresh');
    assert.ok(!checkin.includes('saved as \u201cneeds review\u201d'),
        'the suggest call saves nothing; the UI must not claim otherwise');
});

test('the suggested-by-AI badge is fed by a real migrated column', () => {
    assert.ok(checkin.includes('verse.ai_suggested'), 'the badge must read the payload flag');
    assert.ok(!checkin.includes("verse.source === 'ai'"),
        'the API never returned a source field — that check was dead code');
    assert.ok(versesPhp.includes("'ai_suggested'"), 'se_verse_payload must expose the flag');
    assert.ok(constants.includes('20261103090000_se_verses_ai_flag.sql'),
        'the migration must be registered in SE_SCHEMA_EXPECTED');

    const migrations = readdirSync(join(repo, 'db/migrations'))
        .filter((f) => f.includes('verses_ai_flag'));
    assert.equal(migrations.length, 1, 'exactly one verses_ai_flag migration');
    assert.ok(readFileSync(join(repo, 'db/migrations', migrations[0]), 'utf8')
        .includes('ADD COLUMN suggested_by_ai'), 'the migration must add suggested_by_ai');
});

test('human review stays visible: accepting is approving, said out loud', () => {
    assert.ok(versesPhp.includes('approved_at'), 'accept must set approved_at at insert');
    assert.ok(/approved straight away|approved and can\s*\n?\s*greet/.test(checkin.replace(/\s+/g, ' ')) || checkin.includes('approved and can'),
        'the picker must tell the producer that added verses are usable immediately');
    assert.ok(!api.includes('Nothing is saved until you approve it.'),
        'the suggest reply must not promise an approval step that no longer exists there');
});
