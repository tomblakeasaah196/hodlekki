// /assets/se/js/core/boot.js
//
// Reads the server-provided boot payload (guide §8.6.2).
//
// The data arrives in <script type="application/json" id="se-boot">, which is
// inert: the browser never executes it, so the CSP in §19.7 needs no
// 'unsafe-inline' for script and a hostile value cannot become code.

/**
 * The boot payload for whichever surface is loaded.
 *
 * Public surfaces emit #se-boot (e/index.php); the Studio emits
 * #se-studio-boot (modules/special_events/index.php). Both are read here so
 * that shared code — above all the CSRF token @se/core/api.js attaches to
 * every crew and Studio call — works on either page.
 *
 * @returns {object} the parsed payload, or {} when there is none.
 */
export function readBoot(ids = ['se-boot', 'se-studio-boot']) {
    for (const id of [].concat(ids)) {
        const node = document.getElementById(id);
        if (!node) continue;

        try {
            return JSON.parse(node.textContent || '{}');
        } catch (e) {
            console.error('[se] boot payload #' + id + ' is not valid JSON', e);
            return {};
        }
    }

    return {};
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
