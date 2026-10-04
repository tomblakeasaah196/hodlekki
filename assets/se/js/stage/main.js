// /assets/se/js/stage/main.js
//
// The stage display (guide §13.8).
//
// This runs on a laptop plugged into a projector and must survive the whole
// evening untouched. Three rules shape it:
//
//   1. It never asks the application server for content. It reads
//      public.json and room-<key>.json, which are static files, so a PHP
//      hiccup cannot blank the screen mid-song.
//   2. It holds the last good state. A dropped network turns the corner dot
//      amber and then red; the scene stays exactly as it was.
//   3. Everything is sized in cqw/cqh inside a fixed 16:9 frame, so the same
//      code is correct on a 1080p projector and a 4K TV.
//
// PR3 ships the core scenes (standby, welcome, teams, announcement, break,
// blank, programme) plus placeholders for leaderboard and recap. The game,
// karaoke, feud and finale scenes arrive with PR4/PR5 and currently render
// the standby frame rather than an empty screen.

import { call } from '@se/core/api.js';
import { boot, live, room } from '@se/core/store.js';
import { syncClock, keepClockSynced, serverNow } from '@se/core/clock.js';
import { PollDriver, paceFor, snapshotAge } from '@se/core/realtime.js';
import { loadSfx, unlockSfx, makeCuePlayer, setVolume } from '@se/core/sfx.js';
import { qrSvg } from '@se/core/qr.js';

const config = boot.value || {};
let display = null;
let driver = null;
let playCueFromSnapshot = makeCuePlayer();
let frame = null;
let dot = null;

// --------------------------------------------------------------------------
// Frame
// --------------------------------------------------------------------------

function buildFrame() {
    const host = document.getElementById('se-app');
    host.hidden = false;
    host.className = 'se-display';
    host.replaceChildren();

    frame = document.createElement('div');
    frame.className = 'se-frame';

    dot = document.createElement('span');
    dot.className = 'se-dot-live';
    dot.setAttribute('aria-hidden', 'true');
    frame.appendChild(dot);

    host.appendChild(frame);

    // The server-rendered "Loading…" block has done its job.
    const placeholder = document.getElementById('se-crew-frame');
    if (placeholder) placeholder.remove();
}

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text != null) element.textContent = text;
    return element;
}

/** Swap the scene with a 400 ms crossfade (§13.8). */
function paint(children) {
    const next = node('div', 'se-scene');
    for (const child of children) if (child) next.appendChild(child);

    const previous = frame.querySelector('.se-scene');
    frame.appendChild(next);
    requestAnimationFrame(() => { next.dataset.on = '1'; });

    if (previous) {
        previous.dataset.on = '0';
        setTimeout(() => previous.remove(), 450);
    }
}

// --------------------------------------------------------------------------
// Scenes (§11.12)
// --------------------------------------------------------------------------

function sceneStandby(data) {
    const counter = node('p', 'se-scene-counter', String(data.counts?.checked_in_today ?? 0) + ' checked in');
    const qr = qrSvg(display.checkin_url, { size: 320 });
    const wrap = node('div', 'se-lobby-qr');
    wrap.appendChild(qr);
    wrap.appendChild(node('p', 'se-scene-sub', 'Scan to check in · ' + shortUrl(display.checkin_url)));

    return [
        node('p', 'se-scene-sub', display.event.organizer + ' presents'),
        node('h1', 'se-scene-title', display.event.title + ' ' + (display.event.edition || '')),
        wrap,
        counter,
    ];
}

function sceneWelcome(scene) {
    const payload = scene?.payload || {};
    return [
        node('p', 'se-scene-sub', display.event.organizer),
        node('h1', 'se-scene-title', payload.title || ('Welcome to ' + display.event.title)),
        node('p', 'se-scene-sub', payload.subtitle || display.event.tagline || ''),
    ];
}

function sceneTeams(data) {
    const panels = node('div', 'se-team-panels');

    for (const team of data.teams || []) {
        const panel = node('div', 'se-team-panel');
        panel.style.setProperty('--team-color', team.hex);
        panel.style.setProperty('--team-on', team.on);
        if (team.ring) panel.dataset.ring = '1';
        panel.appendChild(node('p', 'se-team-panel-name', team.name || ('Team ' + team.label)));
        panel.appendChild(node('p', 'se-team-panel-n', team.n + (team.n === 1 ? ' player' : ' players')));
        panels.appendChild(panel);
    }

    return [node('h1', 'se-scene-title', 'The teams'), panels];
}

function sceneAnnouncement(data) {
    return [
        node('p', 'se-scene-sub', 'Announcement'),
        node('h1', 'se-scene-title', data.announcement?.text || ''),
    ];
}

function sceneProgram(scene) {
    const payload = scene?.payload || {};
    return [
        node('p', 'se-scene-sub', 'Up now'),
        node('h1', 'se-scene-title', payload.title || 'Tonight'),
        node('p', 'se-scene-body', payload.note || ''),
    ];
}

