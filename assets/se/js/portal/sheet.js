// /assets/se/js/portal/sheet.js
//
// The registration sheet (guide §13.4) and the success screen.
//
// Three steps, phone first: the number IS the identity (§10.3), so step 1
// asks for it alone, step 2 shows what we already know, and step 3 is the
// confirmation that turns into a ticket. Copy comes from Appendix F.

import { call, SeApiError } from '@se/core/api.js';
import { boot, me, toast } from '@se/core/store.js';
import { popIn, haptic, HAPTIC } from '@se/core/motion.js';
import { prefersReducedMotion } from '@se/core/boot.js';
import { normalizePhone, displayPhone } from '@se/core/phone.js';
import { el, qs, openSheet, dots, field, decorate, copyText } from './dom.js';
import { shouldAskEmail } from './registration-fields.js';
import { saveLocal } from './storage.js';

const GENDERS = [['Male', 'Male'], ['Female', 'Female']];

/** Everything typed so far. Kept outside the DOM so a step can be re-rendered. */
function newDraft() {
    return {
        phone: '',
        first_name: '',
        last_name: '',
        gender: null,
        email: '',
        how_heard: '',
        how_heard_other: '',
        karaoke_interest: false,
        consent: false,
        answers: {},
        lookup: null,
        openedAt: Date.now(),
    };
}

function touched(draft) {
    return draft.first_name !== '' || draft.last_name !== '' || draft.email !== ''
        || draft.gender !== null || draft.how_heard !== '';
}

function eventRef() {
    const config = boot.value || {};

    return { event: config.event?.public_id, slug: config.event?.slug };
}

/** Fire-and-forget counter (§18.1). Never blocks and never shows an error. */
export function beacon(metric, dimension) {
    try {
        call('public', 'beacon', { ...eventRef(), m: metric, d: dimension || '' }).catch(() => {});
    } catch (e) { /* counters are never worth an error */ }
}

// --------------------------------------------------------------------------
// Entry point
// --------------------------------------------------------------------------

export function openRegistration() {
    const config = boot.value || {};
    const form = config.form || {};
    const draft = newDraft();

    const sheet = openSheet({
        label: 'Register for ' + (config.event?.title || 'this event'),
        onBeforeClose: () => {
            if (!touched(draft)) return true;
            return confirm('Leave registration? What you have typed will be lost.');
        },
    });

    beacon('reg_start');
    stepPhone(sheet, draft, form, config);

    return sheet;
}

function head(sheet, title, step) {
    return el('div', { class: 'se-sheet-head' },
        el('h2', { class: 'se-h2', text: title }),
        dots(3, step));
}

// --------------------------------------------------------------------------
// Step 1 — phone
// --------------------------------------------------------------------------

function stepPhone(sheet, draft, form, config) {
    const input = el('input', {
        class: 'se-input',
        type: 'tel',
        inputmode: 'tel',
        autocomplete: 'tel',
        placeholder: '0803 123 4567',
        value: draft.phone,
        'aria-required': 'true',
    });

    const wrapper = field('se-reg-phone', 'Your phone number', input, "We'll use it to welcome you at the door.");

    // The +234 chip sits beside the input rather than inside the value, so
    // "0803…" and "+234 803…" both keep working (§13.4 step 1).
    decorate(wrapper, (control) => el('div', { class: 'se-phone-row' },
        el('span', { class: 'se-phone-prefix', 'aria-hidden': 'true', text: '+234' }),
        control));

    const submit = el('button', { class: 'se-btn se-btn-primary', type: 'submit', text: 'Continue' });

    const formEl = el('form', { novalidate: true, onsubmit: async (event) => {
        event.preventDefault();

        const parsed = normalizePhone(input.value);
        if (!parsed) {
            wrapper.setError('Please enter a mobile number, e.g. 0803 123 4567.');
            input.focus();
            return;
        }

        wrapper.setError('');
        draft.phone = parsed.e164;
        busy(submit, true);

        try {
            draft.lookup = await call('public', 'lookup', { ...eventRef(), purpose: 'register', phone: parsed.e164 });
            stepIdentity(sheet, draft, form, config);
        } catch (error) {
            busy(submit, false);
            wrapper.setError(error instanceof SeApiError ? error.message : 'Please try again.');
        }
    } },
        wrapper,
        el('p', { class: 'se-small se-muted', text: config.texts?.min_age_note || '' }),
        el('p', {}, submit));

    sheet.render(head(sheet, 'Register', 0), formEl);
    input.focus();
}

