<?php
// /includes/special_events/db.php
//
// Database infrastructure shared by every module library: the missing-table
// probe that keeps code shipped before a migration safe (§9.4), the event row
// lock that makes every allocation race-free (§8.4), the schema self-check
// behind Studio -> Settings -> Health (Appendix A rule 3) and the audit log
// writer (§19.10).
//
// This file is a PR1 addition to the §8.7 layout: the four helpers are listed
// in the PR1 build plan without a home, and they do not belong in util.php
// (which must stay database-free so it is unit-testable from the CLI).

// --------------------------------------------------------------------------
// Missing tables (§9.4)
// --------------------------------------------------------------------------

/**
 * True when a table exists in the current schema. Cached per request, like
 * sms_table_exists().
 *
 * The deploy copies the tree BEFORE running migrations, so for a few seconds
 * every release runs new code against the old schema. Any function touching a
 * later phase's table MUST ask here first and degrade (empty result, or the
 * FEATURE_NOT_READY error code) rather than raising.
 */
function se_table_exists(PDO $pdo, string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }
    if (!preg_match('/^[a-z0-9_]+$/', $table)) {
        return $cache[$table] = false;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1"
        );
        $stmt->execute([$table]);

        return $cache[$table] = ($stmt->fetchColumn() !== false);
    } catch (Throwable $e) {
        error_log('SE db/table_exists: ' . $e->getMessage());
        return $cache[$table] = false;
    }
}

/** Every table in $tables exists. */
function se_tables_exist(PDO $pdo, array $tables): bool
{
    foreach ($tables as $table) {
        if (!se_table_exists($pdo, $table)) {
            return false;
        }
    }

    return true;
}

/** Clear the probe cache. Only the test harness needs this, after migrating. */
function se_table_exists_reset(): void
{
    // A static inside the function above cannot be reset from outside, so the
    // cache lives for the request. The harness runs each case in a fresh
    // process; this hook exists so a future in-process migration test can
    // invalidate it by re-including the file.
}

// --------------------------------------------------------------------------
// The event row lock (§8.4, AGENTS.md module rules)
// --------------------------------------------------------------------------

/**
 * Run $fn inside a transaction that holds a write lock on the event row.
 *
 * Seats, teams, player numbers and queue numbers are allocated ONLY in here
 * (AGENTS.md): the lock serialises concurrent registrations and check-ins, so
 * two phones can never be handed the same seat or the same player number.
 *
 * The callback receives the locked event row. Returning normally commits;
 * throwing rolls back and re-throws. Nesting is safe — an inner call joins the
 * outer transaction instead of starting its own.
 *
 * @template T
 * @param callable(array, PDO): T $fn
 * @return T
 */
