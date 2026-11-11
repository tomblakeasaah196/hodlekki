<?php
// /includes/campaigns/formguard.php
//
// Cheap protection for public forms that make us send email (the Connect
// card): a honeypot field people never see, and a signed timestamp minted when
// the page was rendered, so a script that posts straight away (or invents the
// time) is caught. A bot gets an ordinary-looking success and nothing happens;
// a person who left the page open for days is asked to reload.
//
// The signing key is CONNECT_FORM_KEY in .env when set, otherwise derived
// one-way from settings that are already there. It is never printed or logged.
// Pure: no database, safe to load from any public page.

require_once __DIR__ . '/../site_url.php';

const CAMPAIGNS_FORM_MIN_SECONDS = 4;
const CAMPAIGNS_FORM_MAX_SECONDS = 3 * 86400;
const CAMPAIGNS_FORM_HONEYPOT    = 'website';

function campaigns_form_key(): string
{
    $key = site_env('CONNECT_FORM_KEY');
    if ($key !== '') {
        return $key;
    }
    return hash('sha256', 'hod-connect-form|' . site_env('DB_PASS') . '|' . site_env('DB_NAME') . '|' . site_env('SE_HASH_PEPPER'));
}

/** "timestamp.signature" for a hidden field. */
function campaigns_form_token(?int $now = null): string
{
    $ts = (string) ($now ?? time());
    return $ts . '.' . substr(hash_hmac('sha256', 'connect|' . $ts, campaigns_form_key()), 0, 32);
}

/**
 * ok       a person, probably
 * bot      honeypot filled, signature wrong, or sent faster than a person can
 * expired  a real token, but the page was open for more than 3 days
 */
function campaigns_form_verdict(string $token, string $honeypot, ?int $now = null): string
{
    $now ??= time();
    if (trim($honeypot) !== '') {
        return 'bot';
    }
    if (!preg_match('/^(\d{9,11})\.([a-f0-9]{32})$/', $token, $m)) {
        return 'bot';
    }
    $expected = substr(hash_hmac('sha256', 'connect|' . $m[1], campaigns_form_key()), 0, 32);
    if (!hash_equals($expected, $m[2])) {
        return 'bot';
    }
    $age = $now - (int) $m[1];
    if ($age < CAMPAIGNS_FORM_MIN_SECONDS) {
        return 'bot';
    }
    return $age > CAMPAIGNS_FORM_MAX_SECONDS ? 'expired' : 'ok';
}
