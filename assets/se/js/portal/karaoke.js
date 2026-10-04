// /assets/se/js/portal/karaoke.js
//
// The song picker on a guest's phone (guide §10.8.1, §13.5).
//
// Two things make this harder than a list with buttons:
//
//   1. Songs are unique. Two people tapping "Imela" in the same second is
//      the normal case at a church event, not the edge case, so the loser
//      gets a plain sentence and the list refreshes under them — never a
//      stack trace, never a silent nothing.
//   2. A pick made before the doors open is a HOLD, not a place in the
//      queue. The copy says so, because somebody who thinks they are
//      number four and arrives to find they are number eleven feels
//      cheated, and they would be right.
//
// The list loads on demand, inside a sheet, because most people open this
// page to see their code and leave.

import { call, SeApiError } from '@se/core/api.js';
import { boot, toast } from '@se/core/store.js';
import { el, qs, openSheet } from './dom.js';

function eventRef() {
    const config = boot.value || {};
    return { event: config.event?.public_id };
}

const STATUS_LINE = {
    held: 'Held for you — it becomes your place in the queue when you check in.',
    queued: 'You are in the queue.',
    up_next: 'You are up next — head towards the stage!',
    on_stage: 'You are on!',
    done: 'You sang. Legend.',
};

/**
 * The card on the manage page: what you picked, or an invitation to pick.
 */
export function karaokeBlock(data, onChanged) {
    const karaoke = data.karaoke;

    if (!karaoke) {
        return el('div', { class: 'se-glass se-pad se-stack' },
            el('p', { class: 'se-label', text: 'Karaoke' }),
            el('p', { text: 'Pick your song before the night and it is yours.' }),
            el('button', {
                class: 'se-btn', type: 'button', text: '🎤  Pick my song',
                onclick: () => openPicker(onChanged),
            }));
    }

    const lines = [
        el('p', { class: 'se-label', text: 'Your song' }),
        el('p', { class: 'se-h2', text: karaoke.song.title }),
        el('p', { class: 'se-muted', text: karaoke.song.artist }),
    ];

    if (karaoke.queue_no) {
        lines.push(el('p', { class: 'se-status-pill', 'data-status': 'confirmed', text: 'Number ' + karaoke.queue_no }));
    }
    lines.push(el('p', { class: 'se-small', text: STATUS_LINE[karaoke.status] || '' }));

    if (karaoke.ahead > 0 && ['held', 'queued'].includes(karaoke.status)) {
        lines.push(el('p', {
            class: 'se-small se-muted',
            text: karaoke.ahead === 1 ? 'One singer ahead of you.' : karaoke.ahead + ' singers ahead of you.',
        }));
    }

    if (['held', 'queued'].includes(karaoke.status)) {
        lines.push(el('div', { class: 'se-action-grid' },
            el('button', {
                class: 'se-btn se-btn-ghost', type: 'button', text: 'Choose a different song',
                onclick: () => openPicker(onChanged),
            }),
            el('button', {
                class: 'se-btn se-btn-ghost', type: 'button', text: 'Give it up',
                onclick: async () => {
                    try {
                        await call('public', 'release_song', eventRef());
                        toast('Released — somebody else can sing it now.', 'success');
                        onChanged();
                    } catch (error) {
                        toast(error.message || 'That did not work.', 'error');
                    }
                },
            })));
    }

    return el('div', { class: 'se-glass se-pad se-stack' }, ...lines);
}

