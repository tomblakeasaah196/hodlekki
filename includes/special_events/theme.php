<?php
// /includes/special_events/theme.php
//
// The theme engine (guide §13.2): hex -> OKLab/OKLCH colour maths, the Marquee
// token derivation, WCAG contrast and team colour rings.
//
// This file is the SOURCE OF TRUTH: it renders the <style> block on every /e/
// page. assets/se/js/core/theme.js is a line-for-line twin used only for live
// previews in the Studio, and tests/special_events/theme_test.php plus
// tests/special_events/js/theme.test.mjs assert the two agree on shared
// vectors (fixtures/theme_vectors.json). Change one, change both.

// --------------------------------------------------------------------------
// Hex
// --------------------------------------------------------------------------

/** '#RRGGBB' uppercase, or null when the input is not a hex colour. */
function se_normalize_hex(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }
    $s = strtoupper(trim($value));
    $s = ltrim($s, '#');

    if (preg_match('/^[0-9A-F]{3}$/', $s)) {
        $s = $s[0] . $s[0] . $s[1] . $s[1] . $s[2] . $s[2];
    }
    if (!preg_match('/^[0-9A-F]{6}$/', $s)) {
        return null;
    }

    return '#' . $s;
}

/** '#RRGGBB' -> [r, g, b] in 0..1 sRGB. */
function se_hex_to_rgb(string $hex): array
{
    $h = se_normalize_hex($hex) ?? '#000000';
    $i = (int) hexdec(substr($h, 1));

    return [
        (($i >> 16) & 255) / 255.0,
        (($i >> 8) & 255) / 255.0,
        ($i & 255) / 255.0,
    ];
}

/** [r, g, b] in 0..1 -> '#RRGGBB'. Values outside the range are clamped. */
function se_rgb_to_hex(array $rgb): string
{
    $out = '#';
    foreach ($rgb as $c) {
        $v = (int) round(max(0.0, min(1.0, (float) $c)) * 255.0);
        $out .= strtoupper(str_pad(dechex($v), 2, '0', STR_PAD_LEFT));
    }

    return $out;
}

// --------------------------------------------------------------------------
// Transfer functions
// --------------------------------------------------------------------------

/** sRGB component -> linear light. */
function se_srgb_to_linear(float $c): float
{
    return $c <= 0.04045 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
}

/** Linear light -> sRGB component. */
function se_linear_to_srgb(float $c): float
{
    return $c <= 0.0031308 ? $c * 12.92 : 1.055 * pow($c, 1.0 / 2.4) - 0.055;
}

// --------------------------------------------------------------------------
// OKLab / OKLCH (Björn Ottosson's matrices, §13.2.1)
// --------------------------------------------------------------------------

/** Linear sRGB [r,g,b] -> OKLab [L, a, b]. */
function se_linear_to_oklab(array $rgb): array
{
    [$r, $g, $b] = $rgb;

    $l = 0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b;
    $m = 0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b;
    $s = 0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b;

    $l_ = se_cbrt($l);
    $m_ = se_cbrt($m);
    $s_ = se_cbrt($s);

    return [
        0.2104542553 * $l_ + 0.7936177850 * $m_ - 0.0040720468 * $s_,
        1.9779984951 * $l_ - 2.4285922050 * $m_ + 0.4505937099 * $s_,
        0.0259040371 * $l_ + 0.7827717662 * $m_ - 0.8086757660 * $s_,
    ];
}

/** OKLab [L, a, b] -> linear sRGB [r, g, b] (may be out of gamut). */
function se_oklab_to_linear(array $lab): array
{
    [$L, $A, $B] = $lab;

    $l_ = $L + 0.3963377774 * $A + 0.2158037573 * $B;
    $m_ = $L - 0.1055613458 * $A - 0.0638541728 * $B;
    $s_ = $L - 0.0894841775 * $A - 1.2914855480 * $B;

    $l = $l_ * $l_ * $l_;
    $m = $m_ * $m_ * $m_;
    $s = $s_ * $s_ * $s_;

    return [
        4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
        -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
        -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
    ];
}

