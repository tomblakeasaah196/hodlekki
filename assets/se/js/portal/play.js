// /assets/se/js/portal/play.js
//
// The games on a guest's phone (guide §11, §12.2, §13.6).
//
// What the room sees comes from the static snapshots — public.json for the
// round, the room snapshot for names (who buzzed, who is acting) and the
// team snapshot for the captain and the team's suggestions — polled at the
// pace realtime.js picks (1 s only while a round is running). The phone
// asks PHP for its own state (`me`) only when it needs something personal:
// at the start, when a round is scored (its ✓/✗ and points), when it is the
// charades presenter (the secret phrase) and once a minute for the score.
//
// It is Preact rather than hand-built DOM so a re-render never wipes what a
// guest is typing into the survey, and the answer tiles do not flicker on
// every poll.

import { render } from 'preact';
import { useEffect, useRef, useState } from 'preact/hooks';
import { html } from '@se/core/html.js';
import { call } from '@se/core/api.js';
import { serverNow, syncClock, keepClockSynced } from '@se/core/clock.js';
import { PollDriver, paceFor } from '@se/core/realtime.js';

const LETTERS = ['A', 'B', 'C', 'D'];

const HOW_TO = {
    live_quiz: 'Answer on your phone. Right and fast scores the most.',
    trivia: 'Your captain locks in one answer for the team. Tap to suggest yours.',
    buzzer: 'Buzz first, then answer out loud.',
    who_am_i: 'Clues come one at a time. Buzz as soon as you know who it is.',
    charades: 'Watch the actor and shout your guesses.',
    feud: 'Survey says! Watch the board.',
};

/** Re-render four times a second while a clock is on screen. */
function useNow(running) {
    const [now, setNow] = useState(serverNow());
    useEffect(() => {
        if (!running) return undefined;
        const timer = setInterval(() => setNow(serverNow()), 250);
        return () => clearInterval(timer);
    }, [running]);
    return running ? now : serverNow();
}

const teamName = (team) => (team ? (team.name || 'Team ' + team.label) : '');
const buzz = () => navigator.vibrate?.(25);

/** The phase of a timed window: before it opens, open, or over. */
function windowPhase(round, now) {
    if (!round?.opens_ms || !round?.closes_ms) return 'idle';
    if (now < round.opens_ms) return 'ready';
    if (now < round.closes_ms) return 'open';
    return 'over';
}

function Seconds({ round, now }) {
    const phase = windowPhase(round, now);
    if (phase === 'ready') return html`<p class="se-play-count" data-phase="ready">${Math.ceil((round.opens_ms - now) / 1000)}</p>`;
    if (phase === 'open') return html`<p class="se-play-timer">${Math.ceil((round.closes_ms - now) / 1000)}s</p>`;
    if (phase === 'over') return html`<p class="se-play-timer" data-phase="over">Time's up</p>`;
    return null;
}

function Result({ kind, title, children }) {
    return html`
        <div class="se-play-result" data-kind=${kind}>
            <p class="se-play-result-title">${title}</p>
            ${children}
        </div>`;
}

// --------------------------------------------------------------------------
// Live Quiz and Trivia
// --------------------------------------------------------------------------

