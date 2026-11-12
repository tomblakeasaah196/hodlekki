<?php
// /tests/campaigns/run.php
//
// BEHAVIOURAL tests for email and automation (includes/campaigns/): personal
// addresses, consent, suppression, the hourly budget, the outbox worker, block
// documents and the renderer, unsubscribe/click/open, bounce reports, the
// Embrace journey, the Assimilation/Charis absence ladders on the REAL Charis
// AWOL engine, the one-message-a-day rule, the SMS hand-off and coverage.
//
// No MySQL, no network, no PHPMailer: in-memory SQLite built from the real
// migration, a pinned Lagos clock and a fake mail transport.
//
// Run from the repo root:
//     php tests/campaigns/run.php
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

require_once __DIR__ . '/../../includes/security_helpers.php';   // the one password policy (starts a CLI session)
require_once __DIR__ . '/../../includes/auth_helpers.php';
require_once __DIR__ . '/../../includes/ui/bootstrap.php';
require_once __DIR__ . '/../../includes/ui/components.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/sms_functions.php';
require_once __DIR__ . '/../../includes/campaigns/bootstrap.php';
require_once __DIR__ . '/lib.php';

// ===========================================================================
ct_section('1. Before the migration everything degrades');
// ===========================================================================
$bare = ct_db(false);
ct_eq('campaigns_ready() is false', false, campaigns_ready($bare));
ct_eq('member_confirmed_email() answers null', null, member_confirmed_email($bare, 5));
ct_eq('member_by_confirmed_email() answers null', null, member_by_confirmed_email($bare, 'a@gmail.com'));
ct_eq('campaign_mail_lookup() answers null', null, campaign_mail_lookup($bare, str_repeat('a', 64)));
ct_eq('enqueue says FEATURE_NOT_READY', 'FEATURE_NOT_READY', email_enqueue($bare, ['lane' => 'transactional'])['code'] ?? null);
ct_eq('capture says FEATURE_NOT_READY', 'FEATURE_NOT_READY', email_capture_start($bare, 1, 'a@gmail.com', 'profile')['code'] ?? null);
ct_eq('the worker does nothing', false, email_worker_run($bare)['ran']);
ct_eq('the tick does nothing', false, automation_tick($bare)['ran']);
ct_eq('saving a rule says FEATURE_NOT_READY', 'FEATURE_NOT_READY', automation_rule_save($bare, 'embrace', [], 1)['code'] ?? null);
ct_eq('recording consent is a no-op', 0, email_consent_record($bare, 1, 'withdrawn', 'profile'));
ct_eq('the budget meters nothing (and refuses nothing)', true, email_budget_take($bare, 'campaign', 500));

$pdo = ct_db();
ct_eq('after the migration campaigns_ready() is true', true, campaigns_ready($pdo));

// ===========================================================================
ct_section('2. Personal email: rules');
// ===========================================================================
ct_ok('@hodlekki.org is refused', str_contains((string) email_address_problem('pastor@hodlekki.org'), 'not a church one'));
ct_ok('a hodlekki.org subdomain is refused', email_address_problem('x@mail.hodlekki.org') !== null);
ct_ok('@hodlc.com is refused as a sign-in name', str_contains((string) email_address_problem('grace@hodlc.com'), 'sign-in name'));
ct_ok('a broken address is refused', email_address_problem('grace@gmail') !== null && email_address_problem('not an email') !== null);
ct_eq('a Gmail address is fine (any case, any spaces)', null, email_address_problem('  Ada.Obi@Gmail.com '));
ct_eq('typo hint', 'ada@gmail.com', email_typo_hint('ada@gmial.com'));
ct_eq('no hint for a good domain', null, email_typo_hint('ada@gmail.com'));
ct_eq('a sign-in name is not a personal address', false, email_looks_personal('grace@hodlc.com'));
ct_eq('a Gmail address on file is personal', true, email_looks_personal('Ada@gmail.com'));

// ===========================================================================
ct_section('3. Capture, confirm, replace, take, expire');
// ===========================================================================
ct_at('2026-11-12 09:00');
ct_user($pdo, 1, 'Ada', '1st_Timer', ['email' => null, 'phone' => '08031234567']);
ct_user($pdo, 2, 'Grace', 'Member', ['email' => 'grace@hodlc.com']);
ct_user($pdo, 3, 'Bola', 'Member', ['email' => 'bola.personal@yahoo.com']);

$start = email_capture_start($pdo, 1, 'Ada@Gmail.com', 'connect', null, [
    'consent' => ['granted' => true, 'method' => 'connect_form', 'text' => EMAIL_CONSENT_NEWS_TEXT, 'ip' => '102.89.1.1'],
]);
ct_ok('capture starts', $start['ok'] === true && isset($start['token'], $start['link']));
ct_eq('the address is stored lower case', 'ada@gmail.com', $start['email']);
ct_ok('the link points at /email/confirm.php on the site', str_starts_with($start['link'], 'https://hodlekki.org/email/confirm.php?t='));
ct_eq('the raw token is never stored', 0, ct_count($pdo, 'email_addresses', 'token_hash = ?', [$start['token']]));
ct_eq('only its sha256 is', 1, ct_count($pdo, 'email_addresses', 'token_hash = ?', [hash('sha256', $start['token'])]));
$confirmMail = $pdo->query("SELECT * FROM email_outbox WHERE flow = 'confirm_email'")->fetch();
ct_ok('the confirmation email is queued as account mail, first in line',
    $confirmMail && $confirmMail['lane'] === 'transactional' && $confirmMail['purpose'] === 'account' && (int) $confirmMail['priority'] === 10);
ct_ok('it carries the link and the address', str_contains((string) $confirmMail['html'], email_h($start['link']))
    && str_contains((string) $confirmMail['html'], 'ada@gmail.com'));
ct_ok('account mail has no unsubscribe link', !str_contains((string) $confirmMail['html'], 'Unsubscribe'));
ct_eq('consent from the same form is logged with its wording', 'granted', email_consent_state($pdo, 1));
ct_eq('pending, not yet confirmed', null, member_confirmed_email($pdo, 1));
ct_eq('the link peeks as pending', 'pending', email_capture_peek($pdo, $start['token'])['state'] ?? null);

$c = email_capture_confirm($pdo, $start['token'], '102.89.1.1');
ct_eq('confirming works', true, $c['ok']);
ct_eq('the lookup finds the confirmed address', 'ada@gmail.com', member_confirmed_email($pdo, 1));
ct_eq('and the person from the address (any case)', 1, member_by_confirmed_email($pdo, 'ADA@gmail.com'));
ct_eq('a second click says already', true, email_capture_confirm($pdo, $start['token'])['already'] ?? false);
ct_eq('users.email (empty) gets the confirmed address', 'ada@gmail.com', $pdo->query('SELECT email FROM users WHERE id = 1')->fetchColumn());

ct_at('2026-11-12 09:10');
$g = email_capture_start($pdo, 2, 'grace.o@gmail.com', 'signin_prompt', null, ['send' => false]);
email_capture_confirm($pdo, $g['token']);
ct_eq("a member's sign-in name (@hodlc.com) is NOT replaced", 'grace@hodlc.com', $pdo->query('SELECT email FROM users WHERE id = 2')->fetchColumn());
ct_eq('but their personal email is confirmed', 'grace.o@gmail.com', member_confirmed_email($pdo, 2));

$state3 = email_address_state($pdo, 3);
ct_eq('a personal users.email nobody confirmed is "on file"', 'bola.personal@yahoo.com', $state3['on_file']);
ct_eq('the sign-in name is never "on file"', null, email_address_state($pdo, 2)['on_file']);

ct_at('2026-11-12 09:20');
$taken = email_capture_start($pdo, 3, 'ada@gmail.com', 'profile', null, ['send' => false]);
ct_ok("another person's confirmed address is refused up front", $taken['ok'] === false && $taken['code'] === 'INVALID_EMAIL');

