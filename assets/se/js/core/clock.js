// /assets/se/js/core/clock.js
//
// Clock synchronisation (guide §8.5.5, §11.1).
//
// Every phone in the room has a different idea of what time it is — some by
// minutes. A quiz that scores on speed cannot use the device clock, and a
// countdown that says "3… 2… 1…" two seconds after the stage did looks
// broken. So we measure the offset to the server once at boot and keep it
// fresh, and every surface asks this module for the time.
//
// Method: N sequential samples of `action=time`, keep the one with the
// smallest round trip (the least distorted by the network), and estimate
//
//     offset = server_ms − (t0 + rtt / 2)
//
// `performance.now()` is used for the local side because, unlike Date.now(),
// it is monotonic: an NTP correction or the user changing the clock mid-show
// cannot make our elapsed times jump.

import { call } from './api.js';

const SAMPLES_AT_BOOT = 5;
const RESYNC_MS = 60000;

/** The best few samples we have seen, newest last. */
const samples = [];
let offset = 0;
let synced = false;
let resyncTimer = null;

/** Local monotonic wall-clock estimate, in epoch milliseconds. */
function localNow() {
    if (typeof performance !== 'undefined' && typeof performance.timeOrigin === 'number') {
        return performance.timeOrigin + performance.now();
    }
    return Date.now();
}

/** One sample. Returns `{offset, rtt}` or null when the call failed. */
async function sample() {
    const t0 = localNow();

    let data;
    try {
        data = await call('public', 'time', {});
    } catch (e) {
        return null;
    }

    const t1 = localNow();
    const rtt = t1 - t0;
    const serverMs = Number(data?.server_ms || 0);
    if (!serverMs) return null;

    return { offset: serverMs - (t0 + rtt / 2), rtt };
}

/** Keep the five best (lowest round trip) samples and adopt their offset. */
function adopt(result) {
    if (!result) return;

    samples.push(result);
    samples.sort((a, b) => a.rtt - b.rtt);
    samples.length = Math.min(samples.length, 5);

    offset = samples[0].offset;
    synced = true;
}

/**
 * Sync now. Called once at boot by every live surface.
 *
 * Sequential, not parallel: concurrent requests queue behind each other on
 * a phone's single HTTP/1.1 connection and inflate every round trip.
 */
export async function syncClock(count = SAMPLES_AT_BOOT) {
    for (let i = 0; i < count; i++) {
        adopt(await sample());
    }
    return offset;
}

/**
 * Re-sync every 60 s. Surfaces that score on time (/play, /stage, /host)
 * call this; the portal does not need it.
 */
export function keepClockSynced(ms = RESYNC_MS) {
    if (resyncTimer) return;

    resyncTimer = setInterval(() => {
        // Nothing to correct while the tab is in the background, and a
        // throttled timer would produce a wildly pessimistic round trip.
        if (document.visibilityState === 'hidden') return;
        sample().then(adopt);
    }, ms);

    addEventListener('pagehide', () => {
        clearInterval(resyncTimer);
        resyncTimer = null;
    }, { once: true });
}

/** The server's clock, as well as we can estimate it, in epoch ms. */
export function serverNow() {
    return localNow() + offset;
}

/** How far this device's clock is from the server's, in ms. */
export function clockOffset() {
    return offset;
}

/** False until the first successful sample, so UI can hold back a countdown. */
export function clockSynced() {
    return synced;
}

/**
 * Elapsed time since a server timestamp, clamped into [0, max].
 *
 * The clamp is the point: a device whose clock is ahead would otherwise
 * report a negative elapsed time (a "faster than possible" answer), and one
 * that slept through the round would report minutes. Both are rejected at
 * the server too (§11.3) — this keeps the UI honest in the meantime.
 */
export function elapsedSince(startMs, maxMs = Infinity) {
    if (!startMs) return 0;
    return Math.max(0, Math.min(maxMs, serverNow() - startMs));
}
