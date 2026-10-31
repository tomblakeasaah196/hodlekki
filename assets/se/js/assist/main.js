// /assets/se/js/assist/main.js
//
// Volunteer check-in: "hand the phone" (guide §13.6, Volunteers).
//
// For guests without a smartphone. A volunteer opens the keyed link on their
// own phone, types the guest's number (any phone has one) and, for a walk-in,
// their name. Then they turn the phone round: the guest taps "Reveal my
// team" and gets exactly the reveal everybody else gets on their own phone —
// their colour, their verse card. Meanwhile the lobby screens play the
// contest for them like for anyone else. The phone comes back to the
// volunteer with one instruction: which colour wristband to hand over.
//
// Nothing here binds the volunteer's phone to a guest, so one volunteer can
// check in five people, or fifty.

import { call } from '@se/core/api.js';
import { boot } from '@se/core/store.js';
import { normalizePhone, displayPhone } from '@se/core/phone.js';
import { el } from '../portal/dom.js';
import { buildReveal, showReveal, closeReveal } from '../portal/reveal.js';

const config = boot.value || {};
const DONE_KEY = 'se:assist-done:' + (config.event?.public_id || '');

let info = {};
let root = null;
let state = {};
let toastTimer = null;

// --------------------------------------------------------------------------
// Building blocks
// --------------------------------------------------------------------------

function button(label, onClick, kind = 'primary', extra = {}) {
    return el('button', { type: 'button', class: 'se-btn se-btn-' + kind + ' se-btn-wide', onclick: onClick, ...extra }, label);
}

function show(...nodes) {
    root.replaceChildren(header(), ...nodes.filter(Boolean));
    const input = root.querySelector('input:not([type=checkbox])');
    if (input) input.focus();
}

function header() {
    return el('header', { class: 'se-assist-head' },
        el('p', { class: 'se-label' }, 'Volunteer check-in · ' + (info.title || config.event?.title || '')),
        el('p', { class: 'se-assist-stats' },
            el('strong', null, String(doneByMe())), ' checked in by you  ·  ',
            el('strong', null, String(info.checked_in ?? 0)), ' here tonight'));
}

function card(...children) {
    return el('div', { class: 'se-glass se-pad se-stack' }, ...children);
}

function flash(message) {
    clearTimeout(toastTimer);
    document.querySelector('.se-assist-toast')?.remove();
    const node = el('p', { class: 'se-assist-toast', role: 'alert' }, message);
    document.body.appendChild(node);
    toastTimer = setTimeout(() => node.remove(), 4200);
}

function busy(on) {
    root.setAttribute('aria-busy', on ? 'true' : 'false');
    for (const node of root.querySelectorAll('button')) node.disabled = on;
}

function doneByMe() {
    try { return Number(localStorage.getItem(DONE_KEY) || 0); } catch (e) { return 0; }
}

function countMine() {
    try { localStorage.setItem(DONE_KEY, String(doneByMe() + 1)); } catch (e) { /* private mode */ }
}

function explain(error) {
    const map = {
        CHECKIN_NOT_OPEN: 'Check-in is not open yet.',
        CHECKIN_CLOSED: 'Check-in has closed for today — please take them to the desk.',
        WALKIN_FULL: 'We are at capacity for walk-ins — please take them to the desk.',
        BLOCKED: 'Please take them to the desk — something needs sorting out first.',
        INVALID_PHONE: 'That number does not look right, e.g. 0803 123 4567.',
        RATE_LIMITED: 'Too many tries for that number. Wait a minute.',
        FORBIDDEN: 'This volunteer link is no longer valid. Ask for the new one.',
    };
    return map[error.code] || error.message || 'Something went wrong. Try again.';
}

function api(action, payload = {}) {
    return call('display', action, { event: config.event.public_id, key: config.display_key, ...payload });
}

// --------------------------------------------------------------------------
// 1. Their number
// --------------------------------------------------------------------------

function stepPhone() {
    state = {};
    const input = el('input', {
        type: 'tel', inputmode: 'tel', autocomplete: 'off', id: 'se-assist-phone',
        class: 'se-input', placeholder: '0803 123 4567',
    });

    const form = el('form', {
        class: 'se-stack',
        onsubmit: (event) => { event.preventDefault(); lookup(input.value); },
    },
        el('p', { class: 'se-h2' }, 'Who are we checking in?'),
        el('label', { class: 'se-label', for: 'se-assist-phone' }, "Guest's phone number"),
        input,
        el('p', { class: 'se-small se-muted' },
            'Any phone, even one without internet. If they registered, we will find them.'),
        el('button', { type: 'submit', class: 'se-btn se-btn-primary se-btn-wide' }, 'Find guest'));

    show(card(form));
}