/** The sheet: search, tap, done. */
export function openPicker(onChanged) {
    const sheet = openSheet({ label: 'Pick your song' });
    const list = el('div', { class: 'se-stack', 'aria-live': 'polite' },
        el('p', { class: 'se-muted', text: 'Loading the songs…' }));

    const search = el('input', {
        class: 'se-input', type: 'search', placeholder: 'Search a song or artist',
        'aria-label': 'Search the song list',
    });

    let page = 1;
    let timer = null;

    async function load() {
        try {
            const data = await call('public', 'songs', {
                ...eventRef(), q: search.value, page,
            });
            paint(data);
        } catch (error) {
            list.replaceChildren(el('p', { class: 'se-pad', text: error.message || 'We could not load the songs.' }));
        }
    }

    function paint(data) {
        if (!data.items.length) {
            list.replaceChildren(el('p', { class: 'se-pad se-muted', text: 'Nothing matches that. Try the artist instead.' }));
            return;
        }

        const rows = data.items.map((song) => el('button', {
            class: 'se-action', type: 'button', disabled: song.taken || undefined,
            onclick: () => pick(song),
        },
            el('span', { class: 'se-h2', text: song.title }),
            el('span', { class: 'se-muted', text: song.artist + (song.duration ? ' · ' + song.duration : '') }),
            song.taken ? el('span', { class: 'se-small', text: 'Taken' }) : document.createTextNode('')));

        const nodes = [el('div', { class: 'se-action-grid' }, ...rows)];

        if (data.pages > 1) {
            nodes.push(el('div', { class: 'se-action-grid' },
                el('button', {
                    class: 'se-btn se-btn-ghost', type: 'button', text: 'Back', disabled: page <= 1 || undefined,
                    onclick: () => { page -= 1; load(); },
                }),
                el('span', { class: 'se-small se-muted', text: 'Page ' + data.page + ' of ' + data.pages }),
                el('button', {
                    class: 'se-btn se-btn-ghost', type: 'button', text: 'More', disabled: page >= data.pages || undefined,
                    onclick: () => { page += 1; load(); },
                })));
        }

        list.replaceChildren(...nodes);
    }

    async function pick(song) {
        try {
            await call('public', 'pick_song', { ...eventRef(), song_id: song.id });
            toast('That song is yours 🎤', 'success');
            sheet.close();
            onChanged();
        } catch (error) {
            // SONG_TAKEN is the common one and is not the guest's fault, so
            // the list refreshes immediately with the truth.
            if (error instanceof SeApiError && error.code === 'SONG_TAKEN') {
                toast('Somebody just took that one — here is the list again.', 'error');
                load();
                return;
            }
            toast(error.message || 'That did not work.', 'error');
        }
    }

    search.addEventListener('input', () => {
        clearTimeout(timer);
        page = 1;
        timer = setTimeout(load, 250);
    });

    sheet.body.replaceChildren(
        el('p', { class: 'se-small se-muted', text: 'One singer per song. Pick now and it is held for you until you check in.' }),
        search,
        list);

    load();
}

/** The "you are up next" nudge (§12.2.1). It buzzes once per alert. */
export function karaokeAlert(data) {
    const alert = (data.alerts || []).find((a) => a.type === 'karaoke_up_next' || a.type === 'karaoke_on_stage');
    if (!alert) return null;

    const key = 'se-alert-' + alert.type + '-' + alert.at_ms;
    try {
        if (!sessionStorage.getItem(key)) {
            sessionStorage.setItem(key, '1');
            navigator.vibrate?.([120, 60, 120]);
        }
    } catch (e) {
        // A locked-down browser is not a reason to lose the message.
    }

    return el('div', { class: 'se-glass se-pad se-alert', role: 'status' },
        el('p', { class: 'se-h2', text: alert.type === 'karaoke_on_stage' ? 'You are on!' : 'You are up next 🎤' }),
        el('p', { class: 'se-small', text: 'Make your way towards the stage.' }));
}

/** Nothing on the manage page should break if the picker is unavailable. */
export function karaokeMount(host, data, onChanged) {
    const nodes = [];
    const alert = karaokeAlert(data);
    if (alert) nodes.push(alert);
    nodes.push(karaokeBlock(data, onChanged));
    for (const node of nodes) host.appendChild(node);
}

export { qs };
