<?php
// /includes/ui/standalone.php
//
// A page that stands on its own, outside the app shell: the email prompt after
// sign-in, the confirm-email link, forgot / reset password. Same tokens,
// components, ui.js and STRICT CSP as rebuilt pages (docs/UI.md), one centred
// card, no navigation. These pages never include header.php (the prompt is
// where header.php sends people, so including it would loop).
//
//   ui_standalone_open('Confirm your email');
//   … card content (ui_field(), ui_button(), ui_banner() …) …
//   ui_standalone_close();

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/components.php';
require_once dirname(__DIR__) . '/csrf.php';
require_once dirname(__DIR__) . '/security_headers.php';

/** $o: scripts (extra /assets/… files), footer (HTML under the card), wide (bool). */
function ui_standalone_open(string $title, array $o = []): void
{
    $nonce = ui_csp_nonce();
    security_send_csp('strict', $nonce, []);
    if (!headers_sent()) {
        header('Cache-Control: no-store, private');
        header('X-Robots-Tag: noindex, nofollow');
    }
    $GLOBALS['__ui_standalone'] = ['footer' => (string) ($o['footer'] ?? '')];
    $pwaNonce = $nonce;
    $pwaQuiet = true;
    ?>
<!DOCTYPE html>
<html lang="en" class="ui-html" data-ui-mode="strict">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF">
<?php require dirname(__DIR__) . '/pwa_head.php'; ?>
    <title><?= ui_e($title) ?> · HOD Lekki</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= ui_e(csrf_token()) ?>">
    <link rel="preload" href="/assets/ui/fonts/inter-latin.v1.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= ui_e(ui_asset('/assets/ui/app.css')) ?>">
    <script src="<?= ui_e(ui_asset('/assets/ui/js/ui.js')) ?>" defer></script>
<?php foreach ((array) ($o['scripts'] ?? []) as $src): ?>
    <script src="<?= ui_e(ui_asset((string) $src)) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="ui-app bg-canvas">
<main id="main" class="min-h-screen flex flex-col items-center justify-center px-4 py-10">
    <div class="w-full <?= !empty($o['wide']) ? 'max-w-lg' : 'max-w-md' ?>">
        <a href="/" class="flex items-center justify-center gap-3 mb-6 text-ink">
            <img src="/assets/pwa/icon-128.png" width="44" height="44" alt="">
            <span class="ui-h2">HOD Lekki</span>
        </a>
        <div class="ui-card">
            <div class="ui-card__body ui-stack">
<?php
}

function ui_standalone_close(): void
{
    $footer = $GLOBALS['__ui_standalone']['footer'] ?? '';
    ?>
            </div>
        </div>
<?php if ($footer !== ''): ?>
        <div class="text-center text-sm text-ink-muted mt-6"><?= $footer ?></div>
<?php endif; ?>
        <p class="text-center text-sm text-ink-muted mt-6">Household of David, Lekki Centre</p>
    </div>
</main>
</body>
</html>
<?php
}

/** A POST form's CSRF check that answers with a page, not JSON (csrf_require() is for endpoints). */
function ui_standalone_csrf_ok(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || csrf_valid();
}
