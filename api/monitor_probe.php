<?php
// /api/monitor_probe.php
/**
 * Where the outside probe reports (docs/monitor.md, "Outside probe";
 * .github/workflows/monitor-probe.yml).
 *
 * POST only, JSON, signed: X-Monitor-Timestamp (unix time, within 10 minutes)
 * and X-Monitor-Signature: sha256=<HMAC-SHA256 of "<timestamp>\n<body>" with
 * MONITOR_PROBE_SECRET from .env>. A signature is accepted once. Answers JSON
 * with the release the server runs, so the workflow log shows it.
 *
 * No session, no includes/db.php: the monitor's own connection only.
 */

require_once __DIR__ . '/../includes/monitor/capture.php';
hod_monitor_install();
require_once __DIR__ . '/../includes/monitor/probe.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$reply = static function (int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $reply(405, ['status' => 'error', 'message' => 'POST only']);
}
$body = (string) file_get_contents('php://input', false, null, 0, 65537);
if (strlen($body) > 65536) {
    $reply(413, ['status' => 'error', 'message' => 'too large']);
}
$now = time();
$problem = monitor_probe_verify(
    hod_monitor_env('MONITOR_PROBE_SECRET'),
    (string) ($_SERVER['HTTP_X_MONITOR_TIMESTAMP'] ?? ''),
    (string) ($_SERVER['HTTP_X_MONITOR_SIGNATURE'] ?? ''),
    $body,
    $now
);
if ($problem !== null) {
    if ($problem[0] === 401) {
        error_log('Monitor probe refused from ' . substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 12) . ': ' . $problem[1]);
    }
    $reply($problem[0], ['status' => 'error', 'message' => $problem[1]]);
}
$payload = json_decode($body, true);
if (!is_array($payload)) {
    $reply(400, ['status' => 'error', 'message' => 'body is not JSON']);
}
try {
    $db = monitor_db();
    if (!monitor_ready($db)) {
        $reply(503, ['status' => 'error', 'message' => 'FEATURE_NOT_READY: the monitor tables are not there yet']);
    }
    if (!monitor_probe_fresh($db, (string) $_SERVER['HTTP_X_MONITOR_SIGNATURE'])) {
        $reply(409, ['status' => 'error', 'message' => 'this report was already received']);
    }
    $stored = monitor_probe_store($db, $payload, $now);
} catch (Throwable $e) {
    hod_monitor_report($e);
    $reply(500, ['status' => 'error', 'message' => 'could not store the report']);
}

$release = monitor_release();
$reply(200, ['status' => 'success', 'stored' => $stored, 'server_time' => $now,
             'release' => isset($release['sha']) ? substr((string) $release['sha'], 0, 12) : null]);
