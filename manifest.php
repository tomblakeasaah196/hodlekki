<?php
// /manifest.php
/**
 * Web App Manifest for HOD Lekki.
 *
 * Served from PHP rather than a static .webmanifest for one practical reason:
 * the cPanel host's Apache does not know the .webmanifest extension, the root
 * .htaccess is git-ignored (it mirrors cPanel's MultiPHP output and must not
 * be hand-edited — see AGENTS.md), and a manifest served as text/html is
 * rejected outright by some Android builds. Setting the header here removes
 * the whole class of problem and costs one PHP process.
 *
 * It takes no session and reads no database: the manifest is fetched without
 * credentials by design, and must look identical to every visitor.
 */

// Guarded so the test suite can require this file for its icon checks
// without a stream of "headers already sent" warnings.
if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
}

/** Bump when the icon set changes, so phones re-read the artwork. */
const PWA_ASSET_VERSION = '1';

$icon = static function (int $size, string $purpose = 'any'): array {
    $file = $purpose === 'maskable' ? "maskable-{$size}" : "icon-{$size}";

    return [
        'src'     => "/assets/pwa/{$file}.png?v=" . PWA_ASSET_VERSION,
        'sizes'   => "{$size}x{$size}",
        'type'    => 'image/png',
        'purpose' => $purpose,
    ];
};

echo json_encode([
    // A stable id keeps this the SAME installed app across future changes to
    // start_url — change it and every phone treats the next install as a
    // second, separate app.
    'id'          => '/',
    'name'        => 'HOD Lekki',
    'short_name'  => 'HOD Lekki',
    'description' => 'Household of David Lekki Centre — your church in your pocket. '
        . 'Services, departments, giving, follow-up and instant alerts.',

    'start_url' => '/index.php?source=pwa',
    'scope'     => '/',

    'display' => 'standalone',
    // Window-controls-overlay first so the installed desktop app gets the
    // whole window; phones ignore it and fall through to standalone.
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],

    // Not portrait-locked: the Special Events stage and lobby displays are
    // landscape screens served from this same origin and scope.
    'orientation' => 'any',

    'background_color' => '#0B1733',
    'theme_color'      => '#1D356A',

    'lang' => 'en-NG',
    'dir'  => 'ltr',

    'categories'                  => ['lifestyle', 'social', 'education'],
    'prefer_related_applications' => false,

    'icons' => [
        $icon(48),
        $icon(72),
        $icon(96),
        $icon(128),
        $icon(144),
        $icon(152),
        $icon(167),
        $icon(192),
        $icon(256),
        $icon(384),
        $icon(512),
        $icon(192, 'maskable'),
        $icon(512, 'maskable'),
    ],

    // Long-press the home-screen icon.
    'shortcuts' => [
        [
            'name'        => 'My Portal',
            'short_name'  => 'Portal',
            'description' => 'Your giving, prayer requests and assignments',
            'url'         => '/modules/member_portal/index.php?source=shortcut',
            'icons'       => [$icon(96)],
        ],
        [
            'name'        => 'Check In',
            'short_name'  => 'Check In',
            'description' => 'Check in to today\'s service',
            'url'         => '/checkin.php?source=shortcut',
            'icons'       => [$icon(96)],
        ],
        [
            'name'        => 'Sermons',
            'short_name'  => 'Sermons',
            'description' => 'Listen to the latest messages',
            'url'         => '/sermons.php?source=shortcut',
            'icons'       => [$icon(96)],
        ],
        [
            'name'        => 'Connect',
            'short_name'  => 'Connect',
            'description' => 'Say hello and join a department',
            'url'         => '/connect.php?source=shortcut',
            'icons'       => [$icon(96)],
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
