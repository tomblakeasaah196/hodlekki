// /assets/se/js/core/realtime.js
//
// Snapshot polling (guide §8.5.3, §8.5.7).
//
// Every live surface reads the same static JSON files under /live/<id>/.
// They are served by the web server, not by PHP, so 150 phones at 1 Hz cost
// the application nothing — and because we send If-None-Match, almost every
// one of those requests is a 304 with no body at all.
//
// Three things make this behave on a church Wi-Fi:
//
//   * adaptive intervals — 1 s only while something is actually happening,
//     2.5 s when the room is just live, 30 s on the portal, nothing at all
//     when the page is hidden or there is nothing to watch;
//   * jitter — without it 150 phones that loaded the page together would
//     poll in lockstep and arrive as a spike every second;
//   * backoff — 2, 4, 8, 15 s after an error, with a "Reconnecting…" pill
//     once two polls in a row have failed.
//
// The driver interface mirrors the server's (§8.5.7): v1 ships PollDriver,
// and an AblyDriver can be dropped in later without any caller changing.

import { net } from './store.js';

/** Interval table (§8.5.3). `jitter` is the ± spread in milliseconds. */
const INTERVALS = {
    hot:      { ms: 1000,  jitter: 150 },
    live:     { ms: 2500,  jitter: 300 },
    upcoming: { ms: 30000, jitter: 3000 },
    hidden:   { ms: 10000, jitter: 1000 },
    off:      { ms: 0,     jitter: 0 },
};

const BACKOFF_MS = [2000, 4000, 8000, 15000];

function jittered(pace) {
    const spec = INTERVALS[pace] || INTERVALS.live;
    if (!spec.ms) return 0;
    return spec.ms + (Math.random() * 2 - 1) * spec.jitter;
}

/**
 * Which pace a surface should poll at, from the snapshot it last received.
 *
 * Kept here rather than in each surface so stage, lobby and /play cannot
 * drift apart — and so the unit test has one function to check.
 */
export function paceFor(snapshot, context = {}) {
    if (context.hidden) return 'hidden';

    const phase = snapshot?.data?.event?.phase ?? context.phase ?? 'upcoming';
    const game = snapshot?.data?.game ?? null;

    if (game && ['armed', 'open', 'buzz', 'charades'].includes(game.state)) return 'hot';
    if (phase === 'live') return 'live';

    // The portal only polls at all when there is a number on screen that can
    // change — otherwise an idle tab costs nothing.
    if (phase === 'upcoming') return context.watchingSeats ? 'upcoming' : 'off';

    return 'off';
}

/** Fetch one snapshot, honouring ETags. Returns null for a 304. */
async function fetchSnapshot(url, etag) {
    const headers = {};
    if (etag) headers['If-None-Match'] = etag;

    const response = await fetch(url, {
        method: 'GET',
        cache: 'no-cache',
        credentials: 'omit',
        headers,
    });

    if (response.status === 304) return null;
    if (response.status === 404) {
        // The event is real but nothing has been published yet. Not an error.
        const quiet = new Error('snapshot not published yet');
        quiet.quiet = true;
        throw quiet;
    }
    if (!response.ok) throw new Error('snapshot ' + response.status);

    const body = await response.json();
    return { body, etag: response.headers.get('ETag') };
}

/**
 * Watch one snapshot file.
 *
 * @param {object} options
 * @param {string} options.url         the snapshot to poll
 * @param {function} options.onData    called with each new envelope
 * @param {function} [options.pace]    () => a key of INTERVALS
 * @param {function} [options.onStatus] called with {state, failures}
 * @returns {{stop: function, poke: function}}
 */
export function watchSnapshot({ url, onData, pace, onStatus }) {
    let etag = null;
    let version = -1;
    let failures = 0;
    let timer = null;
    let stopped = false;
    let inFlight = false;

    const status = (state) => { if (onStatus) onStatus({ state, failures }); };

    const schedule = (ms) => {
        clearTimeout(timer);
        if (stopped) return;
        if (!(ms > 0)) {
            // Paused. Visibility and pace changes call poke() to restart.
            timer = null;
            return;
        }
        timer = setTimeout(tick, ms);
    };

    async function tick() {
        if (stopped || inFlight) return;
        inFlight = true;

        try {
            const result = await fetchSnapshot(url, etag);
            failures = 0;
            net.value = { online: true, lastOkAt: Date.now(), failures: 0 };
            status('ok');

            if (result) {
                etag = result.etag;
                // Snapshots can be delivered out of order by a cache; an
                // older version must never overwrite a newer one.
                const v = Number(result.body?.v ?? 0);
                if (v >= version) {
                    version = v;
                    onData(result.body);
                }
            }

            schedule(jittered(pace ? pace() : 'live'));
        } catch (e) {
            failures += 1;
            if (!e.quiet) {
                net.value = { online: false, lastOkAt: net.value.lastOkAt, failures };
            }
            status(failures >= 2 ? 'reconnecting' : 'retrying');
            schedule(BACKOFF_MS[Math.min(failures - 1, BACKOFF_MS.length - 1)]);
        } finally {
            inFlight = false;
        }
    }

    /** Poll immediately — after an action, or when the tab comes back. */
    function poke() {
        if (stopped) return;
        clearTimeout(timer);
        tick();
    }

    const onVisibility = () => {
        if (document.visibilityState === 'visible') poke();
        else schedule(jittered('hidden'));
    };
    document.addEventListener('visibilitychange', onVisibility);

    tick();

    return {
        poke,
        stop() {
            stopped = true;
            clearTimeout(timer);
            document.removeEventListener('visibilitychange', onVisibility);
        },
    };
}

/**
 * The poll driver: watch several channels of one event at once.
 *
 * `channels` maps a name to a handler, e.g. `{public: fn, 'room-abc': fn}`.
 */
export class PollDriver {
    constructor({ base, channels, pace, onStatus }) {
        this.base = base.endsWith('/') ? base : base + '/';
        this.watchers = Object.entries(channels).map(([channel, onData]) =>
            watchSnapshot({ url: this.base + channel + '.json', onData, pace, onStatus }));
    }

    poke() { this.watchers.forEach((w) => w.poke()); }

    stop() { this.watchers.forEach((w) => w.stop()); }
}

/** How stale the newest snapshot is, in seconds — the host console's dot. */
export function snapshotAge(snapshot, nowMs = Date.now()) {
    if (!snapshot?.t) return null;
    return Math.max(0, (nowMs - Number(snapshot.t)) / 1000);
}
