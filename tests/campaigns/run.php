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
ct_eq("a member's @hodlc.com sign-in name IS replaced by the confirmed address (owner, 13 Nov 2026)", 'grace.o@gmail.com', $pdo->query('SELECT email FROM users WHERE id = 2')->fetchColumn());
ct_eq('…and kept aside, so typing it says where they sign in now', 2, signin_retired_name_owner($pdo, 'grace@hodlc.com'));
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

// The account-mail route (ZeptoMail, 26 Nov 2026): account mail skips the host's hourly cap.
$r = ct_db();
ct_at('2026-11-16 10:00');
ct_eq('not set up: every lane uses the host', 'host', email_lane_route('transactional'));
$_ENV += ['ACCOUNT_MAIL_HOST' => 'smtp.zeptomail.com', 'ACCOUNT_MAIL_USERNAME' => 'emailapikey', 'ACCOUNT_MAIL_PASSWORD' => 'test-token'];
ct_eq('set up: account mail uses it', ['account', 'account'], [email_lane_route('transactional'), email_lane_route('alert')]);
ct_eq('…church news and automations stay on the host', ['host', 'host', 'host'],
    [email_lane_route('campaign'), email_lane_route('automation'), email_lane_route('mailbox')]);
email_setting_set($r, 'hourly_cap', '10');
for ($i = 0; $i < 12; $i++) {
    email_enqueue($r, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'reset_code', 'to_email' => "code{$i}@gmail.com",
        'subject' => 'Your code', 'html' => '<p>123 456</p>']);
}
$routeTx = ct_transport();
$routeRun = email_worker_run($r, ['endpoints_ready' => true, 'transport' => $routeTx, 'now' => campaigns_now()]);
ct_eq('twelve codes leave though the host cap is 10 (9 usable)', 12, $routeRun['sent']);
ct_eq('…each through the account route', ['account'], array_values(array_unique(array_map(static fn ($m) => $m['route'], $routeTx['sent']->getArrayCopy()))));
ct_eq('…and none counted against the host', 0, email_budget_status($r)['sent_total']);
email_enqueue($r, ['lane' => 'transactional', 'purpose' => 'account', 'flow' => 'reset_code', 'to_email' => 'late@gmail.com',
    'subject' => 'Your code', 'html' => '<p>1</p>']);
email_worker_run($r, ['endpoints_ready' => true, 'now' => campaigns_now(), 'transport' => ct_transport([['ok' => false,
    'error' => 'Domain hodlekki.org has exceeded the max emails per hour (20/20 (100%)) allowed.']])]);
ct_eq('a cap refusal on the account route does not shut the host for the hour', false, email_budget_status($r)['refused']);
// The Brevo backup (owner, 26 Nov 2026): ZeptoMail first, Brevo when ZeptoMail cannot take it.
$_ENV['ACCOUNT_MAIL_BACKUP_API_KEY'] = 'xkeysib-test';
$calls = [];
$zOk = static function (array $m) use (&$calls): array { $calls[] = 'zepto'; return ['ok' => true, 'error' => null, 'message_id' => null]; };
$zNoCredit = static function (array $m) use (&$calls): array { $calls[] = 'zepto'; return ['ok' => false, 'error' => hod_zepto_error(402, '{"error":{"message":"No credits"}}'), 'message_id' => null]; };
$zBadAddress = static function (array $m) use (&$calls): array { $calls[] = 'zepto'; return ['ok' => false, 'error' => 'SMTP Error: 550 5.1.1 user unknown', 'message_id' => null]; };
$bOk = static function (array $m) use (&$calls): array { $calls[] = 'brevo'; return ['ok' => true, 'error' => null, 'message_id' => null]; };
$bDown = static function (array $m) use (&$calls): array { $calls[] = 'brevo'; return ['ok' => false, 'error' => hod_mail_api_error('Brevo', 503, ''), 'message_id' => null]; };
$msg = ['to_email' => 'ada@gmail.com', 'subject' => 'Your code', 'html' => '<p>1</p>'];
ct_ok('ZeptoMail takes it: Brevo is never asked', email_send_account($msg, $zOk, $bOk)['ok'] && $calls === ['zepto']);
$calls = [];
ct_ok('ZeptoMail out of credit: Brevo sends it', email_send_account($msg, $zNoCredit, $bOk)['ok'] && $calls === ['zepto', 'brevo']);
$calls = [];
ct_ok('a wrong address is not retried on Brevo', !email_send_account($msg, $zBadAddress, $bOk)['ok'] && $calls === ['zepto']);
$calls = [];
$both = email_send_account($msg, $zNoCredit, $bDown);
ct_ok('both down: one answer with both reasons, filed as temporary (it goes back in the queue)',
    !$both['ok'] && str_contains($both['error'], 'Brevo') && str_contains($both['error'], 'ZeptoMail')
    && email_classify_failure($both['error']) === 'temporary');
$calls = [];
ct_ok('ZeptoMail not set up: Brevo alone', email_send_account($msg, null, $bOk)['ok'] && $calls === ['brevo']);
$saved = [$_ENV['ACCOUNT_MAIL_HOST'], $_ENV['ACCOUNT_MAIL_PASSWORD']];
unset($_ENV['ACCOUNT_MAIL_HOST'], $_ENV['ACCOUNT_MAIL_PASSWORD']);
ct_eq('only Brevo set up: account mail still takes the account route', 'account', email_lane_route('transactional'));
[$_ENV['ACCOUNT_MAIL_HOST'], $_ENV['ACCOUNT_MAIL_PASSWORD']] = $saved;
unset($_ENV['ACCOUNT_MAIL_BACKUP_API_KEY']);
ct_eq('ZeptoMail answers are filed right', ['temporary', 'permanent', 'config', 'unavailable'], [
    email_classify_failure(hod_zepto_error(503, '')),
    email_classify_failure(hod_zepto_error(422, '{"error":{"message":"Invalid address 550"}}')),
    email_classify_failure(hod_zepto_error(401, '')),
    email_classify_failure('Could not connect to ZeptoMail: timed out'),
]);
unset($_ENV['ACCOUNT_MAIL_HOST'], $_ENV['ACCOUNT_MAIL_USERNAME'], $_ENV['ACCOUNT_MAIL_PASSWORD']);
ct_eq('unset again: back to the host', 'host', email_lane_route('transactional'));

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
    && $bell['link_url'] === '/modules/charis/');
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
ct_section('16c2. Sign-in by phone; the @hodlc.com names die out (owner, 13 Nov 2026)');
// ===========================================================================
ct_eq('a phone number is a phone number', true, signin_is_phone('0803 123 4567'));
ct_eq('…written with +234 too', true, signin_is_phone('+234 803 123 4567'));
ct_eq('an email is not', false, signin_is_phone('grace@gmail.com'));
ct_eq('nor a church sign-in name', false, signin_is_phone('grace@hodlc.com'));
ct_eq('nor a short code', false, signin_is_phone('12345'));
foreach (['08031234567', '+2348031234567', '2348031234567', '+234 0803 123 4567', '8031234567', '0803-123-4567', '(0803) 123 4567'] as $w) {
    ct_eq("one key for {$w}", '2348031234567', signin_phone_key($w));
}
ct_eq('a foreign number keeps its digits', '447700900123', signin_phone_key('+44 7700 900123'));
ct_eq('…and 00 is the same as +', '447700900123', signin_phone_key('0044 7700 900123'));
ct_eq('too short is no number', null, signin_phone_key('0803123'));

ct_at('2027-02-01 09:00');
$ph = ct_db();
$hash = static fn (string $pw): string => password_hash($pw, PASSWORD_BCRYPT, ['cost' => 4]);
ct_user($ph, 1, 'Tayo', 'Member', ['phone' => '0803 123 4567']);
ct_user($ph, 2, 'Funmi', 'Worker', ['phone' => '+234 803 123 4567']);       // Tayo's wife: the same number
ct_user($ph, 3, 'Kunle', 'Member', ['phone' => '08059998888 / 08024446666']); // two numbers on one record
ct_user($ph, 4, 'Ada', '1st_Timer', ['phone' => '08077776666']);
ct_user($ph, 5, 'Sola', 'Member', ['phone' => '07012345678', 'email' => 'sola@hodlc.com']);
foreach ([1 => 'Tayo-pass-1', 2 => 'Funmi-pass-1', 3 => 'Kunle-pass-1', 5 => 'Sola-pass-1'] as $id => $pw) {
    $ph->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash($pw), $id]);
}
$ids = static fn (array $users): array => array_map(static fn (array $u): int => (int) $u['id'], $users);
$try = static fn (string $who, string $pw): array => signin_check_password($ph, signin_candidates($ph, $who)['users'], $pw, $who, true);
$fails = static fn (int $id): int => (int) $ph->query("SELECT failed_login_count FROM users WHERE id = {$id}")->fetchColumn();

ct_eq('both accounts on a shared number are candidates, however it is typed', [1, 2], $ids(signin_candidates($ph, '+234 (803) 123-4567')['users']));
ct_eq('a record holding two numbers answers to either', [3], $ids(signin_candidates($ph, '0802 444 6666')['users']));
ct_eq('…but never to part of one', [], $ids(signin_candidates($ph, '0803 123 456')['users']));
$r = $try('0803 123 4567', 'Funmi-pass-1');
ct_ok("the password opens the wife's account", $r['ok'] && (int) $r['user']['id'] === 2);
$r = $try('+2348031234567', 'Tayo-pass-1');
ct_ok("…and the husband's", $r['ok'] && (int) $r['user']['id'] === 1);
$r = $try('07012345678', 'Sola-pass-1');
ct_ok('a member with a @hodlc.com name signs in with their phone too', $r['ok'] && (int) $r['user']['id'] === 5);
$r = $try('sola@hodlc.com', 'Sola-pass-1');
ct_ok('…and still with the name', $r['ok'] && (int) $r['user']['id'] === 5);
ct_eq('an unknown number: the one answer, nothing counted', SIGNIN_WRONG, $try('09000000000', 'x')['message']);
$r = $try('08031234567', 'wrong');
ct_eq('a wrong password: the same one answer', SIGNIN_WRONG, $r['message']);
ct_eq('…counted on every account it was tried on', [1, 1], [$fails(1), $fails(2)]);
for ($i = 0; $i < 3; $i++) {
    $r = $try('08031234567', 'wrong');
}
ct_ok('two tries left: it says so', str_contains($r['message'], '1 attempt left'));
$r = $try('08031234567', 'wrong');
ct_ok('the fifth locks both accounts', str_contains($r['message'], 'locked for 15 minutes')
    && (int) $ph->query('SELECT COUNT(*) FROM users WHERE id IN (1, 2) AND locked_until IS NOT NULL')->fetchColumn() === 2);