function se_lock_event(PDO $pdo, int $eventId, callable $fn): mixed
{
    $owns = !$pdo->inTransaction();
    if ($owns) {
        $pdo->beginTransaction();
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM se_events WHERE id = ? FOR UPDATE");
        $stmt->execute([$eventId]);
        $event = $stmt->fetch();

        if (!$event) {
            throw new SeNotFoundException('Event not found.');
        }

        $result = $fn($event, $pdo);

        if ($owns) {
            $pdo->commit();
        }

        return $result;
    } catch (Throwable $e) {
        if ($owns && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** Thrown when a locked row is gone. Mapped to EVENT_NOT_FOUND by the APIs. */
class SeNotFoundException extends RuntimeException {}

/** Thrown when an optimistic-concurrency check fails. Mapped to STALE_VERSION. */
class SeStaleVersionException extends RuntimeException {}

/** Thrown for a field-level validation failure. Mapped to VALIDATION. */
class SeValidationException extends RuntimeException
{
    /** @param array<string,string> $fields field name => human reason */
    public function __construct(public readonly array $fields, string $message = 'Please check the highlighted fields.')
    {
        parent::__construct($message);
    }
}

/** Thrown for a rule the caller may show verbatim, with a machine code. */
class SeRuleException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

// --------------------------------------------------------------------------
// Audit log (§19.10)
// --------------------------------------------------------------------------

/**
 * Append one row to the audit log. Never throws: an audit failure must not
 * take down the action it was recording (the action itself is the record of
 * record), but it is logged so the gap is visible.
 *
 * @param string $action One of SE_AUDIT_ACTIONS, optionally with a ':suffix'
 *                       (e.g. 'event_update:brand', 'notice_sent:reminder_1').
 */
function se_audit(
    PDO $pdo,
    ?int $eventId,
    string $action,
    array $detail = [],
    ?string $entity = null,
    ?int $entityId = null,
    ?int $actorUserId = null,
    ?int $actorRegistrationId = null
): void {
    try {
        if (!se_table_exists($pdo, 'se_audit_log')) {
            return;
        }

        $base = explode(':', $action, 2)[0];
        if (!in_array($base, SE_AUDIT_ACTIONS, true)) {
            error_log('SE audit: unknown action "' . $action . '"');
        }

        $actorUserId ??= ((int) ($_SESSION['user_id'] ?? 0)) ?: null;

        $stmt = $pdo->prepare(
            "INSERT INTO se_audit_log
                (event_id, actor_user_id, actor_registration_id, action, entity, entity_id, detail_json, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $eventId,
            $actorUserId,
            $actorRegistrationId,
            mb_substr($action, 0, 60, 'UTF-8'),
            $entity !== null ? mb_substr($entity, 0, 40, 'UTF-8') : null,
            $entityId,
            $detail ? se_json_encode(se_audit_scrub($detail)) : null,
            se_ip_hash(),
        ]);
    } catch (Throwable $e) {
        error_log('SE audit: ' . $e->getMessage());
    }
}

/**
 * Strip secrets out of an audit detail payload before it is stored.
 *
 * Tokens, codes and keys must never be written to the audit log or the error
 * log (§19.9). Only the key NAME is kept, so the shape of the change is still
 * reviewable.
 */
function se_audit_scrub(array $detail): array
{
    $secret = '/(token|secret|pepper|password|csrf|_key|key_|cookie)/i';
    $out    = [];

    foreach ($detail as $key => $value) {
        if (is_string($key) && preg_match($secret, $key)) {
            $out[$key] = '[redacted]';
        } elseif (is_array($value)) {
            $out[$key] = se_audit_scrub($value);
        } elseif (is_string($value)) {
            $out[$key] = mb_substr($value, 0, 500, 'UTF-8');
        } else {
            $out[$key] = $value;
        }
    }

    return $out;
}

// --------------------------------------------------------------------------
// Schema self-check (Appendix A rule 3)
// --------------------------------------------------------------------------

/**
 * Compare information_schema with the expected table/column list in
 * constants.php and report anything missing.
 *
 * This catches two things a plain "did the migration run?" check cannot:
 * a file that failed half way (DDL auto-commits, so some tables exist and
 * some do not), and a table an earlier broken run created with an outdated
 * definition — CREATE TABLE IF NOT EXISTS never alters an existing table.
 *
 * @return array{ok: bool, phase_a: array, later: array, checked_at: string}
 */
function se_schema_check(PDO $pdo): array
{
    $result = [
        'ok'         => true,
        'phase_a'    => [],
        'later'      => [],
        'checked_at' => se_sql_datetime(se_now()),
    ];

    try {
        $present = [];
        $stmt = $pdo->query(
            "SELECT table_name, column_name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name LIKE 'se\\_%'"
        );
        foreach ($stmt->fetchAll() as $row) {
            // MySQL 8 and MariaDB disagree on the case of these column names.
            $table  = (string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
            $column = (string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? '');
            if ($table !== '') {
                $present[$table][$column] = true;
            }
        }

        foreach (SE_SCHEMA_EXPECTED as $file => $tables) {
            foreach ($tables as $table => $columns) {
                if (!isset($present[$table])) {
                    $result['phase_a'][] = [
                        'migration' => $file,
                        'table'     => $table,
                        'status'    => 'missing_table',
                        'detail'    => 'The table does not exist. Run php db/migrate.php.',
                    ];
                    $result['ok'] = false;
                    continue;
                }
                $missing = [];
                foreach ($columns as $column) {
                    if (!isset($present[$table][$column])) {
                        $missing[] = $column;
                    }
                }
                if ($missing) {
                    $result['phase_a'][] = [
                        'migration' => $file,
                        'table'     => $table,
                        'status'    => 'missing_columns',
                        'columns'   => $missing,
                        'detail'    => 'The table exists but is out of date — an earlier migration run probably failed half way. CREATE TABLE IF NOT EXISTS will not fix it; add a forward migration.',
                    ];
                    $result['ok'] = false;
                }
            }
        }

        foreach (SE_SCHEMA_LATER_PHASES as $phase => $tables) {
            $missing = [];
            foreach ($tables as $table) {
                if (!isset($present[$table])) {
                    $missing[] = $table;
                }
            }
            $result['later'][] = [
                'phase'   => $phase,
                'total'   => count($tables),
                'missing' => $missing,
                // Not an error: these ship with their own pull request.
                'status'  => $missing === [] ? 'ready' : (count($missing) === count($tables) ? 'not_migrated' : 'partial'),
            ];
            if ($missing !== [] && count($missing) !== count($tables)) {
                $result['ok'] = false;
            }
        }
    } catch (Throwable $e) {
        error_log('SE db/schema_check: ' . $e->getMessage());
        $result['ok'] = false;
        $result['phase_a'][] = [
            'migration' => '-',
            'table'     => '-',
            'status'    => 'check_failed',
            'detail'    => 'The schema check could not run. See the error log.',
        ];
    }

    return $result;
}