/** Real cube root, including for negative inputs. */
function se_cbrt(float $x): float
{
    return $x < 0 ? -pow(-$x, 1.0 / 3.0) : pow($x, 1.0 / 3.0);
}

/** '#RRGGBB' -> [L, C, H] with H in degrees 0..360. */
function se_hex_to_oklch(string $hex): array
{
    $rgb    = se_hex_to_rgb($hex);
    $linear = array_map('se_srgb_to_linear', $rgb);
    [$L, $a, $b] = se_linear_to_oklab($linear);

    $C = sqrt($a * $a + $b * $b);
    $H = ($C < 1e-9) ? 0.0 : fmod(rad2deg(atan2($b, $a)) + 360.0, 360.0);

    return [$L, $C, $H];
}

/**
 * [L, C, H] -> '#RRGGBB', clipped into the sRGB gamut.
 *
 * Out-of-gamut results have their chroma reduced by a 12-iteration binary
 * search (§13.2.1), which keeps hue and lightness and only desaturates —
 * the behaviour the token table assumes.
 */
function se_oklch_to_hex(float $L, float $C, float $H): string
{
    $L = max(0.0, min(1.0, $L));
    $C = max(0.0, $C);

    if (se_oklch_in_gamut($L, $C, $H)) {
        return se_oklch_to_hex_unclipped($L, $C, $H);
    }

    $lo = 0.0;
    $hi = $C;
    for ($i = 0; $i < 12; $i++) {
        $mid = ($lo + $hi) / 2.0;
        if (se_oklch_in_gamut($L, $mid, $H)) {
            $lo = $mid;
        } else {
            $hi = $mid;
        }
    }

    return se_oklch_to_hex_unclipped($L, $lo, $H);
}

/** True when [L, C, H] lands inside sRGB (with a small tolerance). */
function se_oklch_in_gamut(float $L, float $C, float $H): bool
{
    $rad = deg2rad($H);
    $lin = se_oklab_to_linear([$L, $C * cos($rad), $C * sin($rad)]);
    foreach ($lin as $c) {
        if ($c < -0.000005 || $c > 1.000005) {
            return false;
        }
    }

    return true;
}

/** Convert without the gamut search; components are clamped on the way out. */
function se_oklch_to_hex_unclipped(float $L, float $C, float $H): string
{
    $rad = deg2rad($H);
    $lin = se_oklab_to_linear([$L, $C * cos($rad), $C * sin($rad)]);

    return se_rgb_to_hex(array_map('se_linear_to_srgb', $lin));
}

// --------------------------------------------------------------------------
// Contrast and similarity
// --------------------------------------------------------------------------

/** WCAG relative luminance of a hex colour (§13.2.1). */
function se_relative_luminance(string $hex): float
{
    [$r, $g, $b] = array_map('se_srgb_to_linear', se_hex_to_rgb($hex));

    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
}

/** WCAG contrast ratio between two hex colours: 1.0 … 21.0. */
function se_contrast_ratio(string $hexA, string $hexB): float
{
    $a = se_relative_luminance($hexA);
    $b = se_relative_luminance($hexB);
    $hi = max($a, $b);
    $lo = min($a, $b);

    return ($hi + 0.05) / ($lo + 0.05);
}

