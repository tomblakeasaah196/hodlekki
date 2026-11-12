<?php
// /tests/mail/integration.php
//
// Mail on a REAL database (MySQL 8 / MariaDB 10.11, CI's integration job), through the real permissions engine,
// with the mail server and cPanel's `uapi` faked in memory (tests/mail/support). Expects the database that
// tests/special_events/db_setup.php built (every migration, this module's included). Same environment variables:
//   SE_TEST_DB_HOST / _PORT / _USER / _PASS / _NAME (or _SOCKET)
//     php tests/mail/integration.php
//
// Covers: access (matrix abilities, break-glass, grants by seat / role / person, personal privacy), the quota (the
// 16th mailbox, the kept personal slots, two admins at the last slot), the job queue and every lifecycle job, the
// vault (no password anywhere but encrypted; a half-done rotation), sync and automatic replies, reading (lists,
// filters, search, opening marks read, folder actions and Undo, picture trust), writing (answers, Undo, sending once
// within the hourly budget, retries, schedules, drafts, the signature), the holder who
// leaves, the two-approval export and its mbox, removal with a forwarder, and the nightly access diff.

require __DIR__ . '/support/lib.php';

use HOD\Mail\Admin\Exports;
use HOD\Mail\Admin\Grants;
use HOD\Mail\Admin\Jobs;
use HOD\Mail\Admin\Lifecycle;
use HOD\Mail\Admin\Nightly;
use HOD\Mail\Admin\Quota;
use HOD\Mail\Clock;
use HOD\Mail\Config;
use HOD\Mail\Db;
use HOD\Mail\MailAccess;
use HOD\Mail\MailError;
use HOD\Mail\MailNav;
use HOD\Mail\MailServer;
use HOD\Mail\MailSettings;
use HOD\Mail\MailTransport;
use HOD\Mail\Mime\Message;
use HOD\Mail\Store;
use HOD\Mail\Sync\MailSync;
use HOD\Mail\Tests\FakeMailServer;
use HOD\Mail\Tests\FakeUapi;
use HOD\Mail\Vault;

$socket = getenv('SE_TEST_DB_SOCKET') ?: '';
$db = getenv('SE_TEST_DB_NAME') ?: 'se_test';
$dsn = $socket !== '' ? "mysql:unix_socket={$socket};dbname={$db};charset=utf8mb4"
    : 'mysql:host=' . (getenv('SE_TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (int) (getenv('SE_TEST_DB_PORT') ?: 3306) . ";dbname={$db};charset=utf8mb4";
$pdo = new PDO($dsn, getenv('SE_TEST_DB_USER') ?: 'root', getenv('SE_TEST_DB_PASS') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false,
]);
$pdo->exec("SET time_zone = '+01:00'");
$GLOBALS['pdo'] = $pdo;
Db::use($pdo);
perm_use_pdo($pdo);
perm_set_strict(true);

// ------------------------------------------------------------------ fixtures
section('Setup');
// The harness's user_departments stand-in lacks the roster columns the permissions engine reads.
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'user_departments'")->fetchAll(PDO::FETCH_COLUMN);
$cols = array_map('strtolower', $cols);
foreach (['roster_status' => "VARCHAR(20) NOT NULL DEFAULT 'Active'", 'end_date' => 'DATE NULL', 'ministry_year_id' => 'INT NULL'] as $c => $def) {
    if (!in_array($c, $cols, true)) {
        $pdo->exec("ALTER TABLE user_departments ADD COLUMN {$c} {$def}");
    }
}
foreach (['mail_export_events', 'mail_export_requests', 'mail_admin_jobs', 'mail_settings', 'mail_grants', 'mail_access_snapshot'] as $t) {
    $pdo->exec("DELETE FROM {$t}");
}
$pdo->exec('DELETE FROM mail_accounts');
$pdo->exec("DELETE FROM users WHERE email LIKE 'mailtest-%'");
$pdo->exec("DELETE FROM perm_grants WHERE module_key = 'mail.personal'");

$role = static function (string $name) use ($pdo): int {
    $pdo->prepare('INSERT INTO roles (role_name) SELECT ? FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE role_name = ?)')->execute([$name, $name]);

    return (int) $pdo->query('SELECT id FROM roles WHERE role_name = ' . $pdo->quote($name))->fetchColumn();
};
$superId = $role('Super_Admin');
$pdo->exec("UPDATE roles SET is_break_glass = 1 WHERE id = {$superId}");
$rpId = $role('Resident_Pastor');
$pdo->prepare('INSERT INTO departments (name) VALUES (?)')->execute(['Embrace (mail test)']);
$deptId = (int) $pdo->lastInsertId();
$person = static function (string $first, string $last, ?int $roleId = null, ?string $seat = null) use ($pdo, $deptId): int {
    $pdo->prepare("INSERT INTO users (first_name, last_name, email, account_status) VALUES (?, ?, ?, 'active')")
        ->execute([$first, $last, 'mailtest-' . strtolower($first) . '-' . bin2hex(random_bytes(3)) . '@example.test']);
    $id = (int) $pdo->lastInsertId();
    if ($roleId) {
        $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $roleId]);
    }
    if ($seat) {
        $pdo->prepare("INSERT INTO user_departments (user_id, department_id, role_in_dept, is_active, roster_status) VALUES (?, ?, ?, 1, 'Active')")
            ->execute([$id, $deptId, $seat]);
    }

    return $id;
};
$admin = $person('Ada', 'Admin', $superId);
$admin2 = $person('Bisi', 'Admin', $superId);
$pastor = $person('Tunde', 'Pastor', $rpId);
$hod = $person('Ngozi', 'Hod', null, 'HOD');
$worker = $person('Emeka', 'Worker', null, 'Worker');
$member = $person('Kemi', 'Member');
MailAccess::flush();

