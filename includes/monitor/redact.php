<?php
// /includes/monitor/redact.php
/**
 * Keeping the monitor free of personal data and secrets (docs/monitor.md, "Privacy").
 *
 * This database holds real church members. Everything the monitor stores —
 * error messages, stack traces, page addresses, browser stacks, CSP reports,
 * log lines, the code excerpts in an AI prompt — goes through here first:
 *
 *   monitor_redact($text)        emails, phone numbers, tokens, passwords, cookies,
 *                                .env secret values and quoted database values out
 *   monitor_clean_url($url)      [path, route]: tokens out of the path, the query
 *                                reduced to its parameter names
 *   monitor_browser_family($ua)  "Chrome 131 · Android", never the whole user agent
 *   monitor_device_class($ua)    phone | tablet | desktop
 *
 * Pure functions, no database, safe to load anywhere (the capture loads it at
 * flush time; the tests load it alone).
 */

if (!function_exists('monitor_redact')) {
    /** Personal data and secrets out of a piece of text. */
    function monitor_redact(?string $text): string
    {
        $s = (string) $text;
        if ($s === '') {
            return '';
        }

        // Secret values from .env, by name (DB_PASS, MAIL_PASSWORD, *_KEY, *_TOKEN,
        // SE_HASH_PEPPER, SMS_VAULT_KEY ...), wherever they turn up.
        foreach (monitor_secret_values() as $name => $value) {
            $s = str_replace($value, '[' . $name . ']', $s);
        }

        // Authorization headers, cookies and key=value pairs that carry secrets.
        $s = preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i', '$1 <token>', $s) ?? $s;
        $s = preg_replace('/\b(PHPSESSID|hod_remember|hod_[a-z_]*token|se_[a-z_]*)=([^;\s&"\']+)/i', '$1=<cookie>', $s) ?? $s;
        // A name that IS a secret's name, alone or joined by _ or - (db_pass,
        // MAIL_PASSWORD, access_token, api-key), not one that merely contains
        // the letters ("mapping", "author", "design").
        $s = preg_replace(
            '/\b((?:[A-Za-z0-9]+[_\-])*(?:pass(?:word|wd)?|pwd|secret|token|api[_\-]?key|apikey|auth(?:orization)?|signature|sig|otp|pin|vault[_\-]?key|private[_\-]?key)(?:[_\-][A-Za-z0-9]+)*)\b(["\']?\s*(?:=>|=|:)\s*["\']?)(?!Bearer\b|Basic\b|[\[<])([^\s"\'&,;}]{3,})/i',
            '$1$2<secret>',
            $s
        ) ?? $s;

        // JSON web tokens and other long opaque tokens (letters AND digits, 32+).
        $s = preg_replace('/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{5,}/', '<token>', $s) ?? $s;
        $s = preg_replace('/\b(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{32,}\b/', '<token>', $s) ?? $s;

        // Email addresses, also URL-encoded (ada%40gmail.com).
        $s = preg_replace('/[A-Z0-9._%+\-]+(?:@|%40)[A-Z0-9.\-]+\.[A-Z]{2,}/i', '<email>', $s) ?? $s;

        // Phone numbers: international (+234 803 ..., +237 6...), Nigerian local
        // (0803..., 234803...), also written with spaces or dashes (0803 123 4567,
        // 0803-123-4567), and Cameroonian (237 6xx...) forms.
        $s = preg_replace('/\+\d[\d\s\-]{8,16}\d/', '<phone>', $s) ?? $s;
        $s = preg_replace('/(?<![\w.])(?:234[\s\-]?|0)[789][01]\d[\s\-]?\d{3}[\s\-]?\d{4}(?![\w.])/', '<phone>', $s) ?? $s;
        $s = preg_replace('/(?<![\w.])237[\s\-]?[26]\d{2}[\s\-]?\d{3}[\s\-]?\d{3}(?![\w.])/', '<phone>', $s) ?? $s;

        // Values a database driver quotes back: Duplicate entry 'ada@x.ng' for
        // key, Incorrect integer value: 'abc', Data truncated for column ... at row
        // (MySQL quotes values without escaping, so a value runs to the quote
        // before "for" / "at" or the end of the line).
        $s = preg_replace('/\b(entry|value:?)\s+\'.*?\'(?=\s+(?:for|at)\b|\s*$|\s*\))/im', '$1 \'?\'', $s) ?? $s;

        return $s;
    }
}

