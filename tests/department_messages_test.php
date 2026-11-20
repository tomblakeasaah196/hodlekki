<?php
// /tests/department_messages_test.php
//
// BEHAVIOURAL tests for Departments > Messages (includes/department_messages.php):
// one message to workers by text, email and app alert, now or later. They run
// the real functions on the email suite's in-memory SQLite (tests/campaigns/
// lib.php: the real email migration, a pinned Lagos clock, a fake mail
// transport) and pin:
//
//   - who it reaches: the live year's Active rosters (all, chosen departments
//     with or without sub-units, leaders, absent at an event), Relocated never;
//   - one text per usable number, one email per address, the email address
//     from email_news_address() (personal email, else the profile column,
//     never @hodlc.com), unsubscribed people never emailed;
//   - a send-now whose texts cannot go sends nothing; a scheduled one still
//     emails and alerts, and says why the texts did not go;
//   - a scheduled message goes once (claimed), a cancelled one never;
//   - the emails really leave through the outbox worker, as church news.
//
// Run from the repo root:
//     php tests/department_messages_test.php
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
$_ENV['SITE_URL'] = 'https://hodlekki.org';
$_ENV['MAIL_FROM_ADDRESS'] = 'system@hodlekki.org';
$_ENV['MAIL_USERNAME'] = 'system@hodlekki.org';
$_ENV['SMS_VAULT_KEY'] = str_repeat('0', 64);   // keeps sms_functions.php from loading db.php

require_once __DIR__ . '/../includes/security_helpers.php';
require_once __DIR__ . '/../includes/auth_helpers.php';
require_once __DIR__ . '/../includes/ui/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/sms_functions.php';
require_once __DIR__ . '/../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/../includes/department_messages.php';
require_once __DIR__ . '/campaigns/lib.php';

$GLOBALS['ct_passed'] = $GLOBALS['ct_passed'] ?? 0;
$GLOBALS['ct_failed'] = $GLOBALS['ct_failed'] ?? 0;
$ok = 'ct_ok';
$eq = 'ct_eq';

