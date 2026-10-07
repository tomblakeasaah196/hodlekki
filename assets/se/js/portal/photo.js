// /assets/se/js/portal/photo.js
//
// The in-ring photo fitter (guide §14.3, §4.2 of the design README).
//
// There is no separate cropper screen any more. The card's own medallion is
// the mask, the button and the drag surface all at once: the guest drags with
// one finger, pinches with two, and watches the real card change underneath
// their hand. The fitted square is handed back as a JPEG data URI for the
// template's se__image__photo slot.
//
// Two things this file promises:
//
//   · The photo NEVER leaves the device. There is no fetch, no upload and no
//     storage anywhere in here — the only network calls in the whole builder
//     fetch the template, the hero and the crest, and none of them carry the
//     guest's picture. The UI says so, and this comment is why it can.
//   · The circle can never show an empty edge. Every gesture is clamped to
//     the fitted square, so there is no state a guest can drag themselves
//     into that renders a sliver of background.

import { toast } from '@se/core/store.js';
import { el } from './dom.js';

const MAX_EDGE = 1600;   // downscale cap before any drawing (§14.3 step 2)
const EXPORT = 720;      // the fitted square, large enough for a 1080-wide card
const MAX_ZOOM = 3.2;    // pinch-out limit, measured from the cover fit
const TAP_SLOP = 6;      // px of travel that still counts as a tap, not a drag

/** Decode with EXIF rotation applied, then downscale to MAX_EDGE. */
async function loadBitmap(file) {
    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch (e) {
        bitmap = await createImageBitmap(file);
    }

    const longest = Math.max(bitmap.width, bitmap.height);
    if (longest <= MAX_EDGE) return bitmap;

    const scale = MAX_EDGE / longest;
    const resized = await createImageBitmap(bitmap, {
        resizeWidth: Math.round(bitmap.width * scale),
        resizeHeight: Math.round(bitmap.height * scale),
        resizeQuality: 'high',
    });
    bitmap.close?.();

    return resized;
}

/** Is this a device with a mouse? Pinch does not exist there, a slider does. */
export function hasFinePointer() {
    return typeof window.matchMedia === 'function' && window.matchMedia('(pointer: fine)').matches;
}

/**
 * Fit a photo inside a circular ring and republish the fitted square.
 *
 * @param {object}   options
 * @param {HTMLElement} options.ring     The medallion `<button>`: the mask, the tap target and the drag surface.
 * @param {HTMLInputElement} options.input  The `<input type="file" accept="image/*">` (no `capture`: camera *and* gallery).
 * @param {(dataUri: string|null) => void} options.onChange  Receives the fitted square, or null when there is none.
 * @returns {{pick: Function, remove: Function, hasPhoto: Function, zoom: HTMLElement|null, destroy: Function}}
 */
