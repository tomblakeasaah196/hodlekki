// /assets/se/js/portal/card.js
//
// The "I'm going" share card builder (guide §14.3, §4 of the design README).
//
// Flow: ask the server for the card's data (`action=card`), fetch the SVG
// template, inline the hero and the crest, let the guest fit a photo inside
// the card's own medallion, ask for a shape once they have decided to keep
// it, rasterise and share. Everything after the first two requests happens on
// the device, and the photo never leaves it.
//
// Three buttons exist in the whole builder — Add photo, Remove photo, Save —
// and never more than two are on screen at once. There is no Story/Square
// toggle up front (the question is asked at Save, when the guest knows why it
// matters), no separate cropper screen (the ring is the mask), no Reset (pinch
// out to the minimum does it), no zoom slider on a touchscreen, and no Close
// (drag the handle down, tap the backdrop, or press Escape — `openSheet()`).

import { call } from '@se/core/api.js';
import { boot, toast } from '@se/core/store.js';
import { renderTemplate, templateSize, rasterise, shareOrDownload, embedFontCss } from '@se/core/svg.js';
import { el, openSheet } from './dom.js';
import { mountPhotoFit } from './photo.js';

const templateCache = new Map();

/** 96 px is the whole trick behind the atmosphere (§3.3) — see heroDataUri(). */
const HERO_THUMB_WIDTH = 96;
/** The crest is drawn at 64 px and as a 5 % ghost; it never needs more. */
const CREST_WIDTH = 160;

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
 * Where the medallion is, read from the template rather than guessed.
 *
 * `se__slot__photo` is an inert `se__` token: the engine does not know it, so
 * it survives resolution untouched while every real token around it is filled
 * in. That makes it the one honest source of the circle's geometry, and it is
 * why nothing in this file is hardcoded to 1080 x 1920 (or 1080 x 1080).
 */
function photoSlot(svgText) {
    const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
    const slot = doc.querySelector('#se__slot__photo') || doc.getElementById?.('se__slot__photo');
    if (!slot) return null;

    const box = {
        x: parseFloat(slot.getAttribute('x') || '0'),
        y: parseFloat(slot.getAttribute('y') || '0'),
        width: parseFloat(slot.getAttribute('width') || '0'),
        height: parseFloat(slot.getAttribute('height') || '0'),
    };

    return box.width > 0 && box.height > 0 ? box : null;
}

/** Same-origin image -> data URI. An SVG drawn to a canvas can fetch nothing. */
async function inlineImage(path) {
    if (!path) return '';
    try {
        const response = await fetch(path, { credentials: 'same-origin' });
        if (!response.ok) return '';
        const blob = await response.blob();

        return await new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result || ''));
            reader.onerror = () => resolve('');
            reader.readAsDataURL(blob);
        });
    } catch (e) {
        console.warn('[se] card image could not be inlined', e);

        return '';
    }
}

/**
 * The hero as atmosphere, and nothing more (§3.3).
 *
 * Drawn to 96 px wide and re-encoded as a ~2-4 KB JPEG data URI: the
 * browser's own upscaling gives the soft falloff a 20 px blur would, with
 * nothing for Safari to choke on and a payload small enough to inline without
 * thinking. NEVER build a larger hero data URI — the whole point is that this
 * one is cheap.
 */
async function heroDataUri(path) {
    if (!path) return '';
    try {
        const response = await fetch(path, { credentials: 'same-origin' });
        if (!response.ok) return '';

        const bitmap = await createImageBitmap(await response.blob());
        const width = HERO_THUMB_WIDTH;
        const height = Math.max(1, Math.round(bitmap.height * (width / bitmap.width)));
        const canvas = el('canvas', { width: String(width), height: String(height) });
        canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height);
        bitmap.close?.();

        return canvas.toDataURL('image/jpeg', 0.72);
    } catch (e) {
        console.warn('[se] card hero could not be inlined', e);

        return '';
    }
}

/**
 * The crest, baked to a small PNG before it travels (§3.5).
 *
 * The house crest is an SVG wrapping a base64 PNG, and a raster inside an SVG
 * inside a canvas is exactly the portability risk §3.5 flags. Re-drawing it
 * once into a 160 px canvas and re-encoding removes the risk, keeps the
 * payload small, and gives the 5 % ghost something clean to fade.
 */