$r = $try('08031234567', 'Funmi-pass-1');
ct_ok('a locked account is not tried, even with its right password', !$r['ok'] && str_contains($r['message'], 'locked for another 15 minutes'));
ct_at('2027-02-01 09:16');
$r = $try('08031234567', 'Funmi-pass-1');
ct_ok('after the lock, the right password opens it', $r['ok'] && (int) $r['user']['id'] === 2);
$ph->prepare('UPDATE users SET password_hash = ?, failed_login_count = 0, locked_until = NULL WHERE id IN (1, 2)')->execute([$hash('Same-pass-1')]);
$r = $try('08031234567', 'Same-pass-1');
ct_ok('one number AND one password on two accounts: refused, use the email', !$r['ok'] && str_contains($r['message'], 'Sign in with your email'));
ct_eq('…and that counts against nobody', [0, 0], [$fails(1), $fails(2)]);

// The swap: a confirmed personal email takes the @hodlc.com name's place.
ct_confirm($ph, 5, 'Sola.A@gmail.com', false);
ct_eq('users.email takes the confirmed address', 'sola.a@gmail.com', $ph->query('SELECT email FROM users WHERE id = 5')->fetchColumn());
ct_eq('…and so does real_email (the personal-email column)', 'sola.a@gmail.com', $ph->query('SELECT real_email FROM users WHERE id = 5')->fetchColumn());
ct_eq('the old name is kept aside (any case)', 5, signin_retired_name_owner($ph, 'SOLA@hodlc.com'));
$c = signin_candidates($ph, 'sola@hodlc.com');
ct_ok('typing it finds them and says where they sign in now (after the right password, the API answers MOVED)',
    $ids($c['users']) === [5] && $c['via'] === 'retired' && $c['moved_to'] === 's•••@gmail.com');
$r = $try('sola.a@gmail.com', 'Sola-pass-1');
ct_ok('the personal email signs in', $r['ok'] && (int) $r['user']['id'] === 5);
$r = $try('0701 234 5678', 'Sola-pass-1');
ct_ok('…and so does the phone', $r['ok'] && (int) $r['user']['id'] === 5);
ct_eq('only @hodlc.com names are ever kept', null, signin_retired_name_owner($ph, 'sola.a@gmail.com'));

ct_user($ph, 6, 'Bola', 'Member', ['email' => 'bola@yahoo.com']);
ct_confirm($ph, 6, 'bola.new@gmail.com', false);
ct_eq('a personal address in users.email is never replaced', 'bola@yahoo.com', $ph->query('SELECT email FROM users WHERE id = 6')->fetchColumn());
ct_eq('…but real_email shows the confirmed one', 'bola.new@gmail.com', $ph->query('SELECT real_email FROM users WHERE id = 6')->fetchColumn());

ct_user($ph, 7, 'Kemi', 'Member', ['email' => 'kemi@hodlc.com']);
ct_user($ph, 8, 'Kemi', '1st_Timer', ['email' => 'kemi@gmail.com']);  // an old first-timer record with her address
ct_confirm($ph, 7, 'kemi@gmail.com', false);
ct_eq("an address that is another record's users.email: the name stays (users.email is unique)", 'kemi@hodlc.com',
    $ph->query('SELECT email FROM users WHERE id = 7')->fetchColumn());
ct_eq('…and is not kept aside', null, signin_retired_name_owner($ph, 'kemi@hodlc.com'));

// Everyone who confirmed before this deploy: db/backfill_signin_names.php.
ct_user($ph, 9, 'Tunde', 'Member', ['email' => 'tunde@hodlc.com']);
ct_confirm($ph, 9, 'tunde.b@gmail.com', false);
$ph->exec("UPDATE users SET email = 'tunde@hodlc.com', real_email = NULL WHERE id = 9");   // as it was before this deploy
$ph->exec("DELETE FROM auth_retired_signin_names WHERE user_id = 9");
ct_eq('dry run: counts, changes nothing', ['ready' => true, 'checked' => 2, 'moved' => 0, 'kept' => 0], signin_names_backfill($ph, false));
ct_eq('…still the old name', 'tunde@hodlc.com', $ph->query('SELECT email FROM users WHERE id = 9')->fetchColumn());
ct_eq('the backfill swaps Tunde and keeps Kemi (her address is taken)', ['ready' => true, 'checked' => 2, 'moved' => 1, 'kept' => 1], signin_names_backfill($ph));
ct_eq('…users.email is now the confirmed address', 'tunde.b@gmail.com', $ph->query('SELECT email FROM users WHERE id = 9')->fetchColumn());
ct_eq('…the old name is kept aside', 9, signin_retired_name_owner($ph, 'tunde@hodlc.com'));
ct_eq('run again: only the one it cannot move', ['ready' => true, 'checked' => 1, 'moved' => 0, 'kept' => 1], signin_names_backfill($ph));
$bare = ct_db(false);
ct_eq('before the migration: nothing to do, nothing thrown', false, signin_names_backfill($bare)['ready']);

// First password (auth/setup_password.php) by phone.
ct_user($ph, 10, 'Chika', 'Member', ['phone' => '08120000001']);
$pick = signin_setup_account($ph, '0812 000 0001');
ct_ok("a new member's first password, by phone", $pick['code'] === 'ok' && (int) $pick['user']['id'] === 10);
ct_user($ph, 11, 'Obi', 'Worker', ['phone' => '+2348120000001']);
ct_eq('two fresh accounts on one number: the first name is needed', 'name_needed', signin_setup_account($ph, '08120000001')['code']);
$pick = signin_setup_account($ph, '08120000001', ' OBI ');
ct_ok('…which tells them apart (any case)', $pick['code'] === 'ok' && (int) $pick['user']['id'] === 11);
ct_eq('…a name on neither finds nobody', 'none', signin_setup_account($ph, '08120000001', 'Bisi')['code']);
ct_eq('a first timer joins the app once promoted, not before', 'none', signin_setup_account($ph, '08077776666')['code']);
ct_eq('a number whose accounts all have passwords', 'has_password', signin_setup_account($ph, '08031234567')['code']);
ct_eq('by email, as before', 'has_password', signin_setup_account($ph, 'sola.a@gmail.com')['code']);
ct_eq('an unknown email', 'none', signin_setup_account($ph, 'nobody@gmail.com')['code']);

// Forgot password by phone: the link goes to the confirmed email.
$sent = email_reset_request($ph, '0701 234 5678', '5.5.5.5');
ct_eq('a phone number may ask for a reset link', true, $sent['sent']);
ct_eq('…which goes to the confirmed personal email', 1, ct_count($ph, 'email_outbox', "flow = 'password_reset' AND to_email = 'sola.a@gmail.com'"));
ct_eq('a number with no confirmed email: the same answer, nothing sent', false, email_reset_request($ph, '08031234567', '5.5.5.6')['sent']);

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
ct_ok('…carrying the link', str_contains((string) $resetMail['html'], 'https://hodlekki.org/auth/reset_password?t=' . $req['token']));
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

// A first email, not confirmed yet (owner, 26 Nov 2026).
ct_at('2027-03-01 14:00');
ct_user($p, 110, 'Tobi', 'Member');
email_capture_start($p, 110, 'Tobi.New@gmail.com', 'signin_prompt', 110, ['send' => false]);
ct_eq('a first, unconfirmed email stands in', 'tobi.new@gmail.com', member_first_pending_email($p, 110));
$first = email_reset_request($p, 'TOBI.NEW@gmail.com', '2.2.2.1');
ct_eq('…typing it asks for a link, and one goes', true, $first['sent']);
ct_eq('…to that address', 1, ct_count($p, 'email_outbox', "flow = 'password_reset' AND to_email = 'tobi.new@gmail.com'"));
ct_eq('…which signs nobody in by itself', null, email_signin_lookup($p, 'tobi.new@gmail.com')['user']);
ct_eq('using the link changes the password', true, email_reset_complete($p, $first['token'], $good, $good)['ok']);
ct_eq('…and confirms the address', 'tobi.new@gmail.com', member_confirmed_email($p, 110));
ct_eq('…which now signs in', 110, (int) (email_signin_lookup($p, 'tobi.new@gmail.com')['user']['id'] ?? 0));
ct_eq('…and gets the "password changed" notice', 1, ct_count($p, 'email_outbox', "flow = 'password_changed' AND to_email = 'tobi.new@gmail.com'"));

ct_user($p, 111, 'Kunle', 'Member', ['email' => 'kunle.old@yahoo.com']);
email_capture_start($p, 111, 'kunle.new@gmail.com', 'profile', 111, ['send' => false]);
ct_eq('another personal address on file: not a first email', null, member_first_pending_email($p, 111));
ct_eq('…typing the new one sends nothing', false, email_reset_request($p, 'kunle.new@gmail.com', '2.2.2.2')['sent']);
ct_eq('…nor does the one on file (it was never confirmed)', false, email_reset_request($p, 'kunle.old@yahoo.com', '2.2.2.3')['sent']);

ct_user($p, 112, 'Sade', 'Member', ['email' => 'sade@hodlc.com']);
email_capture_start($p, 112, 'sade.o@gmail.com', 'signin_prompt', 112, ['send' => false]);
ct_eq('a church sign-in name is not an email they gave', 'sade.o@gmail.com', member_first_pending_email($p, 112));
ct_eq('…asking by the sign-in name sends to the first email', true, email_reset_request($p, 'sade@hodlc.com', '2.2.2.4')['sent']);

ct_user($p, 113, 'Uche', 'Member');
ct_confirm($p, 113, 'uche.one@gmail.com', false);
email_capture_start($p, 113, 'uche.two@gmail.com', 'profile', 113, ['send' => false]);
ct_eq('someone who has had a confirmed email: the new one waits for its link', null, member_first_pending_email($p, 113));
ct_eq('…typing it sends nothing', false, email_reset_request($p, 'uche.two@gmail.com', '2.2.2.5')['sent']);

ct_user($p, 114, 'Ngozi', 'Member');
email_capture_start($p, 114, 'ngozi@gmail.com', 'connect', null, ['send' => false]);
ct_eq('an address from the public Connect card never stands in', null, member_first_pending_email($p, 114));

ct_user($p, 115, 'Ife', 'Member');
ct_user($p, 116, 'Dayo', 'Member');
email_capture_start($p, 115, 'family@gmail.com', 'profile', 115, ['send' => false]);
email_capture_start($p, 116, 'family@gmail.com', 'profile', 116, ['send' => false]);
ct_eq('one address waiting on two accounts: nobody is picked', null, member_by_first_pending_email($p, 'family@gmail.com'));
ct_eq('…and typing it sends nothing', false, email_reset_request($p, 'family@gmail.com', '2.2.2.6')['sent']);

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
ct_section('16f. The Automation tab (Embrace, Assimilation, Charis)');
// ===========================================================================
require_once __DIR__ . '/../../includes/campaigns/automation_tab.php';
$t = ct_db();
ct_at('2027-05-03 10:00');
ct_user($t, 300, 'Kemi', 'Worker', ['email' => 'kemi@hodlc.com']);
foreach (['embrace', 'assimilation', 'charis'] as $m) {
    $rule = automation_tab_rule($t, $m);
    ct_ok("{$m}: the rule exists on first look, switched OFF", $rule !== null && !$rule['is_enabled']);
}
$h = automation_tab_html($t, 'embrace');
ct_ok('Embrace panel: the journey, Off, Switch on, six weekly emails and both reminders',
    str_contains($h, 'First-timer journey') && str_contains($h, '>Off<') && str_contains($h, 'Switch on')
    && str_contains($h, 'Week 6') && str_contains($h, 'Midweek reminder') && str_contains($h, 'Sunday reminder')
    && substr_count($h, 'data-action="automation.preview"') === 8);