export function mountPhotoFit({ ring, input, onChange }) {
    const canvas = el('canvas', {
        class: 'se-card-photo',
        width: String(EXPORT),
        height: String(EXPORT),
        'aria-hidden': 'true',
    });
    ring.appendChild(canvas);

    // Desktop only: there is no pinch to zoom with a mouse, and a slider is
    // the one extra control a guest on a laptop actually needs (§4.2).
    const zoom = hasFinePointer()
        ? el('input', {
            type: 'range', min: '100', max: '320', value: '100',
            class: 'se-input se-card-zoom', 'aria-label': 'Zoom your photo',
            hidden: true,
        })
        : null;

    const ctx = canvas.getContext('2d');
    const state = { bitmap: null, baseScale: 1, scale: 1, x: 0, y: 0 };
    const pointers = new Map();

    let frame = 0;
    let pinch = null;
    let travelled = 0;

    /** The image covers the whole square at minimum — so the circle cannot gap. */
    function fit() {
        const { width, height } = state.bitmap;
        state.baseScale = Math.max(EXPORT / width, EXPORT / height);
        state.scale = state.baseScale;
        state.x = (EXPORT - width * state.baseScale) / 2;
        state.y = (EXPORT - height * state.baseScale) / 2;
        if (zoom) zoom.value = '100';
    }

    function clamp() {
        const w = state.bitmap.width * state.scale;
        const h = state.bitmap.height * state.scale;
        state.x = Math.min(0, Math.max(EXPORT - w, state.x));
        state.y = Math.min(0, Math.max(EXPORT - h, state.y));
    }

    function clampScale(value) {
        return Math.max(state.baseScale, Math.min(state.baseScale * MAX_ZOOM, value));
    }

    function paint() {
        if (!state.bitmap) return;
        clamp();
        ctx.clearRect(0, 0, EXPORT, EXPORT);
        ctx.drawImage(
            state.bitmap,
            state.x, state.y,
            state.bitmap.width * state.scale, state.bitmap.height * state.scale);
    }

    /**
     * One frame does both jobs: repaint the ring, and republish the fitted
     * square. Throttled to a frame, because re-encoding a JPEG on every
     * pointermove would make the drag stutter on a cheap phone.
     */
    function schedule() {
        if (frame) return;
        frame = requestAnimationFrame(() => {
            frame = 0;
            if (!state.bitmap) { onChange(null); return; }
            paint();
            onChange(canvas.toDataURL('image/jpeg', 0.9));
        });
    }

    /** Scale about the centre of the square, so a slider nudge feels natural. */
    function zoomTo(scale) {
        const centre = EXPORT / 2;
        const next = clampScale(scale);
        const ratio = next / state.scale;
        state.x = centre - (centre - state.x) * ratio;
        state.y = centre - (centre - state.y) * ratio;
        state.scale = next;
        schedule();
    }

    function clear() {
        cancelAnimationFrame(frame);
        frame = 0;
        state.bitmap?.close?.();
        state.bitmap = null;
        pointers.clear();
        pinch = null;
        ctx.clearRect(0, 0, EXPORT, EXPORT);
        ring.dataset.filled = '0';
        ring.setAttribute('aria-label', 'Add your photo');
        if (zoom) { zoom.hidden = true; zoom.value = '100'; }
        input.value = '';
    }

    // --- the file picker ---------------------------------------------------

    input.addEventListener('change', async () => {
        const file = input.files?.[0];
        if (!file) return;

        try {
            state.bitmap?.close?.();
            state.bitmap = await loadBitmap(file);
            fit();
            ring.dataset.filled = '1';
            ring.setAttribute('aria-label', 'Your photo — drag to move, pinch to zoom');
            if (zoom) zoom.hidden = false;
            paint();
            onChange(canvas.toDataURL('image/jpeg', 0.9));
        } catch (e) {
            console.warn('[se] could not read that photo', e);
            clear();
            onChange(null);
            toast('That photo could not be opened. Try another one.', 'error');
        }
    });

    // --- the ring: tap to choose, drag to move, pinch to zoom ---------------

    ring.addEventListener('click', (event) => {
        // A drag ends in a click; without this it would re-open the picker
        // every time somebody let go of their photo.
        if (travelled > TAP_SLOP) {
            event.preventDefault();
            return;
        }
        // Empty or not, the circle opens the picker — swapping a photo is the
        // same gesture as choosing the first one (§4.2).
        input.click();
    });

    ring.addEventListener('pointerdown', (event) => {
        if (!state.bitmap) return;      // let the tap fall through to `click`
        ring.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY, fromX: event.clientX, fromY: event.clientY });
        travelled = 0;
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinch = { distance: Math.hypot(a.x - b.x, a.y - b.y) || 1, scale: state.scale };
        }
        ring.dataset.dragging = '1';
    });

    ring.addEventListener('pointermove', (event) => {
        const previous = pointers.get(event.pointerId);
        if (!previous || !state.bitmap) return;

        const point = { x: event.clientX, y: event.clientY, fromX: previous.fromX, fromY: previous.fromY };
        pointers.set(event.pointerId, point);
        travelled = Math.max(travelled, Math.hypot(point.x - point.fromX, point.y - point.fromY));

        if (pointers.size === 2 && pinch) {
            const [a, b] = [...pointers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y) || 1;
            zoomTo(pinch.scale * (distance / pinch.distance));
            if (zoom) zoom.value = String(Math.round((state.scale / state.baseScale) * 100));
            return;
        }

        // One CSS pixel of travel is worth EXPORT/ring-px of image movement,
        // so the drag tracks the finger whatever size the sheet is drawn at.
        const ratio = EXPORT / (ring.getBoundingClientRect().width || EXPORT);
        state.x += (point.x - previous.x) * ratio;
        state.y += (point.y - previous.y) * ratio;
        schedule();
    });

    const endPointer = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) pinch = null;
        if (!pointers.size) delete ring.dataset.dragging;
    };
    ring.addEventListener('pointerup', endPointer);
    ring.addEventListener('pointercancel', endPointer);

    if (zoom) {
        zoom.addEventListener('input', () => zoomTo(state.baseScale * (Number(zoom.value) / 100)));
    }

    return {
        pick() { input.click(); },
        remove() { clear(); onChange(null); },
        hasPhoto() { return state.bitmap !== null; },
        zoom,
        destroy() {
            cancelAnimationFrame(frame);
            state.bitmap?.close?.();
            canvas.remove();
            zoom?.remove();
        },
    };
}