/** The email suite's database, plus what Departments needs. */
function dm_db(): PDO
{
    $pdo = ct_db();
    foreach ([
        'ALTER TABLE departments ADD COLUMN parent_id INTEGER',
        "ALTER TABLE user_departments ADD COLUMN membership_type TEXT DEFAULT 'Primary'",
        'ALTER TABLE user_departments ADD COLUMN ministry_year_id INTEGER',
        "ALTER TABLE user_departments ADD COLUMN roster_status TEXT DEFAULT 'Active'",
        'ALTER TABLE events ADD COLUMN end_date TEXT',
        'ALTER TABLE events ADD COLUMN is_closed INTEGER DEFAULT 0',
        'ALTER TABLE checkins ADD COLUMN checked_in_at TEXT',
        'ALTER TABLE users ADD COLUMN phone_status TEXT',
        'ALTER TABLE sms_log ADD COLUMN error_message TEXT',   // production has it (20261004090000)
        "CREATE TABLE dept_messages (id INTEGER PRIMARY KEY AUTOINCREMENT, subject TEXT NOT NULL, body TEXT NOT NULL,
            audience TEXT NOT NULL, department_ids TEXT, include_subunits INTEGER NOT NULL DEFAULT 1, event_id INTEGER,
            send_at TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'scheduled', sms_campaign_id INTEGER, counts_json TEXT,
            error_message TEXT, created_by INTEGER, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, sent_at TEXT)",
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException $e) {
            // the permission install already added this column
            if (!str_contains($e->getMessage(), 'duplicate column')) {
                throw $e;
            }
        }
    }
    // Departments: Choir (1) with its sub-unit Audio (3), Ushers (2).
    $pdo->exec("INSERT INTO departments (id, name, parent_id) VALUES (1, 'Choir', NULL), (2, 'Ushers', NULL), (3, 'Audio', 1)");
    $pdo->exec("INSERT INTO events (id, title, event_category, event_date, is_closed) VALUES (50, 'Workers Meeting', 'Workers_Meeting', '2026-11-20 22:00:00', 0)");
    $people = [
        // id, first, last, phone, real_email, email, status, attendance_status
        [101, 'Ada', 'Okoro', '08031112222', null, 'ada.okoro@gmail.com', 'Worker', 'Active'],
        [102, 'Bayo', 'Lawal', '+2348033334444', 'bayo@yahoo.com', 'bayo@hodlc.com', 'Worker', 'Active'],
        [103, 'Chi', 'Eze', '0803 111 2222', null, 'chi@hodlc.com', 'Worker', 'Active'],     // shares Ada's number
        [104, 'Dayo', 'Ola', '', null, 'dayo@outlook.com', 'Worker', 'Active'],
        [105, 'Efe', 'Moved', '08050000000', null, 'efe@gmail.com', 'Worker', 'Relocated'],
        [106, 'Femi', 'Removed', '08060000000', null, 'femi@gmail.com', 'Worker', 'Active'],
        [107, 'Gbenga', 'Leader', '08070000000', null, 'GBENGA@HODLC.COM', 'Pastor', 'Active'],
        [108, 'Halima', 'Audio', '12345', null, 'shared@gmail.com', 'Worker', 'Active'],     // bad number
        [109, 'Ife', 'Audio', '08090000000', 'shared@gmail.com', null, 'Worker', 'Active'], // same address as Halima
        [110, 'Jide', 'Gone', '08100000000', 'jide@gmail.com', null, 'Worker', 'Active'],   // unsubscribed
    ];
    foreach ($people as [$id, $first, $last, $phone, $real, $email, $status, $att]) {
        ct_user($pdo, $id, $first, $status, ['last_name' => $last, 'phone' => $phone, 'email' => $email, 'attendance_status' => $att]);
        $pdo->prepare('UPDATE users SET real_email = ? WHERE id = ?')->execute([$real, $id]);
    }
    email_consent_record($pdo, 110, 'withdrawn', 'profile', ['email' => 'jide@gmail.com']);
    $roster = [
        // user, dept, seat, type, year, status
        [101, 1, 'Worker', 'Primary', 1, 'Active'],
        [102, 2, 'Worker', 'Primary', 1, 'Active'],
        [102, 3, 'Worker', 'Secondary', 1, 'Active'],
        [103, 2, 'Worker', 'Primary', 1, 'Active'],
        [104, 1, 'Worker', 'Secondary', 1, 'Active'],
        [105, 1, 'Worker', 'Primary', 1, 'Active'],
        [106, 2, 'Worker', 'Primary', 1, 'Removed'],
        [107, 1, 'HOD', 'Primary', 1, 'Active'],
        [108, 3, 'Worker', 'Primary', 1, 'Active'],
        [109, 3, 'Worker', 'Primary', 1, 'Active'],
        [110, 2, 'Worker', 'Primary', 1, 'Active'],
        [101, 2, 'Worker', 'Primary', 2, 'Active'],   // another year: not counted
    ];
    $st = $pdo->prepare('INSERT INTO user_departments (user_id, department_id, role_in_dept, membership_type, ministry_year_id, roster_status, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)');
    foreach ($roster as $r) {
        $st->execute($r);
    }
    return $pdo;
}

$spec = static function (string $audience, array $extra = []): array {
    $r = dept_msg_spec(['audience' => $audience] + $extra);
    if (!$r['ok']) {
        throw new RuntimeException('bad spec: ' . $r['message']);
    }
    return $r['spec'];
};
$ids = static fn (array $people): array => array_map(static fn ($p) => $p['user_id'], $people);
$LEAD = ['HOD'];

// ===========================================================================
ct_section('1. The message text');
// ===========================================================================
$eq('an empty message is refused', 'Write the message.', dept_msg_body_problem('  '));
$ok('too long is refused', dept_msg_body_problem(str_repeat('a', 601)) !== null);
$ok('an unknown merge field is refused', dept_msg_body_problem('Hi {{guest_name}}') !== null);
$ok('…and {{link}} too', dept_msg_body_problem('Go to {{link}}') !== null);
$eq('first, last and full name are fine', null, dept_msg_body_problem('Dear {{first_name}} {{ last_name }}, {{full_name}}'));
$eq('the alert fills names in', 'Dear Ada, Ada Okoro.', dept_msg_fill('Dear {{first_name}}, {{full_name}}.', 'Ada', 'Okoro'));

