// /assets/se/js/portal/main.js
//
// The portal entry module (guide §8.6.1, §13.3).
//
// The page is already painted when this runs: includes/special_events/
// portal.php rendered the hero, the chapters, the venue and the FAQ as real
// HTML. Everything here is an enhancement — motion, the countdown, the
// sticky bar, the registration sheet — so a failure to load, a blocked CDN
// or an old browser costs polish, never information (§13.15).

import { boot, net, toast, toasts, dismissToast } from '@se/core/store.js';
import { reveal, motionEnabled, pauseWhenHidden } from '@se/core/motion.js';
import { prefersReducedMotion, formatDateTime } from '@se/core/boot.js';
import { el, qs, qsa, copyText } from './dom.js';
import { openRegistration, beacon } from './sheet.js';
import { startManage } from './manage.js';
import { readLocal } from './storage.js';

const config = boot.value || {};

// --------------------------------------------------------------------------
// Top bar
// --------------------------------------------------------------------------

function startTopbar() {
    const bar = qs('#se-topbar');
    if (!bar) return;

    const onScroll = () => { bar.dataset.scrolled = scrollY > 80 ? '1' : '0'; };
    addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    const share = qs('[data-se-share]', bar);
    if (share && (navigator.share || navigator.clipboard)) {
        share.hidden = false;
        share.addEventListener('click', async () => {
            const url = location.origin + (config.urls?.portal || location.pathname);
            beacon('share', 'topbar');

            if (navigator.share) {
                try { await navigator.share({ title: config.event?.title, text: config.event?.tagline || '', url }); return; } catch (e) { /* cancelled */ }
            }
            toast(await copyText(url) ? 'Link copied.' : url, 'success');
        });
    }

    // Close the menu on Escape or an outside click, like a real menu.
    const menu = qs('.se-topbar-menu', bar);
    if (menu) {
        document.addEventListener('click', (event) => {
            if (menu.open && !menu.contains(event.target)) menu.open = false;
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') menu.open = false;
        });
    }
}

// --------------------------------------------------------------------------
// Hero motion (§13.3 S1)
// --------------------------------------------------------------------------

function animateHero() {
    const hero = qs('#se-hero');
    if (!hero) return;

    const g = window.gsap;
    const title = qs('[data-se-wordmark]', hero);

    if (!motionEnabled() || !g) {
        reveal(qsa('.se-chip, .se-cta-cluster', hero), { y: 10 });
        return;
    }

    // Wordmark: SplitText chars flicker in, then a slow glow breathing.
    if (title && window.SplitText) {
        try {
            const split = new window.SplitText(title, { type: 'chars' });
            g.timeline()
                .fromTo(split.chars,
                    { opacity: 0, filter: 'brightness(2.5)' },
                    { opacity: 1, filter: 'brightness(1)', duration: 0.5, stagger: 0.04, ease: 'power2.out' })
                .to(split.chars, { opacity: 0.35, duration: 0.07, stagger: { each: 0.01, from: 'random' } }, '-=0.2')
                .to(split.chars, { opacity: 1, duration: 0.25 }, '-=0.05');
        } catch (e) {
            title.style.opacity = '1';
        }
    }

    // Spotlight beams sweep +/- 18 degrees, offset from each other.
    for (const beam of qsa('[data-se-beam]', hero)) {
        const left = beam.dataset.seBeam === 'l';
        const timeline = g.to(beam, {
            rotation: left ? 18 : -18,
            duration: left ? 7 : 6.2,
            yoyo: true,
            repeat: -1,
            ease: 'sine.inOut',
            delay: left ? 0 : 1.1,
        });
        pauseWhenHidden(hero, timeline);
    }

    // Gradient mesh drift.
    const mesh = qs('[data-se-mesh]', hero);
    if (mesh) {
        const timeline = g.to(mesh, {
            '--se-mesh-x1': '32%', '--se-mesh-y1': '26%',
            '--se-mesh-x2': '68%', '--se-mesh-y2': '12%',
            '--se-mesh-x3': '42%', '--se-mesh-y3': '74%',
            duration: 14, yoyo: true, repeat: -1, ease: 'sine.inOut',
        });
        pauseWhenHidden(hero, timeline);
    }

    // Equaliser: randomise each bar so it never reads as a loop.
    for (const bar of qsa('.se-eq-bar', hero)) {
        bar.style.animationDuration = `${(0.6 + Math.random() * 1.1).toFixed(2)}s`;
        bar.style.animationDelay = `${(Math.random() * -2).toFixed(2)}s`;
    }

    reveal(qsa('.se-label, .se-chip, .se-cta-cluster', hero), { y: 14, stagger: 0.05 });
}

