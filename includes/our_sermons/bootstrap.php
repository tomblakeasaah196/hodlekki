<?php
// /includes/our_sermons/bootstrap.php
//
// Our sermons (OUR-SERMONS-PLAN.md): the members' sermon library, separate
// from the public Word Library (/sermons, the `sermons` table and Envision's
// Sermon Archive, all left alone). PR S1, the foundation and Envision's
// manager:
//
//   parse.php    the episode-title parser (preacher, date preached, service),
//                Spotify / YouTube links, durations, titles for matching
//   fetch.php    the one way out to the internet: https only, public hosts,
//                size caps; oEmbed (Spotify, YouTube), the RSS reader, artwork
//   store.php    sermons: create from a feed item or a pasted link, save,
//                publish (tells everyone once), hide, delete, read for pages
//   feeds.php    the RSS feeds and the poller (every 15 minutes, from
//                cron/automation_tick.php: our_sermons_feeds_run())
//   actions.php  the one entry point api/our_sermons_api.php calls (every can() check)
//
// Access is the 'our_sermons' row of the permission matrix: everyone View;
// Envision's seats and the pastors Create / Edit / Delete / Manage.
//
// Hooks for the other PRs (guarded by function_exists(), so S1 stands alone):
//   our_sermons_ai_queue($pdo, $id)   S3 (ai.php): draft the summary etc. for a new draft
//   our_sermons_ai_panel($pdo, $row, $back)  S3: the AI badge and Regenerate on Envision's editor
//
// Every query is portable (MySQL 8, MariaDB 10.11 and the SQLite tests): no
// NOW(), no INSERT IGNORE, no ON DUPLICATE KEY. Times come from PHP, in Lagos.

require_once dirname(__DIR__) . '/upload_guard.php';
require_once __DIR__ . '/parse.php';
require_once __DIR__ . '/fetch.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/feeds.php';
require_once __DIR__ . '/actions.php';
// S3 (AI summaries) lives in its own file: our_sermons_ai_queue() and our_sermons_ai_panel(), when it is here.
if (is_file(__DIR__ . '/ai.php')) {
    require_once __DIR__ . '/ai.php';
}

const OUR_SERMONS_TZ = 'Africa/Lagos';
const OUR_SERMONS_TABLES = ['our_sermons', 'our_sermon_feeds', 'our_sermon_feed_skips'];
const OUR_SERMONS_STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'hidden' => 'Hidden'];
const OUR_SERMONS_SERVICES = [
    'sunday' => 'Sunday service',
    'sunday_1' => 'Sunday 1st service',
    'sunday_2' => 'Sunday 2nd service',
    'sunday_3' => 'Sunday 3rd service',
    'midweek' => 'Midweek (Exousia)',
    'special' => 'Special service',
];
const OUR_SERMONS_ARTWORK_URL = '/uploads/sermons/artwork/';
const OUR_SERMONS_TITLE_MAX = 255;
const OUR_SERMONS_PREACHER_MAX = 120;
const OUR_SERMONS_SERIES_MAX = 120;
const OUR_SERMONS_SUMMARY_MAX = 5000;
const OUR_SERMONS_POINT_MAX = 300;
const OUR_SERMONS_POINTS_MAX = 12;
const OUR_SERMONS_SCRIPTURES_MAX = 20;
const OUR_SERMONS_PAGE_SIZE = 24;

/** True once the migration has run. Until then every screen says "Almost ready". */
function our_sermons_ready(PDO $pdo): bool
{
    static $known = null;
    $known ??= new WeakMap();
    if (!empty($known[$pdo])) {
        return true;
    }
    try {
        foreach (OUR_SERMONS_TABLES as $table) {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 0")->closeCursor();
        }
    } catch (Throwable $e) {
        return false;
    }
    $known[$pdo] = true;
    return true;
}

/** Is this table there? (system_notifications, …) */
function our_sermons_table_exists(PDO $pdo, string $table): bool
{
    static $seen = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (isset($seen[$key])) {
        return $seen[$key];
    }
    try {
        $pdo->query("SELECT 1 FROM {$table} LIMIT 0")->closeCursor();
        return $seen[$key] = true;
    } catch (Throwable $e) {
        return false;
    }
}

/** The clock. Tests pin it with $GLOBALS['our_sermons_now']. */
function our_sermons_now(): DateTimeImmutable
{
    if (isset($GLOBALS['our_sermons_now']) && $GLOBALS['our_sermons_now'] instanceof DateTimeImmutable) {
        return $GLOBALS['our_sermons_now'];
    }
    return new DateTimeImmutable('now', new DateTimeZone(OUR_SERMONS_TZ));
}

function our_sermons_sql_dt(?DateTimeImmutable $at = null): string
{
    return ($at ?? our_sermons_now())->format('Y-m-d H:i:s');
}

/** A date typed into a form (Y-m-d), or null. */
function our_sermons_date($value): ?string
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(OUR_SERMONS_TZ));
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** Trimmed, single-line unless $multiline, control characters out, cut to $max characters. */
function our_sermons_text($value, int $max, bool $multiline = false): string
{
    $s = is_scalar($value) ? (string) $value : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = $multiline ? preg_replace("/\n{3,}/", "\n\n", $s) : preg_replace('/\s+/u', ' ', $s);
    $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

function our_sermons_json($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** A JSON array column back to a list of strings. */
function our_sermons_list($json): array
{
    if (!is_string($json) || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    if (!is_array($v)) {
        return [];
    }
    return array_values(array_filter(array_map(static fn ($x) => is_scalar($x) ? trim((string) $x) : '', $v), static fn ($x) => $x !== ''));
}

/** ['status' => 'success'] plus the payload. */
function our_sermons_ok(array $data = []): array
{
    return ['status' => 'success'] + $data;
}

/** One line saying what to do. $field puts it under that form field. */
function our_sermons_fail(string $message, string $code = 'INVALID', ?string $field = null): array
{
    $out = ['status' => 'error', 'code' => $code, 'message' => $message];
    if ($field !== null) {
        $out['field'] = $field;
    }
    return $out;
}

/** "52 min", "1 h 05 min", or '' when unknown. */
function our_sermons_length_label(?int $seconds): string
{
    if ($seconds === null || $seconds <= 0) {
        return '';
    }
    $minutes = (int) round($seconds / 60);
    if ($minutes < 60) {
        return max(1, $minutes) . ' min';
    }
    return intdiv($minutes, 60) . ' h ' . str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT) . ' min';
}

/** "Sun 8 Nov 2026" */
function our_sermons_date_label(?string $ymd, bool $weekday = true): string
{
    $d = $ymd ? DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10), new DateTimeZone(OUR_SERMONS_TZ)) : false;
    return $d ? $d->format($weekday ? 'D j M Y' : 'j M Y') : '';
}

function our_sermons_service_label(?string $key): string
{
    return OUR_SERMONS_SERVICES[(string) $key] ?? '';
}

/** Where members open a sermon (the listening page, S2 replaces it in place). */
function our_sermons_url(int $id): string
{
    return '/modules/our_sermons/sermon?id=' . $id;
}

/** Where Envision checks a sermon. */
function our_sermons_manage_url(?int $id = null): string
{
    return '/modules/our_sermons/manage' . ($id !== null ? '?id=' . $id : '');
}