function QuizCard({ game, round, me, teamSnap, mine, isCaptain, myTeamId, onAnswer }) {
    const now = useNow(['armed', 'open'].includes(round.state));
    const phase = windowPhase(round, now);
    const trivia = game.type === 'trivia';
    const fromMe = me?.round?.id === round.id ? me.round.my_answer : null;
    const local = mine[round.id] || null;
    const myChoice = local?.choice ?? fromMe?.choice_index ?? null;
    const myRole = local?.role ?? fromMe?.role ?? null;
    const choices = round.choices || [];
    const suggestions = teamSnap?.suggestions?.round_id === round.id ? teamSnap.suggestions.counts || {} : {};
    const captainChoice = teamSnap?.captain_choice?.round_id === round.id ? teamSnap.captain_choice.choice_index : null;
    const captainName = teamSnap?.captain?.name || null;

    if (round.state === 'pending') {
        return html`<p class="se-play-wait">Question ${round.round_no} is coming up…</p>`;
    }

    if (['revealed', 'scored'].includes(round.state)) {
        const result = round.result || {};
        const correct = result.correct_index;
        const answerText = (LETTERS[correct] || '') + ' · ' + (choices[correct] ?? result.answer ?? '');
        if (trivia) {
            const teamChoice = result.team_choices?.[String(myTeamId)]?.choice ?? captainChoice;
            const points = round.team_points?.[String(myTeamId)];
            if (teamChoice === null || teamChoice === undefined) {
                return html`<${Result} kind="neutral" title="No answer from your team"><p>It was ${answerText}</p></${Result}>`;
            }
            return teamChoice === correct
                ? html`<${Result} kind="good" title="✓ Your team got it!">${points ? html`<p class="se-play-points">+${points}</p>` : null}</${Result}>`
                : html`<${Result} kind="bad" title="✕ Not this time"><p>It was ${answerText}</p></${Result}>`;
        }
        if (myChoice === null) {
            return html`<${Result} kind="neutral" title="The answer"><p>${answerText}</p></${Result}>`;
        }
        const points = fromMe?.points;
        return myChoice === correct
            ? html`<${Result} kind="good" title="✓ Correct!">${points ? html`<p class="se-play-points">+${points}</p>` : null}</${Result}>`
            : html`<${Result} kind="bad" title="✕ Not this time"><p>It was ${answerText}</p></${Result}>`;
    }

    if (phase === 'ready') {
        return html`
            <p class="se-label">Get ready</p>
            <${Seconds} round=${round} now=${now} />`;
    }

    const open = phase === 'open' && round.state !== 'locked';
    const lockedIn = trivia ? (isCaptain ? myRole === 'captain' || captainChoice !== null : false) : myChoice !== null;

    return html`
        <p class="se-play-prompt">${round.prompt}</p>
        <${Seconds} round=${round} now=${now} />
        ${trivia ? html`<p class="se-small se-muted">${isCaptain
            ? 'You are the captain: your tap locks in the team’s answer.'
            : captainChoice !== null ? 'Your captain has locked in ' + LETTERS[captainChoice] + '.'
            : 'Tap to suggest an answer' + (captainName ? ' to ' + captainName : '') + '.'}</p>` : null}
        <ol class="se-play-tiles">
            ${choices.map((choice, i) => {
                const chosen = myChoice === i || (trivia && captainChoice === i);
                const votes = suggestions[String(i)] || 0;
                return html`
                    <li key=${i}>
                        <button type="button" class="se-answer-tile" data-chosen=${chosen ? '1' : '0'}
                            disabled=${!open || lockedIn || (!trivia && myChoice !== null)}
                            onClick=${() => onAnswer(round, i, trivia && !isCaptain ? 'suggest' : 'answer')}>
                            <span class="se-answer-letter">${LETTERS[i]}</span>
                            <span>${choice}</span>
                            ${trivia && votes ? html`<span class="se-answer-votes">${votes}</span>` : null}
                        </button>
                    </li>`;
            })}
        </ol>
        ${lockedIn ? html`<p class="se-play-locked">Locked in ✓ — watch the stage.</p>`
            : trivia && myChoice !== null && !isCaptain ? html`<p class="se-play-locked">You suggested ${LETTERS[myChoice]}.</p>`
            : !open ? html`<p class="se-small se-muted">Answers are closed.</p>` : null}`;
}

// --------------------------------------------------------------------------
// Buzzer, Who Am I? and the Family Feud face-off
// --------------------------------------------------------------------------