function busy(button, state) {
    button.disabled = state;
    if (state) {
        button.dataset.label = button.textContent;
        button.replaceChildren(el('span', { class: 'se-spinner', 'aria-hidden': 'true' }), document.createTextNode(' Working…'));
    } else if (button.dataset.label) {
        button.textContent = button.dataset.label;
    }
}

// --------------------------------------------------------------------------
// Step 2 — identity
// --------------------------------------------------------------------------

function initials(name) {
    return String(name || 'G').trim().split(/\s+/).slice(0, 2).map((p) => p[0].toUpperCase()).join('');
}

function stepIdentity(sheet, draft, form, config) {
    const lookup = draft.lookup || { kind: 'new', needs: ['first_name', 'last_name', 'consent'] };
    const needs = new Set(lookup.needs || []);

    if (lookup.kind === 'registered') {
        stepAlready(sheet, draft, config);
        return;
    }

    if (lookup.kind === 'blocked') {
        stepBlocked(sheet, lookup);
        return;
    }

    const nodes = [];
    const fields = [];

    if (lookup.kind === 'member' || lookup.kind === 'returning') {
        nodes.push(el('div', { class: 'se-identity' },
            el('span', { class: 'se-avatar', 'aria-hidden': 'true', text: initials(lookup.display_name) }),
            el('div', {},
                el('p', { class: 'se-h2', text: lookup.kind === 'member'
                    ? `Hi ${lookup.display_name} 👋 — is this you?`
                    : `Welcome back, ${String(lookup.display_name || '').split(' ')[0]}!` }),
                el('button', {
                    class: 'se-btn se-btn-ghost se-small',
                    type: 'button',
                    text: 'Not me',
                    onclick: () => {
                        draft.lookup = { kind: 'new', needs: ['first_name', 'last_name', 'gender', 'consent'] };
                        stepIdentity(sheet, draft, form, config);
                    },
                }))));
    }

    if (needs.has('first_name') || needs.has('last_name')) {
        const first = el('input', { class: 'se-input', type: 'text', autocomplete: 'given-name', value: draft.first_name });
        const last = el('input', { class: 'se-input', type: 'text', autocomplete: 'family-name', value: draft.last_name });

        const firstField = field('se-reg-first', 'First name', first);
        const lastField = field('se-reg-last', 'Last name', last);

        nodes.push(firstField, lastField);
        fields.push(
            { key: 'first_name', node: firstField, input: first, required: true, message: 'Please tell us your first name.' },
            { key: 'last_name', node: lastField, input: last, required: false });
    }

    if (needs.has('gender') || (form.fields?.gender === 'optional' && lookup.kind === 'new')) {
        const group = el('div', { class: 'se-choice-row', role: 'radiogroup', 'aria-label': 'You are…' });
        for (const [value, label] of GENDERS) {
            const button = el('button', {
                class: 'se-choice',
                type: 'button',
                role: 'radio',
                'aria-checked': draft.gender === value ? 'true' : 'false',
                'data-on': draft.gender === value ? '1' : '0',
                text: label,
                onclick: () => {
                    draft.gender = value;
                    for (const sibling of group.children) {
                        const on = sibling === button;
                        sibling.setAttribute('aria-checked', on ? 'true' : 'false');
                        sibling.dataset.on = on ? '1' : '0';
                    }
                    genderField.setError('');
                },
            });
            group.appendChild(button);
        }

        const genderField = field('se-reg-gender', 'You are…', group);
        nodes.push(genderField);
        fields.push({
            key: 'gender',
            node: genderField,
            required: form.fields?.gender === 'required',
            message: 'Please choose one.',
            value: () => draft.gender,
        });
    }

    if (shouldAskEmail(lookup, form.fields)) {
        const email = el('input', { class: 'se-input', type: 'email', autocomplete: 'email', value: draft.email });
        const emailField = field('se-reg-email', form.fields.email === 'required' ? 'Email' : 'Email (optional)', email);
        nodes.push(emailField);
        fields.push({
            key: 'email',
            node: emailField,
            input: email,
            required: form.fields.email === 'required',
            message: 'Please enter a valid email address.',
        });
    }

    if (form.fields?.how_heard && form.fields.how_heard !== 'off' && lookup.kind !== 'member') {
        const options = form.how_heard || {};
        const group = el('div', { class: 'se-choice-row' });
        const other = el('input', { class: 'se-input', type: 'text', placeholder: 'Tell us how', hidden: true, value: draft.how_heard_other });

        for (const [value, label] of Object.entries(options)) {
            group.appendChild(el('button', {
                class: 'se-choice',
                type: 'button',
                'aria-pressed': draft.how_heard === value ? 'true' : 'false',
                text: label,
                onclick: (event) => {
                    draft.how_heard = value;
                    for (const sibling of group.children) {
                        sibling.setAttribute('aria-pressed', sibling === event.currentTarget ? 'true' : 'false');
                    }
                    other.hidden = value !== 'other';
                    howField.setError('');
                },
            }));
        }

        const howField = field('se-reg-how', `How did you hear about ${config.event?.title || 'us'}?`, group);
        howField.appendChild(other);
        nodes.push(howField);
        fields.push({
            key: 'how_heard',
            node: howField,
            required: form.fields.how_heard === 'required',
            message: 'Please choose one of the options.',
            value: () => draft.how_heard,
        });

        other.addEventListener('input', () => { draft.how_heard_other = other.value; });
    }

    // Custom questions (§8.5)
    for (const question of form.questions || []) {
        const node = customField(question, draft);
        if (node) {
            nodes.push(node.wrapper);
            fields.push(node.spec);
        }
    }

    if (form.fields?.karaoke) {
        nodes.push(el('label', { class: 'se-check' },
            el('input', {
                type: 'checkbox',
                checked: draft.karaoke_interest,
                onchange: (event) => { draft.karaoke_interest = event.target.checked; },
            }),
            el('span', { text: "I'd love to sing at karaoke 🎤" })));
    }

    // Consent (§20.1)
    const consentRequired = (form.consent_mode || 'required_followup') === 'required_followup';
    const consentText = config.texts?.consent || '';
    const consentNode = el('input', {
        type: 'checkbox',
        checked: draft.consent || needs.has('consent') === false,
        onchange: (event) => { draft.consent = event.target.checked; consentField.setError(''); },
    });
    draft.consent = consentNode.checked;

    const consentError = el('span', { class: 'se-error', id: 'se-reg-consent-error', role: 'alert', hidden: true });
    const consentField = {
        setError(message) {
            consentError.textContent = message || '';
            consentError.hidden = !message;
            consentNode.setAttribute('aria-invalid', message ? 'true' : 'false');
        },
    };
    consentNode.setAttribute('aria-describedby', 'se-reg-consent-error');

    const consentLabel = el('label', { class: 'se-check' },
        consentNode,
        el('span', {},
            document.createTextNode(consentRequired ? consentText : (config.texts?.optin || consentText)),
            document.createTextNode(' '),
            el('a', { class: 'se-md-link', href: config.urls?.privacy || '#', target: '_blank', rel: 'noopener', text: 'How we use your details' })));

    nodes.push(consentLabel, consentError);

    // The honeypot (§19.4): off-screen, unlabelled, never focusable.
    const honeypot = el('input', { class: 'se-hp', type: 'text', name: 'website', tabindex: '-1', autocomplete: 'off', 'aria-hidden': 'true' });

    const submit = el('button', {
        class: 'se-btn se-btn-primary',
        type: 'submit',
        text: lookup.kind === 'member' ? 'Yes, register me' : 'Register',
    });

    const formEl = el('form', { novalidate: true, onsubmit: (event) => {
        event.preventDefault();
        collect(fields, draft);

        let bad = null;
        for (const spec of fields) {
            const value = spec.value ? spec.value() : (spec.input ? spec.input.value.trim() : '');
            if (spec.required && (!value || value === '')) {
                spec.node.setError(spec.message || 'This one is required.');
                bad = bad || spec;
            } else if (spec.key === 'email' && value && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(value)) {
                spec.node.setError('That email address does not look right.');
                bad = bad || spec;
            } else {
                spec.node.setError('');
            }
        }

        if (consentRequired && !consentNode.checked) {
            consentField.setError('Please tick the box to continue.');
            bad = bad || { node: consentField };
        } else {
            consentField.setError('');
        }

        if (bad) {
            (bad.input || consentNode).focus();
            return;
        }

        draft.consent = consentNode.checked;
        submitRegistration(sheet, draft, config, submit, honeypot);
    } },
        nodes,
        honeypot,
        el('p', {}, submit));

    sheet.render(head(sheet, 'Almost there', 1), formEl);
}