ct_ok('…no "start fresh" choice for a journey (it simply carries on)', !str_contains($h, 'Start fresh'));
$h = automation_tab_html($t, 'charis');
ct_ok('Charis panel: the ladder (person, team, pastor), statuses, the weekly check, Start fresh / Include them',
    str_contains($h, 'Absence follow-up') && str_contains($h, '>Person<') && str_contains($h, '>Team<') && str_contains($h, '>Pastor<')
    && str_contains($h, 'name="statuses[]" value="Member" checked') && str_contains($h, 'Weekly check')
    && str_contains($h, 'Start fresh') && str_contains($h, 'Include them'));
ct_ok('…only the person step has an email; the others have alerts', substr_count($h, 'data-action="automation.preview"') === 1
    && substr_count($h, 'Alert message') === 2);
ct_ok('…no overlap warning with the default audiences', !str_contains($h, 'Two rules cover'));
ct_eq('…and no runs yet', true, str_contains($h, 'No runs yet'));

$rule = automation_tab_rule($t, 'embrace');
$in = ['module' => 'embrace', 'version' => (string) $rule['version'], 'from_name' => 'Embrace Team', 'reply_to' => 'embrace@hodlekki.org',
       'stop_status' => '2nd_Timer', 'weeks' => '4',
       'steps' => ['midweek' => ['weekday' => '3', 'time' => '13:30', 'sms' => 'See you tomorrow, {{first_name}}!'],
                   'welcome' => ['weekday' => '1', 'time' => '09:00', 'series' => [1 => ['sms' => 'Week two, {{first_name}}.']]]]];
$r = automation_tab_action($t, 'embrace', 'save', $in, 300);
$saved = automation_rule_get($t, 'embrace');
ct_ok('saving the journey: who, when and the sender change', $r['status'] === 'success'
    && $saved['config']['audience'] === ['stop_status' => '2nd_Timer', 'weeks' => 4]
    && $saved['config']['from_name'] === 'Embrace Team' && $saved['config']['reply_to'] === 'embrace@hodlekki.org');
ct_ok('…a step\'s day, time and SMS change', automation_step($saved, 'midweek')['when'] === ['weekday' => 3, 'time' => '13:30']
    && automation_step($saved, 'midweek')['sms'] === 'See you tomorrow, {{first_name}}!'
    && automation_step($saved, 'welcome')['series'][1]['sms'] === 'Week two, {{first_name}}.');
ct_ok('…the emails themselves stay as they were', automation_step($saved, 'midweek')['email'] === automation_step($rule, 'midweek')['email']
    && automation_step($saved, 'welcome')['series'][0] === automation_step($rule, 'welcome')['series'][0]);
ct_ok('…and the panel comes back with it', str_contains($r['html'], 'value="13:30"') && str_contains($r['html'], 'Week two, {{first_name}}.'));
$stale = automation_tab_action($t, 'embrace', 'save', $in, 300);
ct_ok('saving over someone else\'s change is refused', $stale['status'] === 'error' && str_contains($stale['message'], 'Someone else changed'));
$bad = automation_tab_action($t, 'charis', 'save', ['module' => 'charis', 'statuses' => ['Member'], 'when_weekday' => '1', 'when_time' => '9am',
    'steps' => ['miss_note' => ['threshold' => '0']]], 300);
ct_ok('a bad time or threshold is refused with the reason, nothing saved', $bad['status'] === 'error'
    && str_contains($bad['message'], 'the time must look like 10:00') && automation_rule_get($t, 'charis')['config']['when']['time'] === '09:00');
$crule = automation_tab_rule($t, 'charis');
$ok = automation_tab_action($t, 'charis', 'save', ['module' => 'charis', 'statuses' => ['Member', 'Worker'], 'when_weekday' => '2', 'when_time' => '08:00',
    'steps' => ['miss_note' => ['threshold' => '3'], 'pastor_alert' => ['threshold' => '10', 'alert_title' => 'Away {{streak}} Sundays: {{full_name}}', 'alert_message' => 'Please call {{full_name}}.']]], 300);
$c = automation_rule_get($t, 'charis')['config'];
ct_ok('saving an absence rule: statuses, the weekly check, thresholds and an alert', $ok['status'] === 'success'
    && $c['audience']['statuses'] === ['Member', 'Worker'] && $c['when'] === ['weekday' => 2, 'time' => '08:00']
    && automation_step(['config' => $c], 'miss_note')['threshold'] === 3
    && automation_step(['config' => $c], 'pastor_alert')['alert'] === ['title' => 'Away {{streak}} Sundays: {{full_name}}', 'message' => 'Please call {{full_name}}.']);
automation_tab_action($t, 'assimilation', 'save', ['module' => 'assimilation', 'statuses' => ['Visitor', 'Worker'], 'when_weekday' => '1', 'when_time' => '09:00'], 300);
ct_eq('two absence rules covering one status are found', ['Worker'], automation_tab_overlap($t, 'charis'));
ct_ok('…and both tabs warn about it', str_contains(automation_tab_html($t, 'charis'), 'Two rules cover Worker')
    && str_contains(automation_tab_html($t, 'assimilation'), 'Untick them here or in Charis'));

$line = automation_tab_action($t, 'charis', 'switch_preview', [], 300);
ct_eq('"Switch on?" says who is past a step today', 'Nobody is past a step today.', $line['line'] ?? null);
ct_eq('…in words for many', '23 people are already past a step (14 by email, 7 by SMS, 2 unreachable).',
    automation_tab_switch_line(['people' => 23, 'emailed' => 14, 'texted' => 7, 'alerted' => 0, 'skipped' => 2]));
$on = automation_tab_action($t, 'charis', 'enable', ['start' => 'fresh'], 300);
$cr = automation_rule_get($t, 'charis');
ct_ok('switching on (start fresh): on, baseline time kept, the panel shows On and Switch off',
    $on['status'] === 'success' && $cr['is_enabled'] && $cr['baseline_at'] !== null
    && str_contains($on['html'], '>On<') && str_contains($on['html'], 'Switch off'));
automation_tab_action($t, 'charis', 'disable', [], 300);
automation_tab_action($t, 'charis', 'enable', ['start' => 'include'], 300);
ct_ok('switching off, then on with "include them": no baseline', automation_rule_get($t, 'charis')['is_enabled']
    && automation_rule_get($t, 'charis')['baseline_at'] === null);
automation_tab_action($t, 'charis', 'disable', [], 300);
ct_eq('…and off again', false, automation_rule_get($t, 'charis')['is_enabled']);

$viewer = email_person($t, 300);
$er = automation_tab_rule($t, 'embrace');
foreach (['embrace' => ['welcome', 'midweek', 'sunday'], 'charis' => ['miss_note']] as $m => $keys) {
    foreach ($keys as $k) {
        $out = automation_tab_render($t, automation_tab_rule($t, $m), $k, 2, $viewer);
        ct_ok("preview {$m}/{$k}: the real render, for the viewer, untracked",
            $out !== null && $out['ok'] && str_contains($out['html'], 'Kemi') && !str_contains($out['html'], '/email/c.php')
            && str_contains($out['html'], '/email/unsubscribe.php?r=preview'));
    }
}
$w2 = (string) automation_step($er, 'welcome')['series'][1]['email']['subject'];
ct_eq("preview of week 2 is week 2's email", str_replace('{{first_name}}', 'Kemi', $w2), automation_tab_render($t, $er, 'welcome', 2, $viewer)['subject']);
ct_ok('an absence preview fills the streak', str_contains(automation_tab_render($t, automation_tab_rule($t, 'charis'), 'miss_note', 1, $viewer)['html'], 'Kemi'));
ct_eq('an alert step has no email to preview', null, automation_tab_render($t, automation_tab_rule($t, 'charis'), 'team_alert', 1, $viewer));

