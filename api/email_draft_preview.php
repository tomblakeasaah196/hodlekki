<?php
// /api/email_draft_preview.php
//
// The editor's live preview: the draft the editor just handed in (kept in the
// session by email_editor_draft_put(), validated), rendered by the real
// renderer for the person looking. GET d=<token>.
//
// Shown in a sandboxed frame (no scripts, no same-origin); this response has
// its own Content-Security-Policy: nothing may load but the inline styles every
// email needs and pictures from this site. Never srcdoc.

require_once '../includes/db.php';
require_once '../includes/campaigns/editor.php';

$fail = static function (int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    echo $text;
    exit;
};
if (empty($_SESSION['user_id'])) {
    $fail(401, 'Please sign in again.');
}
if (!email_editor_allowed()) {
    $fail(403, 'You do not have access to this.');
}
$doc = email_editor_draft_get((string) ($_GET['d'] ?? ''));
$person = $doc !== null ? email_person($pdo, (int) $_SESSION['user_id']) : null;
if ($doc === null || $person === null) {
    $fail(404, 'This preview has expired. Press Preview again.');
}
$out = email_render($doc, $person, ['purpose' => 'news', 'preview' => true, 'pdo' => $pdo,
    'vars' => ['week_number' => '1', 'streak' => '2', 'weeks_away' => '2']]);
if (empty($out['ok'])) {
    $fail(422, 'This email cannot be shown yet: ' . implode(' ', array_slice((array) $out['errors'], 0, 2)));
}

$origin = rtrim(site_url('/'), '/');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: {$origin}; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
echo $out['html'];