$mail = FakeMailServer::install();
$uapi = FakeUapi::install(['system@hodlekki.org', 'embrace@hodlekki.org', 'info@hodlekki.org', 'dmarc@hodlekki.org', 'tom.blake@hodlekki.org'], $mail);
foreach (array_keys($uapi->pops) as $e) {
    $mail->addMailbox($e, $uapi->pops[$e]['password']);
}
$uapi->accountUsed = 20;
Config::set('domain_quota', 15);
check('fixtures ready', $admin > 0 && $deptId > 0);

// ------------------------------------------------------------------ access from the matrix
section('Abilities through can()');
check('a Super Admin manages mailboxes (break-glass)', MailAccess::allows($admin, 'admin'));
check('a member does not', !MailAccess::allows($member, 'admin'));
check('the Resident Pastor holds the pastoral export approval (day-one cell)', MailAccess::allows($pastor, 'export_pastoral'));
check('break-glass does NOT stand in for the pastoral side', !MailAccess::allows($admin, 'export_pastoral'));
check('a Super Admin may give the admin side', MailAccess::allows($admin, 'export_admin') && !MailAccess::allows($pastor, 'export_admin'));
check('mail_can() is the same decision', mail_can('admin', null, $admin) && !mail_can('admin', null, $member));

// ------------------------------------------------------------------ quota
section('Quota');
Quota::refresh();
$snap = Quota::snapshot();
check('counted from cPanel: 5 on hodlekki.org', $snap['domain_used'] === 5 && $snap['account_max'] === 50);
MailSettings::set('quota.reserved_personal', 10);
$e = throws(fn () => Lifecycle::requestShared($admin, ['kind' => 'church', 'local_part' => 'media', 'display_name' => 'HOD Lekki Media']));
check('a church mailbox may not use the 10 kept personal slots', $e instanceof MailError && str_contains($e->getMessage(), 'kept for personal'));
$e = throws(fn () => Lifecycle::requestShared($member, ['kind' => 'church', 'local_part' => 'media', 'display_name' => 'X']));
check('a member cannot create one at all', $e instanceof MailError && $e->status === 403);

// ------------------------------------------------------------------ bring in
section('Bringing the existing mailboxes in');
$e = throws(fn () => Lifecycle::requestBringIn($admin, ['email' => 'system@hodlekki.org', 'kind' => 'church', 'display_name' => 'System']));
check('system@ is refused (the mailer owns its password)', $e instanceof MailError && $e->field === 'email');
$e = throws(fn () => Lifecycle::requestBringIn($admin, ['email' => 'dmarc@hodlekki.org', 'kind' => 'church', 'display_name' => 'DMARC']));
check('dmarc@ stays outside', $e instanceof MailError);
$oldInfo = $uapi->pops['info@hodlekki.org']['password'];
$r = Lifecycle::requestBringIn($admin, ['email' => 'info@hodlekki.org', 'kind' => 'church', 'display_name' => 'Household of David Lekki']);
$r2 = Lifecycle::requestBringIn($admin, ['email' => 'embrace@hodlekki.org', 'kind' => 'church', 'display_name' => 'HOD Lekki Embrace Team']);
check('queued, not done in the request', Store::account($r['account']['id'])['status'] === 'pending' && $uapi->pops['info@hodlekki.org']['password'] === $oldInfo);
$ran = Jobs::run();
$info = Store::account($r['account']['id']);
$embrace = Store::account($r2['account']['id']);
check('the cron set new passwords and opened both', $ran['done'] === 2 && $info['status'] === 'active' && $embrace['status'] === 'active'
    && $uapi->pops['info@hodlekki.org']['password'] !== $oldInfo);
$row = $pdo->query('SELECT secret, secret_pending FROM mail_accounts WHERE id = ' . (int) $info['id'])->fetch();
check('stored encrypted, nothing pending', str_starts_with((string) $row['secret'], 'v1:') && $row['secret_pending'] === null
    && Vault::decrypt($row['secret'], $info['id'], $info['email']) === $uapi->pops['info@hodlekki.org']['password']);
