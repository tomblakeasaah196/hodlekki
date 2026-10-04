<?php
// /cron/special_events.php
/**
 * ============================================================================
 * SPECIAL EVENTS — module cron (guide §23.4)
 * ----------------------------------------------------------------------------
 * Run every five minutes (CLI only):
 *
 *     * /5 * * * *  /usr/local/bin/ea-php83 /path/to/cron/special_events.php >/dev/null 2>&1
 *     (written without the space, see README)
 *
 * Jobs, in order:
 *   1. message runs        — reminders, enqueued into SMS Studio (§16.4)
 *   2. karaoke holds       — release the picks of people who never arrived (§10.8.2)
 *   3. test mode auto-off  — 15 minutes before doors (§11.13)
 *   4. cleanup             — expired tokens, dead devices, old rate-limit rows
 *   5. AI source purge     — programme/song screenshots older than 30 days (§14.1)
 *
 * Only one run works at a time (`GET_LOCK('se_cron', 0)`); a second run exits
 * immediately, which is what makes "two crons at once" harmless. Every run
 * writes `se_settings.cron_last_run`, which the Studio's health panel and the
 * host console's cron dot read.
 * ============================================================================
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// db.php loads .env from DOCUMENT_ROOT, which is empty under CLI.
$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/special_events/bootstrap.php';

/** One log line, the same shape as the other cron scripts. */
function se_cron_log(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
}

$startedAt = microtime(true);
$summary   = ['messages' => 0, 'karaoke' => 0, 'test_mode' => 0, 'tokens' => 0, 'devices' => 0, 'rate_limits' => 0, 'ai_sources' => 0, 'anonymised' => 0];

try {
    if (!se_table_exists($pdo, 'se_events')) {
        se_cron_log('Special Events is not migrated on this database yet. Nothing to do.');
        exit(0);
    }

    $lock = $pdo->query("SELECT GET_LOCK('se_cron', 0)")->fetchColumn();
    if ((int) $lock !== 1) {
        se_cron_log('Another run holds the lock. Exiting.');
        exit(0);
    }
} catch (Throwable $e) {
    error_log('SE cron/start: ' . $e->getMessage());
    se_cron_log('ERROR: ' . $e->getMessage());
    exit(1);
}

try {
    $now = se_now();

    // Events worth looking at: anything published that has not been archived,
    // plus anything that ended in the last two days (thank-you, cleanup).
    $stmt = $pdo->prepare(
        "SELECT * FROM se_events
          WHERE status IN ('published', 'cancelled')
            AND ends_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
          ORDER BY starts_at ASC"
    );
    $stmt->execute();
    $events = $stmt->fetchAll() ?: [];

    foreach ($events as $event) {
        $eventId = (int) $event['id'];

        // ---- 1. message runs -------------------------------------------
        try {
            foreach (se_messages_run_due($pdo, $event, $now, (int) ($event['created_by'] ?? 0) ?: null) as $key => $result) {
                $summary['messages'] += (int) $result['recipients'];
                se_cron_log(sprintf(
                    'event %d %s: %s (%d recipients)',
                    $eventId, $key, $result['status'], $result['recipients']
                ));
            }
        } catch (Throwable $e) {
            error_log('SE cron/messages ' . $eventId . ': ' . $e->getMessage());
        }

        // ---- 2. karaoke hold releases ----------------------------------
        try {
            $released = se_karaoke_release_holds($pdo, $event, null, $now);
            if ($released > 0) {
                $summary['karaoke'] += $released;
                se_cron_log("event {$eventId}: released {$released} karaoke hold(s)");
            }
        } catch (Throwable $e) {
            error_log('SE cron/karaoke ' . $eventId . ': ' . $e->getMessage());
        }

        // ---- 3. test mode auto-off -------------------------------------
        try {
            if (se_test_mode_due_off($pdo, $event, $now)) {
                se_test_mode_set($pdo, $event, false, null);
                $summary['test_mode']++;
                se_cron_log("event {$eventId}: test mode switched off before doors");
            }
        } catch (Throwable $e) {
            error_log('SE cron/test_mode ' . $eventId . ': ' . $e->getMessage());
        }
    }

    // ---- 4. cleanup ----------------------------------------------------
    try {
        if (se_table_exists($pdo, 'se_access_tokens')) {
            $stmt = $pdo->query("DELETE FROM se_access_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
            $summary['tokens'] = $stmt->rowCount();
        }
        if (se_table_exists($pdo, 'se_devices')) {
            $stmt = $pdo->query("DELETE FROM se_devices WHERE last_seen_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
            $summary['devices'] = $stmt->rowCount();
        }
        if (se_table_exists($pdo, 'se_rate_limits')) {
            $stmt = $pdo->query("DELETE FROM se_rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)");
            $summary['rate_limits'] = $stmt->rowCount();
        }
    } catch (Throwable $e) {
        error_log('SE cron/cleanup: ' . $e->getMessage());
    }

    // ---- 5. AI source purge (§14.1: kept 30 days, then gone) -----------
    try {
        if (se_table_exists($pdo, 'se_assets')) {
            $roles = implode(',', array_fill(0, count(SE_ASSET_ROLES_TEMPORARY), '?'));
            $stmt  = $pdo->prepare(
                "SELECT id, path FROM se_assets
                  WHERE role IN ({$roles}) AND deleted_at IS NULL
                    AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
                  LIMIT 200"
            );
            $stmt->execute(SE_ASSET_ROLES_TEMPORARY);

            foreach ($stmt->fetchAll() as $asset) {
                $file = se_docroot() . (string) $asset['path'];
                if (is_file($file)) {
                    @unlink($file);
                }
                $pdo->prepare("UPDATE se_assets SET deleted_at = NOW() WHERE id = ?")->execute([(int) $asset['id']]);
                $summary['ai_sources']++;
            }
        }
    } catch (Throwable $e) {
        error_log('SE cron/ai_purge: ' . $e->getMessage());
    }

    // ---- 6. post-event retention (§19.8) -----------------------------
    try {
        if (se_table_exists($pdo, 'se_feedback')) {
            $retention = se_retention_run($pdo);
            $summary['anonymised'] = (int) $retention['contacts'];
            $summary['devices'] += (int) $retention['devices'];
            $summary['tokens'] += (int) $retention['tokens'];
        }
    } catch (Throwable $e) {
        error_log('SE cron/retention: ' . $e->getMessage());
    }

    // The heartbeat the health panel reads (§23.6).
    se_setting_save($pdo, 'cron_last_run', se_sql_datetime(se_now()), null);

    se_cron_log(sprintf(
        'Done in %dms. events=%d messages=%d karaoke=%d test_mode=%d tokens=%d devices=%d rate_limits=%d ai_sources=%d',
        (int) round((microtime(true) - $startedAt) * 1000),
        count($events),
        $summary['messages'], $summary['karaoke'], $summary['test_mode'],
        $summary['tokens'], $summary['devices'], $summary['rate_limits'], $summary['ai_sources']
    ));
} catch (Throwable $e) {
    error_log('SE cron: ' . $e->getMessage());
    se_cron_log('ERROR: ' . $e->getMessage());
    try {
        $pdo->query("SELECT RELEASE_LOCK('se_cron')");
    } catch (Throwable $ignored) {
        // The connection is going away anyway, which releases the lock.
    }
    exit(1);
}

try {
    $pdo->query("SELECT RELEASE_LOCK('se_cron')");
} catch (Throwable $e) {
    error_log('SE cron/unlock: ' . $e->getMessage());
}

exit(0);
