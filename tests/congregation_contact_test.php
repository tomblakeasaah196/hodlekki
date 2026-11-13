<?php
// /tests/congregation_contact_test.php
//
// BEHAVIOURAL tests for reaching a person from Congregation
// (includes/congregation_contact.php). They EXECUTE the real functions against
// in-memory SQLite and pin what the details dialog promises:
//
//   - Email goes to the confirmed personal address, never a @hodlc.com
//     sign-in name (those are not mailboxes).
//   - Numbers as typed in the register (0803…, 803…, +234 (0)803…) become one
//     international form for tel: and wa.me.
//   - One email or text is logged once; a second Call or WhatsApp tap within
//     two minutes is not logged again; another person's tap is.
//   - The history shows the email's or text's own state, and hides an email
//     that was undone (a draft again, or deleted).
//   - Before the migration nothing is logged and nothing breaks.
//
// Run from the repo root:
//     php tests/congregation_contact_test.php
//
// Exit code 0 = all good, 1 = at least one failure. CLI only.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

if (!extension_loaded('pdo_sqlite')) {
    echo "SKIP: pdo_sqlite is not available in this PHP build.\n";
    exit(0);
}

date_default_timezone_set('Africa/Lagos');

require_once __DIR__ . '/../includes/congregation_contact.php';

$passed = 0;
$failed = 0;

function ok(string $label, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   {$label}\n";
    } else {
        $failed++;
        echo "  FAIL {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function same(string $label, $expected, $actual): void
{
    ok($label, $expected === $actual, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
}

function cc_db(bool $migrated = true, bool $withMail = true, bool $withSms = true): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT)");
    $pdo->exec("INSERT INTO users (id, first_name, last_name) VALUES (1, 'Abi', 'Samuel'), (2, 'Ada', 'Pastor'), (3, 'Tunde', 'Worker')");
    if ($migrated) {
        // The migration's table, in SQLite's words
        $pdo->exec("CREATE TABLE congregation_contacts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, person_id INTEGER NOT NULL, channel TEXT NOT NULL,
            by_user_id INTEGER NOT NULL, ref TEXT NULL, created_at TEXT NOT NULL,
            UNIQUE (channel, ref))");
    }
    if ($withMail) {
        $pdo->exec("CREATE TABLE mail_outbox (id TEXT PRIMARY KEY, user_id INTEGER, status TEXT)");
    }
    if ($withSms) {
        $pdo->exec("CREATE TABLE sms_log (id INTEGER PRIMARY KEY, status TEXT)");
    }
    return $pdo;
}

echo "The address to write to\n";
same('confirmed personal email first', 'abi@gmail.com', cong_contact_email(['real_email' => ' Abi@Gmail.com ', 'email' => 'abi.samuel@hodlc.com']));
same('a @hodlc.com sign-in name is never used', null, cong_contact_email(['real_email' => null, 'email' => 'abi.samuel@hodlc.com']));
same('users.email when it is a real address', 'abi@yahoo.com', cong_contact_email(['real_email' => '', 'email' => 'abi@yahoo.com']));
same('a church mailbox is a real mailbox', 'tom.blake@hodlekki.org', cong_contact_email(['email' => 'tom.blake@hodlekki.org']));
same('nothing usable: no address', null, cong_contact_email(['real_email' => 'not an email', 'email' => '']));

echo "\nThe number for tel: and wa.me\n";
same('0803… gains 234', '2348031234567', cong_contact_intl('0803 123 4567'));
same('803… (as in the register) gains 234', '2347025929881', cong_contact_intl('7025929881'));
same('+234 (0)803… loses the 0', '2348031234567', cong_contact_intl('+234 (0)803-123-4567'));
same('00 44… stays foreign', '447700900123', cong_contact_intl('00 44 7700 900123'));
same('too short: none', null, cong_contact_intl('12345'));
same('empty: none', null, cong_contact_intl(null));