function collect(fields, draft) {
    for (const spec of fields) {
        if (!spec.input) continue;
        if (spec.key.startsWith('answers.')) draft.answers[spec.key.slice(8)] = spec.input.value.trim();
        else draft[spec.key] = spec.input.value.trim();
    }
}

/** One custom question from se_form_fields. */
function customField(question, draft) {
    const id = 'se-q-' + question.key.replace(/[^a-z0-9]/gi, '');
    const label = question.label + (question.required ? '' : ' (optional)');

    if (question.type === 'checkbox') {
        const input = el('input', { type: 'checkbox', onchange: (e) => { draft.answers[question.key] = e.target.checked; } });
        const wrapper = el('label', { class: 'se-check' }, input, el('span', { text: label }));
        wrapper.setError = () => {};

        return { wrapper, spec: { key: 'answers.' + question.key, node: wrapper, required: false } };
    }

    let control;
    if (question.type === 'textarea') {
        control = el('textarea', { class: 'se-input', rows: '3' });
    } else if (question.type === 'select' && question.options?.length) {
        control = el('select', { class: 'se-input' }, el('option', { value: '', text: 'Choose…' }),
            ...question.options.map((o) => el('option', { value: o, text: o })));
    } else if (question.type === 'number') {
        control = el('input', { class: 'se-input', type: 'number', inputmode: 'numeric' });
    } else {
        control = el('input', { class: 'se-input', type: 'text', placeholder: question.placeholder || '' });
    }

    const wrapper = field(id, label, control, question.help || null);
    control.addEventListener('input', () => { draft.answers[question.key] = control.value; });

    return {
        wrapper,
        spec: {
            key: 'answers.' + question.key,
            node: wrapper,
            input: control,
            required: question.required,
            message: question.label + ' is required.',
        },
    };
}