function sceneBreak(scene) {
    const payload = scene?.payload || {};
    const back = node('p', 'se-scene-counter', '');

    if (payload.until_ms) {
        const tick = () => {
            const left = Math.max(0, payload.until_ms - serverNow());
            const m = Math.floor(left / 60000);
            const s = Math.floor((left % 60000) / 1000);
            back.textContent = m + ':' + String(s).padStart(2, '0');
            if (left > 0) requestAnimationFrame(tick);
        };
        tick();
    }

    return [node('h1', 'se-scene-title', payload.title || 'Back shortly'), back];
}

function sceneBlank() {
    return [node('h1', 'se-scene-title', display.event.title)];
}

/** PR4/PR5 own these; until then the room sees something intentional. */
function scenePlaceholder(label) {
    return [
        node('p', 'se-scene-sub', label),
        node('h1', 'se-scene-title', display.event.title + ' ' + (display.event.edition || '')),
        node('p', 'se-scene-body', 'Coming right up.'),
    ];
}

function render() {
    const snapshot = live.value;
    if (!snapshot || !frame) return;

    const data = snapshot.data || {};
    const scene = data.scene || { key: 'standby' };

    // An announcement always wins: the host used it because something has to
    // be said right now.
    const key = data.announcement ? 'announcement' : scene.key;

    const builders = {
        standby: () => sceneStandby(data),
        welcome: () => sceneWelcome(scene),
        teams: () => sceneTeams(data),
        announcement: () => sceneAnnouncement(data),
        program: () => sceneProgram(scene),
        break: () => sceneBreak(scene),
        blank: () => sceneBlank(),
        leaderboard: () => scenePlaceholder('Leaderboard'),
        recap: () => scenePlaceholder('Recap'),
        game: () => scenePlaceholder('Game'),
        karaoke: () => scenePlaceholder('Karaoke'),
        finale: () => scenePlaceholder('Finale'),
    };

    paint((builders[key] || builders.standby)());
    playCueFromSnapshot(data.sfx);
}

function shortUrl(url) {
    return String(url || '').replace(/^https?:\/\//, '');
}

// --------------------------------------------------------------------------
// Health dot and the heartbeat
// --------------------------------------------------------------------------

function updateDot() {
    if (!dot) return;
    const age = snapshotAge(live.value, Date.now());
    dot.dataset.health = age == null ? 'bad' : (age < 3 ? 'ok' : (age < 15 ? 'warn' : 'bad'));
}

async function tick() {
    if (!display?.stage_key) return;
    try {
        await call('display', 'tick', {
            event: config.event.public_id,
            stage_key: display.stage_key,
        });
    } catch (e) {
        // The tick is a freshness nicety, never correctness (§8.5.6).
    }
}

// --------------------------------------------------------------------------
// Start overlay (§13.8)
// --------------------------------------------------------------------------

function startOverlay(onStart) {
    const overlay = node('div', 'se-start-overlay');
    const inner = node('div', 'se-stack');
    inner.appendChild(node('p', 'se-label', config.event?.title || ''));
    inner.appendChild(node('h1', 'se-h1', 'Click to start the show'));
    inner.appendChild(node('p', 'se-small se-muted', 'Starts the sound, goes full screen and keeps the screen awake.'));

    const go = node('button', 'se-btn se-btn-primary se-btn-wide', 'Start');
    go.type = 'button';
    go.addEventListener('click', async () => {
        overlay.remove();
        await unlockSfx();
        try { await document.documentElement.requestFullscreen(); } catch (e) { /* not allowed */ }
        try { await navigator.wakeLock?.request('screen'); } catch (e) { /* not supported */ }
        onStart();
    });

    inner.appendChild(go);
    overlay.appendChild(inner);
    document.body.appendChild(overlay);
}

// --------------------------------------------------------------------------

async function start() {
    const key = config.display_key;
    if (!config.event?.public_id) return;

    if (!key) {
        document.getElementById('se-crew-frame')?.replaceChildren(
            node('p', 'se-glass se-pad', 'This screen needs its link from Studio → Crew (the one ending in ?k=…).'));
        return;
    }

    try {
        display = await call('display', 'display_boot', {
            event: config.event.public_id,
            kind: 'stage',
            key,
        });
    } catch (error) {
        document.getElementById('se-crew-frame')?.replaceChildren(
            node('p', 'se-glass se-pad', error.message || 'That screen link is not valid.'));
        return;
    }

    buildFrame();
    await syncClock();
    keepClockSynced();

    await loadSfx(display.sfx);
    setVolume(display.sfx?.volume ?? 0.8);

    // The room channel's name carries the display key, so it is read back
    // from the URL display_boot handed us rather than reconstructed here.
    const channels = { public: (envelope) => { live.value = envelope; render(); updateDot(); } };
    if (display.snapshots.room) {
        const roomChannel = display.snapshots.room.split('/').pop().replace(/\.json$/, '');
        channels[roomChannel] = (envelope) => { room.value = envelope; };
    }

    driver = new PollDriver({
        base: '/live/' + config.event.public_id + '/',
        channels,
        pace: () => paceFor(live.value, { hidden: false, phase: config.phase }),
    });

    setInterval(updateDot, 1000);
    setInterval(tick, display.tick_ms || 1000);
}

startOverlay(() => { start(); });
