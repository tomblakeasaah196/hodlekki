// /assets/se/js/core/motion.js
//
// Every GSAP call in the module goes through these helpers (guide §13.1.4),
// so the motion preference is checked in ONE place and a reduced-motion
// viewer can never be handed a spotlight, a parallax or a confetti burst.
//
// GSAP is loaded as a UMD global by the page shell, not imported, so this
// module works whether or not the shell chose to include it.

import { prefersReducedMotion } from './boot.js';

const reduced = prefersReducedMotion();

/** The motion tokens from §13.1.4. */
export const DURATION = {
    micro: 0.15,
    small: 0.24,
    medium: 0.45,
    large: 0.8,
    pop: 0.6,
};

export const EASE = {
    micro: 'power2.out',
    small: 'power3.out',
    medium: 'power3.out',
    large: 'expo.out',
    pop: 'back.out(1.6)',
};

/** The GSAP global, or null when the shell did not load it. */
function gsap() {
    return typeof window !== 'undefined' && window.gsap ? window.gsap : null;
}

export function motionEnabled() {
    return !reduced && gsap() !== null;
}

/**
 * Reveal elements as they enter. With reduced motion (or no GSAP) they are
 * simply shown — never left invisible, which is the failure mode that
 * matters most here.
 */
export function reveal(targets, options = {}) {
    const nodes = typeof targets === 'string'
        ? Array.from(document.querySelectorAll(targets))
        : (Array.isArray(targets) ? targets : [targets]).filter(Boolean);

    if (!nodes.length) return null;

    const g = gsap();
    if (!g) {
        nodes.forEach((n) => { n.style.opacity = '1'; n.style.transform = 'none'; });
        return null;
    }

    if (reduced) {
        // A short fade is allowed; movement is not (§13.1.4).
        return g.fromTo(nodes, { opacity: 0 }, { opacity: 1, duration: 0.2, stagger: 0 });
    }

    return g.fromTo(nodes,
        { opacity: 0, y: options.y ?? 24 },
        {
            opacity: 1,
            y: 0,
            duration: options.duration ?? DURATION.medium,
            ease: options.ease ?? EASE.medium,
            stagger: options.stagger ?? 0.08,
            delay: options.delay ?? 0,
        });
}

/** A confirmation pop. Falls back to no animation under reduced motion. */
export function popIn(target, options = {}) {
    const g = gsap();
    if (!g || !target) return null;

    if (reduced) {
        return g.fromTo(target, { opacity: 0 }, { opacity: 1, duration: 0.2 });
    }

    return g.fromTo(target,
        { scale: 0.85, opacity: 0 },
        {
            scale: 1,
            opacity: 1,
            duration: options.duration ?? DURATION.pop,
            ease: options.ease ?? EASE.pop,
        });
}

/** Count a number up. Reduced motion jumps straight to the value. */
export function countUp(element, to, options = {}) {
    if (!element) return null;

    const g = gsap();
    const format = options.format ?? ((v) => String(Math.round(v)));

    if (!g || reduced) {
        element.textContent = format(to);
        return null;
    }

    const state = { value: options.from ?? 0 };

    return g.to(state, {
        value: to,
        duration: options.duration ?? DURATION.large,
        ease: options.ease ?? EASE.large,
        onUpdate: () => { element.textContent = format(state.value); },
    });
}

/**
 * Pause an infinite animation while it is off-screen or the tab is hidden
 * (§13.1.4). Returns a teardown function.
 */
export function pauseWhenHidden(element, timeline) {
    if (!element || !timeline) return () => {};

    const onVisibility = () => {
        if (document.hidden) timeline.pause();
        else if (element.dataset.seOnScreen === '1') timeline.play();
    };

    let observer = null;
    if (typeof IntersectionObserver === 'function') {
        observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                element.dataset.seOnScreen = entry.isIntersecting ? '1' : '0';
                if (entry.isIntersecting && !document.hidden) timeline.play();
                else timeline.pause();
            }
        }, { threshold: 0.01 });
        observer.observe(element);
    } else {
        element.dataset.seOnScreen = '1';
    }

    document.addEventListener('visibilitychange', onVisibility);

    return () => {
        if (observer) observer.disconnect();
        document.removeEventListener('visibilitychange', onVisibility);
    };
}

/** Haptics (§13.1.5). A no-op on iOS, and only ever after a gesture. */
export function haptic(pattern) {
    if (reduced) return;
    try {
        if (typeof navigator !== 'undefined' && typeof navigator.vibrate === 'function') {
            navigator.vibrate(pattern);
        }
    } catch (e) {
        // Vibration is a nicety; never let it break an interaction.
    }
}

export const HAPTIC = {
    confirm: 10,
    answerLocked: 15,
    teamReveal: [30, 40, 60],
    buzzAccepted: 25,
    upNext: [80, 60, 80],
    presenterSelected: [60, 40, 60, 40, 120],
};
