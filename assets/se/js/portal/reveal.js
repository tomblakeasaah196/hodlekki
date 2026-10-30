// /assets/se/js/portal/reveal.js
//
// The moment after "Yes, check me in" (guide §13.6 item 4).
//
// One full-screen page in the guest's team colour, painted over the event's
// own artwork: their name, their team, and their welcome verse as a card
// signed "Chara by Envision". Then the one next step that matters tonight —
// for someone who signed up to sing, "Pick your karaoke song here", which
// opens the karaoke platform.
//
// Shared by the guest's own phone (portal/checkin.js) and the volunteer
// hand-the-phone flow (assist/main.js), so a guest without a smartphone sees
// exactly what everybody else sees.

import { motionEnabled, haptic, HAPTIC } from '@se/core/motion.js';
import { prefersReducedMotion } from '@se/core/boot.js';
import { burst } from '@se/core/confetti.js';
import { el } from './dom.js';

/**
 * Build the reveal.
 *
 * @param {object} data     the check-in payload (team, verse, first_name, …)
 * @param {object} options
 * @param {object} options.config     the page boot payload
 * @param {'self'|'assist'} [options.mode]
 * @param {Array<Node>} [options.actions]  extra buttons under the card
 * @returns {HTMLElement}
 */
export function buildReveal(data, { config = {}, mode = 'self', actions = [] } = {}) {
    const team = data.team;
    const first = data.first_name || String(data.display_name || '').split(' ')[0] || 'friend';
    const title = config.event?.title || '';

    const page = el('section', {
        class: 'se-reveal',
        role: 'dialog',
        'aria-modal': 'true',
        'aria-labelledby': 'se-reveal-hello',
        'data-mode': mode,
    });
    if (team) {
        page.style.setProperty('--team-color', team.hex);
        page.style.setProperty('--team-on', team.on || '#fff');
    }
    if (config.hero) page.style.setProperty('--hero', 'url("' + encodeURI(config.hero) + '")');

    const eyebrow = data.returning
        ? (title ? title + ' · welcome back' : 'Welcome back')
        : data.already
            ? (data.for === 'other' ? 'Already checked in' : 'You are already in')
            : (title ? title + ' · you’re in' : 'You’re in');

    const inner = el('div', { class: 'se-reveal-inner' },
        el('p', { class: 'se-reveal-eyebrow' }, eyebrow),
        el('h1', { class: 'se-reveal-hello', id: 'se-reveal-hello' },
            el('span', { class: 'se-reveal-hello-pre' }, 'Welcome,'),
            el('span', { class: 'se-reveal-hello-name' }, first)));

    if (team) {
        inner.appendChild(el('div', { class: 'se-reveal-team' + (team.ring ? ' se-reveal-team-ring' : '') },
            el('span', { class: 'se-reveal-team-label' }, 'You are on'),
            el('span', { class: 'se-reveal-team-name' }, team.name || ('Team ' + team.label))));
    }

    if (data.games && data.player_no) {
        inner.appendChild(el('p', { class: 'se-reveal-player' }, 'Player #' + data.player_no));
    }

    if (data.verse) inner.appendChild(verseCard(data, first));

    const buttons = el('div', { class: 'se-reveal-actions' });

    // Karaoke on another platform: a singer is sent straight there; anybody
    // else still gets a quieter way in.
    if (data.karaoke_url) {
        const singer = !!data.karaoke_interest;
        buttons.appendChild(el('a', {
            class: 'se-btn se-btn-wide ' + (singer ? 'se-reveal-cta' : 'se-btn-ghost'),
            href: data.karaoke_url,
            target: '_blank',
            rel: 'noopener',
        }, singer ? '🎤  Pick your karaoke song here' : 'Feel like singing? Pick a song'));
    }

    for (const action of actions) if (action) buttons.appendChild(action);
    if (buttons.childNodes.length) inner.appendChild(buttons);

    page.append(el('div', { class: 'se-reveal-bg', 'aria-hidden': 'true' }), inner);
    return page;
}

/** The welcome verse as a card worth keeping. */
function verseCard(data, first) {
    const verse = data.verse;
    const card = el('article', { class: 'se-verse-card' },
        el('p', { class: 'se-verse-card-eyebrow' }, 'A word for you tonight'),
        el('blockquote', { class: 'se-verse-card-text' }, '“' + verse.text + '”'),
        el('p', { class: 'se-verse-card-ref' }, verse.ref));

    if (verse.prayer) {
        card.appendChild(el('p', { class: 'se-verse-card-prayer' }, verse.prayer));
    } else {
        card.appendChild(el('p', { class: 'se-verse-card-prayer' }, first + ', welcome home.'));
    }

    // "Chara by Envision": the event in the signature hand, the organiser
    // smaller beside it.
    const sig = String(data.card_signature || '');
    const at = sig.lastIndexOf(' by ');
    card.appendChild(at > 0
        ? el('p', { class: 'se-verse-card-sign' },
            el('span', null, sig.slice(0, at)),
            el('span', { class: 'se-verse-card-sign-by' }, ' by ' + sig.slice(at + 4)))
        : el('p', { class: 'se-verse-card-sign' }, sig));

    return card;
}

/**
 * Put the reveal on screen and celebrate (instantly — the suspense belongs to
 * the lobby screens; the phone just tells them).
 */
export function showReveal(page, { celebrate = true } = {}) {
    document.querySelector('.se-reveal')?.remove();
    document.body.appendChild(page);
    document.body.classList.add('se-reveal-open');
    page.scrollTop = 0;
    page.querySelector('.se-reveal-cta, a, button')?.focus({ preventScroll: true });

    if (!celebrate) return;
    haptic(HAPTIC.teamReveal);
    if (prefersReducedMotion()) return;

    page.classList.add('se-reveal-enter');
    const hex = getComputedStyle(page).getPropertyValue('--team-color').trim();
    if (hex) {
        setTimeout(() => {
            burst({ x: window.innerWidth / 2, y: window.innerHeight * 0.32, colors: [hex, hex, '#ffffff', '#ffd76a'], count: 140, spread: 80 });
        }, motionEnabled() ? 280 : 120);
    }
}

export function closeReveal() {
    document.querySelector('.se-reveal')?.remove();
    document.body.classList.remove('se-reveal-open');
}
