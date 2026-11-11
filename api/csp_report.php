<?php
// /api/csp_report.php
/**
 * Receives Content-Security-Policy violation reports from rebuilt (strict)
 * pages — the path is HOD_CSP_REPORT_PATH in includes/security_headers.php —
 * and hands them to the error and health monitor.
 *
 * A violation on a strict page is a bug: something inline slipped back in, or
 * a source is missing from the page's $ui['csp']. The monitor
 * (includes/monitor/intake.php, monitor_intake_csp()) dedupes, groups and
 * stores them as security notices. Until the monitor is deployed, reports are
 * counted and dropped: they are deliberately NOT written to error_log, whose
 * every line the monitor's log scanner imports.
 *
 * Accepts both formats browsers send: application/csp-report (report-uri)
 * and application/reports+json (report-to). Nothing personal leaves this
 * file: every URL in a report (page, blocked resource, referrer, script file)
 * loses its query string and fragment — where tokens, phone numbers and
 * search terms live — before it is handed on. No database, no session, no
 * output. Bounded: 16 KB per body, 10 reports a minute per IP, 60 a minute
 * for the whole site.
 */

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    exit;
}

$contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if (!in_array($contentType, ['application/csp-report', 'application/reports+json', 'application/json'], true)) {
    http_response_code(415);
    exit;
}

$raw = (string) file_get_contents('php://input', false, null, 0, 16385);
if (strlen($raw) > 16384) {
    http_response_code(413);
    exit;
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    exit;
}

/** One budget, shared by every PHP process through a small temp file per minute. */
function csp_report_take(string $key, int $limit): bool
{
    $file = sys_get_temp_dir() . '/hod_csp_' . date('YmdHi') . '_' . $key;
    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return true; // no temp dir: never refuse because of the limiter itself
    }
    flock($fh, LOCK_EX);
    $count = (int) stream_get_contents($fh);
    $ok = $count < $limit;
    if ($ok) {
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) ($count + 1));
    }
    flock($fh, LOCK_UN);
    fclose($fh);

    return $ok;
}

$ipKey = substr(hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|hod-csp'), 0, 16);
if (!csp_report_take('ip_' . $ipKey, 10) || !csp_report_take('all', 60)) {
    http_response_code(204);
    exit;
}

/** A URL with its query string and fragment removed; keywords ('inline', 'eval') pass through. */
function csp_report_clean_url($value): string
{
    $value = mb_substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string) $value), 0, 500);

    return (string) preg_replace('/[?#].*$/s', '', $value);
}

/** Strip every URL-bearing field of one report body (either format's names). */
function csp_report_clean(array $report): array
{
    foreach (['document-uri', 'documentURL', 'blocked-uri', 'blockedURL', 'referrer', 'source-file', 'sourceFile'] as $field) {
        if (isset($report[$field])) {
            $report[$field] = csp_report_clean_url($report[$field]);
        }
    }
    // The sample is a snippet of the offending inline code: kept short.
    foreach (['script-sample', 'sample'] as $field) {
        if (isset($report[$field])) {
            $report[$field] = mb_substr((string) $report[$field], 0, 80);
        }
    }

    return $report;
}

if (isset($data['csp-report']) && is_array($data['csp-report'])) {
    $data['csp-report'] = csp_report_clean($data['csp-report']);
} elseif (array_is_list($data)) {
    foreach ($data as $i => $entry) {
        if (is_array($entry) && isset($entry['body']) && is_array($entry['body'])) {
            $data[$i]['body'] = csp_report_clean($entry['body']);
            if (isset($entry['url'])) {
                $data[$i]['url'] = csp_report_clean_url($entry['url']);
            }
        }
    }
}
$clean = (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

$intake = dirname(__DIR__) . '/includes/monitor/intake.php';
if (is_file($intake)) {
    try {
        require_once $intake;
        if (function_exists('monitor_intake_csp')) {
            monitor_intake_csp($clean, $contentType);
        }
    } catch (Throwable $e) {
        // The monitor records its own failures; a report must never 500.
    }
}

http_response_code(204);