async function lookup(raw) {
    const phone = normalizePhone(raw);
    if (!phone) { flash('That number does not look right, e.g. 0803 123 4567.'); return; }
    state.phone = phone.e164;

    busy(true);
    try {
        const found = await api('assist_lookup', { phone: state.phone });
        busy(false);
        state.lookup = found;

        if (found.kind === 'checked_in') {
            stepAlready(found);
        } else if (found.display_name && !(found.needs || []).length) {
            stepConfirm(found);
        } else {
            stepDetails(found);
        }
    } catch (error) {
        busy(false);
        flash(explain(error));
    }
}

// --------------------------------------------------------------------------
// 2. Confirm, or take their details
// --------------------------------------------------------------------------

function karaokeTick() {
    const box = el('input', { type: 'checkbox', id: 'se-assist-karaoke' });
    state.karaokeBox = box;
    return el('label', { class: 'se-check', for: 'se-assist-karaoke' },
        box, el('span', null, '🎤 They would like to sing karaoke'));
}

function stepConfirm(found) {
    show(card(
        el('p', { class: 'se-label' }, 'Registered'),
        el('p', { class: 'se-display-lg' }, found.display_name),
        el('p', { class: 'se-small se-muted' }, displayPhone(state.phone)),
        karaokeTick(),
        button('Yes — check them in', () => checkin({})),
        button('Not them', stepPhone, 'ghost')));
}

function stepAlready(found) {
    show(card(
        el('p', { class: 'se-label' }, 'Already checked in'),
        el('p', { class: 'se-display-lg' }, found.display_name || 'This guest'),
        el('p', { class: 'se-small se-muted' }, 'They are in. You can show them their welcome again.'),
        button('Show their welcome again', () => checkin({}), 'secondary'),
        button('Next guest', stepPhone, 'ghost')));
}

function stepDetails(found) {
    const needs = new Set(found.needs || ['first_name', 'last_name', 'gender']);
    const fields = {};
    const parts = [
        el('p', { class: 'se-label' }, found.display_name ? 'Almost there' : 'Walk-in'),
        el('p', { class: 'se-h2' }, found.display_name ? found.display_name : 'Not registered — no problem.'),
    ];

    const text = (name, label, value) => {
        const input = el('input', { type: 'text', class: 'se-input', id: 'se-assist-' + name, autocomplete: 'off', value: value || '' });
        fields[name] = () => input.value.trim();
        parts.push(el('label', { class: 'se-label', for: 'se-assist-' + name }, label), input);
    };

    if (needs.has('first_name') || !found.display_name) text('first_name', 'First name', found.first_name);
    if (needs.has('last_name') || !found.display_name) text('last_name', 'Last name', found.last_name);

    if (needs.has('gender') || !found.display_name) {
        let chosen = null;
        const chips = ['Male', 'Female'].map((value) => el('button', {
            type: 'button', class: 'se-choice', 'aria-pressed': 'false',
            onclick: (event) => {
                chosen = value;
                chips.forEach((chip) => chip.setAttribute('aria-pressed', 'false'));
                event.currentTarget.setAttribute('aria-pressed', 'true');
            },
        }, value));
        fields.gender = () => chosen;
        parts.push(el('p', { class: 'se-label' }, 'They are…'), el('div', { class: 'se-choice-row' }, ...chips));
    }

    parts.push(karaokeTick());

    if (info.consent_mode !== 'off' && info.consent_text) {
        const box = el('input', { type: 'checkbox', id: 'se-assist-consent' });
        fields.consent = () => box.checked;
        parts.push(el('label', { class: 'se-check', for: 'se-assist-consent' },
            box, el('span', { class: 'se-small' }, 'Read this to them: ' + info.consent_text)));
    }

    parts.push(button('Check them in', () => {
        const extra = {};
        for (const [name, read] of Object.entries(fields)) extra[name] = read();
        if (fields.first_name && !extra.first_name) { flash('We need at least a first name.'); return; }
        if (fields.gender && !extra.gender) { flash('Tap Male or Female — teams are balanced by it.'); return; }
        checkin(extra);
    }), button('Back', stepPhone, 'ghost'));

    show(card(...parts));
}

function stepGender(extra) {
    show(card(
        el('p', { class: 'se-label' }, 'One more tap'),
        el('p', { class: 'se-h2' }, 'They are…'),
        el('div', { class: 'se-choice-row' }, ...['Male', 'Female'].map((value) =>
            el('button', { type: 'button', class: 'se-choice', onclick: () => checkin({ ...extra, gender: value }) }, value)))));
}

// --------------------------------------------------------------------------
// 3. Check in, then hand the phone over
// --------------------------------------------------------------------------