// --------------------------------------------------------------------------
// Submit
// --------------------------------------------------------------------------

async function submitRegistration(sheet, draft, config, submit, honeypot) {
    busy(submit, true);

    const attribution = config.attribution || {};

    const payload = {
        ...eventRef(),
        phone: draft.phone,
        first_name: draft.first_name,
        last_name: draft.last_name,
        gender: draft.gender,
        email: draft.email,
        how_heard: draft.how_heard,
        how_heard_other: draft.how_heard_other,
        karaoke_interest: draft.karaoke_interest,
        consent: draft.consent,
        answers: draft.answers,
        src: attribution.src || null,
        ref: attribution.ref || null,
        hp: honeypot.value,
        t_ms: Date.now() - draft.openedAt,
    };

    try {
        const data = await call('public', 'register', payload);
        beacon('reg_done', data.outcome);
        haptic(HAPTIC.confirm);
        saveLocal(config.event?.public_id, { reg_code: data.reg_code, manage_url: data.manage_url, status: data.status });
        stepSuccess(sheet, data, config, draft);
    } catch (error) {
        busy(submit, false);

        if (error instanceof SeApiError && error.code === 'VALIDATION') {
            for (const [name, reason] of Object.entries(error.fields)) {
                const node = qs(`#se-reg-${name.replace('_', '')}`, sheet.panel)?.closest('.se-field');
                if (node?.setError) node.setError(reason);
            }
            toast(error.message, 'error');
            return;
        }

        if (error instanceof SeApiError && ['REG_CLOSED', 'REG_NOT_OPEN', 'CAPACITY_FULL'].includes(error.code)) {
            sheet.render(head(sheet, 'Registration', 2),
                el('p', { class: 'se-glass se-pad', text: error.message }),
                el('p', {}, el('button', { class: 'se-btn se-btn-ghost', type: 'button', text: 'Close', onclick: () => sheet.close(true) })));
            return;
        }

        toast(error.message || 'Something went wrong. Please try again.', 'error');
        submit.textContent = 'Retry';
    }
}

// --------------------------------------------------------------------------
// Already registered
// --------------------------------------------------------------------------

