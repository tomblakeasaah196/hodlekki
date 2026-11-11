<?php
// /includes/ui/public.php
/**
 * The public layout: for pages anyone may open without signing in (the
 * Charis Library today; testimonies, connect, verify and live radio later).
 * Same tokens, components, ui.js and strict CSP as the members' shell, but
 * no navigation: a slim bar with the church mark, the page name and one or
 * two actions (sign in, or a way back into the app).
 *
 *   require_once __DIR__ . '/../../includes/db.php';
 *   require_once __DIR__ . '/../../includes/ui/public.php';
 *   $page = ['title' => 'Charis Library', 'mode' => 'strict', 'scripts' => [...],
 *            'head' => $openGraphTags, 'actions' => $barButtonsHtml, 'csp' => [...]];
 *   ui_public_open($page);
 *   …content…
 *   ui_public_close();
 *
 * Keys: title, mode (always strict; the key is here so the UI tests treat the
 * page as strict), scripts, csp, head (trusted HTML for <head>, e.g. Open
 * Graph tags the page escaped itself), actions (trusted HTML for the bar),
 * home (where the mark links; default '/'), indexable (default false).
 */

if (defined('HOD_UI_PUBLIC')) {
    return;
}
define('HOD_UI_PUBLIC', true);

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/security_headers.php';
require_once dirname(__DIR__) . '/csrf.php';

function ui_public_open(array $page): void
{
    $page += ['title' => 'HOD Lekki', 'scripts' => [], 'csp' => [], 'head' => '', 'actions' => '', 'home' => '/', 'indexable' => false];
    $nonce = ui_csp_nonce();
    security_send_csp('strict', $nonce, (array) $page['csp']);
    if (!headers_sent()) {
        // Signed-in visitors see their own borrowing and buttons: never cache.
        header('Cache-Control: no-store, private');
    }
    $GLOBALS['__ui_public'] = $page;
    register_shutdown_function('ui_public_close');

    $pwaNonce = $nonce;
    ?>
<!DOCTYPE html>
<html lang="en" class="ui-html" data-ui-mode="strict">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFFFFF">
<?php require dirname(__DIR__) . '/pwa_head.php'; ?>
    <title><?= ui_e($page['title']) ?> · HOD Lekki</title>
<?php if (!$page['indexable']): ?>
    <meta name="robots" content="noindex, nofollow">
<?php endif; ?>
    <meta name="csrf-token" content="<?= ui_e(csrf_token()) ?>">
<?= $page['head'] ?>
    <link rel="preload" href="/assets/ui/fonts/inter-latin.v1.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= ui_e(ui_asset('/assets/ui/app.css')) ?>">
    <script src="<?= ui_e(ui_asset('/assets/ui/js/ui.js')) ?>" defer></script>
<?php foreach ((array) $page['scripts'] as $src): ?>
    <script src="<?= ui_e(strncmp($src, '/assets/', 8) === 0 && strpos($src, '?') === false ? ui_asset($src) : $src) ?>" defer></script>
<?php endforeach; ?>
</head>
<body class="ui-public">
<a class="ui-skip" href="#main">Skip to content</a>
<header class="ui-public-bar">
    <div class="ui-public-bar__inner">
        <a class="ui-brand ui-brand--light" href="<?= ui_e($page['home']) ?>">
            <span class="ui-brand__mark"><img src="/assets/pwa/icon-96.png" alt="" width="28" height="28"></span>
            <span class="ui-brand__text">
                <span class="ui-brand__name">HOD Lekki</span>
                <span class="ui-brand__sub">Household of David</span>
            </span>
        </a>
        <div class="ui-public-bar__actions"><?= $page['actions'] ?></div>
    </div>
</header>
<main id="main" class="ui-public-main" tabindex="-1">
<?php
}

function ui_public_close(): void
{
    $page = $GLOBALS['__ui_public'] ?? null;
    if ($page === null || !empty($GLOBALS['__ui_public_closed'])) {
        return;
    }
    $GLOBALS['__ui_public_closed'] = true;
    ?>
</main>
<div class="ui-toasts" id="ui-toasts" aria-live="polite" aria-atomic="false" popover="manual" data-ui-keep-live></div>
<?= ui_json_script('hod-ui', [
    'sprite' => ui_sprite_url(),
    'mode' => 'strict',
    'module' => 'public',
    'offline' => false,
    'public' => true,
]) ?>
</body>
</html>
<?php
}
