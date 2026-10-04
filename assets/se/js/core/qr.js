// /assets/se/js/core/qr.js
//
// QR codes as standalone SVG (guide §13.1.7 `QR`, §14.2 step 2).
//
// The share-card engine (@se/core/svg.js) already builds a <g> of modules to
// drop inside a template. The lobby screen and the Studio need the same
// thing as a complete, self-contained <svg> element they can put straight in
// the page — so both call the same vendored encoder, at error correction H,
// which survives a poster being photographed at an angle in a dim stairwell.

import { qrGroup } from './svg.js';

/**
 * A square SVG QR of `value`.
 *
 * @param {string} value the URL to encode
 * @param {{size?: number, quiet?: number}} options — `quiet` is the margin
 *        as a fraction of the side (default 6 %).
 * @returns {SVGSVGElement}
 */
export function qrSvg(value, { size = 512, quiet = 0.06 } = {}) {
    const NS = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(NS, 'svg');

    svg.setAttribute('xmlns', NS);
    svg.setAttribute('viewBox', `0 0 ${size} ${size}`);
    svg.setAttribute('width', String(size));
    svg.setAttribute('height', String(size));
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', 'QR code');

    // qrGroup() paints its own white plate and the quiet zone inside the
    // box it is given, so all this has to decide is how much of the square
    // the code occupies.
    const inset = size * quiet;
    svg.appendChild(qrGroup(value, {
        x: inset,
        y: inset,
        width: size - inset * 2,
        height: size - inset * 2,
    }));

    return svg;
}

/** The same QR as a serialised string, for templates rendered off-DOM. */
export function qrSvgString(value, options = {}) {
    return new XMLSerializer().serializeToString(qrSvg(value, options));
}