// The race the UNIQUE key settles: two people pending on one address.
$pdo->exec("INSERT INTO email_addresses (user_id, email, status, source, token_hash, token_expires_at, created_at)
            VALUES (3, 'shared@gmail.com', 'pending', 'profile', '" . hash('sha256', 'tok-bola') . "', '2026-12-01 00:00:00', '2026-11-12 09:00:00'),
                   (1, 'shared@gmail.com', 'pending', 'profile', '" . hash('sha256', 'tok-ada') . "', '2026-12-01 00:00:00', '2026-11-12 09:00:00')");
ct_eq('the first to confirm gets it', true, email_capture_confirm($pdo, 'tok-bola')['ok']);
ct_eq('the second is told it is taken', 'TAKEN', email_capture_confirm($pdo, 'tok-ada')['code'] ?? null);
ct_eq('and keeps the address they already had', 'ada@gmail.com', member_confirmed_email($pdo, 1));

ct_at('2026-11-12 09:30');
$new = email_capture_start($pdo, 1, 'ada.new@outlook.com', 'profile', null, ['send' => false]);
ct_eq('a new address starts pending while the old one still counts', 'ada@gmail.com', member_confirmed_email($pdo, 1));
email_capture_confirm($pdo, $new['token']);
ct_eq('confirming the new one replaces the old', 'ada.new@outlook.com', member_confirmed_email($pdo, 1));
ct_eq('the old one is marked replaced', 'replaced', $pdo->query("SELECT status FROM email_addresses WHERE email = 'ada@gmail.com'")->fetchColumn());
ct_eq('and is free for someone else', null, member_by_confirmed_email($pdo, 'ada@gmail.com'));

ct_user($pdo, 4, 'Kemi', '2nd_Timer');
ct_at('2026-11-12 10:00');
$k = email_capture_start($pdo, 4, 'kemi@gmail.com', 'embrace', 77, ['send' => false]);
ct_at('2026-11-20 10:00');
ct_eq('after 7 days the link has expired', 'EXPIRED', email_capture_confirm($pdo, $k['token'])['code'] ?? null);
ct_eq('and stays unconfirmed', null, member_confirmed_email($pdo, 4));

ct_user($pdo, 5, 'Tolu', '1st_Timer');
ct_at('2026-11-13 08:00');
$t1 = email_capture_start($pdo, 5, 'tolu@gmail.com', 'connect');
$t2 = email_capture_start($pdo, 5, 'tolu@gmail.com', 'connect');
ct_eq('asking again within a minute is refused politely', 'TOO_SOON', $t2['code'] ?? null);
foreach (['08:02', '08:04', '08:06', '08:08'] as $m) {
    ct_at('2026-11-13 ' . $m);
    email_capture_start($pdo, 5, 'tolu@gmail.com', 'connect');
}
ct_at('2026-11-13 08:10');
ct_eq('the 6th link in a day is refused', 'RATE_LIMITED', email_capture_start($pdo, 5, 'tolu@gmail.com', 'connect')['code'] ?? null);
ct_eq('resends reuse one pending row', 1, ct_count($pdo, 'email_addresses', "user_id = 5 AND status = 'pending'"));
ct_eq('only the newest link works', 'used', email_capture_peek($pdo, $t1['token']) === null ? 'used' : 'still valid');

// ===========================================================================
ct_section('4. Consent');
// ===========================================================================
$threw = false;
try {
    email_consent_record($pdo, 3, 'granted', 'signin_prompt');
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ct_ok('a "yes" without the wording shown is refused', $threw);
$t = email_consent_text_id($pdo, 'news', EMAIL_CONSENT_NEWS_TEXT);
ct_eq('the same wording is stored once', $t, email_consent_text_id($pdo, 'news', EMAIL_CONSENT_NEWS_TEXT));
ct_ok('changed wording is a new row', email_consent_text_id($pdo, 'news', EMAIL_CONSENT_NEWS_TEXT . ' ') === $t
    && email_consent_text_id($pdo, 'news', 'Different words.') !== $t);
ct_eq('never asked: none', 'none', email_consent_state($pdo, 3));
ct_eq('no confirmed email → no news by email', 'no_confirmed_email', email_may_send_news($pdo, 4)['reason']);
ct_eq('a MEMBER with a confirmed email needs no box (membership)', true, email_may_send_news($pdo, 2)['ok']);
ct_eq('…and the basis is not a consent row', 'none', email_consent_state($pdo, 2));
ct_user($pdo, 6, 'Vera', 'Visitor');
$vv = email_capture_start($pdo, 6, 'vera@gmail.com', 'profile', null, ['send' => false]);
email_capture_confirm($pdo, $vv['token']);
ct_eq('a VISITOR confirmed but never said yes → no_consent', 'no_consent', email_may_send_news($pdo, 6)['reason']);
email_consent_record($pdo, 2, 'granted', 'signin_prompt', ['text' => EMAIL_CONSENT_NEWS_TEXT, 'ip' => '1.2.3.4']);
ct_eq('after yes → ok', true, email_may_send_news($pdo, 2)['ok']);
email_consent_record($pdo, 2, 'withdrawn', 'profile');
ct_eq('the latest answer wins: withdrawn', 'withdrawn', email_may_send_news($pdo, 2)['reason']);
email_consent_record($pdo, 2, 'granted', 'profile', ['text' => EMAIL_CONSENT_NEWS_TEXT]);
ct_eq('consent is per channel (SMS untouched)', 'none', email_consent_state($pdo, 2, 'news', 'sms'));
$row = email_consent_current($pdo, 2);
ct_ok('the log keeps how and the wording', $row['method'] === 'profile' && (int) $row['text_id'] === $t);

// ===========================================================================
ct_section('5. Suppression');
// ===========================================================================
email_suppress($pdo, 'Dead@Example.com', 'hard_bounce', '550 5.1.1 user unknown');
ct_eq('a hard bounce suppresses (any case)', true, email_is_suppressed($pdo, 'dead@example.com'));
ct_eq('a soft bounce does not, yet', false, email_note_soft_bounce($pdo, 'full@example.com', '452 mailbox full'));
email_note_soft_bounce($pdo, 'full@example.com');
ct_eq('the third in a row does', true, email_note_soft_bounce($pdo, 'full@example.com'));
ct_eq('for 7 days', true, email_is_suppressed($pdo, 'full@example.com'));
ct_at('2026-11-21 08:11');
ct_eq('then it lapses', false, email_is_suppressed($pdo, 'full@example.com'));
email_note_delivered($pdo, 'full@example.com');
ct_eq('a delivery resets the count', 0, (int) $pdo->query("SELECT soft_count FROM email_suppressions WHERE email = 'full@example.com'")->fetchColumn());
ct_eq('a soft bounce never weakens a hard one', true, email_note_soft_bounce($pdo, 'dead@example.com') && email_is_suppressed($pdo, 'dead@example.com'));
ct_eq('an admin can release', true, email_suppression_release($pdo, 'dead@example.com', 900, 'They fixed their mailbox'));
ct_eq('released', false, email_is_suppressed($pdo, 'dead@example.com'));

// ===========================================================================
ct_section('6. The hourly budget');
// ===========================================================================
$b = ct_db();
email_setting_set($b, 'hourly_cap', '10');
$h = ct_at('2026-11-16 14:05');
ct_eq('cap 10 → 9 usable, 5 for bulk', ['cap' => 10, 'usable' => 9, 'bulk' => 5], email_budget_limits($b));
$took = 0;
for ($i = 0; $i < 8; $i++) {
    $took += email_budget_take($b, 'campaign', 1, $h) ? 1 : 0;
}
ct_eq('bulk mail stops at its share', 5, $took);
ct_eq('automations share the same bulk share', false, email_budget_take($b, 'automation', 1, $h));
ct_eq('a password reset still goes', true, email_budget_take($b, 'transactional', 1, $h));
ct_eq('so does a mailbox reply', true, email_budget_take($b, 'mailbox', 3, $h));
ct_eq('…but never past the usable total', false, email_budget_take($b, 'alert', 1, $h));
email_budget_give_back($b, 'mailbox', 1, $h);
ct_eq('a slot handed back can be used', true, email_budget_take($b, 'alert', 1, $h));
ct_eq('the next hour starts fresh', true, email_budget_take($b, 'campaign', 5, $h->modify('+1 hour')));
ct_eq('Exim\'s refusal is understood', ['cap' => 60], email_parse_cap_refusal('550 Domain hodlekki.org has exceeded the max emails per hour (60/60 (100%)) allowed. Message discarded.'));
ct_eq('other errors are not', null, email_parse_cap_refusal('550 5.1.1 user unknown'));
ct_eq("the host's bounce limit stops the hour but teaches no cap", ['cap' => null],
    email_parse_cap_refusal('550 Domain hodlekki.org has exceeded the max defers and failures per hour (5/5 (100%)) allowed.'));
$h2 = $h->modify('+2 hours');
email_budget_refused($b, 8, $h2);
ct_eq('after a refusal nothing more goes this hour', false, email_budget_take($b, 'transactional', 1, $h2));
ct_eq('an unbelievable figure (under 10) is not learnt', 10, email_budget_limits($b)['cap']);
$st = email_budget_status($b, $h->modify('+1 hour'));
ct_ok('status shows what is left', $st['sent_bulk'] === 5 && $st['left_bulk'] === 0 && $st['refused'] === false);

// ===========================================================================
ct_section('7. The outbox and its worker');
// ===========================================================================
$o = ct_db();
ct_user($o, 30, 'Uche', 'Member');
ct_user($o, 31, 'Ife', 'Member');
ct_at('2026-11-16 09:00');
ct_confirm($o, 30, 'uche@gmail.com');
ct_confirm($o, 31, 'ife@gmail.com');
$camp = email_send_doc($o, ct_doc(), ['id' => 30, 'first_name' => 'Uche'], [
    'lane' => 'campaign', 'purpose' => 'news', 'flow' => 'campaign', 'to_email' => 'uche@gmail.com', 'track' => true,
]);
$reset = email_enqueue($o, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_reset', 'user_id' => 31,
    'to_email' => 'ife@gmail.com', 'subject' => 'Reset', 'html' => '<p>reset</p>', 'text' => 'reset']);
$tx = ct_transport();
$run = email_worker_run($o, ['endpoints_ready' => true, 'transport' => $tx, 'now' => campaigns_now()]);
ct_eq('both sent', 2, $run['sent']);
ct_eq('the reset left first (priority), the campaign after', ['ife@gmail.com', 'uche@gmail.com'],
    array_map(static fn ($m) => $m['to_email'], $tx['sent']->getArrayCopy()));
$sentCampaign = $tx['sent'][1];
$rowCampaign = $o->query('SELECT * FROM email_outbox WHERE id = ' . (int) $camp['id'])->fetch();
ct_eq('the Message-ID sent is the one recorded at queue time', $rowCampaign['message_id'], $sentCampaign['message_id']);
ct_ok('it ends in the sender\'s domain', str_ends_with($rowCampaign['message_id'], '@hodlekki.org>'));
ct_eq('church news carries one-click unsubscribe', '<https://hodlekki.org/email/unsubscribe.php?r=' . $rowCampaign['ref'] . '>',
    $sentCampaign['headers']['List-Unsubscribe'] ?? null);
ct_eq('…with the RFC 8058 POST header', 'List-Unsubscribe=One-Click', $sentCampaign['headers']['List-Unsubscribe-Post'] ?? null);
ct_eq('bulk mail says so', 'bulk', $sentCampaign['headers']['Precedence'] ?? null);
ct_ok('account mail has no unsubscribe header', !isset($tx['sent'][0]['headers']['List-Unsubscribe']));
ct_eq('every message asks auto-replies to stay quiet', 'auto-generated', $tx['sent'][0]['headers']['Auto-Submitted'] ?? null);
ct_eq('every message carries its ref', $rowCampaign['ref'], $sentCampaign['headers']['X-HOD-Ref'] ?? null);
$look = campaign_mail_lookup($o, email_message_id_hash(strtoupper($rowCampaign['message_id'])));
ct_ok('the mailbox lookup finds person and flow from the reply\'s Message-ID (any case)',
    $look !== null && $look['user_id'] === 30 && $look['flow'] === 'campaign');
ct_eq('a delivered row is sent', 'sent', $rowCampaign['status']);

// Failures
ct_at('2026-11-16 09:10');
$tmp = email_enqueue($o, ['lane' => 'automation', 'purpose' => 'news', 'flow' => 'embrace.welcome', 'user_id' => 30,
    'to_email' => 'uche@gmail.com', 'subject' => 'Hi', 'html' => '<p>hi</p>']);
$down = email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport([['ok' => false, 'error' => 'SMTP Error: Could not connect to SMTP host. Failed to connect to server']]), 'now' => campaigns_now()]);
$r = $o->query('SELECT * FROM email_outbox WHERE id = ' . $tmp['id'])->fetch();
ct_ok('our mail server being down stops the run and costs the message no attempt',
    $down['stopped'] === 'unavailable' && $r['status'] === 'queued' && (int) $r['attempts'] === 0 && $r['not_before'] === null);
email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport([['ok' => false, 'error' => '451 4.3.0 Temporary local problem']]), 'now' => campaigns_now()]);
$r = $o->query('SELECT * FROM email_outbox WHERE id = ' . $tmp['id'])->fetch();
ct_ok('a temporary failure retries in a minute', $r['status'] === 'queued' && (int) $r['attempts'] === 1 && $r['not_before'] === '2026-11-16 09:11:00');
ct_eq('not before then', 0, email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()])['sent']);
ct_at('2026-11-16 09:12');
ct_eq('then it goes', 1, email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()])['sent']);

ct_user($o, 32, 'Femi', 'Member');
ct_confirm($o, 32, 'femi@gmail.com');
$perm = email_enqueue($o, ['lane' => 'automation', 'purpose' => 'news', 'flow' => 'charis.miss_note', 'user_id' => 32,
    'to_email' => 'femi@gmail.com', 'subject' => 'Hi', 'html' => '<p>hi</p>']);
email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport([['ok' => false, 'error' => 'SMTP Error: The following recipients failed: femi@gmail.com: 550 5.1.1 The email account that you tried to reach does not exist']]), 'now' => campaigns_now()]);
ct_eq('a permanent recipient failure fails the message', 'failed', $o->query('SELECT status FROM email_outbox WHERE id = ' . $perm['id'])->fetchColumn());
ct_eq('and suppresses the address', true, email_is_suppressed($o, 'femi@gmail.com'));

ct_at('2026-11-16 09:20');
$capped = email_enqueue($o, ['lane' => 'campaign', 'purpose' => 'news', 'flow' => 'campaign', 'user_id' => 31,
    'to_email' => 'ife@gmail.com', 'subject' => 'Hi', 'html' => '<p>hi</p>']);
$capRun = email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport([['ok' => false, 'error' => 'SMTP Error: data not accepted. SMTP server error: DATA END command failed Detail: Domain hodlekki.org has exceeded the max emails per hour (40/40 (100%)) allowed. SMTP code: 550']]), 'now' => campaigns_now()]);
$cr = $o->query('SELECT * FROM email_outbox WHERE id = ' . $capped['id'])->fetch();
ct_ok('the host\'s cap puts the email back untouched', $cr['status'] === 'queued' && (int) $cr['attempts'] === 0);
ct_eq('stops the run', 'cap', $capRun['stopped']);
ct_eq('and learns the cap', 40, email_budget_limits($o)['cap']);
ct_eq('the address is NOT blamed', false, email_is_suppressed($o, 'ife@gmail.com'));
ct_at('2026-11-24 09:21');
ct_eq('a learnt cap lapses after 7 days', 100, email_budget_limits($o)['cap']);
ct_at('2026-11-16 09:21');
ct_eq("the bounce limit is not the recipient's fault either", 'cap', email_classify_failure('550 Domain hodlekki.org has exceeded the max defers and failures per hour (5/5 (100%)) allowed.'));
ct_eq('a full mailbox at SMTP time is temporary, not a hard bounce', 'temporary', email_classify_failure('552 5.2.2 Mailbox full'));

// Orphans, last-moment checks, the window, the limit, the kill switch
$o->exec("UPDATE email_outbox SET status = 'sending', locked_at = '2026-11-16 09:00:00' WHERE id = " . (int) $capped['id']);
ct_at('2026-11-16 10:30');
$orph = email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()]);
ct_eq('a send interrupted mid-way becomes unknown', 1, $orph['recovered']);
ct_eq('and is never sent again', 'unknown', $o->query('SELECT status FROM email_outbox WHERE id = ' . $capped['id'])->fetchColumn());

