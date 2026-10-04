// /assets/se/js/core/store.js
//
// Client state as signals (guide §8.6.2). Every surface reads from here, so
// a snapshot poll or an `action=me` refresh updates the whole UI at once.

import { signal, computed } from '@preact/signals';
import { readBoot } from './boot.js';

/** Server-provided event config: theme, texts, flags, urls, csrf. */
export const boot = signal(readBoot());

/** From action=me: this device's registration, team, karaoke, alerts. */
export const me = signal(null);

/** public.json — the open snapshot. Never contains personal names (§19.1). */
export const live = signal(null);

/** Key-protected snapshots: the room, and this device's team. */
export const room = signal(null);
export const team = signal(null);

/** Network health, for the NetPill (§13.1.7). */
export const net = signal({ online: true, lastOkAt: 0, failures: 0 });

/** The live phase wins over the one baked into the page at render time. */
export const phase = computed(() => live.value?.event?.phase ?? boot.value?.phase ?? 'upcoming');

/** Toasts. Views read this; `toast()` below is the only writer. */
export const toasts = signal([]);

let toastSeq = 0;

/** Show a short message. `kind` is 'info' | 'success' | 'error'. */
export function toast(message, kind = 'info', ms = 4000) {
    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, message, kind }];

    if (ms > 0) {
        setTimeout(() => {
            toasts.value = toasts.value.filter((t) => t.id !== id);
        }, ms);
    }

    return id;
}

export function dismissToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}