$tt = automation_tab_action($t, 'embrace', 'test', ['step' => 'midweek'], 300);
ct_ok('a test needs the tester\'s confirmed email', $tt['status'] === 'error' && $tt['message'] === 'Confirm your email in Profile first.');
ct_confirm($t, 300, 'kemi.k@gmail.com', false);
$tt = automation_tab_action($t, 'embrace', 'test', ['step' => 'welcome', 'week' => '3'], 300);
$row = $t->query("SELECT * FROM email_outbox WHERE flow = 'automation_test' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
ct_ok('a test goes to the tester, marked as a test, in the test lane as account mail (no unsubscribe)',
    $tt['status'] === 'success' && $row && $row['to_email'] === 'kemi.k@gmail.com' && $row['lane'] === 'test'
    && $row['purpose'] === 'account' && str_starts_with((string) $row['subject'], 'Test: '));
ct_eq('…naming where it went', 'Test sent to k•••@gmail.com.', $tt['message']);

$t->exec("INSERT INTO automation_runs (rule_id, occasion_key, occasion_at, status, people, emailed, texted, alerted, skipped, started_at)
          VALUES ({$er['id']}, 'welcome:2027-05-03', '2027-05-03 10:00:00', 'done', 3, 1, 1, 0, 1, '2027-05-03 10:00:00')");
$runId = (int) $t->lastInsertId();
ct_user($t, 301, 'Ola', '1st_Timer');
ct_user($t, 302, 'Uche', '1st_Timer');
foreach ([[300, 'email', 'queued'], [301, 'sms', 'queued'], [302, 'none', 'skipped_no_contact']] as [$uid, $ch, $outcome]) {
    $t->prepare("INSERT INTO automation_sends (rule_id, step_key, user_id, dedupe_key, run_id, channel, outcome, created_at)
                 VALUES (?, 'welcome', ?, '1', ?, ?, ?, '2027-05-03 10:00:00')")->execute([$er['id'], $uid, $runId, $ch, $outcome]);
}
$h = automation_tab_html($t, 'embrace');
ct_ok('recent runs: when, and what happened', str_contains($h, 'Mon 3 May, 10:00') && str_contains($h, '3 people: 1 emailed, 1 texted, 1 skipped'));
$who = automation_tab_action($t, 'embrace', 'run_people', ['run' => (string) $runId], 300);
ct_eq('…and who: named, with the step and the result', [
    ['name' => 'Kemi Test', 'step' => 'Monday welcome', 'result' => 'Emailed'],
    ['name' => 'Ola Test', 'step' => 'Monday welcome', 'result' => 'Texted'],
    ['name' => 'Uche Test', 'step' => 'Monday welcome', 'result' => 'No email or phone'],
], $who['people'] ?? null);

ct_eq('SMS count: short', '3 characters · 1 text', automation_tab_sms_count('abc'));
ct_eq('SMS count: 161 plain characters are two texts', '161 characters · 2 texts', automation_tab_sms_count(str_repeat('a', 161)));
ct_eq('SMS count: an emoji makes it Unicode (70 a text)', '71 characters · 2 texts', automation_tab_sms_count(str_repeat('a', 70) . '🙏'));
ct_eq('an unknown module is refused', 'error', automation_tab_action($t, 'finance', 'panel', [], 300)['status']);
ct_eq('an unknown action is refused', 'error', automation_tab_action($t, 'embrace', 'drop_tables', [], 300)['status']);
ct_ok('before the migration: "Not set up yet", and actions refuse',
    str_contains(automation_tab_html(ct_db(false), 'charis'), 'Not set up yet')
    && automation_tab_action(ct_db(false), 'charis', 'enable', [], 300)['status'] === 'error');

$root = __DIR__ . '/../../';
$api = (string) file_get_contents($root . 'api/automation_api.php');
$prev = (string) file_get_contents($root . 'api/email_preview.php');
ct_ok('the API needs the module\'s manage right and the CSRF token',
    str_contains($api, "api_require_can(\$module, 'manage');") && str_contains($api, 'csrf_require();'));
ct_ok('the preview needs the manage right and sends its own locked-down CSP',
    str_contains($prev, "can(\$module, 'manage')") && str_contains($prev, "default-src 'none'; style-src 'unsafe-inline'")
    && str_contains((string) file_get_contents($root . 'includes/campaigns/automation_tab.php'), 'sandbox="" referrerpolicy="no-referrer"')
    && !preg_match('/\.srcdoc\b|srcdoc\s*=/', (string) file_get_contents($root . 'assets/ui/js/pages/automation.js')));
$emb = (string) file_get_contents($root . 'modules/embrace/index.php');
$asm = (string) file_get_contents($root . 'modules/assimilation/index.php');
$chr = (string) file_get_contents($root . 'modules/charis/index.php');
ct_ok('the tab shows only with the manage right: Embrace, Assimilation, Charis',
    str_contains($emb, "\$embraceAutomation = can('embrace', 'manage');") && str_contains($emb, "'frame-src' => [\"'self'\"]")
    && str_contains($asm, "\$assim_manager = can('assimilation', 'manage');") && str_contains($asm, "'automation' => ['label' => 'Automation'")
    && str_contains($asm, "<?php if (\$assim_manager): ?>\n    <section id=\"panel-automation\"") && str_contains($asm, "'frame-src' => [\"'self'\"]")
    && str_contains($chr, "(\$charisCanConfigure ? ['automation' => ['label' => 'Automation'")
    && str_contains($chr, "<?php if (\$charisCanConfigure): ?>\n    <section id=\"panel-automation\"") && str_contains($chr, "'frame-src' => [\"'self'\"]"));
ct_section('16g. The block editor: drafts, pictures, templates');
// ===========================================================================
require_once __DIR__ . '/../../includes/campaigns/editor.php';
$e = ct_db();
ct_at('2027-06-07 11:00');
$_SESSION['email_drafts'] = [];
$goodDoc = ['v' => 1, 'subject' => 'Hello {{first_name}}', 'preheader' => '', 'blocks' => [
    ['type' => 'heading', 'level' => 1, 'align' => 'left', 'text' => 'Welcome'],
    ['type' => 'text', 'align' => 'left', 'content' => [['k' => 'p', 'r' => [['t' => 'Hi '], ['t' => '{{first_name}}', 'b' => 1]]]]],
]];
$d = email_editor_draft_put($goodDoc);
ct_ok('a draft is kept, validated (the footer added), behind a token', preg_match('/^[A-Za-z0-9_-]{22}$/', $d['token']) === 1
    && $d['errors'] === [] && end(email_editor_draft_get($d['token'])['blocks'])['type'] === 'footer');
$bad = email_editor_draft_put(['v' => 1, 'subject' => '', 'blocks' => [['type' => 'script', 'src' => 'x']]]);
ct_ok('…a bad draft is kept too (the preview shows what it can) with its errors', $bad['errors'] !== []
    && email_editor_draft_get($bad['token'])['blocks'] === [['type' => 'footer']]);
ct_eq('…an unknown or malformed token finds nothing', [null, null], [email_editor_draft_get('nope'), email_editor_draft_get('../../etc')]);
for ($i = 0; $i < 6; $i++) {
    email_editor_draft_put($goodDoc);
}
ct_ok('…only the newest five are kept', count($_SESSION['email_drafts']) === 5 && email_editor_draft_get($d['token']) === null);

$tmp = sys_get_temp_dir() . '/hod-eml-' . bin2hex(random_bytes(4));
mkdir($tmp);
$make = static function (string $kind, int $w, int $h) use ($tmp): array {
    $img = imagecreatetruecolor($w, $h);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 30, 40));
    $path = $tmp . '/in-' . $kind . '-' . $w;
    match ($kind) {
        'png' => imagepng($img, $path),
        'jpg' => imagejpeg($img, $path),
        'gif' => imagegif($img, $path),
    };
    return ['name' => 'photo.php.' . $kind, 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK, 'type' => 'text/x-php'];
};
$root = $tmp . '/uploads-email';
$at = campaigns_dt('2027-06-07 11:00');
$png = email_editor_store_image($make('png', 300, 200), $root, $at);
ct_ok('a PNG is re-encoded as PNG under uploads/email/2027/06, named by us', $png['ok']
    && preg_match('#/uploads/email/2027/06/20270607-[0-9a-f]{16}\.png$#', (string) $png['src']) === 1
    && is_file($root . '/2027/06/' . basename((string) $png['src'])));
ct_ok('…the URL is one the email documents accept', email_doc_image_ok((string) $png['src']));
ct_ok('…and the folder runs nothing, serving only pictures', is_file($root . '/.htaccess')
    && str_contains((string) file_get_contents($root . '/.htaccess'), 'Require all denied'));
$big = email_editor_store_image($make('jpg', 2400, 1200), $root, $at);
ct_ok('a wide JPEG is shrunk to 1200 px (twice the email width)', $big['ok'] && $big['width'] === 1200 && $big['height'] === 600
    && str_ends_with((string) $big['src'], '.jpg'));
$gif = email_editor_store_image($make('gif', 100, 100), $root, $at);
ct_ok('a GIF becomes a JPEG (what every mail app shows)', $gif['ok'] && str_ends_with((string) $gif['src'], '.jpg'));
file_put_contents($tmp . '/fake', "<?php echo 'hi'; ?>\x89PNG");
ct_eq('a script dressed as a picture is refused', 'Use a JPG, PNG, GIF or WebP picture.',
    email_editor_store_image(['tmp_name' => $tmp . '/fake', 'size' => 30, 'error' => UPLOAD_ERR_OK], $root, $at)['message'] ?? null);
ct_eq('a picture over 5 MB is refused', 'That picture is too large (5 MB at most).',
    email_editor_store_image(['tmp_name' => $tmp . '/fake', 'size' => 6 * 1024 * 1024, 'error' => UPLOAD_ERR_OK], $root, $at)['message'] ?? null);
ct_eq('an upload error is said plainly', 'The picture did not arrive. Try again.',
    email_editor_store_image(['error' => UPLOAD_ERR_PARTIAL], $root, $at)['message'] ?? null);
ct_eq('only pictures are written (no copy of the upload, no client name)', 3, count(glob($root . '/2027/06/*')));

$t = email_template_save($e, '  Welcome  email ', $goodDoc, 300);
ct_ok('a template is saved with a tidy name', $t['ok'] && email_templates_list($e)[0]['name'] === 'Welcome email');
ct_eq('…and comes back as a clean document', 'Hello {{first_name}}', email_template_get($e, $t['id'])['doc']['subject']);
ct_ok('…an invalid document is not kept', !email_template_save($e, 'Broken', ['v' => 1, 'subject' => '', 'blocks' => []], 300)['ok']);
ct_eq('…a template needs a name', ['Give the template a name (120 characters at most).'], email_template_save($e, ' ', $goodDoc, 300)['errors']);
ct_ok('…saving over it updates it', email_template_save($e, 'Welcome (v2)', $goodDoc, 300, $t['id'])['ok']
    && email_templates_list($e)[0]['name'] === 'Welcome (v2)' && count(email_templates_list($e)) === 1);
ct_ok('…removing it hides it (kept, archived)', email_template_archive($e, $t['id'], 300) && email_templates_list($e) === []
    && email_template_get($e, $t['id']) === null && ct_count($e, 'email_templates') === 1);

$chk = email_editor_action($e, 'check', ['doc' => $goodDoc], 300);
ct_ok('"check" answers ok with the cleaned document', $chk['ok'] && end($chk['doc']['blocks'])['type'] === 'footer');
$chk = email_editor_action($e, 'check', ['doc' => ['v' => 1, 'subject' => 'x', 'blocks' => [['type' => 'button', 'label' => 'Go', 'href' => 'javascript:alert(1)']]]], 300);
ct_ok('…and refuses a javascript: link with the reason', !$chk['ok'] && in_array('Button (block 1) needs a link starting with https://.', $chk['errors'], true));
ct_eq('an unknown editor action is refused', 'error', email_editor_action($e, 'drop', [], 300)['status']);

$root2 = __DIR__ . '/../../';
$eapi = (string) file_get_contents($root2 . 'api/email_editor_api.php');
$dprev = (string) file_get_contents($root2 . 'api/email_draft_preview.php');
ct_ok('the editor API needs an Automation manage right and the CSRF token',
    str_contains($eapi, 'if (!email_editor_allowed()) {') && str_contains($eapi, 'csrf_require();'));
ct_ok('the draft preview needs it too, with its own locked-down CSP',
    str_contains($dprev, 'if (!email_editor_allowed()) {') && str_contains($dprev, "default-src 'none'; style-src 'unsafe-inline'"));
$js = (string) file_get_contents($root2 . 'assets/ui/js/email-editor.js');
ct_ok('the editor pastes plain text only, previews in a sandboxed frame, never srcdoc',
    str_contains($js, "getData('text/plain')") && str_contains($js, 'sandbox=""') && !preg_match('/\.srcdoc\b|srcdoc\s*=/', $js)
    && !preg_match('/\.innerHTML\s*=\s*[^;]*getData\(/', $js));
array_map('unlink', glob($root . '/2027/06/*') ?: []);
@unlink($root . '/.htaccess');
@rmdir($root . '/2027/06'); @rmdir($root . '/2027'); @rmdir($root);
array_map('unlink', glob($tmp . '/*') ?: []);
@rmdir($tmp);

// ===========================================================================
ct_section('16h. Editing a step\'s email from the Automation tab');
// ===========================================================================
$w = ct_db();
ct_at('2027-06-08 09:00');
$wr = automation_tab_rule($w, 'embrace');
ct_eq('every email step has an Edit button (six weeks and two reminders)', 8,
    substr_count(automation_tab_html($w, 'embrace'), 'data-action="automation.edit"'));
ct_ok('…and the panel carries the editor dialog', str_contains(automation_tab_html($w, 'embrace'), 'data-automation-editor-root'));
$got = automation_tab_action($w, 'embrace', 'email_doc', ['step' => 'welcome', 'week' => '3'], 300);
ct_ok('the editor gets week 3\'s email and the rule version', $got['status'] === 'success'
    && $got['doc'] === automation_step($wr, 'welcome')['series'][2]['email'] && $got['version'] === (int) $wr['version']);
$newDoc = $got['doc'];
$newDoc['subject'] = 'Week three, {{first_name}}';
$sv = automation_tab_action($w, 'embrace', 'save_email', ['step' => 'welcome', 'week' => 3, 'version' => $got['version'], 'doc' => $newDoc], 300);
$after = automation_rule_get($w, 'embrace');
ct_ok('saving it changes week 3 only, and answers with the panel', $sv['status'] === 'success' && str_contains($sv['html'], 'Week three, {{first_name}}')
    && automation_step($after, 'welcome')['series'][2]['email']['subject'] === 'Week three, {{first_name}}'
    && automation_step($after, 'welcome')['series'][1] === automation_step($wr, 'welcome')['series'][1]
    && automation_step($after, 'midweek') === automation_step($wr, 'midweek'));
$stale = automation_tab_action($w, 'embrace', 'save_email', ['step' => 'welcome', 'week' => 3, 'version' => $got['version'], 'doc' => $newDoc], 300);
ct_ok('saving over someone else\'s change is refused', $stale['status'] === 'error' && str_contains($stale['message'], 'Someone else changed'));
$badDoc = $newDoc;
$badDoc['blocks'][] = ['type' => 'button', 'label' => 'Go', 'href' => 'javascript:alert(1)'];
$bad = automation_tab_action($w, 'embrace', 'save_email', ['step' => 'midweek', 'week' => 1, 'version' => (int) $after['version'], 'doc' => $badDoc], 300);
ct_ok('an invalid email is refused with the reason, nothing saved', $bad['status'] === 'error'
    && str_contains($bad['message'], 'needs a link starting with https://') && automation_step(automation_rule_get($w, 'embrace'), 'midweek') === automation_step($wr, 'midweek'));
ct_eq('an alert step has no email to edit', 'error', automation_tab_action($w, 'charis', 'email_doc', ['step' => 'team_alert'], 300)['status']);
ct_eq('…nor to save into', 'error', automation_tab_action($w, 'charis', 'save_email', ['step' => 'pastor_alert', 'doc' => $newDoc], 300)['status']);
ct_eq('a week past the series is refused', 'error', automation_tab_action($w, 'embrace', 'save_email', ['step' => 'welcome', 'week' => 9, 'doc' => $newDoc], 300)['status']);
$aapi = (string) file_get_contents(__DIR__ . '/../../api/automation_api.php');
ct_ok('the tab API reads JSON (the editor\'s document) as well as forms, behind the same gate',
    str_contains($aapi, "stripos((string) (\$_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') === 0")
    && strpos($aapi, "api_require_can(\$module, 'manage');") < strpos($aapi, 'echo json_encode(automation_tab_action('));
foreach (['embrace', 'assimilation', 'charis'] as $m) {
    $src = (string) file_get_contents(__DIR__ . '/../../modules/' . $m . '/index.php');
    ct_ok("{$m} loads the editor before the tab script",
        strpos($src, '/assets/ui/js/email-editor.js') !== false && strpos($src, '/assets/ui/js/email-editor.js') < strpos($src, '/assets/ui/js/pages/automation.js'));
}

// ===========================================================================
ct_section('16i. The Connect card: blank optional fields keep the column default');
// ===========================================================================
require_once __DIR__ . '/../../includes/embrace_helpers.php';
$cx = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
// As in the live schema: the address is NOT NULL with a default; the rest allow NULL.
$cx->exec("CREATE TABLE connect_users (id INTEGER PRIMARY KEY, first_name TEXT NOT NULL, last_name TEXT, email TEXT UNIQUE, phone TEXT,
    gender TEXT, dob TEXT, marital_status TEXT, physical_address TEXT NOT NULL DEFAULT 'To be updated', latitude REAL, longitude REAL,
    spiritual_status TEXT, invited_by TEXT, wants_to_join INTEGER, visitation_preference TEXT, prayer_requests TEXT, qr_code_hash TEXT)");
$card = ['first_name' => 'Ada', 'last_name' => 'Obi', 'email' => '', 'phone' => '2348030000001', 'gender' => '', 'dob' => '',
    'marital_status' => 'Single', 'physical_address' => '', 'latitude' => null, 'longitude' => null, 'spiritual_status' => '1st_Timer',
    'invited_by' => '', 'wants_to_join' => 0, 'visitation_preference' => 'None', 'prayer_requests' => '', 'qr_code_hash' => 'h1'];
[$sql, $params] = embrace_connect_insert($card, 'connect_users');
ct_ok('a blank address, email, gender, date of birth and coordinates are left out of the INSERT',
    !str_contains($sql, 'physical_address') && !str_contains($sql, 'email') && !str_contains($sql, 'gender')
    && !str_contains($sql, 'dob') && !str_contains($sql, 'latitude') && !in_array('', array_slice($params, 0, 3), true));
$cx->prepare($sql)->execute($params);
$row = $cx->query("SELECT * FROM connect_users WHERE qr_code_hash = 'h1'")->fetch();
ct_ok('…so the card saves, the address taking the column default and the email NULL',
    $row['physical_address'] === 'To be updated' && $row['email'] === null && $row['gender'] === null && $row['spiritual_status'] === '1st_Timer');
[$sql, $params] = embrace_connect_insert(['physical_address' => '12 Admiralty Way, Lekki', 'email' => 'ada@gmail.com', 'qr_code_hash' => 'h2', 'first_name' => 'Ada'] + $card, 'connect_users');
$cx->prepare($sql)->execute($params);
ct_eq('a filled-in address and email are saved as given', ['12 Admiralty Way, Lekki', 'ada@gmail.com'],
    array_values($cx->query("SELECT physical_address, email FROM connect_users WHERE qr_code_hash = 'h2'")->fetch()));
ct_ok('…while required fields are always sent, even empty (invited_by, prayer)',
    str_contains($sql, 'invited_by') && str_contains($sql, 'prayer_requests'));
$threw = false;
try { embrace_connect_insert(['first_name; DROP TABLE users' => 'x']); } catch (InvalidArgumentException $e) { $threw = true; }
ct_ok('a column name that is not a plain identifier is refused', $threw);
$papi = (string) file_get_contents(__DIR__ . '/../../api/embrace_public_api.php');
ct_ok('the Connect API builds its INSERT this way (no explicit NULL for the address)',
    str_contains($papi, '[$sql, $params] = embrace_connect_insert([') && !str_contains($papi, "\$address !== '' ? \$address : null"));

// ===========================================================================
ct_section("16j. The bounce reader (system@'s inbox)");
// ===========================================================================
require_once __DIR__ . '/../../includes/mail/bootstrap.php';
require_once __DIR__ . '/../mail/support/FakeImapSession.php';
require_once __DIR__ . '/../mail/support/FakeMailServer.php';
$bx = ct_db();
ct_at('2027-07-01 10:00');
ct_user($bx, 500, 'Bola', 'Member');
ct_user($bx, 501, 'Chi', 'Member');
ct_confirm($bx, 500, 'bola.b@gmail.com', true);
ct_confirm($bx, 501, 'chi.c@gmail.com', true);
$sendTo = static fn (int $uid, string $to): array => email_send_doc($bx, ct_doc(), email_person($bx, $uid) + ['email' => $to],
    ['lane' => 'automation', 'purpose' => 'news', 'flow' => 'bounce_test', 'to_email' => $to]);
$o1 = $sendTo(500, 'bola.b@gmail.com');
$o2 = $sendTo(501, 'chi.c@gmail.com');
$bx->exec("UPDATE email_outbox SET status = 'sent', sent_at = '2027-07-01 09:00:00'");
$refOf = static fn (int $id): string => (string) $bx->query('SELECT ref FROM email_outbox WHERE id = ' . $id)->fetchColumn();
$dsn = static fn (string $to, string $ref, string $status, string $diag): string =>
    "From: Mail Delivery System <MAILER-DAEMON@mx.example>\r\nTo: system@hodlekki.org\r\nSubject: Undelivered Mail\r\n"
    . "Content-Type: multipart/report; report-type=delivery-status; boundary=\"B\"\r\n\r\n"
    . "--B\r\nContent-Type: text/plain\r\n\r\nYour message could not be delivered.\r\n"
    . "--B\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mx.example\r\n\r\n"
    . "Final-Recipient: rfc822; {$to}\r\nAction: failed\r\nStatus: {$status}\r\nDiagnostic-Code: smtp; {$diag}\r\n"
    . "--B\r\nContent-Type: text/rfc822-headers\r\n\r\nFrom: system@hodlekki.org\r\nTo: {$to}\r\nX-HOD-Ref: {$ref}\r\n--B--\r\n";
$server = \HOD\Mail\Tests\FakeMailServer::install();
$server->addMailbox('system@hodlekki.org', 'pw');
$letter = static function (string $raw, string $subject = 'Undelivered Mail'): array {
    $m = \HOD\Mail\Tests\FakeMailServer::message(from: 'MAILER-DAEMON@mx.example', to: 'system@hodlekki.org', subject: $subject, text: 'x');
    $m['raw'] = $raw;
    return $m;
};
$box = 'system@hodlekki.org';
$server->deliver($box, 'INBOX', $letter($dsn('bola.b@gmail.com', $refOf((int) $o1['id']), '5.1.1', '550 5.1.1 user unknown')));
$server->deliver($box, 'INBOX', $letter($dsn('chi.c@gmail.com', $refOf((int) $o2['id']), '5.2.2', '552 5.2.2 mailbox full')));
$server->deliver($box, 'INBOX', $letter($dsn('victim@gmail.com', str_repeat('Z', 22), '5.1.1', '550 user unknown')));
$server->deliver($box, 'INBOX', \HOD\Mail\Tests\FakeMailServer::message(from: 'Ada <ada@gmail.com>', to: $box, subject: 'Hello', text: 'Is this the church?'));
$server->deliver($box, 'INBOX', $letter($dsn('bola.b@gmail.com', $refOf((int) $o1['id']), '5.1.1', '550 again')), ['\Seen']);
$imap = $server->connect($box, 'pw');

$d = email_bounces_run($bx, $imap, ['dry' => true]);
ct_eq('dry run: the four unread messages, sorted by what they are', [4, 1, 1, 1, 1],
    [$d['read'], $d['hard'], $d['soft'], $d['unmatched'], $d['other']]);
ct_ok('…and nothing changes: no suppression, every message still unread, no Bounces folder',
    !email_is_suppressed($bx, 'bola.b@gmail.com') && count(array_filter($server->folder($box, 'INBOX'), static fn ($m) => !in_array('\Seen', $m['flags'], true))) === 4
    && !isset($server->mailboxes[$box]['folders'][EMAIL_BOUNCES_FOLDER]));

$r = email_bounces_run($bx, $imap);
ct_eq('the run: 1 hard, 1 soft, 1 unmatched (a report about an email we never sent), 1 other', [4, 1, 1, 1, 1, 0],
    [$r['read'], $r['hard'], $r['soft'], $r['unmatched'], $r['other'], $r['failed']]);
ct_ok('…the hard bounce suppresses the address and marks our email', email_is_suppressed($bx, 'bola.b@gmail.com')
    && $bx->query('SELECT bounced_at FROM email_outbox WHERE id = ' . (int) $o1['id'])->fetchColumn() !== null);
ct_ok('…a full mailbox is only counted (soft), not suppressed', !email_is_suppressed($bx, 'chi.c@gmail.com')
    && ct_count($bx, 'email_events', "outbox_id = ? AND kind = 'bounce'", [(int) $o2['id']]) === 1);
ct_eq('…a forged report touches nobody', false, email_is_suppressed($bx, 'victim@gmail.com'));
ct_ok('…the three reports are filed in INBOX.Bounces; the letter from a person stays, read', $r['filed']
    && count($server->folder($box, EMAIL_BOUNCES_FOLDER)) === 3
    && count($server->folder($box, 'INBOX')) === 2
    && count(array_filter($server->folder($box, 'INBOX'), static fn ($m) => in_array('\Seen', $m['flags'], true))) === 2);
ct_eq('a second run finds nothing new', 0, email_bounces_run($bx, $imap)['read']);
for ($i = 0; $i < 3; $i++) {
    $server->deliver($box, 'INBOX', \HOD\Mail\Tests\FakeMailServer::message(from: 'x@y.example', to: $box, subject: 'Note ' . $i, text: 'hi'));
}
$lim = email_bounces_run($bx, $imap, ['limit' => 2]);
ct_eq('a limit is honoured, the rest waits for the next run', [2, 1], [$lim['read'], $lim['left']]);
ct_ok('the line for the log says it all', str_contains(email_bounces_line($r), 'read 4: 1 hard, 1 soft, 0 complaint(s)')
    && str_contains(email_bounces_line($lim), '1 left for the next run'));
$cron = (string) file_get_contents(__DIR__ . '/../../cron/email_bounces.php');
ct_ok('the cron: CLI only, one run at a time, --dry-run, the sending account, no password printed',
    str_contains($cron, "if (PHP_SAPI !== 'cli')") && str_contains($cron, 'campaigns_lock($pdo, EMAIL_BOUNCES_LOCK, 0)')
    && str_contains($cron, "'--dry-run'") && str_contains($cron, "site_env('MAIL_PASSWORD')") && !preg_match('/\$say\([^)]*\$pass/', $cron));
\HOD\Mail\MailServer::use(null);

// ===========================================================================
ct_section('16k. Email campaigns: audience, who gets what, sending, the report, access');
// ===========================================================================
require_once __DIR__ . '/../../includes/campaigns/campaign_actions.php';
$g = ct_db();
ct_at('2027-03-01 09:00');
$g->exec(perm_test_migration('20261207090100_perm_campaigns.sql'));
perm_use_pdo($g);
$g->exec("INSERT INTO departments (id, name) VALUES (11, 'Charis Care')");
$g->exec('UPDATE departments SET parent_id = 10 WHERE id = 11');
// 1001-1010: one of each way a person can (not) be reached.
ct_user($g, 1001, 'Ada', 'Member', ['phone' => '08030000001', 'region_id' => 1]);
ct_user($g, 1002, 'Ben', 'Visitor', ['phone' => '08030000002', 'region_id' => 1]);
ct_user($g, 1003, 'Cy', 'Visitor', ['phone' => '08030000003']);
ct_user($g, 1004, 'Dee', 'Member', ['phone' => '08030000004']);
ct_user($g, 1005, 'Eve', 'Member', ['phone' => '08030000005']);
ct_user($g, 1006, 'Fay', 'Member', ['phone' => '08030000006']);
ct_user($g, 1007, 'Gus', 'Member');
ct_user($g, 1008, 'Hal', 'Member', ['phone' => '08030000008']);
ct_user($g, 1009, 'Ivy', 'Member', ['phone' => '08030000009', 'attendance_status' => 'Relocated', 'region_id' => 1]);
ct_user($g, 1010, 'Jo', 'Member', ['phone' => '08030000010']);
ct_confirm($g, 1001, 'ada@example.com', false);           // a member: news by email without a box
ct_confirm($g, 1002, 'ben@example.com', true);            // a visitor who said yes
ct_confirm($g, 1003, 'cy@example.com', false);            // a visitor who did not tick the box
ct_confirm($g, 1004, 'dee@example.com', true);
email_consent_record($g, 1004, 'withdrawn', 'one_click', ['email' => 'dee@example.com']);
ct_confirm($g, 1005, 'eve@example.com', true);
email_suppress($g, 'eve@example.com', 'hard_bounce', 'test');
$g->exec("INSERT INTO sms_suppression (phone) VALUES ('2348030000008')");
email_consent_record($g, 1010, 'withdrawn', 'staff', ['channel' => 'sms']);
foreach ([1001, 1002, 1003] as $uid) {
    $g->prepare("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (?, 10, 1, 'Worker')")->execute([$uid]);
}
$g->exec("INSERT INTO user_departments (user_id, department_id, is_active, role_in_dept) VALUES (1004, 11, 1, 'Worker'), (1005, 10, 0, 'Worker')");

$people = campaign_people_rows($g, range(1001, 1010));
$withText = campaign_reach($g, $people, true);
$emailOnly = campaign_reach($g, $people, false);
$chan = static fn (array $r): array => array_map(static fn ($x) => $x['channel'] . (isset($x['reason']) ? ':' . $x['reason'] : ''), $r);
ct_eq('who gets what, with a text for the rest', [
    1001 => 'email', 1002 => 'email', 1003 => 'sms', 1004 => 'none:unsubscribed', 1005 => 'sms',
    1006 => 'sms', 1007 => 'none:no_phone', 1008 => 'none:sms_blocked', 1009 => 'sms', 1010 => 'none:unsubscribed',
], $chan($withText));
ct_eq('…and email only', [
    1001 => 'email', 1002 => 'email', 1003 => 'none:no_consent', 1004 => 'none:unsubscribed', 1005 => 'none:bounced',
    1006 => 'none:no_email', 1007 => 'none:no_email', 1008 => 'none:no_email', 1009 => 'none:no_email', 1010 => 'none:no_email',
], $chan($emailOnly));
$disagree = [];
foreach ($people as $uid => $p) {
    $may = email_may_send_news($g, $uid)['ok'];
    $auto = automation_choose_channel($g, $p, ['step' => ['key' => 'x', 'sms' => 'Hi']])['channel'];
    if ($may !== ($emailOnly[$uid]['channel'] === 'email') || $auto !== $withText[$uid]['channel']) {
        $disagree[] = $uid . ': may=' . json_encode($may) . ' auto=' . $auto . ' batch=' . $withText[$uid]['channel'];
    }
}
ct_eq('the batch agrees with email_may_send_news() and automation_choose_channel() for every person', [], $disagree);
$probeFile = tempnam(sys_get_temp_dir(), 'hodsms') . '.php';
file_put_contents($probeFile, '<?php $_ENV["SMS_VAULT_KEY"] = str_repeat("0", 64); require ' . var_export(realpath(__DIR__ . '/../../includes/campaigns/bootstrap.php'), true)
    . '; echo function_exists("sms_normalize_phone") ? "loaded" : "not loaded", " ", automation_sms_phone("08030000001");');
$probe = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($probeFile));
@unlink($probeFile);
ct_eq('a web request that never loaded SMS Studio still recognises a mobile number', 'not loaded 2348030000001', trim($probe));