function stepAlready(sheet, draft, config) {
    const lookup = draft.lookup || {};

    // A returning guest lands back on the confirmation they saw the first
    // time — the ticket and all four cards — instead of a bare "you're in"
    // line (§13.4). The one thing that differs is what the device is allowed
    // to hold: the seat link belongs to the phone that made the registration
    // (§10.3.5), so every other device gets "Text me my link" in that slot.
    if (lookup.device_owns) {
        // This device does hold the registration, so the server can hand back
        // a live ticket and a live seat link. One round trip, no tap.
        sheet.render(head(sheet, 'Welcome back', 2),
            el('p', { class: 'se-small se-muted', text: 'Getting your ticket…' }));

        call('public', 'me', eventRef())
            .then((data) => {
                me.value = data;
                stepSuccess(sheet, {
                    outcome: 'already',
                    reg_code: data.registration.reg_code,
                    display_name: data.registration.display_name,
                    first_name: data.registration.first_name,
                    status: data.registration.status,
                    ref_url: data.links.ref_url,
                    manage_url: data.links.manage_url,
                    waitlist_position: data.registration.waitlist_position,
                    can_make_card: config.flags?.im_going_card,
                    // They answered this the first time round. Asking again on
                    // a return visit reads as though we had forgotten them.
                    ask_wants_visit: false,
                }, config, draft);
            })
            .catch(() => {
                // The lookup said this device owns it, but the ticket would
                // not load. Show what is already known rather than an error.
                stepSuccess(sheet, alreadyPayload(lookup, config), config, draft);
            });
        return;
    }

    stepSuccess(sheet, alreadyPayload(lookup, config), config, draft);
}

/**
 * The crew took this number off the list, and the portal will refuse it
 * (outcome 'blocked', §12.2).
 *
 * Say it here, on step one, rather than letting them type their whole name,
 * gender and email and refusing them at the end — which is what used to
 * happen, because the lookup only recognised a *live* registration.
 */
function stepBlocked(sheet, lookup) {
    const who = lookup.display_name ? ` about ${lookup.display_name}` : '';

    sheet.render(head(sheet, 'One moment', 2),
        el('p', {
            class: 'se-muted',
            text: `There is something to sort out${who} before this number can register. Please see the desk when you arrive — they can help you straight away.`,
        }),
        el('p', {}, el('button', {
            class: 'se-btn se-btn-ghost', type: 'button', text: 'Close', onclick: () => sheet.close(true),
        })));
}

/** The success screen's data, built from what a phone lookup alone returns. */
function alreadyPayload(lookup, config) {
    return {
        outcome: 'already',
        first_name: lookup.first_name || String(lookup.display_name || '').split(' ')[0],
        display_name: lookup.display_name,
        reg_code: lookup.reg_code || null,
        status: lookup.reg_status,
        waitlist_position: lookup.waitlist_position,
        ref_url: lookup.ref_url || null,
        // Never a seat link here: this is not the device that holds the seat,
        // and its absence is what swaps the first card for "Text me my link".
        manage_url: null,
        can_make_card: lookup.can_make_card !== false && config.flags?.im_going_card !== false,
        ask_wants_visit: false,
    };
}

// --------------------------------------------------------------------------
// Step 3 — success
// --------------------------------------------------------------------------

async function burstConfetti() {
    if (prefersReducedMotion()) return;
    try {
        const styles = getComputedStyle(document.documentElement);
        const colors = ['--se-primary-raw', '--se-secondary-raw', '--se-accent']
            .map((token) => styles.getPropertyValue(token).trim())
            .filter(Boolean);

        const { default: confetti } = await import('canvas-confetti');
        // The default instance draws in a Worker built from a blob: URL,
        // which the §19.7 CSP (script-src 'self' + nonce) refuses. A
        // main-thread instance needs nothing the policy does not allow.
        const burst = confetti.create(null, { resize: true, useWorker: false });
        burst({ particleCount: 110, spread: 75, origin: { y: 0.3 }, colors: colors.length ? colors : undefined, disableForReducedMotion: true });
    } catch (e) {
        /* a missing confetti module must never break the ticket */
    }
}