if (!function_exists('monitor_secret_values')) {
    /**
     * The values of .env settings whose NAMES say they are secret, longest
     * first so a value that contains another is replaced whole. Values shorter
     * than 6 characters are left alone (they would mangle ordinary words).
     * Never printed or returned anywhere but here.
     *
     * @return array<string,string> name => value
     */
    function monitor_secret_values(): array
    {
        // Worked out again whenever $_ENV has changed, so a warning met before
        // db.php loaded .env (a session_start() warning) cannot leave an empty
        // list behind for the rest of the request.
        static $cache = null;
        static $seen = -1;
        if ($cache !== null && $seen === count($_ENV)) {
            return $cache;
        }
        $seen = count($_ENV);
        $cache = [];
        $env = $_ENV;
        if ($env === [] && !function_exists('site_env')) {
            require_once dirname(__DIR__) . '/site_url.php';   // reads .env itself when db.php has not
        }
        if (function_exists('site_env') && $env === []) {
            // One name per line: CI's secret sweep reads "NAME', '<long text>" as an inlined secret.
            $names = [
                'DB_PASS',
                'MAIL_PASSWORD',
                'SMS_VAULT_KEY',
                'SE_HASH_PEPPER',
                'DEPLOY_WEBHOOK_SECRET',
                'EDGE_TOKEN',
                'MONITOR_SECRET',
                'MONITOR_PROBE_SECRET',
                'VAPID_PRIVATE_KEY',
                'GEMINI_API_KEY',
                'ABLY_API_KEY',
                'BIBLE_API_KEY',
                'SMS_WEBHOOK_SECRET',
                'GEOAPIFY_API_KEY',
            ];
            foreach ($names as $k) {
                $env[$k] = site_env($k);
            }
        }
        foreach ($env as $name => $value) {
            $value = trim((string) $value);
            if (strlen($value) >= 6 && preg_match('/PASS|SECRET|KEY|TOKEN|PEPPER|VAULT|PRIVATE/i', (string) $name)) {
                $cache[(string) $name] = $value;
            }
        }
        uasort($cache, static fn ($a, $b) => strlen($b) <=> strlen($a));

        return $cache;
    }
}

if (!function_exists('monitor_clean_url')) {
    /**
     * A page address with nothing private in it.
     *
     * Returns [path, route]: path keeps the real segments but turns tokens into
     * :token and the query into its parameter names (?id=…&tab=…); route also
     * turns numbers into :id and Special Events slugs into :event, so the same
     * screen groups together. No host, no fragment.
     *
     * @return array{0:string,1:string}
     */
    function monitor_clean_url(?string $url): array
    {
        $url = (string) $url;
        if ($url === '') {
            return ['/', '/'];
        }
        $parts = parse_url($url);
        if ($parts === false) {
            $parts = ['path' => strtok($url, '?#') ?: '/'];
        }
        $rawPath = (string) ($parts['path'] ?? '/');
        $segments = explode('/', trim($rawPath, '/'));
        $path = [];
        $route = [];
        $prev = '';
        foreach ($segments as $i => $seg) {
            if ($seg === '') {
                continue;
            }
            $seg = rawurldecode($seg);
            $was = $seg;
            // A token: 16+ URL-safe characters with a digit or a capital (slugs and
            // file names are lower case), or anything after /me/ (Special Events
            // manage links, /e/<event>/me/<22-character token>, can be all letters).
            if (preg_match('/^[A-Za-z0-9_\-]{16,}$/', $seg) && (preg_match('/[0-9A-Z]/', $seg) || $prev === 'me')) {
                $seg = ':token';
            } elseif ($prev === 'me' && preg_match('/^[A-Za-z0-9_\-]{8,}$/', $seg)) {
                $seg = ':token';
            } elseif (mb_strlen($seg) > 60) {
                $seg = ':long';
            } elseif (preg_match('/[@+]|\d{7,}/', $seg)) {
                $seg = ':value';   // an email or phone number in the path
            }
            $path[] = $seg;
            if (ctype_digit($seg)) {
                $route[] = ':id';
            } elseif ($i === 1 && ($segments[0] ?? '') === 'e') {
                $route[] = ':event';
            } else {
                $route[] = $seg;
            }
            $prev = $was;
        }
        // Clean addresses (Nov 2026): /x.php and /x are the same page, and so
        // are /dir/index.php and /dir/. The route takes the clean form so old
        // and new addresses group together; endpoints keep their .php.
        $routeSlash = false;
        if ($route && !in_array($segments[0] ?? '', ['api', 'webhook', 'email', 'includes', 'cron', 'db', 'bin', 'assets', 'uploads'], true)) {
            $last = $route[count($route) - 1];
            if ($last === 'index.php') {
                array_pop($route);
                $routeSlash = true;
            } elseif (str_ends_with($last, '.php')) {
                $route[count($route) - 1] = substr($last, 0, -4);
            }
        }
        $p = '/' . implode('/', $path);
        $r = '/' . implode('/', $route);
        if (str_ends_with($rawPath, '/') && $p !== '/') {
            $p .= '/';
        }
        if ((str_ends_with($rawPath, '/') || $routeSlash) && $r !== '/') {
            $r .= '/';
        }
        if (!empty($parts['query'])) {
            $names = [];
            foreach (explode('&', (string) $parts['query']) as $pair) {
                $name = rawurldecode(strtok($pair, '=') ?: '');
                if ($name !== '' && preg_match('/^[A-Za-z0-9_\-\[\]\.]{1,40}$/', $name)) {
                    $names[$name] = $name . '=…';
                }
            }
            if ($names) {
                $p .= '?' . implode('&', array_slice($names, 0, 12));
            }
        }

        return [mb_substr($p, 0, 255), mb_substr($r, 0, 190)];
    }
}

