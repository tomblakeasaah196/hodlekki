// /assets/se/js/portal/manage.js
//
// The manage page at /e/<slug>/me/<token> (guide §13.5).
//
// Opening the link does two things: it shows the person their place, and it
// binds this device to their registration (`claim_link`), which is what makes
// "works on a second device" true. PR2 ships the status hero, the cards, the
// invite link and self-cancel; the song picker and My Night arrive with the
// karaoke and recap PRs.

import { call, SeApiError } from '@se/core/api.js';
import { boot, me, toast } from '@se/core/store.js';
import { formatDateTime } from '@se/core/boot.js';
import { el, qs, copyText } from './dom.js';
import { saveLocal } from './storage.js';

function eventRef() {
    const config = boot.value || {};

    return { event: config.event?.public_id, slug: config.event?.slug };
}

const STATUS_TEXT = {
    confirmed: 'Confirmed ✓',
    waitlisted: 'On the waitlist',
    cancelled: 'Cancelled',
    removed: 'Please see the desk',
};

export async function startManage() {
    const host = qs('#se-manage');
    if (!host) return;

    const config = boot.value || {};
    const token = config.token || null;

    try {
        const data = token
            ? (await call('public', 'claim_link', { ...eventRef(), token })).me
            : await call('public', 'me', eventRef());

        me.value = data;
        saveLocal(config.event?.public_id, {
            reg_code: data.registration?.reg_code,
            manage_url: data.links?.manage_url,
            status: data.registration?.status,
        });
        render(host, data, config);
    } catch (error) {
        host.setAttribute('aria-busy', 'false');
        host.replaceChildren(
            el('p', { class: 'se-glass se-pad', text: error instanceof SeApiError ? error.message : 'We could not open your link.' }),
            requestLinkBlock(config));
    }
}

function render(host, data, config) {
    const registration = data.registration || {};
    const status = registration.status || 'confirmed';

    host.setAttribute('aria-busy', 'false');

    const nodes = [
        el('div', { class: 'se-status-hero' },
            el('span', { class: 'se-status-pill', 'data-status': status, text: STATUS_TEXT[status] || status }),
            el('p', { class: 'se-h1', text: registration.display_name || '' }),
            status === 'waitlisted'
                ? el('p', { class: 'se-muted', text: `You're #${registration.waitlist_position || 1} in the queue. We'll text you the moment a seat opens.` })
                : el('p', { class: 'se-muted', text: `Your code is ${registration.reg_code}. Show it at the door, or just give your phone number.` })),
    ];

    if (status !== 'cancelled' && status !== 'removed') {
        const actions = el('div', { class: 'se-action-grid' });

        if (config.flags?.im_going_card !== false) {
            actions.appendChild(el('button', {
                class: 'se-action', type: 'button', text: '🎟  Your “I’m going” card',
                onclick: async () => {
                    const { openCardBuilder } = await import('./card.js');
                    openCardBuilder('im_going');
                },
            }));
        }

        if (data.links?.ref_url) {
            actions.appendChild(el('button', {
                class: 'se-action', type: 'button', text: '💌  Invite friends',
                onclick: async () => {
                    if (navigator.share) {
                        try { await navigator.share({ title: config.event?.title, text: "I'm going — come with me!", url: data.links.ref_url }); return; } catch (e) { /* cancelled */ }
                    }
                    toast(await copyText(data.links.ref_url) ? 'Your invite link is copied.' : data.links.ref_url, 'success');
                },
            }));
        }

        if (config.urls?.calendar) {
            actions.appendChild(el('a', { class: 'se-action', href: config.urls.calendar, text: '📅  Add to calendar' }));
        }

        if (data.links?.manage_url) {
            actions.appendChild(el('button', {
                class: 'se-action', type: 'button', text: '🔗  Copy my link',
                onclick: async () => {
                    toast(await copyText(data.links.manage_url) ? 'Link copied — keep it safe.' : data.links.manage_url, 'success');
                },
            }));
        }

        nodes.push(actions);

        if (data.can?.cancel) {
            nodes.push(cancelBlock(host, data, config));
        }

        nodes.push(optOutBlock());
    }

    if (status === 'cancelled') {
        nodes.push(el('p', { class: 'se-glass se-pad' },
            document.createTextNode('Your seat has been released. '),
            el('a', { class: 'se-md-link', href: config.urls?.portal || '/', text: 'Register again' }),
            document.createTextNode(' if your plans change.')));
    }

    const day = config.days?.[0];
    if (day?.starts_at) {
        nodes.push(el('p', { class: 'se-small se-muted', text: formatDateTime(day.starts_at, { dateStyle: 'full', timeStyle: 'short' }) }));
    }

    host.replaceChildren(...nodes);
}

function cancelBlock(host, data, config) {
    const details = el('details', { class: 'se-faq-item' });
    const summary = el('summary', { text: "Can't make it?" });

    const confirmButton = el('button', {
        class: 'se-btn se-btn-ghost', type: 'button', text: 'Release my seat',
        onclick: async () => {
            if (!confirm('Release your seat? Someone on the waitlist will take it.')) return;
            confirmButton.disabled = true;
            try {
                await call('public', 'cancel', eventRef());
                toast('Your seat has been released.', 'success');
                const fresh = await call('public', 'me', eventRef());
                me.value = fresh;
                render(host, fresh, config);
            } catch (error) {
                confirmButton.disabled = false;
                toast(error.message, 'error');
            }
        },
    });

    details.append(summary, el('div', { class: 'se-pad se-stack-sm' },
        el('p', { class: 'se-small', text: 'Let us know and we will pass your seat to someone on the waitlist. You can always register again if there is room.' }),
        confirmButton));

    return details;
}

function optOutBlock() {
    const button = el('button', {
        class: 'se-btn se-btn-ghost se-small', type: 'button', text: 'Stop contacting me',
        onclick: async () => {
            if (!confirm('Stop all messages about this and future events?')) return;
            try {
                await call('public', 'optout', eventRef());
                button.replaceWith(el('p', { class: 'se-small se-muted', text: 'Done — we will not contact you again.' }));
            } catch (error) {
                toast(error.message, 'error');
            }
        },
    });

    return el('p', { class: 'se-hero-foot' }, button);
}

/** Shown when the token has expired: a way back in that reveals nothing. */
function requestLinkBlock(config) {
    if (config.flags?.link_on_demand === false) {
        return el('p', { class: 'se-small se-muted', text: 'Please see the desk on the day — your phone number is enough.' });
    }

    const input = el('input', { class: 'se-input', type: 'tel', inputmode: 'tel', autocomplete: 'tel', placeholder: '0803 123 4567', 'aria-label': 'Your phone number' });
    const button = el('button', { class: 'se-btn se-btn-primary', type: 'submit', text: 'Text me my link' });

    return el('form', { class: 'se-stack-sm', onsubmit: async (event) => {
        event.preventDefault();
        button.disabled = true;
        try {
            await call('public', 'request_link', { ...eventRef(), phone: input.value });
            toast('If that number is registered, we have texted the link.', 'success');
        } catch (error) {
            toast(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    } },
        el('p', { class: 'se-small', text: 'Lost your link? We can text it to you.' }),
        input,
        button);
}