ct_ok('everyone: people who left are never in it', !in_array(1009, campaign_audience_ids($g, ['kind' => 'church']), true)
    && in_array(1010, campaign_audience_ids($g, ['kind' => 'church']), true));
ct_eq('by status', [1002, 1003], campaign_audience_ids($g, ['kind' => 'statuses', 'statuses' => ['Visitor']]));
ct_eq('a department takes in its sub-units and only active members', [901, 1001, 1002, 1003, 1004],
    campaign_audience_ids($g, ['kind' => 'departments', 'ids' => [10]]));
ct_eq('a regional hub', [1001, 1002], campaign_audience_ids($g, ['kind' => 'regions', 'ids' => [1]]));
ct_eq('an unknown kind is nobody', [], campaign_audience_ids($g, ['kind' => 'everyone; DROP TABLE users']));

$dept = static fn (array $a, ?int $d) => campaign_audience_check($a, $d, false, [10, 11]);
ct_ok('a department sender cannot send to everyone', !$dept(['kind' => 'church'], 10)['ok']);
ct_ok('…nor to another department', !$dept(['kind' => 'departments', 'ids' => [20]], 10)['ok']);
ct_ok('…nor without saying which department sends it', !$dept(['kind' => 'departments', 'ids' => [10]], null)['ok']);
ct_ok('…but to their own department, yes', $dept(['kind' => 'departments', 'ids' => [10]], 10)['ok']);
ct_ok('the whole church may pick any audience', campaign_audience_check(['kind' => 'regions', 'ids' => [1]], null, true, [])['ok']);

