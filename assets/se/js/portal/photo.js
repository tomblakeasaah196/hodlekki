// /assets/se/js/portal/photo.js
//
// PhotoCircleCropper (guide §14.3).
//
// The guest picks a photo, drags and pinches it inside a circular mask, and
// the circle's square bounding box is exported as a JPEG data URI for the
// card template's se__image__photo slot.
//
// The photo NEVER leaves the device: there is no fetch, no upload and no
// storage anywhere in this file. The UI says so, and this comment is the
// reason it can.

import { el } from './dom.js';

const MAX_EDGE = 1600;     // downscale cap before any drawing (§14.3 step 2)
const EXPORT = 720;        // the exported square, large enough for 1080-wide cards

/** Decode with EXIF rotation applied and downscale to MAX_EDGE. */
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

/**
 * Mount the cropper.
 *
 * @param {HTMLElement} mount
 * @param {(dataUri: string|null) => void} onChange
 * @returns {{destroy(): void, clear(): void}}
 */
export function mountPhotoCropper(mount, onChange) {
    const canvas = el('canvas', { width: '560', height: '560', role: 'img', 'aria-label': 'Your photo, drag to move' });
    const stage = el('div', { class: 'se-cropper', hidden: true }, canvas);

    const fileInput = el('input', { type: 'file', accept: 'image/*', class: 'se-sr-only', id: 'se-photo-input' });
    const pickLabel = el('label', { class: 'se-action', for: 'se-photo-input', text: '📷  Add your photo (optional)' });

    const zoom = el('input', {
        type: 'range', min: '100', max: '320', value: '100', class: 'se-input',
        'aria-label': 'Zoom', hidden: true,
    });

    const reset = el('button', { class: 'se-btn se-btn-ghost se-small', type: 'button', text: 'Reset', hidden: true });
    const remove = el('button', { class: 'se-btn se-btn-ghost se-small', type: 'button', text: 'Remove photo', hidden: true });

    const privacy = el('p', { class: 'se-small se-muted', text: 'Your photo stays on your phone.' });

    mount.replaceChildren(stage, pickLabel, fileInput, zoom,
        el('div', { class: 'se-choice-row' }, reset, remove), privacy);

    const ctx = canvas.getContext('2d');
    const state = { bitmap: null, scale: 1, baseScale: 1, x: 0, y: 0 };
    let emitTimer = null;

    function fit() {
        if (!state.bitmap) return;
        state.baseScale = Math.max(canvas.width / state.bitmap.width, canvas.height / state.bitmap.height);
        state.scale = state.baseScale;
        state.x = (canvas.width - state.bitmap.width * state.scale) / 2;
        state.y = (canvas.height - state.bitmap.height * state.scale) / 2;
        zoom.value = '100';
    }

    function clamp() {
        if (!state.bitmap) return;
        const w = state.bitmap.width * state.scale;
        const h = state.bitmap.height * state.scale;
        state.x = Math.min(0, Math.max(canvas.width - w, state.x));
        state.y = Math.min(0, Math.max(canvas.height - h, state.y));
    }

    function draw() {
        if (!state.bitmap) return;
        clamp();
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(state.bitmap, state.x, state.y, state.bitmap.width * state.scale, state.bitmap.height * state.scale);
        scheduleEmit();
    }

    function scheduleEmit() {
        clearTimeout(emitTimer);
        emitTimer = setTimeout(emit, 120);
    }

    function emit() {
        if (!state.bitmap) { onChange(null); return; }

        const out = document.createElement('canvas');
        out.width = EXPORT;
        out.height = EXPORT;
        const octx = out.getContext('2d');
        const ratio = EXPORT / canvas.width;
        octx.drawImage(
            state.bitmap,
            state.x * ratio, state.y * ratio,
            state.bitmap.width * state.scale * ratio,
            state.bitmap.height * state.scale * ratio);

        onChange(out.toDataURL('image/jpeg', 0.9));
    }

    // --- Input -------------------------------------------------------------

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files?.[0];
        if (!file) return;

        try {
            state.bitmap?.close?.();
            state.bitmap = await loadBitmap(file);
            fit();
            stage.hidden = false;
            zoom.hidden = false;
            reset.hidden = false;
            remove.hidden = false;
            pickLabel.textContent = '📷  Choose a different photo';
            draw();
        } catch (e) {
            console.warn('[se] could not read that photo', e);
            onChange(null);
        }
    });

    zoom.addEventListener('input', () => {
        const factor = Number(zoom.value) / 100;
        const centreX = canvas.width / 2;
        const centreY = canvas.height / 2;
        const before = state.scale;
        state.scale = state.baseScale * factor;
        state.x = centreX - ((centreX - state.x) * (state.scale / before));
        state.y = centreY - ((centreY - state.y) * (state.scale / before));
        draw();
    });

    reset.addEventListener('click', () => { fit(); draw(); });

    remove.addEventListener('click', () => {
        state.bitmap?.close?.();
        state.bitmap = null;
        stage.hidden = true;
        zoom.hidden = true;
        reset.hidden = true;
        remove.hidden = true;
        pickLabel.textContent = '📷  Add your photo (optional)';
        fileInput.value = '';
        onChange(null);
    });

    // Drag with one pointer, pinch with two.
    const pointers = new Map();
    let pinchStart = null;

    stage.addEventListener('pointerdown', (event) => {
        stage.setPointerCapture(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });
        if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            pinchStart = { distance: Math.hypot(a.x - b.x, a.y - b.y), scale: state.scale };
        }
    });

    stage.addEventListener('pointermove', (event) => {
        if (!pointers.has(event.pointerId) || !state.bitmap) return;

        const previous = pointers.get(event.pointerId);
        pointers.set(event.pointerId, { x: event.clientX, y: event.clientY });

        if (pointers.size === 2 && pinchStart) {
            const [a, b] = [...pointers.values()];
            const distance = Math.hypot(a.x - b.x, a.y - b.y);
            const factor = distance / (pinchStart.distance || 1);
            const before = state.scale;
            state.scale = Math.max(state.baseScale, Math.min(state.baseScale * 3.2, pinchStart.scale * factor));
            const centreX = canvas.width / 2;
            const centreY = canvas.height / 2;
            state.x = centreX - ((centreX - state.x) * (state.scale / before));
            state.y = centreY - ((centreY - state.y) * (state.scale / before));
            zoom.value = String(Math.round((state.scale / state.baseScale) * 100));
            draw();
            return;
        }

        const rect = stage.getBoundingClientRect();
        const ratio = canvas.width / rect.width;
        state.x += (event.clientX - previous.x) * ratio;
        state.y += (event.clientY - previous.y) * ratio;
        draw();
    });

    const endPointer = (event) => {
        pointers.delete(event.pointerId);
        if (pointers.size < 2) pinchStart = null;
    };
    stage.addEventListener('pointerup', endPointer);
    stage.addEventListener('pointercancel', endPointer);

    return {
        clear() { remove.click(); },
        destroy() {
            clearTimeout(emitTimer);
            state.bitmap?.close?.();
            mount.replaceChildren();
        },
    };
}
