<?php
// /api/email_preview.php
//
// An automation step's email, rendered by the real renderer for the person
// looking (their name in it, example streak/week values), for the preview frame
// of the Automation tab. GET module=…&step=…&week=N.
//
// The frame is sandboxed (no scripts, no same-origin) and this response has its
// own Content-Security-Policy: nothing may load but the inline styles every
// email needs and pictures from this site. Never srcdoc: the HTML stays on the
// server. Access: the module's manage right, like the tab.

require_once '../includes/db.php';
require_once '../includes/campaigns/automation_tab.php';

$module = (string) ($_GET['module'] ?? '');
$fail = static function (int $code, string $text): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'self'");
    echo $text;
    exit;
};
if (!isset(AUTOMATION_KINDS[$module])) {
    $fail(400, 'Unknown module.');
}
if (empty($_SESSION['user_id'])) {
    $fail(401, 'Please sign in again.');
}
if (!can($module, 'manage')) {
    $fail(403, 'You do not have access to this.');
}

$rule = automation_tab_rule($pdo, $module);
$viewer = $rule !== null ? email_person($pdo, (int) $_SESSION['user_id']) : null;
$out = $viewer !== null
    ? automation_tab_render($pdo, $rule, (string) ($_GET['step'] ?? ''), max(1, (int) ($_GET['week'] ?? 1)), $viewer)
    : null;
if ($out === null || empty($out['ok'])) {
    $fail(404, 'This step has no email to preview.');
}

$origin = rtrim(site_url('/'), '/');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src data: {$origin}; base-uri 'none'; form-action 'none'; frame-ancestors 'self'");
echo $out['html'];