/** Chapters reveal on enter; on desktop ScrollTrigger scrubs them in. */
function animateChapters() {
    const chapters = qsa('[data-se-chapter]');
    if (!chapters.length) return;

    const g = window.gsap;
    if (!motionEnabled() || !g || !window.ScrollTrigger) {
        for (const chapter of chapters) chapter.style.opacity = '1';
        return;
    }

    g.registerPlugin(window.ScrollTrigger);

    for (const chapter of chapters) {
        g.fromTo(chapter,
            { opacity: 0, y: 32 },
            {
                opacity: 1, y: 0, duration: 0.6, ease: 'power3.out',
                scrollTrigger: { trigger: chapter, start: 'top 85%', once: true },
            });
    }

    for (const line of qsa('[data-se-draw]')) {
        try {
            const length = line.getTotalLength();
            g.fromTo(line,
                { strokeDasharray: length, strokeDashoffset: length },
                {
                    strokeDashoffset: 0, duration: 1.2, ease: 'power2.out',
                    scrollTrigger: { trigger: line, start: 'top 85%', once: true },
                });
        } catch (e) { /* not a path: nothing to draw */ }
    }
}

/** The hero video, skipped on a metered or slow connection (§13.3 S1). */
function startHeroVideo() {
    const video = qs('[data-se-hero-video]');
    if (!video || !video.dataset.src) return;

    const connection = navigator.connection || {};
    if (connection.saveData || ['2g', 'slow-2g'].includes(connection.effectiveType) || prefersReducedMotion()) {
        video.remove();
        return;
    }

    video.src = video.dataset.src;
    video.play?.().catch(() => { /* autoplay refused: the poster stands in */ });
}

// --------------------------------------------------------------------------
// Countdown (§13.3 S1)
// --------------------------------------------------------------------------

// A single digit column: a 0–9 strip inside a one-character window. Rolling
// it is one transform, so four units ticking every second stay cheap.
function digitColumn() {
    const strip = el('span', { class: 'se-count-strip' });
    for (let n = 0; n <= 9; n += 1) strip.append(el('span', { text: String(n) }));

    const column = el('span', { class: 'se-count-digit' }, strip);
    column.dataset.value = '';
    return column;
}

function rollTo(numNode, text) {
    const digits = qsa('.se-count-digit', numNode);

    // The number grew (9 → 10 days): add columns, never rebuild the row.
    while (digits.length < text.length) {
        const column = digitColumn();
        numNode.append(column);
        digits.push(column);
    }
    while (digits.length > text.length) digits.pop().remove();

    text.split('').forEach((char, i) => {
        const column = digits[i];
        if (column.dataset.value === char) return;
        column.dataset.value = char;
        column.dataset.rolling = '1';
        qs('.se-count-strip', column).style.setProperty('--se-digit', char);
        setTimeout(() => { column.dataset.rolling = '0'; }, 860);
    });
}

