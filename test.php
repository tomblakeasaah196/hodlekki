<?php
// /test.php
// The link and the QR code of a standalone test (includes/trainings/tests.php, docs/EXAMS.md §8):
//   /test?t=<the test's exam item id>
// Signed in: straight to the test. Not signed in: the sign-in page, which comes back here to the test.
require_once __DIR__ . '/includes/db.php';

$item = (int) ($_GET['t'] ?? 0);
if ($item <= 0) {
    header('Location: /modules/trainings/#tab=tests', true, 302);
    exit;
}
$target = '/modules/exams/take?item=' . $item;
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $target, true, 302);
    exit;
}
header('Location: /auth/login?next=' . rawurlencode($target), true, 302);
exit;
