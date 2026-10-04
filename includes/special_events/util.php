<?php
// /includes/special_events/util.php
//
// Small, dependency-free helpers: random identifiers, Crockford base32,
// JSON encode/decode with the module's flags, keyed hashing, time formatting
// and the Markdown renderer used for descriptions and the privacy notice.
//
// Nothing here touches the database or $_SESSION, so every function in this
// file is unit-testable from the CLI (tests/special_events/).

// --------------------------------------------------------------------------
// Identifiers
// --------------------------------------------------------------------------

/**
 * Crockford base32 alphabet: no I, L, O or U, so an identifier read aloud or
 * copied off a poster cannot be confused (and cannot spell English words).
 */
const SE_BASE32_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

/** Encode bytes as Crockford base32. Length is ceil(bytes * 8 / 5). */
function se_base32_encode(string $bytes): string
{
    $out  = '';
    $bits = 0;
    $acc  = 0;
    $len  = strlen($bytes);

    for ($i = 0; $i < $len; $i++) {
        $acc   = ($acc << 8) | ord($bytes[$i]);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out  .= SE_BASE32_ALPHABET[($acc >> $bits) & 31];
        }
    }
    if ($bits > 0) {
        $out .= SE_BASE32_ALPHABET[($acc << (5 - $bits)) & 31];
    }

    return $out;
}

/**
 * A random Crockford-base32 string of exactly $length characters.
 * Used for public ids, registration codes and referral codes — things people
 * read, type or see in a URL.
 */
function se_random_code(int $length): string
{
    if ($length < 1) {
        throw new InvalidArgumentException('se_random_code: length must be >= 1');
    }
    $need = (int) ceil($length * 5 / 8) + 1;

    return substr(se_base32_encode(random_bytes($need)), 0, $length);
}

/**
 * A random URL-safe token of exactly $length characters (A-Z a-z 0-9 _ -).
 * Used for secrets that are only ever copied by machines: manage tokens,
 * device cookies, preview keys and display keys.
 */
function se_random_token(int $length = 22): string
{
    if ($length < 1) {
        throw new InvalidArgumentException('se_random_token: length must be >= 1');
    }
    $out = '';
    while (strlen($out) < $length) {
        $out .= rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    return substr($out, 0, $length);
}

/** A 12-character public id for an event (`se_events.public_id`). */
function se_new_public_id(): string
{
    return se_random_code(SE_PUBLIC_ID_LENGTH);
}

// --------------------------------------------------------------------------
// Hashing
// --------------------------------------------------------------------------

/**
 * The HMAC pepper from .env. Required (§19.9): without it the module refuses
 * to issue or verify tokens rather than falling back to an unkeyed hash.
 *
 * @throws RuntimeException when SE_HASH_PEPPER is missing or too short.
 */
function se_hash_pepper(): string
{
    static $pepper = null;
    if ($pepper !== null) {
        return $pepper;
    }

    $raw = (string) ($_ENV['SE_HASH_PEPPER'] ?? '');
    if (strlen($raw) < 32) {
        // Never log the value itself, only that it is unusable.
        error_log('SE config: SE_HASH_PEPPER is missing or shorter than 32 characters; token issuing is disabled.');
        throw new RuntimeException('SE_HASH_PEPPER is not configured');
    }

    return $pepper = $raw;
}

/** True when the pepper is usable, so callers can degrade without throwing. */
function se_has_hash_pepper(): bool
{
    try {
        se_hash_pepper();
        return true;
    } catch (RuntimeException $e) {
        return false;
    }
}

/**
 * Keyed hash of a secret or an identifier, for storage.
 *
 * Tokens, device cookies, IPs and user agents are stored only as
 * HMAC-SHA256(pepper, domain . ':' . value) (§9.1). The domain prefix keeps
 * the hash of an IP from ever colliding with the hash of a token.
 */
function se_hmac(string $domain, string $value): string
{
    return hash_hmac('sha256', $domain . ':' . $value, se_hash_pepper());
}

/** Constant-time comparison of two hex digests. */
function se_hash_equals(string $a, string $b): bool
{
    return hash_equals($a, $b);
}

// --------------------------------------------------------------------------
// JSON
// --------------------------------------------------------------------------

/**
 * Encode for storage in a *_json TEXT column, or for an API response body.
 * Unicode and slashes stay readable so stored settings can be inspected by
 * hand in phpMyAdmin.
 */
function se_json_encode(mixed $value): string
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return $json === false ? 'null' : $json;
}

