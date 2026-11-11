<?php
// /tests/campaigns/integration.php
//
// Email and automation on a REAL database (MySQL 8 / MariaDB 10.11, CI's
// integration job), for the things SQLite cannot vouch for:
//   - several NULLs in a UNIQUE key (one confirmed address per person and per
//     address relies on it);
//   - the budget's conditional UPDATE and its affected-row count, with native
//     prepares (production sets ATTR_EMULATE_PREPARES = false), including two
//     connections taking the last slots of an hour;
//   - GET_LOCK keeping a second worker out;
//   - the unique claims (occasion, ledger, one-a-day) as real duplicate-key
//     errors (1062);
//   - the outbox round trip and the mailbox's Message-ID lookup.
//
// Expects the database tests/special_events/db_setup.php built (it applies
// every migration, this module's included). Same environment variables:
//   SE_TEST_DB_HOST / _PORT / _USER / _PASS / _NAME (or _SOCKET)
//     php tests/campaigns/integration.php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}
date_default_timezone_set('Africa/Lagos');
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['MAIL_FROM_ADDRESS'] = 'system@hodlekki.org';
$_ENV['SMS_VAULT_KEY'] = $_ENV['SMS_VAULT_KEY'] ?? str_repeat('ab', 32);

require_once __DIR__ . '/../../includes/security_helpers.php';
require_once __DIR__ . '/../../includes/sms_functions.php';
require_once __DIR__ . '/../../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/lib.php';