async function checkin(extra) {
    busy(true);
    try {
        const data = await api('assist_checkin', {
            phone: state.phone,
            karaoke_interest: !!state.karaokeBox?.checked,
            ...extra,
        });
        busy(false);
        info.checked_in = data.checked_in ?? info.checked_in;
        if (data.outcome === 'checked_in') countMine();
        stepHandOver(data);
    } catch (error) {
        busy(false);
        if (error.code === 'NEEDS_GENDER') { stepGender(extra); return; }
        if (error.code === 'NEEDS_DETAILS') { stepDetails({ ...(state.lookup || {}), needs: error.data?.fields || ['first_name'] }); return; }
        flash(explain(error));
    }
}

/** "Turn the phone round": the guest taps to see their own team. */
function stepHandOver(data) {
    const first = data.first_name || String(data.display_name || '').split(' ')[0];

    const panel = el('div', { class: 'se-assist-handover' },
        el('p', { class: 'se-label' }, data.outcome === 'checked_in' ? 'Checked in ✓' : 'Already checked in'),
        el('p', { class: 'se-assist-handover-title' }, 'Turn the phone to ' + first),
        el('p', { class: 'se-small se-muted' }, 'Let them tap the button. Their name is on the big screens now.'),
        button('✨ ' + first + ', reveal your team', () => reveal(data), 'primary', { class: 'se-btn se-btn-primary se-btn-wide se-assist-reveal-btn' }));

    show(panel);
}

function reveal(data) {
    const actions = [
        button('Done — give the phone back', () => { closeReveal(); stepWristband(data); }, 'secondary'),
    ];

    // Without a smartphone they cannot open the karaoke link themselves, so
    // the volunteer does it with them, on this phone.
    const shown = { ...data, karaoke_url: null };
    if (data.karaoke_url && data.karaoke_interest) {
        actions.unshift(el('a', {
            class: 'se-btn se-btn-wide se-reveal-cta', href: data.karaoke_url, target: '_blank', rel: 'noopener',
        }, '🎤  Pick a song together'));
    }

    showReveal(buildReveal(shown, { config, mode: 'assist', actions }), { celebrate: true });
}

/** Back to the volunteer: which wristband, then the next guest. */
function stepWristband(data) {
    const team = data.team;
    const first = data.first_name || String(data.display_name || '').split(' ')[0];

    const block = el('div', { class: 'se-assist-band' + (team?.ring ? ' se-assist-band-ring' : '') },
        el('p', { class: 'se-assist-band-pre' }, 'Give ' + first + ' a'),
        el('p', { class: 'se-assist-band-colour' }, team ? (team.label || team.name) : 'welcome'),
        el('p', { class: 'se-assist-band-post' }, 'wristband'));
    if (team) {
        block.style.setProperty('--team-color', team.hex);
        block.style.setProperty('--team-on', team.on || '#fff');
    }

    show(block,
        button('✓ Wristband given — next guest', stepPhone),
        data.karaoke_interest && data.karaoke_url
            ? el('p', { class: 'se-small se-muted se-assist-foot' }, first + ' wants to sing. If you did not pick a song together, tell the karaoke team.')
            : null);
}

// --------------------------------------------------------------------------

async function start() {
    const host = document.getElementById('se-app');
    const fail = (message) => document.getElementById('se-crew-frame')?.replaceChildren(
        el('div', { class: 'se-container' }, el('p', { class: 'se-glass se-pad' }, message)));

    if (!config.event?.public_id) return;
    if (!config.display_key) {
        fail('This page needs the volunteer link from Studio → Live → Screen links (the one ending in ?k=…).');
        return;
    }

    try {
        info = await api('assist_boot');
    } catch (error) {
        fail(explain(error));
        return;
    }

    document.getElementById('se-crew-frame')?.remove();
    host.hidden = false;
    host.className = 'se-assist se-container';
    root = el('main', { class: 'se-stack', id: 'se-assist' });
    host.replaceChildren(root);

    if (info.window && info.window.open === false) {
        waitForDoors();
        return;
    }

    stepPhone();
}

/** Opened before the doors: check again every 15 seconds and open by itself. */
function waitForDoors() {
    const recheck = async () => {
        try {
            info = await api('assist_boot');
        } catch (error) {
            return;
        }
        if (!info.window || info.window.open !== false) {
            clearInterval(timer);
            stepPhone();
        }
    };
    const timer = setInterval(recheck, 15000);

    show(card(el('p', { class: 'se-h2' }, 'Check-in is not open yet.'),
        el('p', { class: 'se-small se-muted' }, 'This page opens by itself the moment the doors open. Keep it handy.'),
        button('Check again now', recheck, 'secondary')));
}

start();
