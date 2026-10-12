<?php
// /includes/pwa_head.php
/**
 * ============================================================================
 * PWA <head> PARTIAL
 * ----------------------------------------------------------------------------
 * Include this from inside the <head> of any page that should be part of the
 * installable app, then include includes/pwa_boot.php as the first thing
 * inside <body>:
 *
 *     <head>
 *         …
 *         <?php require_once __DIR__ . '/includes/pwa_head.php'; ?>
 *     </head>
 *     <body …>
 *         <?php require_once __DIR__ . '/includes/pwa_boot.php'; ?>
 *
 * It emits the manifest link, the Apple home-screen metadata and launch
 * images, the critical CSS for the preloader, and the client runtime. It
 * touches no database and assumes no session has been started — the manifest
 * is deliberately identical for every visitor, so it can be cached.
 * ============================================================================
 */

if (!defined('PWA_HEAD_RENDERED')) {
    define('PWA_HEAD_RENDERED', true);

    /** Bump to bust the cached CSS/JS on every phone. Keep in step with manifest.php. */
    define('PWA_VERSION', '1.0.0');

    $pwaSignedIn = isset($_SESSION['user_id']);
    $pwaName     = $pwaSignedIn ? (string) ($_SESSION['first_name'] ?? '') : '';

    /**
     * iOS launch images. Safari picks by exact device metrics, so each entry
     * is [css width, css height, device pixel ratio] and both orientations
     * are emitted. A device with no match simply gets the background colour,
     * which is why the in-app preloader exists as well.
     */
    $pwaSplashes = [
        [320, 568, 2], [375, 667, 2], [414, 896, 2],
        [375, 812, 3], [390, 844, 3], [393, 852, 3], [402, 874, 3],
        [414, 736, 3], [414, 896, 3], [428, 926, 3], [430, 932, 3], [440, 956, 3],
        [768, 1024, 2], [810, 1080, 2], [834, 1112, 2], [834, 1194, 2], [1024, 1366, 2],
    ];
    ?>
    <!-- ================= HOD Lekki — installable app ================= -->
    <link rel="manifest" href="/manifest.php">

    <meta name="application-name" content="HOD Lekki">
    <meta name="theme-color" content="#1D356A" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#0B1733" media="(prefers-color-scheme: dark)">
    <meta name="color-scheme" content="light">

    <!-- iOS home-screen behaviour. black-translucent lets the app paint under
         the status bar; the safe-area padding in pwa.css keeps content clear
         of the notch and the home indicator. -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="HOD Lekki">

    <link rel="apple-touch-icon" href="/assets/pwa/apple-touch-icon.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/assets/pwa/icon-152.png">
    <link rel="apple-touch-icon" sizes="167x167" href="/assets/pwa/icon-167.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/pwa/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/assets/pwa/icon-192.png">
    <link rel="icon" type="image/png" sizes="512x512" href="/assets/pwa/icon-512.png">
<?php
    // Every name here is prefixed: this file is included INSIDE the <head> of
    // pages that have hundreds of their own variables in scope (header.php is
    // 1000 lines), and a bare $w or $portrait would quietly clobber one.
    foreach ($pwaSplashes as [$pwaW, $pwaH, $pwaDpr]):
        $pwaPortrait  = ($pwaW * $pwaDpr) . 'x' . ($pwaH * $pwaDpr);
        $pwaLandscape = ($pwaH * $pwaDpr) . 'x' . ($pwaW * $pwaDpr); ?>
    <link rel="apple-touch-startup-image" href="/assets/pwa/splash-<?= $pwaPortrait ?>.png" media="screen and (device-width: <?= $pwaW ?>px) and (device-height: <?= $pwaH ?>px) and (-webkit-device-pixel-ratio: <?= $pwaDpr ?>) and (orientation: portrait)">
    <link rel="apple-touch-startup-image" href="/assets/pwa/splash-<?= $pwaLandscape ?>.png" media="screen and (device-width: <?= $pwaW ?>px) and (device-height: <?= $pwaH ?>px) and (-webkit-device-pixel-ratio: <?= $pwaDpr ?>) and (orientation: landscape)">
<?php endforeach;
    unset($pwaSplashes, $pwaW, $pwaH, $pwaDpr, $pwaPortrait, $pwaLandscape);
?>

    <link rel="stylesheet" href="/assets/css/pwa.css?v=<?= PWA_VERSION ?>">

    <!--
        Critical preloader CSS, inline so the splash paints on the very first
        frame. Everything else about the app can wait; this cannot, because
        the gap it covers is exactly the gap Tailwind's CDN leaves behind.
    -->
    <style>
        #hodSplash {
            position: fixed;
            inset: 0;
            z-index: 2147483000;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            background: #0B1733;
            color: #fff;
            font-family: Montserrat, Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        html.hod-booting #hodSplash { display: flex; }
        html.hod-booting, html.hod-booting body { overflow: hidden !important; }
        #hodSplash .hod-splash-tile {
            width: 108px; height: 108px;
            background: #fff;
            border-radius: 26px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 28px 70px -20px rgba(0,0,0,.7);
            animation: hodSplashIn .62s cubic-bezier(.2,.9,.3,1) both;
        }
        #hodSplash .hod-splash-tile img { width: 84px; height: 84px; display: block; }
        #hodSplash .hod-splash-word {
            margin-top: 26px;
            font-size: 15px; font-weight: 800;
            letter-spacing: .34em; text-indent: .34em;
            text-transform: uppercase;
            animation: hodSplashFade .5s .18s ease-out both;
        }
        #hodSplash .hod-splash-sub {
            margin-top: 8px;
            font-size: 10.5px; font-weight: 500;
            letter-spacing: .18em; text-indent: .18em;
            text-transform: uppercase;
            color: #7E92B6;
            animation: hodSplashFade .5s .3s ease-out both;
        }
        #hodSplash .hod-splash-rail {
            margin-top: 34px;
            width: 132px; height: 3px;
            border-radius: 99px;
            background: rgba(255,255,255,.12);
            overflow: hidden;
            animation: hodSplashFade .5s .38s ease-out both;
        }
        #hodSplash .hod-splash-rail i {
            display: block; height: 100%; width: 42%;
            border-radius: 99px;
            background: #D11920;
            animation: hodSplashRail 1.15s cubic-bezier(.65,.05,.36,1) infinite;
        }
        #hodSplash.hod-splash-out { animation: hodSplashOut .42s ease-in forwards; }
        @keyframes hodSplashIn  { from { opacity: 0; transform: scale(.82) translateY(10px); } to { opacity: 1; transform: none; } }
        @keyframes hodSplashFade{ from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
        @keyframes hodSplashRail{ 0% { transform: translateX(-110%); } 100% { transform: translateX(345%); } }
        @keyframes hodSplashOut { to { opacity: 0; visibility: hidden; } }
        @media (prefers-reduced-motion: reduce) {
            #hodSplash *, #hodSplash { animation-duration: .01ms !important; animation-iteration-count: 1 !important; }
        }
    </style>

    <script>
        /*
         * Runs before <body> is parsed, which is the whole point:
         *
         *  1. viewport-fit=cover has to be on the viewport meta BEFORE first
         *     layout or iOS leaves white bars round the notch. Every page in
         *     this repo already has its own viewport tag, so it is amended
         *     here rather than duplicated — two viewport metas disagree and
         *     browsers resolve that differently.
         *  2. The splash shows once per app launch, not on every navigation.
         *     sessionStorage is exactly the right lifetime: it is empty when
         *     the app is opened from the home screen and survives clicks.
         */
        (function () {
            var meta = document.querySelector('meta[name="viewport"]');
            if (meta) {
                meta.setAttribute('content', 'width=device-width, initial-scale=1, viewport-fit=cover');
            }

            var root = document.documentElement;
            var standalone = window.matchMedia('(display-mode: standalone)').matches
                || window.navigator.standalone === true;

            root.classList.add('hod-app');
            if (standalone) root.classList.add('hod-standalone');

            try {
                if (!sessionStorage.getItem('hodLaunched')) {
                    root.classList.add('hod-booting');
                    sessionStorage.setItem('hodLaunched', '1');
                }
            } catch (e) {
                /* private mode: skip the splash rather than break the page */
            }
        })();

        window.HOD_PWA = {
            version: '<?= PWA_VERSION ?>',
            signedIn: <?= $pwaSignedIn ? 'true' : 'false' ?>,
            firstName: <?= json_encode($pwaName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            pushApi: '/api/push_api.php',
            notificationsApi: '/api/notifications_api.php'
        };
    </script>

    <script src="/assets/js/pwa.js?v=<?= PWA_VERSION ?>" defer></script>
    <!-- =============================================================== -->
<?php } ?>
