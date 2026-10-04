// /assets/se/js/lobby/main.js
//
// The lobby display (guide §13.9).
//
// A TV downstairs, next to the check-in posters. Its whole job is to make
// arriving feel like being welcomed: a big QR on the left, and on the right
// a stream of "Welcome Ada O. → Team Black" cards that fly up as people
// come in.
//
// It reads lobby-<key>.json, which is the only snapshot that carries names —
// and even that one drops them when `settings.lobby.show_names` is off, in
// which case the cards read "New arrival → Team Black".

import { call } from '@se/core/api.js';
import { boot } from '@se/core/store.js';
import { syncClock, serverNow } from '@se/core/clock.js';
import { PollDriver } from '@se/core/realtime.js';
import { qrSvg } from '@se/core/qr.js';

const config = boot.value || {};
const MAX_CARDS = 6;
const IDLE_MS = 60000;

const TIPS = [
    'Tip: tick karaoke when you register to sing tonight 🎤',
    'Tip: your player number is on your phone after check-in.',
    'Tip: teams are balanced automatically — no queueing to pick sides.',
    'Tip: bring a friend. Walk-ins are welcome while space lasts.',
];

let display = null;
let arrivalsNode = null;
let barsNode = null;
let totalNode = null;
let countdownNode = null;
let tipTimer = null;
let firstPaint = true;

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text != null) element.textContent = text;
    return element;
}

function build() {
    const host = document.getElementById('se-app');
    host.hidden = false;
    host.className = 'se-display';
    host.replaceChildren();

    const frame = node('div', 'se-frame');
    const grid = node('div', 'se-lobby');

    // Left: the QR everybody is looking for.
    const left = node('div', 'se-lobby-qr');
    left.appendChild(qrSvg(display.checkin_url, { size: 600 }));
    left.appendChild(node('p', 'se-scene-sub', 'Scan to check in'));
    left.appendChild(node('p', 'se-scene-body', shortUrl(display.checkin_url)));
    countdownNode = node('p', 'se-scene-counter', '');
    left.appendChild(countdownNode);

    // Right: who has just walked in.
    const right = node('div', 'se-stack');
    totalNode = node('p', 'se-scene-counter', '0 checked in');
    arrivalsNode = node('div', 'se-arrivals');
    barsNode = node('div', 'se-bars');
    right.append(totalNode, arrivalsNode, barsNode);

    grid.append(left, right);
    frame.appendChild(grid);
    host.appendChild(frame);

    document.getElementById('se-crew-frame')?.remove();
}

function shortUrl(url) {
    return String(url || '').replace(/^https?:\/\//, '');
}

function renderArrivals(data) {
    const showNames = data.show_names !== false;
    const teams = new Map((data.teams || []).map((team) => [team.id, team]));

    const cards = (data.arrivals || []).slice(0, MAX_CARDS).map((arrival, index) => {
        const team = teams.get(arrival.team_id);
        const who = showNames && arrival.name ? 'Welcome ' + arrival.name : 'New arrival';
        const where = team ? ' → ' + (team.name || ('Team ' + team.label)) : '';

        const card = node('div', 'se-arrival', who + where);
        card.dataset.rank = String(index);
        if (team) card.style.setProperty('--team-color', team.hex);
        return card;
    });

    arrivalsNode.replaceChildren(...cards);

    if (cards.length) {
        clearTimeout(tipTimer);
        tipTimer = setTimeout(showTip, IDLE_MS);
    }
}

/** After a minute with nobody arriving, say something useful instead. */
function showTip() {
    const tip = TIPS[Math.floor(Math.random() * TIPS.length)];
    arrivalsNode.replaceChildren(node('p', 'se-scene-sub', tip));
    tipTimer = setTimeout(showTip, 12000);
}

function renderBars(data) {
    const teams = data.teams || [];
    const max = Math.max(1, ...teams.map((team) => team.n || 0));

    barsNode.replaceChildren(...teams.map((team) => {
        const row = node('div', 'se-stack');
        const bar = node('div', 'se-bar');
        bar.style.setProperty('--team-color', team.hex);
        // The goal line is "all teams equal", so widths are relative to the
        // biggest team: the screen shows balance, not raw numbers.
        bar.style.width = Math.round(((team.n || 0) / max) * 100) + '%';
        row.appendChild(node('p', 'se-scene-body', (team.name || ('Team ' + team.label)) + ' · ' + (team.n || 0)));
        row.appendChild(bar);
        return row;
    }));
}

function renderCountdown(data) {
    const startsAt = Date.parse(data.starts_at || '');
    if (!Number.isFinite(startsAt)) { countdownNode.textContent = ''; return; }

    const left = startsAt - serverNow();
    if (left <= 0) { countdownNode.textContent = 'Happening now'; return; }

    const h = Math.floor(left / 3600000);
    const m = Math.floor((left % 3600000) / 60000);
    countdownNode.textContent = 'Starts in ' + (h ? h + 'h ' : '') + m + 'm';
}

function render(envelope) {
    const data = envelope?.data || {};

    totalNode.textContent = (data.checked_in || 0) + ' checked in';
    renderArrivals(data);
    renderBars(data);
    renderCountdown(data);

    firstPaint = false;
}

async function start() {
    if (!config.event?.public_id) return;

    const key = config.display_key;
    const fail = (message) => document.getElementById('se-crew-frame')?.replaceChildren(
        node('p', 'se-glass se-pad', message));

    if (!key) {
        fail('This screen needs its link from Studio → Crew (the one ending in ?k=…).');
        return;
    }

    try {
        display = await call('display', 'display_boot', {
            event: config.event.public_id,
            kind: 'lobby',
            key,
        });
    } catch (error) {
        fail(error.message || 'That screen link is not valid.');
        return;
    }

    build();
    await syncClock();

    const channel = display.snapshots.lobby.split('/').pop().replace(/\.json$/, '');

    // eslint-disable-next-line no-new
    new PollDriver({
        base: '/live/' + config.event.public_id + '/',
        channels: { [channel]: render },
        // The lobby is never idle-important enough for 1 Hz, and never slow
        // enough for 30 s: arrivals should land within a couple of seconds.
        pace: () => 'live',
    });
}

start();
