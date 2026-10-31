// /tests/special_events/js/timing.test.mjs
//
// Clock synchronisation and the polling pace (guide §8.5.3, §8.5.5, §11.1,
// §22.1).
//
// Two things here decide whether the night feels right:
//
//   * the elapsed-time clamp. A phone whose clock is two minutes fast would
//     otherwise answer a quiz question "before" it was asked, and a phone
//     that slept through a round would report a six-minute answer. Both are
//     rejected server-side, but the UI must never show them either.
//   * the polling pace. Too slow and the room sees the stage change before
//     their phones do; too fast and 200 phones hammer a shared host.

import { test } from 'node:test';
import assert from 'node:assert/strict';
import { register } from 'node:module';

register('./vendor_loader.mjs', import.meta.url);

// Both modules reach the browser through core/store.js, which reads the
// #se-boot <script> at import time, and core/api.js, which needs fetch.
// A two-method document and a fetch that refuses is the whole surface they
// touch before anything is called — if that ever grows, this test fails
// loudly rather than quietly testing a stub.
globalThis.document ??= {
    getElementById: () => null,
    addEventListener: () => {},
    visibilityState: 'visible',
};
globalThis.addEventListener ??= () => {};
globalThis.fetch ??= async () => { throw new Error('no network in tests'); };

const { elapsedSince, serverNow, clockOffset, clockSynced } = await import('@se/core/clock.js');
const { paceFor, snapshotAge, pollDelay } = await import('@se/core/realtime.js');

// --------------------------------------------------------------------------
// The clamp (§11.1)
// --------------------------------------------------------------------------

test('elapsed time is never negative, however wrong the device clock is', () => {
    // A start time one minute in the future: the clock is ahead, or the
    // round was armed before this phone finished loading.
    const future = serverNow() + 60000;

    assert.equal(elapsedSince(future), 0);
    assert.equal(elapsedSince(future, 30000), 0);
});

test('elapsed time is capped at the round length', () => {
    // Backgrounded for ten minutes, then woken up mid-round.
    const longAgo = serverNow() - 600000;

    assert.equal(elapsedSince(longAgo, 30000), 30000);
    assert.equal(elapsedSince(longAgo, 1000), 1000);
});

test('an uncapped call still reports a sane elapsed time', () => {
    const start = serverNow() - 5000;
    const elapsed = elapsedSince(start);

    // Allow a wide band: this is a real clock, and CI machines stall.
    assert.ok(elapsed >= 4000 && elapsed < 20000, `got ${elapsed}`);
});

test('no start time means no elapsed time', () => {
    assert.equal(elapsedSince(0), 0);
    assert.equal(elapsedSince(null), 0);
    assert.equal(elapsedSince(undefined), 0);
});

test('an unsynced clock still answers, using the device clock', () => {
    assert.equal(clockSynced(), false);
    assert.equal(clockOffset(), 0);

    // serverNow() must never be NaN or zero — a countdown would break.
    const now = serverNow();
    assert.ok(Number.isFinite(now) && now > 1600000000000, `got ${now}`);
});

// --------------------------------------------------------------------------
// Snapshot age — the host console's health dot (§13.10)
// --------------------------------------------------------------------------

test('snapshot age is reported in seconds and never negative', () => {
    const now = 1_700_000_000_000;

    assert.equal(snapshotAge({ t: now - 2500 }, now), 2.5);
    assert.equal(snapshotAge({ t: now + 5000 }, now), 0, 'a snapshot from the future is "now"');
    assert.equal(snapshotAge(null, now), null);
    assert.equal(snapshotAge({}, now), null);
});

// --------------------------------------------------------------------------
// Polling pace (§8.5.3)
// --------------------------------------------------------------------------

const envelope = (data) => ({ v: 1, t: Date.now(), e: 'abc', kind: 'public', data });

test('a hidden tab always drops to the slow pace', () => {
    const live = envelope({ event: { phase: 'live' }, game: { state: 'open' } });

    assert.equal(paceFor(live, { hidden: true }), 'hidden');
});

test('an armed or open game gets the hot pace', () => {
    for (const state of ['armed', 'open', 'buzz', 'charades']) {
        const snapshot = envelope({ event: { phase: 'live' }, game: { state } });
        assert.equal(paceFor(snapshot, {}), 'hot', state);
    }
});

test('a live event without a running game polls at the live pace', () => {
    assert.equal(paceFor(envelope({ event: { phase: 'live' }, game: null }), {}), 'live');
    assert.equal(paceFor(envelope({ event: { phase: 'live' }, game: { state: 'closed' } }), {}), 'live');
});

test('an idle upcoming page does not poll at all unless it is watching seats', () => {
    const upcoming = envelope({ event: { phase: 'upcoming' } });

    assert.equal(paceFor(upcoming, {}), 'off');
    assert.equal(paceFor(upcoming, { watchingSeats: true }), 'upcoming');
});

test('after the event there is nothing left to poll for', () => {
    assert.equal(paceFor(envelope({ event: { phase: 'post' } }), {}), 'off');
});

test('the lobby screens poll twice a second, with a small spread', () => {
    for (let i = 0; i < 500; i++) {
        const ms = pollDelay('lobby');
        assert.ok(ms >= 425 && ms <= 575, 'lobby delay ' + ms);
    }
});

test('phones keep their own rates: the lobby rate is never chosen for them', () => {
    // Every state paceFor can see on a phone maps to a slower pace.
    const states = [null, 'armed', 'open', 'buzz', 'charades', 'closed'];
    for (const phase of ['upcoming', 'live', 'post', 'between_days']) {
        for (const state of states) {
            const snapshot = envelope({ event: { phase }, game: state ? { state } : null });
            assert.notEqual(paceFor(snapshot, {}), 'lobby', phase + '/' + state);
            assert.notEqual(paceFor(snapshot, { watchingSeats: true }), 'lobby', phase + '/' + state);
        }
    }
    for (let i = 0; i < 200; i++) {
        const ms = pollDelay('hot');
        assert.ok(ms >= 850 && ms <= 1150, 'hot delay ' + ms);
    }
});

test('an unknown pace falls back to the live rate, never to a fast one', () => {
    for (let i = 0; i < 100; i++) {
        const ms = pollDelay('no-such-pace');
        assert.ok(ms >= 2200 && ms <= 2800, 'fallback delay ' + ms);
    }
    assert.equal(pollDelay('off'), 0);
});

test('with no snapshot yet the pace comes from the boot payload', () => {
    assert.equal(paceFor(null, { phase: 'live' }), 'live');
    assert.equal(paceFor(null, { phase: 'upcoming' }), 'off');
    assert.equal(paceFor(null, {}), 'off');
});