// ===========================================================================
ct_section('2. Who gets it');
// ===========================================================================
$eq('an unknown audience is refused', false, dept_msg_spec(['audience' => 'everyone'])['ok']);
$eq('departments need at least one ticked', false, dept_msg_spec(['audience' => 'departments', 'department_ids' => '[]'])['ok']);
$eq('absent needs the event', false, dept_msg_spec(['audience' => 'absent'])['ok']);
$eq('department ids are cleaned (JSON from the page)', [2, 1], $spec('departments', ['department_ids' => '["2","1","2","x"]'])['department_ids']);

$pdo = dm_db();
$all = dept_msg_people($pdo, $spec('all'), 1, $LEAD);
$eq('all workers: the live year\'s Active rows, one per person, A to Z', [101, 102, 103, 104, 107, 108, 109, 110], $ids($all));
$ok('Relocated and Removed are never included', !in_array(105, $ids($all), true) && !in_array(106, $ids($all), true));
$bayo = array_values(array_filter($all, static fn ($p) => $p['user_id'] === 102))[0];
$eq('someone on two rosters carries both', ['Ushers', 'Audio'], $bayo['departments']);
$eq('Choir with its sub-units takes Audio too', [101, 102, 104, 107, 108, 109],
    $ids(dept_msg_people($pdo, $spec('departments', ['department_ids' => [1], 'include_subunits' => '1']), 1, $LEAD)));
$eq('…without sub-units, Choir alone', [101, 104, 107],
    $ids(dept_msg_people($pdo, $spec('departments', ['department_ids' => [1], 'include_subunits' => '0']), 1, $LEAD)));
$eq('leaders only', [107], $ids(dept_msg_people($pdo, $spec('leaders'), 1, $LEAD)));
$pdo->exec("INSERT INTO attendance (event_id, user_id, status, check_in_time) VALUES (50, 101, 'Present', '2026-11-20 21:50:00')");
$pdo->exec("INSERT INTO checkins (event_id, user_id, checkin_date, checked_in_at) VALUES (50, 102, '2026-11-20', '2026-11-20 21:55:00')");
$eq('absent at the event leaves out a clock-in and a door check-in', [103, 104, 107, 108, 109, 110],
    $ids(dept_msg_people($pdo, $spec('absent', ['event_id' => 50]), 1, $LEAD)));

// ===========================================================================
ct_section('3. One text per number, one email per address');
// ===========================================================================
$sms = dept_msg_sms_recipients($all);
$eq('texts: Ada, Bayo, Gbenga, Ife, Jide', ['2348031112222', '2348033334444', '2348070000000', '2348090000000', '2348100000000'],
    array_column($sms['data'], 'phone'));
$eq('…Chi shares Ada\'s number, Dayo has none, Halima\'s is not a number', [1, 1, 1], [$sms['duplicates'], $sms['no_phone'], $sms['invalid']]);
$eq('a text carries the names for {{first_name}}', ['Ada', 'Okoro', 'user', 101],
    [$sms['data'][0]['first_name'], $sms['data'][0]['last_name'], $sms['data'][0]['source'], $sms['data'][0]['source_id']]);
$pdo->exec("UPDATE users SET phone_status = 'invalid' WHERE id = 109");
$eq('a number marked invalid is not billed', 1 + 1, dept_msg_sms_recipients(dept_msg_people($pdo, $spec('all'), 1, $LEAD))['invalid']);
$pdo->exec('UPDATE users SET phone_status = NULL WHERE id = 109');

$mail = dept_msg_email_targets($pdo, $all);
$eq('emails: profile Gmail, personal Yahoo, profile Outlook, one shared address', ['ada.okoro@gmail.com', 'bayo@yahoo.com', 'dayo@outlook.com', 'shared@gmail.com'],
    array_column($mail['data'], 'email'));