$haystack = json_encode([$pdo->query('SELECT * FROM mail_admin_jobs')->fetchAll(), $uapi->calls,
    $pdo->query("SELECT details FROM security_audit_log WHERE action LIKE 'mail_%'")->fetchAll(PDO::FETCH_COLUMN)]);
check('the password appears in no job, no uapi log line, no audit entry', !str_contains($haystack, $uapi->pops['info@hodlekki.org']['password']));
check('the first sync ran with the new password', $info['last_synced_at'] !== null || Store::folderByRole($info['id'], 'inbox') !== null);

// ------------------------------------------------------------------ grants
section('Who works from embrace@ (grants by seat key, role and person)');
Grants::set($admin, $embrace, [
    ['subject_type' => 'seat', 'subject_id' => $deptId, 'seat_type' => 'hod', 'level' => 'assign'],
    ['subject_type' => 'seat', 'subject_id' => $deptId, 'seat_type' => 'worker', 'level' => 'send'],
    ['subject_type' => 'role', 'subject_id' => $rpId, 'level' => 'read'],
]);
$embrace = Store::account($embrace['id']);
check('the HOD seat: read, send and assign', MailAccess::level($hod, $embrace) === 'assign' && MailAccess::can($hod, $embrace, 'assign'));
check('a worker: read and send, not assign', MailAccess::level($worker, $embrace) === 'send' && !MailAccess::can($worker, $embrace, 'assign'));
check('the Resident Pastor role: read only', MailAccess::level($pastor, $embrace) === 'read' && !MailAccess::can($pastor, $embrace, 'send'));
check('a member: nothing', MailAccess::level($member, $embrace) === null);
check('a Super Admin manages mailboxes but does not READ them without a grant', MailAccess::level($admin, $embrace) === null);
$want = [$pastor, $hod, $worker];
sort($want);
check('readers: exactly those three', array_keys(MailAccess::readers($embrace)) === $want);
$e = throws(fn () => Grants::set($admin, $embrace, [['subject_type' => 'seat', 'subject_id' => $deptId, 'seat_type' => 'nonexistent', 'level' => 'read']]));
check('a seat key that does not exist is refused', $e instanceof MailError);
$pdo->exec("UPDATE user_departments SET is_active = 0 WHERE user_id = {$worker}");
MailAccess::flush();
check('a roster move takes effect at once', MailAccess::level($worker, $embrace) === null);
$pdo->exec("UPDATE user_departments SET is_active = 1 WHERE user_id = {$worker}");
MailAccess::flush();

// ------------------------------------------------------------------ sync
section('Sync, automatic replies, the unread badge');
$mail->deliver('embrace@hodlekki.org', 'INBOX', FakeMailServer::message(from: 'Ada Visitor <ada@example.test>', to: 'embrace@hodlekki.org', subject: 'Thank you', text: 'I enjoyed Sunday.'));
$auto = FakeMailServer::message(from: 'Bola <bola@example.test>', to: 'embrace@hodlekki.org', subject: 'Out of office', text: 'I am away.');
$auto['headers'] = "Auto-Submitted: auto-replied\r\n" . $auto['headers'];
$mail->deliver('embrace@hodlekki.org', 'INBOX', $auto);
$res = MailSync::make()->account($embrace['id'], 20.0, true);
check('two messages copied', $res['status'] === 'ok' && (int) Db::value('SELECT COUNT(*) FROM mail_messages WHERE account_id = ?', [$embrace['id']]) === 2, json_encode($res));
check('the out-of-office is marked automatic', (int) Db::value("SELECT is_auto FROM mail_messages WHERE account_id = ? AND subject = 'Out of office'", [$embrace['id']]) === 1);
check('the change stamp moved', Store::account($embrace['id'])['change_stamp'] > $embrace['change_stamp']);
$_SESSION = [];
check('the badge counts unread conversations for a reader, none for a member', MailNav::unread($hod) === 2 && (function () use ($member) { MailNav::forget(); return MailNav::unread($member) === 0; })());
$again = MailSync::make()->account($embrace['id'], 20.0);
check('a second run with nothing new skips the unchanged folders', $again['added'] === 0 && $again['removed'] === 0);

