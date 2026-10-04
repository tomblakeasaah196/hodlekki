// /assets/se/js/portal/play.js
// Phone-first controller for quiz, party games, surveys and the finale.
import { call } from '@se/core/api.js';
import { serverNow } from '@se/core/clock.js';

const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text != null) node.textContent = text;
    return node;
};

function button(label, onClick, className = 'se-btn se-btn-primary se-btn-wide') {
    const node = el('button', className, label);
    node.type = 'button';
    node.addEventListener('click', onClick);
    return node;
}

export async function startPlay(config) {
    const host = document.querySelector('#se-main');
    if (!host) return;
    host.replaceChildren(el('section', 'se-card', 'Getting your game pass ready…'));

    let joined;
    try {
        joined = await call('public', 'join_games', { event: config.event.public_id });
    } catch (error) {
        const card = el('section', 'se-card');
        card.append(el('h1', '', 'Join the games'), el('p', '', error.message || 'Check in first.'));
        host.replaceChildren(card);
        return;
    }

    let publicState = null;
    let me = null;
    let timer = null;

    async function refresh() {
        try {
            const [snapshot, mine] = await Promise.all([
                fetch(`/live/${config.event.public_id}/public.json`, { cache: 'no-cache', credentials: 'omit' }).then((r) => r.ok ? r.json() : null),
                call('public', 'me', { event: config.event.public_id }),
            ]);
            if (snapshot) publicState = snapshot.data;
            me = mine;
            paint();
        } catch (error) {
            // Keep the last good controller visible. The next poll retries.
        }
    }

    function paintSurvey(card) {
        const questions = me?.games?.survey || [];
        if (!questions.length) return false;
        card.appendChild(el('p', 'se-label', 'Play ahead · Family Feud'));
        card.appendChild(el('h1', 'se-h1', 'Quick survey'));
        for (const question of questions) {
            const block = el('div', 'se-stack');
            block.appendChild(el('label', 'se-label', question.question));
            const input = el('input', 'se-input');
            input.maxLength = 60;
            input.value = question.answer || '';
            const save = button(question.answer ? 'Update answer' : 'Save answer', async () => {
                save.disabled = true;
                try {
                    await call('public', 'survey', { event: config.event.public_id, item_id: question.id, text: input.value });
                    save.textContent = 'Saved ✓';
                    await refresh();
                } catch (error) {
                    save.textContent = error.message || 'Try again';
                    save.disabled = false;
                }
            }, 'se-btn se-btn-secondary');
            block.append(input, save);
            card.appendChild(block);
        }
        return true;
    }

    function paint() {
        const card = el('section', 'se-card se-stack');
        const round = publicState?.game?.round || null;
        const game = publicState?.game || null;
        const presenter = me?.presenter || null;
        const team = me?.team;

        const header = el('div', 'se-game-phone-header');
        header.append(
            el('strong', '', team?.name || (team?.label ? `Team ${team.label}` : 'Games')),
            el('span', '', me?.registration?.player_no ? `#${me.registration.player_no}` : ''),
            el('span', '', `${me?.score?.points || 0} pts`),
        );
        card.appendChild(header);

        if (presenter) {
            card.append(el('p', 'se-label', 'Only you can see this'), el('h1', 'se-charades-secret', presenter.phrase));
            if (presenter.category) card.appendChild(el('p', 'se-muted', presenter.category));
            if (presenter.hint) card.appendChild(el('p', '', presenter.hint));
            const secret = card.querySelector('.se-charades-secret');
            card.appendChild(button('Hide / show phrase', () => { secret.hidden = !secret.hidden; }, 'se-btn se-btn-secondary'));
            if (presenter.closes_ms) card.appendChild(el('p', 'se-scene-counter', `${Math.max(0, Math.ceil((presenter.closes_ms - serverNow()) / 1000))}s`));
        } else if (!game || !round) {
            card.append(el('h1', 'se-h1', 'Next game soon'), el('p', 'se-muted', 'Watch the stage. Your controller will change automatically.'));
            paintSurvey(card);
        } else if (game.type === 'charades') {
            card.append(el('p', 'se-label', game.title), el('h1', 'se-h1', publicState?.room?.presenter ? `${publicState.room.presenter} is acting` : 'Guess out loud!'));
            card.appendChild(el('p', 'se-scene-counter', `${round.words_done || 0} guessed`));
        } else if (game.type === 'feud') {
            card.append(el('p', 'se-label', 'Family Feud'), el('h1', 'se-h1', round.question || 'Survey says…'));
            card.appendChild(button('BUZZ', () => buzz(round), 'se-buzz-button'));
        } else if (game.type === 'buzzer' || game.type === 'who_am_i') {
            card.append(el('p', 'se-label', game.title));
            for (const clue of round.clues || []) card.appendChild(el('h2', 'se-h2', clue));
            if (round.prompt) card.appendChild(el('h1', 'se-h1', round.prompt));
            card.appendChild(button('BUZZ', () => buzz(round), 'se-buzz-button'));
        } else {
            card.append(el('p', 'se-label', game.title), el('h1', 'se-h1', round.prompt || round.lead || 'Get ready'));
            if (me?.round?.my_answer) {
                card.appendChild(el('p', 'se-success', 'Locked in ✓'));
            } else {
                (round.choices || []).forEach((choice, index) => {
                    card.appendChild(button(`${String.fromCharCode(65 + index)} · ${choice}`, async () => {
                        await call('public', game.type === 'trivia' && !me?.games?.captain ? 'suggest' : 'answer', {
                            event: config.event.public_id, round_id: round.id, choice_index: index,
                            client_elapsed_ms: Math.max(0, serverNow() - (round.opens_ms || serverNow())),
                        });
                        navigator.vibrate?.(15);
                        refresh();
                    }, 'se-answer-tile'));
                });
            }
        }
        host.replaceChildren(card);
    }

    async function buzz(round) {
        try {
            await call('public', 'buzz', { event: config.event.public_id, round_id: round.id, attempt: round.attempt || 1, client_ms: serverNow() });
            navigator.vibrate?.(25);
            await refresh();
        } catch (error) {
            const message = el('p', 'se-error', error.message || 'Buzz closed.');
            host.querySelector('.se-card')?.appendChild(message);
        }
    }

    await refresh();
    timer = setInterval(() => { if (document.visibilityState === 'visible') refresh(); }, 1000);
    addEventListener('pagehide', () => clearInterval(timer), { once: true });
}