$eq('…never @hodlc.com (Chi, Gbenga), never someone unsubscribed (Jide), the shared address once', ['no_email' => 2, 'shared' => 1, 'withdrawn' => 1],
    (static function ($s) { ksort($s); return $s; })($mail['skipped']));
$eq('where the addresses came from (Halima, first A to Z, has the shared one in her profile)', ['personal' => 1, 'profile' => 3], (static function ($s) { ksort($s); return $s; })($mail['sources']));

$p = dept_msg_preview($pdo, $spec('all'), 'Dear {{first_name}}, the meeting is at 10 PM.', 1, $LEAD);
$eq('the preview counts people, texts, emails and alerts', [8, 5, 4, 8], [$p['people'], $p['sms']['count'], $p['email']['count'], $p['push']['count']]);
$eq('…one page each, at the list price', [1, 5, round(5 * SMS_DEFAULT_UNIT_PRICE, 2)], [$p['sms']['pages'], $p['sms']['units'], $p['sms']['cost']]);

// ===========================================================================
ct_section('4. Saving a message');
// ===========================================================================
$now = new DateTimeImmutable('2026-11-20 11:00:00');
$eq('a subject is needed', false, dept_msg_create($pdo, $spec('all'), ' ', 'Hi', null, 900, $now)['ok']);
$eq('a time under 5 minutes ahead is refused', false, dept_msg_create($pdo, $spec('all'), 'S', 'Hi', '2026-11-20T11:03', 900, $now)['ok']);
$eq('a time over 30 days ahead is refused', false, dept_msg_create($pdo, $spec('all'), 'S', 'Hi', '2026-12-25T11:00', 900, $now)['ok']);
$eq('a bad time is refused', false, dept_msg_create($pdo, $spec('all'), 'S', 'Hi', 'tonight', 900, $now)['ok']);
$later = dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, see you at 10 PM.', '2026-11-20T18:00', 900, $now);
$eq('later: stored as scheduled for that time', ['scheduled', '2026-11-20 18:00:00'], [$later['row']['status'], $later['row']['send_at']]);
$nowMsg = dept_msg_create($pdo, $spec('leaders'), 'Leaders', 'Hi {{first_name}}', null, 900, $now);
$eq('now: born claimed (sending) at this minute', ['sending', '2026-11-20 11:00:00'], [$nowMsg['row']['status'], $nowMsg['row']['send_at']]);

// ===========================================================================
ct_section('5. Sending: the channels');
// ===========================================================================
$calls = [];
$fake = static function (string $name, array $result) use (&$calls): callable {
    return static function (PDO $pdo, array $row, array $list) use ($name, $result, &$calls): array {
        $calls[$name] = count($list);
        return $result + ['ok' => true, 'queued' => count($list)];
    };
};
$noSms = ['sms' => $fake('sms', ['ok' => false, 'message' => 'Wallet too low']), 'email' => $fake('email', []), 'push' => $fake('push', [])];

$calls = [];
$r = dept_msg_expand($pdo, $nowMsg['row'], 1, $LEAD, $noSms, true);
$eq('send-now: texts cannot go → nothing goes', [false, ['sms' => 1]], [$r['ok'], $calls]);
$row = dept_msg_get($pdo, $nowMsg['id']);
$eq('…the row says why', ['failed', 'Texts not sent: Wallet too low'], [$row['status'], $row['error_message']]);

$calls = [];
$claimed = dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}', null, 900, $now);
$r = dept_msg_expand($pdo, $claimed['row'], 1, $LEAD, $noSms, false);
$eq('scheduled: emails and alerts still go', ['sms' => 5, 'email' => 4, 'push' => 8], $calls);
$row = dept_msg_get($pdo, $claimed['id']);
$eq('…sent, saying the texts did not', ['sent', 'Texts not sent: Wallet too low'], [$row['status'], $row['error_message']]);