// ------------------------------------------------------------------ reading (PR 2)
section('Reading: the list, a conversation, folders, pictures');
$inboxFor = static fn (int $who, array $q = []) => \HOD\Mail\Read\Inbox::open($who, $q + ['box' => $embrace['id']]);
$box = $inboxFor($hod);
check('the HOD opens embrace@ at its Inbox', $box->account !== null && $box->account['id'] === $embrace['id'] && ($box->folder['role'] ?? '') === 'inbox');
check('a member cannot open it', $inboxFor($member)->account === null);
$list = \HOD\Mail\Read\Screen::list($box, '', 'all', 1);
check('the list shows both conversations', $list['total'] === 2 && str_contains($list['html'], 'Thank you') && str_contains($list['html'], 'Out of office'));
$thankId = (int) Db::value("SELECT thread_id FROM mail_messages WHERE account_id = ? AND subject = 'Thank you'", [$embrace['id']]);
Db::exec("UPDATE mail_threads SET status = 'waiting' WHERE id = ?", [$thankId]);
check('the Waiting filter', \HOD\Mail\Read\Screen::list($box, '', 'waiting', 1)['total'] === 1);
check('search finds the words of a message', \HOD\Mail\Read\ThreadList::search($hod, $embrace, 'Sunday')['total'] === 1);
check('search by sender', \HOD\Mail\Read\ThreadList::search($hod, $embrace, 'from:bola')['total'] === 1);
$open = \HOD\Mail\Read\Screen::thread($inboxFor($hod, ['thread' => $thankId]), Store::thread($thankId));
check('opening shows the text, escaped', str_contains($open['messages'], 'I enjoyed Sunday.'));
check('opening marks it read for everyone, and says who', $open['changed'] && (int) Db::value('SELECT seen_by FROM mail_messages WHERE thread_id = ?', [$thankId]) === $hod);
check('the server is told too', in_array('\\Seen', $mail->folder('embrace@hodlekki.org', 'INBOX')['messages'][1]['flags'] ?? [], true)
    || (int) Db::value('SELECT is_seen FROM mail_messages WHERE thread_id = ?', [$thankId]) === 1);
MailNav::forget();
check('the badge drops by one', MailNav::unread($hod) === 1);
check('Reply and Forward, now that the composer exists', str_contains($open['head'], 'data-compose="reply"') && str_contains($open['messages'], 'data-compose="forward"'));
check('the HOD may archive', str_contains($open['head'], 'data-action="archive"'));
$pastorView = \HOD\Mail\Read\Screen::thread($inboxFor($pastor, ['thread' => $thankId]), Store::thread($thankId), null, [], false, false);
check('a read-only reader sees no folder actions and no Reply', !str_contains($pastorView['head'], 'data-action="archive"') && !str_contains($pastorView['head'], 'data-move')
    && !str_contains($pastorView['head'] . $pastorView['messages'], 'data-compose'));
$e = throws(fn () => \HOD\Mail\Read\Actions::run($inboxFor($pastor, ['thread' => $thankId]), Store::thread($thankId), 'archive', false));
check('and cannot archive through the API either', $e instanceof MailError);
$moved = \HOD\Mail\Read\Actions::run($inboxFor($hod, ['thread' => $thankId]), Store::thread($thankId), 'archive', false);
check('archive moves it out of the Inbox, with an Undo', $moved['left'] && is_array($moved['undo']) && $moved['undo']['ids'] !== []);
MailSync::make()->account($embrace['id'], 20.0, true);
check('the Inbox list no longer has it', \HOD\Mail\Read\Screen::list($inboxFor($hod), '', 'all', 1)['total'] === 1);
\HOD\Mail\Read\Actions::undo($inboxFor($hod), $moved['undo']['ids'], (int) $moved['undo']['folder']);
MailSync::make()->account($embrace['id'], 20.0, true);
check('Undo brings it back', \HOD\Mail\Read\Screen::list($inboxFor($hod), '', 'all', 1)['total'] === 2);
check('nobody trusts a sender\'s pictures at first', \HOD\Mail\Read\ImageTrust::trusted($hod, ['ada@example.test']) === []);
check('Always show is saved for that person', \HOD\Mail\Read\ImageTrust::trust($hod, 'Ada@Example.test') && \HOD\Mail\Read\ImageTrust::trusted($hod, ['ADA@example.test']) === ['ada@example.test']);
check('and only for that person', \HOD\Mail\Read\ImageTrust::trusted($worker, ['ada@example.test']) === []);
check('a non-address is refused', !\HOD\Mail\Read\ImageTrust::trust($hod, 'not an address'));
check('the counts carry a change stamp the page polls', strlen($inboxFor($hod)->counts()['stamp']) === 16);
section('A half-done password change never locks the ERP out');
$newPw = 'Rotated-' . bin2hex(random_bytes(6));
$mail->setPassword('embrace@hodlekki.org', $newPw);
Db::update('mail_accounts', ['secret_pending' => Vault::encrypt($newPw, $embrace['id'], $embrace['email'])], $embrace['id']);
$session = MailServer::make()->open(Store::account($embrace['id']));
$session->logout();
$row = $pdo->query('SELECT secret, secret_pending FROM mail_accounts WHERE id = ' . (int) $embrace['id'])->fetch();
check('the pending password was tried and kept', $row['secret_pending'] === null && Vault::decrypt($row['secret'], $embrace['id'], $embrace['email']) === $newPw);

