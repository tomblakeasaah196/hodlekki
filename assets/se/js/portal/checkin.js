// /assets/se/js/portal/checkin.js
//
// Check-in on the guest's own phone (guide §13.6).
//
// This is the busiest ten seconds of the night: somebody is standing in a
// doorway, often on one bar of signal, with people behind them. So the
// screen only ever asks for one thing at a time, every step is a single big
// tap, and the happy path for a registered guest is: type number → "Is this
// you?" → team reveal.
//
// Loaded on demand by portal/main.js, so the portal's own critical path
// never carries it.

import { call, SeApiError } from '@se/core/api.js';
import { toast } from '@se/core/store.js';
import { motionEnabled, popIn, haptic, HAPTIC } from '@se/core/motion.js';
import { normalizePhone, displayPhone } from '@se/core/phone.js';
import { el, qs } from './dom.js';

let config = {};
let root = null;
let state = {};

// --------------------------------------------------------------------------
// Small building blocks
// --------------------------------------------------------------------------

function card(...children) {
    return el('div', { class: 'se-glass se-pad se-stack' }, ...children);
}

function button(label, onClick, kind = 'primary') {
    const node = el('button', {
        type: 'button',
        class: 'se-btn se-btn-' + kind + ' se-btn-wide',
        onclick: onClick,
    }, label);
    return node;
}

function show(...nodes) {
    root.replaceChildren(...nodes);
    const focusable = qs('input, button', root);
    if (focusable && focusable.tagName === 'INPUT') focusable.focus();
}

function busy(on) {
    root.setAttribute('aria-busy', on ? 'true' : 'false');
    for (const node of root.querySelectorAll('button')) node.disabled = on;
}

/** Turn an API error into something a person in a doorway can act on. */
function explain(error) {
    const map = {
        CHECKIN_NOT_OPEN: 'Check-in is not open yet. Hang on a moment.',
        CHECKIN_CLOSED: 'Check-in has closed — please see the desk.',
        WALKIN_FULL: "We're at capacity right now — please see the desk.",
        BLOCKED: 'Please see the desk — we need to sort something out first.',
        INVALID_PHONE: 'Please enter a mobile number, e.g. 0803 123 4567.',
        RATE_LIMITED: 'That is a lot of tries. Wait a minute, then try again.',
        FEATURE_NOT_READY: 'Check-in is not switched on for this event yet.',
    };
    return map[error.code] || error.message || 'Something went wrong. Please try again.';
}

// --------------------------------------------------------------------------
// Step 1 — phone (§13.6 splash)
// --------------------------------------------------------------------------

function stepPhone(prefill = '') {
    const input = el('input', {
        type: 'tel',
        inputmode: 'tel',
        autocomplete: 'tel',
        id: 'se-checkin-phone',
        class: 'se-input',
        placeholder: '0803 123 4567',
        value: prefill,
        'aria-describedby': 'se-checkin-phone-hint',
    });

    const form = el('form', {
        class: 'se-stack',
        onsubmit: (event) => { event.preventDefault(); submitPhone(input.value); },
    },
        el('label', { class: 'se-label', for: 'se-checkin-phone' }, 'Your phone number'),
        input,
        el('p', { class: 'se-small se-muted', id: 'se-checkin-phone-hint' },
            "The same number you registered with. New here? We'll sign you in at the door."),
        el('button', { type: 'submit', class: 'se-btn se-btn-primary se-btn-wide' }, 'Continue'));

    show(card(form));
}

async function submitPhone(raw) {
    const phone = normalizePhone(raw);
    if (!phone) {
        toast('Please enter a mobile number, e.g. 0803 123 4567.', 'error');
        return;
    }

    state.phone = phone;
    busy(true);

    try {
        const data = await call('public', 'lookup', {
            event: config.event.public_id,
            purpose: 'checkin',
            phone,
        });
        state.lookup = data;
        stepConfirm(data);
    } catch (error) {
        toast(explain(error), 'error');
        busy(false);
    }
}

// --------------------------------------------------------------------------
// Step 2 — confirm / details / gender
// --------------------------------------------------------------------------

