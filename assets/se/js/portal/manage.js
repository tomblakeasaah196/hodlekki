// /assets/se/js/portal/manage.js
//
// The manage page at /e/<slug>/me/<token> (guide §13.5).
//
// Opening the link does two things: it shows the person their place, and it
// binds this device to their registration (`claim_link`), which is what makes
// "works on a second device" true. PR2 ships the status hero, the cards, the
// invite link and self-cancel; PR4 adds the song picker and the "you are up
// next" nudge. My Night arrives with the recap PR.

import { call, SeApiError } from '@se/core/api.js';
import { boot, me, toast } from '@se/core/store.js';
import { formatDateTime } from '@se/core/boot.js';
import { el, qs, copyText } from './dom.js';
import { saveLocal } from './storage.js';
import { karaokeBlock, karaokeAlert } from './karaoke.js';

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
        render(host, data, config, () => startManage());

        // While somebody is queued the page refreshes itself, because the
        // whole point of the nudge is that it arrives without a tap.
        if (data.karaoke && !['done', 'released', 'cancelled'].includes(data.karaoke.status)) {
            clearTimeout(refreshTimer);
            refreshTimer = setTimeout(() => {
                if (document.visibilityState === 'visible') startManage();
            }, 20000);
        }
    } catch (error) {
        host.setAttribute('aria-busy', 'false');
        host.replaceChildren(
            el('p', { class: 'se-glass se-pad', text: error instanceof SeApiError ? error.message : 'We could not open your link.' }),
            requestLinkBlock(config));
    }
}

let refreshTimer = null;

function render(host, data, config, reload) {
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

        if (['post', 'archived'].includes(config.event?.phase) || ['#recap', '#feedback'].includes(location.hash)) {
            actions.appendChild(el('button', {
                class: 'se-action', type: 'button', text: '✨  My Night card',
                onclick: async () => {
                    const { openCardBuilder } = await import('./card.js');
                    openCardBuilder('my_night');
                },
            }));
            actions.appendChild(el('button', {
                class: 'se-action', type: 'button', text: '💬  Share one-minute feedback',
                onclick: () => openFeedback(host, reload),
            }));
        }

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

        const alert = karaokeAlert(data);
        if (alert) nodes.unshift(alert);

        const flags = config.flags || {};
        if (flags.karaoke_enabled !== false && flags.karaoke_ready
            && (flags.karaoke_list_published || data.karaoke)) {
            nodes.push(karaokeBlock(data, reload));
        }

        if (data.games?.survey?.length) {
            nodes.push(surveyBlock(data, reload));
        }

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

function surveyBlock(data, reload) {
    const block = el('section', { class: 'se-glass se-pad se-stack-sm' },
        el('p', { class: 'se-label', text: 'Play ahead · Family Feud' }),
        el('h2', { class: 'se-h2', text: 'Quick survey' }),
        el('p', { class: 'se-small se-muted', text: 'One short answer each. You can change it until the game starts.' }));
    for (const question of data.games.survey) {
        const input = el('input', { class: 'se-input', maxlength: '60', value: question.answer || '', 'aria-label': question.question });
        const save = el('button', { class: 'se-btn se-btn-secondary', type: 'button', text: question.answer ? 'Update answer' : 'Save answer', onclick: async () => {
            save.disabled = true;
            try {
                await call('public', 'survey', { ...eventRef(), item_id: question.id, text: input.value });
                toast('Survey answer saved.', 'success');
                reload?.();
            } catch (error) {
                toast(error.message, 'error');
                save.disabled = false;
            }
        }});
        block.append(el('label', { class: 'se-label', text: question.question }), input, save);
    }
    return block;
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

function openFeedback(host, reload) {
    const nps = el('input', { class: 'se-input', type: 'number', min: '0', max: '10', required: true, placeholder: '0–10', 'aria-label': 'Likelihood to recommend, zero to ten' });
    const favorite = el('input', { class: 'se-input', maxlength: '30', placeholder: 'Favourite moment' });
    const oneWord = el('input', { class: 'se-input', maxlength: '40', placeholder: 'One word for the night' });
    const comment = el('textarea', { class: 'se-input', maxlength: '2000', rows: '3', placeholder: 'Anything else?' });
    const visit = el('input', { type: 'checkbox' });
    const future = el('input', { type: 'checkbox' });
    const form = el('form', { class: 'se-glass se-pad se-stack-sm', id: 'feedback', onsubmit: async (event) => {
        event.preventDefault();
        const button = event.submitter; button.disabled = true;
        try {
            await call('public', 'feedback', { ...eventRef(), nps: Number(nps.value), favorite: favorite.value, one_word: oneWord.value, comment: comment.value, wants_visit: visit.checked, future_optin: future.checked });
            toast('Thank you — your feedback is saved.', 'success');
            reload();
        } catch (error) { toast(error.message, 'error'); button.disabled = false; }
    } },
        el('h2', { class: 'se-h2', text: 'How was your night?' }),
        el('label', { class: 'se-small', text: 'How likely are you to recommend the next one? (0–10)' }), nps,
        favorite, oneWord, comment,
        el('label', { class: 'se-small' }, visit, ' I would like to visit HOD Lekki'),
        el('label', { class: 'se-small' }, future, ' Keep me posted about future events'),
        el('button', { class: 'se-btn se-btn-primary', type: 'submit', text: 'Send feedback' }));
    host.replaceChildren(form);
    form.scrollIntoView({ behavior: 'smooth', block: 'start' });
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
