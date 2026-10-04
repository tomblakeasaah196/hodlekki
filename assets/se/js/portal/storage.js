// /assets/se/js/portal/storage.js
//
// The device's own cached summary (guide §13.4, last bullet).
//
// localStorage throws in Safari private mode and when storage is full, so
// every access is wrapped. A failure here only costs the "you're registered"
// shortcut — the device cookie and the manage token are the real records.

const KEY = (publicId) => `se:${publicId}`;

export function readLocal(publicId) {
    if (!publicId) return null;
    try {
        return JSON.parse(localStorage.getItem(KEY(publicId)) || 'null');
    } catch (e) {
        return null;
    }
}

export function saveLocal(publicId, patch) {
    if (!publicId) return;
    try {
        const current = readLocal(publicId) || {};
        localStorage.setItem(KEY(publicId), JSON.stringify({ ...current, ...patch, saved_at: Date.now() }));
    } catch (e) {
        /* private mode: carry on without the cache */
    }
}

export function clearLocal(publicId) {
    if (!publicId) return;
    try {
        localStorage.removeItem(KEY(publicId));
    } catch (e) { /* nothing to do */ }
}