$late = email_enqueue($o, ['lane' => 'campaign', 'purpose' => 'news', 'flow' => 'campaign', 'user_id' => 30,
    'to_email' => 'uche@gmail.com', 'subject' => 'Hi', 'html' => '<p>hi</p>']);
email_consent_record($o, 30, 'withdrawn', 'one_click');
$lateTx = ct_transport();
email_worker_run($o, ['endpoints_ready' => true, 'transport' => $lateTx, 'now' => campaigns_now()]);
ct_eq('unsubscribed after queueing → not sent', 'suppressed', $o->query('SELECT status FROM email_outbox WHERE id = ' . $late['id'])->fetchColumn());
ct_eq('nothing reached the transport', 0, count($lateTx['sent']));

ct_at('2026-11-16 21:30');
$night = email_enqueue($o, ['lane' => 'automation', 'purpose' => 'news', 'flow' => 'x', 'user_id' => 31,
    'to_email' => 'ife@gmail.com', 'subject' => 'Hi', 'html' => '<p>hi</p>']);
$nightReset = email_enqueue($o, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'password_reset', 'user_id' => 31,
    'to_email' => 'ife@gmail.com', 'subject' => 'Reset', 'html' => '<p>reset</p>']);
$nightRun = email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()]);
ct_ok('at 21:30 a reset still goes but church news waits for 07:00', $nightRun['sent'] === 1 && $nightRun['outside_window'] === 1);
ct_at('2026-11-17 07:00');
$held = email_worker_run($o, ['endpoints_ready' => false, 'transport' => ct_transport(), 'now' => campaigns_now()]);
ct_ok('until the public unsubscribe/click pages are deployed, church news is held', $held['sent'] === 0 && $held['outside_window'] === 1
    && str_contains($held['message'], 'not deployed'));
ct_eq('…and this checkout has them (unsubscribe and click pages exist)', true, email_public_endpoints_ready());
$htEmail = (string) file_get_contents(dirname(__DIR__, 2) . '/email/.htaccess');
ct_ok('email/.htaccess serves exactly the four email pages', str_contains($htEmail, '<FilesMatch "^(?!(confirm|unsubscribe|c|o)\.php$).*">'));
ct_ok('the RFC 8058 one-click POST is exempt from the CSRF check', in_array('/email/unsubscribe.php', CSRF_EXEMPT_PREFIXES, true));
ct_eq('in the morning it goes', 1, email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()])['sent']);

for ($i = 0; $i < 25; $i++) {
    email_enqueue($o, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'bulk_test', 'to_email' => "p{$i}@gmail.com",
        'subject' => 'x', 'html' => '<p>x</p>']);
}
ct_eq('one run sends at most per_minute (20)', 20, email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()])['sent']);
email_setting_set($o, 'paused', '1');
ct_eq('the kill switch stops everything', false, email_worker_run($o, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()])['ran']);
email_setting_set($o, 'paused', '0');

$threw = false;
try {
    email_enqueue($o, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'x', 'to_email' => 'a@gmail.com',
        'subject' => 'x', 'html' => 'x', 'headers' => ["X-Evil\r\nBcc" => 'all@x.com']]);
} catch (InvalidArgumentException $e) {
    $threw = true;
}
ct_ok('header injection is refused at the door', $threw);
ct_eq('church news never goes to @hodlekki.org', 'INVALID_EMAIL', email_enqueue($o, ['lane' => 'campaign', 'purpose' => 'news', 'flow' => 'x',
    'to_email' => 'media@hodlekki.org', 'subject' => 'x', 'html' => 'x'])['code'] ?? null);
ct_eq('hod_mail_header_ok refuses From', false, hod_mail_header_ok('From', 'x@y.com'));
ct_eq('hod_mail_header_ok accepts List-Unsubscribe', true, hod_mail_header_ok('List-Unsubscribe', '<https://hodlekki.org/x>'));

// ===========================================================================
ct_section('8. Block documents (the sanitiser) and the renderer');
// ===========================================================================
$bad = static fn (array $blocks): array => email_doc_validate(['v' => 1, 'subject' => 'S', 'blocks' => $blocks]);
ct_eq('an unknown block is refused', false, $bad([['type' => 'html', 'html' => '<script>x</script>']])['ok']);
ct_eq('a javascript: link is refused', false, $bad([['type' => 'button', 'label' => 'Go', 'href' => 'javascript:alert(1)']])['ok']);
ct_eq('a plain http: link is refused', false, $bad([['type' => 'button', 'label' => 'Go', 'href' => 'http://example.com']])['ok']);
ct_eq('a data: link in text is refused', false, $bad([['type' => 'text', 'content' => [['k' => 'p', 'r' => [['t' => 'x', 'href' => 'data:text/html,hi']]]]]])['ok']);
ct_eq('a hot-linked picture is refused', false, $bad([['type' => 'image', 'src' => 'https://evil.example/pixel.gif']])['ok']);
ct_eq('a picture from our uploads is fine', true, $bad([['type' => 'image', 'src' => 'https://hodlekki.org/uploads/email/2026/11/a.jpg', 'alt' => 'Choir']])['ok']);
ct_eq('a path trick is refused', false, $bad([['type' => 'image', 'src' => 'https://hodlekki.org/uploads/email/../../.env.png']])['ok']);
ct_eq('an unknown merge field is refused', false, $bad([['type' => 'heading', 'text' => 'Hi {{password}}']])['ok']);
ct_eq('a paragraph node that is not p/ul/ol is refused', false, $bad([['type' => 'text', 'content' => [['k' => 'script', 'r' => [['t' => 'x']]]]]])['ok']);
ct_eq('a footer in the middle is refused', false, $bad([['type' => 'footer'], ['type' => 'heading', 'text' => 'After']])['ok']);
$v = $bad([['type' => 'heading', 'text' => 'Only a heading']]);
ct_ok('a missing footer is added at the end', $v['ok'] && end($v['doc']['blocks'])['type'] === 'footer');
ct_eq('too many blocks are refused', false, $bad(array_fill(0, 61, ['type' => 'divider']))['ok']);
ct_eq('an unknown scripture is refused', false, $bad([['type' => 'scripture', 'ref' => 'Hezekiah 4:1']])['ok']);
ct_eq('six verses are fine', true, $bad([['type' => 'scripture', 'ref' => 'Psalm 23:1-6']])['ok']);
ct_eq('more than six are refused', false, $bad([['type' => 'scripture', 'ref' => 'Psalm 119:1-7']])['ok']);
ct_eq('a passage across chapters is refused', false, $bad([['type' => 'scripture', 'ref' => 'John 3:36-4:2']])['ok']);
ct_eq('a known scripture is fine', true, $bad([['type' => 'scripture', 'ref' => 'Psalm 122:1']])['ok']);
ct_eq('unknown fields are dropped from a block', ['type' => 'divider'], $bad([['type' => 'divider', 'onclick' => 'x']])['doc']['blocks'][0]);

$doc = ['v' => 1, 'subject' => 'Welcome, {{first_name}}', 'preheader' => 'For {{first_name}}', 'blocks' => [
    ['type' => 'heading', 'text' => 'Hello {{first_name}}', 'level' => 1],
    ['type' => 'text', 'content' => [
        ['k' => 'p', 'r' => [['t' => 'Bold ', 'b' => 1], ['t' => 'our site', 'href' => 'https://hodlekki.org/sermons.php']]],
        ['k' => 'ul', 'items' => [[['t' => 'One']], [['t' => 'Two', 'i' => 1]]]],
    ]],
    ['type' => 'button', 'label' => 'Visit', 'href' => 'https://hodlekki.org/', 'style' => 'primary', 'align' => 'center'],
    ['type' => 'scripture', 'ref' => 'Psalm 122:1'],
    ['type' => 'footer'],
]];
$rendered = email_render($doc, ['first_name' => '<b>Ada</b>'], ['purpose' => 'news', 'ref' => str_repeat('A', 22), 'track' => true]);
ct_eq('renders', true, $rendered['ok']);
ct_ok('a name is escaped, never markup', str_contains($rendered['html'], '&lt;b&gt;Ada&lt;/b&gt;') && !str_contains($rendered['html'], '<b>Ada</b>'));
ct_eq('the subject is merged (plain text)', 'Welcome, <b>Ada</b>', $rendered['subject']);
ct_eq('both links are tracked, in order', ['https://hodlekki.org/sermons.php', 'https://hodlekki.org/'], $rendered['links']);
ct_ok('…through the click URL', str_contains($rendered['html'], 'https://hodlekki.org/email/c.php?r=' . str_repeat('A', 22) . '&amp;l=1'));
ct_ok('the unsubscribe link is never tracked', str_contains($rendered['html'], 'https://hodlekki.org/email/unsubscribe.php?r=' . str_repeat('A', 22)));
ct_ok('the plain-text twin lists real targets', str_contains($rendered['text'], 'Visit: https://hodlekki.org/')
    && str_contains($rendered['text'], 'our site (https://hodlekki.org/sermons.php)') && str_contains($rendered['text'], '- One'));
ct_ok('the layout is a 600px table with MSO ghost tables', str_contains($rendered['html'], 'width="600"') && str_contains($rendered['html'], '<!--[if mso]>'));
ct_ok('dark mode is declared', str_contains($rendered['html'], 'color-scheme" content="light dark'));
ct_ok('no script anywhere', stripos($rendered['html'], '<script') === false);
$kjv = bible_kjv_chapter(19, 122)[0]['t'];
ct_ok('the scripture is the Bible module\'s KJV, word for word', str_contains($rendered['html'], email_h($kjv)) && str_contains($rendered['html'], 'Psalm 122:1 (KJV)'));
$multi = email_scripture('Numbers 6:24-26');
ct_ok('a short passage joins its verses', $multi !== null && str_starts_with($multi['text'], 'The LORD bless thee'));
$plainRender = email_render($doc, ['first_name' => 'Ada'], ['purpose' => 'account']);
ct_ok('without a ref nothing is tracked', $plainRender['links'] === [] && !str_contains($plainRender['html'], '/email/c.php'));
ct_ok('account mail explains itself instead of offering unsubscribe', !str_contains($plainRender['html'], 'Unsubscribe')
    && str_contains($plainRender['html'], 'about your church account'));
$opens = email_render($doc, ['first_name' => 'Ada'], ['purpose' => 'news', 'ref' => str_repeat('B', 22), 'track_opens' => true]);
ct_ok('the open pixel only when asked for', str_contains($opens['html'], '/email/o.php?r=' . str_repeat('B', 22)) && !str_contains($rendered['html'], '/email/o.php'));
$noName = email_render(ct_doc(), [], ['purpose' => 'news']);
ct_eq('no first name → "friend"', 'Hello friend', $noName['subject']);
$evDoc = ct_doc([['type' => 'event', 'event_id' => 5], ['type' => 'footer']]);
$evHtml = email_render($evDoc, [], ['purpose' => 'news', 'event_resolver' => static fn (int $id) => $id === 5
    ? ['title' => 'Chara Night', 'tagline' => '', 'when' => 'Saturday 28 November · 6:00 PM', 'venue' => 'Main hall', 'url' => 'https://hodlekki.org/e/chara']
    : null]);
ct_ok('an event card renders from Special Events', str_contains($evHtml['html'], 'Chara Night') && str_contains($evHtml['html'], 'https://hodlekki.org/e/chara'));
$evGone = email_render($evDoc, [], ['purpose' => 'news', 'event_resolver' => static fn (int $id) => null]);
ct_ok('a cancelled event is left out, not shown wrong', $evGone['ok'] && !str_contains($evGone['html'], 'EVENT'));

// ===========================================================================
ct_section('9. Unsubscribe, clicks, opens');
// ===========================================================================
$u = ct_db();
ct_user($u, 40, 'Seyi', 'Member');
ct_at('2026-11-16 09:00');
ct_confirm($u, 40, 'seyi@gmail.com');
$q = email_send_doc($u, $doc, ['id' => 40, 'first_name' => 'Seyi'], ['lane' => 'campaign', 'purpose' => 'news', 'flow' => 'campaign',
    'to_email' => 'seyi@gmail.com', 'track' => true]);
