<?php
// /includes/pwa_boot.php
/**
 * The launch screen, and the only markup the PWA adds to a page.
 *
 * Include it as the FIRST thing inside <body> on any page that includes
 * includes/pwa_head.php. It is hidden unless the early script in pwa_head.php
 * added `hod-booting` to <html>, which it does once per app launch — so a
 * member who opens the app from their home screen gets the branded hand-off
 * from the iOS launch image, and someone clicking between pages does not see
 * it at all.
 *
 * assets/js/pwa.js takes it away on window.load, with a hard ceiling so a
 * slow CDN can never leave anyone staring at it.
 */

if (!defined('PWA_BOOT_RENDERED')) {
    define('PWA_BOOT_RENDERED', true);
    ?>
    <div id="hodSplash" role="status" aria-live="polite" aria-label="Opening HOD Lekki">
        <div class="hod-splash-tile">
            <img src="/assets/pwa/icon-192.png" alt="" width="84" height="84" fetchpriority="high">
        </div>
        <div class="hod-splash-word">HOD Lekki</div>
        <div class="hod-splash-sub">Household of David</div>
        <div class="hod-splash-rail"><i></i></div>
    </div>
<?php } ?>
