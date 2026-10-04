// /tests/special_events/js/phone.test.mjs
//
// The JavaScript half of the phone contract (guide §10.3.1, §22.1).
//
// tests/special_events/phone_test.php runs the SAME vectors through the PHP
// implementation. If the two ever disagree, the registration sheet accepts a
// number the API then rejects — which is exactly the bug this pair exists to
// prevent, and it will fail here first.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

const { normalizePhone, displayPhone, smsCapable, normalizeNigerian, displayName } =
    await import(join(repo, 'assets/se/js/core/phone.js'));

const fixture = JSON.parse(readFileSync(join(repo, 'tests/special_events/fixtures/phones.json'), 'utf8'));

test('the shared fixture has at least 40 vectors', () => {
    assert.ok(fixture.vectors.length >= 40, `got ${fixture.vectors.length}`);
});

test('the fixture covers acceptance and rejection', () => {
    const accepted = fixture.vectors.filter((v) => v.expected !== null).length;
    const rejected = fixture.vectors.length - accepted;
    assert.ok(accepted > 0 && rejected > 0, `accepted=${accepted} rejected=${rejected}`);
});

for (const [i, vector] of fixture.vectors.entries()) {
    const label = `[${String(i).padStart(2, '0')}] ${JSON.stringify(vector.input)}`;

    test(`${label} matches the shared vector`, () => {
        const actual = normalizePhone(vector.input);

        if (vector.expected === null) {
            assert.equal(actual, null);
            return;
        }

        assert.ok(actual, 'expected a result, got null');
        assert.equal(actual.e164, vector.expected.e164);
        assert.equal(actual.sms, vector.expected.sms);
        assert.equal(actual.display, vector.expected.display);
    });
}

test('every Nigerian mobile prefix is recognised', () => {
    for (const [input, expected] of [
        ['07031234567', '2347031234567'],
        ['07112345678', '2347112345678'],
        ['08031234567', '2348031234567'],
        ['08131234567', '2348131234567'],
        ['09031234567', '2349031234567'],
        ['09131234567', '2349131234567'],
    ]) {
        assert.equal(normalizeNigerian(input), expected, input);
    }
});

test('a landline, a short number and a long number are all rejected', () => {
    assert.equal(normalizeNigerian('012345678'), null);
    assert.equal(normalizeNigerian('0803123456'), null);
    assert.equal(normalizeNigerian('080312345678'), null);
});

test('non-string, non-number input is rejected rather than coerced', () => {
    for (const bad of [null, undefined, true, {}, [], ['0803'], () => {}]) {
        assert.equal(normalizePhone(bad), null, String(bad));
    }
});

test('a number primitive is read as digits', () => {
    assert.equal(normalizePhone(8031234567)?.e164, '2348031234567');
});

test('display groups a Nigerian number the way people read it', () => {
    assert.equal(displayPhone('2348031234567'), '+234 803 123 4567');
    assert.equal(displayPhone(''), '');
});

test('smsCapable is true only for Nigerian mobiles', () => {
    assert.equal(smsCapable('2348031234567'), true);
    assert.equal(smsCapable('447911123456'), false);
    assert.equal(smsCapable(''), false);
});

test('re-normalising an accepted number is a no-op', () => {
    for (const vector of fixture.vectors) {
        if (vector.expected === null) continue;
        assert.equal(normalizePhone('+' + vector.expected.e164)?.e164, vector.expected.e164);
        assert.equal(normalizePhone(vector.expected.display)?.e164, vector.expected.e164);
    }
});

test('displayName is the Ada O. form, matching se_display_name()', () => {
    assert.equal(displayName('Ada', 'Obi'), 'Ada O.');
    assert.equal(displayName('Ada', ''), 'Ada');
    assert.equal(displayName('', ''), 'Guest');
    assert.equal(displayName('   ', '  '), 'Guest');
    assert.equal(displayName('tom', 'smith-jones'), 'tom S.');
});
