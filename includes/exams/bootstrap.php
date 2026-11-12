<?php
// /includes/exams/bootstrap.php
//
// Trainings PR 2: exams and the question bank. Guide: docs/EXAMS.md.
// The only entry point (after includes/db.php):
//   xlsx.php    the .xlsx writer and reader (the template, uploaded sheets)
//   bank.php    questions: check, save, approve; the Excel template and import
//   engine.php  exams: settings, the one attempt, the paper, answers, marking,
//               tab-leave notes, results
//   access.php  who may do what (Roles & permissions + enrolment), the API's actions
//
// Exams hang off PR 1's training items (type 'exam'). Everything asks
// exams_ready() first and degrades before the migration has run.

require_once __DIR__ . '/xlsx.php';

const EXAMS_TZ     = 'Africa/Lagos';
const EXAMS_TABLES = ['training_questions', 'training_question_imports', 'training_exams', 'training_attempts',
                      'training_attempt_answers', 'training_attempt_events'];

/** Now, in Lagos. Tests pin it with exams_set_clock(). */
function exams_now(): DateTimeImmutable
{
    return exams_set_clock() ?? new DateTimeImmutable('now', new DateTimeZone(EXAMS_TZ));
}

/** Pin (DateTimeImmutable), unpin (false) or read (no argument) the clock. */
function exams_set_clock($at = null): ?DateTimeImmutable
{
    static $pinned = null;
    if ($at instanceof DateTimeImmutable) {
        $pinned = $at->setTimezone(new DateTimeZone(EXAMS_TZ));
    } elseif ($at === false) {
        $pinned = null;
    }
    return $pinned;
}

function exams_dt(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone(EXAMS_TZ));
}

function exams_sql_dt(DateTimeImmutable $at): string
{
    return $at->setTimezone(new DateTimeZone(EXAMS_TZ))->format('Y-m-d H:i:s');
}

/** Does $table exist? Cached per connection. */
function exams_table_exists(PDO $pdo, string $table): bool
{
    static $memo = null;
    $memo ??= new WeakMap();
    if (!preg_match('/^[a-z_][a-z0-9_]*$/', $table)) {
        return false;
    }
    $known = $memo[$pdo] ?? [];
    if (!array_key_exists($table, $known)) {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 0");
            $known[$table] = true;
        } catch (Throwable $e) {
            $known[$table] = false;
        }
        $memo[$pdo] = $known;
    }
    return $known[$table];
}

/** The migration has run (every table of the module exists). */
function exams_ready(PDO $pdo): bool
{
    foreach (EXAMS_TABLES as $t) {
        if (!exams_table_exists($pdo, $t)) {
            return false;
        }
    }
    return true;
}

function exams_is_duplicate_key(Throwable $e): bool
{
    $code = $e instanceof PDOException ? (string) ($e->errorInfo[1] ?? $e->getCode()) : '';
    return $code === '1062' || $code === '19' || $code === '2067' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
}

function exams_json($v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** One line of text, trimmed, at most $len characters. */
function exams_line($v, int $len): string
{
    return mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $v)), 0, $len);
}

/** Text that may keep its line breaks, trimmed, at most $len characters. */
function exams_text($v, int $len): string
{
    $s = str_replace(["\r\n", "\r"], "\n", (string) $v);
    $s = (string) preg_replace('/[ \t]+/u', ' ', $s);
    $s = (string) preg_replace("/\n{3,}/", "\n\n", $s);
    return mb_substr(trim($s), 0, $len);
}

require_once __DIR__ . '/bank.php';
require_once __DIR__ . '/engine.php';
