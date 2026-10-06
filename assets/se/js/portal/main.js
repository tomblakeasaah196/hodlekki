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

/** Seconds of stillness between two plays of the wordmark flicker. */
const HERO_WORDMARK_REPEAT_DELAY = 5;

function animateHero() {
    const hero = qs('#se-hero');
    if (!hero) return;

    const g = window.gsap;
    const title = qs('[data-se-wordmark]', hero);

    if (!motionEnabled() || !g) {
        reveal(qsa('.se-chip, .se-cta-cluster', hero), { y: 10 });
        return;
    }

    // Wordmark: SplitText chars flicker in, then the whole figure replays
    // five seconds after it settles, so the event name keeps announcing
    // itself. The loop is paused off-screen and in a background tab by
    // pauseWhenHidden(), like every other infinite timeline in the hero.
    if (title && window.SplitText) {
        try {
            const split = new window.SplitText(title, { type: 'chars' });
            const timeline = g.timeline({ repeat: -1, repeatDelay: HERO_WORDMARK_REPEAT_DELAY })
                .fromTo(split.chars,
                    { opacity: 0, filter: 'brightness(2.5)' },
                    { opacity: 1, filter: 'brightness(1)', duration: 0.5, stagger: 0.04, ease: 'power2.out' })
                .to(split.chars, { opacity: 0.35, duration: 0.07, stagger: { each: 0.01, from: 'random' } }, '-=0.2')
                .to(split.chars, { opacity: 1, duration: 0.25 }, '-=0.05');
            pauseWhenHidden(hero, timeline);
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

// --------------------------------------------------------------------------
// Chapter art (§13.3 S3)
// --------------------------------------------------------------------------
//
// One timeline per icon, built when the card first scrolls into view and
// then left looping quietly. Every loop goes through pauseWhenHidden(), so a
// phone scrolled past the section is not still animating four SVGs.
//
// Everything here is enhancement: the markup is already painted and legible,
// and under prefers-reduced-motion (or without GSAP) nothing below runs.

/** The microphone: the halo ignites, the glow breathes, the equaliser sings. */
function animateMic(art, g) {
    const ring = qs('[data-se-mic-ring]', art);
    const capsule = qs('[data-se-mic-capsule]', art);
    const glow = qs('[data-se-mic-glow]', art);
    const bars = qsa('[data-se-mic-bar]', art);

    const spin = [ring, glow].filter(Boolean);
    if (spin.length) g.set(spin, { transformOrigin: '50% 43%' });

    const intro = g.timeline();
    if (capsule) {
        intro.fromTo(capsule, { scale: 0.86, opacity: 0.4, transformOrigin: '50% 60%' },
            { scale: 1, opacity: 1, duration: 0.5, ease: 'back.out(2)' });
    }
    if (ring) {
        intro.fromTo(ring, { scale: 0.65, opacity: 0, rotation: -45 },
            { scale: 1, opacity: 0.55, rotation: 0, duration: 0.8, ease: 'power3.out' }, 0.1);
    }
    if (glow) {
        intro.fromTo(glow, { opacity: 0, scale: 0.7 },
            { opacity: 0.2, scale: 1, duration: 0.7, ease: 'power2.out' }, 0.2);
    }
    if (bars.length) {
        intro.fromTo(bars, { scaleY: 0.1, transformOrigin: '50% 50%' },
            { scaleY: 1, duration: 0.4, ease: 'power2.out', stagger: 0.06 }, 0.3);
    }

    // Idle: the halo turns slowly, the glow pulses like a warm lamp and each
    // bar moves on its own clock so the equaliser never reads as a loop.
    const idle = g.timeline({ repeat: -1 });
    if (ring) idle.to(ring, { rotation: 360, duration: 44, ease: 'none' }, 0);
    if (glow) idle.to(glow, { opacity: 0.32, scale: 1.08, duration: 2.2, yoyo: true, repeat: -1, ease: 'sine.inOut' }, 0);
    bars.forEach((bar, i) => {
        idle.to(bar, {
            scaleY: 0.25 + Math.random() * 0.9,
            duration: 0.45 + Math.random() * 0.5,
            yoyo: true,
            repeat: -1,
            ease: 'sine.inOut',
            transformOrigin: '50% 50%',
            delay: i * 0.08,
        }, 0);
    });

    return idle;
}

/** The games: the cards deal out, the buzzer pings, the score ticks. */
function animateCards(art, g) {
    const cards = qsa('[data-se-card]', art);
    const ring = qs('[data-se-buzz-ring]', art);
    const buzz = qs('[data-se-buzz]', art);

    const intro = g.timeline();
    cards.forEach((card, i) => {
        const angle = Number(card.dataset.seCard || 0);
        g.set(card, { transformOrigin: '50% 100%' });
        intro.fromTo(card,
            { rotation: 0, x: 24 - i * 6, y: 14, opacity: 0 },
            { rotation: angle, x: 0, y: 0, opacity: 1, duration: 0.55, ease: 'back.out(1.4)' },
            i * 0.09);
    });
    if (buzz) {
        intro.fromTo(buzz, { scale: 0.5, opacity: 0, transformOrigin: '80% 72%' },
            { scale: 1, opacity: 1, duration: 0.4, ease: 'back.out(2)' }, 0.35);
    }

    const idle = g.timeline({ repeat: -1, repeatDelay: 1.1 });
    if (ring) {
        g.set(ring, { transformOrigin: '50% 50%' });
        idle.fromTo(ring, { scale: 0.8, opacity: 0.6 },
            { scale: 1.7, opacity: 0, duration: 1.1, ease: 'power2.out' }, 0);
    }
    if (buzz) {
        idle.to(buzz, { y: 3, duration: 0.12, yoyo: true, repeat: 1, ease: 'power2.inOut' }, 0);
    }
    // The top card lifts and falls back, as if somebody is about to play it.
    if (cards.length) {
        const top = cards[cards.length - 1];
        idle.to(top, { y: -8, rotation: Number(top.dataset.seCard || 0) + 3, duration: 0.8, yoyo: true, repeat: 1, ease: 'sine.inOut' }, 0.4);
    }

    return idle;
}

/**
 * The teams: four separate circles arrive from four different places,
 * converge until they overlap into one light, then settle into an orbit that
 * keeps breathing in and out. Four people from everywhere, one body.
 *
 * The CSS keyframes do a plainer version of this on their own; taking over
 * here sets data-js on the orbit so the two never fight for the transform.
 */
function animateOrbit(art, g) {
    const orbit = qs('[data-se-orbit]', art);
    if (!orbit) return null;

    const orbs = qsa('.se-orb', orbit);
    const core = qs('.se-orbit-core', orbit);
    if (!orbs.length) return null;

    orbit.dataset.js = '1';

    const size = orbit.getBoundingClientRect().width || 208;
    const radius = size * 0.34;
    const step = (Math.PI * 2) / orbs.length;
    const seat = orbs.map((_, i) => ({
        x: Math.cos(i * step - Math.PI / 2) * radius,
        y: Math.sin(i * step - Math.PI / 2) * radius,
    }));

    // Everyone starts off the card, from their own direction.
    orbs.forEach((orb, i) => {
        g.set(orb, {
            x: seat[i].x * 3.4,
            y: seat[i].y * 3.4,
            scale: 0.45,
            opacity: 0,
        });
    });
    if (core) g.set(core, { scale: 0.2, opacity: 0 });

    const intro = g.timeline();
    intro.to(orbs, {
        x: 0, y: 0, scale: 1.08, opacity: 1,
        duration: 1.15, ease: 'power3.inOut', stagger: 0.07,
    });
    if (core) {
        // The fusion: one light where the four met.
        intro.to(core, { scale: 1.25, opacity: 1, duration: 0.5, ease: 'power2.out' }, '-=0.25')
            .to(core, { scale: 1, duration: 0.6, ease: 'sine.out' });
    }
    intro.to(orbs, {
        x: (i) => seat[i].x,
        y: (i) => seat[i].y,
        scale: 1,
        duration: 0.9,
        ease: 'power2.inOut',
    }, '-=0.35');

    // Idle: breathe back together and apart, for ever, slowly.
    const idle = g.timeline({ repeat: -1, repeatDelay: 1.4, paused: true });
    idle.to(orbs, { x: 0, y: 0, scale: 1.1, duration: 2.6, ease: 'sine.inOut', stagger: 0.04 })
        .to(orbs, { x: (i) => seat[i].x, y: (i) => seat[i].y, scale: 1, duration: 2.6, ease: 'sine.inOut', stagger: 0.04 });
    if (core) {
        idle.to(core, { scale: 1.2, opacity: 1, duration: 2.6, ease: 'sine.inOut' }, 0)
            .to(core, { scale: 0.9, opacity: 0.75, duration: 2.6, ease: 'sine.inOut' }, 2.6);
    }

    // The idle loop is wired up only once the arrival has finished: handing
    // it to pauseWhenHidden() straight away would start it on top of the
    // intro, and the two would fight over the same four transforms.
    intro.eventCallback('onComplete', () => {
        idle.play();
        pauseWhenHidden(art, idle);
    });

    return null;
}

/** Everything else in the catalogue: one entrance, one quiet idle. */
function animateGenericIcon(art, g, icon) {
    const svg = qs('svg', art);
    if (!svg) return null;

    g.fromTo(svg, { scale: 0.9, opacity: 0.3, transformOrigin: '50% 55%' },
        { scale: 1, opacity: 1, duration: 0.6, ease: 'back.out(1.4)' });

    const idle = g.timeline({ repeat: -1 });

    const hands = qs('[data-se-clock-hands]', art);
    if (hands) {
        idle.to(hands, { rotation: 360, duration: 12, ease: 'none', transformOrigin: '50% 35%' }, 0);
    }

    const notes = qsa('[data-se-note]', art);
    if (notes.length) {
        idle.to(notes, { y: -6, duration: 1.6, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.22 }, 0);
    }

    const floats = qsa('[data-se-float]', art);
    if (floats.length) {
        idle.to(floats, { opacity: 0.15, x: 5, duration: 1.4, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.3 }, 0);
    }

    const shine = qs('[data-se-shine]', art);
    if (shine) {
        idle.fromTo(shine, { opacity: 0.15, x: -6 }, { opacity: 0.85, x: 6, duration: 1.3, yoyo: true, repeat: -1, ease: 'sine.inOut' }, 0);
    }

    const lid = qs('[data-se-lid]', art);
    if (lid) {
        idle.to(lid, { y: -9, rotation: -3, duration: 0.9, yoyo: true, repeat: -1, repeatDelay: 1.2, ease: 'power2.inOut', transformOrigin: '50% 100%' }, 0);
    }

    const shutter = qs('[data-se-shutter]', art);
    const flash = qs('[data-se-flash]', art);
    if (shutter) {
        idle.to(shutter, { scale: 0.82, duration: 0.14, yoyo: true, repeat: 1, ease: 'power2.inOut', transformOrigin: '50% 50%', repeatDelay: 0 }, 0)
            .to({}, { duration: 2.4 });
    }
    if (flash) {
        idle.fromTo(flash, { opacity: 0.2 }, { opacity: 1, duration: 0.12, yoyo: true, repeat: 1 }, 0);
    }

    const people = qsa('[data-se-person]', art);
    if (people.length) {
        people.forEach((person) => {
            const from = Number(person.dataset.sePerson || 0);
            g.fromTo(person, { x: from, opacity: 0 }, { x: 0, opacity: 1, duration: 0.8, ease: 'power3.out' });
        });
        idle.to(people, { y: -4, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.18 }, 0);
    }

    const flame = qs('[data-se-flame]', art);
    const flameCore = qs('[data-se-flame-core]', art);
    if (flame) {
        idle.to(flame, { scaleY: 1.07, scaleX: 0.96, duration: 0.9, yoyo: true, repeat: -1, ease: 'sine.inOut', transformOrigin: '50% 100%' }, 0);
    }
    if (flameCore) {
        idle.to(flameCore, { opacity: 0.25, duration: 0.7, yoyo: true, repeat: -1, ease: 'sine.inOut' }, 0);
    }

    const glow = qs('[data-se-glow]', art);
    if (glow) {
        idle.fromTo(glow, { opacity: 0.25, scale: 0.9 }, { opacity: 0.8, scale: 1.1, duration: 2.6, yoyo: true, repeat: -1, ease: 'sine.inOut', transformOrigin: '50% 50%' }, 0);
    }

    const steam = qsa('[data-se-steam]', art);
    if (steam.length) {
        idle.fromTo(steam, { opacity: 0.1, y: 6 }, { opacity: 0.7, y: -4, duration: 1.8, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.25 }, 0);
    }

    const twinkles = qsa('[data-se-twinkle]', art);
    if (twinkles.length) {
        twinkles.forEach((star, i) => {
            idle.fromTo(star,
                { scale: 0.75, opacity: 0.35, transformOrigin: '50% 50%' },
                { scale: 1.1, opacity: 1, duration: 0.9 + i * 0.35, yoyo: true, repeat: -1, ease: 'sine.inOut' }, i * 0.4);
        });
    }

    const dots = qsa('[data-se-dot]', art);
    if (dots.length) {
        idle.fromTo(dots, { scale: 0.6, transformOrigin: '50% 50%' },
            { scale: 1.25, duration: 1.1, yoyo: true, repeat: -1, ease: 'sine.inOut', stagger: 0.35 }, 0);
    }

    return idle.duration() > 0 ? idle : null;
}

/** Draw an SVG stroke on, for the paths marked data-se-draw. */
function drawStroke(line, g) {
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

/** Chapters reveal on enter; each card's art then animates on its own. */
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

    for (const art of qsa('[data-se-chapter-art]')) {
        const icon = art.dataset.seChapterArt || '';
        let built = false;

        window.ScrollTrigger.create({
            trigger: art,
            start: 'top 88%',
            once: true,
            onEnter: () => {
                if (built) return;
                built = true;

                let idle = null;
                if (icon === 'mic') idle = animateMic(art, g);
                else if (icon === 'cards') idle = animateCards(art, g);
                else if (icon === 'orbit') idle = animateOrbit(art, g);
                else idle = animateGenericIcon(art, g, icon);

                // A photograph behind the card drifts a hair as it arrives,
                // which is what makes it feel like a window rather than a
                // sticker. It never moves far enough to crop the subject.
                const bg = qs('.se-chapter-art-bg', art);
                if (bg) {
                    g.fromTo(bg, { scale: 1.14, opacity: 0 }, { scale: 1.06, opacity: 1, duration: 1.4, ease: 'power2.out' });
                }

                if (idle) pauseWhenHidden(art, idle);
            },
        });
    }

    for (const line of qsa('[data-se-draw]')) drawStroke(line, g);
}

/**
 * Portal music (§13.3 S0b), loaded on demand.
 *
 * The engine is ~9 KB of audio graph and gesture plumbing that most visits
 * never need — there is no dock unless the producer uploaded a track — so it
 * stays off the critical path and is fetched only when one is in the DOM.
 */
function startPortalMusic() {
    if (!config.music?.enabled || !document.getElementById('se-music')) return;

    import('./music.js')
        .then((module) => module.startMusic(config))
        .catch(() => { /* no music is not an error worth showing anybody */ });
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
        startPortalMusic();
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