function BuzzButton({ round, now, onBuzz, done }) {
    const phase = windowPhase(round, now);
    if (done) return html`<p class="se-play-locked">Buzzed! Wait for the host.</p>`;
    if (phase === 'ready') return html`<button type="button" class="se-buzz-button" disabled>Get ready…</button>`;
    if (phase === 'open' && ['armed', 'open'].includes(round.state)) {
        return html`<button type="button" class="se-buzz-button" onClick=${() => onBuzz(round)}>BUZZ</button>`;
    }
    return html`<p class="se-small se-muted">Buzzers are closed.</p>`;
}

function BuzzCard({ game, round, room, teams, myTeamId, buzzed, onBuzz }) {
    const now = useNow(['armed', 'open'].includes(round.state));
    const lockedOut = (round.locked_out || []).includes(myTeamId);
    const winner = room?.buzz_winner && room.buzz_winner.round_id === round.id ? room.buzz_winner : null;
    const done = !!buzzed[round.id + ':' + round.attempt];

    if (round.state === 'pending') {
        return html`<p class="se-play-wait">${game.type === 'who_am_i' ? 'Who am I? Get your buzzer finger ready…' : 'Next question coming up…'}</p>`;
    }

    if (['revealed', 'scored'].includes(round.state)) {
        const result = round.result || {};
        const won = result.winner_team_id ? teams[result.winner_team_id] : null;
        return html`
            <${Result} kind=${won && won.id === myTeamId ? 'good' : 'neutral'}
                title=${won ? (won.id === myTeamId ? '✓ Your team got it!' : teamName(won) + ' got it') : 'Nobody got it'}>
                <p>${result.answer}</p>
                ${won && round.team_points?.[String(won.id)] ? html`<p class="se-play-points">+${round.team_points[String(won.id)]}</p>` : null}
            </${Result}>`;
    }

    return html`
        ${game.type === 'who_am_i' ? html`
            <ol class="se-play-clues">
                ${(round.clues || []).map((clue, i) => html`<li key=${i} data-new=${i === (round.clues.length - 1) ? '1' : '0'}>${clue}</li>`)}
            </ol>` : html`<p class="se-play-prompt">${round.prompt}</p>`}
        ${winner && winner.attempt === round.attempt ? html`
            <p class="se-play-locked">${winner.team_id === myTeamId ? '🔔 Your team buzzed first — ' + winner.display_name + ' answers!' : '🔔 ' + winner.display_name + ' (' + teamName(teams[winner.team_id]) + ') buzzed first'}</p>`
            : null}
        ${lockedOut ? html`<p class="se-play-out">Your team is out for this ${game.type === 'who_am_i' ? 'clue' : 'question'}.</p>`
            : html`<${BuzzButton} round=${round} now=${now} onBuzz=${onBuzz} done=${done} />`}`;
}

// --------------------------------------------------------------------------
// Bible Charades
// --------------------------------------------------------------------------

function CharadesCard({ round, room, me, teams, myTeamId, isPresenter }) {
    const secret = isPresenter ? me?.presenter : null;
    const timed = secret?.opens_ms ? secret : round;
    const now = useNow(!!timed.opens_ms && round.state !== 'scored');
    const [hidden, setHidden] = useState(false);
    const acting = teams[round.team_id];
    const presenter = room?.presenter || null;

    if (round.state === 'scored') {
        return html`<${Result} kind=${round.team_id === myTeamId ? 'good' : 'neutral'}
            title=${teamName(acting) + ' got ' + (round.words_done || 0)}>
            ${round.team_points?.[String(round.team_id)] ? html`<p class="se-play-points">+${round.team_points[String(round.team_id)]}</p>` : null}
        </${Result}>`;
    }

    if (secret) {
        return html`
            <p class="se-label">Only you can see this — act it out!</p>
            ${secret.phrase ? html`
                <div class="se-charades-card" data-hidden=${hidden ? '1' : '0'}>
                    <p class="se-charades-secret">${hidden ? '• • •' : secret.phrase}</p>
                    ${!hidden && secret.category ? html`<p class="se-muted">${secret.category}</p>` : null}
                    ${!hidden && secret.hint ? html`<p class="se-small">${secret.hint}</p>` : null}
                </div>` : html`<p class="se-play-wait">No phrases left — tell the host.</p>`}
            <${Seconds} round=${timed} now=${now} />
            <button type="button" class="se-btn se-btn-ghost se-btn-wide" onClick=${() => setHidden(!hidden)}>
                ${hidden ? 'Show the phrase' : 'Hide the phrase'}</button>
            <p class="se-small se-muted">${secret.words_done || 0} guessed so far</p>`;
    }

    if (!round.team_id) {
        return html`<p class="se-play-wait">Charades: the host is choosing who acts next…</p>`;
    }

    return html`
        <p class="se-play-prompt">${round.team_id === myTeamId ? 'Your team is acting — shout your guesses!' : teamName(acting) + ' is acting'}</p>
        ${presenter ? html`<p class="se-muted">${presenter.display_name} is the actor</p>` : null}
        ${round.opens_ms ? html`<${Seconds} round=${round} now=${now} />` : html`<p class="se-small se-muted">Waiting for the clock…</p>`}
        <p class="se-play-score">${round.words_done || 0} guessed</p>`;
}

