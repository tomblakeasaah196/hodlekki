<?php
// /includes/trainings/bootstrap.php
//
// Trainings, the new HOD Academy (TRAININGS-PLAN.md). PR 1, the foundation:
// trainings built from items, the length wizard's empty frame, the catalogue
// and Assign, visitor invites, the learner's Trainings page and completion
// that the Academy confirms. Exams (PR 2) and Google Meet (PR 3) hook in
// through training_items (type 'exam' / 'live') and add their own tables.
//
//   build.php     trainings, sections (days / weeks / modules), items, media, templates
//   people.php    enrolment, the catalogue, Assign (groups + named people), invites
//   progress.php  a learner's progress, handing in work, marking, completion, confirming
//   notes.php     My notes: the learner's own running note on a training, and its Word copy
//   tests.php     standalone tests (no course): a training of kind 'test' with one exam item (docs/EXAMS.md §8)
//   actions.php   the one entry point api/trainings_api.php calls (every can() check)
//
// Access is the 'trainings' row of the permission matrix: Create builds and
// assigns, Edit changes any training and marks work, Delete removes one,
// Approve confirms graduates. A learner's own records (scope 'own') are
// their enrolments, the items they do and the work they hand in.
//
// Every query is portable (MySQL 8, MariaDB 10.11 and the SQLite tests): no
// NOW(), no INSERT IGNORE, no ON DUPLICATE KEY. Times come from PHP, in Lagos.

require_once dirname(__DIR__) . '/upload_guard.php';
// Exams are PR 2's (includes/exams/, docs/EXAMS.md): loaded when they are here,
// so every link to an exam page goes through its own URL helpers.
if (is_file(dirname(__DIR__) . '/exams/bootstrap.php')) {
    require_once dirname(__DIR__) . '/exams/bootstrap.php';
}
// Live classes on Google Meet are PR 3's (includes/training_live/, docs/INTEGRATIONS.md): the Meet page link,
// Join class and attendance, through training_live_*() hooks guarded by function_exists().
if (is_file(dirname(__DIR__) . '/training_live/bootstrap.php')) {
    require_once dirname(__DIR__) . '/training_live/bootstrap.php';
}
require_once __DIR__ . '/pptx.php';
require_once __DIR__ . '/build.php';
require_once __DIR__ . '/people.php';
require_once __DIR__ . '/progress.php';
require_once __DIR__ . '/certificates.php';
require_once __DIR__ . '/reminders.php';
require_once __DIR__ . '/notes.php';
require_once __DIR__ . '/tests.php';
require_once __DIR__ . '/actions.php';

const TRAININGS_TZ = 'Africa/Lagos';
const TRAININGS_LENGTHS = ['one_day' => 'One day', 'days' => 'A few days', 'weeks' => 'Weeks', 'self_paced' => 'Self-paced'];
const TRAININGS_STATUSES = ['draft' => 'Draft', 'published' => 'Published', 'archived' => 'Archived'];
const TRAININGS_ITEM_TYPES = ['live' => 'Live class', 'video' => 'Video', 'audio' => 'Audio', 'reading' => 'Reading',
    'assignment' => 'Assignment', 'exam' => 'Exam'];
// What the builder makes itself; 'exam' joins once PR 2's pages are here (trainings_builder_types()).
const TRAININGS_BUILDER_TYPES = ['live', 'video', 'audio', 'reading', 'assignment'];
const TRAININGS_OFFICIAL = ['membership' => 'Membership Class', 'maturity' => 'Maturity Class'];
const TRAININGS_MAX_DAYS = 14;
const TRAININGS_MAX_WEEKS = 26;
const TRAININGS_MAX_SECTIONS = 60;
const TRAININGS_MAX_ITEMS = 300;
const TRAININGS_DEPARTED = ['Relocated', 'Attends_Another_Church'];
const TRAININGS_TABLES = ['trainings', 'training_sections', 'training_items', 'training_enrolments',
    'training_assignments', 'training_progress', 'training_invites'];

/** True once the core migration has run. Until then every screen says "being set up". */
function trainings_ready(PDO $pdo): bool
{
    static $known = null;
    $known ??= new WeakMap();
    if (!empty($known[$pdo])) {
        return true;
    }
    try {
        foreach (TRAININGS_TABLES as $table) {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 0")->closeCursor();
        }
    } catch (Throwable $e) {
        return false;
    }
    $known[$pdo] = true;
    return true;
}

