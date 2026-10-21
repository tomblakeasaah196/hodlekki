<?php
// /tests/sms/run.php
/**
 * SMS Studio helper tests — no database, no network.
 *     php tests/sms/run.php
 * Covers the pure functions in includes/sms_functions.php that decide whether
 * a message goes out, what it says, and what it costs: phone normalisation,
 * GSM-7 segment counting, the sender name written into every text, the price
 * per unit and the wallet pre-flight arithmetic, delivery-status mapping.
 */

// sms_vault_key.php pulls in db.php (and a live connection) when the key is
// not already in $_ENV; a dummy key keeps this suite self-contained.
$_ENV['SMS_VAULT_KEY'] = str_repeat('0', 64);
require_once __DIR__ . '/../../includes/sms_functions.php';

$fails = 0;
$count = 0;
function is_same(string $label, $want, $got): void
{
    global $fails, $count;
    $count++;
    if ($want === $got) {
        echo "  ok   {$label}\n";
        return;
    }
    $fails++;
    echo "  FAIL {$label}\n       want " . var_export($want, true) . "\n       got  " . var_export($got, true) . "\n";
}

echo "sms_normalize_phone\n";
is_same('0803… local format',            '2348031234567', sms_normalize_phone('08031234567'));
is_same('spaces and +234',               '2348031234567', sms_normalize_phone('+234 803 123 4567'));
is_same('already international',         '2348031234567', sms_normalize_phone('2348031234567'));
is_same('00234 prefix',                  '2348031234567', sms_normalize_phone('002348031234567'));
is_same('+234 (0) 803… typo',            '2348031234567', sms_normalize_phone('+234 (0) 803 123 4567'));
is_same('ten digits without the 0',      '2348031234567', sms_normalize_phone('8031234567'));
is_same('070x (MTN/Airtel) is valid',    '2347021234567', sms_normalize_phone('07021234567'));
is_same('081x is valid',                 '2348151234567', sms_normalize_phone('08151234567'));
is_same('090x is valid',                 '2349031234567', sms_normalize_phone('09031234567'));
is_same('091x is valid',                 '2349121234567', sms_normalize_phone('09121234567'));
is_same('060x is not a mobile',          null,            sms_normalize_phone('06031234567'));
is_same('too short',                     null,            sms_normalize_phone('0803123'));
is_same('empty',                         null,            sms_normalize_phone(''));
is_same('Lagos landline 01 is rejected', null,            sms_normalize_phone('012345678'));

echo "sms_gsm_clean / sms_segments\n";
is_same('curly apostrophe becomes plain', "it's", sms_gsm_clean("it\u{2019}s"));
is_same('160 GSM-7 characters is one page', 1, sms_segments(str_repeat('a', 160))['pages']);
is_same('161 is two pages',                 2, sms_segments(str_repeat('a', 161))['pages']);
is_same('306 is two pages (153 per part)',  2, sms_segments(str_repeat('a', 306))['pages']);
is_same('307 is three pages',               3, sms_segments(str_repeat('a', 307))['pages']);
is_same('line break counts as one',         11, sms_segments("Hello\nWorld")['chars']);
is_same('€ counts as two (extension)',      2, sms_segments('€')['chars']);
is_same('the naira sign forces Unicode',    'Unicode', sms_segments('Give ₦100')['encoding']);
is_same('70 Unicode characters is one page', 1, sms_segments(str_repeat('₦', 70))['pages']);
is_same('71 Unicode characters is two',      2, sms_segments(str_repeat('₦', 71))['pages']);
is_same('an emoji switches to Unicode',     'Unicode', sms_segments('Hi 🎉')['encoding']);

echo "sms_render\n";
is_same('merge fields and GSM clean-up', "Dear Ada, see you at Sunday Service - it's on!",
    sms_render("Dear {{first_name}}, see you at {{event_title}} \u{2014} it\u{2019}s on!", ['first_name' => 'Ada', 'event_title' => 'Sunday Service']));
is_same('guest name is the first name only', 'Hi Chisom', sms_render('Hi {{guest_name}}', ['guest_name' => 'Chisom Test']));
is_same('unknown field is left as typed', 'Hi {{nickname}}', sms_render('Hi {{nickname}}', ['first_name' => 'Ada']));

