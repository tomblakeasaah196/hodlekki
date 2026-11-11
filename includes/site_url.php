<?php
// /includes/site_url.php

/**
 * The site's public address, in one place.
 *
 * hodlekki.org since November 2026 (it was hodlc.lpc.cm, borrowed from lpc.cm).
 * Anything that leaves the browser — QR codes, SMS, push, emails, printed posters,
 * share cards — is built from SITE_URL in .env, never from the request's host:
 * a link made while someone is on connect.hodlekki.org, on the old address or on
 * the server's own alias must still point at the real site.
 *
 * Order: SITE_URL in .env, then (in a browser, for local copies with no SITE_URL)
 * the request's own host, then https://hodlekki.org.
 *
 * Safe to require from anywhere, before or after includes/db.php: when .env has
 * not been loaded yet it reads SITE_URL straight from the project root's .env.
 */

if (!defined('HOD_DEFAULT_SITE_URL')) {
    define('HOD_DEFAULT_SITE_URL', 'https://hodlekki.org');
}

if (!function_exists('site_env')) {
    /** A value from .env, read from the project root when db.php has not loaded it. */
    function site_env(string $key): string
    {
        if (isset($_ENV[$key])) {
            return trim((string) $_ENV[$key]);
        }
        static $file = null;
        if ($file === null) {
            $file = [];
            $path = dirname(__DIR__) . '/.env';
            if (is_file($path)) {
                foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                        continue;
                    }
                    [$k, $v] = explode('=', $line, 2);
                    $file[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
                }
            }
        }

        return trim((string) ($file[$key] ?? ''));
    }
}

if (!function_exists('site_origin')) {
    /** e.g. https://hodlekki.org — no trailing slash. */
    function site_origin(): string
    {
        $configured = site_env('SITE_URL');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
            $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
                || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
            $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) $_SERVER['HTTP_HOST']);
            if ($host !== '') {
                return ($https ? 'https://' : 'http://') . $host;
            }
        }

        return HOD_DEFAULT_SITE_URL;
    }
}

if (!function_exists('site_host')) {
    /** e.g. hodlekki.org — for text printed on posters and PDFs. */
    function site_host(): string
    {
        return (string) (parse_url(site_origin(), PHP_URL_HOST) ?: 'hodlekki.org');
    }
}

if (!function_exists('site_url')) {
    /** An absolute URL on the site: site_url('/sermons') → https://hodlekki.org/sermons. */
    function site_url(string $path = ''): string
    {
        return site_origin() . ($path === '' ? '' : '/' . ltrim($path, '/'));
    }
}

if (!function_exists('connect_origin')) {
    /** The public Connect page's own address: CONNECT_URL in .env, else connect.<site host>. */
    function connect_origin(): string
    {
        $configured = site_env('CONNECT_URL');
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        $scheme = (string) (parse_url(site_origin(), PHP_URL_SCHEME) ?: 'https');

        return $scheme . '://connect.' . site_host();
    }
}

if (!function_exists('is_connect_host')) {
    /** Whether this request arrived on the Connect page's own address (connect.hodlekki.org). */
    function is_connect_host(): bool
    {
        if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
            return false;
        }
        $host = strtolower(preg_replace('/:\d+$/', '', (string) $_SERVER['HTTP_HOST']));
        $connectHost = strtolower((string) parse_url(connect_origin(), PHP_URL_HOST));

        return $host !== '' && ($host === $connectHost || str_starts_with($host, 'connect.'));
    }
}