// ------------------------------------------------------------------ personal mailboxes
section('A personal mailbox');
$e = throws(fn () => Lifecycle::requestPersonal($admin, $pastor, ''));
check('not without the matrix ability mail.personal', $e instanceof MailError && $e->field === 'user_id');
$pdo->prepare("INSERT INTO perm_grants (role_id, seat_key, module_key, level, scope) VALUES (?, '', 'mail.personal', 1, 'church')")->execute([$rpId]);
perm_reset();
MailAccess::flush();
MailSettings::set('quota.reserved_personal', 10);
$r = Lifecycle::requestPersonal($admin, $pastor, '');
check('firstname.lastname suggested', $r['account']['email'] === 'tunde.pastor@hodlekki.org');
Jobs::run();
$personal = Store::account($r['account']['id']);
check('made on the server, active, the holder told', $personal['status'] === 'active' && isset($uapi->pops['tunde.pastor@hodlekki.org'])
    && (int) Db::value("SELECT COUNT(*) FROM system_notifications WHERE user_id = ? AND title = 'Your church email is ready'", [$pastor]) === 1);
check('its holder owns it; nobody else reads it, Super Admins included', MailAccess::level($pastor, $personal) === 'owner'
    && MailAccess::level($admin, $personal) === null && MailAccess::level($hod, $personal) === null);

section('Two admins at the last slot');
MailSettings::set('quota.reserved_personal', 0);
foreach (range(1, 8) as $i) {
    $uapi->pops["filler{$i}@hodlekki.org"] = ['password' => 'x', 'quota' => 1, 'used' => 0, 'suspended' => false];
}
Quota::refresh();
check('14 of 15 used', Quota::snapshot()['domain_used'] === 14);
$first = throws(fn () => Lifecycle::requestShared($admin, ['kind' => 'church', 'local_part' => 'media', 'display_name' => 'Media']));
$second = throws(fn () => Lifecycle::requestShared($admin2, ['kind' => 'church', 'local_part' => 'finance', 'display_name' => 'Finance']));
check('the first is queued, the second refused (queued creates count)', $first === null && $second instanceof MailError && str_contains($second->getMessage(), '15 of its 15'));
$uapi->failNext['add_pop'] = 'You have reached the maximum allowed email accounts.';
Jobs::run();
$media = Store::accountByEmail('media@hodlekki.org');
check("a refusal by cPanel: the mailbox is marked failed, the admin told in cPanel's words", $media['status'] === 'failed'
    && (int) Db::value("SELECT COUNT(*) FROM system_notifications WHERE user_id = ? AND message LIKE '%maximum allowed%'", [$admin]) === 1);
foreach (range(1, 8) as $i) {
    unset($uapi->pops["filler{$i}@hodlekki.org"]);
}

// ------------------------------------------------------------------ the holder leaves
section('The holder leaves');
$pdo->exec("UPDATE user_roles SET is_frozen = 1 WHERE user_id = {$pastor} AND role_id = {$rpId}");
perm_reset();
MailAccess::flush();
$n = Nightly::access();
check('the nightly diff starts the leaving path', $n['leaving'] === 1);
$pwBefore = $uapi->pops['tunde.pastor@hodlekki.org']['password'];
Jobs::run();
$personal = Store::account($personal['id']);
check('password changed, login suspended, auto-reply to info@ for 30 days', $personal['status'] === 'leaving'
    && $uapi->pops['tunde.pastor@hodlekki.org']['suspended'] && $uapi->pops['tunde.pastor@hodlekki.org']['password'] !== $pwBefore
    && str_contains($uapi->autoReplies['tunde.pastor@hodlekki.org'][1] ?? '', 'info@hodlekki.org'));
check('removal planned in 30 days', abs(strtotime((string) $personal['remove_after']) - strtotime('+30 days')) < 120);

section('Export: two different people, then a download');
$pdo->exec("UPDATE user_roles SET is_frozen = 0 WHERE user_id = {$pastor} AND role_id = {$rpId}");
perm_reset();
MailAccess::flush();
$mail->deliver('tunde.pastor@hodlekki.org', 'INBOX', FakeMailServer::message(from: 'Elder <elder@example.test>', to: 'tunde.pastor@hodlekki.org', subject: 'From the elders', text: "Line one\r\nFrom the start"));
$e = throws(fn () => Exports::request($admin, $personal['id'], 'short'));
check('a reason is required', $e instanceof MailError && $e->field === 'reason');
$reqId = Exports::request($admin, $personal['id'], 'The church needs the correspondence with the bank about the building fund.');
check('the pastoral approver is told', (int) Db::value("SELECT COUNT(*) FROM system_notifications WHERE user_id = ? AND title LIKE '%export request%'", [$pastor]) === 1);
$e = throws(fn () => Exports::approve($admin, $reqId, 'pastoral'));
check('a Super Admin cannot give the pastoral side', $e instanceof MailError && $e->status === 403);
Exports::approve($admin, $reqId, 'admin');
check('one approval is not enough', Exports::find($reqId)['status'] === 'pending');
$pdo->exec("INSERT INTO user_roles (user_id, role_id) VALUES ({$admin2}, {$rpId})");
perm_reset();
MailAccess::flush();
$e = throws(fn () => Exports::approve($admin, $reqId, 'pastoral'));
check('the same person never gives both sides', $e instanceof MailError);
Exports::approve($pastor, $reqId, 'pastoral');
check('both: approved, a job opens the login', Exports::find($reqId)['status'] === 'approved'
    && (int) Db::value("SELECT COUNT(*) FROM mail_admin_jobs WHERE action = 'export_open' AND status = 'queued'") === 1);