function stepConfirm(lookup) {
    // Somebody we know: one tap and they are in.
    if (lookup.display_name && !(lookup.needs || []).length) {
        show(card(
            el('p', { class: 'se-label' }, 'Is this you?'),
            el('p', { class: 'se-display-lg' }, lookup.display_name),
            el('p', { class: 'se-small se-muted' }, displayPhone(state.phone)),
            button('Yes, check me in', () => doCheckin({})),
            button('Not me', () => stepPhone(''), 'ghost')));
        return;
    }

    stepDetails(lookup);
}

function stepDetails(lookup) {
    const needs = new Set(lookup.needs || []);
    const fields = {};

    const children = [
        el('p', { class: 'se-label' }, 'Welcome!'),
        el('p', { class: 'se-h2' }, 'Just a couple of details and you are in.'),
    ];

    const text = (name, label, autocomplete) => {
        const input = el('input', {
            type: 'text', class: 'se-input', id: 'se-ci-' + name,
            autocomplete, value: lookup[name] || '',
        });
        fields[name] = () => input.value;
        children.push(el('label', { class: 'se-label', for: 'se-ci-' + name }, label), input);
    };

    if (needs.has('first_name')) text('first_name', 'First name', 'given-name');
    if (needs.has('last_name')) text('last_name', 'Last name', 'family-name');

    if (needs.has('gender')) {
        let chosen = null;
        const chips = ['Male', 'Female'].map((value) => el('button', {
            type: 'button',
            class: 'se-choice',
            'aria-pressed': 'false',
            onclick: (event) => {
                chosen = value;
                for (const chip of chips) chip.setAttribute('aria-pressed', 'false');
                event.currentTarget.setAttribute('aria-pressed', 'true');
            },
        }, value));
        fields.gender = () => chosen;
        children.push(el('p', { class: 'se-label' }, 'You are…'), el('div', { class: 'se-choice-row' }, ...chips));
    }

    if (needs.has('consent')) {
        const box = el('input', { type: 'checkbox', id: 'se-ci-consent' });
        fields.consent = () => box.checked;
        children.push(el('label', { class: 'se-check', for: 'se-ci-consent' },
            box, el('span', { class: 'se-small' }, config.texts?.consent || 'Keep me posted about what is next.')));
    }

    children.push(button('Check me in', () => {
        const payload = {};
        for (const [name, read] of Object.entries(fields)) payload[name] = read();
        doCheckin(payload);
    }));

    show(card(...children));
}

/** NEEDS_GENDER after the fact: one tap, nothing else on screen. */
function stepGender(options) {
    show(card(
        el('p', { class: 'se-label' }, 'One more tap'),
        el('p', { class: 'se-h2' }, 'You are…'),
        el('div', { class: 'se-choice-row' }, ...(options || ['Male', 'Female']).map((value) =>
            el('button', {
                type: 'button', class: 'se-choice',
                onclick: () => doCheckin({ ...state.extra, gender: value }),
            }, value)))));
}

// --------------------------------------------------------------------------
// The call itself
// --------------------------------------------------------------------------

async function doCheckin(extra) {
    state.extra = { ...state.extra, ...extra };
    busy(true);

    try {
        const data = await call('public', 'checkin', {
            event: config.event.public_id,
            phone: state.phone,
            confirm: true,
            ...state.extra,
        });
        stepReveal(data);
    } catch (error) {
        busy(false);

        if (error.code === 'NEEDS_GENDER') {
            stepGender(error.data?.options);
            return;
        }
        if (error.code === 'NEEDS_DETAILS') {
            stepDetails({ ...(state.lookup || {}), needs: error.data?.fields || ['first_name'] });
            return;
        }
        toast(explain(error), 'error');
    }
}

// --------------------------------------------------------------------------
// Step 4 — the reveal (§13.6 item 4)
// --------------------------------------------------------------------------