$sum = campaign_summary($g, ['kind' => 'statuses', 'statuses' => ['Member', 'Visitor']], true);
ct_eq('the summary before Send (Ivy left: not counted)', ['total' => 9, 'email' => 2, 'sms' => 3, 'none' => 4],
    ['total' => $sum['total'], 'email' => $sum['email'], 'sms' => $sum['sms'], 'none' => $sum['none']]);
ksort($sum['reasons']);
ct_eq('…with why the rest are not reached', ['no_phone' => 1, 'sms_blocked' => 1, 'unsubscribed' => 2], $sum['reasons']);
ct_eq('…and how long it takes at the hour\'s budget', 'within the hour', $sum['eta']);

// Write, schedule, expand, send, finish.
$new = campaign_save($g, null, ['name' => 'Harvest Sunday', 'audience' => ['kind' => 'statuses', 'statuses' => ['Member', 'Visitor']],
    'sms_fallback' => 1, 'sms_text' => 'Hi {{first_name}}, Harvest Sunday is on 7 March.', 'track_opens' => 1], 900, true, []);
ct_ok('a campaign starts as a draft', $new['ok'] && campaign_get($g, $new['id'])['status'] === 'draft');
$cid = $new['id'];
ct_ok('…an empty email cannot go out', in_array('The email is empty.', campaign_problems($g, campaign_get($g, $cid)), true));
ct_ok('a text was asked for but is blank: refused', !campaign_save($g, $cid, ['name' => 'X', 'audience' => ['kind' => 'church'], 'sms_fallback' => 1], 900, true, [])['ok']);
ct_ok('the email is saved through the sanitiser', campaign_save_doc($g, $cid, ct_doc(), 900)['ok']
    && campaign_get($g, $cid)['subject'] === 'Hello {{first_name}}' && campaign_problems($g, campaign_get($g, $cid)) === []);