Jobs::run();
check('ready for 7 days, login lifted, the requester told', Exports::find($reqId)['status'] === 'ready' && !$uapi->pops['tunde.pastor@hodlekki.org']['suspended']);
$e = throws(fn () => Exports::folders($pastor, $reqId));
check('only the person who asked downloads', $e instanceof MailError && $e->status === 403);
$out = '';
$count = Exports::stream($admin, $reqId, 'INBOX', function (string $chunk) use (&$out) { $out .= $chunk; });
check('the folder streams as mbox, From lines escaped', $count === 1 && str_starts_with($out, 'From MAILER-DAEMON ') && str_contains($out, "\n>From the start"));
check('the download is on record', (int) Db::value("SELECT COUNT(*) FROM mail_export_events WHERE request_id = ? AND event = 'downloaded'", [$reqId]) === 1);

section('Removal, with a forwarder to info@');
Db::update('mail_accounts', ['remove_after' => Clock::sql(-60)], $personal['id']);
Nightly::removals();
check('an export still open is closed by the removal date', Exports::find($reqId)['status'] === 'cancelled');
Jobs::run();
check('deleted on the server, forwarded to info@, gone from the ERP', !isset($uapi->pops['tunde.pastor@hodlekki.org'])
    && in_array(['tunde.pastor@hodlekki.org', 'info@hodlekki.org'], $uapi->forwarders, true) && Store::account($personal['id']) === null);

section('Losing access to a shared mailbox gives it a new password');
Nightly::access();
$pdo->exec("UPDATE user_departments SET is_active = 0 WHERE user_id = {$worker}");
MailAccess::flush();
$before = $uapi->pops['embrace@hodlekki.org']['password'];
$n = Nightly::access();
Jobs::run();
check('the diff queues a reset and it runs', $n['resets'] === 1 && $uapi->pops['embrace@hodlekki.org']['password'] !== $before
    && Store::account($embrace['id'])['status'] === 'active');

section('Sending takes the domain\'s hourly budget (docs/EMAIL.md)');
$pdo->exec('DELETE FROM email_rate_buckets');
$sentTotal = static fn (): int => (int) Db::value('SELECT COALESCE(SUM(sent_total), 0) FROM email_rate_buckets');
$msg = static fn () => (new Message())->to('ada@example.test')->cc('bola@example.test')->subject('Welcome')->text('Good to see you.');
$box = Store::account($embrace['id']);
$out = MailTransport::make()->send($hod, $box, $msg(), false);
check('a reply to two people takes two slots in the mailbox lane', $sentTotal() === 2 && count($mail->sent) === 1 && $out['message_id'] !== '');
$mail->smtpFailure = 'temporary';
$e = throws(fn () => MailTransport::make()->send($hod, $box, $msg(), false));
check('a send that fails gives its slots back', $e instanceof \HOD\Mail\MailSendException && $sentTotal() === 2);
$mail->smtpFailure = 'temporary';
$mail->smtpFailureText = 'Domain hodlekki.org has exceeded the max emails per hour (100/100 (100%)) allowed.';
throws(fn () => MailTransport::make()->send($hod, $box, $msg(), false));
check("the host's hourly refusal stops every sender for the hour", Db::value('SELECT refused_at FROM email_rate_buckets LIMIT 1') !== null);
$before = count($mail->sent);
$e = throws(fn () => MailTransport::make()->send($hod, $box, $msg(), false));
check('then nothing more leaves this hour, and the person is told when', $e instanceof \HOD\Mail\MailSendException && $e->reason === 'temporary'
    && count($mail->sent) === $before && str_contains($e->getMessage(), 'next hour'));
