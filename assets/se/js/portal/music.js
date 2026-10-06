// /assets/se/js/portal/music.js
//
// Portal music — the soft bed under /e/<slug> (guide §13.3 S0b).
//
// Read this part first, because it is the part people get wrong:
//
//   1. A web page CANNOT detect whether a phone is on silent. There is no
//      API for the ringer switch, for system volume, or for whether anything
//      is audible. `audio.volume` is this element's own gain (and on iOS it
//      is read-only and always 1); an AnalyserNode measures our own buffer
//      before it ever reaches a speaker, so it reads "loud" on a muted
//      handset. Every "silent mode detector" on the internet is one of those
//      two mistakes. We do not try.
//
//   2. A page CANNOT start audible audio on its own. Chrome, Safari, Firefox
//      and Samsung Internet all require a user gesture first. Muted autoplay
//      is permitted and is useless for music.
//
// So the design starts from both facts instead of fighting them: the page
// loads silent, the FIRST real gesture (a tap, a scroll, a key) fades the
// track in at a low volume, and two always-visible keys can stop it or turn
// it down. That last part is also WCAG 2.1 SC 1.4.2 — audio over three
// seconds needs a control — so it is a requirement, not a courtesy.
//
// What the gesture buys us beyond permission: it is also the only moment an
// AudioContext can be created, which is what makes the breathing ring on the
// button possible. The ring is driven by the real waveform via an
// AnalyserNode, so it moves with THIS track rather than a guessed tempo. If
// the analyser cannot be built (very old Safari, a blocked context), the
// track's stored BPM drives a plain CSS-timed pulse instead, and if there is
// no BPM either the ring simply rests. Sound never depends on the animation.
//
// Everything here fails quietly. A missing file, a refused context or a
// browser without Web Audio costs the page its music; it must never cost
// the page its registration form.

import { prefersReducedMotion } from '@se/core/boot.js';

// Under a tenth of a second and the ring looks like a strobe; over a third
// and it lags the beat. 70 ms attack with a slow release reads as "breath".
const LEVEL_ATTACK = 0.45;
const LEVEL_RELEASE = 0.12;

// How long the first fade-in takes. Long enough that nobody is startled,
// short enough that it is clearly a response to what they just did.
const FADE_IN_MS = 1600;
const FADE_OUT_MS = 500;

const STORE_KEY = (publicId) => `se:music:${publicId}`;

/** Remembered choice. localStorage throws in Safari private mode. */
function readPref(publicId) {
    try {
        return JSON.parse(localStorage.getItem(STORE_KEY(publicId)) || 'null') || {};
    } catch (e) {
        return {};
    }
}

function savePref(publicId, patch) {
    try {
        localStorage.setItem(STORE_KEY(publicId), JSON.stringify({ ...readPref(publicId), ...patch }));
    } catch (e) {
        /* private mode: the choice lasts this page only */
    }
}

/**
 * Should we even offer this?
 *
 * Save-Data and 2G are the same rule the hero video follows (§13.3 S1). A
 * three-minute MP3 is two to four megabytes, and on a metered Nigerian
 * connection that is a real cost to impose on someone who came to register
 * for a church event. No dock is drawn at all.
 */
function connectionAllows() {
    const c = navigator.connection || {};

    return !c.saveData && !['2g', 'slow-2g'].includes(c.effectiveType);
}

/**
 * Build the playback graph.
 *
 * <audio> does the fetching and the looping; Web Audio only taps the signal
 * so the ring has something to follow. The gain node is the real volume
 * control — `audio.volume` is left at 1 because iOS ignores writes to it.
 *
 * Returns null when Web Audio is unavailable, in which case the caller falls
 * back to the plain element and `audio.volume`.
 */
function buildGraph(audio) {
    const Ctx = window.AudioContext || window.webkitAudioContext;
    if (!Ctx) return null;

    try {
        const context = new Ctx();
        const source = context.createMediaElementSource(audio);
        const gain = context.createGain();
        const analyser = context.createAnalyser();

        // 256 bins is plenty for an amplitude envelope and costs almost
        // nothing to read every frame.
        analyser.fftSize = 256;
        analyser.smoothingTimeConstant = 0.75;

        gain.gain.value = 0;
        source.connect(gain);
        gain.connect(analyser);
        analyser.connect(context.destination);

        return { context, gain, analyser, data: new Uint8Array(analyser.fftSize) };
    } catch (e) {
        console.warn('[se] music graph unavailable:', e.message);
        return null;
    }
}

