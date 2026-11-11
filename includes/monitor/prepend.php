<?php
// /includes/monitor/prepend.php
/**
 * OPTIONAL: the error monitor on every PHP file the site serves, through PHP's
 * auto_prepend_file (docs/monitor.md, "Capture").
 *
 * includes/db.php already installs the monitor, which covers every API, every
 * ERP page and every cron job. About a dozen public pages never load db.php
 * (connect.php, checkin.php, verify.php, testimonies.php …); their problems
 * still reach the monitor through the log reader, a minute later and with less
 * detail. This file closes that gap. To switch it on (the owner's step, not a
 * deploy step — the INI files are cPanel's):
 *
 *   cPanel → MultiPHP INI Editor → Editor Mode → hodlekki.org, add:
 *     auto_prepend_file = "/home/smartqaq/public_html/hodlekki/includes/monitor/prepend.php"
 *
 * Health → "PHP (web)" says whether it is on. Installing twice (here and in
 * db.php) is harmless. To switch it off, remove the line. Nothing here touches
 * the database, the session or the output.
 */

if (!defined('HOD_MONITOR_PREPENDED')) {
    define('HOD_MONITOR_PREPENDED', true);
    require_once __DIR__ . '/capture.php';
    hod_monitor_install();
}