function ci_pdo(): PDO
{
    $socket = getenv('SE_TEST_DB_SOCKET') ?: '';
    $db = getenv('SE_TEST_DB_NAME') ?: 'se_test';
    $dsn = $socket !== ''
        ? "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4"
        : 'mysql:host=' . (getenv('SE_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (int) (getenv('SE_TEST_DB_PORT') ?: 3306)
          . ";dbname={$db};charset=utf8mb4";
    $pdo = new PDO($dsn, getenv('SE_TEST_DB_USER') ?: 'root', getenv('SE_TEST_DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+01:00'");
    return $pdo;
}

try {
    $pdo = ci_pdo();
    $other = ci_pdo();
} catch (PDOException $e) {
    fwrite(STDERR, '[campaigns-it] cannot connect: ' . $e->getMessage() . "\n");
    exit(1);
}
echo '[campaigns-it] ' . $pdo->query('SELECT VERSION()')->fetchColumn() . "\n";

ct_section('Schema');
ct_eq('every table of the module exists', true, campaigns_ready($pdo));
foreach (array_reverse(CAMPAIGNS_TABLES) as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM users WHERE id BETWEEN 9001 AND 9010');
foreach ([[9001, 'Ada'], [9002, 'Bola'], [9003, 'Chi']] as [$id, $name]) {
    $pdo->prepare("INSERT INTO users (id, first_name, last_name, email, spiritual_status) VALUES (?, ?, 'Test', NULL, 'Member')")
        ->execute([$id, $name]);
}

ct_section('One confirmed address per person and per address (UNIQUE with NULLs)');
ct_at('2026-11-16 09:00');
$a = email_capture_start($pdo, 9001, 'ada.it@gmail.com', 'profile', null, ['send' => false]);
$b = email_capture_start($pdo, 9002, 'bola.it@gmail.com', 'profile', null, ['send' => false]);
ct_ok('two pending rows coexist (both confirmed_* columns NULL)', $a['ok'] && $b['ok']);
ct_eq('confirm Ada', true, email_capture_confirm($pdo, $a['token'])['ok']);
ct_eq('confirm Bola', true, email_capture_confirm($pdo, $b['token'])['ok']);
$pdo->prepare("INSERT INTO email_addresses (user_id, email, status, source, token_hash, token_expires_at, created_at)
               VALUES (9003, 'ada.it@gmail.com', 'pending', 'profile', ?, '2026-12-31 00:00:00', '2026-11-16 09:00:00')")
    ->execute([hash('sha256', 'it-chi')]);
ct_eq("Chi cannot confirm Ada's address (1062 → TAKEN)", 'TAKEN', email_capture_confirm($pdo, 'it-chi')['code'] ?? null);
ct_eq("Ada still holds it", 9001, member_by_confirmed_email($pdo, 'ada.it@gmail.com'));
ct_eq('users.email got the convenience copy', 'ada.it@gmail.com', $pdo->query('SELECT email FROM users WHERE id = 9001')->fetchColumn());

ct_section('The hourly budget under native prepares, two connections');
email_setting_set($pdo, 'hourly_cap', '10');
email_settings_all($other, true);
$h = campaigns_dt('2026-11-16 14:05');
$taken = 0;
for ($i = 0; $i < 4; $i++) {
    $taken += email_budget_take($pdo, 'campaign', 1, $h) ? 1 : 0;
    $taken += email_budget_take($other, 'campaign', 1, $h) ? 1 : 0;
}
ct_eq('two workers together stop exactly at the bulk share (5 of cap 10)', 5, $taken);
ct_eq('a reset still has room', true, email_budget_take($other, 'transactional', 4, $h));
ct_eq('the usable total (9) is never passed', false, email_budget_take($pdo, 'transactional', 1, $h));
email_budget_refused($pdo, 9, $h->modify('+1 hour'));
ct_eq('a refused hour refuses everyone', false, email_budget_take($other, 'transactional', 1, $h->modify('+1 hour')));

ct_section('Named locks');
ct_eq('the first worker takes the lock', true, campaigns_lock($pdo, EMAIL_WORKER_LOCK, 0));
ct_eq('a second worker is kept out', true, email_worker_run($other, ['endpoints_ready' => true, 'transport' => ct_transport(), 'lock_wait' => 0])['locked']);
campaigns_unlock($pdo, EMAIL_WORKER_LOCK);

ct_section('Outbox round trip');
email_setting_set($pdo, 'hourly_cap', '100');
email_consent_record($pdo, 9001, 'granted', 'profile', ['text' => EMAIL_CONSENT_NEWS_TEXT]);
ct_at('2026-11-16 16:00');
$withButton = ct_doc([
    email_doc_paragraphs('Hello {{first_name}}.'),
    ['type' => 'button', 'label' => 'Visit', 'href' => 'https://hodlekki.org/'],
    ['type' => 'footer'],
]);
$q = email_send_doc($pdo, $withButton, ['id' => 9001, 'first_name' => 'Ada'], [
    'lane' => 'automation', 'purpose' => 'news', 'flow' => 'embrace.welcome', 'to_email' => 'ada.it@gmail.com', 'track' => true,
]);
$tx = ct_transport();
$run = email_worker_run($pdo, ['endpoints_ready' => true, 'transport' => $tx, 'now' => campaigns_now()]);
ct_eq('sent', 1, $run['sent']);
$row = $pdo->query('SELECT * FROM email_outbox WHERE id = ' . (int) $q['id'])->fetch();
ct_eq('status sent', 'sent', $row['status']);
ct_eq('the mailbox finds it by the reply\'s Message-ID', 9001, campaign_mail_lookup($pdo, email_message_id_hash($tx['sent'][0]['message_id']))['user_id'] ?? null);
ct_eq('a click is served from the stored targets', 'https://hodlekki.org/', email_track_click($pdo, $q['ref'], 0));
ct_eq('one-click unsubscribe', true, email_unsubscribe($pdo, $q['ref'])['ok']);

ct_section('Sign-in email and password reset');
ct_ok('identity tables exist', email_identity_ready($pdo));
$pdo->exec('DELETE FROM auth_reset_tokens');
$pdo->exec('DELETE FROM auth_reset_requests');
$pdo->exec('DELETE FROM email_prompt_skips');
ct_eq('a confirmed personal email signs in', 9001, (int) (email_signin_lookup($pdo, 'ADA.IT@gmail.com')['user']['id'] ?? 0));
ct_eq('Chi, with a link pending (and no confirmed email), gets the banner', 'banner', email_prompt_state($pdo, 9003));
ct_at('2026-11-16 17:00');
$req = email_reset_request($pdo, 'ada.it@gmail.com', '10.0.0.1');
ct_eq('a reset link is queued', true, $req['sent']);
$pw = 'Hallelujah-2027!';
ct_eq('the link sets a new password once', true, email_reset_complete($pdo, $req['token'], $pw, $pw)['ok']);
ct_eq('…and only once', 'USED', email_reset_complete($other, $req['token'], $pw, $pw)['code'] ?? null);
ct_ok('the password works', password_verify($pw, (string) $pdo->query('SELECT password_hash FROM users WHERE id = 9001')->fetchColumn()));

ct_section('Unique claims raise real duplicate-key errors');
$pdo->exec("INSERT INTO automation_rules (module, kind, name, is_enabled, config_json, version, created_at)
            VALUES ('embrace', 'journey', 'Test', 1, '{}', 1, '2026-11-16 00:00:00')");
$ruleId = (int) $pdo->lastInsertId();
ct_ok('a ledger row is claimed once', automation_ledger_claim($pdo, $ruleId, 'welcome', 9001, '2026-11-16', null, 'pending') !== null
    && automation_ledger_claim($other, $ruleId, 'welcome', 9001, '2026-11-16', null, 'pending') === null);
ct_ok('a person-day is claimed once across connections', automation_person_day_claim($pdo, 9001, '2026-11-16', $ruleId, 'welcome', false)
    && !automation_person_day_claim($other, 9001, '2026-11-16', $ruleId + 1, 'miss_note', false));
$claim = static function (PDO $c) use ($ruleId): bool {
    try {
        $c->prepare("INSERT INTO automation_runs (rule_id, occasion_key, occasion_at, status, started_at) VALUES (?, '2026-11-16#welcome', '2026-11-16 10:00:00', 'running', '2026-11-16 10:05:00')")
            ->execute([$ruleId]);
        return true;
    } catch (PDOException $e) {
        return !campaigns_is_duplicate_key($e) ? throw $e : false;
    }
};
ct_ok('an occasion is claimed once', $claim($pdo) && !$claim($other));

foreach (array_reverse(CAMPAIGNS_TABLES) as $table) {
    $pdo->exec("DELETE FROM {$table}");
}
$pdo->exec('DELETE FROM users WHERE id BETWEEN 9001 AND 9010');
campaigns_set_clock(false);
echo "\n{$GLOBALS['ct_passed']} passed, {$GLOBALS['ct_failed']} failed\n";
exit($GLOBALS['ct_failed'] === 0 ? 0 : 1);
