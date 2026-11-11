<?php
// /email/c.php
//
// A tracked link in an email: /email/c.php?r=REF&l=N. Records the click and
// sends the person to the link's real target — but ONLY a target stored with
// that very email when it was rendered (email_track_click()), so this can never
// be used as an open redirect. Anything unknown goes to the home page.
// No page, no cookies needed.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

$ref = (string) ($_GET['r'] ?? '');
$index = filter_var($_GET['l'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 500]]);
$target = null;
try {
    if ($index !== false && preg_match('/^[A-Za-z0-9_-]{22}$/', $ref)) {
        $target = email_track_click($pdo, $ref, (int) $index);
    }
} catch (Throwable $e) {
    error_log('[email] click tracking failed: ' . $e->getMessage());
}

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Location: ' . ($target ?? site_url('/')), true, 302);
exit;