/** OKLab ΔE between two hex colours (§13.2.1), for similarity warnings. */
function se_color_delta_e(string $hexA, string $hexB): float
{
    $a = se_linear_to_oklab(array_map('se_srgb_to_linear', se_hex_to_rgb($hexA)));
    $b = se_linear_to_oklab(array_map('se_srgb_to_linear', se_hex_to_rgb($hexB)));

    return sqrt(
        ($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2
    );
}

/** '#0B0B0F' or '#FFFFFF', whichever contrasts more with $hex (§13.2.2). */
function se_best_on_color(string $hex): string
{
    $dark  = '#0B0B0F';
    $light = '#FFFFFF';

    return se_contrast_ratio($hex, $dark) >= se_contrast_ratio($hex, $light) ? $dark : $light;
}

// --------------------------------------------------------------------------
// Marquee derivation (§13.2.2)
// --------------------------------------------------------------------------

/**
 * Raise a colour's OKLCH lightness in 0.02 steps until it reaches
 * $minRatio against $against, capped at L = 0.92 (§13.2.2).
 */
function se_raise_until_contrast(string $hex, string $against, float $minRatio, float $lCap = 0.92): string
{
    [$L, $C, $H] = se_hex_to_oklch($hex);
    $candidate   = se_oklch_to_hex($L, $C, $H);

    while (se_contrast_ratio($candidate, $against) < $minRatio && $L < $lCap) {
        $L = min($lCap, $L + 0.02);
        $candidate = se_oklch_to_hex($L, $C, $H);
    }

    return $candidate;
}

/**
 * Derive the full Marquee token set from the event's brand colours.
 *
 * @param string      $primary   '#RRGGBB'
 * @param string      $secondary '#RRGGBB'
 * @param string|null $accent    '#RRGGBB' or null to derive one
 * @return array{tokens: array<string,string>, contrast: array, inputs: array, hash: string, preset: string}
 */
function se_theme_derive(string $primary, string $secondary, ?string $accent = null, string $preset = 'marquee'): array
{
    $P = se_normalize_hex($primary) ?? '#1D356A';
    $S = se_normalize_hex($secondary) ?? '#D11920';
    $A = $accent !== null ? se_normalize_hex($accent) : null;

    [$Lp, $Cp, $Hp] = se_hex_to_oklch($P);
    [$Ls, $Cs, $Hs] = se_hex_to_oklch($S);

    // --- Surfaces -------------------------------------------------------
    $bg        = se_oklch_to_hex(0.14, min(0.035, 0.25 * $Cp), $Hp);
    $bg2       = se_oklch_to_hex(0.10, min(0.030, 0.20 * $Cp), $Hp);
    $surface   = se_oklch_to_hex(0.19, min(0.040, 0.30 * $Cp), $Hp);
    $surface2  = se_oklch_to_hex(0.24, min(0.045, 0.30 * $Cp), $Hp);

    // --- Text -----------------------------------------------------------
    $text = se_oklch_to_hex(0.97, 0.010, $Hp);

    $mutedL = 0.80;
    $muted  = se_oklch_to_hex($mutedL, 0.020, $Hp);
    while (se_contrast_ratio($muted, $bg) < 4.5 && $mutedL < 0.99) {
        $mutedL = min(0.99, $mutedL + 0.02);
        $muted  = se_oklch_to_hex($mutedL, 0.020, $Hp);
    }

    // --- Brand pair -----------------------------------------------------
    [$primaryToken, $onPrimary]     = se_theme_brand_pair($P, $bg);
    [$secondaryToken, $onSecondary] = se_theme_brand_pair($S, $bg);

    // --- Accent ---------------------------------------------------------
    if ($A !== null) {
        $accentToken = se_raise_until_contrast($A, $bg, 3.0);
    } else {
        $accentToken = se_oklch_to_hex(0.78, max($Cp, 0.12), fmod($Hp + 150.0, 360.0));
    }

    // --- Glow -----------------------------------------------------------
    $glow  = se_oklch_to_hex(min(0.85, $Lp + 0.12), min(0.32, 1.25 * $Cp), $Hp);
    $glow2 = se_oklch_to_hex(min(0.85, $Ls + 0.12), min(0.32, 1.25 * $Cs), $Hs);

    // --- Focus ----------------------------------------------------------
    $focus = se_contrast_ratio($secondaryToken, $bg) >= 3.0 ? $secondaryToken : $text;

    // --- Fixed semantic colours ----------------------------------------
    $success = se_oklch_to_hex(0.78, 0.17, 150.0);
    $danger  = se_oklch_to_hex(0.70, 0.19, 25.0);
    $warning = se_oklch_to_hex(0.85, 0.16, 85.0);

    $tokens = [
        '--se-bg'            => $bg,
        '--se-bg-2'          => $bg2,
        '--se-surface'       => $surface,
        '--se-surface-2'     => $surface2,
        '--se-border'        => 'rgb(255 255 255 / 0.14)',
        '--se-text'          => $text,
        '--se-text-muted'    => $muted,
        '--se-primary'       => $primaryToken,
        '--se-primary-raw'   => $P,
        '--se-on-primary'    => $onPrimary,
        '--se-secondary'     => $secondaryToken,
        '--se-secondary-raw' => $S,
        '--se-on-secondary'  => $onSecondary,
        '--se-accent'        => $accentToken,
        '--se-glow'          => $glow,
        '--se-glow-2'        => $glow2,
        '--se-focus'         => $focus,
        '--se-success'       => $success,
        '--se-danger'        => $danger,
        '--se-warning'       => $warning,
    ];

    $inputs = ['primary' => $P, 'secondary' => $S, 'accent' => $A, 'preset' => $preset];

    return [
        'preset'   => $preset,
        'inputs'   => $inputs,
        'tokens'   => $tokens,
        'contrast' => se_theme_contrast_report($tokens),
        'hash'     => hash('sha256', se_json_encode($inputs) . '|' . $preset),
    ];
}

/**
 * One brand colour and its on-colour (§13.2.2).
 *
 * The colour is first raised until it reads against the background (≥ 3.0).
 * Then black or white is chosen, whichever contrasts more. If even the better
 * of the two is under 4.5, the brand lightness is nudged further in the
 * direction that helps — never back below the value that satisfied the
 * background rule, so the two requirements cannot fight each other.
 *
 * @return array{0: string, 1: string} [brandToken, onColor]
 */
function se_theme_brand_pair(string $brand, string $bg): array
{
    $token = se_raise_until_contrast($brand, $bg, 3.0);
    $on    = se_best_on_color($token);

    if (se_contrast_ratio($token, $on) >= 4.5) {
        return [$token, $on];
    }

    [$L, $C, $H] = se_hex_to_oklch($token);
    $floorL      = $L;   // Never go below: this L already satisfies the bg rule.

    for ($i = 0; $i < 40; $i++) {
        // White wants a darker brand, black wants a lighter one.
        $step = ($on === '#FFFFFF') ? -0.02 : 0.02;
        $next = $L + $step;
        if ($next < $floorL || $next < 0.02 || $next > 0.98) {
            break;
        }
        $L         = $next;
        $candidate = se_oklch_to_hex($L, $C, $H);
        $on        = se_best_on_color($candidate);
        if (se_contrast_ratio($candidate, $on) >= 4.5 && se_contrast_ratio($candidate, $bg) >= 3.0) {
            return [$candidate, $on];
        }
        $token = $candidate;
    }

    return [$token, se_best_on_color($token)];
}

/** Contrast checks the Studio shows as a report (§13.2.3, §13.14). */
function se_theme_contrast_report(array $tokens): array
{
    $pairs = [
        'text_on_bg'           => ['--se-text', '--se-bg', 4.5],
        'muted_on_bg'          => ['--se-text-muted', '--se-bg', 4.5],
        'primary_on_bg'        => ['--se-primary', '--se-bg', 3.0],
        'secondary_on_bg'      => ['--se-secondary', '--se-bg', 3.0],
        'accent_on_bg'         => ['--se-accent', '--se-bg', 3.0],
        'on_primary_on_primary' => ['--se-on-primary', '--se-primary', 4.5],
        'on_secondary_on_secondary' => ['--se-on-secondary', '--se-secondary', 4.5],
        'text_on_surface'      => ['--se-text', '--se-surface', 4.5],
    ];

    $report = [];
    foreach ($pairs as $key => [$fg, $bgKey, $min]) {
        $ratio = se_contrast_ratio($tokens[$fg], $tokens[$bgKey]);
        $report[$key] = [
            'ratio'    => round($ratio, 2),
            'required' => $min,
            'passes'   => $ratio >= $min,
        ];
    }

    return $report;
}

// --------------------------------------------------------------------------
// Team colours (§13.2.4)
// --------------------------------------------------------------------------

/**
 * Tokens for one team colour. A team whose colour does not read against the
 * stage background (black on a dark stage) gets `needs_ring`, and the UI then
 * draws a 2 px ring in --se-text so the shape is always defined.
 */
function se_team_tokens(string $colorHex, string $bgHex, int $index): array
{
    $hex = se_normalize_hex($colorHex) ?? '#888888';
    [$L, $C, $H] = se_hex_to_oklch($hex);

    $ratio = se_contrast_ratio($hex, $bgHex);

    // An achromatic team (black, white, grey) keeps a neutral glow: its hue is
    // undefined, so borrowing one would tint a black team pink.
    $glowC = $C < 0.01 ? 0.0 : min(0.30, max($C, 0.08) * 1.25);

    return [
        'index'      => $index,
        'color'      => $hex,
        'on'         => se_best_on_color($hex),
        'glow'       => se_oklch_to_hex(min(0.85, max($L, 0.45) + 0.18), $glowC, $H),
        'needs_ring' => $ratio < 3.0,
        'ratio_vs_bg' => round($ratio, 2),
    ];
}

/**
 * Warn when two team colours are too close to tell apart (§13.13 Teams tab).
 * ΔE below 0.12 in OKLab is roughly "the same colour at a glance".
 */
function se_team_similarity_warnings(array $hexList, float $threshold = 0.12): array
{
    $warnings = [];
    $count    = count($hexList);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = se_normalize_hex($hexList[$i]);
            $b = se_normalize_hex($hexList[$j]);
            if ($a === null || $b === null) {
                continue;
            }
            $delta = se_color_delta_e($a, $b);
            if ($delta < $threshold) {
                $warnings[] = ['a' => $i, 'b' => $j, 'delta_e' => round($delta, 4)];
            }
        }
    }

    return $warnings;
}