async function crestDataUri(path) {
    if (!path) return '';
    const source = await inlineImage(path);
    if (!source) return '';

    try {
        const image = await new Promise((resolve, reject) => {
            const probe = new Image();
            probe.onload = () => resolve(probe);
            probe.onerror = reject;
            probe.src = source;
        });
        const width = CREST_WIDTH;
        const height = Math.max(1, Math.round((image.naturalHeight || 1) * (width / (image.naturalWidth || 1))));
        const canvas = el('canvas', { width: String(width), height: String(height) });
        canvas.getContext('2d').drawImage(image, 0, 0, width, height);

        return canvas.toDataURL('image/png');
    } catch (e) {
        console.warn('[se] card crest could not be baked', e);

        // The original data URI still works in every engine but old Safari.
        return source;
    }
}

/**
 * Open the card builder for one card kind.
 *
 * @param {'im_going'|'welcome'|'team'|'my_night'} kind
 * @param {{phone?: string}} [options] The registration phone for a cross-device "I'm going" card.
 */
export async function openCardBuilder(kind = 'im_going', options = {}) {
    const config = boot.value || {};
    let fit = null;
    // Closing the sheet releases the decoded bitmap; openSheet() runs this on
    // every exit path a guest has (Escape, backdrop, drag, the handle).
    const sheet = openSheet({ label: 'Make your card', onBeforeClose: () => { fit?.destroy(); } });

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

    const state = { size: 'story', photo: null, busy: false, assets: null, refreshTimer: 0 };
    const preview = el('div', { class: 'se-card-preview', 'data-size': state.size, role: 'img', 'aria-label': 'Preview of your card' });
    const frame = el('div', { class: 'se-card-frame' }, preview);
    const ring = el('button', {
        class: 'se-card-ring',
        type: 'button',
        'data-filled': '0',
        'aria-label': 'Add your photo',
    });
    frame.appendChild(ring);

    const hint = el('p', { class: 'se-small se-muted', text: 'Tap your picture to add a photo.' });
    const privacy = el('p', { class: 'se-small se-muted', text: 'Your photo stays on your phone.' });

    // Still a real <label> and a real input, with no `capture` attribute, so
    // camera AND gallery stay available (§4.1). The ring and the button both
    // open it, which is why the input itself is out of the tab order.
    const input = el('input', {
        type: 'file', accept: 'image/*', class: 'se-sr-only',
        id: 'se-card-photo', tabindex: '-1',
    });
    const inputLabel = el('label', { class: 'se-sr-only', for: 'se-card-photo', text: 'Add a photo to your card' });

    // The one button that changes its own label: Add and Remove swap in place,
    // so the row never grows past two (§4.2).
    const photoButton = el('button', { class: 'se-btn se-btn-ghost', type: 'button', text: 'Add photo' });
    const saveButton = el('button', { class: 'se-btn se-btn-primary', type: 'button', text: 'Save / share' });

    let currentSource = '';
    let currentSize = { width: 1080, height: 1920 };
    let slot = null;

    /** Move the ring button over the medallion the template actually declares. */
    function placeRing() {
        const image = preview.querySelector('img');
        const shown = (image?.clientWidth || preview.clientWidth || 0);
        if (!slot || !currentSize.width || !shown) return;

        const scale = shown / currentSize.width;
        ring.style.left = `${slot.x * scale}px`;
        ring.style.top = `${slot.y * scale}px`;
        ring.style.width = `${slot.width * scale}px`;
        ring.style.height = `${slot.height * scale}px`;
    }

    async function refresh() {
        try {
            // The hero and the crest are fetched and inlined once, then reused
            // for every redraw — fitting a photo must not re-fetch them.
            state.assets ??= Promise.all([heroDataUri(data.hero), crestDataUri(data.images?.logo)])
                .then(([hero, crest]) => ({ hero, crest }))
                .catch(() => ({ hero: '', crest: '' }));

            const [svgText, fontCss, { hero, crest }] = await Promise.all([
                loadTemplate(data.templates[state.size]),
                // The card's OWN faces, not the event's (§14.3): Chara runs
                // Unbounded, but the approved artwork is Fraunces, italic.
                embedFontCss([
                    { family: data.fonts?.card_display || data.fonts?.display, italic: true },
                    data.fonts?.card_body || data.fonts?.body,
                ]),
                state.assets,
            ]);

            const images = {};
            if (state.photo) images.photo = state.photo;
            if (hero) images.hero = hero;
            if (crest) images.logo = crest;

            currentSize = templateSize(svgText);
            slot = slot || photoSlot(svgText);
            currentSource = renderTemplate(svgText, {
                text: data.text,
                colors: data.colors,
                images,
                qr: data.qr,
                flags: { ...data.flags, has_photo: Boolean(state.photo) },
                fontCss,
            });

            const image = new Image();
            image.alt = '';
            image.className = 'se-card-art';
            image.addEventListener('load', placeRing, { once: true });
            image.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(currentSource);
            preview.replaceChildren(image);
            placeRing();
        } catch (error) {
            console.warn('[se] card render failed', error);
            preview.replaceChildren(el('p', { class: 'se-small se-muted', text: 'The card could not be drawn here.' }));
        }
    }

    // --- the photo ---------------------------------------------------------

    fit = mountPhotoFit({
        ring,
        input,
        onChange(dataUri) {
            const had = Boolean(state.photo);
            state.photo = dataUri;
            photoButton.textContent = dataUri ? 'Remove photo' : 'Add photo';
            hint.textContent = dataUri
                ? 'Drag to move your photo. Pinch to zoom.'
                : 'Tap your picture to add a photo.';

            // The ring repaints itself every frame; the card behind it does
            // not have to keep up with a finger, only to be right at Save — so
            // a photo appearing or going redraws sooner than a nudge does.
            clearTimeout(state.refreshTimer);
            state.refreshTimer = setTimeout(refresh, had === Boolean(dataUri) ? 320 : 120);
        },
    });

    photoButton.addEventListener('click', () => {
        if (fit.hasPhoto()) fit.remove();
        else fit.pick();
    });

    // --- save, then ask ----------------------------------------------------

    const canShare = typeof navigator.share === 'function' && typeof navigator.canShare === 'function';

    async function saveAs(size, { download = false } = {}) {
        if (state.busy) return;
        state.busy = true;
        saveButton.disabled = true;
        state.size = size;
        preview.dataset.size = size;

        try {
            await refresh();
            const blob = await rasterise(currentSource, currentSize.width, currentSize.height);
            const result = await shareOrDownload(blob, data.filename, data.text?.title || 'My card');
            if (download || result === 'downloaded') toast('Saved to your downloads.', 'success');
        } catch (error) {
            if (!error || error.name !== 'AbortError') {
                toast(error?.message || 'The card could not be saved.', 'error');
            }
        } finally {
            state.busy = false;
            saveButton.disabled = false;
        }
    }

    /** The format ask: after Save, never before (§4.3). */
    function askFormat() {
        const ask = openSheet({ label: 'Save or share as…' });

        const option = (size, title, dimensions, places, shape) => el('button', {
            class: 'se-format', type: 'button', 'data-format': size,
            onclick: async () => {
                await ask.close(true);
                saveAs(size);
            },
        },
        el('span', { class: 'se-format-shape', 'data-format': size, 'aria-hidden': 'true', text: shape }),
        el('span', {},
            el('b', { text: title }),
            el('small', { text: `${dimensions} · ${places}` })));

        ask.render(
            el('h2', { class: 'se-h2', text: 'Save or share as…' }),
            el('p', { class: 'se-small se-muted', text: 'Pick the shape. It changes above as you choose.' }),
            el('div', { class: 'se-format-list' },
                option('story', 'Story', '1080 × 1920', 'WhatsApp Status, IG/FB Story, TikTok', '▯'),
                option('square', 'Square', '1080 × 1080', 'WhatsApp DP, group chat, IG feed', '▭')),
            el('p', { class: 'se-small se-muted' },
                canShare
                    ? 'Your phone’s share sheet saves it.'
                    // A link, not a third button: it saves whatever is on the
                    // card behind this sheet right now, no choice required.
                    : el('button', {
                        class: 'se-link', type: 'button', text: 'Download instead',
                        onclick: async () => {
                            await ask.close(true);
                            saveAs(state.size, { download: true });
                        },
                    })));
    }

    saveButton.addEventListener('click', () => {
        if (state.busy) return;
        askFormat();
    });

    // --- the sheet ---------------------------------------------------------

    sheet.render(
        el('h2', { class: 'se-h2', text: 'Your “I’m going” card' }),
        el('div', { class: 'se-card-stage' }, frame),
        fit.zoom,
        hint,
        el('div', { class: 'se-card-actions' }, photoButton, saveButton),
        privacy,
        inputLabel,
        input);

    // The sheet is sized by the viewport, so the ring has to be re-placed
    // whenever the preview is; a ResizeObserver is the only reliable signal
    // (rotation, a desktop window drag, the mobile URL bar collapsing).
    if (typeof ResizeObserver === 'function') {
        new ResizeObserver(placeRing).observe(preview);
    } else {
        window.addEventListener('resize', placeRing);
    }

    await refresh();
}