export function stepSuccess(sheet, data, config, draft) {
    const waitlisted = data.outcome === 'waitlisted' || data.status === 'waitlisted';
    const firstName = data.first_name || String(data.display_name || '').split(' ')[0];

    const ticket = el('div', { class: 'se-ticket' },
        el('p', { class: 'se-label', text: `${config.event?.title || ''} ${config.event?.edition || ''}`.trim() }),
        el('p', { class: 'se-ticket-name', text: data.display_name }),
        el('p', { class: 'se-small', text: config.days?.[0]?.starts_at
            ? new Intl.DateTimeFormat('en-NG', { timeZone: 'Africa/Lagos', dateStyle: 'full', timeStyle: 'short' }).format(new Date(config.days[0].starts_at))
            : '' }),
        data.reg_code ? el('p', { class: 'se-ticket-code', text: data.reg_code }) : null);

    const actions = el('div', { class: 'se-action-grid' });

    if (data.manage_url) {
        actions.appendChild(el('button', {
            class: 'se-action', type: 'button', text: '🔗  Save your link',
            onclick: async () => {
                if (navigator.share) {
                    try { await navigator.share({ title: 'My place at ' + (config.event?.title || ''), url: data.manage_url }); return; } catch (e) { /* cancelled */ }
                }
                toast(await copyText(data.manage_url) ? 'Link copied.' : data.manage_url, 'success');
            },
        }));
    } else if (config.flags?.link_on_demand) {
        // Someone is looking at this ticket on a phone that does not hold the
        // seat, so there is no link to save. Texting it to the number that
        // does is the only safe way to hand it over (§10.3.5), and the
        // server's answer is deliberately the same either way.
        const text = el('button', {
            class: 'se-action', type: 'button', text: '🔗  Text me my link',
            onclick: async () => {
                busy(text, true);
                try {
                    await call('public', 'request_link', { ...eventRef(), phone: draft?.phone });
                    text.replaceWith(el('p', { class: 'se-glass se-pad se-small', text: 'If that number is registered, we have texted the link.' }));
                } catch (error) {
                    busy(text, false);
                    toast(error.message, 'error');
                }
            },
        });
        actions.appendChild(text);
    }

    if (data.can_make_card !== false && config.flags?.im_going_card !== false) {
        actions.appendChild(el('button', {
            class: 'se-action', type: 'button', text: '🎟  Make your “I’m going” card',
            onclick: async () => {
                beacon('card', 'im_going');
                // The SVG engine and the QR library are a sizeable chunk
                // that most visitors never need: load them only when
                // somebody actually asks for a card (§13.15).
                const { openCardBuilder } = await import('./card.js');
                openCardBuilder('im_going');
            },
        }));
    }

    if (config.urls?.calendar) {
        actions.appendChild(el('a', { class: 'se-action', href: config.urls.calendar, text: '📅  Add to calendar' }));
    }

    if (data.ref_url) {
        actions.appendChild(el('button', {
            class: 'se-action', type: 'button', text: '💌  Invite friends',
            onclick: async () => {
                beacon('share', 'ref');
                if (navigator.share) {
                    try { await navigator.share({ title: config.event?.title, text: "I'm going — come with me!", url: data.ref_url }); return; } catch (e) { /* cancelled */ }
                }
                toast(await copyText(data.ref_url) ? 'Your invite link is copied.' : data.ref_url, 'success');
            },
        }));
    }

    // A returning guest is welcomed back by name, not told again that they
    // are in — this screen is also the answer to "am I registered?" (§13.4).
    const already = data.outcome === 'already';

    const nodes = [
        head(sheet, waitlisted
            ? `You're #${data.waitlist_position || 1} on the waitlist`
            : (already
                ? `You're already in, ${data.display_name || firstName} 🎉`
                : `You're in, ${firstName}! 🎉`), 2),
    ];

    if (waitlisted) {
        nodes.push(el('p', { class: 'se-muted', text: "We'll text you the moment a seat opens." }));
    } else if (data.manage_url) {
        nodes.push(el('p', { class: 'se-small se-muted', text: "Save your link — it's how you manage your seat." }));
    } else {
        nodes.push(el('p', { class: 'se-small se-muted', text: 'Just check in at the door — your phone number is enough.' }));
    }

    nodes.push(ticket, actions);

    // "One more thing…" (§20.2) — only for guests, and only once.
    if (data.ask_wants_visit) {
        nodes.push(wantsVisitBlock());
    }

    nodes.push(el('p', {}, el('button', { class: 'se-btn se-btn-ghost', type: 'button', text: 'Done', onclick: () => sheet.close(true) })));

    sheet.render(nodes);
    if (!waitlisted) {
        popIn(ticket);
        burstConfetti();
    }
}

function wantsVisitBlock() {
    const block = el('div', { class: 'se-glass se-pad se-stack-sm' },
        el('p', { text: 'One more thing: would you like to visit HOD Lekki on a Sunday?' }));

    const answer = async (value) => {
        try {
            await call('public', 'wants_visit', { ...eventRef(), value });
            block.replaceChildren(el('p', { class: 'se-small', text: value ? "Lovely — we'll be in touch. 💛" : 'No problem at all.' }));
        } catch (error) {
            toast(error.message, 'error');
        }
    };

    block.appendChild(el('div', { class: 'se-choice-row' },
        el('button', { class: 'se-choice', type: 'button', text: "Yes, I'd love to", onclick: () => answer(true) }),
        el('button', { class: 'se-choice', type: 'button', text: 'Not now', onclick: () => answer(false) })));

    return block;
}

export { displayPhone };