/**
 * Encode for embedding in an inline <script type="application/json"> block.
 * The HEX flags make `</script>`, `&`, `'` and `"` inert in HTML (§19.5).
 */
function se_json_for_html(mixed $value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );

    return $json === false ? 'null' : $json;
}

/** Decode a stored *_json column. Always returns an array. */
function se_json_decode(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : [];
}

// --------------------------------------------------------------------------
// Scalar coercion (used by the settings normaliser and every endpoint)
// --------------------------------------------------------------------------

function se_str(mixed $v, int $maxLength = 255): string
{
    if (is_bool($v))  { return $v ? '1' : '0'; }
    if (is_array($v) || is_object($v) || $v === null) { return ''; }

    $s = trim((string) $v);
    // Strip control characters except tab and newline, then collapse to the cap.
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';

    return mb_substr($s, 0, $maxLength, 'UTF-8');
}

/** A single-line string: newlines and runs of whitespace collapse to a space. */
function se_line(mixed $v, int $maxLength = 255): string
{
    $s = preg_replace('/\s+/u', ' ', se_str($v, $maxLength * 2)) ?? '';

    return mb_substr(trim($s), 0, $maxLength, 'UTF-8');
}

function se_int(mixed $v, ?int $min = null, ?int $max = null, int $default = 0): int
{
    if (is_bool($v)) { $n = $v ? 1 : 0; }
    elseif (is_numeric($v)) { $n = (int) $v; }
    else { $n = $default; }

    if ($min !== null && $n < $min) { $n = $min; }
    if ($max !== null && $n > $max) { $n = $max; }

    return $n;
}

/** Nullable integer: '' and null stay null, so "unlimited" survives a round trip. */
function se_int_or_null(mixed $v, ?int $min = null, ?int $max = null): ?int
{
    if ($v === null || $v === '' || (is_string($v) && trim($v) === '')) {
        return null;
    }
    if (!is_numeric($v) && !is_bool($v)) {
        return null;
    }

    return se_int($v, $min, $max);
}

