// /tests/special_events/js/card_reentry.test.mjs
//
// Keep the registration sheet, card request and public API aligned: the
// cross-device "I'm going" path carries the entered phone, while the server
// still limits that lookup to active registrations for this event.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const read = (path) => readFileSync(join(repo, path), 'utf8');
const sheet = read('assets/se/js/portal/sheet.js');
const card = read('assets/se/js/portal/card.js');
const api = read('api/special_events_public_api.php');


test('the already-in/success screen passes its entered phone into the card builder', () => {
    assert.ok(
        sheet.includes("openCardBuilder('im_going', { phone: draft?.phone })"),
        'the registration phone must follow the attendee to card generation on another device'
    );
});

test('the card builder sends the optional phone with the card request', () => {
    assert.ok(card.includes('request.phone = options.phone;'), 'the phone must be part of the API request');
    assert.ok(card.includes("call('public', 'card', request)"), 'card generation must use the public card API');
});

test('phone lookup is an authorization path only for active “I’m going” cards', () => {
    const start = api.indexOf("case 'card':");
    const end = api.indexOf("case 'beacon':", start);
    const action = api.slice(start, end);

    assert.match(action, /\$kind === 'im_going' && array_key_exists\('phone', \$body\)/);
    assert.ok(action.includes('se_card_registration_for_phone('));
    assert.ok(action.includes("se_public_limit($pdo, $event, 'lookup', 20, 400, 600)"),
        'phone card requests must share the existing lookup rate limit');
    assert.ok(action.includes("'lookup_phone'"), 'phone requests must share the existing per-number limit');
    assert.ok(action.includes('se_public_actor('), 'other card kinds must keep their device/token auth');
});
