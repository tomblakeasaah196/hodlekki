<?php
/**
 * Deploy webhook — receives an authenticated POST from GitHub Actions and
 * runs bin/deploy.sh. Lives inside the docroot at webhook/deploy.php, so
 * it's reachable at https://hodlc.lpc.cm/webhook/deploy.php.
 *
 * Security model:
 *   - POST only (method whitelist).
 *   - Requires an X-Deploy-Token request header whose value matches the
 *     DEPLOY_WEBHOOK_SECRET line in /home/smartqaq/public_html/hodlc.lpc.cm/.env.
 *   - Constant-time comparison via hash_equals() to defeat timing attacks.
 *   - Every rejected call is written to error_log with the client IP and
 *     truncated user-agent for later review.
 *   - No shell metacharacters ever originate from the request: the endpoint
 *     runs one fixed command (bin/deploy.sh) with escapeshellarg on its path.
 *   - Output is streamed line-by-line so Actions logs show progress live,
 *     but bin/deploy.sh also mirrors to /home/smartqaq/deploy.log so
 *     nothing is lost if the caller disconnects.
 */

header('Content-Type: text/plain; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

/* ------------------------------------------------------------------------- *
 * 1. Method whitelist
 * ------------------------------------------------------------------------- */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit("method not allowed\n");
}

/* ------------------------------------------------------------------------- *
 * 2. Load expected token from .env (never from the request, never hardcoded)
 * ------------------------------------------------------------------------- */
$envPath = dirname(__DIR__) . '/.env';
if (!is_file($envPath)) {
    http_response_code(500);
    exit(".env not found\n");
}

$expected = '';
foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
    [$k, $v] = explode('=', $line, 2);
    if (trim($k) === 'DEPLOY_WEBHOOK_SECRET') {
        $expected = trim($v, " \t\n\r\0\x0B\"'");
        break;
    }
}

if ($expected === '' || strlen($expected) < 32) {
    http_response_code(500);
    exit("DEPLOY_WEBHOOK_SECRET is missing or too short in .env (need >= 32 chars)\n");
}

/* ------------------------------------------------------------------------- *
 * 3. Verify the caller's token — constant-time compare, no early exit
 * ------------------------------------------------------------------------- */
$provided = $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '';
if (!is_string($provided) || !hash_equals($expected, $provided)) {
    error_log(sprintf(
        '[webhook/deploy] rejected: bad or missing X-Deploy-Token from %s (ua=%s)',
        $_SERVER['REMOTE_ADDR'] ?? '?',
        substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 80)
    ));
    http_response_code(401);
    exit("unauthorized\n");
}

/* ------------------------------------------------------------------------- *
 * 4. Locate the deploy script and confirm required PHP shell functions exist
 * ------------------------------------------------------------------------- */
$deployScript = '/home/smartqaq/repositories/hodlekki/bin/deploy.sh';
if (!is_file($deployScript)) {
    http_response_code(500);
    exit("bin/deploy.sh not found at {$deployScript}\n");
}

$disabled = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
foreach (['popen', 'pclose'] as $fn) {
    if (!function_exists($fn) || in_array($fn, $disabled, true)) {
        http_response_code(500);
        exit("PHP function {$fn}() is disabled on this host; webhook cannot run\n");
    }
}

/* ------------------------------------------------------------------------- *
 * 5. Stream the deploy output back to the caller
 * ------------------------------------------------------------------------- */
ignore_user_abort(true);
@set_time_limit(0);

while (ob_get_level() > 0) { @ob_end_flush(); }
@ob_implicit_flush(true);

echo "[webhook] " . date(DATE_ATOM) . " starting deploy\n";
echo "[webhook] host: " . gethostname() . "\n";
echo "[webhook] script: {$deployScript}\n";
echo str_repeat('-', 60) . "\n";

$cmd = '/usr/bin/env bash ' . escapeshellarg($deployScript) . ' 2>&1';
$fh  = popen($cmd, 'r');
if (!$fh) {
    http_response_code(500);
    exit("popen() failed\n");
}

while (!feof($fh)) {
    $chunk = fread($fh, 4096);
    if ($chunk !== false && $chunk !== '') {
        echo $chunk;
        @flush();
    }
}
$rc = pclose($fh);

echo str_repeat('-', 60) . "\n";
if ($rc !== 0) {
    http_response_code(500);
    echo "[webhook] DEPLOY FAILED (exit code {$rc})\n";
    exit;
}
echo "[webhook] DEPLOY OK\n";