// --------------------------------------------------------------------------
// Family Feud
// --------------------------------------------------------------------------

function FeudCard({ round, room, me, teams, myTeamId, buzzed, onBuzz }) {
    const now = useNow(round.phase === 'faceoff');
    const faceoff = room?.faceoff && room.faceoff.round_id === round.id ? room.faceoff : null;
    const myName = me?.registration?.display_name;
    const myRep = faceoff ? [faceoff.rep_a, faceoff.rep_b].find((r) => r.team_id === myTeamId) : null;
    const iAmRep = myRep && myRep.display_name === myName;
    const done = !!buzzed[round.id + ':' + round.attempt];

    return html`
        <p class="se-play-prompt">${round.question}</p>
        <ol class="se-play-board">
            ${(round.board || []).map((slot) => html`
                <li key=${slot.slot} data-revealed=${slot.revealed ? '1' : '0'}>
                    <span>${slot.revealed ? slot.label : slot.slot}</span>
                    <strong>${slot.revealed ? slot.points : ''}</strong>
                </li>`)}
        </ol>
        ${round.phase === 'faceoff' ? (iAmRep
            ? html`<p class="se-label">You are in the face-off!</p><${BuzzButton} round=${round} now=${now} onBuzz=${onBuzz} done=${done} />`
            : html`<p class="se-muted">Face-off: ${faceoff ? faceoff.rep_a.display_name + ' vs ' + faceoff.rep_b.display_name : teamName(teams[round.team_a]) + ' vs ' + teamName(teams[round.team_b])}</p>`) : null}
        ${round.phase === 'control' ? html`
            <p class="se-muted">${teamName(teams[round.control_team])} is playing the board</p>
            <p class="se-play-strikes">${[0, 1, 2].map((i) => html`<span key=${i} data-on=${i < round.strikes ? '1' : '0'}>✕</span>`)}</p>` : null}
        ${round.phase === 'steal' ? html`<p class="se-play-locked">${teamName(teams[round.steal_team])} can steal!</p>` : null}
        ${round.phase === 'done' && round.winner_team ? html`
            <${Result} kind=${round.winner_team === myTeamId ? 'good' : 'neutral'} title=${teamName(teams[round.winner_team]) + ' banks the board'}>
                ${round.team_points?.[String(round.winner_team)] ? html`<p class="se-play-points">+${round.team_points[String(round.winner_team)]}</p>` : null}
            </${Result}>` : null}`;
}

// --------------------------------------------------------------------------
// Play ahead: the Family Feud survey (§11.10.1)
// --------------------------------------------------------------------------