export function startMusic(config) {
    const dock = document.getElementById('se-music');
    const music = config.music;

    if (!dock || !music?.enabled || !music.tracks?.length) return;
    if (!connectionAllows()) {
        dock.remove();
        return;
    }

    const publicId = config.event?.public_id || '';
    const pref = readPref(publicId);

    // The producer's volume is the starting point; a returning visitor's own
    // choice beats it. Clamped because localStorage is user-writable.
    const startVolume = Number.isFinite(pref.volume)
        ? Math.min(100, Math.max(0, pref.volume))
        : (music.volume || 30);

    // An explicit "off" is honoured forever. There is no nagging: if they
    // muted it last time, the dock still appears (so they can change their
    // mind) but nothing plays until they ask.
    let wanted = pref.muted !== true;
    let volume = startVolume;

    const order = music.shuffle
        ? music.tracks.map((t) => t).sort(() => Math.random() - 0.5)
        : music.tracks.slice();
    let index = 0;

    const toggleKey = dock.querySelector('[data-se-music="toggle"]');
    const volumeKey = dock.querySelector('[data-se-music="volume"]');
    const rail = dock.querySelector('.se-music-rail');
    const volWrap = dock.querySelector('.se-music-vol');

    // ----------------------------------------------------------------
    // The element
    // ----------------------------------------------------------------

    const audio = new Audio();
    audio.preload = 'none';          // nothing is fetched until they ask
    audio.crossOrigin = 'anonymous'; // same-origin anyway; required for the analyser
    audio.loop = order.length === 1; // one track loops itself; a playlist advances

    let graph = null;
    let playing = false;
    let raf = 0;
    let level = 0;

    const setSource = () => {
        audio.src = order[index].src;
    };

    const label = () => {
        const track = order[index];
        const name = track.artist ? `${track.title} by ${track.artist}` : track.title;

        return wanted && playing ? `Turn the music off (${name})` : 'Play the background music';
    };

    const paint = () => {
        dock.dataset.state = wanted && playing ? 'on' : 'off';
        toggleKey?.setAttribute('aria-pressed', wanted && playing ? 'true' : 'false');
        toggleKey?.setAttribute('aria-label', label());
    };

    // ----------------------------------------------------------------
    // Volume, faded rather than stepped
    // ----------------------------------------------------------------

    const target = () => (wanted ? volume / 100 : 0);

    const ramp = (to, ms) => {
        if (graph) {
            const now = graph.context.currentTime;
            // setTargetAtTime is exponential and never quite arrives, so the
            // ramp is linear from wherever the gain is right now.
            graph.gain.gain.cancelScheduledValues(now);
            graph.gain.gain.setValueAtTime(graph.gain.gain.value, now);
            graph.gain.gain.linearRampToValueAtTime(to, now + ms / 1000);
        } else {
            // No Web Audio: a stepped fade on the element. iOS ignores this
            // (volume is read-only there) but iOS always has Web Audio.
            audio.volume = to;
        }
    };

    // ----------------------------------------------------------------
    // The breathing ring
    // ----------------------------------------------------------------
    //
    // RMS of the time-domain samples, smoothed asymmetrically: quick to rise
    // so a kick registers, slow to fall so it reads as a breath rather than
    // a flicker. The result lands on a CSS variable and CSS does the rest.

    const reduced = prefersReducedMotion();

    const pulse = () => {
        if (!graph || !playing) { raf = 0; return; }

        graph.analyser.getByteTimeDomainData(graph.data);

        let sum = 0;
        for (let i = 0; i < graph.data.length; i += 1) {
            const v = (graph.data[i] - 128) / 128;
            sum += v * v;
        }
        // RMS of normalised samples rarely exceeds ~0.35 on mastered music,
        // so it is scaled up before being clamped to 0..1.
        const rms = Math.min(1, Math.sqrt(sum / graph.data.length) * 3.2);
        const rate = rms > level ? LEVEL_ATTACK : LEVEL_RELEASE;
        level += (rms - level) * rate;

        dock.style.setProperty('--se-music-level', level.toFixed(3));
        raf = requestAnimationFrame(pulse);
    };

    const startPulse = () => {
        if (reduced || !graph || raf) return;
        raf = requestAnimationFrame(pulse);
    };

    const stopPulse = () => {
        if (raf) cancelAnimationFrame(raf);
        raf = 0;
        level = 0;
        dock.style.setProperty('--se-music-level', '0');
    };

    /**
     * No analyser: fall back to the track's declared BPM.
     *
     * Not as good — it cannot know where the beat actually is — but a slow
     * pulse at the right tempo still reads as "this is in time with the
     * music", which is the whole point of the ring.
     */
    const bpmFallback = () => {
        if (reduced || graph) return;
        const bpm = order[index]?.bpm;
        if (!bpm) return;

        const beat = 60000 / bpm;
        let on = false;
        const tick = setInterval(() => {
            if (!playing) { clearInterval(tick); dock.style.setProperty('--se-music-level', '0'); return; }
            on = !on;
            dock.style.setProperty('--se-music-level', on ? '0.55' : '0.12');
        }, beat);
    };

    // ----------------------------------------------------------------
    // Play / stop
    // ----------------------------------------------------------------

    const play = async () => {
        if (!audio.src) setSource();

        // The graph can only be built from inside a gesture, and only once
        // per element — createMediaElementSource throws on a second call.
        if (!graph) graph = buildGraph(audio);
        if (graph?.context.state === 'suspended') await graph.context.resume().catch(() => {});

        try {
            await audio.play();
        } catch (e) {
            // NotAllowedError: the gesture was not good enough (or Low Power
            // Mode refused). Leave the dock armed and wait for a real tap on
            // the button, which always counts.
            playing = false;
            paint();
            return false;
        }

        playing = true;
        ramp(target(), FADE_IN_MS);
        startPulse();
        bpmFallback();
        paint();

        return true;
    };

    const stop = () => {
        ramp(0, FADE_OUT_MS);
        // Let the fade finish before the element actually stops, or the
        // track ends on a click.
        setTimeout(() => {
            if (!wanted) { audio.pause(); playing = false; stopPulse(); paint(); }
        }, FADE_OUT_MS);
    };

    // A playlist advances; the last track wraps to the first, so the set
    // loops for as long as the page is open.
    audio.addEventListener('ended', () => {
        if (order.length < 2) return;
        index = (index + 1) % order.length;
        setSource();
        audio.play().then(() => { playing = true; paint(); bpmFallback(); }).catch(() => {});
    });

    // A missing or corrupt file skips to the next rather than killing the
    // whole playlist. With one track, the dock removes itself: a button that
    // cannot make a sound is worse than no button.
    audio.addEventListener('error', () => {
        if (order.length > 1) {
            index = (index + 1) % order.length;
            setSource();
            if (wanted && playing) audio.play().catch(() => {});
            return;
        }
        stopPulse();
        dock.remove();
    });

    // ----------------------------------------------------------------
    // The two keys
    // ----------------------------------------------------------------

    toggleKey?.addEventListener('click', async () => {
        wanted = !wanted;
        savePref(publicId, { muted: !wanted });

        if (wanted) {
            await play();
        } else {
            stop();
            paint();
        }
    });

    volumeKey?.addEventListener('click', () => {
        const open = volWrap.dataset.open === '1';
        volWrap.dataset.open = open ? '0' : '1';
        volumeKey.setAttribute('aria-expanded', open ? 'false' : 'true');
        rail.tabIndex = open ? -1 : 0;
        if (!open) rail.focus({ preventScroll: true });
    });

    rail?.addEventListener('input', () => {
        volume = Number(rail.value) || 0;
        savePref(publicId, { volume });

        // Dragging to zero is a mute, and dragging back up un-mutes — the
        // two controls agree with each other rather than contradicting.
        if (volume === 0 && wanted) {
            wanted = false;
            savePref(publicId, { muted: true });
            stop();
            paint();
            return;
        }
        if (volume > 0 && !wanted) {
            wanted = true;
            savePref(publicId, { muted: false });
            play();
            return;
        }
        ramp(target(), 120);
    });

    // Clicking away closes the rail. Pointerdown rather than click so it
    // closes on the same gesture that starts a scroll.
    document.addEventListener('pointerdown', (e) => {
        if (volWrap?.dataset.open === '1' && !dock.contains(e.target)) {
            volWrap.dataset.open = '0';
            volumeKey?.setAttribute('aria-expanded', 'false');
            rail.tabIndex = -1;
        }
    });

    // ----------------------------------------------------------------
    // Courtesy
    // ----------------------------------------------------------------

    // Leaving the tab stops the sound. Nothing is more annoying than hunting
    // nineteen tabs for the one that is singing. Coming back resumes only if
    // it was playing when they left.
    let resumeOnReturn = false;
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            resumeOnReturn = playing && wanted;
            if (playing) { audio.pause(); playing = false; stopPulse(); paint(); }
        } else if (resumeOnReturn && wanted) {
            play();
        }
    });

    // Duck under anything else that makes noise — a hero video with sound, a
    // karaoke preview — so two things never talk over each other.
    document.addEventListener('play', (e) => {
        if (e.target === audio || !playing) return;
        if (e.target instanceof HTMLMediaElement && !e.target.muted) ramp(target() * 0.15, 300);
    }, true);

    document.addEventListener('pause', (e) => {
        if (e.target === audio || !playing) return;
        if (e.target instanceof HTMLMediaElement) ramp(target(), 600);
    }, true);

    // ----------------------------------------------------------------
    // Arm it
    // ----------------------------------------------------------------
    //
    // The dock becomes visible immediately — it is a control, and hiding a
    // control until the thing it controls has started is backwards. What
    // waits for the gesture is the SOUND.

    dock.hidden = false;
    paint();
    requestAnimationFrame(() => { dock.dataset.ready = '1'; });

    if (!wanted) return;   // they muted it last visit: the dock is enough

    // The first gesture of any kind. `scroll` is not itself a user-activation
    // gesture in Chrome's eyes, so it is included only as a trigger to TRY —
    // if the attempt is refused, the listeners stay on and the next real tap
    // succeeds. { once: true } would throw that second chance away.
    const arm = async () => {
        if (playing) return;
        const ok = await play();
        if (!ok) return;

        for (const type of ['pointerdown', 'touchstart', 'keydown', 'scroll', 'wheel']) {
            window.removeEventListener(type, arm);
        }
    };

    for (const type of ['pointerdown', 'touchstart', 'keydown', 'scroll', 'wheel']) {
        window.addEventListener(type, arm, { passive: true });
    }
}