email_worker_run($u, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()]);
ct_eq('a click is sent where the email said', 'https://hodlekki.org/', email_track_click($u, $q['ref'], 1));
ct_eq('an index the email never had goes nowhere', null, email_track_click($u, $q['ref'], 7));
ct_eq('a made-up ref goes nowhere', null, email_track_click($u, str_repeat('Z', 22), 0));
ct_ok('the click is recorded', ct_count($u, 'email_events', "kind = 'click'") === 1
    && $u->query('SELECT clicked_at FROM email_outbox WHERE id = ' . $q['id'])->fetchColumn() !== null);
email_track_open($u, $q['ref']);
email_track_open($u, $q['ref']);
ct_eq('an open is counted once', 1, ct_count($u, 'email_events', "kind = 'open'"));
ct_eq('one-click unsubscribe works', true, email_unsubscribe($u, $q['ref'], 'one_click', '9.9.9.9')['ok']);
ct_eq('…and withdraws church news by email', 'withdrawn', email_consent_state($u, 40));
ct_eq('…remembering which email it came from', (int) $q['id'], (int) email_consent_current($u, 40)['outbox_id']);
ct_eq('a second click says already', true, email_unsubscribe($u, $q['ref'])['already'] ?? false);
ct_eq('undo resubscribes', 'granted', (email_resubscribe($u, $q['ref'])['ok'] ? email_consent_state($u, 40) : 'no'));
ct_eq('an unknown ref is refused', 'UNKNOWN', email_unsubscribe($u, 'nope')['code'] ?? null);
ct_eq('the pixel is a GIF', 'GIF89a', substr(email_pixel_gif(), 0, 6));

// ===========================================================================
ct_section('10. Bounce and complaint reports');
// ===========================================================================
$ref = $q['ref'];
$dsnHard = "From: Mail Delivery System <MAILER-DAEMON@server.example>\r\nTo: system@hodlekki.org\r\nSubject: Mail delivery failed\r\n"
    . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"BOUND\"\r\n\r\n"
    . "--BOUND\r\nContent-Type: text/plain\r\n\r\nYour message could not be delivered.\r\n"
    . "--BOUND\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; server.example\r\n\r\n"
    . "Final-Recipient: rfc822; seyi@gmail.com\r\nAction: failed\r\nStatus: 5.1.1\r\nDiagnostic-Code: smtp; 550 5.1.1 user unknown\r\n"
    . "--BOUND\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: system@hodlekki.org\r\nTo: seyi@gmail.com\r\nX-HOD-Ref: {$ref}\r\nMessage-ID: <abc@hodlekki.org>\r\n"
    . "--BOUND--\r\n";
$p = email_dsn_parse($dsnHard);
ct_ok('an RFC 3464 report is read', $p !== null && $p['kind'] === 'bounce' && $p['recipients'][0]['email'] === 'seyi@gmail.com');
ct_eq('5.1.1 is hard', true, $p['recipients'][0]['hard']);
ct_eq('our ref is found in the returned headers', $ref, $p['ref']);
$applied = email_record_bounce($u, $p);
ct_ok('applying it suppresses the address and marks the email', $applied['suppressed'] === 1 && email_is_suppressed($u, 'seyi@gmail.com')
    && $u->query('SELECT bounced_at FROM email_outbox WHERE id = ' . $q['id'])->fetchColumn() !== null);

$full = str_replace(['Status: 5.1.1', '550 5.1.1 user unknown'], ['Status: 5.2.2', '552 5.2.2 mailbox full'], $dsnHard);
ct_eq('a full mailbox (5.2.2) is soft', false, email_dsn_parse($full)['recipients'][0]['hard']);
$delay = str_replace(['Action: failed', 'Status: 5.1.1'], ['Action: delayed', 'Status: 4.4.1'], $dsnHard);
ct_eq('a delay is only a delay', 'delay', email_dsn_parse($delay)['kind']);
$exim = "From: Mail Delivery System <Mailer-Daemon@server.hodlekki.org>\nTo: system@hodlekki.org\nX-Failed-Recipients: gone@yahoo.com\n"
    . "Subject: Mail delivery failed: returning message to sender\n\nThis message was created automatically by mail delivery software.\n\n"
    . "A message that you sent could not be delivered to one or more of its\nrecipients. This is a permanent error. The following address(es) failed:\n\n"
    . "  gone@yahoo.com\n    host mta.yahoo.com said: 554 delivery error: dd This user doesn't have a yahoo.com account\n\n"
    . "------ This is a copy of the message, including all the headers. ------\n\nX-HOD-Ref: {$ref}\nSubject: Hi\n";
$pe = email_dsn_parse($exim);
ct_ok("Exim's own bounce is read", $pe !== null && $pe['recipients'][0]['email'] === 'gone@yahoo.com' && $pe['recipients'][0]['hard'] === true && $pe['ref'] === $ref);
$arf = "From: feedback@mail.example\nTo: system@hodlekki.org\nContent-Type: multipart/report; report-type=feedback-report; boundary=\"F\"\n\n"
    . "--F\nContent-Type: text/plain\n\nThis is an abuse report\n--F\nContent-Type: message/feedback-report\n\nFeedback-Type: abuse\nUser-Agent: x\nVersion: 1\n\n"
    . "--F\nContent-Type: message/rfc822\n\nFrom: system@hodlekki.org\nTo: Seyi <seyi@gmail.com>\nX-HOD-Ref: {$ref}\n\nbody\n--F--\n";
$pa = email_dsn_parse($arf);
ct_ok('a spam complaint (ARF) is read', $pa !== null && $pa['kind'] === 'complaint' && $pa['recipients'][0]['email'] === 'seyi@gmail.com');
email_consent_record($u, 40, 'granted', 'profile', ['text' => EMAIL_CONSENT_NEWS_TEXT]);
email_record_bounce($u, $pa);
ct_eq('a complaint withdraws church news', 'withdrawn', email_consent_state($u, 40));
$capB = str_replace('550 5.1.1 user unknown', '550 Domain hodlekki.org has exceeded the max emails per hour (50/50 (100%)) allowed. Message discarded.', $dsnHard);
$u->exec("UPDATE email_outbox SET status = 'sent' WHERE id = " . $q['id']);
$pc = email_dsn_parse($capB);
ct_eq("the host's cap in a bounce is not the person's fault", 'cap', $pc['kind']);
$appliedCap = email_record_bounce($u, $pc);
ct_ok('…the email goes back in the queue for next hour', $appliedCap['requeued']
    && $u->query('SELECT status FROM email_outbox WHERE id = ' . $q['id'])->fetchColumn() === 'queued');
ct_eq('an ordinary email is not a report', null, email_dsn_parse("From: a@b.com\nSubject: Hello\n\nJust saying hi"));
$forged = str_replace($ref, str_repeat('F', 22), $arf);
email_consent_record($u, 40, 'granted', 'profile', ['text' => EMAIL_CONSENT_NEWS_TEXT]);
email_suppression_release($u, 'seyi@gmail.com', 900);
ct_eq('a forged complaint naming no email we sent is ignored', 'unmatched', email_record_bounce($u, email_dsn_parse($forged))['kind']);
ct_ok('…nothing suppressed, consent untouched', !email_is_suppressed($u, 'seyi@gmail.com') && email_consent_state($u, 40) === 'granted');
$forgedCap = "From: x@evil.example\nX-Failed-Recipients: a@b.com\n\nexceeded the max emails per hour (1/1 (100%))\nX-HOD-Ref: " . str_repeat('G', 22) . "\n";
email_record_bounce($u, email_dsn_parse($forgedCap));
ct_eq('a forged "cap" report teaches nothing', '', email_setting($u, 'learned_cap'));

// ===========================================================================
ct_section('11. Default rules');
// ===========================================================================
$d = ct_db();
ct_eq('three default rules are created', 3, automation_ensure_defaults($d));
ct_eq('…once', 0, automation_ensure_defaults($d));
ct_eq('…all switched off', 0, ct_count($d, 'automation_rules', 'is_enabled = 1'));
foreach (AUTOMATION_KINDS as $module => $kind) {
    $check = automation_config_validate($kind, automation_default_config($module));
    ct_ok("the default {$module} rule is valid", $check['ok'], implode('; ', $check['errors']));
}
$journey = automation_rule_get($d, 'embrace');
ct_eq('the journey has 6 weekly welcomes', 6, count($journey['config']['steps'][0]['series']));
ct_eq('Monday 10:00', ['weekday' => 1, 'time' => '10:00'], $journey['config']['steps'][0]['when']);
ct_eq('Wednesday and Saturday reminders', [3, 6], [$journey['config']['steps'][1]['when']['weekday'], $journey['config']['steps'][2]['when']['weekday']]);
$abs = automation_rule_get($d, 'charis');
ct_eq('absence: 2 / 4 / 8', [2, 4, 8], array_column($abs['config']['steps'], 'threshold'));
ct_eq('absence: person, team, pastor', ['member', 'team', 'pastor'], array_column($abs['config']['steps'], 'target'));
ct_eq('Charis covers members', ['Member', 'Worker', 'Pastor'], $abs['config']['audience']['statuses']);
ct_eq('Assimilation covers visitors', ['Visitor', 'Non_Member'], automation_rule_get($d, 'assimilation')['config']['audience']['statuses']);
foreach (automation_default_journey()['steps'][0]['series'] as $i => $week) {
    ct_ok('week ' . ($i + 1) . ' SMS fits one text', mb_strlen($week['sms']) <= 160 && sms_segments(sms_render($week['sms'], ['first_name' => 'Oluwaseun']))['pages'] <= 1);
}
$badCfg = automation_config_validate('absence', ['audience' => ['statuses' => ['Wizard']], 'when' => ['weekday' => 9, 'time' => '25:00'], 'steps' => []]);
ct_ok('a broken rule is refused with reasons', !$badCfg['ok'] && count($badCfg['errors']) >= 3);
$v1 = (int) $journey['version'];
ct_eq('saving bumps the version', true, automation_rule_save($d, 'embrace', $journey['config'], 900, $v1)['ok']);
ct_eq("saving over someone else's edit is refused", false, automation_rule_save($d, 'embrace', $journey['config'], 900, $v1)['ok']);

// ===========================================================================
ct_section('12. Embrace first-timer journey');
// ===========================================================================
$j = ct_db();
ct_at('2026-11-15 08:00');
$sun15 = ct_service($j, '2026-11-15');
ct_user($j, 10, 'Ada', '1st_Timer', ['created_at' => '2026-11-15 10:00:00', 'phone' => '08031111111']);
ct_present($j, 10, '2026-11-15', $sun15);
ct_confirm($j, 10, 'ada@gmail.com');
ct_user($j, 11, 'Bayo', '1st_Timer', ['created_at' => '2026-11-15 10:00:00', 'phone' => '08031234567']);
ct_user($j, 12, 'Chika', '1st_Timer', ['created_at' => '2026-11-15 10:00:00']);
ct_user($j, 13, 'Dupe', '3rd_Timer', ['created_at' => '2026-11-01 10:00:00', 'phone' => '08032222222']);
ct_user($j, 14, 'Emeka', '1st_Timer', ['created_at' => '2026-11-17 18:00:00', 'phone' => '08033333333']);
ct_user($j, 15, 'Funke', '1st_Timer', ['created_at' => '2026-09-01 10:00:00', 'phone' => '08034444444']);
ct_user($j, 16, 'Gbenga', '1st_Timer', ['created_at' => '2026-11-15 10:00:00', 'phone' => '08035555555', 'attendance_status' => 'Relocated']);
ct_user($j, 18, 'Ijeoma', '1st_Timer', ['created_at' => '2026-11-15 10:00:00', 'phone' => '08037777770']);
email_consent_record($j, 18, 'withdrawn', 'staff');   // said no to church news, has no confirmed email
ct_user($j, 17, 'Halima', '2nd_Timer', ['created_at' => '2026-11-08 10:00:00', 'phone' => '08036666666']);
ct_confirm($j, 17, 'halima@gmail.com');
email_consent_record($j, 17, 'withdrawn', 'one_click');
automation_ensure_defaults($j);
$rule = automation_rule_get($j, 'embrace');
ct_at('2026-11-16 08:00');
automation_rule_set_enabled($j, $rule['id'], true, 900, 'include');

