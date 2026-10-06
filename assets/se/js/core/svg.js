// /assets/se/js/core/svg.js
//
// The share-card template engine (guide §14.3, §14.4).
//
// Designers export an SVG from Figma with layer ids turned on; those ids are
// the tokens. This module resolves them against a data object and rasterises
// the result on the device:
//
//   se__text__<field>     text content
//   se__box__<field>      the fit box for that text (--fit | --wrap-N)
//   se__fill__<role>      fill colour from the palette   (--N for duplicates)
//   se__stroke__<role>    stroke colour
//   se__stop__<role>      gradient stop colour
//   se__image__<slot>     <image> href replaced with a data URI
//   se__qr__<target>      <rect> replaced with a QR of the same box
//   se__if__<flag>        group shown when the flag is true
//   se__ifnot__<flag>     group shown when it is false
//
// Everything happens in the browser. The guest's photo is a data URI that is
// drawn, rasterised and shared without ever being uploaded.

import { qrcode } from 'qrcode-generator';

const SVG_NS = 'http://www.w3.org/2000/svg';
const XLINK_NS = 'http://www.w3.org/1999/xlink';

/** Strip the `--suffix` designers add to keep ids unique. */
function tokenParts(id) {
    const match = /^se__([a-z]+)__([a-z0-9_]+)(?:--(.+))?$/i.exec(id || '');
    if (!match) return null;

    return { kind: match[1].toLowerCase(), name: match[2].toLowerCase(), suffix: match[3] || '' };
}

/** Every token-bearing element in the document, in document order. */
function tokenElements(root) {
    const out = [];
    for (const el of root.querySelectorAll('[id]')) {
        const parts = tokenParts(el.id);
        if (parts) out.push({ el, ...parts });
    }

    return out;
}

/**
 * The tokens a template declares — what the Studio validator lists before
 * accepting an uploaded template (§14.4).
 */
export function readTokens(svgText) {
    const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
    const found = { text: [], box: [], fill: [], stroke: [], stop: [], image: [], qr: [], if: [], ifnot: [] };

    for (const { kind, name } of tokenElements(doc)) {
        if (found[kind] && !found[kind].includes(name)) found[kind].push(name);
    }

    return found;
}

// --------------------------------------------------------------------------
// Text fitting (§14.4)
// --------------------------------------------------------------------------

let measureCtx = null;

function measure(text, font) {
    if (!measureCtx) measureCtx = document.createElement('canvas').getContext('2d');
    measureCtx.font = font;

    return measureCtx.measureText(text).width;
}

function fontShorthand(el, sizePx) {
    const style = el.getAttribute('font-style') || 'normal';
    const weight = el.getAttribute('font-weight') || '400';
    const family = el.getAttribute('font-family') || 'sans-serif';

    return `${style} ${weight} ${sizePx}px ${family}`;
}

/** Greedy wrap into at most `maxLines` lines that each fit `maxWidth`. */
function wrapLines(text, font, maxWidth, maxLines) {
    const words = String(text).split(/\s+/).filter(Boolean);
    const lines = [];
    let line = '';

    for (const word of words) {
        const candidate = line ? `${line} ${word}` : word;
        if (line && measure(candidate, font) > maxWidth) {
            lines.push(line);
            line = word;
            if (lines.length === maxLines) break;
        } else {
            line = candidate;
        }
    }
    if (line && lines.length < maxLines) lines.push(line);

    return lines;
}

/**
 * Put `value` into a text element, shrinking (and wrapping) it to stay
 * inside its box.
 *
 * Mode comes from the box token's suffix: `--fit` keeps one line and only
 * shrinks; `--wrap-6` wraps to at most six lines and then shrinks.
 */
function applyText(el, value, box) {
    const text = String(value ?? '');

    // Clear whatever the designer typed, keeping the first tspan's styling.
    const template = el.querySelector('tspan');
    while (el.firstChild) el.removeChild(el.firstChild);

    const baseSize = parseFloat(el.getAttribute('font-size') || '48') || 48;
    const x = parseFloat(el.getAttribute('x') || (box ? box.x : 0)) || 0;

    if (!box || text === '') {
        el.textContent = text;
        return;
    }

    const mode = /^wrap-(\d+)$/.exec(box.mode || '');
    const maxLines = mode ? Math.max(1, parseInt(mode[1], 10)) : 1;

    let size = baseSize;
    let lines = [text];

    for (let i = 0; i < 24; i++) {
        const font = fontShorthand(el, size);
        lines = maxLines > 1
            ? wrapLines(text, font, box.width, maxLines)
            : [text];

        const widest = Math.max(...lines.map((l) => measure(l, font)), 0);
        const tall = lines.length * size * 1.15;
        const joined = lines.join(' ').length;
        const complete = maxLines === 1 || joined >= text.replace(/\s+/g, ' ').length - 1;

        if (widest <= box.width && tall <= box.height && complete) break;
        size *= 0.93;
        if (size < 8) break;
    }

    el.setAttribute('font-size', String(Math.round(size * 100) / 100));

    if (lines.length === 1) {
        el.textContent = lines[0];
        return;
    }

    lines.forEach((line, i) => {
        const tspan = document.createElementNS(SVG_NS, 'tspan');
        if (template) {
            for (const attr of ['fill', 'text-anchor', 'letter-spacing']) {
                const v = template.getAttribute(attr);
                if (v) tspan.setAttribute(attr, v);
            }
        }
        tspan.setAttribute('x', String(x));
        tspan.setAttribute('dy', i === 0 ? '0' : `${(size * 1.15).toFixed(2)}`);
        tspan.textContent = line;
        el.appendChild(tspan);
    });
}

