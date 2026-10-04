// /assets/se/js/core/api.js
//
// The only way this module talks to the server (guide §12.1).
//
// Every POST carries X-SE-Request: 1, which a cross-origin page cannot set
// without a CORS preflight we never grant (§19.3). Crew and Studio calls add
// X-SE-CSRF from the boot payload.

import { boot, net } from './store.js';

/** Raised for any non-success envelope, carrying the §12.1 machine code. */
export class SeApiError extends Error {
    constructor(message, code, data) {
        super(message || 'Something went wrong.');
        this.name = 'SeApiError';
        this.code = code || 'SERVER_ERROR';
        this.data = data || {};
    }

    /** Field errors from a VALIDATION response: {field: reason}. */
    get fields() {
        return this.data.fields || {};
    }
}

const ENDPOINTS = {
    studio:  '/api/special_events_api.php',
    public:  '/api/special_events_public_api.php',
    live:    '/api/special_events_live_api.php',
    display: '/api/special_events_display_api.php',
};

function headersFor(endpoint, extra = {}) {
    const headers = {
        'X-SE-Request': '1',
        Accept: 'application/json',
        ...extra,
    };

    // The public API is unauthenticated and takes no CSRF token.
    if (endpoint !== 'public') {
        const csrf = boot.value?.csrf;
        if (csrf) headers['X-SE-CSRF'] = csrf;
    }

    return headers;
}

function markNet(ok) {
    const current = net.value;
    net.value = ok
        ? { online: true, lastOkAt: Date.now(), failures: 0 }
        : { online: false, lastOkAt: current.lastOkAt, failures: current.failures + 1 };
}

/**
 * Call one action and return its `data`.
 *
 * @param {string} endpoint 'studio' | 'public' | 'live' | 'display'
 * @param {string} action
 * @param {object} payload
 * @throws {SeApiError}
 */
export async function call(endpoint, action, payload = {}) {
    const url = ENDPOINTS[endpoint];
    if (!url) throw new SeApiError('Unknown endpoint: ' + endpoint, 'BAD_REQUEST');

    let response;
    try {
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headersFor(endpoint, { 'Content-Type': 'application/json' }),
            body: JSON.stringify({ action, ...payload }),
        });
    } catch (e) {
        markNet(false);
        throw new SeApiError('You appear to be offline. Please try again.', 'NETWORK');
    }

    // 429 carries Retry-After; the body still has code: RATE_LIMITED.
    let body;
    try {
        body = await response.json();
    } catch (e) {
        markNet(false);
        throw new SeApiError('The server sent an unreadable reply.', 'SERVER_ERROR');
    }

    markNet(true);

    if (body.status !== 'success') {
        throw new SeApiError(body.message, body.code, body.data);
    }

    return body.data || {};
}

/**
 * Upload files with the same envelope. Non-file fields travel as a single
 * JSON `payload` part, which the server merges (se_request_body()).
 */
export async function upload(endpoint, action, payload = {}, files = {}) {
    const url = ENDPOINTS[endpoint];
    if (!url) throw new SeApiError('Unknown endpoint: ' + endpoint, 'BAD_REQUEST');

    const form = new FormData();
    form.append('action', action);
    form.append('payload', JSON.stringify({ action, ...payload }));
    for (const [name, file] of Object.entries(files)) {
        if (file) form.append(name, file);
    }

    let response;
    try {
        // No Content-Type here: the browser must set the multipart boundary.
        response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: headersFor(endpoint),
            body: form,
        });
    } catch (e) {
        markNet(false);
        throw new SeApiError('The upload could not be sent. Please try again.', 'NETWORK');
    }

    let body;
    try {
        body = await response.json();
    } catch (e) {
        markNet(false);
        throw new SeApiError('The server sent an unreadable reply.', 'SERVER_ERROR');
    }

    markNet(true);

    if (body.status !== 'success') {
        throw new SeApiError(body.message, body.code, body.data);
    }

    return body.data || {};
}

/** Shorthand for the Studio endpoint, which is most of this PR's traffic. */
export const studio = (action, payload) => call('studio', action, payload);
export const studioUpload = (action, payload, files) => upload('studio', action, payload, files);