if (!function_exists('monitor_browser_family')) {
    /** "Chrome 131 · Android" — enough to reproduce, too little to identify. */
    function monitor_browser_family(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return '';
        }
        $os = match (true) {
            str_contains($ua, 'Android')                                => 'Android',
            (bool) preg_match('/iPhone|iPad|iPod/', $ua)                => 'iOS',
            str_contains($ua, 'Windows')                                => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS')                                   => 'ChromeOS',
            str_contains($ua, 'Linux')                                  => 'Linux',
            default                                                     => '',
        };
        $browser = '';
        foreach ([
            'Edge'    => '/Edg(?:e|A|iOS)?\/(\d+)/',
            'Opera'   => '/(?:OPR|Opera)\/(\d+)/',
            'Samsung' => '/SamsungBrowser\/(\d+)/',
            'Firefox' => '/(?:Firefox|FxiOS)\/(\d+)/',
            'Chrome'  => '/(?:Chrome|CriOS)\/(\d+)/',
            'Safari'  => '/Version\/(\d+).*Safari/',
        ] as $name => $re) {
            if (preg_match($re, $ua, $m)) {
                $browser = $name . ' ' . $m[1];
                break;
            }
        }
        if ($browser === '' && preg_match('/bot|crawl|spider|slurp|curl|wget|python|httpclient/i', $ua)) {
            $browser = 'Bot';
        }

        return mb_substr(trim($browser . ($os !== '' ? ' · ' . $os : '')), 0, 60);
    }
}

if (!function_exists('monitor_device_class')) {
    /** phone, tablet or desktop, from the user agent. */
    function monitor_device_class(?string $ua): string
    {
        $ua = (string) $ua;
        if (preg_match('/iPad|Tablet|PlayBook|Silk|(Android(?!.*Mobile))/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|iPhone|iPod|Android|Windows Phone|Opera Mini/i', $ua)) {
            return 'phone';
        }

        return 'desktop';
    }
}

if (!function_exists('monitor_short_path')) {
    /** A file inside the site as modules/events/index.php, never the server's full path. */
    function monitor_short_path(?string $file): ?string
    {
        if ($file === null || $file === '') {
            return null;
        }
        $file = str_replace('\\', '/', $file);
        $root = str_replace('\\', '/', dirname(__DIR__, 2)) . '/';
        if (str_starts_with($file, $root)) {
            return mb_substr(substr($file, strlen($root)), 0, 255);
        }
        // The same tree seen from the other copy (the repository checkout runs
        // bin/ and db/ scripts; the docroot serves pages): keep the part after it.
        if (preg_match('#/(?:public_html/hodlekki|repositories/hodlekki)/(.+)$#', $file, $m)) {
            return mb_substr($m[1], 0, 255);
        }
        if (preg_match('#^/home/[^/]+/(.+)$#', $file, $m)) {
            return mb_substr('~/' . $m[1], 0, 255);
        }

        return mb_substr($file, 0, 255);
    }
}