function SurveyQuestion({ eventId, question, onSaved }) {
    const [text, setText] = useState(question.answer || '');
    const [state, setState] = useState(question.answer ? 'saved' : 'idle');
    const [error, setError] = useState(null);

    const save = async (e) => {
        e.preventDefault();
        if (!text.trim()) return;
        setState('saving');
        setError(null);
        try {
            await call('public', 'survey', { event: eventId, item_id: question.id, text: text.trim() });
            setState('saved');
            onSaved();
        } catch (err) {
            setState('idle');
            setError(err.message || 'That did not save. Try again.');
        }
    };

    return html`
        <form class="se-play-survey-q" onSubmit=${save}>
            <label class="se-play-survey-label" for=${'se-survey-' + question.id}>${question.question}</label>
            <div class="se-phone-row">
                <input class="se-input" id=${'se-survey-' + question.id} maxLength="60" value=${text} autocomplete="off"
                    onInput=${(e) => { setText(e.currentTarget.value); if (state === 'saved') setState('idle'); }} />
                <button type="submit" class="se-btn se-btn-primary" disabled=${state === 'saving' || !text.trim()}>
                    ${state === 'saved' ? 'Saved ✓' : state === 'saving' ? '…' : 'Save'}</button>
            </div>
            ${error ? html`<span class="se-error" role="alert">${error}</span>` : null}
        </form>`;
}

function Survey({ eventId, questions, onSaved }) {
    if (!questions?.length) return null;
    const done = questions.filter((q) => q.answer).length;
    return html`
        <section class="se-card se-play-card">
            <p class="se-label">Play ahead · Family Feud</p>
            <h2 class="se-h2">Quick survey</h2>
            <p class="se-small se-muted">Answer with the first thing that comes to mind. The most popular answers end up on the board tonight. ${done} of ${questions.length} done.</p>
            ${questions.map((q) => html`<${SurveyQuestion} key=${q.id} eventId=${eventId} question=${q} onSaved=${onSaved} />`)}
        </section>`;
}

// --------------------------------------------------------------------------
// The screen
// --------------------------------------------------------------------------

function Standings({ teams, myTeamId }) {
    const rows = [...teams].sort((a, b) => (b.score || 0) - (a.score || 0));
    if (!rows.some((t) => t.score)) return null;
    return html`
        <ol class="se-play-standings" aria-label="Team scores">
            ${rows.map((t, i) => html`
                <li key=${t.id} data-mine=${t.id === myTeamId ? '1' : '0'} style=${{ '--team-color': t.hex }}>
                    <span>${i + 1}. ${teamName(t)}</span><strong>${t.score || 0}</strong>
                </li>`)}
        </ol>`;
}