echo "sms_signature / sms_sign_body (the sender name in every text)\n";
$hod = ['sender_id' => 'HODLEKKI', 'sign_mode' => ''];
is_same('default: "SENDER: "',               'HODLEKKI: ', sms_signature($hod));
is_same('explicit prefix mode',              'HODLEKKI: ', sms_signature(['sender_id' => 'HODLEKKI', 'sign_mode' => 'prefix']));
is_same('none turns it off',                 '',           sms_signature(['sender_id' => 'HODLEKKI', 'sign_mode' => 'none']));
is_same('no sender ID, nothing to write',    '',           sms_signature(['sender_id' => '', 'sign_mode' => '']));
is_same('sender ID is trimmed',              'HODLEKKI: ', sms_signature(['sender_id' => ' HODLEKKI ', 'sign_mode' => '']));
is_same('body gets the prefix',              'HODLEKKI: Dear Ada', sms_sign_body('Dear Ada', $hod));
is_same('already signed: not doubled',       'HODLEKKI: Dear Ada', sms_sign_body('HODLEKKI: Dear Ada', $hod));
is_same('already signed, any case',          'hodlekki: Dear Ada', sms_sign_body('hodlekki: Dear Ada', $hod));
is_same('empty body stays empty (refused)',  '',                   sms_sign_body('', $hod));
is_same('off: body untouched',               'Dear Ada',           sms_sign_body('Dear Ada', ['sender_id' => 'HODLEKKI', 'sign_mode' => 'none']));
is_same('150 chars + prefix still one page', 1, sms_segments(sms_sign_body(str_repeat('a', 150), $hod))['pages']);
is_same('151 chars + prefix is two pages',   2, sms_segments(sms_sign_body(str_repeat('a', 151), $hod))['pages']);
is_same('the signature itself is GSM-7',     'GSM-7', sms_segments(sms_signature($hod))['encoding']);

echo "sms_unit_price / sms_parse_balance / sms_wallet_check arithmetic\n";
is_same('no price saved: list price',        6.49, sms_unit_price(['unit_price' => '']));
is_same('saved price wins',                  7.5,  sms_unit_price(['unit_price' => '7.5']));
is_same('garbage falls back',                6.49, sms_unit_price(['unit_price' => 'abc']));
is_same('zero or negative falls back',       6.49, sms_unit_price(['unit_price' => '-1']));
is_same('balance.total_balance with commas', 1631.83, sms_parse_balance(['balance' => ['total_balance' => '1,631.83']]));
is_same('data.balance number',               100.0,   sms_parse_balance(['data' => ['balance' => 100]]));
is_same('naira sign stripped',               12.5,    sms_parse_balance(['balance' => '₦12.50']));
is_same('nothing there',                     null,    sms_parse_balance(['status' => 'success']));
is_same('not an array',                      null,    sms_parse_balance('oops'));
is_same('700 units at 6.49 need 4543.00',    4543.0,  round(700 * sms_unit_price(['unit_price' => '']), 2));
// sms_wallet_check needs the network for the balance; the refusal path is exercised
// without it: no token = cannot read the balance = fail closed, nothing queued.
$w = sms_wallet_check(['api_token' => '', 'unit_price' => ''], 700);
is_same('unreadable balance fails closed',   false, $w['ok']);
is_same('…and says why',                     true,  strpos((string)$w['message'], 'Nothing was queued') !== false);
is_same('…with the amount it needed',        4543.0, $w['need']);

echo "sms_map_dlr_status\n";
is_same('DELIVRD',               'delivered', sms_map_dlr_status('DELIVRD'));
is_same('delivered to handset',  'delivered', sms_map_dlr_status('Delivered to handset'));
is_same('UNDELIV',               'failed',    sms_map_dlr_status('UNDELIV'));
is_same('not delivered',         'failed',    sms_map_dlr_status('Not Delivered'));
is_same('DND',                   'failed',    sms_map_dlr_status('DND'));
is_same('awaiting delivery',     'pending',   sms_map_dlr_status('awaiting delivery'));
is_same('ACCEPTD',               'pending',   sms_map_dlr_status('ACCEPTD'));
is_same('sent',                  'pending',   sms_map_dlr_status('sent'));
is_same('empty is unknown',      null,        sms_map_dlr_status(''));
is_same('gibberish is unknown',  null,        sms_map_dlr_status('xyz'));

echo "sms_explain_code\n";
is_same('insufficient balance',  'Insufficient wallet balance — fund the account.', sms_explain_code('bsng-3000'));
is_same('unapproved sender ID',  'Sender ID not registered or not approved.',       sms_explain_code('BSNG-2011'));
is_same('unknown code',          null, sms_explain_code('BSNG-9999'));

echo "\n{$count} checks, {$fails} failed\n";
exit($fails ? 1 : 0);
