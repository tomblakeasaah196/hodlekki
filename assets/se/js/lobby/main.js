// /assets/se/js/lobby/main.js
//
// The lobby display (guide §13.9): "Who claims you?"
//
// One page, opened on every screen by the door (the same link can run on
// two or ten displays at once — each one polls the same static snapshot).
// The QR sits in the middle, large enough to scan from a few steps away,
// and the teams live in the corners: their colour, how many are in, and
// the last three people they claimed.
//
// When somebody checks in, their name pops out of the QR. Every corner
// starts to beat and throws a beam of its colour at it; the name sways
// towards each in turn, faster and faster, until the team they were really
// assigned to (the server decided that the moment they checked in) locks
// on. The others let go, the name takes the colour and flies home, the
// corner swells, confetti bursts in that colour and the count ticks up.
// Several people checking in at once each get their own contest, side by
// side, up to four at a time; a long queue plays faster rather than late.
//
// It reads lobby-<key>.json, the only snapshot that carries names — and even
// that one drops them when `settings.lobby.show_names` is off, in which case
// the chips read "New arrival".
//
// Every 50 people a fanfare and a confetti rain. Sound needs one click on
// the screen (browsers refuse audio until someone interacts); the same
// click also takes the page full screen and keeps the display awake.

import { call } from '@se/core/api.js';
import { boot } from '@se/core/store.js';
import { syncClock, serverNow } from '@se/core/clock.js';
import { PollDriver } from '@se/core/realtime.js';
import { qrSvg } from '@se/core/qr.js';
import { prefersReducedMotion } from '@se/core/boot.js';
import { burst, rain } from '@se/core/confetti.js';

const config = boot.value || {};
const reduced = prefersReducedMotion();

const NAMES_PER_CORNER = 3;
const MAX_ACTIVE = 4;
const LAUNCH_GAP_MS = 380;
const MILESTONE_EVERY = 50;
const TIP_EVERY_MS = 9000;

// Corner order by team position. Four teams fill the four corners; five or
// six add the middle of each side.
const POSITIONS = ['tl', 'tr', 'bl', 'br', 'ml', 'mr'];

// Where a name pops out to wait for its contest, as fractions of the frame.
// They ring the QR without covering it, so the code stays scannable.
const SLOTS = [
    { x: 0.275, y: 0.40 }, { x: 0.725, y: 0.40 },
    { x: 0.275, y: 0.62 }, { x: 0.725, y: 0.62 },
    { x: 0.275, y: 0.51 }, { x: 0.725, y: 0.51 },
];

const TIPS = [
    'No smartphone? Our volunteers will check you in.',
    'Your team colour is waiting for you.',
    'Signed up to sing? Pick your karaoke song right after you check in.',
    'Point your camera at the code — no app needed.',
];

let display = null;
let frame = null;
let beamsSvg = null;
let chipsLayer = null;
let totalNode = null;
let tipNode = null;
let startsNode = null;
let milestoneNode = null;
let dot = null;

const teams = new Map();       // id → {id, label, hex, on, ring, pos, n, names[], el{…}}
const seen = new Set();        // check-in ids already shown
const queue = [];              // arrivals waiting for a contest
const freeSlots = new Set(SLOTS.map((_, i) => i));

let firstPaint = true;
let active = 0;
let lastLaunch = 0;
let pumpTimer = null;
let shownTotal = 0;
let latest = null;             // the newest snapshot's data
let lastMilestone = 0;
let showNames = true;
let audio = null;

// --------------------------------------------------------------------------
// Small helpers
// --------------------------------------------------------------------------

function node(tag, className, text) {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text != null) element.textContent = text;
    return element;
}

function svgNode(tag, attrs = {}) {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [key, value] of Object.entries(attrs)) element.setAttribute(key, String(value));
    return element;
}

function teamTitle(team) {
    return team.name || team.label || 'Team';
}