ct_at('2026-11-16 09:59');
ct_eq('nothing before 10:00', [], automation_tick($j, ['now' => campaigns_now()])['runs']);
ct_at('2026-11-16 10:05');
$t = automation_tick($j, ['now' => campaigns_now()]);
ct_eq('Monday 10:05: the welcome runs once', 1, count($t['runs']));
$dec = [];
foreach ($t['runs'][0]['decisions'] as $x) {
    $dec[$x['user_id']] = $x['channel'] . ':' . $x['outcome'];
}
ct_eq('Ada (confirmed + yes) gets an email', 'email:queued', $dec[10] ?? null);
ct_eq('Bayo (no email, a mobile) gets an SMS', 'sms:queued', $dec[11] ?? null);
ct_eq('Chika (neither) is skipped', 'none:skipped_no_contact', $dec[12] ?? null);
ct_eq('Halima (unsubscribed) is skipped, not texted', 'none:skipped_consent', $dec[17] ?? null);
ct_eq('Ijeoma (said no, no email on file) is skipped, not texted', 'none:skipped_consent', $dec[18] ?? null);
ct_ok('3rd Timers, people who joined later, finished journeys and departed people are left out',
    !isset($dec[13]) && !isset($dec[14]) && !isset($dec[15]) && !isset($dec[16]));
$adaMail = $j->query("SELECT * FROM email_outbox WHERE user_id = 10 AND lane = 'automation'")->fetch();
ct_ok('Ada\'s email is week 1, from the Embrace team, tracked', $adaMail['subject'] === 'Welcome to the family, Ada'
    && $adaMail['from_name'] === 'HOD Lekki Embrace Team' && $adaMail['flow'] === 'embrace.welcome' && $adaMail['links_json'] !== null);
ct_ok('the scripture in it is the KJV', str_contains((string) $adaMail['html'], email_h(bible_kjv_chapter(19, 122)[0]['t'])));
$sms = $j->query("SELECT * FROM sms_campaigns")->fetchAll();
ct_ok('the SMS goes to SMS Studio as one campaign', count($sms) === 1 && $sms[0]['audience'] === 'automation' && str_contains($sms[0]['body_template'], '{{first_name}}'));
$queued = json_decode((string) $j->query('SELECT recipient_json FROM sms_queue')->fetchColumn(), true);
ct_eq('…with the number in SMS Studio\'s form', '2348031234567', $queued['phone'] ?? null);
ct_eq('…and sms_log is never written here', 0, ct_count($j, 'sms_log'));
ct_eq('the run is recorded', ['emailed' => 1, 'texted' => 1], array_intersect_key($j->query('SELECT emailed, texted FROM automation_runs')->fetch(), ['emailed' => 1, 'texted' => 1]) === ['emailed' => 1, 'texted' => 1] ? ['emailed' => 1, 'texted' => 1] : 'mismatch');
ct_eq('a second tick the same morning does nothing', [], automation_tick($j, ['now' => campaigns_now()->modify('+5 minutes')])['runs']);
ct_eq('even forcing the occasion again cannot double-send', null, automation_run_occasion($j, automation_rule_get($j, 'embrace'),
    automation_due_occasions(automation_rule_get($j, 'embrace'), campaigns_now())[0]));

ct_at('2026-11-18 12:05');
$wed = automation_tick($j, ['now' => campaigns_now()]);
ct_eq('Wednesday 12:05: the midweek reminder', 'midweek', $wed['runs'][0]['decisions'][0]['step'] ?? null);
ct_eq('Ada, Bayo and Emeka (who joined on Tuesday) get it', 3, $wed['runs'][0]['counts']['emailed'] + $wed['runs'][0]['counts']['texted']);
ct_ok('a date-bound reminder must leave the same day', $j->query("SELECT send_by FROM email_outbox WHERE flow = 'embrace.midweek'")->fetchColumn() === '2026-11-18 23:59:59');

ct_at('2026-11-23 10:05');
$mon2 = automation_tick($j, ['now' => campaigns_now()]);
$dec2 = [];
foreach ($mon2['runs'][0]['decisions'] as $x) {
    $dec2[$x['user_id']] = $x['outcome'];
}
ct_eq("Ada's second Monday brings the second email", 'Getting to know us, Ada',
    $j->query("SELECT subject FROM email_outbox WHERE user_id = 10 AND flow = 'embrace.welcome' ORDER BY id DESC LIMIT 1")->fetchColumn());
ct_eq('Emeka, who joined on a Tuesday, starts at the beginning', 'queued', $dec2[14] ?? null);
$emekaText = $j->query("SELECT body_template FROM sms_campaigns ORDER BY id DESC")->fetchAll(PDO::FETCH_COLUMN);
ct_ok("…with week 1's text", in_array(automation_default_journey()['steps'][0]['series'][0]['sms'], $emekaText, true));

$j->exec("UPDATE users SET spiritual_status = '3rd_Timer' WHERE id = 10");
ct_at('2026-11-30 10:05');
$mon3 = automation_tick($j, ['now' => campaigns_now()]);
ct_ok('a 3rd Timer leaves the journey', !in_array(10, array_column($mon3['runs'][0]['decisions'], 'user_id'), true));
ct_at('2026-12-28 10:05');
$late = automation_tick($j, ['now' => campaigns_now()]);
ct_ok('after 6 weeks the journey ends', !in_array(11, array_column($late['runs'][0]['decisions'] ?? [], 'user_id'), true));

// ===========================================================================
ct_section('13. Absence ladders on the real Charis AWOL engine');
// ===========================================================================
$a = ct_db();
ct_at('2026-09-01 08:00');
$sundays = [];
for ($day = campaigns_dt('2026-09-06'); $day <= campaigns_dt('2027-01-31'); $day = $day->modify('+7 days')) {
    $sundays[$day->format('Y-m-d')] = ct_service($a, $day->format('Y-m-d'));
}
$thu = ct_service($a, '2026-10-15', 'Midweek_Service');
ct_user($a, 50, 'Musa', 'Member', ['phone' => '08037777777', 'region_id' => 1]);   // drifts after 4 Oct
ct_user($a, 51, 'Nkechi', 'Member', ['phone' => '08038888888']);                   // misses Sundays but comes midweek
ct_user($a, 52, 'Ola', 'Visitor', ['phone' => '08039999999']);                      // not a Charis status
foreach ($sundays as $date => $id) {
    if ($date <= '2026-10-04') {
        ct_present($a, 50, $date, $id);
        ct_present($a, 51, $date, $id);
        ct_present($a, 52, $date, $id);
    }
    if ($date >= '2026-11-01') {
        ct_present($a, 51, $date, $id);   // Nkechi is back from November
    }
}
// Nkechi misses 11 and 18 Oct but comes on Thursday 15 Oct: midweek presence
// clears the streak (midweek absence never counts), so on 19 Oct she is at 1.
ct_present($a, 51, '2026-10-15', $thu);
ct_confirm($a, 50, 'musa@gmail.com');
automation_ensure_defaults($a);
$charis = automation_rule_get($a, 'charis');
ct_at('2026-10-05 08:00');
automation_rule_set_enabled($a, $charis['id'], true, 900, 'include');

$weekly = static function (PDO $pdo, string $monday): array {
    ct_at($monday . ' 09:05');
    $t = automation_tick($pdo, ['now' => campaigns_now()]);
    $out = [];
    foreach ($t['runs'] as $run) {
        foreach ($run['decisions'] as $x) {
            $out[] = $x['user_id'] . ':' . $x['step'] . ':' . $x['outcome'];
        }
    }
    return $out;
};
ct_eq('Mon 12 Oct, one Sunday missed: nothing', [], $weekly($a, '2026-10-12'));
ct_eq('Mon 19 Oct, two missed: Musa gets "we miss you"; Nkechi\'s Thursday visit cleared hers',
    ['50:miss_note:queued'], $weekly($a, '2026-10-19'));
ct_eq('the email went out as Charis', 'charis.miss_note', $a->query('SELECT flow FROM email_outbox WHERE user_id = 50 ORDER BY id DESC LIMIT 1')->fetchColumn());
ct_eq('Mon 26 Oct: Musa at three (nothing new); Nkechi now two Sundays after her Thursday',
    ['51:miss_note:queued'], $weekly($a, '2026-10-26'));
ct_eq('Mon 2 Nov, four missed: the team is told', ['50:team_alert:alerted'], $weekly($a, '2026-11-02'));
$bell = $a->query("SELECT * FROM system_notifications WHERE user_id = 901")->fetch();
ct_ok('…the Charis HOD gets a bell naming Musa and the streak', $bell && str_contains($bell['title'], '4 Sundays away: Musa Test')
    && $bell['link_url'] === '/modules/charis/index.php');
foreach (['2026-11-09', '2026-11-16', '2026-11-23'] as $m) {
    $weekly($a, $m);
}
ct_eq('Mon 30 Nov, eight missed: his region pastor is told', ['50:pastor_alert:alerted'], $weekly($a, '2026-11-30'));
ct_eq('…the Orchid pastor, not everyone', 1, ct_count($a, 'system_notifications', "user_id = 902 AND title LIKE '8 Sundays%'"));
ct_ok('no step ever fired twice for one streak', ct_count($a, 'automation_sends', "user_id = 50 AND outcome IN ('queued', 'alerted')") === 3);
ct_present($a, 50, '2026-12-06', $sundays['2026-12-06']);
ct_eq('Musa comes back on 6 Dec: the streak resets, nothing is sent', [], $weekly($a, '2026-12-07'));
$weekly($a, '2026-12-14');
ct_eq('…and drifts again: two more missed → a NEW "we miss you"', ['50:miss_note:queued'], $weekly($a, '2026-12-21'));
ct_eq('Ola (a Visitor) never entered Charis\'s list', 0, ct_count($a, 'automation_sends', 'user_id = 52'));

// Catch-up and "start fresh"
$f = ct_db();
ct_at('2026-09-01 08:00');
foreach (range(0, 15) as $w) {
    $date = campaigns_dt('2026-09-06')->modify('+' . (7 * $w) . ' days')->format('Y-m-d');
    $sid = ct_service($f, $date);
    if ($date <= '2026-09-13') {
        ct_present($f, 60, $date, $sid);
        ct_present($f, 61, $date, $sid);
    }
}
ct_user($f, 60, 'Pius', 'Member', ['phone' => '08030000001']);
ct_user($f, 61, 'Queen', 'Member', ['phone' => '08030000002']);
automation_ensure_defaults($f);
$cr = automation_rule_get($f, 'charis');
$cfg = $cr['config'];
$cfg['steps'][] = ['key' => 'second_note', 'label' => 'Still missing you', 'target' => 'member', 'threshold' => 6,
    'email' => $cfg['steps'][0]['email'], 'sms' => 'Hi {{first_name}}, we still miss you.'];
automation_rule_save($f, 'charis', $cfg, 900);
// By Mon 2 Nov both are 7 Sundays away (20 Sep … 1 Nov).
ct_at('2026-11-02 08:00');
$fresh = automation_rule_set_enabled($f, $cr['id'], true, 900, 'fresh');
ct_ok('"start fresh" baselines everyone already past a step', $fresh['baselined'] >= 4);
ct_eq('…so the first check sends nothing', [], $weekly($f, '2026-11-02'));
ct_eq('…until they cross the next step (8: the pastor)', ['60:pastor_alert:alerted', '61:pastor_alert:alerted'], $weekly($f, '2026-11-09'));

$g2 = ct_db();
ct_at('2026-09-01 08:00');
foreach (range(0, 10) as $w) {
    $date = campaigns_dt('2026-09-06')->modify('+' . (7 * $w) . ' days')->format('Y-m-d');
    $sid = ct_service($g2, $date);
    if ($date <= '2026-09-13') {
        ct_present($g2, 60, $date, $sid);
    }
}
ct_user($g2, 60, 'Pius', 'Member', ['phone' => '08030000001']);
automation_ensure_defaults($g2);
automation_rule_save($g2, 'charis', $cfg, 900);
ct_at('2026-11-02 08:00');
automation_rule_set_enabled($g2, automation_rule_get($g2, 'charis')['id'], true, 900, 'include');
$catch = $weekly($g2, '2026-11-02');
sort($catch);
ct_eq('"include": 7 away in one go → only the highest step per audience fires',
    ['60:second_note:queued', '60:team_alert:alerted'], $catch);
ct_eq('…the lower one is recorded as superseded', 'superseded',
    $g2->query("SELECT outcome FROM automation_sends WHERE user_id = 60 AND step_key = 'miss_note'")->fetchColumn());