function startCountdown() {
    const host = qs('[data-se-countdown]');
    if (!host) return;

    const target = new Date(host.dataset.target).getTime();
    if (!Number.isFinite(target)) { host.remove(); return; }

    // The server's clock is the one that matters; the phone's may be wrong.
    const skew = (config.server_ms || Date.now()) - Date.now();
    const units = Object.fromEntries(qsa('[data-unit]', host).map((n) => [n.dataset.unit, n]));

    // Swap the server's plain text for odometer columns once, up front. The
    // rolling strips read as "0123456789" to a screen reader, so the faces
    // are hidden from it and one spoken summary carries the real value.
    Object.values(units).forEach((node) => {
        node.replaceChildren(digitColumn(), digitColumn());
        node.closest('.se-count-face')?.setAttribute('aria-hidden', 'true');
    });
    const spoken = el('li', { class: 'se-sr-only', role: 'status' });
    host.append(spoken);
    host.dataset.ready = '1';

    const tick = () => {
        const left = target - (Date.now() + skew);
        if (left <= 0) {
            host.replaceChildren(el('li', { class: 'se-count-unit' },
                el('span', { class: 'se-count-face' }, el('span', { class: 'se-count-num se-count-now', text: 'Tonight' }))));
            clearInterval(timer);
            return;
        }
        const pad = (v) => String(v).padStart(2, '0');
        rollTo(units.d, String(Math.floor(left / 86400000)).padStart(2, '0'));
        rollTo(units.h, pad(Math.floor((left % 86400000) / 3600000)));
        rollTo(units.m, pad(Math.floor((left % 3600000) / 60000)));
        rollTo(units.s, pad(Math.floor((left % 60000) / 1000)));

        const days = Math.floor(left / 86400000);
        const hours = Math.floor((left % 86400000) / 3600000);
        const spokenText = `Starts in ${days} ${days === 1 ? 'day' : 'days'} and ${hours} ${hours === 1 ? 'hour' : 'hours'}.`;
        if (spoken.textContent !== spokenText) spoken.textContent = spokenText;
    };

    tick();
    const timer = setInterval(tick, 1000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
}

// --------------------------------------------------------------------------
// Sticky bar (§13.3)
// --------------------------------------------------------------------------

function startSticky() {
    const bar = qs('#se-sticky');
    const hero = qs('#se-hero');
    if (!bar || !hero) return;

    bar.hidden = false;

    if (typeof IntersectionObserver !== 'function') {
        bar.dataset.shown = '1';
        return;
    }

    const observer = new IntersectionObserver(([entry]) => {
        bar.dataset.shown = entry.isIntersecting ? '0' : '1';
    }, { threshold: 0.05 });
    observer.observe(hero);

    // A sheet covers the screen; the bar underneath it is only noise.
    new MutationObserver(() => {
        bar.hidden = document.body.dataset.seSheet === '1';
    }).observe(document.body, { attributes: true, attributeFilter: ['data-se-sheet'] });
}

// --------------------------------------------------------------------------
// Calls to action
// --------------------------------------------------------------------------

function bindCtas() {
    for (const button of qsa('[data-se-cta]')) {
        const action = button.dataset.seCta;

        if (action === 'register') {
            if (!config.flags?.registration_ready) {
                button.disabled = true;
                button.title = 'Registration opens shortly.';
                continue;
            }
            button.addEventListener('click', () => openRegistration());
        }

        if (action === 'manage') {
            button.addEventListener('click', () => {
                const cached = readLocal(config.event?.public_id);
                location.href = cached?.manage_url || config.urls?.me || config.urls?.portal || '/';
            });
        }
    }
}

// --------------------------------------------------------------------------
// Toasts and network (§13.1.7)
// --------------------------------------------------------------------------

function startToasts() {
    const host = el('div', { class: 'se-toast-wrap', role: 'status', 'aria-live': 'polite' });
    document.body.appendChild(host);

    toasts.subscribe((list) => {
        host.replaceChildren(...list.map((item) => el('div', {
            class: 'se-toast se-glass',
            'data-kind': item.kind,
            onclick: () => dismissToast(item.id),
        }, item.message)));
    });

    net.subscribe((state) => {
        if (!state.online && state.failures === 1) toast('Reconnecting…', 'error', 3000);
    });

    addEventListener('offline', () => { net.value = { ...net.value, online: false }; });
    addEventListener('online', () => { net.value = { ...net.value, online: true, failures: 0 }; });
}

// --------------------------------------------------------------------------
// Boot
// --------------------------------------------------------------------------

function start() {
    if (!config.event) {
        console.warn('[se] no boot payload; leaving the server-rendered page as it is');
        return;
    }

    document.documentElement.dataset.seReady = '1';

    startToasts();
    startTopbar();
    bindCtas();

    if (config.view === 'manage') {
        startManage();
    } else if (config.view === 'play') {
        import('./play.js').then((module) => module.startPlay(config)).catch(() => toast('Games could not load.', 'error'));
    } else if (config.view === 'checkin') {
        // Loaded on demand: the portal's own critical path must not carry
        // the check-in flow, and /in never needs the hero machinery.
        import('./checkin.js')
            .then((module) => module.startCheckin(config))
            .catch(() => toast('Check-in could not load. Please see the desk.', 'error'));
    } else if (config.view === 'home') {
        startHeroVideo();
        animateHero();
        animateChapters();
        startCountdown();
        startSticky();
        beacon('view', config.attribution?.src || '');
    }

    // Local dates, in case the server and the viewer disagree (§13.14).
    for (const node of qsa('#se-main time[datetime]')) {
        const pretty = formatDateTime(node.getAttribute('datetime'), { dateStyle: 'full', timeStyle: 'short' });
        if (pretty) node.title = pretty;
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
} else {
    start();
}
