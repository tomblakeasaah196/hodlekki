// /tests/special_events/js/boot.test.mjs
//
// @se/core/boot.js reads the server's payload from whichever surface is
// loaded, and must never throw on a missing or malformed block — the
// server-rendered page has to keep working either way.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

/** A DOM small enough to exercise readBoot() without a browser. */
function stubDom(blocks) {
    globalThis.document = {
        getElementById: (id) => (id in blocks ? { textContent: blocks[id] } : null),
    };
}

const { readBoot, formatDateTime } = await import(join(repo, 'assets/se/js/core/boot.js'));

test('reads the public payload', () => {
    stubDom({ 'se-boot': '{"event":{"title":"Chara"},"phase":"upcoming"}' });
    assert.equal(readBoot().event.title, 'Chara');
});

test('reads the Studio payload when the public one is absent', () => {
    // The Studio emits #se-studio-boot; shared code (the CSRF token in
    // @se/core/api.js) must find it there.
    stubDom({ 'se-studio-boot': '{"csrf":"abc","access":"manager"}' });
    assert.equal(readBoot().csrf, 'abc');
});

test('prefers the public payload when both exist', () => {
    stubDom({ 'se-boot': '{"which":"public"}', 'se-studio-boot': '{"which":"studio"}' });
    assert.equal(readBoot().which, 'public');
});

test('a missing block yields an empty object, not a throw', () => {
    stubDom({});
    assert.deepEqual(readBoot(), {});
});

test('malformed JSON yields an empty object, not a throw', () => {
    stubDom({ 'se-boot': '{not json' });
    const originalError = console.error;
    console.error = () => {};
    try {
        assert.deepEqual(readBoot(), {});
    } finally {
        console.error = originalError;
    }
});

test('dates render in the event timezone', () => {
    // 17:00 WAT is 16:00 UTC; the formatter must show the Lagos time whatever
    // the viewer's own clock says (§13.14).
    // Accept either clock convention: the ICU build decides whether en-NG
    // renders 12- or 24-hour. What matters is that it is Lagos time, not UTC.
    const out = formatDateTime('2026-10-25T16:00:00Z', { dateStyle: undefined, timeStyle: 'short' });
    assert.match(out, /(^|\D)(17:00|5:00)/, `expected 5 pm Lagos time, got "${out}"`);
});

test('an empty date is empty, not "Invalid Date"', () => {
    assert.equal(formatDateTime(''), '');
    assert.equal(formatDateTime(null), '');
});