// --------------------------------------------------------------------------
// QR (§14.2 step 2 — error correction H)
// --------------------------------------------------------------------------

/** A <g> of QR modules sized and positioned like `rect`. */
export function qrGroup(value, rect) {
    const qr = qrcode(0, 'H');
    qr.addData(String(value || ''));
    qr.make();

    const count = qr.getModuleCount();
    const cell = Math.min(rect.width, rect.height) / count;
    const offsetX = rect.x + (rect.width - cell * count) / 2;
    const offsetY = rect.y + (rect.height - cell * count) / 2;

    let path = '';
    for (let row = 0; row < count; row++) {
        for (let col = 0; col < count; col++) {
            if (!qr.isDark(row, col)) continue;
            const x = offsetX + col * cell;
            const y = offsetY + row * cell;
            path += `M${x.toFixed(2)} ${y.toFixed(2)}h${cell.toFixed(2)}v${cell.toFixed(2)}h-${cell.toFixed(2)}z`;
        }
    }

    const group = document.createElementNS(SVG_NS, 'g');

    const quiet = document.createElementNS(SVG_NS, 'rect');
    quiet.setAttribute('x', String(rect.x));
    quiet.setAttribute('y', String(rect.y));
    quiet.setAttribute('width', String(rect.width));
    quiet.setAttribute('height', String(rect.height));
    quiet.setAttribute('fill', '#FFFFFF');
    quiet.setAttribute('rx', String(Math.min(rect.width, rect.height) * 0.06));
    group.appendChild(quiet);

    const modules = document.createElementNS(SVG_NS, 'path');
    modules.setAttribute('d', path);
    modules.setAttribute('fill', '#000000');
    group.appendChild(modules);

    return group;
}

// --------------------------------------------------------------------------
// Fonts (§14.2 step 3)
// --------------------------------------------------------------------------

const fontCache = new Map();

/**
 * Google Fonts CSS with the WOFF2 files inlined as data URIs.
 *
 * An SVG drawn to a canvas cannot fetch anything, so every glyph has to be
 * inside the document. Failure is not fatal: the card still renders in the
 * fallback family.
 */
export async function embedFontCss(families) {
    const wanted = [...new Set(families.filter(Boolean))];
    if (!wanted.length) return '';

    const key = wanted.join('|');
    if (fontCache.has(key)) return fontCache.get(key);

    const promise = (async () => {
        const query = wanted
            .map((f) => 'family=' + encodeURIComponent(f.replace(/ /g, '+')) + ':wght@400;700;800')
            .join('&');

        try {
            const css = await (await fetch(`https://fonts.googleapis.com/css2?${query}&display=swap`, {
                headers: { Accept: 'text/css' },
            })).text();

            const urls = [...css.matchAll(/url\((https:\/\/fonts\.gstatic\.com\/[^)]+\.woff2)\)/g)].map((m) => m[1]);
            const unique = [...new Set(urls)].slice(0, 12);

            const dataUris = new Map();
            await Promise.all(unique.map(async (url) => {
                try {
                    const blob = await (await fetch(url)).blob();
                    dataUris.set(url, await blobToDataUri(blob));
                } catch (e) {
                    /* one missing subset is survivable */
                }
            }));

            let out = css;
            for (const [url, data] of dataUris) out = out.split(url).join(data);

            // Drop any face we could not inline, so the renderer never waits.
            return out.replace(/@font-face\s*\{[^}]*url\(https:\/\/[^)]*\)[^}]*\}/g, '');
        } catch (e) {
            console.warn('[se] could not embed fonts', e);
            return '';
        }
    })();

    fontCache.set(key, promise);

    return promise;
}

