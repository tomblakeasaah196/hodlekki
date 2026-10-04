// /assets/se/js/core/boot.js
//
// Reads the server-provided boot payload (guide §8.6.2).
//
// The data arrives in <script type="application/json" id="se-boot">, which is
// inert: the browser never executes it, so the CSP in §19.7 needs no
// 'unsafe-inline' for script and a hostile value cannot become code.

/** @returns {object} the parsed boot payload, or {} when it is missing. */
export function readBoot(id = 'se-boot') {
    const node = document.getElementById(id);
    if (!node) {
        console.warn('[se] boot payload #' + id + ' is missing');
        return {};
    }

    try {
        return JSON.parse(node.textContent || '{}');
    } catch (e) {
        console.error('[se] boot payload is not valid JSON', e);
        return {};
    }
}

/** Honour the viewer's motion preference once, at boot (§13.1.4). */
export function prefersReducedMotion() {
    return typeof matchMedia === 'function'
        && matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Low-power device or reduced transparency: drop glass and video (§13.1.2). */
export function prefersLowPower() {
    if (typeof matchMedia === 'function'
        && matchMedia('(prefers-reduced-transparency: reduce)').matches) {
        return true;
    }

    return typeof navigator !== 'undefined'
        && typeof navigator.deviceMemory === 'number'
        && navigator.deviceMemory <= 2;
}

/** Format a date in the event's timezone, for every surface (§13.14). */
export function formatDateTime(iso, options = {}) {
    if (!iso) return '';
    try {
        return new Intl.DateTimeFormat('en-NG', {
            timeZone: 'Africa/Lagos',
            dateStyle: 'medium',
            timeStyle: 'short',
            ...options,
        }).format(new Date(iso));
    } catch (e) {
        return String(iso);
    }
}
