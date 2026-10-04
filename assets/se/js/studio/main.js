// /assets/se/js/studio/main.js
//
// The Studio entry module (guide §8.6.4, §13.13).
//
// Mounts into #se-studio, which header.php has already wrapped in the ERP
// chrome. The Studio uses the Tailwind CDN that header.php loads — not
// se.css — so it looks native; only the live previews use the event's own
// palette (§8.6.4).

import { render } from 'preact';
import { useEffect, useState } from 'preact/hooks';
import { html } from '@se/core/html.js';
import {
    boot, screen, tab, current, currentLoading, isDirty,
    loadEvents, openEvent, goHome, toasts, dismissToast, discardDraft,
} from './state.js';
import { Spinner } from './ui.js';
import { HomeScreen } from './home.js';
import { OverviewTab } from './tabs/overview.js';
import { DetailsTab } from './tabs/details.js';
import { BrandTab } from './tabs/brand.js';
import { RegistrationTab } from './tabs/registration.js';
import { AttendeesTab } from './tabs/attendees.js';
import { AssetsTab } from './tabs/assets.js';
import { CrewTab } from './tabs/crew.js';
import { SettingsTab } from './tabs/settings.js';

const TABS = {
    overview: OverviewTab,
    details: DetailsTab,
    brand: BrandTab,
    registration: RegistrationTab,
    attendees: AttendeesTab,
    assets: AssetsTab,
    crew: CrewTab,
    settings: SettingsTab,
};

// --------------------------------------------------------------------------
// Toasts
// --------------------------------------------------------------------------

function Toasts() {
    const list = toasts.value;
    if (!list.length) return null;

    const styles = {
        success: 'bg-emerald-600',
        error: 'bg-hodRed',
        info: 'bg-hodBlue',
    };

    return html`
        <div class="fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] space-y-2 w-[min(90vw,28rem)]"
             role="status" aria-live="polite">
            ${list.map((t) => html`
            <div key=${t.id}
                 class=${'rounded-2xl px-5 py-3.5 text-white shadow-xl flex items-start justify-between gap-3 '
                     + (styles[t.kind] || styles.info)}>
                <span class="text-sm font-semibold">${t.message}</span>
                <button type="button" onClick=${() => dismissToast(t.id)}
                    class="text-white/70 hover:text-white shrink-0" aria-label="Dismiss">×</button>
            </div>`)}
        </div>`;
}

// --------------------------------------------------------------------------
// Live preview drawer (§13.13)
// --------------------------------------------------------------------------

function PreviewDrawer({ open, onClose, event }) {
    if (!open || !event?.preview_url) return null;

    // Same-origin iframe; frame-ancestors 'self' in the §19.7 CSP allows it.
    return html`
        <div class="fixed inset-0 z-50 flex justify-end" data-app-modal data-modal-ignore>
            <button type="button" aria-label="Close preview" onClick=${onClose}
                class="absolute inset-0 bg-gray-900/40"></button>
            <aside class="relative bg-white w-[min(100vw,26rem)] h-full shadow-2xl flex flex-col">
                <header class="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-100">
                    <div>
                        <h2 class="font-display font-bold text-gray-900">Preview</h2>
                        <p class="text-xs text-gray-500">Live, including unsaved colours once you save.</p>
                    </div>
                    <button type="button" onClick=${onClose}
                        class="text-gray-400 hover:text-gray-900 p-2" aria-label="Close">✕</button>
                </header>
                <iframe src=${event.preview_url} title="Portal preview"
                    class="flex-1 w-full border-0" loading="lazy"></iframe>
                <footer class="px-5 py-3 border-t border-gray-100">
                    <a href=${event.preview_url} target="_blank" rel="noopener"
                       class="text-sm font-semibold text-hodBlue hover:underline">Open in a new tab</a>
                </footer>
            </aside>
        </div>`;
}

// --------------------------------------------------------------------------
// Workspace
// --------------------------------------------------------------------------