$e = throws(fn () => MailTransport::make()->send($pastor, $box, $msg(), false));
check('reading is not sending: a Read grant cannot send', $e instanceof MailError && $e->status === 403);
$pdo->exec('DELETE FROM email_rate_buckets');
// ------------------------------------------------------------------ writing (PR 3)
section('Writing: an answer, Undo, sending once, the signature');
$ob = \HOD\Mail\Compose\Outbox::make();
$sender = \HOD\Mail\Compose\OutboxSender::make();
$orow = static fn (string $id) => \HOD\Mail\Compose\Outbox::row($id);
$box = Store::account($embrace['id']);
$orig = Store::message((int) Db::value("SELECT id FROM mail_messages WHERE account_id = ? AND subject = 'Thank you' ORDER BY id LIMIT 1", [$embrace['id']]));
$e = throws(fn () => $ob->start($pastor, 'reply', null, $orig['id']));
check('a reader cannot answer: Read is not send', $e instanceof MailError && $e->status === 403);
$e = throws(fn () => $ob->start($member, 'new'));
check('someone with no mailbox to send from cannot write', $e instanceof MailError && $e->status === 403);
$draft = $ob->start($hod, 'reply', null, $orig['id']);
$p = $draft['payload'];
check('Reply: to the sender, "Re:", threading headers, the original quoted, the short signature',
    $p['to'][0]['email'] === 'ada@example.test' && $p['subject'] === 'Re: Thank you' && $p['in_reply_to'] === $orig['message_id']
    && str_contains((string) $p['quote_text'], 'I enjoyed Sunday.') && $p['signature'] === 'slim' && $draft['thread_id'] === $orig['thread_id']);
check('the quote is escaped HTML', !str_contains((string) $p['quote_html'], '<script') && str_contains((string) $p['quote_html'], 'wrote:'));
$e = throws(fn () => $ob->own($worker, $draft['id'], ['draft']));
check("someone else's draft does not exist for them", $e instanceof MailError && $e->status === 404);
$again = $ob->start($hod, 'reply', null, $orig['id']);
$r = $ob->save($hod, $draft['id'], ['html' => '<p onclick="x()">Thank you, <b>Ada</b>!<script>alert(1)</script></p>', 'cc' => 'not an address, bola@example.test']);
check('saving cleans the HTML and reports what is not an address', $r['row']['payload']['html'] === '<p>Thank you, <b>Ada</b>!</p>'
    && $r['invalid']['cc'] === ['not an address'] && $r['row']['payload']['cc'][0]['email'] === 'bola@example.test');
check('an answer opened again carries on with the written draft', $ob->start($hod, 'reply', null, $orig['id'])['id'] === $draft['id'] && $again['id'] !== $draft['id']);
$ob->discard($hod, $again['id']);
$att = $ob->addUpload($hod, $draft['id'], 'notes.pdf', '%PDF-1.4 test');
check('a file is kept in the database, typed by its first bytes', $att['mime'] === 'application/pdf' && \HOD\Mail\Compose\Files::get($draft['id'], $att['key']) === '%PDF-1.4 test');
\HOD\Mail\Compose\ComposeConfig::set('max_attachment_bytes', 20);
$e = throws(fn () => $ob->addUpload($hod, $draft['id'], 'big.bin', str_repeat('x', 30)));
check('files past the limit are refused', $e instanceof MailError && $e->status === 413);
\HOD\Mail\Compose\ComposeConfig::reset();
check('the copy in the server\'s Drafts', $ob->saveServerDraft($hod, $draft['id']) && !empty($orow($draft['id'])['payload']['server_draft']['uid']));
$ob->queue($hod, $draft['id']);
check('Send waits for Undo', $orow($draft['id'])['status'] === 'queued' && $sender->send($draft['id'])['status'] === 'queued');
check('Undo brings it back as a draft', $ob->undo($hod, $draft['id']) && $orow($draft['id'])['status'] === 'draft');
$ob->queue($hod, $draft['id']);
$pdo->exec('DELETE FROM email_rate_buckets');
$before = count($mail->sent);
$row = $sender->send($draft['id'], 30);
$raw = $mail->sent[$before]['raw'] ?? '';
check('it leaves from the mailbox, as an answer, with the file', $row['status'] === 'sent' && count($mail->sent) === $before + 1
    && preg_match('/^From: .*embrace@hodlekki\.org/mi', $raw) && preg_match('/^In-Reply-To: <' . preg_quote((string) $orig['message_id'], '/') . '>/mi', $raw)
    && str_contains($raw, 'notes.pdf'));
check('it takes the hourly budget: two recipients, two slots', $sentTotal() === 2);
check('never twice', $sender->send($draft['id'], 30)['status'] === 'sent' && count($mail->sent) === $before + 1);
check('its files go once it has left', !\HOD\Mail\Compose\Files::has($draft['id'], $att['key']));
check('the copy in Sent is credited to the writer', (int) Db::value('SELECT sent_by FROM mail_messages WHERE account_id = ? AND message_id_hash = ? LIMIT 1', [$embrace['id'], $row['message_id_hash']]) === $hod);
check('the original is marked answered', (int) Db::value('SELECT is_answered FROM mail_messages WHERE id = ?', [$orig['id']]) === 1);
check('the shared conversation records who sent it', (int) Db::value("SELECT COUNT(*) FROM mail_thread_activity WHERE type = 'sent' AND user_id = ?", [$hod]) >= 1);

