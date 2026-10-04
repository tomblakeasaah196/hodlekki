// /assets/se/js/portal/main.js
//
// The portal entry module (guide §8.6.1). PR1's job is small on purpose: the
// first paint is already server-rendered HTML, so this file only adds the
// progressive touches — motion on the hero, the network pill, and the live
// countdown — and leaves the Marquee portal and registration to PR2.
//
// Keeping the entry point real from PR1 means SE_PRELOAD, the import map and
// the CSP nonce are all exercised by the smoke test rather than first tried
// in a later PR.

import { boot, net, toast } from '@se/core/store.js';
import { reveal } from '@se/core/motion.js';
import { formatDateTime } from '@se/core/boot.js';

const config = boot.value || {};

// --------------------------------------------------------------------------
// Hero motion
// --------------------------------------------------------------------------

function animateHero() {
    const main = document.getElementById('se-main');
    if (!main) return;

    // Only animate what is already painted: never hide server HTML behind an
    // animation that might not run (§13.15).
    const nodes = main.querySelectorAll('h1, .se-label, .se-h2, dl, p.se-small');
    if (nodes.length) reveal(Array.from(nodes).slice(0, 8), { y: 16, stagger: 0.06 });
}

// --------------------------------------------------------------------------
// Countdown
// --------------------------------------------------------------------------

function startCountdown() {
    if (!config.flags?.show_countdown) return;

    const firstDay = (config.days || [])[0];
    if (!firstDay?.starts_at) return;

    const target = new Date(firstDay.starts_at).getTime();
    if (!Number.isFinite(target) || target <= Date.now()) return;

    const time = document.querySelector('#se-main time[datetime]');
    if (!time) return;

    const host = document.createElement('p');
    host.className = 'se-small';
    host.style.color = 'var(--se-text-muted)';
    host.setAttribute('aria-live', 'off');
    time.parentElement?.appendChild(host);

    const tick = () => {
        const left = target - Date.now();
        if (left <= 0) {
            host.textContent = 'Starting now.';
            clearInterval(timer);
            return;
        }
        const days = Math.floor(left / 86400000);
        const hours = Math.floor((left % 86400000) / 3600000);
        const minutes = Math.floor((left % 3600000) / 60000);

        host.textContent = days > 0
            ? `${days} day${days === 1 ? '' : 's'}, ${hours} hr to go`
            : (hours > 0 ? `${hours} hr ${minutes} min to go` : `${minutes} min to go`);
    };

    tick();
    const timer = setInterval(tick, 30000);
}

// --------------------------------------------------------------------------
// Network pill
// --------------------------------------------------------------------------

function watchNetwork() {
    net.subscribe((state) => {
        if (!state.online && state.failures === 1) {
            toast('Reconnecting…', 'error', 3000);
        }
    });

    addEventListener('offline', () => {
        net.value = { ...net.value, online: false };
    });
    addEventListener('online', () => {
        net.value = { ...net.value, online: true, failures: 0 };
    });
}

// --------------------------------------------------------------------------
// Boot
// --------------------------------------------------------------------------

function start() {
    if (!config.event) {
        // No boot payload: the server-rendered page still stands on its own.
        console.warn('[se] no boot payload; leaving the server-rendered page as it is');
        return;
    }

    document.documentElement.dataset.seReady = '1';

    animateHero();
    startCountdown();
    watchNetwork();

    // Local dates, in case the server and the viewer disagree (§13.14).
    for (const el of document.querySelectorAll('#se-main time[datetime]')) {
        const pretty = formatDateTime(el.getAttribute('datetime'), { dateStyle: 'full', timeStyle: 'short' });
        if (pretty) el.title = pretty;
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