// Away longer than the engine looks back (archive 12 + 8 + 4 weeks): the
// streak must keep ONE identity, or every step would fire again every week.
$lng = ct_db();
ct_at('2026-01-01 08:00');
for ($day = campaigns_dt('2026-01-04'); $day <= campaigns_dt('2026-12-27'); $day = $day->modify('+7 days')) {
    $sid = ct_service($lng, $day->format('Y-m-d'));
    if ($day->format('Y-m-d') <= '2026-02-01') {
        ct_present($lng, 80, $day->format('Y-m-d'), $sid);
    }
}
ct_user($lng, 80, 'Sola', 'Member', ['phone' => '08030000080', 'created_at' => '2025-06-01 10:00:00']);
ct_user($lng, 81, 'Taiwo', 'Member', ['phone' => '08030000081', 'created_at' => '2025-06-01 10:00:00']);  // never in church
automation_ensure_defaults($lng);
ct_at('2026-11-01 08:00');
automation_rule_set_enabled($lng, automation_rule_get($lng, 'charis')['id'], true, 900, 'include');
$firstWeek = $weekly($lng, '2026-11-02');
ct_eq('long-absent people get each step once…', 6, count($firstWeek));
ct_eq('…and nothing the next week', [], $weekly($lng, '2026-11-09'));
ct_eq('…or the week after', [], $weekly($lng, '2026-11-16'));

// ===========================================================================
ct_section('14. One member-facing message a day, across modules');
// ===========================================================================
$x = ct_db();
ct_at('2026-11-01 08:00');
foreach (['2026-11-01', '2026-11-08', '2026-11-15'] as $date) {
    $ids[$date] = ct_service($x, $date);
}
ct_user($x, 70, 'Rotimi', '1st_Timer', ['created_at' => '2026-11-01 10:00:00', 'phone' => '08030000070']);
ct_present($x, 70, '2026-11-01', $ids['2026-11-01']);
ct_confirm($x, 70, 'rotimi@gmail.com');
automation_ensure_defaults($x);
$as = automation_rule_get($x, 'assimilation');
$acfg = $as['config'];
$acfg['audience']['statuses'] = ['1st_Timer'];
automation_rule_save($x, 'assimilation', $acfg, 900);
ct_at('2026-11-02 08:00');
automation_rule_set_enabled($x, $as['id'], true, 900, 'include');
automation_rule_set_enabled($x, automation_rule_get($x, 'embrace')['id'], true, 900, 'include');
ct_at('2026-11-16 09:05');
$absRun = automation_tick($x, ['now' => campaigns_now()]);
ct_eq('09:05 Assimilation reaches Rotimi first', 'queued', $absRun['runs'][0]['decisions'][0]['outcome'] ?? null);
ct_at('2026-11-16 10:05');
$welRun = automation_tick($x, ['now' => campaigns_now()]);
ct_eq('10:05 the Embrace welcome is deferred, not sent the same day', 'deferred', $welRun['runs'][0]['decisions'][0]['outcome'] ?? null);
ct_eq('one email that day', 1, ct_count($x, 'email_outbox', "user_id = 70 AND lane = 'automation'"));
ct_at('2026-11-17 00:05');
ct_eq('not just after midnight', 0, automation_tick($x, ['now' => campaigns_now()])['retried']['retried'] ?? 0);
ct_at('2026-11-17 08:00');
ct_eq('nor before the time it was first meant to go (10:00)', 0, automation_tick($x, ['now' => campaigns_now()])['retried']['retried'] ?? 0);
ct_at('2026-11-17 10:05');
$retry = automation_tick($x, ['now' => campaigns_now()]);
ct_eq('the next day at 10:05 the deferred welcome goes', 1, $retry['retried']['retried']);
ct_eq('…as the series\' next email', 2, ct_count($x, 'email_outbox', "user_id = 70 AND lane = 'automation'"));
ct_eq('…and the ledger says so', 'queued', $x->query("SELECT outcome FROM automation_sends WHERE user_id = 70 AND step_key = 'welcome'")->fetchColumn());
$x->prepare('INSERT INTO automation_person_day (user_id, day, rule_id, step_key, created_at) VALUES (70, ?, 99, ?, ?)')
    ->execute(['2026-11-18', 'other', '2026-11-18 08:00:00']);
ct_at('2026-11-18 12:05');
$wedX = automation_tick($x, ['now' => campaigns_now()]);
ct_eq('a date-bound reminder that loses the day is skipped, not deferred', 'skipped_same_day', $wedX['runs'][0]['decisions'][0]['outcome'] ?? null);

// ===========================================================================
ct_section('15. Dry runs write nothing');
// ===========================================================================
$tables = ['automation_runs', 'automation_sends', 'automation_person_day', 'email_outbox', 'sms_campaigns', 'sms_queue', 'system_notifications'];
$before = array_map(static fn ($t) => ct_count($a, $t), $tables);
ct_at('2027-01-04 09:05');
$dry = automation_tick($a, ['now' => campaigns_now(), 'dry_run' => true]);
ct_ok('a dry run plans', $dry['ran'] && count($dry['runs']) === 1);
ct_eq('…and writes nothing', $before, array_map(static fn ($t) => ct_count($a, $t), $tables));
$preview = automation_preview($a, automation_rule_get($a, 'charis'), campaigns_now());
ct_ok('the tab preview lists people and channels', isset($preview['counts'], $preview['decisions']));

// ===========================================================================
ct_section('16. Coverage');
// ===========================================================================
$cov = email_coverage($j);
ct_eq('first timers counted from the Embrace pipeline', 9, $cov['first_timers']['total']);
ct_eq('confirmed addresses among them', 2, $cov['first_timers']['confirmed']);
ct_eq('reached by email (Ada: confirmed + yes; a 3rd Timer is still in the pipeline)', 1, $cov['first_timers']['news']);
ct_eq('unsubscribed (Halima, and Ijeoma who has no address)', 2, $cov['first_timers']['unsubscribed']);
ct_ok('the rest split into SMS-only and unreachable', $cov['first_timers']['sms_only'] + $cov['first_timers']['unreachable']
    + $cov['first_timers']['news'] + $cov['first_timers']['unsubscribed'] === $cov['first_timers']['total']);

// ===========================================================================
ct_section('16b. The sign-in email prompt');
// ===========================================================================
$p = ct_db();
ct_user($p, 100, 'Grace', 'Member', ['email' => 'grace@hodlc.com']);
ct_at('2026-11-20 09:00');
ct_eq('no email at all → the prompt is required', 'required', email_prompt_state($p, 100));
ct_eq('before the migration nobody is stopped', 'none', email_prompt_state($bare, 100));
email_capture_start($p, 100, 'grace.o@gmail.com', 'signin_prompt', 100, ['send' => false]);
ct_eq('a link sent → in with a banner', 'banner', email_prompt_state($p, 100));
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? null;
$banner = email_prompt_banner($p, 100);
ct_ok('the banner shows the address masked and resends in one tap', str_contains($banner, 'g•••@gmail.com')
    && str_contains($banner, 'value="resend"') && str_contains($banner, '_csrf'));
ct_at('2026-11-28 09:00');
ct_eq('a week later without confirming → required again', 'required', email_prompt_state($p, 100));
email_prompt_skip($p, 100, 100);
ct_eq('"I don\'t have an email" lets them in', 'none', email_prompt_state($p, 100));
ct_at('2027-03-01 09:00');
ct_eq('…and is asked again after 90 days', 'required', email_prompt_state($p, 100));
email_setting_set($p, 'signin_prompt', '0');
ct_eq('the switch turns the prompt off', 'none', email_prompt_state($p, 100));
email_setting_set($p, 'signin_prompt', '1');
ct_confirm($p, 100, 'grace.real@gmail.com', false);
ct_eq('confirmed → nothing to do', 'none', email_prompt_state($p, 100));

// ===========================================================================
ct_section('16b2. Capture from Connect and Embrace forms');
// ===========================================================================
$cf = ct_db();
ct_at('2026-11-22 10:00');
ct_user($cf, 110, 'Kola', '1st_Timer', ['email' => 'kola@gmail.com']);
email_capture_after_save($cf, 110, 'Kola@Gmail.com', 'connect', null, true, ['ip' => '102.1.1.1', 'user_agent' => 'Phone']);
ct_eq('a Connect card with an email starts confirmation', 'kola@gmail.com', email_address_state($cf, 110)['pending']);
ct_eq('…and the confirm email is queued', 1, ct_count($cf, 'email_outbox', "flow = 'confirm_email' AND user_id = 110"));
$kc = email_consent_current($cf, 110);
ct_ok('…recorded honestly as a pre-ticked box left ticked, with their IP', $kc['action'] === 'granted' && $kc['method'] === 'prechecked'
    && $kc['ip'] === '102.1.1.1' && $kc['recorded_by'] === null
    && $cf->query('SELECT body FROM email_consent_texts WHERE id = ' . (int) $kc['text_id'])->fetchColumn() === EMAIL_CONSENT_VISITOR_TEXT);
ct_at('2026-11-22 10:05');
email_capture_after_save($cf, 110, 'kola@gmail.com', 'embrace', 900, false);
ct_eq('saving the record again never re-sends the link', 1, ct_count($cf, 'email_outbox', "flow = 'confirm_email' AND user_id = 110"));
ct_user($cf, 111, 'Lara', '1st_Timer');
email_capture_after_save($cf, 111, 'lara@yahoo.com', 'embrace', 901, true);
$lc = email_consent_current($cf, 111);
ct_ok("an Embrace worker's form is pre-ticked too: 'prechecked' with the worker's id, never \"spoken\"", $lc['method'] === 'prechecked'
    && (int) $lc['recorded_by'] === 901 && $lc['ip'] === null
    && $cf->query('SELECT body FROM email_consent_texts WHERE id = ' . (int) $lc['text_id'])->fetchColumn() === EMAIL_CONSENT_VISITOR_TEXT);
ct_user($cf, 112, 'Musa', '1st_Timer');
email_capture_after_save($cf, 112, 'musa@hodlekki.org', 'embrace', 901, true);
ct_ok('a church address is ignored (no link, no consent)', email_address_state($cf, 112)['pending'] === null && email_consent_state($cf, 112) === 'none');
email_capture_after_save($cf, 112, '', 'embrace', 901, true);
ct_eq('no email, nothing recorded', 'none', email_consent_state($cf, 112));
email_capture_after_save($cf, 111, 'lara.new@yahoo.com', 'embrace', 901, false);
ct_eq('a changed address starts a new link', 'lara.new@yahoo.com', email_address_state($cf, 111)['pending']);
ct_eq('…and an unticked box withdraws nothing', 'granted', email_consent_state($cf, 111));
email_consent_record($cf, 111, 'withdrawn', 'one_click');
email_capture_after_save($cf, 111, 'lara.new@yahoo.com', 'embrace', 901, true);
email_capture_after_save($cf, 111, 'lara.third@yahoo.com', 'embrace', 901, true);
ct_eq('a pre-ticked box never undoes an unsubscribe (re-save or new address)', 'withdrawn', email_consent_state($cf, 111));

// Regression (#120): a page whose own forms are CSRF-checked must not be served
// with Referrer-Policy no-referrer — browsers then send "Origin: null" on its
// own POST and csrf_same_origin() refuses it ("open too long").
$root = dirname(__DIR__, 2);
$noRefBad = [];
foreach (glob($root . '/{,*/,*/*/}.htaccess', GLOB_BRACE) ?: [] as $ht) {
    $dir = dirname($ht);
    $hasCsrfForm = false;
    foreach (glob($dir . '/*.php') ?: [] as $php) {
        $src = (string) file_get_contents($php);
        if (str_contains($src, 'ui_csrf_field(') || str_contains($src, 'csrf_valid(') || str_contains($src, 'ui_standalone_csrf_ok(')) {
            $hasCsrfForm = true;
            break;
        }
    }
    if ($hasCsrfForm && preg_match('/Referrer-Policy\s+"?no-referrer"?\s*$/mi', (string) file_get_contents($ht))) {
        $noRefBad[] = substr($ht, strlen($root) + 1);
    }
}
ct_eq('no folder with CSRF-checked forms sends Referrer-Policy no-referrer', [], $noRefBad);
ct_ok('email/.htaccess keeps same-origin', (bool) preg_match('/Referrer-Policy\s+"same-origin"/', (string) file_get_contents($root . '/email/.htaccess')));

