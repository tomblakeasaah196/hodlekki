<?php
// /email/o.php
//
// The open pixel: /email/o.php?r=REF. Only in campaign emails that ask for it
// (never in absence or journey emails), counted once per email; opens are
// approximate (some mail apps fetch every picture). Always answers with a 1×1
// transparent GIF, whatever happened.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

$ref = (string) ($_GET['r'] ?? '');
try {
    if (preg_match('/^[A-Za-z0-9_-]{22}$/', $ref)) {
        email_track_open($pdo, $ref);
    }
} catch (Throwable $e) {
    error_log('[email] open tracking failed: ' . $e->getMessage());
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
echo email_pixel_gif();
exit;