ct_eq("a stale form does not overwrite someone else's change", 'STALE',
    campaign_save($g, $cid, ['name' => 'Y', 'audience' => ['kind' => 'church']], 900, true, [], '2000-01-01 00:00:00')['code'] ?? null);
ct_ok('a time in the past is refused', !campaign_send($g, $cid, campaigns_now()->modify('-1 hour'), 900)['ok']);
ct_ok('…and one too far ahead', !campaign_send($g, $cid, campaigns_now()->modify('+90 days'), 900)['ok']);
$at = campaigns_now()->modify('+1 day');
ct_ok('scheduled for tomorrow', campaign_send($g, $cid, $at, 900)['ok'] && campaign_get($g, $cid)['status'] === 'scheduled');
ct_eq('not due yet: the tick leaves it', 0, campaigns_tick($g)['expanded']);
ct_at('2027-03-02 09:01');
ct_eq('due: the tick expands it once', ['expanded' => 1, 'finished' => 0, 'failed' => 0], campaigns_tick($g));
ct_eq('…a second tick finds nothing to do', 0, campaigns_tick($g)['expanded']);
ct_eq('…and a racing expansion is refused', null, campaign_expand($g, $cid));
$c = campaign_get($g, $cid);
ct_eq('the counts', ['sending', 9, 2, 3, 4], [$c['status'], $c['total'], $c['emailed'], $c['texted'], $c['skipped']]);
ct_eq('one church-news email per address, in the campaign lane, opens counted', 2,
    ct_count($g, 'email_outbox', "campaign_id = ? AND lane = 'campaign' AND purpose = 'news' AND html LIKE '%/email/o.php%'", [$cid]));
ct_eq('every person once, with their channel', 9, ct_count($g, 'email_campaign_people', 'campaign_id = ?', [$cid]));
ct_ok('the texts went to SMS Studio as one campaign, never to sms_log',
    ct_count($g, 'sms_campaigns', "id = ? AND audience = 'email_campaign' AND total = 3", [$c['sms_campaign_id']]) === 1
    && ct_count($g, 'sms_queue', 'campaign_id = ?', [$c['sms_campaign_id']]) === 3 && ct_count($g, 'sms_log') === 0);
ct_ok('…with the name left for SMS Studio to fill', str_contains((string) $g->query('SELECT body_template FROM sms_campaigns WHERE id = ' . (int) $c['sms_campaign_id'])->fetchColumn(), '{{first_name}}'));
ct_eq('…and each text linked to its person', 3, ct_count($g, 'email_campaign_people', "campaign_id = ? AND channel = 'sms' AND sms_queue_id IS NOT NULL", [$cid]));
$locked = campaign_save_doc($g, $cid, ct_doc(), 900);
ct_ok('a campaign that is going out can no longer change', !$locked['ok'] && ($locked['code'] ?? '') === 'LOCKED');

$tx = ct_transport();
email_worker_run($g, ['endpoints_ready' => true, 'transport' => $tx, 'now' => campaigns_now()]);
ct_eq('the worker sends both emails', 2, count($tx['sent']));
ct_ok('…with one-click unsubscribe and bulk headers', str_contains(json_encode($tx['sent'][0]), 'List-Unsubscribe-Post')
    && str_contains(json_encode($tx['sent'][0]), 'Precedence'));
ct_eq('then the tick marks it sent', 1, campaigns_tick($g)['finished']);
ct_eq('…sent', 'sent', campaign_get($g, $cid)['status']);

