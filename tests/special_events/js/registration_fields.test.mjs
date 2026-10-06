// Recognised congregation members should not be asked to re-enter private
// profile fields merely because lookup intentionally does not return values.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repo = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');
const { shouldAskEmail } = await import(join(repo, 'assets/se/js/portal/registration-fields.js'));

test('a recognised member with a known email is not shown a blank email field', () => {
    assert.equal(shouldAskEmail({ kind: 'member', needs: ['consent'] }, { email: 'optional' }), false);
});

test('a required email is requested when lookup says it is missing', () => {
    assert.equal(shouldAskEmail({ kind: 'member', needs: ['email', 'consent'] }, { email: 'required' }), true);
});

test('new visitors can supply the configured optional email', () => {
    assert.equal(shouldAskEmail({ kind: 'new', needs: [] }, { email: 'optional' }), true);
});

test('an email field configured off stays off', () => {
    assert.equal(shouldAskEmail({ kind: 'new', needs: ['email'] }, { email: 'off' }), false);
});
