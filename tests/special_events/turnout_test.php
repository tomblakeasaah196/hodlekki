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