/** The kinds of item the builder offers: exams only once PR 2 can set them up. */
function trainings_builder_types(): array
{
    return function_exists('exam_url_setup') ? array_merge(TRAININGS_BUILDER_TYPES, ['exam']) : TRAININGS_BUILDER_TYPES;
}

/** Where a tutor sets up an exam item (PR 2's exam_url_setup()), or null before PR 2. */
function trainings_exam_setup_url(int $itemId): ?string
{
    return function_exists('exam_url_setup') ? (string) exam_url_setup($itemId) : null;
}

/** Where a learner takes an exam item (PR 2's exam_url_take()), or null before PR 2. */
function trainings_exam_take_url(int $itemId): ?string
{
    return function_exists('exam_url_take') ? (string) exam_url_take($itemId) : null;
}

/** Is this table there? (Optional platform tables: status history, email, security.) */
function trainings_table_exists(PDO $pdo, string $table): bool
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

/** The clock. Tests pin it with $GLOBALS['trainings_now']. */
function trainings_now(): DateTimeImmutable
{
    if (isset($GLOBALS['trainings_now']) && $GLOBALS['trainings_now'] instanceof DateTimeImmutable) {
        return $GLOBALS['trainings_now'];
    }
    return new DateTimeImmutable('now', new DateTimeZone(TRAININGS_TZ));
}

function trainings_sql_dt(?DateTimeImmutable $at = null): string
{
    return ($at ?? trainings_now())->format('Y-m-d H:i:s');
}

function trainings_today(): string
{
    return trainings_now()->format('Y-m-d');
}

/** A date typed into a form (Y-m-d), or null. */
function trainings_date($value): ?string
{
    $value = trim((string) $value);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone(TRAININGS_TZ));
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/** A time typed into a form (HH:MM, 24 h), or null. */
function trainings_time($value): ?string
{
    $value = trim((string) $value);
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
}

function trainings_add_days(string $ymd, int $days): string
{
    return (new DateTimeImmutable($ymd, new DateTimeZone(TRAININGS_TZ)))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
}

/** Whole days from $from to $to (both Y-m-d). */
function trainings_days_between(string $from, string $to): int
{
    $tz = new DateTimeZone(TRAININGS_TZ);
    return (int) (new DateTimeImmutable($from, $tz))->diff(new DateTimeImmutable($to, $tz))->format('%r%a');
}

/** Trimmed, single-line unless $multiline, cut to $max characters. */
function trainings_text($value, int $max, bool $multiline = false): string
{
    $s = is_scalar($value) ? (string) $value : '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = $multiline ? preg_replace("/\n{3,}/", "\n\n", $s) : preg_replace('/\s+/', ' ', $s);
    $s = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $s));
    return mb_substr($s, 0, $max, 'UTF-8');
}

function trainings_json($value): string
{
    return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function trainings_decode($json): array
{
    if (!is_string($json) || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

/** ['status' => 'success'] plus the payload. */
function trainings_ok(array $data = []): array
{
    return ['status' => 'success'] + $data;
}

/** One line saying what to do. $field puts it under that form field. */
function trainings_fail(string $message, string $code = 'INVALID', ?string $field = null): array
{
    $out = ['status' => 'error', 'code' => $code, 'message' => $message];
    if ($field !== null) {
        $out['field'] = $field;
    }
    return $out;
}

/** "Ada Obi" */
function trainings_person_name(array $u): string
{
    return trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? ''));
}

/** "1st Timer" */
function trainings_status_label(?string $status): string
{
    return str_replace('_', ' ', (string) $status);
}

/** A notification in the bell (and the phone, through the push worker). Best effort. */
function trainings_notify(PDO $pdo, array $userIds, string $title, string $message, string $link): int
{
    if ($userIds === [] || !trainings_table_exists($pdo, 'system_notifications')) {
        return 0;
    }
    $n = 0;
    $stmt = $pdo->prepare('INSERT INTO system_notifications (user_id, title, message, link_url) VALUES (?, ?, ?, ?)');
    foreach (array_unique(array_map('intval', $userIds)) as $id) {
        if ($id <= 0) {
            continue;
        }
        try {
            $stmt->execute([$id, $title, $message, $link]);
            $n++;
        } catch (Throwable $e) {
            error_log('[trainings] notification failed: ' . $e->getMessage());
        }
    }
    return $n;
}
