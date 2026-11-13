<?php
// /db/backfill_signin_names.php
/**
 * The @hodlc.com sign-in names die out (owner's decision, 13 Nov 2026).
 *
 * From this deploy on, confirming a personal email puts it in users.email in
 * place of the @hodlc.com name (email_sync_users_email()). This pass does the
 * same, through the same function, for everyone who confirmed an email
 * BEFORE that: users.email (and users.real_email) take the confirmed address,
 * and the old name is kept in auth_retired_signin_names so typing it on the
 * sign-in page says where they sign in now. A name whose confirmed address is
 * already another account's users.email stays as it is (users.email is
 * unique) and is counted as "kept".
 *
 * Idempotent and safe to run every time: once done it finds nothing. Like
 * db/backfill_status_ladder.php it is wired into bin/deploy.sh after the
 * migrations, nothing that goes wrong in it fails the deploy, and it is NOT
 * tracked in schema_migrations.
 *
 * Usage
 *   php db/backfill_signin_names.php             # apply
 *   php db/backfill_signin_names.php --dry-run   # count who would move, change nothing
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$dryRun = in_array('--dry-run', array_slice($argv, 1), true);

$_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? '' ?: dirname(__DIR__);
if (!ini_get('error_log')) {
    ini_set('error_log', dirname(__DIR__) . '/includes/error_log');
}

try {
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/campaigns/bootstrap.php';

    echo '[signin-names] ' . date('Y-m-d H:i:s') . ' starting' . ($dryRun ? ' (dry run)' : '') . "\n";
    $r = signin_names_backfill($pdo, !$dryRun);
} catch (Throwable $e) {
    // A data hiccup here must not block shipping code; the next deploy tries again.
    error_log('[signin-names] failed: ' . $e->getMessage());
    echo '[signin-names] failed: ' . $e->getMessage() . "\n";
    exit(0);
}

if (!$r['ready']) {
    echo "[signin-names] auth_retired_signin_names does not exist yet (db/migrate.php has not run) — nothing to do\n";
    exit(0);
}
echo '[signin-names] done — ' . ($dryRun ? "would_check={$r['checked']}" : "checked={$r['checked']} moved={$r['moved']} kept={$r['kept']}") . "\n";
exit(0);