// ===========================================================================
ct_section('16b3. The Connect card cannot be used to mail strangers');
// ===========================================================================
$th = ct_db();
ct_at('2026-11-23 09:00');
for ($i = 0; $i < 4; $i++) {
    ct_user($th, 200 + $i, 'Bot' . $i, '1st_Timer', ['phone' => '0803000020' . $i]);
    $th_at = ct_at('2026-11-23 09:0' . $i);
    email_capture_after_save($th, 200 + $i, 'victim@example.com', 'connect', null, false, ['ip' => '10.0.0.' . $i]);
}
ct_eq('one address: at most 3 links a day, whoever the "person" is', 3, ct_count($th, 'email_outbox', "to_email = 'victim@example.com'"));
ct_eq('…the 4th card is saved but nothing is queued for it', null, email_address_state($th, 203)['pending']);

$th2 = ct_db();
ct_at('2026-11-23 10:00');
for ($i = 0; $i < 6; $i++) {
    ct_user($th2, 300 + $i, 'Same' . $i, '1st_Timer');
    email_capture_after_save($th2, 300 + $i, "person{$i}@example.com", 'connect', null, false, ['ip' => '41.58.1.1']);
}
ct_eq('one requester: at most 5 Connect links an hour', 5, ct_count($th2, 'email_outbox', "flow = 'confirm_email'"));
ct_ok('the IP is stored only as a hash', ct_count($th2, 'email_link_throttle', "key_hash = '41.58.1.1'") === 0
    && ct_count($th2, 'email_link_throttle') === 5);
ct_at('2026-11-23 11:01');
ct_user($th2, 399, 'Later', '1st_Timer');
email_capture_after_save($th2, 399, 'later@example.com', 'connect', null, false, ['ip' => '41.58.1.1']);
ct_eq('…an hour later they may again', 6, ct_count($th2, 'email_outbox', "flow = 'confirm_email'"));

$th3 = ct_db();
ct_at('2026-11-23 12:00');
for ($i = 0; $i < 22; $i++) {
    ct_user($th3, 400 + $i, 'Many' . $i, '1st_Timer');
    email_capture_after_save($th3, 400 + $i, "many{$i}@example.com", 'connect', null, false, ['ip' => '102.0.0.' . $i]);
}
ct_eq('all of Connect together: at most 20 links an hour', 20, ct_count($th3, 'email_outbox', "flow = 'confirm_email'"));
ct_user($th3, 499, 'Member', 'Member');
ct_ok('…and a member\'s own sign-in confirmation still goes', email_capture_start($th3, 499, 'member.own@example.com', 'signin_prompt', 499, ['ip' => '102.0.0.1'])['ok'] === true);
ct_eq('…so do Embrace workers\' entries', true, (function () use ($th3): bool {
    ct_user($th3, 498, 'Desk', '1st_Timer');
    email_capture_after_save($th3, 498, 'desk.entry@example.com', 'embrace', 901, false);
    return email_address_state($th3, 498)['pending'] === 'desk.entry@example.com';
})());
$noTable = ct_db();
$noTable->exec('DROP TABLE email_link_throttle');
ct_user($noTable, 500, 'Early', '1st_Timer');
ct_eq('before its migration the limiter fails closed (no Connect link)', 'THROTTLED',
    email_capture_start($noTable, 500, 'early@example.com', 'connect', null, ['ip' => '1.1.1.1'])['code'] ?? null);

$sidA = 'session-a';
$tok = campaigns_form_token(1_800_000_000, $sidA);
ct_eq('a person who took their time', 'ok', campaigns_form_verdict($tok, '', 1_800_000_060, $sidA));
ct_eq('the hidden field filled in: a bot (silent)', 'bot', campaigns_form_verdict($tok, 'http://spam.example', 1_800_000_060, $sidA));
ct_eq('posted within 4 seconds of the page: a bot (silent)', 'bot', campaigns_form_verdict($tok, '', 1_800_000_002, $sidA));
ct_eq('an invented time: "send again" (never a silent drop)', 'stale', campaigns_form_verdict('1799999000.' . str_repeat('a', 32), '', 1_800_000_060, $sidA));
ct_eq('no token at all: "send again"', 'stale', campaigns_form_verdict('', '', 1_800_000_060, $sidA));
ct_eq('a token from another session (a bot dropping cookies): "send again"', 'stale', campaigns_form_verdict($tok, '', 1_800_000_060, 'session-b'));
ct_eq('a page left open for days: asked to reload', 'expired', campaigns_form_verdict($tok, '', 1_800_000_000 + 4 * 86400, $sidA));
$_SESSION['connect_form_used'] = [];
campaigns_form_consume($tok);
ct_eq('once it has saved a card, the same page replayed is a bot', 'bot', campaigns_form_verdict($tok, '', 1_800_000_090, $sidA));
$fresh = campaigns_form_token(1_800_000_100, $sidA);
ct_ok("an error reply's fresh token works (after the 4 seconds)", $fresh !== $tok && campaigns_form_verdict($fresh, '', 1_800_000_105, $sidA) === 'ok');
$_SESSION['connect_form_used'] = [];

// Cloudflare Turnstile: off without keys; with keys, the reply decides; Cloudflare down lets the card through.
unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY']);
ct_eq('Turnstile without keys is off (the card works as before)', 'off', campaigns_turnstile_check('', '1.1.1.1'));
$_ENV['TURNSTILE_SITE_KEY'] = '1x00000000000000000000AA';
$_ENV['TURNSTILE_SECRET_KEY'] = '1x0000000000000000000000000000000AA';
$seen = null;
$ok = campaigns_turnstile_check('tok-123', '1.1.1.1', function (string $url, array $form) use (&$seen): ?array {
    $seen = [$url, $form['response'], $form['remoteip']];
    return ['success' => true];
});
ct_ok('with keys, a solved challenge passes (token and IP sent to siteverify)', $ok === 'ok'
    && $seen === [CAMPAIGNS_TURNSTILE_VERIFY, 'tok-123', '1.1.1.1']);
ct_eq('…a rejected one asks them to tick it again', 'fail', campaigns_turnstile_check('tok-x', '1.1.1.1', fn () => ['success' => false]));
ct_eq('…no token at all fails', 'fail', campaigns_turnstile_check('', '1.1.1.1', fn () => ['success' => true]));
ct_eq('…Cloudflare unreachable lets the card through (logged)', 'unreachable', campaigns_turnstile_check('tok-y', '1.1.1.1', fn () => null));
unset($_ENV['TURNSTILE_SITE_KEY'], $_ENV['TURNSTILE_SECRET_KEY']);

// ===========================================================================
ct_section('16c. Sign-in with a personal email');
// ===========================================================================
ct_user($p, 101, 'Femi', 'Member', ['email' => 'femi@hodlc.com']);
ct_user($p, 102, 'Bisi', 'Member', ['email' => 'bisi.home@yahoo.com']);
$l = email_signin_lookup($p, 'GRACE.REAL@gmail.com');
ct_ok('the confirmed personal email signs in (any case)', $l['user'] !== null && (int) $l['user']['id'] === 100 && $l['via'] === 'personal');
$old = email_signin_lookup($p, 'grace@hodlc.com');
ct_ok('the old sign-in name finds them, but says they have moved', (int) $old['user']['id'] === 100 && $old['moved_to'] === 'g•••@gmail.com');
ct_eq('someone who has not moved still signs in with their name', null, email_signin_lookup($p, 'femi@hodlc.com')['moved_to']);
ct_confirm($p, 102, 'bisi.new@gmail.com', false);
ct_eq('a personal address on file is never "moved"', null, email_signin_lookup($p, 'bisi.home@yahoo.com')['moved_to']);
ct_eq('an unknown identifier finds nobody', null, email_signin_lookup($p, 'nobody@gmail.com')['user']);
ct_eq('a masked address', 'a•••@gmail.com', email_mask('ada.obi@gmail.com'));

// ===========================================================================
ct_section('16d. Forgot password');
// ===========================================================================
ct_at('2027-03-01 10:00');
ct_eq('unknown person: the same answer, nothing sent', false, email_reset_request($p, 'nobody@gmail.com', '1.1.1.1')['sent']);
ct_eq('no confirmed email: nothing sent', false, email_reset_request($p, 'femi@hodlc.com', '1.1.1.2')['sent']);
$req = email_reset_request($p, 'grace@hodlc.com', '1.1.1.3');
ct_ok('the old sign-in name may ask; the link goes to the confirmed email', $req['sent'] === true);
$resetMail = $p->query("SELECT * FROM email_outbox WHERE flow = 'password_reset' ORDER BY id DESC LIMIT 1")->fetch();
ct_ok('…as account mail, first in line, to grace.real@gmail.com', $resetMail['to_email'] === 'grace.real@gmail.com'
    && $resetMail['lane'] === 'transactional' && $resetMail['purpose'] === 'account');
ct_ok('…carrying the link', str_contains((string) $resetMail['html'], 'https://hodlekki.org/auth/reset_password.php?t=' . $req['token']));
ct_eq('the raw token is never stored', 0, ct_count($p, 'auth_reset_tokens', 'token_hash = ?', [$req['token']]));
ct_eq('the link opens the form', 'valid', email_reset_peek($p, $req['token'])['state']);
ct_eq('mismatch is refused', 'MISMATCH', email_reset_complete($p, $req['token'], 'Abcdefgh12!x', 'Abcdefgh12!y')['code'] ?? null);
ct_eq('a weak password is refused by the one policy', 'WEAK', email_reset_complete($p, $req['token'], 'abc', 'abc')['code'] ?? null);
$req2 = email_reset_request($p, 'grace.real@gmail.com', '1.1.1.3');
ct_eq('a newer link replaces the older one', 'used', email_reset_peek($p, $req['token'])['state']);
$good = 'Hallelujah-2027!';
ct_eq('a good password is saved', true, email_reset_complete($p, $req2['token'], $good, $good)['ok']);
ct_ok('…and works', password_verify($good, (string) $p->query('SELECT password_hash FROM users WHERE id = 100')->fetchColumn()));
ct_eq('…the link works once', 'USED', email_reset_complete($p, $req2['token'], $good, $good)['code'] ?? null);
ct_eq('…and a "password changed" notice goes to the confirmed email', 1, ct_count($p, 'email_outbox', "flow = 'password_changed' AND to_email = 'grace.real@gmail.com'"));
$req3 = email_reset_request($p, 'grace.real@gmail.com', '1.1.1.4');
ct_at('2027-03-01 10:31');
ct_eq('after 30 minutes the link has expired', 'EXPIRED', email_reset_complete($p, $req3['token'], $good, $good)['code'] ?? null);
ct_eq('a third request for one identifier within the hour still goes', false,
    email_reset_request($p, 'grace.real@gmail.com', '1.1.1.5')['throttled']);
ct_eq('the fourth is throttled (with the same answer on the page)', true,
    email_reset_request($p, 'grace.real@gmail.com', '1.1.1.7')['throttled']);
for ($i = 0; $i < 10; $i++) {
    email_reset_request($p, "probe{$i}@gmail.com", '9.9.9.9');
}
ct_eq('ten requests from one address in an hour, then throttled', true, email_reset_request($p, 'grace@hodlc.com', '9.9.9.9')['throttled']);
$p->exec("UPDATE users SET account_status = 'suspended' WHERE id = 102");
ct_at('2027-03-01 12:00');
ct_eq('a suspended account gets no link', false, email_reset_request($p, 'bisi.new@gmail.com', '1.1.1.6')['sent']);

// ===========================================================================
ct_section('16e. The Profile email card');
// ===========================================================================
require_once __DIR__ . '/../../includes/campaigns/profile_card.php';
$q = ct_db();
ct_at('2027-04-01 10:00');
ct_user($q, 200, 'Tolu', 'Member', ['email' => 'tolu@hodlc.com']);
ct_user($q, 201, 'Yemi', 'First Timer');
$v = email_profile_view($q, 200);
ct_ok('a member with no address: nothing confirmed or waiting, nothing to suggest from a church sign-in name',
    $v['ready'] && $v['confirmed'] === null && $v['pending'] === null && $v['suggest'] === null);
