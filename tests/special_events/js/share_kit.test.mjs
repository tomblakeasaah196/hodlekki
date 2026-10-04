// /tests/special_events/js/share_kit.test.mjs
//
// Studio → Overview → Share kit (guide §18.2). The panel opens on the two
// links the crew uses every time — the plain link and the check-in poster QR
// — and hides the per-channel codes behind "View more". Everything the server
// sent must still be reachable: nothing may be dropped by the split.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';

register('./vendor_loader.mjs', import.meta.url);

globalThis.document ??= {
    getElementById: () => null,
    addEventListener: () => {},
    visibilityState: 'visible',
};
globalThis.fetch ??= async () => { throw new Error('no network in tests'); };

const { splitShareLinks, PRIMARY_CODES } = await import('@se/studio/share_kit.js');

/** The shape api/special_events_api.php's `share_kit` case returns. */
function kit() {
    return [
        { code: '', label: 'Plain link', url: 'https://x/e/chara' },
        { code: 'wa', label: 'WhatsApp', url: 'https://x/e/chara?s=wa' },
        { code: 'ig', label: 'Instagram', url: 'https://x/e/chara?s=ig' },
        { code: 'fb', label: 'Facebook', url: 'https://x/e/chara?s=fb' },
        { code: 'checkin', label: 'Check-in poster QR', url: 'https://x/e/chara/in' },
    ];
}

test('the two always-visible rows are the plain link and the check-in QR', () => {
    const { primary } = splitShareLinks(kit());
    assert.equal(primary.length, 2);
    assert.deepEqual(primary.map((r) => r.code), PRIMARY_CODES);
    assert.equal(primary[0].label, 'Plain link');
    assert.equal(primary[1].label, 'Check-in poster QR');
});

test('everything else goes behind "View more", in server order', () => {
    const { rest } = splitShareLinks(kit());
    assert.deepEqual(rest.map((r) => r.code), ['wa', 'ig', 'fb']);
});

test('no link is lost by the split', () => {
    const links = kit();
    const { primary, rest } = splitShareLinks(links);
    assert.equal(primary.length + rest.length, links.length);
    for (const row of links) {
        assert.ok(primary.includes(row) || rest.includes(row), `${row.code} vanished`);
    }
});

test('a code the server adds later falls into "View more", not out of the list', () => {
    const links = [...kit(), { code: 'threads', label: 'Threads', url: 'https://x/e/chara?s=threads' }];
    const { rest } = splitShareLinks(links);
    assert.ok(rest.some((r) => r.code === 'threads'));
});

test('a missing check-in row is skipped rather than rendered empty', () => {
    const links = kit().filter((r) => r.code !== 'checkin');
    const { primary, rest } = splitShareLinks(links);
    assert.deepEqual(primary.map((r) => r.code), ['']);
    assert.equal(rest.length, 3);
});

test('no links, or a malformed payload, is not a crash', () => {
    assert.deepEqual(splitShareLinks([]), { primary: [], rest: [] });
    assert.deepEqual(splitShareLinks(undefined), { primary: [], rest: [] });
    assert.deepEqual(splitShareLinks(null), { primary: [], rest: [] });
});
