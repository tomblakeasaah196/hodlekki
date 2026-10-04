// /assets/se/js/core/theme.js
//
// JS twin of includes/special_events/theme.php (guide §13.2).
//
// PHP is the source of truth: it renders the <style> block on every page.
// This file exists only so the Studio can preview a palette live, before
// anything is saved. The two MUST produce identical tokens for the same
// inputs — tests/special_events/theme_test.php and
// tests/special_events/js/theme.test.mjs run the same vectors through both
// (fixtures/theme_vectors.json). Change one, change both.

// --------------------------------------------------------------------------
// Hex
// --------------------------------------------------------------------------

/** '#RRGGBB' uppercase, or null when the input is not a hex colour. */
export function normalizeHex(value) {
    if (typeof value !== 'string') return null;

    let s = value.trim().toUpperCase().replace(/^#/, '');

    if (/^[0-9A-F]{3}$/.test(s)) {
        s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    }
    if (!/^[0-9A-F]{6}$/.test(s)) return null;

    return '#' + s;
}

/** '#RRGGBB' -> [r, g, b] in 0..1 sRGB. */
export function hexToRgb(hex) {
    const h = normalizeHex(hex) || '#000000';
    const i = parseInt(h.slice(1), 16);

    return [((i >> 16) & 255) / 255, ((i >> 8) & 255) / 255, (i & 255) / 255];
}

/** [r, g, b] in 0..1 -> '#RRGGBB'. Values outside the range are clamped. */
export function rgbToHex(rgb) {
    let out = '#';
    for (const c of rgb) {
        const v = Math.round(Math.max(0, Math.min(1, c)) * 255);
        out += v.toString(16).padStart(2, '0').toUpperCase();
    }

    return out;
}

// --------------------------------------------------------------------------
// Transfer functions
// --------------------------------------------------------------------------

export function srgbToLinear(c) {
    return c <= 0.04045 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
}

export function linearToSrgb(c) {
    return c <= 0.0031308 ? c * 12.92 : 1.055 * Math.pow(c, 1 / 2.4) - 0.055;
}

/** Real cube root, including for negative inputs. */
function cbrt(x) {
    return x < 0 ? -Math.pow(-x, 1 / 3) : Math.pow(x, 1 / 3);
}

// --------------------------------------------------------------------------
// OKLab / OKLCH (§13.2.1)
// --------------------------------------------------------------------------

export function linearToOklab([r, g, b]) {
    const l = 0.4122214708 * r + 0.5363325363 * g + 0.0514459929 * b;
    const m = 0.2119034982 * r + 0.6806995451 * g + 0.1073969566 * b;
    const s = 0.0883024619 * r + 0.2817188376 * g + 0.6299787005 * b;

    const l_ = cbrt(l), m_ = cbrt(m), s_ = cbrt(s);

    return [
        0.2104542553 * l_ + 0.7936177850 * m_ - 0.0040720468 * s_,
        1.9779984951 * l_ - 2.4285922050 * m_ + 0.4505937099 * s_,
        0.0259040371 * l_ + 0.7827717662 * m_ - 0.8086757660 * s_,
    ];
}

export function oklabToLinear([L, A, B]) {
    const l_ = L + 0.3963377774 * A + 0.2158037573 * B;
    const m_ = L - 0.1055613458 * A - 0.0638541728 * B;
    const s_ = L - 0.0894841775 * A - 1.2914855480 * B;

    const l = l_ * l_ * l_, m = m_ * m_ * m_, s = s_ * s_ * s_;

    return [
        4.0767416621 * l - 3.3077115913 * m + 0.2309699292 * s,
        -1.2684380046 * l + 2.6097574011 * m - 0.3413193965 * s,
        -0.0041960863 * l - 0.7034186147 * m + 1.7076147010 * s,
    ];
}

/** '#RRGGBB' -> [L, C, H] with H in degrees 0..360. */
export function hexToOklch(hex) {
    const linear = hexToRgb(hex).map(srgbToLinear);
    const [L, a, b] = linearToOklab(linear);

    const C = Math.sqrt(a * a + b * b);
    const H = C < 1e-9 ? 0 : ((Math.atan2(b, a) * 180) / Math.PI + 360) % 360;

    return [L, C, H];
}

/** True when [L, C, H] lands inside sRGB (with a small tolerance). */
export function oklchInGamut(L, C, H) {
    const rad = (H * Math.PI) / 180;
    const lin = oklabToLinear([L, C * Math.cos(rad), C * Math.sin(rad)]);

    return lin.every((c) => c >= -0.000005 && c <= 1.000005);
}

function oklchToHexUnclipped(L, C, H) {
    const rad = (H * Math.PI) / 180;
    const lin = oklabToLinear([L, C * Math.cos(rad), C * Math.sin(rad)]);

    return rgbToHex(lin.map(linearToSrgb));
}

/**
 * [L, C, H] -> '#RRGGBB', clipped into sRGB. Out-of-gamut results have their
 * chroma reduced by a 12-iteration binary search (§13.2.1), keeping hue and
 * lightness.
 */
export function oklchToHex(L, C, H) {
    L = Math.max(0, Math.min(1, L));
    C = Math.max(0, C);

    if (oklchInGamut(L, C, H)) return oklchToHexUnclipped(L, C, H);

    let lo = 0, hi = C;
    for (let i = 0; i < 12; i++) {
        const mid = (lo + hi) / 2;
        if (oklchInGamut(L, mid, H)) lo = mid; else hi = mid;
    }

    return oklchToHexUnclipped(L, lo, H);
}

// --------------------------------------------------------------------------
// Contrast and similarity
// --------------------------------------------------------------------------

export function relativeLuminance(hex) {
    const [r, g, b] = hexToRgb(hex).map(srgbToLinear);

    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** WCAG contrast ratio between two hex colours: 1.0 … 21.0. */
export function contrastRatio(hexA, hexB) {
    const a = relativeLuminance(hexA), b = relativeLuminance(hexB);

    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

/** OKLab ΔE between two hex colours (§13.2.1). */
export function colorDeltaE(hexA, hexB) {
    const a = linearToOklab(hexToRgb(hexA).map(srgbToLinear));
    const b = linearToOklab(hexToRgb(hexB).map(srgbToLinear));

    return Math.sqrt((a[0] - b[0]) ** 2 + (a[1] - b[1]) ** 2 + (a[2] - b[2]) ** 2);
}

/** '#0B0B0F' or '#FFFFFF', whichever contrasts more with hex (§13.2.2). */
export function bestOnColor(hex) {
    const dark = '#0B0B0F', light = '#FFFFFF';

    return contrastRatio(hex, dark) >= contrastRatio(hex, light) ? dark : light;
}

// --------------------------------------------------------------------------
// Marquee derivation (§13.2.2)
// --------------------------------------------------------------------------

/** Raise OKLCH lightness in 0.02 steps until minRatio is met, capped at lCap. */
export function raiseUntilContrast(hex, against, minRatio, lCap = 0.92) {
    let [L, C, H] = hexToOklch(hex);
    let candidate = oklchToHex(L, C, H);

    while (contrastRatio(candidate, against) < minRatio && L < lCap) {
        L = Math.min(lCap, L + 0.02);
        candidate = oklchToHex(L, C, H);
    }

    return candidate;
}

/**
 * One brand colour and its on-colour. Mirrors se_theme_brand_pair():
 * raise against the background first, then pick black/white, then nudge the
 * brand if even the better of the two is under 4.5 — never back below the
 * lightness that satisfied the background rule.
 */
export function themeBrandPair(brand, bg) {
    let token = raiseUntilContrast(brand, bg, 3.0);
    let on = bestOnColor(token);

    if (contrastRatio(token, on) >= 4.5) return [token, on];

    let [L, C, H] = hexToOklch(token);
    const floorL = L;

    for (let i = 0; i < 40; i++) {
        const step = on === '#FFFFFF' ? -0.02 : 0.02;
        const next = L + step;
        if (next < floorL || next < 0.02 || next > 0.98) break;

        L = next;
        const candidate = oklchToHex(L, C, H);
        on = bestOnColor(candidate);
        if (contrastRatio(candidate, on) >= 4.5 && contrastRatio(candidate, bg) >= 3.0) {
            return [candidate, on];
        }
        token = candidate;
    }

    return [token, bestOnColor(token)];
}

/** Contrast checks the Studio shows as a report (§13.2.3). */
export function themeContrastReport(tokens) {
    const pairs = {
        text_on_bg: ['--se-text', '--se-bg', 4.5],
        muted_on_bg: ['--se-text-muted', '--se-bg', 4.5],
        primary_on_bg: ['--se-primary', '--se-bg', 3.0],
        secondary_on_bg: ['--se-secondary', '--se-bg', 3.0],
        accent_on_bg: ['--se-accent', '--se-bg', 3.0],
        on_primary_on_primary: ['--se-on-primary', '--se-primary', 4.5],
        on_secondary_on_secondary: ['--se-on-secondary', '--se-secondary', 4.5],
        text_on_surface: ['--se-text', '--se-surface', 4.5],
    };

    const report = {};
    for (const [key, [fg, bgKey, min]] of Object.entries(pairs)) {
        const ratio = contrastRatio(tokens[fg], tokens[bgKey]);
        report[key] = {
            ratio: Math.round(ratio * 100) / 100,
            required: min,
            passes: ratio >= min,
        };
    }

    return report;
}

/**
 * Derive the full Marquee token set. Same signature and output shape as
 * se_theme_derive(), minus the `hash` (which only the server needs, for the
 * palette_json cache).
 */
export function themeDerive(primary, secondary, accent = null, preset = 'marquee') {
    const P = normalizeHex(primary) || '#1D356A';
    const S = normalizeHex(secondary) || '#D11920';
    const A = accent !== null && accent !== '' ? normalizeHex(accent) : null;

    const [Lp, Cp, Hp] = hexToOklch(P);
    const [Ls, Cs, Hs] = hexToOklch(S);

    const bg       = oklchToHex(0.14, Math.min(0.035, 0.25 * Cp), Hp);
    const bg2      = oklchToHex(0.10, Math.min(0.030, 0.20 * Cp), Hp);
    const surface  = oklchToHex(0.19, Math.min(0.040, 0.30 * Cp), Hp);
    const surface2 = oklchToHex(0.24, Math.min(0.045, 0.30 * Cp), Hp);

    const text = oklchToHex(0.97, 0.010, Hp);

    let mutedL = 0.80;
    let muted = oklchToHex(mutedL, 0.020, Hp);
    while (contrastRatio(muted, bg) < 4.5 && mutedL < 0.99) {
        mutedL = Math.min(0.99, mutedL + 0.02);
        muted = oklchToHex(mutedL, 0.020, Hp);
    }

    const [primaryToken, onPrimary] = themeBrandPair(P, bg);
    const [secondaryToken, onSecondary] = themeBrandPair(S, bg);

    const accentToken = A !== null
        ? raiseUntilContrast(A, bg, 3.0)
        : oklchToHex(0.78, Math.max(Cp, 0.12), (Hp + 150) % 360);

    const glow  = oklchToHex(Math.min(0.85, Lp + 0.12), Math.min(0.32, 1.25 * Cp), Hp);
    const glow2 = oklchToHex(Math.min(0.85, Ls + 0.12), Math.min(0.32, 1.25 * Cs), Hs);

    const focus = contrastRatio(secondaryToken, bg) >= 3.0 ? secondaryToken : text;

    const tokens = {
        '--se-bg': bg,
        '--se-bg-2': bg2,
        '--se-surface': surface,
        '--se-surface-2': surface2,
        '--se-border': 'rgb(255 255 255 / 0.14)',
        '--se-text': text,
        '--se-text-muted': muted,
        '--se-primary': primaryToken,
        '--se-primary-raw': P,
        '--se-on-primary': onPrimary,
        '--se-secondary': secondaryToken,
        '--se-secondary-raw': S,
        '--se-on-secondary': onSecondary,
        '--se-accent': accentToken,
        '--se-glow': glow,
        '--se-glow-2': glow2,
        '--se-focus': focus,
        '--se-success': oklchToHex(0.78, 0.17, 150),
        '--se-danger': oklchToHex(0.70, 0.19, 25),
        '--se-warning': oklchToHex(0.85, 0.16, 85),
    };

    return {
        preset,
        inputs: { primary: P, secondary: S, accent: A, preset },
        tokens,
        contrast: themeContrastReport(tokens),
    };
}

/** Tokens for one team colour (§13.2.4). Mirrors se_team_tokens(). */
export function teamTokens(colorHex, bgHex, index) {
    const hex = normalizeHex(colorHex) || '#888888';
    const [L, C, H] = hexToOklch(hex);
    const ratio = contrastRatio(hex, bgHex);

    // An achromatic team keeps a neutral glow: its hue is undefined.
    const glowC = C < 0.01 ? 0 : Math.min(0.30, Math.max(C, 0.08) * 1.25);

    return {
        index,
        color: hex,
        on: bestOnColor(hex),
        glow: oklchToHex(Math.min(0.85, Math.max(L, 0.45) + 0.18), glowC, H),
        needs_ring: ratio < 3.0,
        ratio_vs_bg: Math.round(ratio * 100) / 100,
    };
}

/** Warn when two team colours are too close to tell apart. */
export function teamSimilarityWarnings(hexList, threshold = 0.12) {
    const warnings = [];
    for (let i = 0; i < hexList.length; i++) {
        for (let j = i + 1; j < hexList.length; j++) {
            const a = normalizeHex(hexList[i]), b = normalizeHex(hexList[j]);
            if (!a || !b) continue;
            const delta = colorDeltaE(a, b);
            if (delta < threshold) {
                warnings.push({ a: i, b: j, delta_e: Math.round(delta * 10000) / 10000 });
            }
        }
    }

    return warnings;
}

/** Apply a derived theme's tokens to an element (the Studio's live preview). */
export function applyTheme(theme, element = document.documentElement) {
    for (const [name, value] of Object.entries(theme.tokens || {})) {
        element.style.setProperty(name, value);
    }
}