echo "\nThe log\n";
$pdo = cc_db();
$t = strtotime('2026-11-13 10:00:00');
ok('a call is logged', cong_contact_log($pdo, 1, 'call', 2, null, $t));
ok('the same call tapped again a minute later is accepted', cong_contact_log($pdo, 1, 'call', 2, null, $t + 60));
same('but logged once', 1, (int) $pdo->query("SELECT COUNT(*) FROM congregation_contacts WHERE channel = 'call'")->fetchColumn());
cong_contact_log($pdo, 1, 'call', 3, null, $t + 60);
same("someone else's call is its own row", 2, (int) $pdo->query("SELECT COUNT(*) FROM congregation_contacts WHERE channel = 'call'")->fetchColumn());
cong_contact_log($pdo, 1, 'call', 2, null, $t + 300);
same('a call five minutes later is a new row', 3, (int) $pdo->query("SELECT COUNT(*) FROM congregation_contacts WHERE channel = 'call'")->fetchColumn());

$pdo->exec("INSERT INTO sms_log (id, status) VALUES (41, 'delivered')");
ok('a text is logged', cong_contact_log($pdo, 1, 'sms', 2, '41', $t + 400));
ok('the same text again is accepted', cong_contact_log($pdo, 1, 'sms', 2, '41', $t + 401));
same('but logged once (UNIQUE channel + ref)', 1, (int) $pdo->query("SELECT COUNT(*) FROM congregation_contacts WHERE channel = 'sms'")->fetchColumn());
ok('an unknown channel is refused', !cong_contact_log($pdo, 1, 'fax', 2, null, $t));
ok('nobody is refused', !cong_contact_log($pdo, 0, 'call', 2, null, $t));

echo "\nThe history\n";
$pdo->exec("INSERT INTO mail_outbox (id, user_id, status) VALUES ('m-sent', 2, 'sent'), ('m-going', 2, 'queued'), ('m-undone', 2, 'draft')");
cong_contact_log($pdo, 1, 'email', 2, 'm-sent', $t + 500);
cong_contact_log($pdo, 1, 'email', 2, 'm-going', $t + 600);
cong_contact_log($pdo, 1, 'email', 2, 'm-undone', $t + 700);
cong_contact_log($pdo, 1, 'email', 2, 'm-deleted', $t + 800);
cong_contact_log($pdo, 1, 'whatsapp', 3, null, $t + 900);
$recent = cong_contact_recent($pdo, 1, 10);
same('newest first, undone emails hidden', ['whatsapp', 'email', 'email', 'sms', 'call', 'call', 'call'], array_column($recent, 'channel'));
same('states from the email and the text', [null, 'sending', 'sent', 'delivered', null], array_slice(array_column($recent, 'state'), 0, 5));
same('who reached them, by name', 'Tunde Worker', $recent[0]['by']);
same('the limit holds', 2, count(cong_contact_recent($pdo, 1, 2)));
same('someone never reached: empty', [], cong_contact_recent($pdo, 3));
$pdo->exec("UPDATE sms_log SET status = 'failed' WHERE id = 41");
same('a failed text says so', 'failed', cong_contact_recent($pdo, 1, 10)[3]['state']);

echo "\nWithout Mail or SMS Studio's tables\n";
$bare = cc_db(true, false, false);
cong_contact_log($bare, 1, 'email', 2, 'm-1', $t);
cong_contact_log($bare, 1, 'sms', 2, '7', $t + 10);
same('rows still show, with no state', [['sms', null], ['email', null]], array_map(fn ($r) => [$r['channel'], $r['state']], cong_contact_recent($bare, 1)));

echo "\nBefore the migration\n";
$old = cc_db(false);
ok('not ready', !cong_contact_ready($old));
ok('nothing is logged, no error', !cong_contact_log($old, 1, 'call', 2, null, $t));
same('the history is empty', [], cong_contact_recent($old, 1));

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
