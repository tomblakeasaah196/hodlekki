<?php
// /includes/ui/bootstrap.php
/**
 * The front-end foundation's PHP side: escaping, asset URLs, icons, the CSP
 * nonce and JSON boot data. Everything that prints markup for the shared
 * components builds on these. Catalogue and rules: docs/UI.md.
 *
 * Safe to require more than once, from any page, before or after db.php. It
 * touches no database and starts no session.
 */

if (defined('HOD_UI_BOOTSTRAP')) {
    return;
}
define('HOD_UI_BOOTSTRAP', true);

/** HTML-escape for text and attribute values. */
function ui_e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * A static file's URL with its content hash, e.g. /assets/ui/app.css?v=1e92ea448dd2.
 * Hashes come from includes/ui/asset_manifest.php (bin/build_ui.sh); a file
 * missing from it is hashed on the fly, so a new script never ships uncached
 * forever — but add it to the manifest's list all the same.
 */
function ui_asset(string $path): string
{
    static $manifest = null;
    if ($manifest === null) {
        $file = __DIR__ . '/asset_manifest.php';
        $manifest = is_file($file) ? (array) require $file : [];
    }

    $hash = $manifest[$path] ?? null;
    if ($hash === null) {
        $disk = dirname(__DIR__, 2) . $path;
        $hash = is_file($disk) ? substr((string) md5_file($disk), 0, 12) : '0';
        $manifest[$path] = $hash;
    }

    return $path . '?v=' . $hash;
}

/** The icon sprite's URL (hashed). */
function ui_sprite_url(): string
{
    return ui_asset('/assets/ui/icons.svg');
}

/**
 * An icon from the sprite. Decorative by default (aria-hidden); pass a label
 * to make it speak for itself when it stands alone.
 *
 *   ui_icon('search')
 *   ui_icon('trash-2', 'ui-icon--sm')
 *   ui_icon('circle-alert', '', 'Warning')
 */
function ui_icon(string $name, string $class = '', ?string $label = null): string
{
    $cls = trim('ui-icon ' . $class);
    $a11y = $label === null || $label === ''
        ? ' aria-hidden="true" focusable="false"'
        : ' role="img" aria-label="' . ui_e($label) . '"';

    return '<svg class="' . ui_e($cls) . '"' . $a11y . '><use href="' . ui_e(ui_sprite_url()) . '#' . ui_e($name) . '"></use></svg>';
}

/** Render an attribute array: ['data-action' => 'save', 'disabled' => true]. */
function ui_attrs(array $attrs): string
{
    $out = '';
    foreach ($attrs as $name => $value) {
        if ($value === null || $value === false) {
            continue;
        }
        if (!preg_match('/^[a-zA-Z_:][a-zA-Z0-9_.:-]*$/', (string) $name)) {
            continue;
        }
        if (stripos((string) $name, 'on') === 0) {
            // Inline event handlers are banned by the CSP: wire behaviour
            // with data-action and assets/ui/js/ui.js instead.
            continue;
        }
        $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . ui_e($value) . '"';
    }

    return $out;
}

/** One nonce per request, for the few inline blocks that genuinely need one. */
function ui_csp_nonce(): string
{
    static $nonce = null;

    return $nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

/**
 * Data for the page's scripts, as an inert JSON block (never executed, so
 * the CSP needs no exception for it). Read it with HOD.data('id').
 */
function ui_json_script(string $id, $data): string
{
    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR
    );

    return '<script type="application/json" id="' . ui_e($id) . '">' . $json . '</script>';
}

/** Up to two initials for an avatar. */
function ui_initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $parts = array_values(array_filter($parts, static fn ($p) => $p !== ''));
    if ($parts === []) {
        return '?';
    }
    $first = mb_substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

    return mb_strtoupper($first . $last);
}

/** A stable avatar colour (1–6) for a name, so a person keeps theirs everywhere. */
function ui_avatar_tone(string $name): string
{
    $tones = ['ui-avatar--c1', 'ui-avatar--c2', 'ui-avatar--c3', 'ui-avatar--c4', 'ui-avatar--c5', 'ui-avatar--c6'];

    return $tones[crc32(mb_strtolower(trim($name))) % 6];
}

/** "Resident_Pastor" → "Resident Pastor". */
function ui_role_label(string $role): string
{
    return trim(str_replace('_', ' ', $role));
}

require_once __DIR__ . '/components.php';
