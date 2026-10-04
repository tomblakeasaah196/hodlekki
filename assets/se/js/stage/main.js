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
// PR3 shipped the core scenes (standby, welcome, teams, announcement,
// break, blank) plus placeholders. PR4 makes the programme scene real —
// now and next, off the live timeline — and adds the karaoke scene, which
// reads the room snapshot because it says singers' names and public.json
// is world-readable (§8.5.2). PR5/PR6 add the game scenes — every game
// type, with countdowns, the answer spread at the reveal, the buzz winner,
// the charades actor and the Feud board — the leaderboard and the finale.

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

/**
 * Show a scene: a 400 ms crossfade for a new scene (§13.8), an instant swap
 * for new numbers in the same one — a projector that fades every second
 * while answers come in looks broken.
 */
function paint(children, inPlace = false) {
    const current = frame.querySelector('.se-scene:last-of-type');
    if (inPlace && current) {
        current.replaceChildren(...children.filter(Boolean));
        return;
    }

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

/**
 * The programme scene (§10.7.5).
 *
 * The room only ever needs two lines: what is happening and what is after
 * it. Times come from the live ETA, so a night running twenty minutes late
 * shows twenty-minutes-late times rather than the plan nobody is following.
 */
function sceneProgram(scene, data) {
    const payload = scene?.payload || {};
    const program = data.program || {};
    const now = program.now || null;
    const next = program.next || null;

    const out = [node('p', 'se-scene-sub', payload.label || 'On now')];
    out.push(node('h1', 'se-scene-title', now?.title || payload.title || 'Tonight'));
    if (now?.blurb || now?.host) {
        out.push(node('p', 'se-scene-body', now.blurb || now.host));
    }

    if (next) {
        const after = node('div', 'se-scene-next');
        after.appendChild(node('p', 'se-scene-sub', 'Next'));
        after.appendChild(node('p', 'se-scene-next-title',
            next.title + (next.time ? ' · ' + next.time : '')));
        out.push(after);
    }

    if (program.drift_min) {
        out.push(node('p', 'se-scene-counter',
            'Running ' + Math.abs(program.drift_min) + ' minutes '
            + (program.drift_min > 0 ? 'behind' : 'ahead')));
    }

    return out;
}

/**
 * The karaoke scene (§10.8.4).
 *
 * Names on a projector are the point here — the room cheers for a person,
 * not for a song — so this is the one scene that insists on the room
 * snapshot and shows nothing rather than guessing from public.json.
 */
function sceneKaraoke() {
    const karaoke = room.value?.data?.karaoke || null;

    if (!karaoke || (!karaoke.now && !karaoke.next)) {
        return [
            node('p', 'se-scene-sub', 'Karaoke'),
            node('h1', 'se-scene-title', 'Who is next?'),
            node('p', 'se-scene-body', 'Pick your song on your phone.'),
        ];
    }

    const out = [node('p', 'se-scene-sub', 'Singing now')];

    if (karaoke.now) {
        out.push(node('h1', 'se-scene-title', karaoke.now.singer));
        out.push(node('p', 'se-scene-body',
            karaoke.now.song + ' · ' + karaoke.now.artist));
    } else {
        out.push(node('h1', 'se-scene-title', 'Up next'));
    }

    if (karaoke.next) {
        const after = node('div', 'se-scene-next');
        after.appendChild(node('p', 'se-scene-sub', 'Up next'));
        after.appendChild(node('p', 'se-scene-next-title',
            karaoke.next.singer + ' — ' + karaoke.next.song));
        out.push(after);
    }

    if (karaoke.stats?.remaining) {
        out.push(node('p', 'se-scene-counter', karaoke.stats.remaining + ' waiting'));
    }

    return out;
}

function sceneBreak(scene) {
    const payload = scene?.payload || {};
    const back = node('p', 'se-scene-counter', '');
    // se_scene_payload_clean() writes `label` and `ends_ms`.
    const until = payload.ends_ms || payload.until_ms || 0;

    if (until) {
        const tick = () => {
            const left = Math.max(0, until - serverNow());
            const m = Math.floor(left / 60000);
            const s = Math.floor((left % 60000) / 1000);
            const text = m + ':' + String(s).padStart(2, '0');
            if (back.textContent !== text) back.textContent = text;
            if (left > 0 && (back.isConnected || !back.dataset.started)) {
                back.dataset.started = '1';
                requestAnimationFrame(tick);
            }
        };
        tick();
    }

    return [node('h1', 'se-scene-title', payload.label || payload.title || 'Back shortly'), back];
}

function sceneBlank() {
    return [node('h1', 'se-scene-title', display.event.title)];
}

const teamName = (team) => (team ? (team.name || 'Team ' + team.label) : '');
const LETTERS = ['A', 'B', 'C', 'D'];
const HOW_TO = {
    live_quiz: 'Answer on your phone — right and fast scores the most.',
    trivia: 'Captains lock in one answer for the team. Everyone else, suggest!',
    buzzer: 'Buzz on your phone, then answer out loud.',
    who_am_i: 'Clues one at a time. Buzz the moment you know.',
    charades: 'One actor, no words. Shout your guesses!',
    feud: 'Survey says! Name the most popular answers.',
};

function teamBadge(team, className = 'se-stage-team') {
    const badge = node('span', className, teamName(team));
    if (team) {
        badge.style.setProperty('--team-color', team.hex);
        badge.style.setProperty('--team-on', team.on);
    }
    return badge;
}

/**
 * A countdown that keeps itself up to date: "3… 2… 1…" before a window
 * opens, then the seconds left. It stops on its own once its scene is gone.
 */
function countdown(round, { open = 'left', className = 'se-stage-timer' } = {}) {
    const el = node('p', className, '');
    if (!round?.opens_ms || !round?.closes_ms) return null;

    let shown = '';
    const tick = () => {
        const now = serverNow();
        let text;
        if (now < round.opens_ms) {
            el.dataset.phase = 'ready';
            text = String(Math.ceil((round.opens_ms - now) / 1000));
        } else if (now < round.closes_ms) {
            el.dataset.phase = 'open';
            text = Math.ceil((round.closes_ms - now) / 1000) + (open ? ' ' + open : '');
        } else {
            el.dataset.phase = 'over';
            text = "Time's up";
        }
        if (text !== shown) { el.textContent = text; shown = text; }
        if (el.dataset.phase !== 'over' && (el.isConnected || !el.dataset.started)) {
            el.dataset.started = '1';
            requestAnimationFrame(tick);
        }
    };
    tick();
    return el;
}

function teamPoints(round, teams) {
    const rows = Object.entries(round.team_points || {}).filter(([, p]) => p > 0);
    if (!rows.length) return null;
    const list = node('div', 'se-stage-points');
    for (const [id, points] of rows.sort((a, b) => b[1] - a[1])) {
        const row = node('p', 'se-stage-points-row');
        row.append(teamBadge(teams[id]), node('strong', '', '+' + points));
        list.appendChild(row);
    }
    return list;
}

function quizScene(game, round, teams) {
    const revealed = ['revealed', 'scored'].includes(round.state);
    const result = round.result || {};
    const counts = result.distribution || [];
    const total = counts.reduce((a, b) => a + b, 0);

    const grid = node('ol', 'se-stage-choices');
    (round.choices || []).forEach((choice, i) => {
        const tile = node('li', 'se-stage-choice');
        tile.dataset.i = String(i);
        if (revealed) tile.dataset.state = i === result.correct_index ? 'correct' : 'wrong';
        tile.append(node('span', 'se-stage-choice-letter', LETTERS[i]), node('span', 'se-stage-choice-text', choice));
        if (revealed) {
            const n = counts[i] || 0;
            const bar = node('span', 'se-stage-choice-bar');
            bar.style.setProperty('--share', total ? (n / total) : 0);
            tile.append(bar, node('span', 'se-stage-choice-n', String(n)));
        }
        grid.appendChild(tile);
    });

    const top = node('div', 'se-stage-row');
    top.append(node('p', 'se-scene-sub', game.title + ' · Question ' + round.round_no));
    if (!revealed && round.state !== 'locked') {
        const timer = countdown(round, { open: '' });
        if (timer) top.appendChild(timer);
    }

    const out = [top, node('h1', 'se-stage-q', round.prompt || '')];
    const before = round.opens_ms && serverNow() < round.opens_ms && round.state === 'armed';
    if (!before || revealed) out.push(grid);

    if (revealed) {
        if (result.explanation || result.ref) {
            out.push(node('p', 'se-scene-body', [result.explanation, result.ref].filter(Boolean).join(' — ')));
        }
        if (round.state === 'scored') out.push(teamPoints(round, teams));
    } else {
        out.push(node('p', 'se-stage-meta', (round.answered || 0) + (game.type === 'trivia' ? ' teams locked in' : ' answered')));
    }
    return out;
}

function buzzScene(game, round, teams) {
    const revealed = ['revealed', 'scored'].includes(round.state);
    const winner = room.value?.data?.buzz_winner;
    const out = [node('p', 'se-scene-sub', game.title + ' · ' + (game.type === 'who_am_i' ? 'Person ' : 'Question ') + round.round_no)];

    if (game.type === 'who_am_i') {
        const clues = node('ol', 'se-stage-clues');
        (round.clues || []).forEach((clue, i, all) => {
            const li = node('li', '', clue);
            li.dataset.new = i === all.length - 1 && !revealed ? '1' : '0';
            clues.appendChild(li);
        });
        out.push(clues);
        if (!revealed) out.push(node('p', 'se-stage-meta', 'Clue ' + ((round.clue_index ?? 0) + 1) + ' of ' + (round.clues_total || '?')));
    } else {
        out.push(node('h1', 'se-stage-q', round.prompt || ''));
    }

    if (revealed) {
        const result = round.result || {};
        out.push(node('p', 'se-stage-answer', '✓ ' + (result.answer || '')));
        if (result.winner_team_id && teams[result.winner_team_id]) {
            const banner = node('p', 'se-stage-buzz');
            banner.append(teamBadge(teams[result.winner_team_id]), node('span', '', ' +' + (round.team_points?.[String(result.winner_team_id)] || 0)));
            out.push(banner);
        }
        return out;
    }

    if (winner && winner.round_id === round.id && winner.attempt === round.attempt) {
        const banner = node('p', 'se-stage-buzz');
        banner.style.setProperty('--team-color', teams[winner.team_id]?.hex || '');
        banner.append(node('span', 'se-stage-buzz-name', '🔔 ' + winner.display_name), teamBadge(teams[winner.team_id]));
        out.push(banner);
    } else if (['armed', 'open'].includes(round.state)) {
        const timer = countdown(round, { open: 'seconds to buzz' });
        if (timer) out.push(timer);
    }

    const out_ = (round.locked_out || []).map((id) => teamName(teams[id])).filter(Boolean);
    if (out_.length) out.push(node('p', 'se-stage-meta', 'Out: ' + out_.join(', ')));
    return out;
}

function charadesScene(game, round, teams) {
    const presenter = room.value?.data?.presenter;
    const acting = teams[round.team_id];
    const out = [node('p', 'se-scene-sub', game.title)];

    if (round.state === 'scored') {
        out.push(node('h1', 'se-scene-title', teamName(acting) + ': ' + (round.words_done || 0)));
        out.push(node('p', 'se-scene-body', (round.words_done === 1 ? 'phrase' : 'phrases') + ' guessed'));
        return out;
    }
    if (!acting) {
        out.push(node('h1', 'se-scene-title', 'Who is acting next?'), node('p', 'se-scene-body', HOW_TO.charades));
        return out;
    }

    out.push(teamBadge(acting, 'se-stage-team se-stage-team-lg'));
    out.push(node('h1', 'se-scene-title', presenter?.display_name ? presenter.display_name + ' is acting' : 'Get ready…'));
    if (round.opens_ms) {
        const timer = countdown(round, { open: '', className: 'se-stage-timer se-stage-timer-lg' });
        if (timer) out.push(timer);
    }
    out.push(node('p', 'se-scene-counter', (round.words_done || 0) + ' guessed'));
    return out;
}

function feudScene(game, round, teams) {
    const out = [node('p', 'se-scene-sub', game.title + (round.multiplier > 1 ? ' · double points ×' + round.multiplier : '')), node('h1', 'se-stage-q', round.question || '')];

    const board = node('ol', 'se-stage-board');
    board.style.setProperty('--rows', String(Math.max(1, Math.ceil((round.board || []).length / 2))));
    for (const slot of round.board || []) {
        const li = node('li', 'se-stage-slot');
        li.dataset.revealed = slot.revealed ? '1' : '0';
        li.append(node('span', '', slot.revealed ? slot.label : String(slot.slot)), node('strong', '', slot.revealed ? String(slot.points) : ''));
        board.appendChild(li);
    }
    out.push(board);

    const status = node('div', 'se-stage-row');
    const faceoff = room.value?.data?.faceoff;
    if (round.phase === 'faceoff') {
        const text = faceoff && faceoff.round_id === round.id
            ? faceoff.rep_a.display_name + ' vs ' + faceoff.rep_b.display_name
            : teamName(teams[round.team_a]) + ' vs ' + teamName(teams[round.team_b]);
        status.append(node('p', 'se-stage-meta', 'Face-off: ' + text));
        if (faceoff?.first_buzz) status.append(node('p', 'se-stage-meta', '🔔 ' + faceoff.first_buzz.display_name));
    } else if (round.phase === 'control' || round.phase === 'steal') {
        status.append(teamBadge(teams[round.phase === 'steal' ? round.steal_team : round.control_team]));
        status.append(node('p', 'se-stage-meta', round.phase === 'steal' ? 'can steal!' : 'is playing'));
        const strikes = node('p', 'se-stage-strikes');
        for (let i = 0; i < 3; i++) {
            const x = node('span', '', '✕');
            x.dataset.on = i < round.strikes ? '1' : '0';
            strikes.appendChild(x);
        }
        status.append(strikes);
    } else if (round.phase === 'done' && round.winner_team) {
        status.append(teamBadge(teams[round.winner_team]), node('p', 'se-stage-meta', 'banks ' + (round.team_points?.[String(round.winner_team)] ?? round.bank)));
    }
    status.append(node('p', 'se-stage-meta se-stage-bank', 'Bank ' + (round.bank * (round.multiplier || 1))));
    out.push(status);
    return out;
}

function sceneGame(data) {
    const game = data.game || null;
    const round = game?.round || null;
    const teams = Object.fromEntries((data.teams || []).map((t) => [t.id, t]));

    if (!game) {
        return [node('p', 'se-scene-sub', display.event.title), node('h1', 'se-scene-title', 'Game time'), node('p', 'se-scene-body', 'Get your phones ready.')];
    }
    if (!round || round.state === 'pending' || round.state === 'void' || game.status !== 'live') {
        const out = [node('p', 'se-scene-sub', round ? 'Up next' : 'Next game'), node('h1', 'se-scene-title', game.title)];
        out.push(node('p', 'se-scene-body', HOW_TO[game.type] || ''));
        return out;
    }

    if (game.type === 'live_quiz' || game.type === 'trivia') return quizScene(game, round, teams);
    if (game.type === 'buzzer' || game.type === 'who_am_i') return buzzScene(game, round, teams);
    if (game.type === 'charades') return charadesScene(game, round, teams);
    if (game.type === 'feud') return feudScene(game, round, teams);
    return [node('h1', 'se-scene-title', game.title)];
}

function sceneLeaderboard(data, scene) {
    const board = node('ol', 'se-stage-leaderboard');
    [...(data.teams || [])].sort((a, b) => b.score - a.score).forEach((team, index) => {
        const row = node('li', 'se-stage-leader-row');
        row.style.setProperty('--team-color', team.hex);
        row.append(node('strong', '', (index + 1) + '. ' + teamName(team)), node('span', '', team.score + ' pts'));
        board.appendChild(row);
    });
    const out = [node('p', 'se-scene-sub', 'Championship'), node('h1', 'se-scene-title', 'Leaderboard'), board];
    const mvp = room.value?.data?.mvp || [];
    if (mvp.length && scene?.payload?.show_mvp !== false) out.push(node('p', 'se-scene-body', 'MVP · ' + mvp[0].display_name + ' · ' + mvp[0].points + ' pts'));
    return out;
}

/**
 * The finale (§11.11): the teams count up from last place, the champions
 * land last with the fanfare, then the MVP — whose name comes from the room
 * snapshot, because public.json never carries a person.
 */
function sceneFinale(scene) {
    const finale = scene?.payload || {};
    const teams = [...(finale.teams || [])].sort((a, b) => b.points - a.points);
    const champion = finale.champion;
    const step = 1.4;

    const out = [node('p', 'se-scene-sub', 'And the champions are…')];
    const list = node('ol', 'se-stage-finale');
    teams.slice(1).reverse().forEach((team, i) => {
        const row = node('li', 'se-stage-leader-row se-stage-finale-row');
        row.style.setProperty('--team-color', team.hex);
        row.style.setProperty('--delay', (i * step) + 's');
        row.append(node('strong', '', (team.rank ?? '') + (team.rank ? '. ' : '') + team.name), node('span', '', team.points + ' pts'));
        list.appendChild(row);
    });
    out.push(list);

    const win = node('h1', 'se-scene-title se-stage-champion', champion ? '🏆 ' + champion.name : 'What a night!');
    win.style.setProperty('--delay', (Math.max(0, teams.length - 1) * step + 0.6) + 's');
    if (champion) win.style.setProperty('--team-color', champion.hex);
    out.push(win);

    if (champion) {
        const points = node('p', 'se-scene-counter se-stage-finale-row', champion.points + ' points');
        points.style.setProperty('--delay', (Math.max(0, teams.length - 1) * step + 1.2) + 's');
        out.push(points);
    }

    const mvp = room.value?.data?.mvp?.[0];
    if (finale.has_mvp && mvp) {
        const line = node('p', 'se-scene-body se-stage-finale-row', 'MVP · ' + mvp.display_name + ' · ' + mvp.points + ' pts');
        line.style.setProperty('--delay', (Math.max(0, teams.length - 1) * step + 2.2) + 's');
        out.push(line);
    }
    return out;
}

/** Fallback for an unknown scene. */
function scenePlaceholder(label) {
    return [
        node('p', 'se-scene-sub', label),
        node('h1', 'se-scene-title', display.event.title + ' ' + (display.event.edition || '')),
        node('p', 'se-scene-body', 'Coming right up.'),
    ];
}

let lastKey = null;
let lastSig = null;
let repaintTimer = null;

/** What a scene shows, so an unchanged snapshot never repaints it. */
function signature(key, data, scene) {
    const r = room.value?.data || {};
    const teams = (data.teams || []).map((t) => [t.id, t.name, t.score, t.n]);
    switch (key) {
        case 'game': return [data.game, teams, r.buzz_winner, r.presenter, r.faceoff];
        case 'leaderboard': return [teams, r.mvp?.[0], scene.payload];
        case 'finale': return [scene.since_ms, scene.payload, r.mvp?.[0]];
        case 'karaoke': return [r.karaoke];
        case 'teams': return [teams];
        case 'standby': return [data.counts?.checked_in_today, scene];
        case 'program': return [data.program, scene];
        case 'announcement': return [data.announcement];
        default: return [scene];
    }
}

function render() {
    const snapshot = live.value;
    if (!snapshot || !frame) return;

    const data = snapshot.data || {};
    const scene = data.scene || { key: 'standby' };

    // An announcement always wins: the host used it because something has to
    // be said right now.
    const key = data.announcement ? 'announcement' : scene.key;

    // A new round is a new scene (a crossfade); new numbers in the same
    // round are an instant update.
    const sceneId = key === 'game'
        ? 'game:' + (data.game?.id ?? 0) + ':' + (data.game?.round?.id ?? 0)
        : key + ':' + (scene.since_ms ?? '');
    const sig = JSON.stringify(signature(key, data, scene));
    if (sceneId === lastKey && sig === lastSig) {
        playCueFromSnapshot(data.sfx);
        return;
    }

    const builders = {
        standby: () => sceneStandby(data),
        welcome: () => sceneWelcome(scene),
        teams: () => sceneTeams(data),
        announcement: () => sceneAnnouncement(data),
        program: () => sceneProgram(scene, data),
        break: () => sceneBreak(scene),
        blank: () => sceneBlank(),
        leaderboard: () => sceneLeaderboard(data, scene),
        recap: () => scenePlaceholder('Recap'),
        game: () => sceneGame(data),
        karaoke: () => sceneKaraoke(),
        finale: () => sceneFinale(scene),
    };

    paint((builders[key] || builders.standby)(), sceneId === lastKey);
    lastKey = sceneId;
    lastSig = sig;
    playCueFromSnapshot(data.sfx);

    // The answer tiles appear when the window opens, which no snapshot
    // announces: repaint at that moment.
    const round = key === 'game' ? data.game?.round : null;
    clearTimeout(repaintTimer);
    if (round?.opens_ms && round.state === 'armed' && round.opens_ms > serverNow()) {
        repaintTimer = setTimeout(() => { lastSig = null; render(); }, round.opens_ms - serverNow() + 50);
    }
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
        channels[roomChannel] = (envelope) => { room.value = envelope; render(); };
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
