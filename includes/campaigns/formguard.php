<?php
// /includes/campaigns/formguard.php
//
// Protection for public forms that make us save a record and send email (the
// Connect card):
//   1. a honeypot field people never see (bots fill it);
//   2. a signed render time, bound to the visitor's session and good for ONE
//      saved card: a script that posts at once, invents the time, replays a
//      page it fetched once, or drops cookies gets nowhere. Every error reply
//      carries a fresh token (campaigns_form_token()), so a real visitor who
//      mistyped their phone never has to reload;
//   3. Cloudflare Turnstile when TURNSTILE_SITE_KEY and TURNSTILE_SECRET_KEY
//      are in .env (the owner pastes them). Without them, 1 and 2 still hold;
//      if Cloudflare cannot be reached, the card is saved and it is logged.
//
// Verdicts: bot → an ordinary-looking success, nothing saved; stale/expired →
// "send again" with a fresh token. Signing key: CONNECT_FORM_KEY, else derived
// one-way from .env. Never printed or logged.

require_once __DIR__ . '/../site_url.php';

const CAMPAIGNS_FORM_MIN_SECONDS = 4;
const CAMPAIGNS_FORM_MAX_SECONDS = 3 * 86400;
const CAMPAIGNS_FORM_HONEYPOT    = 'website';
const CAMPAIGNS_FORM_USED_KEEP   = 20;
const CAMPAIGNS_TURNSTILE_VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

function campaigns_form_key(): string
{
    $key = site_env('CONNECT_FORM_KEY');
    if ($key !== '') {
        return $key;
    }
    return hash('sha256', 'hod-connect-form|' . site_env('DB_PASS') . '|' . site_env('DB_NAME') . '|' . site_env('SE_HASH_PEPPER'));
}

function campaigns_form_session(): string
{
    return session_status() === PHP_SESSION_ACTIVE ? (string) session_id() : '';
}

function campaigns_form_sig(string $ts, string $sid): string
{
    return substr(hash_hmac('sha256', 'connect|' . $ts . '|' . $sid, campaigns_form_key()), 0, 32);
}

/** "timestamp.signature" for the hidden field, bound to this session. */
function campaigns_form_token(?int $now = null, ?string $sid = null): string
{
    $ts = (string) ($now ?? time());
    return $ts . '.' . campaigns_form_sig($ts, $sid ?? campaigns_form_session());
}

/**
 * ok       a person, probably
 * bot      honeypot filled, a token already used for a saved card, or sent
 *          faster than a person can (answered with a normal-looking success)
 * stale    not signed for this session (a lapsed session, or invented):
 *          "send again" with a fresh token
 * expired  a real token, but the page was open for more than 3 days
 */
function campaigns_form_verdict(string $token, string $honeypot, ?int $now = null, ?string $sid = null): string
{
    $now ??= time();
    if (trim($honeypot) !== '') {
        return 'bot';
    }
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) {
        return 'stale';
    }
    if (!hash_equals(campaigns_form_sig($m[1], $sid ?? campaigns_form_session()), $m[2])) {
        return 'stale';
    }
    if (in_array($m[2], (array) ($_SESSION['connect_form_used'] ?? []), true)) {
        return 'bot'; // the same page, replayed after it already saved a card
    }
    $age = $now - (int) $m[1];
    if ($age < CAMPAIGNS_FORM_MIN_SECONDS) {
        return 'bot';
    }
    return $age > CAMPAIGNS_FORM_MAX_SECONDS ? 'expired' : 'ok';
}

/** The card was saved: this token is spent (in this session). */
function campaigns_form_consume(string $token): void
{
    if (!preg_match('/^\d{9,11}\.([a-f0-9]{32})$/', $token, $m)) {
        return;
    }
    $used = (array) ($_SESSION['connect_form_used'] ?? []);
    $used[] = $m[1];
    $_SESSION['connect_form_used'] = array_slice($used, -CAMPAIGNS_FORM_USED_KEEP);
}

// ---------------------------------------------------------------------------
// Cloudflare Turnstile (optional)
// ---------------------------------------------------------------------------

function campaigns_turnstile_site_key(): string
{
    return site_env('TURNSTILE_SITE_KEY');
}

function campaigns_turnstile_enabled(): bool
{
    return campaigns_turnstile_site_key() !== '' && site_env('TURNSTILE_SECRET_KEY') !== '';
}

/**
 * off          not configured (the card works without it)
 * ok           Cloudflare says a person solved it
 * fail         missing or rejected: ask them to tick it again
 * unreachable  Cloudflare did not answer: let the card through, log it
 *
 * $http (tests): fn(string $url, array $form): ?array — the decoded reply, or null.
 */
function campaigns_turnstile_check(string $token, string $ip, ?callable $http = null): string
{
    if (!campaigns_turnstile_enabled()) {
        return 'off';
    }
    if (trim($token) === '' || strlen($token) > 2048) {
        return 'fail';
    }
    $http ??= 'campaigns_turnstile_post';
    $reply = $http(CAMPAIGNS_TURNSTILE_VERIFY, [
        'secret'   => site_env('TURNSTILE_SECRET_KEY'),
        'response' => $token,
        'remoteip' => $ip,
    ]);
    if (!is_array($reply)) {
        error_log('[connect] Turnstile could not be reached; the card was let through.');
        return 'unreachable';
    }
    return !empty($reply['success']) ? 'ok' : 'fail';
}

/** POST a form to Cloudflare (5 s), decoded JSON or null. The secret is never logged. */
function campaigns_turnstile_post(string $url, array $form): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($form),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $status !== 200) {
        return null;
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}
