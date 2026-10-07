// /assets/se/js/portal/card.js
//
// The "I'm going" share card builder (guide §14.3).
//
// Flow: ask the server for the card's data (`action=card`), fetch the SVG
// template, let the guest optionally add a photo, resolve the tokens with
// @se/core/svg.js, rasterise and share. Everything after the first two
// requests happens on the device.

import { call } from '@se/core/api.js';
import { boot, toast } from '@se/core/store.js';
import { renderTemplate, templateSize, rasterise, shareOrDownload, embedFontCss } from '@se/core/svg.js';
import { el, openSheet } from './dom.js';
import { mountPhotoCropper } from './photo.js';

const templateCache = new Map();

async function loadTemplate(url) {
    if (!templateCache.has(url)) {
        templateCache.set(url, fetch(url, { credentials: 'same-origin' }).then((r) => {
            if (!r.ok) throw new Error('That card template could not be loaded.');
            return r.text();
        }));
    }

    return templateCache.get(url);
}

/**
 * Open the card builder for one card kind.
 *
 * @param {'im_going'|'welcome'|'team'|'my_night'} kind
 * @param {{phone?: string}} [options] The registration phone for a cross-device "I'm going" card.
 */
export async function openCardBuilder(kind = 'im_going', options = {}) {
    const config = boot.value || {};
    const sheet = openSheet({ label: 'Make your card' });

    sheet.render(el('h2', { class: 'se-h2', text: 'Making your card…' }),
        el('p', { class: 'se-small se-muted', text: 'One moment.' }));

    let data;
    try {
        const request = { event: config.event?.public_id, slug: config.event?.slug, kind };
        if (typeof options?.phone === 'string' && options.phone.trim() !== '') {
            request.phone = options.phone;
        }
        data = await call('public', 'card', request);
    } catch (error) {
        sheet.render(el('h2', { class: 'se-h2', text: 'Not just yet' }),
            el('p', { class: 'se-glass se-pad', text: error.message }),
            el('p', {}, el('button', { class: 'se-btn se-btn-ghost', type: 'button', text: 'Close', onclick: () => sheet.close(true) })));
        return;
    }

    const state = { size: 'story', photo: null, busy: false };

    const preview = el('div', { class: 'se-card-preview', 'data-size': state.size, role: 'img', 'aria-label': 'Preview of your card' });
    const photoMount = el('div', { class: 'se-stack-sm' });

    const sizeToggle = el('div', { class: 'se-choice-row', role: 'radiogroup', 'aria-label': 'Card shape' },
        ...[['story', 'Story'], ['square', 'Square']].map(([value, label]) => el('button', {
            class: 'se-choice', type: 'button', role: 'radio',
            'aria-checked': state.size === value ? 'true' : 'false',
            'data-on': state.size === value ? '1' : '0',
            text: label,
            onclick: (event) => {
                state.size = value;
                for (const sibling of sizeToggle.children) {
                    const on = sibling === event.currentTarget;
                    sibling.setAttribute('aria-checked', on ? 'true' : 'false');
                    sibling.dataset.on = on ? '1' : '0';
                }
                preview.dataset.size = value;
                refresh();
            },
        })));

    const saveButton = el('button', { class: 'se-btn se-btn-primary', type: 'button', text: 'Save / share' });

    let currentSource = null;
    let currentSize = { width: 1080, height: 1920 };

    async function refresh() {
        try {
            const [svgText, fontCss] = await Promise.all([
                loadTemplate(data.templates[state.size]),
                embedFontCss([data.fonts?.display, data.fonts?.body]),
            ]);

            currentSize = templateSize(svgText);
            currentSource = renderTemplate(svgText, {
                text: data.text,
                colors: data.colors,
                images: state.photo ? { photo: state.photo } : {},
                qr: data.qr,
                flags: { ...data.flags, has_photo: Boolean(state.photo) },
                fontCss,
            });

            preview.replaceChildren();
            const img = new Image();
            img.alt = '';
            img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(currentSource);
            preview.appendChild(img);
        } catch (error) {
            console.warn('[se] card render failed', error);
            preview.replaceChildren(el('p', { class: 'se-small se-muted', text: 'The card could not be drawn here.' }));
        }
    }

    saveButton.addEventListener('click', async () => {
        if (state.busy || !currentSource) return;
        state.busy = true;
        saveButton.disabled = true;

        try {
            const blob = await rasterise(currentSource, currentSize.width, currentSize.height);
            const result = await shareOrDownload(blob, data.filename, data.text?.title || 'My card');
            if (result === 'downloaded') toast('Saved to your downloads.', 'success');
        } catch (error) {
            toast(error.message || 'The card could not be saved.', 'error');
        } finally {
            state.busy = false;
            saveButton.disabled = false;
        }
    });

    sheet.render(
        el('h2', { class: 'se-h2', text: "Your “I’m going” card" }),
        el('div', { class: 'se-card-stage' }, preview, sizeToggle),
        photoMount,
        el('p', {}, saveButton),
        el('p', {}, el('button', { class: 'se-btn se-btn-ghost', type: 'button', text: 'Close', onclick: () => sheet.close(true) })));

    mountPhotoCropper(photoMount, (dataUri) => {
        state.photo = dataUri;
        refresh();
    });

    refresh();
}
