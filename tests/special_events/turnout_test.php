<?php
// /tests/special_events/turnout_test.php

$day = '2026-11-07';
$row = static fn(int $id, string $status, string $channel, string $created, ?string $in): array => [
    'id' => $id, 'status' => $status, 'channel' => $channel, 'created_at' => $created, 'checked_in_at' => $in,
];

ok('walk-in channel is day-of whatever the date', se_turnout_is_day_of($row(1, 'confirmed', 'walkin_desk', '2026-11-01 10:00:00', null), $day));
ok('self walk-in channel is day-of', se_turnout_is_day_of($row(1, 'confirmed', 'walkin_self', '2026-10-01 10:00:00', null), $day));
ok('portal registration on the first day is day-of', se_turnout_is_day_of($row(1, 'confirmed', 'portal', '2026-11-07 18:59:00', null), $day));
ok('portal registration after the first day is day-of', se_turnout_is_day_of($row(1, 'confirmed', 'portal', '2026-11-08 09:00:00', null), $day));
ok('portal registration the day before is not day-of', !se_turnout_is_day_of($row(1, 'confirmed', 'portal', '2026-11-06 23:59:59', null), $day));
ok('no event day: only the channel counts', !se_turnout_is_day_of($row(1, 'confirmed', 'portal', '2026-11-08 09:00:00', null), ''));

$b = se_turnout_classify([
    $row(1, 'confirmed', 'portal', '2026-11-01 10:00:00', '2026-11-07 19:00:00'),    // came, pre-registered
    $row(2, 'confirmed', 'portal', '2026-11-02 10:00:00', null),                     // no-show
    $row(3, 'waitlisted', 'portal', '2026-11-03 10:00:00', null),                    // waitlisted no-show
    $row(4, 'confirmed', 'walkin_desk', '2026-11-07 19:10:00', '2026-11-07 19:10:01'), // walk-in
    $row(5, 'confirmed', 'portal', '2026-11-07 17:00:00', '2026-11-07 19:20:00'),    // registered online that day
    $row(6, 'cancelled', 'portal', '2026-11-01 10:00:00', null),                     // cancelled: nowhere
    $row(7, 'cancelled', 'portal', '2026-11-01 10:00:00', '2026-11-07 19:30:00'),    // came then cancelled
], $day);
$ids = static fn(array $list): array => array_map(static fn(array $r): int => $r['id'], $list);

is_same('registered = confirmed + waitlisted', [1, 2, 3, 4, 5], $ids($b['registered']));
is_same('checked in = anyone who came', [1, 4, 5, 7], $ids($b['checked_in']));
is_same('day-of = came and walk-in or registered that day', [4, 5], $ids($b['day_of']));
is_same('no-show = signed up and never came', [2, 3], $ids($b['no_show']));
is_same('empty input gives four empty lists', ['registered' => [], 'checked_in' => [], 'day_of' => [], 'no_show' => []], se_turnout_classify([], $day));

$api = (string) file_get_contents(__DIR__ . '/../../api/special_events_api.php');
ok('turnout_get is gated on attendee.search', (bool) preg_match("/case 'turnout_get'.*?se_studio_event\\(\\\$pdo, \\\$body, 'attendee\\.search'\\)/s", $api));
ok('turnout_get masks phones without attendee.pii', (bool) preg_match("/case 'turnout_get'.*?'attendee\\.pii'/s", $api));

// --- Member names from the directory -------------------------------------

is_same('phone key from local format', '8031234567', se_turnout_phone_key('0803 123 4567'));
is_same('phone key from +234 with dashes', '8031234567', se_turnout_phone_key('+234 803-123-4567'));
is_same('phone key from bare E.164', '8031234567', se_turnout_phone_key('2348031234567'));
is_same('too-short phone has no key', null, se_turnout_phone_key('12345'));

$index = se_turnout_member_index_build([
    ['id' => 4, 'first_name' => 'Ada', 'last_name' => 'Obi', 'phone' => '0803 000 0004'],
    ['id' => 7, 'first_name' => 'Emeka', 'last_name' => 'Nwosu', 'phone' => '+234-805-555-0007'],
    ['id' => 8, 'first_name' => 'Twin', 'last_name' => 'One', 'phone' => '0809-999-0000'],
    ['id' => 9, 'first_name' => 'Twin', 'last_name' => 'Two', 'phone' => '2348099990000'],
    ['id' => 10, 'first_name' => 'No', 'last_name' => 'Phone', 'phone' => ''],
]);

$ada = se_turnout_match_member($index, '2348030000004');
is_same('a number in users is a member, with the exact system name', ['id' => 4, 'name' => 'Ada Obi', 'ambiguous' => false], $ada);
is_same('punctuation in the stored phone does not stop a match', 7, se_turnout_match_member($index, '2348055550007')['id'] ?? null);
is_same('a number not in users is a guest', null, se_turnout_match_member($index, '2348012345678'));
is_same('a foreign number is never matched on its last ten digits', null, se_turnout_match_member($index, '448030000004'));
is_same('no phone is a guest', null, se_turnout_match_member($index, ''));
$twin = se_turnout_match_member($index, '2348099990000');
is_same('two users on one number: the exact stored format wins', 9, $twin['id'] ?? null);
ok('…and the match is flagged ambiguous', !empty($twin['ambiguous']));

$poor = ['id' => 1, 'first_name' => 'adda', 'last_name' => ' obbi', 'phone_e164' => '2348030000004', 'status' => 'confirmed',
         'channel' => 'portal', 'created_at' => '2026-11-01 10:00:00', 'checked_in_at' => '2026-11-07 19:00:00', 'member' => $ada];
$p = se_turnout_person($poor, true);
is_same('a member is shown under the system name', 'Ada Obi', $p['name']);
is_same('…with what they typed kept alongside', 'adda obbi', $p['typed_name']);
ok('…and flagged a member', $p['is_member'] === true);

$same = ['first_name' => 'ADA', 'last_name' => 'obi'] + $poor;
is_same('a typed name that only differs in case is not repeated', null, se_turnout_person($same, true)['typed_name']);

$guest = ['member' => null, 'first_name' => 'Kemi', 'last_name' => 'Ade', 'phone_e164' => '2348012345678'] + $poor;
$g = se_turnout_person($guest, false);
is_same('a guest keeps the name they typed', 'Kemi Ade', $g['name']);
ok('…is flagged a guest, with no typed-name line', $g['is_member'] === false && $g['typed_name'] === null);

$blank = ['member' => ['id' => 11, 'name' => '', 'ambiguous' => false]] + $poor;
is_same('a member with a blank directory name falls back to the typed name', 'adda obbi', se_turnout_person($blank, true)['name']);