$refs = $g->query("SELECT ref FROM email_outbox WHERE campaign_id = {$cid} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
$g->exec("UPDATE email_outbox SET links_json = '[\"https://hodlekki.org/harvest\"]' WHERE campaign_id = {$cid}");
email_track_click($g, $refs[0], 0);
email_track_click($g, $refs[0], 0);
email_track_open($g, $refs[1]);
email_unsubscribe($g, $refs[1]);
$rep = campaign_report($g, campaign_get($g, $cid));
ct_eq('the report: sent, opened, clicked, unsubscribed', ['sent' => 2, 'opened' => 1, 'clicked' => 1, 'unsubscribed' => 1],
    array_intersect_key($rep['email'], ['sent' => 1, 'opened' => 1, 'clicked' => 1, 'unsubscribed' => 1]));
ct_eq('…clicks per link count people, not clicks', [['url' => 'https://hodlekki.org/harvest', 'people' => 1]], $rep['links']);
ct_eq('…texts by status', ['queued' => 3], $rep['sms']);
$byName = array_column($rep['people'], 'result', 'name');
ct_eq("…and every person's line", ['Ada Test' => 'Clicked', 'Ben Test' => 'Opened', 'Cy Test' => 'Text waiting',
    'Dee Test' => 'Unsubscribed', 'Gus Test' => 'No mobile number', 'Hal Test' => 'Number blocked'],
    array_intersect_key($byName, array_flip(['Ada Test', 'Ben Test', 'Cy Test', 'Dee Test', 'Gus Test', 'Hal Test'])));
ct_ok('a sent campaign cannot be deleted', !campaign_delete($g, $cid));

// Stop one that is going out.
$two = campaign_duplicate($g, campaign_get($g, $cid), 900);
ct_ok('a copy is a fresh draft with the same email', campaign_get($g, $two)['status'] === 'draft'
    && campaign_get($g, $two)['doc']['subject'] === 'Hello {{first_name}}' && str_starts_with(campaign_get($g, $two)['name'], 'Copy of'));
campaign_send($g, $two, null, 900);
ct_eq('send now: queued for the next tick', 'queued', campaign_get($g, $two)['status']);
campaigns_tick($g);
$stop = campaign_cancel($g, $two, 900);
ct_eq('stopping it holds back what has not left', [true, 1, 3, 'cancelled'],
    [$stop['ok'], $stop['emails'], $stop['texts'], campaign_get($g, $two)['status']]);
$tx2 = ct_transport();
email_worker_run($g, ['endpoints_ready' => true, 'transport' => $tx2, 'now' => campaigns_now()]);
ct_eq('…and the worker sends none of it', 0, count($tx2['sent']));

// A campaign that cannot be expanded fails, and comes back as a draft.
$three = campaign_duplicate($g, campaign_get($g, $cid), 900);
$g->prepare("UPDATE email_campaigns SET status = 'queued', doc_json = '{}' WHERE id = ?")->execute([$three]);
$failRun = campaigns_tick($g);
ct_eq('a broken campaign fails without sending anything', [1, 'failed', 0],
    [$failRun['failed'], campaign_get($g, $three)['status'], ct_count($g, 'email_outbox', 'campaign_id = ?', [$three])]);
ct_ok('…and goes back to a draft with Edit', campaign_unschedule($g, $three, 900) && campaign_get($g, $three)['status'] === 'draft');
ct_ok('…which can be deleted', campaign_delete($g, $three) && campaign_get($g, $three) === null);

// The test send: account mail to yourself.
ct_eq('a test needs your own confirmed email', 'Confirm your email in Profile first.', campaign_test_send($g, campaign_get($g, $cid), 902)['message']);
ct_confirm($g, 902, 'paul@example.com', false);
$test = campaign_test_send($g, campaign_get($g, $cid), 902);
ct_ok('…then it is sent, marked as a test, without an unsubscribe link', $test['ok']
    && ct_count($g, 'email_outbox', "lane = 'test' AND purpose = 'account' AND to_email = 'paul@example.com' AND subject LIKE 'Test: %'") === 1);

// Access, through the real permission engine (migration 20261207090100).
$as = static function (int $uid): void {
    $_SESSION['user_id'] = $uid;
    perm_reset();
};
ct_eq('the grants: three roles and three seats', 6, ct_count($g, 'perm_grants', "module_key = 'campaigns'"));
$g->exec(perm_test_migration('20261207090100_perm_campaigns.sql'));
ct_eq('…and the migration re-runs as a no-op', 6, ct_count($g, 'perm_grants', "module_key = 'campaigns'"));
$as(900);
ct_eq('the Resident Pastor sends to the whole church', ['church' => true, 'depts' => []], campaign_sender());
ct_eq('…and sees every campaign', 2, count(campaign_action($g, 'list', [], 900)['campaigns']));
ct_eq('…but email settings are Super Admin only', 'FORBIDDEN', campaign_action($g, 'settings', [], 900)['code'] ?? null);
$as(901);
ct_eq('a Charis HOD sends for Charis and its sub-unit', ['church' => false, 'depts' => [10, 11]], campaign_sender());
$own = campaign_action($g, 'save', ['name' => 'Charis night', 'department_id' => 10, 'audience' => ['kind' => 'departments', 'ids' => [10]]], 901);
ct_eq('…starts a Charis campaign', 'success', $own['status']);
ct_eq('…not one for everyone', 'error', campaign_action($g, 'save', ['name' => 'All', 'department_id' => 10, 'audience' => ['kind' => 'church']], 901)['status']);
ct_eq("…cannot open the church's campaign (it looks missing)", 'NOT_FOUND', campaign_action($g, 'get', ['id' => $cid], 901)['code'] ?? null);
ct_eq('…lists only Charis campaigns', ['Charis night'], array_column(campaign_action($g, 'list', [], 901)['campaigns'], 'name'));
ct_eq('…may send, copy and delete their draft', ['send' => true, 'delete' => true, 'copy' => true],
    array_intersect_key($own['campaign']['can'] ?? [], ['send' => 1, 'delete' => 1, 'copy' => 1]));
$as(903);
ct_eq('the Media Director does not see the Charis campaign', [], campaign_action($g, 'list', [], 903)['campaigns']);
ct_eq('…and cannot change it', 'FORBIDDEN', campaign_action($g, 'save', ['id' => $own['campaign']['id'] ?? 0, 'name' => 'Mine', 'department_id' => 20,
    'audience' => ['kind' => 'departments', 'ids' => [20]]], 903)['code'] ?? null);
$as(1001);
ct_eq('a member cannot start one', 'FORBIDDEN', campaign_action($g, 'save', ['name' => 'Hi', 'audience' => ['kind' => 'church']], 1001)['code'] ?? null);
ct_user($g, 904, 'Sam', 'Worker');
$g->exec('INSERT INTO user_roles (user_id, role_id) VALUES (904, 1)');
$as(904);
ct_eq('a Super Admin manages the settings', 'success', campaign_action($g, 'settings', [], 904)['status']);
$bad = campaign_action($g, 'settings_save', ['hourly_cap' => '5', 'window_start' => '20:00', 'window_end' => '07:00', 'postal_line' => ''], 904);
ct_eq('…which are checked', 3, count($bad['errors'] ?? []));
campaign_action($g, 'settings_save', ['hourly_cap' => '250', 'window_start' => '06:30', 'window_end' => '21:00', 'postal_line' => 'Lekki', 'paused' => '1'], 904);
ct_eq('…and saved', ['250', '06:30', '21:00', 'Lekki', '1'], [email_setting($g, 'hourly_cap'), email_setting($g, 'window_start'),
    email_setting($g, 'window_end'), email_setting($g, 'postal_line'), email_setting($g, 'paused')]);
ct_ok('campaign writers may use the block editor', email_editor_allowed());

// The screen, its script and its endpoint keep their contracts.
$root = dirname(__DIR__, 2);
$page = (string) file_get_contents($root . '/modules/campaigns/index.php');
$js = (string) file_get_contents($root . '/assets/ui/js/pages/campaigns.js');
$api = (string) file_get_contents($root . '/api/campaigns_api.php');
$acts = (string) file_get_contents($root . '/includes/campaigns/campaign_actions.php');
ct_ok('the endpoint asks Roles & permissions and checks the CSRF token',
    str_contains($api, "api_require_can('campaigns', 'view');") && str_contains($api, 'csrf_require();'));
ct_ok('the page is strict, gated, and allows only its own preview frame', str_contains($page, "'mode' => 'strict'")
    && str_contains($page, "require_can('campaigns', 'view');") && str_contains($page, "'frame-src' => [\"'self'\"]"));
ct_eq('no inline handler, script or style on the page', 0,
    preg_match_all('/\son[a-z]+\s*=|<script(?![^>]*type="application\/json")|\sstyle\s*=/i', $page));
preg_match_all('/[\'"](cam\.[a-z-]+)[\'"]/', $page, $m);
$used = array_values(array_unique($m[1]));
preg_match_all("/HOD\\.actions\\.on\\('(cam\\.[a-z-]+)'/", $js, $m);
$handled = array_values(array_unique($m[1]));
ct_eq('every action on the page has a handler', [], array_values(array_diff($used, $handled)));
ct_eq('every handler is used by the page', [], array_values(array_diff($handled, $used)));
preg_match_all("/call\\('([a-z_]+)'/", $js, $m);
$calls = array_values(array_unique($m[1]));
$missing = array_values(array_filter($calls, static fn ($a) => !str_contains($acts, "case '{$a}':")));
ct_eq('every action the script calls exists in campaign_action() (' . count($calls) . ' actions)', [], $missing);
ct_ok('the email worker starts due campaigns before it sends, and never on a dry run',
    preg_match("/if \\(!in_array\\('--dry-run', \\\$args, true\\)\\) \\{\\s*try \\{\\s*\\\$t = campaigns_tick\\(\\\$pdo\\);/", (string) file_get_contents($root . '/cron/email_worker.php')) === 1);
unset($_SESSION['user_id']);
perm_use_pdo(null);

// ===========================================================================
ct_section('17. Every function the module calls exists');
// ===========================================================================
$files = array_merge(glob(__DIR__ . '/../../includes/campaigns/*.php'), [
    __DIR__ . '/../../cron/email_worker.php', __DIR__ . '/../../cron/automation_tick.php', __DIR__ . '/../../includes/mailer.php',
]);
// trainings_reminders_run: Trainings' reminders ride on automation_tick.php behind a function_exists() guard.
// our_sermons_feeds_run: Our sermons' RSS poller rides on it the same way.
// our_sermons_ai_run: Our sermons' AI drafts (PR S3) ride on it the same way, after the automation.
// training_live_tick: live classes that invite their learners send the Google guest list again (19 Nov 2026).
$optional = ['fastcgi_finish_request', 'litespeed_finish_request', 'push_release_response', 'trainings_reminders_run',
    'our_sermons_feeds_run', 'our_sermons_ai_run', 'training_live_tick'];
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
// ===========================================================================
ct_section('Worker messages: the personal or profile address (owner, 20 Nov 2026)');
// ===========================================================================
$w = ct_db();
ct_at('2026-11-20 10:00');
$wu = static function (int $id, string $first, ?string $real, ?string $email, string $status = 'Worker') use ($w): void {
    ct_user($w, $id, $first, $status, ['email' => $email]);
    $w->prepare('UPDATE users SET real_email = ? WHERE id = ?')->execute([$real, $id]);
};
$wu(60, 'Conf', 'conf.old@yahoo.com', 'conf@hodlc.com');
ct_confirm($w, 60, 'conf@gmail.com', false);
$wu(61, 'Real', 'Real.Person@Yahoo.com ', 'real@hodlc.com');
$wu(62, 'Prof', null, 'prof@gmail.com');
$wu(63, 'Signin', '', 'signin@hodlc.com');
$wu(64, 'Upper', null, 'UPPER@HODLC.COM');
$wu(65, 'Church', null, 'staff@hodlekki.org');
$wu(66, 'Bad', 'not-an-email', 'bad.fallback@outlook.com');
$wu(67, 'Gone', 'gone@gmail.com', 'gone2@gmail.com');
email_consent_record($w, 67, 'withdrawn', 'profile', ['email' => 'gone@gmail.com']);
$wu(68, 'Visitor', 'visitor@gmail.com', null, 'Visitor');
$wu(69, 'Bounced', 'bounced@gmail.com', 'kept@outlook.com');
email_suppress($w, 'bounced@gmail.com', 'hard_bounce', 'test');
$wu(70, 'AllBlocked', 'b1@gmail.com', null);
email_suppress($w, 'b1@gmail.com', 'hard_bounce', 'test');
$wu(71, 'Borrowed', 'conf@gmail.com', null);   // someone else's confirmed address typed as theirs
$addr = static fn (int $id): array => email_news_address($w, $id);
ct_eq('a confirmed personal email wins', ['conf@gmail.com', 'confirmed'], [$addr(60)['email'], $addr(60)['source']]);
ct_eq('else the personal-email column, cleaned', ['real.person@yahoo.com', 'personal'], [$addr(61)['email'], $addr(61)['source']]);
ct_eq('else a real address in the sign-in column', ['prof@gmail.com', 'profile'], [$addr(62)['email'], $addr(62)['source']]);
ct_eq('a @hodlc.com sign-in name is never used', ['no_email', null], [$addr(63)['reason'], $addr(63)['email']]);
ct_eq('…in any case', 'no_email', $addr(64)['reason']);
ct_eq('nor a church @hodlekki.org address', 'no_email', $addr(65)['reason']);
ct_eq('a malformed personal email is passed over', 'bad.fallback@outlook.com', $addr(66)['email']);
ct_eq('someone who unsubscribed gets nothing at any address', ['withdrawn', null], [$addr(67)['reason'], $addr(67)['email']]);
ct_eq('a visitor without the box gets nothing', 'no_consent', $addr(68)['reason']);
ct_eq('a bounced address is passed over for the next', 'kept@outlook.com', $addr(69)['email']);
ct_eq('every address blocked: suppressed', 'suppressed', $addr(70)['reason']);
ct_eq('an address another person confirmed is theirs, not this person\'s', 'no_email', $addr(71)['reason']);
ct_ok('only the worker-message flows may use it', email_flow_takes_profile_address('dept-msg-12')
    && !email_flow_takes_profile_address('campaign') && !email_flow_takes_profile_address('embrace.welcome'));

// The worker re-checks at send time.
$queueNews = static function (int $uid, string $to, string $flow) use ($w): int {
    $r = email_enqueue($w, ['lane' => 'campaign', 'purpose' => 'news', 'flow' => $flow, 'user_id' => $uid,
        'to_email' => $to, 'subject' => 'Workers meeting', 'html' => '<p>hi</p>', 'text' => 'hi']);
    return (int) $r['id'];
};
$okDept = $queueNews(62, 'prof@gmail.com', 'dept-msg-1');
$noCamp = $queueNews(62, 'prof@gmail.com', 'campaign');
$tx = ct_transport();
email_worker_run($w, ['endpoints_ready' => true, 'transport' => $tx, 'now' => campaigns_now()]);
$status = static fn (int $id): array => $w->query('SELECT status, last_error FROM email_outbox WHERE id = ' . $id)->fetch(PDO::FETCH_ASSOC);
ct_eq('a worker message to a profile address is sent', 'sent', $status($okDept)['status']);
ct_eq('a campaign to the same unconfirmed address is still stopped', 'suppressed', $status($noCamp)['status']);
ct_eq('…and only the worker message left', ['prof@gmail.com'], array_map(static fn ($m) => $m['to_email'], $tx['sent']->getArrayCopy()));
ct_ok('it carries the one-click unsubscribe like any church email', isset($tx['sent'][0]['headers']['List-Unsubscribe']));

$changed = $queueNews(61, 'real.person@yahoo.com', 'dept-msg-2');
$w->exec("UPDATE users SET real_email = 'new.person@yahoo.com' WHERE id = 61");
$gone = $queueNews(62, 'prof@gmail.com', 'dept-msg-3');
email_consent_record($w, 62, 'withdrawn', 'profile', ['email' => 'prof@gmail.com']);
email_worker_run($w, ['endpoints_ready' => true, 'transport' => ct_transport(), 'now' => campaigns_now()]);
ct_eq('an address changed after queueing is not written to', ['suppressed', 'Not sent: address changed.'],
    [$status($changed)['status'], $status($changed)['last_error']]);
ct_eq('an unsubscribe after queueing stops it', ['suppressed', 'Not sent: withdrawn.'], [$status($gone)['status'], $status($gone)['last_error']]);
$signin = email_enqueue($w, ['lane' => 'campaign', 'purpose' => 'news', 'flow' => 'dept-msg-4', 'user_id' => 63,
    'to_email' => 'signin@hodlc.com', 'subject' => 'Workers meeting', 'html' => '<p>hi</p>', 'text' => 'hi']);
ct_eq('the outbox refuses a @hodlc.com church email outright', [false, 'INVALID_EMAIL'], [$signin['ok'], $signin['code'] ?? null]);

echo "\n{$GLOBALS['ct_passed']} passed, {$GLOBALS['ct_failed']} failed\n";
exit($GLOBALS['ct_failed'] === 0 ? 0 : 1);