// The real alert and email channels.
$pdo->exec('DELETE FROM system_notifications');
$real = ['sms' => $fake('sms', ['campaign_id' => 77, 'units' => 5])];
ct_at('2026-11-20 11:00');
$msg = dept_msg_create($pdo, $spec('all'), 'Workers meeting', "Dear {{first_name}},\nThe meeting is at 10 PM.", null, 900, $now);
$r = dept_msg_expand($pdo, $msg['row'], 1, $LEAD, $real, true);
$eq('all three went', [true, 5, 4, 8], [$r['ok'], $r['counts']['sms']['queued'], $r['counts']['email']['queued'], $r['counts']['push']['queued']]);
$eq('the SMS Studio campaign is kept on the row', 77, (int) dept_msg_get($pdo, $msg['id'])['sms_campaign_id']);
$alerts = $pdo->query('SELECT user_id, title, message, link_url FROM system_notifications ORDER BY user_id')->fetchAll();
$eq('one app alert per worker, names filled in', [101, 'Workers meeting', "Dear Ada,\nThe meeting is at 10 PM.", '/modules/member_portal/'],
    array_values($alerts[0]));
$out = $pdo->query("SELECT to_email, lane, purpose, flow, user_id, send_by FROM email_outbox WHERE flow = 'dept-msg-" . $msg['id'] . "' ORDER BY to_email")->fetchAll();
$eq('emails queued as church news on the campaign lane', ['ada.okoro@gmail.com', 'campaign', 'news', 'dept-msg-' . $msg['id'], 101],
    [$out[0]['to_email'], $out[0]['lane'], $out[0]['purpose'], $out[0]['flow'], (int) $out[0]['user_id']]);
$ok('…dropped if they cannot leave by the end of the day', str_starts_with((string) $out[0]['send_by'], '2026-11-20 23:59'));
$tx = ct_transport();
$run = email_worker_run($pdo, ['endpoints_ready' => true, 'transport' => $tx, 'now' => campaigns_now()]);
$eq('the email worker sends all four, profile and personal addresses alike', 4, $run['sent']);
$first = $tx['sent'][0];
$ok('each one has the one-click unsubscribe', isset($first['headers']['List-Unsubscribe']));
$ok('the name is filled in the email', str_contains((string) $first['text'], 'Dear Ada') || str_contains((string) $first['html'], 'Dear Ada'));
$eq('the email validates as a block document', true, email_doc_validate(dept_msg_email_doc('Workers meeting', "Dear {{first_name}},\nSee you."))['ok']);

// ===========================================================================
ct_section('6. Scheduled messages go once');
// ===========================================================================
$pdo->exec("DELETE FROM dept_messages");
$a = dept_msg_create($pdo, $spec('leaders'), 'Due', 'Hi {{first_name}}', '2026-11-20T18:00', 900, $now)['id'];
$b = dept_msg_create($pdo, $spec('leaders'), 'Not yet', 'Hi', '2026-11-20T21:00', 900, $now)['id'];
$c = dept_msg_create($pdo, $spec('leaders'), 'Stopped', 'Hi', '2026-11-20T18:00', 900, $now)['id'];
$eq('a scheduled message can be cancelled', true, dept_msg_cancel($pdo, $c));
$eq('…once', false, dept_msg_cancel($pdo, $c));
$fakeAll = ['sms' => $fake('sms', []), 'email' => $fake('email', []), 'push' => $fake('push', [])];
$pdo->exec('DELETE FROM system_notifications');
$eq('nothing is due before its time', 0, dept_msg_tick($pdo, '2026-11-20 17:59:00', $fakeAll, 1, $LEAD));
$eq('at its time one goes', 1, dept_msg_tick($pdo, '2026-11-20 18:00:30', $fakeAll, 1, $LEAD));
$eq('…and not again', 0, dept_msg_tick($pdo, '2026-11-20 18:01:00', $fakeAll, 1, $LEAD));
$eq('the cancelled one never goes', 'cancelled', dept_msg_get($pdo, $c)['status']);
$eq('the later one waits', 'scheduled', dept_msg_get($pdo, $b)['status']);
$eq('the sender hears how it went', 1, (int) $pdo->query("SELECT COUNT(*) FROM system_notifications WHERE user_id = 900 AND title = 'Workers message'")->fetchColumn());
$eq('a claimed row cannot be claimed twice', false, dept_msg_claim($pdo, $a));
$eq('a sent message cannot be cancelled', false, dept_msg_cancel($pdo, $a));