function Workspace() {
    const event = current.value;
    const tabs = boot.value?.tabs || {};
    const Active = TABS[tab.value] || OverviewTab;

    if (currentLoading.value || !event) return html`<${Spinner} label="Opening event…" />`;

    const switchTo = (id) => {
        if (isDirty.value && !confirm('You have unsaved changes on this tab. Leave them?')) return;
        discardDraft();
        tab.value = id;

        const url = new URL(window.location.href);
        url.hash = 'tab=' + id;
        history.replaceState({}, '', url.toString());
    };

    return html`
        <div class="space-y-6">

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" onClick=${() => {
                        if (isDirty.value && !confirm('You have unsaved changes. Leave them?')) return;
                        goHome();
                    }}
                    class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-500 hover:text-hodBlue transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    All events
                </button>
                <span class="text-gray-300" aria-hidden="true">/</span>
                <span class="text-sm font-bold text-gray-900">${event.title} ${event.edition_label || ''}</span>
            </div>

            <nav class="flex gap-1 overflow-x-auto pb-1 -mx-1 px-1" role="tablist" aria-label="Event sections">
                ${Object.entries(tabs).map(([id, label]) => html`
                <button key=${id} type="button" role="tab" id=${'se-tab-' + id}
                    aria-selected=${tab.value === id} aria-controls="se-tabpanel"
                    onClick=${() => switchTo(id)}
                    class=${'px-4 py-2.5 rounded-xl text-sm font-semibold whitespace-nowrap transition-colors shrink-0 '
                        + (tab.value === id
                            ? 'bg-hodBlue text-white shadow-sm'
                            : 'text-gray-600 hover:text-hodBlue hover:bg-blue-50')}>
                    ${label}
                </button>`)}
            </nav>

            <div id="se-tabpanel" role="tabpanel" aria-labelledby=${'se-tab-' + tab.value}>
                <${Active} />
            </div>
        </div>`;
}

// --------------------------------------------------------------------------
// App
// --------------------------------------------------------------------------

function App() {
    const [previewOpen, setPreviewOpen] = useState(false);

    useEffect(() => {
        // Deep link: ?event=<id>#tab=<name> (the Ctrl/Cmd+K palette and the
        // crew notification both use this).
        const params = new URLSearchParams(window.location.search);
        const hash = new URLSearchParams((window.location.hash || '').replace(/^#/, ''));
        const wanted = hash.get('tab');

        if (wanted && (boot.value?.tabs || {})[wanted]) tab.value = wanted;

        const eventId = Number(params.get('event') || boot.value?.open_event || 0);
        if (eventId > 0) {
            openEvent(eventId, wanted || 'overview');
        } else {
            loadEvents();
        }
    }, []);

    return html`
        <div>
            ${screen.value === 'home' ? html`<${HomeScreen} />` : html`<${Workspace} />`}

            ${screen.value === 'event' && current.value?.preview_url ? html`
                <button type="button" onClick=${() => setPreviewOpen(!previewOpen)}
                    class="fixed bottom-6 right-6 z-40 inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-gray-900 text-white font-semibold shadow-xl hover:bg-gray-800 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    Preview
                </button>` : null}

            <${PreviewDrawer} open=${previewOpen} onClose=${() => setPreviewOpen(false)} event=${current.value} />
            <${Toasts} />
        </div>`;
}

// --------------------------------------------------------------------------
// Mount
// --------------------------------------------------------------------------

const root = document.getElementById('se-studio');

if (root) {
    render(html`<${App} />`, root);

    // tab-deeplink.js calls this for #tab= links from the Ctrl/Cmd+K palette.
    window.switchTab = (id) => {
        if ((boot.value?.tabs || {})[id]) {
            tab.value = id;
            if (screen.value === 'home' && current.value) screen.value = 'event';
        }
    };

    // Warn before losing unsaved edits to a browser navigation.
    window.addEventListener('beforeunload', (e) => {
        if (isDirty.value) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
} else {
    console.error('[se-studio] #se-studio is missing');
}