function Play({ config }) {
    const eventId = config.event.public_id;
    const [join, setJoin] = useState(null);
    const [snap, setSnap] = useState(null);
    const [room, setRoom] = useState(null);
    const [teamSnap, setTeamSnap] = useState(null);
    const [me, setMe] = useState(null);
    const [mine, setMine] = useState({});
    const [buzzed, setBuzzed] = useState({});
    const [notice, setNotice] = useState(null);
    const [net, setNet] = useState('ok');
    const lastPublic = useRef(null);
    const driver = useRef(null);

    const refreshMe = async () => {
        try {
            setMe(await call('public', 'me', { event: eventId, purpose: 'play' }));
        } catch (e) {
            // Keep what we had; the next trigger tries again.
        }
    };

    const say = (text, kind = 'error') => {
        setNotice({ text, kind });
        setTimeout(() => setNotice((n) => (n && n.text === text ? null : n)), 4000);
    };

    // Join (idempotent), then watch the snapshots.
    useEffect(() => {
        let stopped = false;
        (async () => {
            try {
                const out = await call('public', 'join_games', { event: eventId });
                if (stopped) return;
                setJoin(out);
                syncClock(3).then(() => keepClockSynced());
                refreshMe();

                const channels = {
                    public: (envelope) => { lastPublic.current = envelope; setSnap(envelope.data); },
                };
                if (out.room_key) channels['room-' + out.room_key] = (envelope) => setRoom(envelope.data);
                if (out.team_key) channels['team-' + out.team_key] = (envelope) => setTeamSnap(envelope.data);

                driver.current = new PollDriver({
                    base: config.urls?.live || '/live/' + eventId + '/',
                    channels,
                    pace: () => {
                        const pace = paceFor(lastPublic.current, { hidden: document.visibilityState !== 'visible', phase: 'live' });
                        return pace === 'off' ? 'live' : pace;
                    },
                    onStatus: ({ state }) => setNet(state),
                });
            } catch (e) {
                if (!stopped) setJoin({ error: e.message || 'Check in first to join the games.', code: e.code || null });
            }
        })();
        return () => { stopped = true; driver.current?.stop(); };
    }, []);

    const game = snap?.game || null;
    const round = game?.round || null;
    const myTeam = me?.team || null;
    const myTeamId = myTeam?.id ?? null;
    const teamList = snap?.teams || [];
    const teams = Object.fromEntries(teamList.map((t) => [t.id, t]));
    const isCaptain = !!(me?.games?.captain || (teamSnap?.captain?.registration_id_hash && teamSnap.captain.registration_id_hash === (me?.games?.me_hash || join?.me_hash)));
    const presenter = room?.presenter || null;
    const myNo = me?.registration?.player_no ?? null;
    const isPresenter = !!(presenter && round && game?.type === 'charades' && presenter.team_id === myTeamId
        && (myNo !== null ? presenter.player_no === myNo : presenter.display_name === me?.registration?.display_name));

    // Ask for `me` when something personal changes, spread over a second so
    // two hundred phones do not all arrive at once.
    const roundKey = round ? round.id + ':' + round.state : 'none';
    useEffect(() => {
        if (!round || !['scored', 'revealed'].includes(round.state)) return undefined;
        const t = setTimeout(refreshMe, 200 + Math.random() * 1200);
        return () => clearTimeout(t);
    }, [roundKey]);

    const presenterKey = isPresenter ? round.id + ':' + (round.phrase_seq ?? 0) + ':' + (round.opens_ms ? 1 : 0) : 'no';
    useEffect(() => { if (isPresenter) refreshMe(); }, [presenterKey]);

    useEffect(() => {
        const t = setInterval(() => { if (document.visibilityState === 'visible') refreshMe(); }, 60000);
        return () => clearInterval(t);
    }, []);

    const onAnswer = async (r, choice, action) => {
        navigator.vibrate?.(15);
        setMine((m) => ({ ...m, [r.id]: { choice, role: action === 'suggest' ? 'suggestion' : (game.type === 'trivia' ? 'captain' : 'player') } }));
        try {
            await call('public', action, {
                event: eventId, round_id: r.id, choice_index: choice,
                client_elapsed_ms: Math.max(0, Math.round(serverNow() - (r.opens_ms || serverNow()))),
            });
            driver.current?.poke();
        } catch (e) {
            if (e.code !== 'ALREADY_ANSWERED') {
                setMine((m) => { const next = { ...m }; delete next[r.id]; return next; });
            }
            say(e.message || 'That did not go through.');
        }
    };

    const onBuzz = async (r) => {
        buzz();
        setBuzzed((b) => ({ ...b, [r.id + ':' + r.attempt]: true }));
        try {
            await call('public', 'buzz', { event: eventId, round_id: r.id, attempt: r.attempt || 1, client_ms: Math.round(serverNow()) });
            driver.current?.poke();
        } catch (e) {
            if (e.code !== 'ALREADY_BUZZED') {
                setBuzzed((b) => { const next = { ...b }; delete next[r.id + ':' + r.attempt]; return next; });
            }
            say(e.message || 'Buzz not counted.');
        }
    };

    if (!join) {
        return html`<section class="se-card se-play-card"><p class="se-play-wait">Getting your game pass ready…</p></section>`;
    }

    if (join.error) {
        return html`
            <section class="se-card se-play-card">
                <h1 class="se-h1">Join the games</h1>
                <p>${join.error}</p>
                ${join.code === 'NOT_CHECKED_IN' && config.urls?.checkin
                    ? html`<a class="se-btn se-btn-primary se-btn-wide" href=${config.urls.checkin}>Check in</a>`
                    : html`<a class="se-btn se-btn-ghost se-btn-wide" href=${config.urls?.portal || '/'}>Back to the event page</a>`}
            </section>`;
    }

    const scene = snap?.scene?.scene || null;
    const finale = scene === 'finale' ? (snap?.scene?.payload || null) : null;
    const showRound = game && game.status === 'live' && round && round.state !== 'void';

    let body;
    if (finale && finale.champion) {
        const mine = finale.champion.id === myTeamId;
        body = html`
            <${Result} kind=${mine ? 'good' : 'neutral'} title=${mine ? '🏆 Your team are the champions!' : '🏆 ' + finale.champion.name + ' win!'}>
                <p class="se-play-points">${finale.champion.points} points</p>
            </${Result}>`;
    } else if (showRound && game.type === 'live_quiz' || showRound && game.type === 'trivia') {
        body = html`<${QuizCard} game=${game} round=${round} me=${me} teamSnap=${teamSnap} mine=${mine}
            isCaptain=${isCaptain} myTeamId=${myTeamId} onAnswer=${onAnswer} />`;
    } else if (showRound && (game.type === 'buzzer' || game.type === 'who_am_i')) {
        body = html`<${BuzzCard} game=${game} round=${round} room=${room} teams=${teams} myTeamId=${myTeamId} buzzed=${buzzed} onBuzz=${onBuzz} />`;
    } else if (showRound && game.type === 'charades') {
        body = html`<${CharadesCard} round=${round} room=${room} me=${me} teams=${teams} myTeamId=${myTeamId} isPresenter=${isPresenter} />`;
    } else if (showRound && game.type === 'feud') {
        body = html`<${FeudCard} round=${round} room=${room} me=${me} teams=${teams} myTeamId=${myTeamId} buzzed=${buzzed} onBuzz=${onBuzz} />`;
    } else if (game && game.status === 'live') {
        body = html`<p class="se-play-wait">${game.title} is about to start.</p><p class="se-muted">${HOW_TO[game.type] || ''}</p>`;
    } else {
        body = html`<p class="se-play-wait">Next game soon</p><p class="se-muted">Keep this page open — it changes by itself when the host starts a game.</p>`;
    }

    return html`
        <div class="se-play">
            <header class="se-play-head" style=${myTeam ? { '--team-color': myTeam.hex, '--team-on': myTeam.on } : null}>
                <strong>${myTeam ? teamName(myTeam) : 'The games'}</strong>
                <span>${myNo ? '#' + myNo : ''}${isCaptain ? ' · Captain' : ''}</span>
                <span>${me?.score?.points ? me.score.points + ' pts' : ''}</span>
            </header>
            ${snap?.event?.test_mode ? html`<p class="se-play-test">Rehearsal — scores do not count</p>` : null}
            ${net === 'reconnecting' ? html`<p class="se-play-net" role="status">Reconnecting…</p>` : null}

            <section class="se-card se-play-card" aria-live="polite">
                ${showRound ? html`<p class="se-label">${game.title}${round ? ' · ' + (game.type === 'feud' ? 'Board ' : game.type === 'charades' ? 'Turn ' : 'Question ') + round.round_no : ''}</p>` : null}
                ${body}
                ${notice ? html`<p class="se-play-notice" data-kind=${notice.kind} role="alert">${notice.text}</p>` : null}
            </section>

            <${Standings} teams=${teamList} myTeamId=${myTeamId} />
            ${!showRound ? html`<${Survey} eventId=${eventId} questions=${me?.games?.survey} onSaved=${refreshMe} />` : null}
        </div>`;
}

export async function startPlay(config) {
    const host = document.querySelector('#se-main');
    if (!host) return;

    const mount = document.createElement('div');
    mount.className = 'se-container se-play-wrap';
    host.replaceChildren(mount);
    render(html`<${Play} config=${config} />`, mount);
}
