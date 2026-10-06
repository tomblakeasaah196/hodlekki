// /assets/se/js/studio/state.js
//
// Studio-wide state (guide §13.13). Signals, not a store framework: the
// Studio is a handful of tabs over one event, and every view reads the same
// three or four values.

import { signal, computed } from '@preact/signals';
import { studio } from '@se/core/api.js';

function readBoot() {
    const node = document.getElementById('se-studio-boot');
    try {
        return node ? JSON.parse(node.textContent || '{}') : {};
    } catch (e) {
        console.error('[se-studio] boot payload is not valid JSON', e);
        return {};
    }
}

export const boot = signal(readBoot());

/** 'home' | 'event' */
export const screen = signal('home');

/** The event list on Home. */
export const events = signal([]);
export const seriesList = signal([]);
export const listFilter = signal('active');
export const listLoading = signal(false);

/** The open event (the full payload from get_event). */
export const current = signal(null);
export const currentLoading = signal(false);

/** The active workspace tab. */
export const tab = signal('overview');

/** Unsaved edits for the active tab: {field: value}. */
export const draft = signal({});
export const saving = signal(false);

/** Field-level errors from the last VALIDATION response. */
export const fieldErrors = signal({});

/** Toasts. */
export const toasts = signal([]);
let toastSeq = 0;

export function toast(message, kind = 'info', ms = 4500) {
    const id = ++toastSeq;
    toasts.value = [...toasts.value, { id, message, kind }];
    if (ms > 0) setTimeout(() => dismissToast(id), ms);
    return id;
}

export function dismissToast(id) {
    toasts.value = toasts.value.filter((t) => t.id !== id);
}

/** True when the active tab has edits the Producer has not saved. */
export const isDirty = computed(() => Object.keys(draft.value).length > 0);

/** Does the viewer hold this capability on the open event? */
export function can(capability) {
    const caps = current.value?.capabilities || [];
    return caps.includes(capability);
}

/** Set one draft field. */
export function setDraft(field, value) {
    draft.value = { ...draft.value, [field]: value };
}

/** Drop all unsaved edits. */
export function discardDraft() {
    draft.value = {};
    fieldErrors.value = {};
}

/** The draft value if edited, else the saved one. */
export function fieldValue(field, savedValue) {
    return Object.prototype.hasOwnProperty.call(draft.value, field)
        ? draft.value[field]
        : savedValue;
}

// --------------------------------------------------------------------------
// Loading
// --------------------------------------------------------------------------

export async function loadEvents() {
    listLoading.value = true;
    try {
        const data = await studio('list_events', { filter: listFilter.value, per_page: 60 });
        events.value = data.items || [];
        seriesList.value = data.series || [];
    } catch (e) {
        toast(e.message, 'error');
    } finally {
        listLoading.value = false;
    }
}

export async function openEvent(id, nextTab) {
    currentLoading.value = true;
    discardDraft();
    try {
        const data = await studio('get_event', { id });
        current.value = data.event;
        screen.value = 'event';
        if (nextTab) tab.value = nextTab;

        // Keep the URL shareable and the back button honest.
        const url = new URL(window.location.href);
        url.searchParams.set('event', String(id));
        history.replaceState({}, '', url.toString() + (url.hash ? '' : '#tab=' + tab.value));
    } catch (e) {
        toast(e.message, 'error');
        screen.value = 'home';
    } finally {
        currentLoading.value = false;
    }
}

/** Re-read the open event after a mutation. */
export function applyEvent(event) {
    if (event) current.value = event;
    discardDraft();
}

/**
 * Re-read the open event after a save that was NOT the tab's own form.
 *
 * A side panel with its own Save button (the portal page and its FAQ, for
 * one) shares a tab with the draft form above it. applyEvent() would throw
 * that draft away, so a half-typed tagline would vanish the moment someone
 * saved a question. The next save still carries the fresh row_version,
 * because saveSection() reads it at save time.
 */
export function refreshEvent(event) {
    if (event) current.value = event;
}

export function goHome() {
    screen.value = 'home';
    current.value = null;
    discardDraft();

    const url = new URL(window.location.href);
    url.searchParams.delete('event');
    url.hash = '';
    history.replaceState({}, '', url.toString());

    loadEvents();
}

/**
 * Save the active tab.
 *
 * Every write carries expected_row_version. On STALE_VERSION the Producer is
 * offered "reload or overwrite" rather than silently losing the other
 * person's work (§13.13).
 */
export async function saveSection(section, fields, { action = 'update_event', extra = {} } = {}) {
    if (!current.value) return false;

    saving.value = true;
    fieldErrors.value = {};

    try {
        const data = await studio(action, {
            id: current.value.id,
            section,
            fields,
            expected_row_version: current.value.row_version,
            ...extra,
        });
        applyEvent(data.event);
        toast(data.message || 'Saved.', 'success');
        return true;
    } catch (e) {
        if (e.code === 'VALIDATION') {
            fieldErrors.value = e.fields;
            toast(e.message, 'error');
        } else if (e.code === 'STALE_VERSION') {
            const reload = confirm(
                'Someone else saved this event while you were editing.\n\n'
                + 'OK — reload their version (your unsaved changes here are lost).\n'
                + 'Cancel — keep editing, then save again to overwrite.'
            );
            if (reload) {
                await openEvent(current.value.id, tab.value);
            }
        } else {
            toast(e.message, 'error');
        }
        return false;
    } finally {
        saving.value = false;
    }
}