function shortUrl(url) {
    return String(url || '').replace(/^https?:\/\//, '');
}

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/** A point inside the frame, in frame pixels, for a fraction of its size. */
function at(fx, fy) {
    const box = frame.getBoundingClientRect();
    return { x: box.width * fx, y: box.height * fy };
}

/** The centre of an element, in frame pixels. */
function centreOf(element) {
    const box = frame.getBoundingClientRect();
    const r = element.getBoundingClientRect();
    return { x: r.left - box.left + r.width / 2, y: r.top - box.top + r.height / 2 };
}

// --------------------------------------------------------------------------
// Build
// --------------------------------------------------------------------------

function build() {
    const host = document.getElementById('se-app');
    host.hidden = false;
    host.className = 'se-display se-arena';
    host.replaceChildren();

    // The event's own artwork, blurred into atmosphere, under a vignette that
    // keeps every word readable whatever the picture is.
    const bg = node('div', 'se-arena-bg');
    if (display.hero) bg.style.setProperty('--hero', 'url("' + encodeURI(display.hero) + '")');
    else bg.dataset.empty = '1';
    host.append(bg, node('div', 'se-arena-vignette'));

    frame = node('div', 'se-frame se-arena-frame');

    beamsSvg = svgNode('svg', { class: 'se-arena-beams', 'aria-hidden': 'true' });
    frame.appendChild(beamsSvg);

    // The centre: what this screen is for.
    const centre = node('div', 'se-arena-centre');
    startsNode = node('p', 'se-arena-eyebrow', '');
    const title = node('h1', 'se-arena-title');
    title.append(node('span', 'se-arena-title-pre', 'Scan to enter'),
        node('span', 'se-arena-title-main', 'the ' + (display.event?.title || 'Party') + ' Party'));

    const qr = node('div', 'se-arena-qr');
    const ring = node('div', 'se-arena-qr-ring');
    const tile = node('div', 'se-arena-qr-tile');
    tile.appendChild(qrSvg(display.checkin_url, { size: 720, quiet: 0.04 }));
    qr.append(ring, tile);

    const url = node('p', 'se-arena-url', shortUrl(display.checkin_url));
    const total = node('p', 'se-arena-total');
    totalNode = node('strong', 'se-arena-total-n', '0');
    total.append(totalNode, node('span', null, ' here tonight'));
    tipNode = node('p', 'se-arena-tip', TIPS[0]);

    centre.append(startsNode, title, qr, url, total, tipNode);
    frame.appendChild(centre);

    chipsLayer = node('div', 'se-arena-chips');
    chipsLayer.setAttribute('aria-hidden', 'true');
    frame.appendChild(chipsLayer);

    milestoneNode = node('div', 'se-arena-milestone');
    milestoneNode.setAttribute('role', 'status');
    frame.appendChild(milestoneNode);

    dot = node('span', 'se-dot-live');
    dot.title = 'Live';
    frame.appendChild(dot);

    host.appendChild(frame);

    const hint = node('button', 'se-arena-hint', 'Click for sound and full screen');
    hint.type = 'button';
    host.appendChild(hint);
    host.addEventListener('click', () => wake(hint), { once: true });

    document.getElementById('se-crew-frame')?.remove();

    setInterval(rotateTip, TIP_EVERY_MS);
    setInterval(renderStarts, 15000);
}

/** Paint (or repaint) the corners from the snapshot's team list. */
function buildCorners(list) {
    const wanted = list.slice(0, POSITIONS.length);
    const ids = wanted.map((t) => t.id).join(',');
    if (frame.dataset.teams === ids) return;
    frame.dataset.teams = ids;
    frame.dataset.count = String(wanted.length);

    frame.querySelectorAll('.se-corner').forEach((n) => n.remove());

    wanted.forEach((team, index) => {
        const pos = POSITIONS[index];
        const corner = node('section', 'se-corner');
        corner.dataset.pos = pos;
        if (team.ring) corner.dataset.ring = '1';
        corner.style.setProperty('--team-color', team.hex);
        corner.style.setProperty('--team-on', team.on || '#fff');

        const glow = node('div', 'se-corner-glow');
        const head = node('div', 'se-corner-head');
        const label = node('p', 'se-corner-label', 'Team');
        const name = node('p', 'se-corner-name', teamTitle(team));
        head.append(label, name);
        const count = node('p', 'se-corner-count', String(team.n || 0));
        const names = node('ol', 'se-corner-names');
        corner.append(glow, head, count, names);
        frame.insertBefore(corner, chipsLayer);

        const existing = teams.get(team.id) || { names: [] };
        teams.set(team.id, {
            ...existing,
            ...team,
            pos,
            index,
            n: team.n || 0,
            el: { corner, glow, count, names, name },
        });
        renderNames(teams.get(team.id));
    });

    // The ring around the QR is made of the teams' own colours.
    const stops = wanted.map((t) => t.hex);
    frame.style.setProperty('--arena-stops', stops.concat(stops[0] || '#fff').join(', '));
}

function renderNames(team) {
    const items = team.names.slice(0, NAMES_PER_CORNER).map((name, i) => {
        const li = node('li', 'se-corner-person', name);
        li.dataset.rank = String(i);
        return li;
    });
    team.el.names.replaceChildren(...items);
}

// --------------------------------------------------------------------------
// The snapshot
// --------------------------------------------------------------------------

function render(envelope) {
    const data = envelope?.data || {};
    latest = data;
    showNames = data.show_names !== false;

    buildCorners(data.teams || []);

    const arrivals = (data.arrivals || []).slice();

    if (firstPaint) {
        // Whatever happened before this screen was switched on is history:
        // paint it still, and only animate what arrives from now on.
        for (const team of teams.values()) team.names = [];
        for (const a of arrivals) {
            seen.add(a.id ?? a.at_ms);
            const team = teams.get(a.team_id);
            if (team && team.names.length < NAMES_PER_CORNER) team.names.push(label(a));
        }
        for (const team of teams.values()) {
            team.el.count.textContent = String(team.n);
            renderNames(team);
        }
        shownTotal = data.checked_in || 0;
        totalNode.textContent = String(shownTotal);
        lastMilestone = Math.floor(shownTotal / MILESTONE_EVERY) * MILESTONE_EVERY;
        firstPaint = false;
        renderStarts();
        return;
    }

    // Oldest first, so a burst plays in the order people came through.
    const fresh = arrivals.filter((a) => !seen.has(a.id ?? a.at_ms)).reverse();
    for (const a of fresh) {
        seen.add(a.id ?? a.at_ms);
        queue.push(a);
    }

    pump();
    reconcileWhenIdle();
}

function label(arrival) {
    return showNames && arrival.name ? arrival.name : 'New arrival';
}

/**
 * When nothing is animating, make the corners agree with the server: an
 * undo at the desk, a team move or a reset all change counts without an
 * arrival to animate.
 */
function reconcileWhenIdle() {
    if (active || queue.length || !latest) return;

    for (const t of latest.teams || []) {
        const team = teams.get(t.id);
        if (team && team.n !== t.n) {
            team.n = t.n;
            team.el.count.textContent = String(t.n);
        }
    }
    if ((latest.checked_in || 0) !== shownTotal) {
        shownTotal = latest.checked_in || 0;
        totalNode.textContent = String(shownTotal);
    }
}

// --------------------------------------------------------------------------
// The contest
// --------------------------------------------------------------------------

/** Start as many queued contests as there is room for, one beat apart. */
function pump() {
    clearTimeout(pumpTimer);
    while (queue.length && active < MAX_ACTIVE && freeSlots.size) {
        const now = performance.now();
        if (now - lastLaunch < LAUNCH_GAP_MS) {
            pumpTimer = setTimeout(pump, LAUNCH_GAP_MS - (now - lastLaunch));
            return;
        }
        lastLaunch = now;

        const arrival = queue.shift();
        const slot = takeSlot();
        active += 1;
        contest(arrival, slot)
            .catch((e) => console.error('[se] lobby contest', e))
            .finally(() => {
                freeSlots.add(slot);
                active -= 1;
                pump();
                reconcileWhenIdle();
            });
    }
}

function takeSlot() {
    const free = Array.from(freeSlots);
    const slot = free[Math.floor(Math.random() * free.length)];
    freeSlots.delete(slot);
    return slot;
}

/** How long a contest takes: shorter when people are queueing for one. */
function tempo() {
    const backlog = queue.length;
    if (backlog > 14) return { pop: 260, fight: 700, fly: 520 };
    if (backlog > 6) return { pop: 340, fight: 1200, fly: 620 };
    return { pop: 480, fight: 1900, fly: 760 };
}

async function contest(arrival, slotIndex) {
    const winner = teams.get(arrival.team_id);
    const name = label(arrival);

    if (reduced || !winner) {
        // No theatre: the name simply joins its team.
        land(winner, name);
        await wait(reduced ? 250 : 600);
        return;
    }

    const t = tempo();
    const origin = at(0.5, 0.5);
    const slot = at(SLOTS[slotIndex].x, SLOTS[slotIndex].y);

    const chip = node('div', 'se-arena-chip', name);
    chipsLayer.appendChild(chip);

    // 1. Out of the QR.
    await chip.animate([
        { transform: `translate(${origin.x}px, ${origin.y}px) translate(-50%, -50%) scale(0.2)`, opacity: 0 },
        { transform: `translate(${slot.x}px, ${slot.y}px) translate(-50%, -50%) scale(1.08)`, opacity: 1, offset: 0.75 },
        { transform: `translate(${slot.x}px, ${slot.y}px) translate(-50%, -50%) scale(1)`, opacity: 1 },
    ], { duration: t.pop, easing: 'cubic-bezier(.2,.9,.3,1.2)', fill: 'forwards' }).finished;

    // 2. Every team pulls. Beams from each corner, the name leaning to each
    //    in turn, the beat speeding up, the chip's glow taking each colour.
    const contenders = Array.from(teams.values());
    const beams = contenders.map((team) => {
        const from = centreOf(team.el.count);
        const line = svgNode('line', {
            x1: from.x, y1: from.y, x2: slot.x, y2: slot.y,
            class: 'se-arena-beam',
        });
        line.style.setProperty('--team-color', team.hex);
        beamsSvg.appendChild(line);
        return { team, line, from };
    });

    frame.dataset.beating = String(Number(frame.dataset.beating || 0) + 1);

    const sway = [];
    const steps = Math.max(6, Math.round(t.fight / 150));
    for (let i = 0; i <= steps; i++) {
        const pick = beams[(i + slotIndex) % beams.length];
        const pull = 0.06 + 0.06 * (i / steps);
        const dx = (pick.from.x - slot.x) * pull;
        const dy = (pick.from.y - slot.y) * pull;
        const offset = Math.pow(i / steps, 0.7);
        sway.push({
            transform: `translate(${slot.x + dx}px, ${slot.y + dy}px) translate(-50%, -50%) rotate(${(i % 2 ? 3 : -3)}deg)`,
            boxShadow: `0 0 0 0.25vw ${pick.team.hex}, 0 0 4vw ${pick.team.hex}`,
            offset,
        });
    }
    const fight = chip.animate(sway, { duration: t.fight, easing: 'linear', fill: 'forwards' });

    const flickers = beams.map(({ line }, i) => line.animate([
        { opacity: 0, strokeWidth: 0.5 },
        { opacity: 0.85, strokeWidth: 3, offset: 0.15 },
        { opacity: 0.25, strokeWidth: 1.5, offset: 0.35 },
        { opacity: 0.9, strokeWidth: 3.5, offset: 0.6 },
        { opacity: 0.3, strokeWidth: 2, offset: 0.8 },
        { opacity: 0.95, strokeWidth: 4 },
    ], { duration: t.fight, delay: i * 40, easing: 'ease-in-out', fill: 'forwards' }));

    await fight.finished;

    // 3. The winner locks on; the rest let go.
    const won = beams.find((b) => b.team === winner) || beams[0];
    flickers.forEach((f) => f.cancel());
    beams.forEach(({ line, team }) => {
        if (team === winner) {
            line.classList.add('se-arena-beam-won');
            line.animate([{ opacity: 1, strokeWidth: 10 }, { opacity: 0, strokeWidth: 2 }],
                { duration: t.fly + 120, easing: 'ease-in', fill: 'forwards' });
        } else {
            line.animate([{ opacity: 0.8 }, { opacity: 0 }], { duration: 160, fill: 'forwards' });
        }
    });
    winner.el.corner.classList.remove('se-corner-claim');
    void winner.el.corner.offsetWidth;
    winner.el.corner.classList.add('se-corner-claim');

    chip.style.setProperty('--team-color', winner.hex);
    chip.style.setProperty('--team-on', winner.on || '#fff');
    chip.classList.add('se-arena-chip-won');

    // 4. Home, along a curve that bows away from the centre.
    const landing = centreOf(winner.el.names);
    const start = sway.length ? parseSlot(sway[sway.length - 1].transform) : slot;
    const mid = {
        x: (start.x + landing.x) / 2 + (landing.y - start.y) * 0.25,
        y: (start.y + landing.y) / 2 - Math.abs(landing.x - start.x) * 0.18,
    };
    const path = [];
    for (let i = 0; i <= 10; i++) {
        const k = i / 10;
        const x = (1 - k) * (1 - k) * start.x + 2 * (1 - k) * k * mid.x + k * k * landing.x;
        const y = (1 - k) * (1 - k) * start.y + 2 * (1 - k) * k * mid.y + k * k * landing.y;
        path.push({
            transform: `translate(${x}px, ${y}px) translate(-50%, -50%) scale(${1.15 - 0.55 * k})`,
            opacity: k > 0.9 ? 0 : 1,
            offset: k,
        });
    }
    await chip.animate(path, { duration: t.fly, easing: 'cubic-bezier(.5,0,.2,1)', fill: 'forwards' }).finished;

    chip.remove();
    beams.forEach(({ line }) => line.remove());
    frame.dataset.beating = String(Math.max(0, Number(frame.dataset.beating || 1) - 1));

    // 5. The corner takes them in.
    land(winner, name, landing);
}

function parseSlot(transform) {
    const m = /translate\(([-\d.]+)px, ([-\d.]+)px\)/.exec(transform);
    return m ? { x: Number(m[1]), y: Number(m[2]) } : at(0.5, 0.5);
}

/** The arrival is home: names, count, total, confetti and any milestone. */
function land(team, name, point) {
    if (team) {
        team.names.unshift(name);
        team.names.length = Math.min(team.names.length, NAMES_PER_CORNER);
        renderNames(team);
        team.el.names.firstChild?.classList.add('se-corner-person-new');

        team.n += 1;
        team.el.count.textContent = String(team.n);
        team.el.count.classList.remove('se-corner-count-bump');
        void team.el.count.offsetWidth;
        team.el.count.classList.add('se-corner-count-bump');

        if (point && !reduced) {
            const box = frame.getBoundingClientRect();
            const toward = team.pos.endsWith('l') ? -60 : -120;
            burst({
                x: box.left + point.x,
                y: box.top + point.y,
                colors: [team.hex, team.hex, '#ffffff', '#ffd76a'],
                count: 90,
                power: 0.9,
                spread: 55,
                angle: team.pos.startsWith('b') ? toward : (team.pos.endsWith('l') ? 45 : 135),
            });
        }
    }

    shownTotal += 1;
    totalNode.textContent = String(shownTotal);
    totalNode.classList.remove('se-arena-total-bump');
    void totalNode.offsetWidth;
    totalNode.classList.add('se-arena-total-bump');

    const mark = Math.floor(shownTotal / MILESTONE_EVERY) * MILESTONE_EVERY;
    if (mark > lastMilestone && mark > 0) {
        lastMilestone = mark;
        milestone(mark);
    }
}

// --------------------------------------------------------------------------
// Milestones
// --------------------------------------------------------------------------

function milestone(count) {
    const colors = Array.from(teams.values()).map((t) => t.hex);
    rain({ colors: colors.length ? colors.concat(['#ffffff']) : ['#ffffff', '#ffd76a'] });
    fanfare();

    milestoneNode.replaceChildren(
        node('span', 'se-arena-milestone-n', String(count)),
        node('span', 'se-arena-milestone-words', 'of us are here!'));
    milestoneNode.classList.remove('se-arena-milestone-on');
    void milestoneNode.offsetWidth;
    milestoneNode.classList.add('se-arena-milestone-on');
}

/**
 * A short bright fanfare, synthesised: the stage sprite has not been
 * produced yet, and a lobby screen should not depend on a file anyway.
 */
function fanfare() {
    if (!audio) return;
    const now = audio.currentTime + 0.02;
    const notes = [523.25, 659.25, 783.99, 1046.5, 783.99, 1046.5];
    const times = [0, 0.12, 0.24, 0.36, 0.62, 0.74];
    const lengths = [0.14, 0.14, 0.14, 0.3, 0.12, 0.7];

    notes.forEach((freq, i) => {
        for (const [type, gain] of [['triangle', 0.22], ['sawtooth', 0.04]]) {
            const osc = audio.createOscillator();
            const amp = audio.createGain();
            osc.type = type;
            osc.frequency.value = freq;
            const t0 = now + times[i];
            amp.gain.setValueAtTime(0, t0);
            amp.gain.linearRampToValueAtTime(gain, t0 + 0.02);
            amp.gain.exponentialRampToValueAtTime(0.0001, t0 + lengths[i]);
            osc.connect(amp).connect(audio.destination);
            osc.start(t0);
            osc.stop(t0 + lengths[i] + 0.05);
        }
    });
}

/** The one click: sound, full screen, and no screen saver. */
async function wake(hint) {
    hint.remove();
    try {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (Ctx) {
            audio = new Ctx();
            await audio.resume();
        }
    } catch (e) { audio = null; }
    try { await document.documentElement.requestFullscreen?.(); } catch (e) { /* the screen may refuse */ }
    try {
        let lock = await navigator.wakeLock?.request('screen');
        document.addEventListener('visibilitychange', async () => {
            if (document.visibilityState === 'visible' && (!lock || lock.released)) {
                try { lock = await navigator.wakeLock?.request('screen'); } catch (e) { /* ignore */ }
            }
        });
    } catch (e) { /* not supported */ }
}

// --------------------------------------------------------------------------
// The quiet parts
// --------------------------------------------------------------------------

let tipIndex = 0;
function rotateTip() {
    tipIndex = (tipIndex + 1) % TIPS.length;
    tipNode.classList.remove('se-arena-tip-in');
    void tipNode.offsetWidth;
    tipNode.textContent = TIPS[tipIndex];
    tipNode.classList.add('se-arena-tip-in');
}

function renderStarts() {
    const title = [display.event?.title, display.event?.edition].filter(Boolean).join(' ');
    const startsAt = Number(display.event?.starts_ms || Date.parse(latest?.starts_at || ''));
    let when = '';
    if (Number.isFinite(startsAt) && startsAt > 0) {
        const left = startsAt - serverNow();
        if (left <= 0) when = 'Happening now';
        else {
            const h = Math.floor(left / 3600000);
            const m = Math.max(1, Math.round((left % 3600000) / 60000));
            when = 'Starts in ' + (h ? h + 'h ' : '') + m + ' min';
        }
    }
    startsNode.textContent = [title, when].filter(Boolean).join('  ·  ');
}

// --------------------------------------------------------------------------

async function start() {
    if (!config.event?.public_id) return;

    const key = config.display_key;
    const fail = (message) => document.getElementById('se-crew-frame')?.replaceChildren(
        node('p', 'se-glass se-pad', message));

    if (!key) {
        fail('This screen needs its link from Studio → Live → Screen links (the one ending in ?k=…).');
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
        // Once a second: the file is static and answered with a 304 almost
        // every time, and an arrival should reach the screen while the guest
        // is still looking up from their phone.
        pace: () => 'hot',
        onStatus: ({ state }) => { if (dot) dot.dataset.health = state === 'ok' ? 'ok' : (state === 'retrying' ? 'warn' : 'bad'); },
    });
}

start();
