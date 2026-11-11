<?php
// /modules/monitor/wall.php
/**
 * The live wall: System Health full screen, for a TV or a spare screen
 * (docs/monitor.md, "Live wall").
 *
 * No sign-in and no data in this page. The link carries a key in its
 * fragment (#k=…, never sent to any server); the script polls the snapshot
 * live/monitor/<key>.json, which LiteSpeed serves as a static file, every 5
 * seconds. The monitor rewrites that snapshot on every new error and every
 * cron run. "Make a new link" in Settings replaces the key and deletes the old
 * file, so a link given away stops working. The snapshot holds counts, check
 * states and redacted titles only; the script shows every word of it as text.
 *
 * Its own strict Content-Security-Policy: nothing but this site's own files.
 */

require_once __DIR__ . '/../../includes/site_url.php';
require_once __DIR__ . '/../../includes/ui/bootstrap.php';

header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self'; font-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'");
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title>Live wall · System Health · HOD Lekki</title>
    <link rel="stylesheet" href="<?= ui_e(ui_asset('/assets/ui/app.css')) ?>">
    <link rel="stylesheet" href="<?= ui_e(ui_asset('/assets/css/monitor_wall.css')) ?>">
    <script src="<?= ui_e(ui_asset('/assets/js/monitor_wall.js')) ?>" defer></script>
</head>
<body class="mw">
<main class="mw-frame" aria-live="polite">
    <header class="mw-top">
        <div class="mw-brand">
            <span class="mw-brand__dot" aria-hidden="true"></span>
            <span class="mw-brand__name">HOD Lekki</span>
            <span class="mw-brand__sub">System Health · <span data-mw="site"><?= ui_e(site_host()) ?></span></span>
        </div>
        <div class="mw-state" data-mw="state" data-state="unknown">Connecting…</div>
        <div class="mw-clock">
            <span class="mw-clock__time" data-mw="clock">--:--</span>
            <span class="mw-clock__fresh" data-mw="fresh">waiting for the first snapshot</span>
        </div>
    </header>

    <p class="mw-notice" data-mw="notice" hidden></p>

    <section class="mw-tiles" aria-label="At a glance">
        <div class="mw-tile"><span class="mw-tile__label">Open problems</span><span class="mw-tile__value" data-mw="open">—</span><span class="mw-tile__meta" data-mw="new">—</span></div>
        <div class="mw-tile mw-tile--wide"><span class="mw-tile__label">Errors, last 24 hours</span><span class="mw-tile__value" data-mw="hour">—</span><span class="mw-spark" data-mw="spark" aria-hidden="true"></span></div>
        <div class="mw-tile"><span class="mw-tile__label">hodlekki.org</span><span class="mw-tile__value" data-mw="up-site">—</span><span class="mw-tile__meta" data-mw="up-site-meta">—</span></div>
        <div class="mw-tile"><span class="mw-tile__label">Speed on phones</span><span class="mw-tile__value" data-mw="lcp">—</span><span class="mw-tile__meta" data-mw="lcp-meta">—</span></div>
    </section>

    <div class="mw-columns">
        <section class="mw-panel" aria-labelledby="mw-latest-title">
            <h2 class="mw-panel__title" id="mw-latest-title">Latest open problems</h2>
            <ol class="mw-problems" data-mw="latest"></ol>
            <p class="mw-empty" data-mw="latest-empty" hidden>No open problems. When the site meets an error, it appears here within seconds.</p>
        </section>
        <section class="mw-panel" aria-labelledby="mw-checks-title">
            <h2 class="mw-panel__title" id="mw-checks-title">Health checks</h2>
            <ul class="mw-checks" data-mw="checks"></ul>
        </section>
    </div>

    <footer class="mw-foot">
        <span data-mw="deploy"></span>
        <span data-mw="probe"></span>
    </footer>
</main>
</body>
</html>
