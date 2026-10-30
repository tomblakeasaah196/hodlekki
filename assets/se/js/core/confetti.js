// /assets/se/js/core/confetti.js
//
// Confetti on one shared full-screen canvas, for the lobby screens and the
// check-in reveal. No library: a burst is ~120 rectangles with gravity, drag
// and a wobble, drawn in a single requestAnimationFrame loop that stops the
// moment the last piece has fallen, so an idle screen costs nothing.
//
// Reduced motion never gets confetti (§13.1.4): every call is a no-op.

import { prefersReducedMotion } from './boot.js';

const reduced = prefersReducedMotion();

let canvas = null;
let ctx = null;
let pieces = [];
let running = false;
let dpr = 1;

function ensureCanvas() {
    if (canvas) return;
    canvas = document.createElement('canvas');
    canvas.className = 'se-confetti';
    canvas.setAttribute('aria-hidden', 'true');
    document.body.appendChild(canvas);
    ctx = canvas.getContext('2d');
    resize();
    window.addEventListener('resize', resize);
}

function resize() {
    if (!canvas) return;
    dpr = Math.min(2, window.devicePixelRatio || 1);
    canvas.width = Math.round(window.innerWidth * dpr);
    canvas.height = Math.round(window.innerHeight * dpr);
}

function loop() {
    const w = canvas.width;
    const h = canvas.height;
    ctx.clearRect(0, 0, w, h);

    const alive = [];
    for (const p of pieces) {
        p.vx *= 0.985;
        p.vy = p.vy * 0.985 + p.gravity;
        p.x += p.vx;
        p.y += p.vy;
        p.spin += p.vspin;
        p.life -= 1;
        if (p.life <= 0 || p.y > h + 40) continue;
        alive.push(p);

        const fade = Math.min(1, p.life / 40);
        ctx.save();
        ctx.globalAlpha = fade;
        ctx.translate(p.x, p.y);
        ctx.rotate(p.spin);
        ctx.scale(1, Math.cos(p.spin * 1.7));
        ctx.fillStyle = p.color;
        if (p.round) {
            ctx.beginPath();
            ctx.arc(0, 0, p.size * 0.45, 0, Math.PI * 2);
            ctx.fill();
        } else {
            ctx.fillRect(-p.size / 2, -p.size / 4, p.size, p.size / 2);
        }
        ctx.restore();
    }
    pieces = alive;

    if (pieces.length) {
        requestAnimationFrame(loop);
    } else {
        running = false;
        ctx.clearRect(0, 0, w, h);
    }
}

function start() {
    if (running) return;
    running = true;
    requestAnimationFrame(loop);
}

/**
 * A burst from a point (CSS pixels), in the given colours.
 *
 * @param {{x:number, y:number, colors:string[], count?:number, power?:number, spread?:number, angle?:number}} options
 *   angle is the direction in degrees (−90 = straight up), spread the cone.
 */
export function burst({ x, y, colors, count = 110, power = 1, spread = 70, angle = -90 }) {
    if (reduced) return;
    ensureCanvas();

    const scale = dpr * Math.max(0.6, Math.min(1.6, window.innerHeight / 900));
    for (let i = 0; i < count; i++) {
        const a = (angle + (Math.random() - 0.5) * spread * 2) * Math.PI / 180;
        const speed = (6 + Math.random() * 10) * power * scale;
        pieces.push({
            x: x * dpr,
            y: y * dpr,
            vx: Math.cos(a) * speed,
            vy: Math.sin(a) * speed,
            gravity: 0.32 * scale,
            size: (7 + Math.random() * 9) * scale,
            spin: Math.random() * Math.PI,
            vspin: (Math.random() - 0.5) * 0.35,
            color: colors[i % colors.length],
            round: Math.random() < 0.18,
            life: 150 + Math.random() * 80,
        });
    }
    start();
}

/** Confetti falling from the whole top edge — the milestone rain. */
export function rain({ colors, count = 260 }) {
    if (reduced) return;
    ensureCanvas();

    const scale = dpr * Math.max(0.6, Math.min(1.6, window.innerHeight / 900));
    for (let i = 0; i < count; i++) {
        pieces.push({
            x: Math.random() * canvas.width,
            y: -Math.random() * canvas.height * 0.5,
            vx: (Math.random() - 0.5) * 3 * scale,
            vy: (2 + Math.random() * 4) * scale,
            gravity: 0.06 * scale,
            size: (8 + Math.random() * 10) * scale,
            spin: Math.random() * Math.PI,
            vspin: (Math.random() - 0.5) * 0.3,
            color: colors[i % colors.length],
            round: Math.random() < 0.15,
            life: 320 + Math.random() * 120,
        });
    }
    start();
}