function se_bool(mixed $v): bool
{
    if (is_bool($v)) { return $v; }
    if (is_numeric($v)) { return (float) $v != 0.0; }
    if (is_string($v)) {
        return in_array(strtolower(trim($v)), ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

function se_float(mixed $v, float $min, float $max, float $default): float
{
    if (!is_numeric($v)) { return $default; }
    $f = (float) $v;

    return $f < $min ? $min : ($f > $max ? $max : $f);
}

/** Value if it is one of $allowed, else $default. */
function se_enum(mixed $v, array $allowed, string $default): string
{
    $s = is_string($v) ? trim($v) : '';

    return in_array($s, $allowed, true) ? $s : $default;
}

// --------------------------------------------------------------------------
// Names
// --------------------------------------------------------------------------

/**
 * Validate and tidy a person's name (§19.5): 1-80 characters of Unicode
 * letters, marks, spaces, apostrophes, hyphens and full stops. Returns '' when
 * the input holds nothing usable, so the caller can raise a field error.
 */
function se_clean_name(mixed $v): string
{
    $s = se_line($v, 120);
    if ($s === '') {
        return '';
    }
    // Keep letters/marks; drop anything else except the three joiners.
    $s = preg_replace("/[^\p{L}\p{M} '.\-]/u", '', $s) ?? '';
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    $s = trim($s, " '-.");

    return mb_substr($s, 0, 80, 'UTF-8');
}

/**
 * Title-case for display: "ADA" and "ada" both become "Ada", while "McBride"
 * and "O'Neil" keep their inner capitals (we only ever raise the first letter
 * of each part, never lower the rest of an already mixed-case word).
 */
function se_name_case(string $name): string
{
    $parts = preg_split("/([ '\-])/u", $name, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
    $out   = '';
    foreach ($parts as $part) {
        if ($part === '' || preg_match("/^[ '\-]$/u", $part)) {
            $out .= $part;
            continue;
        }
        $isUniform = ($part === mb_strtoupper($part, 'UTF-8')) || ($part === mb_strtolower($part, 'UTF-8'));
        $body      = $isUniform ? mb_strtolower($part, 'UTF-8') : $part;
        $out .= mb_strtoupper(mb_substr($body, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($body, 1, null, 'UTF-8');
    }

    return $out;
}

/**
 * The only name shape that reaches a screen: "Ada O." (§D25).
 * Falls back to the first name alone when there is no surname, and to "Guest"
 * when there is no name at all. Capped at the 40-char display_name column.
 */
function se_display_name(string $firstName, string $lastName = ''): string
{
    $first = se_name_case(se_clean_name($firstName));
    $last  = se_name_case(se_clean_name($lastName));

    if ($first === '') {
        return 'Guest';
    }
    $out = $first;
    if ($last !== '') {
        $out .= ' ' . mb_strtoupper(mb_substr($last, 0, 1, 'UTF-8'), 'UTF-8') . '.';
    }

    return mb_substr($out, 0, 40, 'UTF-8');
}

// --------------------------------------------------------------------------
// Time
// --------------------------------------------------------------------------

/** The module's timezone. PHP is already in Africa/Lagos (includes/db.php). */
function se_tz(): DateTimeZone
{
    static $tz = null;

    return $tz ??= new DateTimeZone('Africa/Lagos');
}

function se_now(): DateTimeImmutable
{
    return new DateTimeImmutable('now', se_tz());
}

/** Parse a stored DATETIME (or any accepted string) in WAT. Null when unusable. */
function se_parse_datetime(?string $value): ?DateTimeImmutable
{
    if ($value === null || trim($value) === '' || str_starts_with($value, '0000-00-00')) {
        return null;
    }
    try {
        return new DateTimeImmutable($value, se_tz());
    } catch (Exception $e) {
        return null;
    }
}

/** Format for a MySQL DATETIME column. */
function se_sql_datetime(DateTimeImmutable $when): string
{
    return $when->format('Y-m-d H:i:s');
}

/** Format for a MySQL DATETIME(3) column (live timings, §9.1). */
function se_sql_datetime_ms(DateTimeImmutable $when): string
{
    return $when->format('Y-m-d H:i:s.v');
}

/** ISO 8601 with offset, for display in API responses (§12.1). */
function se_iso(?string $sqlDatetime): ?string
{
    $dt = se_parse_datetime($sqlDatetime);

    return $dt?->format('c');
}

/** Epoch milliseconds, for live timing fields (`*_ms`, §12.1). */
function se_epoch_ms(?DateTimeImmutable $when = null): int
{
    $when ??= se_now();

    return (int) round((float) $when->format('U.u') * 1000);
}

/**
 * A human date range for one event ("Sat 24 Oct, 5:00 PM" or
 * "24-26 Oct 2026"), used in the shell, cards and SMS.
 */
function se_format_day(DateTimeImmutable $start): string
{
    return $start->format('D j M, g:i A');
}

// --------------------------------------------------------------------------
// Markdown (§19.5)
// --------------------------------------------------------------------------

/**
 * Escape-first Markdown renderer for event descriptions, FAQ answers and the
 * privacy notice. Modelled on reach_markdown() but with the portal's classes
 * and no HTML pass-through anywhere: the text is escaped before a single tag
 * is added, so a user cannot inject markup even through a link target.
 *
 * Supports: #/##/### headings, **bold**, *italic*, `code`, [links](url) with
 * http(s)/relative/#anchor targets only, - and 1. lists, > quotes, --- rules
 * and paragraphs. Everything else is literal text.
 */
function se_markdown(string $md): string
{
    $inline = static function (string $t): string {
        $t = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
        $t = preg_replace('/`([^`]+)`/', '<code class="se-md-code">$1</code>', $t) ?? $t;
        $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t) ?? $t;
        $t = preg_replace('/(?<![\*\w])\*(?!\s)(.+?)(?<!\s)\*(?![\*\w])/', '<em>$1</em>', $t) ?? $t;

        return preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function (array $m): string {
            // $m[2] is already HTML-escaped; decode only to test the scheme.
            $target = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
            if (!preg_match('#^(https?://|/|\#)[^\s]*$#i', $target)) {
                return $m[1];
            }
            $external = str_starts_with(strtolower($target), 'http');
            $rel      = $external ? ' target="_blank" rel="noopener noreferrer"' : '';

            return '<a href="' . $m[2] . '" class="se-md-link"' . $rel . '>' . $m[1] . '</a>';
        }, $t) ?? $t;
    };

    $html  = '';
    $para  = [];
    $list  = null;
    $items = [];

    $flushPara = static function () use (&$html, &$para, $inline): void {
        if ($para) {
            $html .= '<p>' . implode(' ', $para) . '</p>';
            $para = [];
        }
    };
    $flushList = static function () use (&$html, &$list, &$items): void {
        if ($list) {
            $html .= "<{$list} class=\"se-md-list\">"
                . implode('', array_map(static fn($i) => "<li>{$i}</li>", $items))
                . "</{$list}>";
            $list  = null;
            $items = [];
        }
    };

    foreach (preg_split('/\R/', $md) ?: [] as $line) {
        if (preg_match('/^(#{1,3})\s+(.+)$/', $line, $m)) {
            $flushPara(); $flushList();
            $n = strlen($m[1]) + 1;   // h1 in the body would fight the hero's h1
            $html .= "<h{$n} class=\"se-md-h{$n}\">" . $inline($m[2]) . "</h{$n}>";
        } elseif (preg_match('/^\s*(?:-{3,}|\*{3,})\s*$/', $line)) {
            $flushPara(); $flushList();
            $html .= '<hr class="se-md-rule">';
        } elseif (preg_match('/^\s*(?:([-*])|(\d+)\.)\s+(.+)$/', $line, $m)) {
            $flushPara();
            $type = ($m[1] ?? '') !== '' ? 'ul' : 'ol';
            if ($list !== $type) { $flushList(); $list = $type; }
            $items[] = $inline($m[3]);
        } elseif (preg_match('/^>\s?(.*)$/', $line, $m)) {
            $flushPara(); $flushList();
            $html .= '<blockquote class="se-md-quote">' . $inline($m[1]) . '</blockquote>';
        } elseif (trim($line) === '') {
            $flushPara(); $flushList();
        } else {
            $flushList();
            $para[] = $inline(trim($line));
        }
    }
    $flushPara(); $flushList();

    return $html;
}

/** Plain text from Markdown, for meta descriptions and SMS. */
function se_markdown_excerpt(string $md, int $maxLength = 160): string
{
    $text = html_entity_decode(strip_tags(se_markdown($md)), ENT_QUOTES, 'UTF-8');
    $text = se_line($text, $maxLength * 3);
    if (mb_strlen($text, 'UTF-8') <= $maxLength) {
        return $text;
    }

    return rtrim(mb_substr($text, 0, $maxLength - 1, 'UTF-8'), " ,.;:") . '…';
}

// --------------------------------------------------------------------------
// Misc
// --------------------------------------------------------------------------

/** Escape for HTML text or an attribute. Used by every server-rendered page. */
function se_h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * Mask a phone number for a screen a crew member without the PII capability
 * can see: "0803 *** 4567" keeps the last four digits for desk search.
 */
function se_mask_phone(string $phone): string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    $len    = strlen($digits);
    if ($len < 7) {
        return '***';
    }

    return substr($digits, 0, 4) . ' *** ' . substr($digits, -4);
}

/** Mask an email: "a***@example.com". */
function se_mask_email(string $email): string
{
    $at = strpos($email, '@');
    if ($at === false || $at < 1) {
        return '***';
    }

    return substr($email, 0, 1) . '***' . substr($email, $at);
}

/**
 * Atomic file write: write to a temp file in the same directory, then rename.
 * Readers (phones polling a snapshot) therefore never see a partial file.
 */
function se_write_file_atomic(string $path, string $contents): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('SE util: cannot create directory ' . $dir);
        return false;
    }
    $tmp = $dir . '/.' . basename($path) . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
        error_log('SE util: cannot write ' . $tmp);
        return false;
    }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $path)) {
        @unlink($tmp);
        error_log('SE util: cannot rename into ' . $path);
        return false;
    }

    return true;
}
