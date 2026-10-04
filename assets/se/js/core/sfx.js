// /assets/se/js/core/sfx.js
//
// The stage sound board (guide §13.1.6).
//
// One MP3 sprite holds every cue; `se-sfx.json` says where each one starts
// and how long it lasts. Web Audio is used rather than <audio> elements for
// two reasons: a decoded buffer can be played with no latency (a "buzz" that
// arrives 200 ms late is worse than no buzz), and overlapping cues need
// separate source nodes.
//
// Browsers refuse to start an AudioContext without a user gesture, which is
// exactly what the stage's "Click to start" overlay is for.
//
// Everything here fails quietly. A missing sprite, a blocked context or an
// unknown cue costs the room a sound effect; it must never stop the screen
// from showing the scene.

let context = null;
let buffer = null;
let master = null;
let sprite = {};
let ready = false;
let loading = null;

/** Load and decode the sprite. Safe to call more than once. */
export async function loadSfx({ sprite: spriteUrl, map: mapUrl, volume = 0.8 } = {}) {
    if (loading) return loading;

    loading = (async () => {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx || !spriteUrl || !mapUrl) return false;

            context = new Ctx();
            master = context.createGain();
            master.gain.value = volume;
            master.connect(context.destination);

            const [mapResponse, audioResponse] = await Promise.all([
                fetch(mapUrl, { cache: 'force-cache' }),
                fetch(spriteUrl, { cache: 'force-cache' }),
            ]);
            if (!mapResponse.ok || !audioResponse.ok) return false;

            sprite = (await mapResponse.json())?.sprite || {};
            buffer = await context.decodeAudioData(await audioResponse.arrayBuffer());
            ready = true;
            return true;
        } catch (e) {
            console.warn('[se] sound is off:', e.message);
            return false;
        }
    })();

    return loading;
}

/**
 * Unlock the context from a click handler.
 *
 * iOS creates the context in a "suspended" state and only resume() inside a
 * real gesture will start it.
 */
export async function unlockSfx() {
    if (!context) return false;
    try {
        if (context.state === 'suspended') await context.resume();
        return context.state === 'running';
    } catch (e) {
        return false;
    }
}

/** Master volume, 0–1 (host console: `settings.stage.volume`). */
export function setVolume(value) {
    if (master) master.gain.value = Math.max(0, Math.min(1, Number(value) || 0));
}

export function muteSfx(muted) {
    setVolume(muted ? 0 : 0.8);
}

/** Play one cue by name. Unknown cues are ignored on purpose. */
export function playCue(cue) {
    if (!ready || !cue || !sprite[cue]) return false;

    try {
        const [startMs, durationMs] = sprite[cue];
        const source = context.createBufferSource();
        source.buffer = buffer;
        source.connect(master);
        source.start(0, startMs / 1000, durationMs / 1000);
        return true;
    } catch (e) {
        return false;
    }
}

/**
 * Play whatever the snapshot's `sfx` block asks for, exactly once.
 *
 * The snapshot carries a monotonic `seq`, not an event: a screen that joins
 * late, or polls twice between cues, must not replay the last fanfare. The
 * first snapshot a screen sees only records the sequence number.
 */
export function makeCuePlayer() {
    let lastSeq = null;

    return (sfx) => {
        const seq = Number(sfx?.seq ?? 0);
        if (lastSeq === null) { lastSeq = seq; return false; }
        if (seq <= lastSeq) return false;

        lastSeq = seq;
        return playCue(sfx?.cue);
    };
}

export function sfxReady() {
    return ready;
}