$h = dept_msg_history($pdo);
$eq('history lists newest first with its label', ['Stopped', 'cancelled'], [$h[0]['subject'], $h[0]['status']]);
$sent = array_values(array_filter($h, static fn ($x) => $x['id'] === $a))[0];
$eq('a sent message shows its counts', [1, 'Leaders'], [$sent['counts']['push']['queued'], $sent['audience_label']]);

// ===========================================================================
ct_section('6b. The same message to the same group: once a day, no override');
// ===========================================================================
$pdo->exec('DELETE FROM dept_messages');
$t0 = new DateTimeImmutable('2026-11-20 12:00:00');
$first = dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0);
$pdo->exec("UPDATE dept_messages SET status = 'sent', sent_at = '2026-11-20 12:00:05' WHERE id = " . (int) $first['id']);
$again = dept_msg_create($pdo, $spec('all'), ' Workers   meeting ', "Dear {{first_name}}, 10 PM.\n", null, 900, $t0->modify('+4 minutes'));
$eq('a second send minutes later is refused', [false, 'repeat'], [$again['ok'], $again['code'] ?? null]);
$eq('…and says why, with no way round it', 'This went to them today at 12:00 PM. Change the words to send something new.', $again['message']);
$eq('…six hours later too: the rest of the day', false, dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0->modify('+6 hours'))['ok']);
$eq('…and scheduling it for later today', false, dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', '2026-11-20T21:00', 900, $t0)['ok']);
$eq('only one row was ever stored', 1, (int) $pdo->query('SELECT COUNT(*) FROM dept_messages')->fetchColumn());
$eq('tomorrow it is a new day', true, dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', '2026-11-21T09:00', 900, $t0)['ok']);
$eq('another group may get it today', true, dept_msg_create($pdo, $spec('leaders'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0)['ok']);
$eq('other departments may get it today', true, dept_msg_create($pdo, $spec('departments', ['department_ids' => [2]]), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0)['ok']);
$eq('changed words are a new message', true, dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10:30 PM.', null, 900, $t0)['ok']);
$pdo->exec('DELETE FROM dept_messages');
$sched = dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', '2026-11-20T18:00', 900, $t0);
$now2 = dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0->modify('+1 minute'));
$eq('a send-now of a message already scheduled today is refused', 'This is already scheduled for 6:00 PM. Cancel it or change the words.', $now2['message'] ?? null);
dept_msg_cancel($pdo, $sched['id']);
$eq('once cancelled, it may be sent', true, dept_msg_create($pdo, $spec('all'), 'Workers meeting', 'Dear {{first_name}}, 10 PM.', null, 900, $t0->modify('+2 minutes'))['ok']);
// Belt and braces: a scheduled twin that slipped in is stopped when it is due.
$pdo->exec("INSERT INTO dept_messages (subject, body, audience, include_subunits, send_at, status, created_by, created_at)
            VALUES ('Workers meeting', 'Dear {{first_name}}, 10 PM.', 'all', 0, '2026-11-20 18:00:00', 'scheduled', 900, '2026-11-20 12:30:00')");
$slipped = (int) $pdo->lastInsertId();
$pdo->exec("UPDATE dept_messages SET status = 'sent' WHERE status = 'sending'");
$calls = [];
$eq('when due, it does not go out', 0, dept_msg_tick($pdo, '2026-11-20 18:00:30', $fakeAll, 1, $LEAD));
$eq('…nothing was sent through any channel', [], $calls);
$eq('…and the row says why', ['cancelled', 'Not sent: the same message already went to them that day.'],
    [dept_msg_get($pdo, $slipped)['status'], dept_msg_get($pdo, $slipped)['error_message']]);

// ===========================================================================
ct_section('6c. Delivery, person by person');
// ===========================================================================
$pdo->exec('DELETE FROM dept_messages');
$pdo->exec('DELETE FROM sms_queue');
$pdo->exec('DELETE FROM sms_log');
ct_at('2026-11-20 13:00');
$msg = dept_msg_create($pdo, $spec('all'), 'Detail check', "Dear {{first_name}},\nSee you.", null, 900, new DateTimeImmutable('2026-11-20 13:00:00'));
$smsInto = static function (PDO $pdo, array $row, array $recipients): array {
    $pdo->prepare("INSERT INTO sms_campaigns (title, audience, body_template, total, status) VALUES ('t', 'departments', 'b', ?, 'queued')")->execute([count($recipients)]);
    $cid = (int) $pdo->lastInsertId();
    foreach ($recipients as $rec) {
        $pdo->prepare("INSERT INTO sms_queue (campaign_id, recipient_json, status) VALUES (?, ?, 'queued')")->execute([$cid, json_encode($rec)]);
    }
    return ['ok' => true, 'campaign_id' => $cid, 'queued' => count($recipients), 'units' => count($recipients)];
};
$r = dept_msg_expand($pdo, $msg['row'], 1, $LEAD, ['sms' => $smsInto], true);
$ok('sent through all three', $r['ok']);
$eq('the people it went to are kept on the row', [101, 102, 103, 104, 107, 108, 109, 110],
    json_decode((string) dept_msg_get($pdo, $msg['id'])['counts_json'], true)['recipients']);
// The SMS worker's progress: Ada delivered, Bayo awaiting a report, Gbenga failed, Ife still queued, Jide blocked.
$cid = (int) dept_msg_get($pdo, $msg['id'])['sms_campaign_id'];
$set = static function (string $phone, string $qStatus, ?string $logStatus, ?string $err = null) use ($pdo, $cid): void {
    $logId = null;
    if ($logStatus !== null) {
        $pdo->prepare('INSERT INTO sms_log (campaign_id, recipient_phone, body, status) VALUES (?, ?, ?, ?)')->execute([$cid, $phone, 'x', $logStatus]);
        $logId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare("UPDATE sms_queue SET status = ?, log_id = ?, error_message = ? WHERE campaign_id = ? AND recipient_json LIKE ?")
        ->execute([$qStatus, $logId, $err, $cid, '%"' . $phone . '"%']);
};
$set('2348031112222', 'sent', 'delivered');
$set('2348033334444', 'sent', 'sent');
$set('2348070000000', 'failed', 'failed', 'Number not reachable');
$set('2348100000000', 'sent', 'blocked', 'Same text within 24 hours');
$email_worker = email_worker_run($pdo, ['endpoints_ready' => true, 'transport' => ct_transport([['ok' => true, 'error' => null], ['ok' => false, 'error' => '550 5.1.1 User unknown']]), 'now' => campaigns_now()]);
$d = dept_msg_detail($pdo, $msg['id']);
$eq('text summary: one delivered, one awaiting, one failed, one blocked, one waiting', ['total' => 5, 'delivered' => 1, 'awaiting' => 1, 'waiting' => 1, 'failed' => 1, 'blocked' => 1, 'unknown' => 0, 'cancelled' => 0],
    $d['summary']['sms']);
$eq('email summary counts all four', 4, $d['summary']['email']['total']);
$ok('…one sent, one failed (the 550)', $d['summary']['email']['sent'] >= 1 && $d['summary']['email']['failed'] + $d['summary']['email']['not_sent'] >= 1);
$eq('alerts', 8, $d['summary']['push']);
$byName = array_column($d['people'], null, 'name');
$eq('everyone it went to is listed, A to Z', ['Ada Okoro', 'Bayo Lawal', 'Chi Eze', 'Dayo Ola', 'Gbenga Leader', 'Halima Audio', 'Ife Audio', 'Jide Gone'], array_column($d['people'], 'name'));
$eq('Ada: delivered, with her email', ['delivered', 'ada.okoro@gmail.com'], [$byName['Ada Okoro']['sms'], $byName['Ada Okoro']['email']]);
$eq('Gbenga: failed, with the reason', ['failed', 'Number not reachable'], [$byName['Gbenga Leader']['sms'], $byName['Gbenga Leader']['sms_note']]);
$eq('Jide: blocked, and no email (unsubscribed)', ['blocked', null], [$byName['Jide Gone']['sms'], $byName['Jide Gone']['email']]);
$eq('Chi: no text of his own (shares Ada\'s number), no email (@hodlc.com)', [null, null], [$byName['Chi Eze']['sms'], $byName['Chi Eze']['email']]);
$eq('Ife: still queued', 'waiting', $byName['Ife Audio']['sms']);
$eq('an unknown message has no details', null, dept_msg_detail($pdo, 99999));
$eq('the list behind it never leaves in the history view', false, isset(dept_msg_history($pdo)[0]['counts']['recipients']));

// ===========================================================================
ct_section('7. Wiring');
// ===========================================================================
$api = (string) file_get_contents(__DIR__ . '/../api/department_api.php');
$page = (string) file_get_contents(__DIR__ . '/../modules/departments/index.php');
$js = (string) file_get_contents(__DIR__ . '/../assets/ui/js/pages/departments-messages.js');
$ok('the endpoint lets only SMS Studio users send', str_contains($api, "if (!sms_user_can_send()) {\n                dept_fail('Only SMS Studio users"));
$ok('a double tap sends once (compose token)', str_contains($api, "\$_SESSION['dept_msg_tokens']"));
$ok('a repeat the same day is refused, with no override', str_contains($api, "dept_fail(\$created['message'], \$created['code'] ?? 'error');")
    && !str_contains($api, 'confirm_repeat') && !str_contains($js, 'confirm_repeat') && !str_contains($js, 'Send again'));
$ok('after a send the form makes way for the result (no second tap)', str_contains($js, '$form.hidden = true;') && str_contains($js, '$done.hidden = false;')
    && str_contains($page, 'id="dmsg-done"'));
$ok('the result card follows delivery live', str_contains($js, 'function startLive()') && str_contains($page, 'id="dmsg-done-progress"'));
$ok('a row in the list opens its delivery', str_contains($js, "main.setAttribute('data-action', 'dmsg.detail');") && str_contains($page, "ui_dialog_start('dmsg-detail'")
    && str_contains($api, "case 'msg_detail':"));
$ok('a send-now is strict: no texts, nothing goes', str_contains($api, 'dept_msg_expand($pdo, $created[\'row\'], $year_id, $leader_roles, [], true)'));
$cron = (string) file_get_contents(__DIR__ . '/../cron/sms_queue_worker.php');
$ok('the SMS cron sends due messages before the queue, and cannot be stopped by them',
    strpos($cron, 'dept_msg_tick($pdo)') < strpos($cron, "sms_process_queue(\$pdo, 55, 'cron')") && str_contains($cron, "workers\\' messages:"));
$ok('the tab shows only to SMS Studio users', str_contains($page, "\$deptCanMessage = can('sms_studio', 'view');"));
preg_match_all("/call\\('(msg_[a-z_]+)'/", $js, $m);
$missing = array_filter(array_unique($m[1]), static fn ($a) => !str_contains($api, "case '{$a}':"));
$eq('every action the script calls exists in the API', [], array_values($missing));
preg_match_all('/data-action(?:-change|-input)?="(dmsg\.[a-z-]+)"/', $page, $pm);
preg_match_all("/setAttribute\\('data-action(?:-change)?', '(dmsg\\.[a-z-]+)'\\)/", $js, $jm);
preg_match_all("/HOD\\.actions\\.on\\('(dmsg\\.[a-z-]+)'/", $js, $hm);
$noHandler = array_diff(array_unique(array_merge($pm[1], $jm[1])), $hm[1]);
$eq('every action on the tab has a handler', [], array_values($noHandler));

echo "\n{$GLOBALS['ct_passed']} passed, {$GLOBALS['ct_failed']} failed\n";
exit($GLOBALS['ct_failed'] > 0 ? 1 : 0);