section('Writing: retries, failures, schedules, drafts');
$new = $ob->start($hod, 'new', $embrace['id'], null, 'mailto:visitor@example.test');
check('New mail from a mailto: link, with the full signature', $new['kind'] === 'new' && $new['payload']['to'][0]['email'] === 'visitor@example.test' && $new['payload']['signature'] === 'full');
$ob->save($hod, $new['id'], ['subject' => 'Welcome', 'html' => '<p>Welcome to HOD.</p>']);
$ob->queue($hod, $new['id']);
$mail->smtpFailure = 'temporary';
$row = $sender->send($new['id'], 30);
check('a temporary failure waits and tries again', $row['status'] === 'queued' && $row['attempts'] === 1 && $row['last_error'] !== null
    && $row['send_at'] > Clock::sql(30));
Db::update('mail_outbox', ['send_at' => Clock::sql(-1)], $new['id']);
$mail->smtpFailure = 'rejected';
$notes = (int) Db::value('SELECT COUNT(*) FROM system_notifications WHERE user_id = ?', [$hod]);
$row = $sender->due()['failed'] === 1 ? $orow($new['id']) : null;
check('a refusal fails it and tells the writer', $row && $row['status'] === 'failed' && (int) Db::value('SELECT COUNT(*) FROM system_notifications WHERE user_id = ?', [$hod]) === $notes + 1);
check('a failed message opens again as a draft', $ob->open($hod, $new['id'])['status'] === 'draft');
$e = throws(fn () => $ob->queue($hod, $new['id'], Clock::now()->modify('+10 seconds')));
check('a schedule needs a time at least a minute away', $e instanceof MailError && $e->field === 'send_at');
$ob->queue($hod, $new['id'], Clock::now()->modify('+2 hours'));
check('a scheduled message waits for its time', $sender->due()['sent'] === 0 && $orow($new['id'])['status'] === 'queued');
check('the Outbox page lists it, and only to its writer', count($ob->listFor($hod)['scheduled']) === 1 && $ob->listFor($worker)['scheduled'] === []);
check('Delete works on a scheduled message', $ob->discard($hod, $new['id']) && $orow($new['id']) === null);
$e = throws(fn () => $ob->queue($hod, $ob->start($hod, 'new', $embrace['id'])['id']));
check('no recipient, no send', $e instanceof MailError && $e->field === 'to');
Db::exec("UPDATE mail_outbox SET updated_at = ? WHERE status = 'draft'", [Clock::sql(-2 * 86400)]);
check('drafts opened and never written in are pruned', $ob->pruneUntouched() >= 1);
$stuck = $ob->start($hod, 'new', $embrace['id'], null, 'x@example.test');
Db::update('mail_outbox', ['status' => 'sending', 'updated_at' => Clock::sql(-3600)], $stuck['id']);
check('a send that stopped half-way is never sent again by itself', $sender->due()['stuck'] === 1 && $orow($stuck['id'])['status'] === 'failed');

section('Writing: suggestions and the signature');
$names = array_column(\HOD\Mail\Compose\Recipients::suggest($hod, 'ada'), 'email');
check('suggestions include people in mail the person reads', in_array('ada@example.test', $names, true));
check('no suggestions for someone who cannot send', \HOD\Mail\Compose\Recipients::suggest($member, 'ada') === []);
\HOD\Mail\Compose\Signatures::setNumbers($hod, '+234 801 234 5678', '');
$sig = \HOD\Mail\Compose\Signatures::html($hod, $box, 'full');
check('the signature carries the person, the mailbox and the church', str_contains($sig, \HOD\Mail\People::name($hod)) && str_contains($sig, 'embrace@hodlekki.org')
    && str_contains($sig, 'tel:+2348012345678') && str_contains(\HOD\Mail\Compose\Signatures::text($hod, $box, 'full'), "-- \n"));
$e = throws(fn () => \HOD\Mail\Compose\Signatures::setNumbers($hod, 'call me', null));
check('a number that is not one is refused', $e instanceof MailError && $e->field === 'phone');
\HOD\Mail\Compose\Signatures::setChurch(['name' => 'Household of David <Lekki>', 'show_logo' => false], $admin);
$sig = \HOD\Mail\Compose\Signatures::html($hod, $box, 'full');
check('the church part is escaped, and the logo can be hidden', str_contains($sig, 'Household of David &lt;Lekki&gt;') && !str_contains($sig, '<img'));
\HOD\Mail\Compose\Signatures::resetChurch();
$pdo->exec('DELETE FROM email_rate_buckets');
section('Removing needs the address typed');
$e = throws(fn () => Lifecycle::request($admin, 'remove', Store::account($info['id']), ['confirm' => 'info']));
check('a wrong confirmation is refused', $e instanceof MailError && $e->field === 'confirm');
$e = throws(fn () => Lifecycle::request($admin, 'remove', Store::account($info['id']), ['confirm' => 'info@hodlekki.org', 'forward_to' => 'me@gmail.com']));
check('a forwarder outside hodlekki.org is refused', $e instanceof MailError && $e->field === 'forward_to');

\HOD\Mail\Admin\Uapi::use(null);
MailServer::use(null);
mail_test_done();