ct_ok('…church news is on by membership', $v['member'] && $v['news']);
$h = email_profile_card_html($v);
ct_ok('…the card shows the open form and no news switch',
    str_contains($h, 'data-profile-email-form') && !str_contains($h, 'data-profile-email-form novalidate hidden')
    && str_contains($h, 'Send link') && !str_contains($h, 'profile.email-news'));
email_prompt_skip($q, 200, 200);
$bad = email_profile_action($q, 200, 'email_save', ['email' => 'tolu@hodlekki.org']);
ct_ok('a church address is refused on the email field', $bad['status'] === 'error' && ($bad['field'] ?? '') === 'email');
ct_eq('an empty field is refused', 'email', email_profile_action($q, 200, 'email_save', ['email' => ' '])['field'] ?? null);
$r = email_profile_action($q, 200, 'email_save', ['email' => 'Tolu@Gmail.com'], '1.2.3.4', 'UA');
ct_ok('the first address needs no password: saving sends a link and answers with the card', $r['status'] === 'success' && $r['message'] === 'Link sent.'
    && str_contains($r['html'], 'tolu@gmail.com') && str_contains($r['html'], 'Waiting') && str_contains($r['html'], 'Resend'));
ct_eq('…one confirmation email queued to it', 1, ct_count($q, 'email_outbox', "flow = 'confirm_email' AND to_email = 'tolu@gmail.com'"));
ct_eq('…recorded as from Profile', 1, ct_count($q, 'email_addresses', "user_id = 200 AND source = 'profile' AND status = 'pending'"));
ct_eq("…and \"I don't have an email\" is forgotten", 0, ct_count($q, 'email_prompt_skips', 'user_id = 200'));
ct_ok('the news switch shows once there is an address', str_contains($r['html'], 'profile.email-news') && str_contains($r['html'], ' checked>'));
$again = email_profile_action($q, 200, 'email_resend', []);
ct_ok('resend straight away waits a minute', $again['status'] === 'error' && !isset($again['field']));
ct_at('2027-04-01 10:02');
ct_eq('resend after a minute sends again', 'success', email_profile_action($q, 200, 'email_resend', [])['status']);
ct_eq('…to the same waiting address', 2, ct_count($q, 'email_outbox', "flow = 'confirm_email' AND to_email = 'tolu@gmail.com'"));
$c = email_profile_action($q, 200, 'email_cancel', []);
ct_ok('"Wrong email?" drops the waiting address and shows the form again',
    $c['status'] === 'success' && email_profile_view($q, 200)['pending'] === null && str_contains($c['html'], 'Send link'));
ct_eq('resend with nothing waiting says so', 'error', email_profile_action($q, 200, 'email_resend', [])['status']);

ct_confirm($q, 200, 'tolu@gmail.com', false);
$h = email_profile_card_html(email_profile_view($q, 200));
ct_ok('confirmed: the address, "Confirmed", the form folded behind "Change email"',
    str_contains($h, 'Confirmed') && str_contains($h, 'data-profile-email-form novalidate hidden') && str_contains($h, 'Change email'));
ct_ok('…the switch is on (a member who has not said stop)', str_contains($h, ' checked>'));
ct_eq('saving the same address again: already confirmed', 'Already confirmed.',
    email_profile_action($q, 200, 'email_save', ['email' => 'tolu@gmail.com'])['message'] ?? null);

$off = email_profile_action($q, 200, 'email_news', ['on' => '0'], '1.2.3.4', 'UA');
ct_ok('switching church news off', $off['status'] === 'success' && $off['news'] === false);
$row = email_consent_current($q, 200);
ct_ok('…is a withdrawal in the consent log, from Profile, with the address',
    $row['action'] === 'withdrawn' && $row['method'] === 'profile' && $row['email'] === 'tolu@gmail.com' && $row['ip'] === '1.2.3.4');
ct_eq('…and stops church news', 'withdrawn', email_may_send_news($q, 200)['reason']);
ct_ok('…the switch shows off', !str_contains(email_profile_card_html(email_profile_view($q, 200)), ' checked>'));
email_profile_action($q, 200, 'email_news', ['on' => '1']);
$row = email_consent_current($q, 200);
$text = $q->prepare('SELECT body FROM email_consent_texts WHERE id = ?');
$text->execute([(int) $row['text_id']]);
ct_ok('switching it on again is a "yes" with the wording beside the switch',
    $row['action'] === 'granted' && $row['method'] === 'profile' && $text->fetchColumn() === EMAIL_PROFILE_NEWS_TEXT);
ct_eq('…and church news may go again', true, email_may_send_news($q, 200)['ok']);
$n = ct_count($q, 'email_consents', 'user_id = 200');
$same = email_profile_action($q, 200, 'email_news', ['on' => '1']);
ct_ok('switching on when already on writes nothing', $same['status'] === 'success' && ct_count($q, 'email_consents', 'user_id = 200') === $n);

ct_confirm($q, 201, 'yemi@yahoo.com', false);
ct_eq('a visitor with no "yes" on record: the switch is off', false, email_profile_view($q, 201)['news']);
ct_eq('…and no church news', 'no_consent', email_may_send_news($q, 201)['reason']);
email_profile_action($q, 201, 'email_news', ['on' => '1']);
ct_eq('…switching it on lets church news go', true, email_may_send_news($q, 201)['ok']);

ct_at('2027-04-01 11:00');
$q->prepare('UPDATE users SET password_hash = ? WHERE id = 200')->execute([password_hash('Right-Pass-2027', PASSWORD_DEFAULT)]);
ct_ok('changing a CONFIRMED address asks for the password',
    str_contains(email_profile_card_html(email_profile_view($q, 200)), 'name="password"'));
$np = email_profile_action($q, 200, 'email_save', ['email' => 'tolu.new@gmail.com']);
ct_ok('…without it: refused on the password field', $np['status'] === 'error' && ($np['field'] ?? '') === 'password');
$wp = email_profile_action($q, 200, 'email_save', ['email' => 'tolu.new@gmail.com', 'password' => 'Wrong-Pass-2027']);
ct_ok('…a wrong password: refused with one line', $wp['status'] === 'error' && ($wp['field'] ?? '') === 'password'
    && $wp['message'] === 'That is not your password.');
ct_eq('…and nothing is started or sent', [null, 0],
    [email_profile_view($q, 200)['pending'], ct_count($q, 'email_outbox', "to_email = 'tolu.new@gmail.com'")]);
$typo = email_profile_action($q, 200, 'email_save', ['email' => 'tolu@hodlekki.org', 'password' => 'Wrong-Pass-2027']);
ct_eq('…a bad address is refused before the password is tried', 'email', $typo['field'] ?? null);
$src = (string) file_get_contents(__DIR__ . '/../../includes/campaigns/profile_card.php');
ct_ok("…a wrong password counts in the Password card's lockout (login_attempts, 'password_change')",
    str_contains($src, "security_record_attempt(\$pdo, \$userId, (string) (\$me['email'] ?? ''), false, 'wrong_password_email_change', 'password_change')")
    && str_contains($src, "security_recent_failure_count(\$pdo, \$userId, 'password_change', SECURITY_PASSWORD_CHANGE_WINDOW_MINUTES)"));
$ch = email_profile_action($q, 200, 'email_save', ['email' => 'tolu.new@gmail.com', 'password' => 'Right-Pass-2027']);
ct_ok('with the password: the new one waits beside the old',
    $ch['status'] === 'success' && str_contains($ch['html'], 'New, waiting') && str_contains($ch['html'], 'Keep current'));
ct_eq('…and mail still goes to the confirmed one until the link is tapped', 'tolu@gmail.com', member_confirmed_email($q, 200));
email_profile_action($q, 200, 'email_cancel', []);
ct_eq('"Keep current" drops the new one', null, email_profile_view($q, 200)['pending']);

ct_eq('a first confirmation tells no old address', 0, ct_count($q, 'email_outbox', "flow = 'email_changed'"));
ct_at('2027-04-01 12:00');
email_profile_action($q, 200, 'email_save', ['email' => 'tolu.new@gmail.com', 'password' => 'Right-Pass-2027']);
$html = (string) $q->query("SELECT html FROM email_outbox WHERE flow = 'confirm_email' AND to_email = 'tolu.new@gmail.com' ORDER BY id DESC LIMIT 1")->fetchColumn();
preg_match('#confirm\.php\?t=([A-Za-z0-9_-]+)#', $html, $m);
ct_eq('confirming the new address replaces the old', 'tolu.new@gmail.com',
    (email_capture_confirm($q, $m[1] ?? '')['ok'] ?? false) ? member_confirmed_email($q, 200) : null);
$note = $q->query("SELECT * FROM email_outbox WHERE flow = 'email_changed' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ct_ok('…and the OLD address is told, as account mail in the alert lane',
    $note && $note['to_email'] === 'tolu@gmail.com' && $note['lane'] === 'alert' && $note['purpose'] === 'account' && (int) $note['user_id'] === 200);
ct_ok('…naming the new address only masked, with the date',
    str_contains((string) $note['html'], 't•••@gmail.com') && !str_contains((string) $note['html'], 'tolu.new@gmail.com')
    && str_contains((string) $note['html'], '1 Apr 2027') && str_contains((string) $note['html'], 'Not you?'));
$err = email_profile_card_html(email_profile_view($q, 200), ['error' => 'Check this.', 'typed' => 'x@y']);
ct_ok('a failed save re-opens the form with the error and what was typed',
    str_contains($err, 'Check this.') && str_contains($err, 'value="x@y"') && !str_contains($err, 'novalidate hidden'));
email_suppress($q, 'tolu.new@gmail.com', 'hard_bounce', 'test');
ct_ok('a bouncing address says so', email_profile_view($q, 200)['suppressed']
    && str_contains(email_profile_card_html(email_profile_view($q, 200)), 'Emails are not arriving'));

$bare = ct_db(false);
ct_ok('before the migration: the card says it is being set up, actions refuse',
    str_contains(email_profile_card_html(email_profile_view($bare, 200)), 'Email is being set up.')
    && email_profile_action($bare, 200, 'email_save', ['email' => 'a@gmail.com'])['status'] === 'error');
$api = (string) file_get_contents(__DIR__ . '/../../api/profile_api.php');
$page = (string) file_get_contents(__DIR__ . '/../../modules/profile/index.php');
ct_ok('Profile no longer saves the personal email as a free field',
    !str_contains($api, 'real_email = ?') && !str_contains($page, "'name' => 'real_email'"));
ct_ok("…the API runs the card's actions and the page prints the card",
    str_contains($api, 'email_profile_action(') && str_contains($page, 'email_profile_card_html('));

// ===========================================================================
ct_section('17. Every function the module calls exists');
// ===========================================================================
$files = array_merge(glob(__DIR__ . '/../../includes/campaigns/*.php'), [
    __DIR__ . '/../../cron/email_worker.php', __DIR__ . '/../../cron/automation_tick.php', __DIR__ . '/../../includes/mailer.php',
]);
$optional = ['fastcgi_finish_request', 'litespeed_finish_request', 'push_release_response'];
$missing = [];
foreach ($files as $file) {
    $tokens = token_get_all((string) file_get_contents($file));
    $prev = null;
    foreach ($tokens as $i => $tok) {
        if (is_array($tok) && in_array($tok[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if (is_array($tok) && $tok[0] === T_STRING) {
            $next = null;
            for ($k = $i + 1; isset($tokens[$k]); $k++) {
                if (is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) {
                    continue;
                }
                $next = $tokens[$k];
                break;
            }
            $prevId = is_array($prev) ? $prev[0] : $prev;
            if ($next === '(' && !in_array($prevId, [T_FUNCTION, T_NEW, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_CONST], true)) {
                $name = $tok[1];
                if (!function_exists($name) && !in_array($name, $optional, true)) {
                    $missing[basename($file) . ':' . $name] = true;
                }
            }
        }
        $prev = $tok;
    }
}
ct_eq('no call to a function that does not exist', [], array_keys($missing));

campaigns_set_clock(false);
echo "\n{$GLOBALS['ct_passed']} passed, {$GLOBALS['ct_failed']} failed\n";
exit($GLOBALS['ct_failed'] === 0 ? 0 : 1);