// --------------------------------------------------------------------------
// Rendering
// --------------------------------------------------------------------------

/**
 * The CSS custom property declarations for a derived theme, for the
 * <style nonce> block in the page shell (§8.8 step 5).
 */
function se_theme_css_vars(array $theme, array $teams = []): string
{
    $lines = [];
    foreach ($theme['tokens'] as $name => $value) {
        // Token names and values are generated here, never user text, but the
        // belt-and-braces filter keeps a stray character out of the stylesheet.
        $safeName  = preg_replace('/[^a-z0-9\-]/i', '', $name) ?? '';
        $safeValue = preg_replace('/[^#a-z0-9\s().,\/%-]/i', '', (string) $value) ?? '';
        if ($safeName !== '' && $safeValue !== '') {
            $lines[] = "  {$safeName}: {$safeValue};";
        }
    }
    foreach ($teams as $i => $team) {
        $n = (int) ($team['index'] ?? $i);
        $lines[] = "  --team-{$n}: {$team['color']};";
        $lines[] = "  --team-{$n}-on: {$team['on']};";
        $lines[] = "  --team-{$n}-glow: {$team['glow']};";
        $lines[] = "  --team-{$n}-ring: " . ($team['needs_ring'] ? 'rgb(255 255 255 / 0.85)' : 'transparent') . ';';
    }

    return ":root {\n" . implode("\n", $lines) . "\n}\n";
}

/** The event's stored palette, derived and cached in palette_json (§13.2.2). */
function se_event_theme(array $event): array
{
    $stored = se_json_decode($event['palette_json'] ?? null);
    $inputs = [
        'primary'   => se_normalize_hex($event['brand_primary'] ?? '') ?? '#1D356A',
        'secondary' => se_normalize_hex($event['brand_secondary'] ?? '') ?? '#D11920',
        'accent'    => isset($event['brand_accent']) ? se_normalize_hex($event['brand_accent']) : null,
        'preset'    => (string) ($event['theme_preset'] ?? 'marquee'),
    ];
    $hash = hash('sha256', se_json_encode($inputs) . '|' . $inputs['preset']);

    if (($stored['hash'] ?? null) === $hash && !empty($stored['tokens'])) {
        return $stored;
    }

    return se_theme_derive($inputs['primary'], $inputs['secondary'], $inputs['accent'], $inputs['preset']);
}