export function blobToDataUri(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

// --------------------------------------------------------------------------
// Rendering
// --------------------------------------------------------------------------

/**
 * Resolve every token in `svgText` against `data` and return the SVG source.
 *
 * @param {string} svgText
 * @param {{text?:object, colors?:object, images?:object, qr?:object, flags?:object, fontCss?:string}} data
 */
export function renderTemplate(svgText, data = {}) {
    const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
    const svg = doc.documentElement;

    if (!svg || svg.nodeName === 'parsererror' || svg.getElementsByTagName('parsererror').length) {
        throw new Error('That card template is not valid SVG.');
    }

    const text = data.text || {};
    const colors = data.colors || {};
    const images = data.images || {};
    const qrTargets = data.qr || {};
    const flags = data.flags || {};

    // Boxes first: a text token needs its box before it can be fitted.
    const boxes = new Map();
    for (const { el, kind, name, suffix } of tokenElements(svg)) {
        if (kind !== 'box') continue;
        boxes.set(name, {
            x: parseFloat(el.getAttribute('x') || '0'),
            y: parseFloat(el.getAttribute('y') || '0'),
            width: parseFloat(el.getAttribute('width') || '0'),
            height: parseFloat(el.getAttribute('height') || '0'),
            mode: suffix,
        });
        el.setAttribute('fill', 'none');
        el.setAttribute('stroke', 'none');
    }

    for (const { el, kind, name } of tokenElements(svg)) {
        switch (kind) {
            case 'text':
                if (Object.prototype.hasOwnProperty.call(text, name)) {
                    applyText(el, text[name], boxes.get(name));
                }
                break;

            case 'fill':
                if (colors[name]) el.setAttribute('fill', colors[name]);
                break;

            case 'stroke':
                if (colors[name]) el.setAttribute('stroke', colors[name]);
                break;

            case 'stop':
                if (colors[name]) el.setAttribute('stop-color', colors[name]);
                break;

            case 'image':
                if (images[name]) {
                    el.setAttributeNS(XLINK_NS, 'xlink:href', images[name]);
                    el.setAttribute('href', images[name]);
                } else {
                    el.setAttribute('href', '');
                    el.remove();
                }
                break;

            case 'qr': {
                const value = qrTargets[name];
                if (!value) { el.remove(); break; }
                const rect = {
                    x: parseFloat(el.getAttribute('x') || '0'),
                    y: parseFloat(el.getAttribute('y') || '0'),
                    width: parseFloat(el.getAttribute('width') || '0'),
                    height: parseFloat(el.getAttribute('height') || '0'),
                };
                el.parentNode.replaceChild(qrGroup(value, rect), el);
                break;
            }

            case 'if':
                if (!flags[name]) el.remove();
                break;

            case 'ifnot':
                if (flags[name]) el.remove();
                break;

            default:
                break;
        }
    }

    if (data.fontCss) {
        const style = doc.createElementNS(SVG_NS, 'style');
        style.textContent = data.fontCss;
        svg.insertBefore(style, svg.firstChild);
    }

    return new XMLSerializer().serializeToString(svg);
}

/** The template's own pixel size, from width/height or the viewBox. */
export function templateSize(svgText) {
    const doc = new DOMParser().parseFromString(svgText, 'image/svg+xml');
    const svg = doc.documentElement;

    const w = parseFloat(svg.getAttribute('width') || '0');
    const h = parseFloat(svg.getAttribute('height') || '0');
    if (w > 0 && h > 0) return { width: w, height: h };

    const vb = (svg.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number);

    return { width: vb[2] || 1080, height: vb[3] || 1920 };
}

/**
 * Rasterise resolved SVG source to an image blob at 1:1 (§14.2 step 5).
 *
 * `options` — `{ type, quality, background }` — defaults to the PNG every
 * caller before the programme poster wanted. Pass `{ type: 'image/jpeg' }`
 * for a flat, mail-and-print-friendly file.
 */
export function rasterise(svgSource, width, height, options = {}) {
    // `options` is new and optional: three-argument calls still get a PNG.
    const type = options.type || 'image/png';
    const quality = typeof options.quality === 'number' ? options.quality : 0.92;
    // JPEG has no alpha, so anything transparent comes out black unless the
    // canvas is painted first (§14.3).
    const background = options.background || (type === 'image/jpeg' ? '#000000' : null);

    return new Promise((resolve, reject) => {
        const blob = new Blob([svgSource], { type: 'image/svg+xml;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const image = new Image();

        image.onload = () => {
            try {
                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                if (background) {
                    ctx.fillStyle = background;
                    ctx.fillRect(0, 0, width, height);
                }
                ctx.drawImage(image, 0, 0, width, height);
                URL.revokeObjectURL(url);
                canvas.toBlob(
                    (out) => (out ? resolve(out) : reject(new Error('The image could not be saved.'))),
                    type,
                    quality
                );
            } catch (e) {
                URL.revokeObjectURL(url);
                reject(e);
            }
        };
        image.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('The card could not be drawn.'));
        };

        image.src = url;
    });
}

/**
 * Share the image, falling back to a download — the same approach checkin.php
 * already uses, because Web Share with files is Android-and-modern-iOS only.
 */
export async function shareOrDownload(blob, filename, title) {
    const file = new File([blob], filename, { type: blob.type || 'image/png' });

    try {
        if (navigator.canShare && navigator.canShare({ files: [file] })) {
            await navigator.share({ files: [file], title });
            return 'shared';
        }
    } catch (e) {
        if (e && e.name === 'AbortError') return 'cancelled';
    }

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);

    return 'downloaded';
}