function stepReveal(data) {
    busy(false);

    const team = data.team;
    const nodes = [];

    if (data.already_elsewhere) {
        nodes.push(el('p', { class: 'se-glass se-pad se-small' },
            "You're already checked in on another phone. To play here, ask the desk for a transfer code."));
    } else if (data.for === 'other') {
        nodes.push(el('p', { class: 'se-label' }, 'Already checked in'));
    }

    nodes.push(el('p', { class: 'se-label' }, data.already ? 'You are already in' : "You're on"));
    nodes.push(el('p', { class: 'se-display-lg' }, data.display_name));

    if (team) {
        const badge = el('span', {
            class: 'se-team-badge' + (team.ring ? ' se-team-badge-ring' : ''),
            'data-team-hex': team.hex,
        }, team.name || ('Team ' + team.label));
        // The team colour is a per-render value, so it rides on a CSS
        // variable in the style property rather than an inline declaration
        // in server HTML (§19.7 allows neither style attributes server-side).
        badge.style.setProperty('--team-color', team.hex);
        badge.style.setProperty('--team-on', team.on);
        nodes.push(el('p', { class: 'se-h1' }, badge));
    }

    if (data.player_no) {
        nodes.push(el('p', { class: 'se-player-no' }, '#' + data.player_no));
    }

    if (data.verse) {
        const verse = el('blockquote', { class: 'se-verse se-stack' },
            el('p', { class: 'se-h2' }, data.verse.text),
            el('cite', { class: 'se-small se-muted' }, data.verse.ref));
        if (data.verse.prayer) {
            verse.appendChild(el('p', { class: 'se-script' }, data.verse.prayer));
        }
        nodes.push(verse);
        nodes.push(el('p', { class: 'se-script se-small se-muted' }, data.card_signature || ''));
    }

    const actions = el('div', { class: 'se-stack' });
    if (data.verse) {
        actions.appendChild(button('Save your welcome card', () => openCard('welcome'), 'secondary'));
    }
    if (team) {
        actions.appendChild(button('Make your team card', () => openCard('team'), 'ghost'));
    }
    actions.appendChild(el('a', {
        class: 'se-btn se-btn-ghost se-btn-wide',
        href: config.urls?.play || '#',
    }, 'Join the games'));
    nodes.push(actions);

    const panel = card(...nodes);
    show(panel);

    if (!data.already) {
        haptic(HAPTIC.teamReveal);
        if (motionEnabled()) {
            popIn(panel);
            if (team) wipe(team.hex);
        }
    }
}

/** A circle wipe in the team colour, expanding from the middle of the page. */
function wipe(hex) {
    const layer = el('div', { class: 'se-team-wipe', 'aria-hidden': 'true' });
    layer.style.setProperty('--team-color', hex);
    document.body.appendChild(layer);
    setTimeout(() => layer.remove(), 1200);
}

async function openCard(kind) {
    try {
        const { openCardBuilder } = await import('./card.js');
        await openCardBuilder(kind);
    } catch (e) {
        toast('The card builder could not load. Your check-in is safe.', 'error');
    }
}

// --------------------------------------------------------------------------
// The countdown shown before the doors open
// --------------------------------------------------------------------------

function startCountdown(node) {
    const opensAt = Date.parse(node.dataset.opensAt || '');
    if (!Number.isFinite(opensAt)) return;

    const tick = () => {
        const left = opensAt - Date.now();
        if (left <= 0) {
            node.textContent = 'Doors are open — refresh to check in.';
            clearInterval(timer);
            return;
        }
        const total = Math.floor(left / 1000);
        const h = Math.floor(total / 3600);
        const m = Math.floor((total % 3600) / 60);
        const s = total % 60;
        node.textContent = 'Opens in '
            + (h ? h + 'h ' : '')
            + (h || m ? m + 'm ' : '')
            + s + 's';
    };

    const timer = setInterval(tick, 1000);
    tick();
}

// --------------------------------------------------------------------------

export function startCheckin(bootConfig) {
    config = bootConfig || {};
    root = qs('#se-checkin');
    if (!root) return;

    const countdown = qs('#se-checkin-countdown');
    if (countdown) startCountdown(countdown);

    if (config.flags?.checkin_ready === false) {
        show(card(el('p', { class: 'se-small' },
            'Check-in for this event is not switched on yet. Come to the desk and we will sign you in.')));
        return;
    }

    if (root.dataset.open !== '1') return;

    // A device we already know can skip straight to "Is this you?".
    const known = config.me?.registration?.display_name;
    if (known) {
        show(card(
            el('p', { class: 'se-label' }, 'Is this you?'),
            el('p', { class: 'se-display-lg' }, known),
            button('Check in as ' + known, () => {
                state.phone = null;
                checkinKnown();
            }),
            button('Someone else', () => stepPhone(''), 'ghost')));
        return;
    }

    stepPhone('');
}

/**
 * The device already owns a registration, so we have no phone number in
 * hand — ask for it once rather than guessing, because the person holding
 * the phone may be checking in their friend.
 */
function checkinKnown() {
    stepPhone('');
}
