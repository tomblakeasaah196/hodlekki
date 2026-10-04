<?php
// /tests/special_events/program_eta_test.php — the programme clock
// (guide §10.7.2, §10.7.4, §22.1).
//
// The ETA engine is the one piece of PR4 that every surface believes: the
// host console, the stage screen and two hundred phones all repeat whatever
// it says. So it is worth pinning down precisely:
//
//  1. Planning chains durations, and an explicit time pins an item without
//     dragging the ones before it.
//  2. Live drift pushes everything after the live item, and only after it.
//  3. Drift is reported at the end of the evening, which is the number the
//     host actually cares about ("are we getting out on time?").
//  4. The public clock rounds, because an approximate promise is a promise
//     that can be kept.

// Parsed the way the module parses a row out of MySQL, so the pinned
// strings below and this day start share one timezone. Mixing a bare
// DateTimeImmutable with se_parse_datetime() would silently shift an hour.
$programDay = se_parse_datetime('2026-10-24 17:00:00');

/** A tiny builder so the cases below read like a run of show. */
$item = static function (int $id, string $title, int $minutes, ?string $pinned = null, string $status = 'planned', ?string $started = null, ?string $ended = null): array {
    return [
        'id' => $id,
        'title' => $title,
        'duration_min' => $minutes,
        'planned_start_at' => $pinned,
        'status' => $status,
        'started_at' => $started,
        'ended_at' => $ended,
    ];
};

// --------------------------------------------------------------------------
// Planning
// --------------------------------------------------------------------------

echo "    planned times\n";

$plan = se_program_plan([
    $item(1, 'Doors', 30),
    $item(2, 'Welcome', 10),
    $item(3, 'Worship', 25),
], $programDay);

is_same('the first item starts when the day does', '17:00', $plan[0]['planned_start']->format('H:i'));
is_same('the second follows the first', '17:30', $plan[1]['planned_start']->format('H:i'));
is_same('and the third follows that', '17:40', $plan[2]['planned_start']->format('H:i'));
is_same('an item ends a duration after it starts', '18:05', $plan[2]['planned_end']->format('H:i'));

$pinned = se_program_plan([
    $item(1, 'Doors', 30),
    $item(2, 'The message', 40, '2026-10-24 19:00:00'),
    $item(3, 'Karaoke', 60),
], $programDay);

is_same('a pinned item keeps its own time', '19:00', $pinned[1]['planned_start']->format('H:i'));
is_same('an item after a pinned one follows the pin', '19:40', $pinned[2]['planned_start']->format('H:i'));
is_same('a pinned item that is not early overlaps nobody', 0, $pinned[1]['overlap_min']);

$clash = se_program_plan([
    $item(1, 'Doors', 60),
    $item(2, 'Welcome', 10, '2026-10-24 17:30:00'),
], $programDay);

is_same('a pin that lands inside the item before it is flagged', 30, $clash[1]['overlap_min']);

$noDay = se_program_plan([$item(1, 'Doors', 30)], null);
ok('with no day start and no pin, there is no planned time',
    $noDay[0]['planned_start'] === null && $noDay[0]['planned_end'] === null);

// --------------------------------------------------------------------------
// Live drift
// --------------------------------------------------------------------------

echo "    live drift\n";

$now = se_parse_datetime('2026-10-24 17:50:00');

$live = se_program_eta(se_program_plan([
    $item(1, 'Doors', 30, null, 'done', '2026-10-24 17:00:00', '2026-10-24 17:40:00'),
    $item(2, 'Welcome', 10, null, 'live', '2026-10-24 17:40:00'),
    $item(3, 'Worship', 25),
], $programDay), $now);

is_same('a finished item reports when it really ended', '17:40', $live['items'][0]['eta_end']->format('H:i'));
is_same('a live item that has overrun ends now, not in the past',
    '17:50', $live['items'][1]['eta_end']->format('H:i'));
is_same('the next item is pushed by the overrun', '17:50', $live['items'][2]['eta_start']->format('H:i'));
is_same('…and so is its end', '18:15', $live['items'][2]['eta_end']->format('H:i'));
is_same('drift is the end-of-night number, in minutes late', 10, $live['drift_min']);

$early = se_program_eta(se_program_plan([
    $item(1, 'Doors', 30, null, 'done', '2026-10-24 17:00:00', '2026-10-24 17:20:00'),
    $item(2, 'Welcome', 10),
], $programDay), $now);

is_same('running early is negative drift', -10, $early['drift_min']);

$untouched = se_program_eta(se_program_plan([
    $item(1, 'Doors', 30),
    $item(2, 'Welcome', 10),
], $programDay), se_parse_datetime('2026-10-24 16:00:00'));

is_same('a night nobody has started yet has no drift', 0, $untouched['drift_min']);
is_same('and keeps its planned times', '17:30', $untouched['items'][1]['eta_start']->format('H:i'));

$skipped = se_program_eta(se_program_plan([
    $item(1, 'Doors', 30, null, 'skipped', null, '2026-10-24 17:05:00'),
    $item(2, 'Welcome', 10),
], $programDay), se_parse_datetime('2026-10-24 17:05:00'));

is_same('skipping an item pulls the rest of the night forward',
    '17:05', $skipped['items'][1]['eta_start']->format('H:i'));

$pinnedLive = se_program_eta(se_program_plan([
    $item(1, 'Doors', 30, null, 'done', '2026-10-24 17:00:00', '2026-10-24 17:10:00'),
    $item(2, 'The message', 40, '2026-10-24 19:00:00'),
], $programDay), se_parse_datetime('2026-10-24 17:10:00'));

is_same('a pinned item does not move earlier just because we are ahead',
    '19:00', $pinnedLive['items'][1]['eta_start']->format('H:i'));

// --------------------------------------------------------------------------
// The public clock (§10.7.2)
// --------------------------------------------------------------------------

echo "    public times\n";

$at = se_parse_datetime('2026-10-24 19:07:00');

is_same('exact mode says the time', '7:07 PM', se_program_public_time($at, 'exact'));
is_same('approximate mode rounds to the nearest quarter hour',
    '~7:00 PM', se_program_public_time($at, 'approximate'));
is_same('…rounding up when that is nearer',
    '~7:15 PM', se_program_public_time(se_parse_datetime('2026-10-24 19:11:00'), 'approximate'));
is_same('order-only mode says nothing at all', null, se_program_public_time($at, 'order_only'));
is_same('and no time is still no time', null, se_program_public_time(null, 'exact'));

is_same('rounding goes down when it is nearer',
    '~7:00 PM', se_program_public_time(se_parse_datetime('2026-10-24 19:04:00'), 'approximate'));
is_same('rounding crosses the hour cleanly',
    '~8:00 PM', se_program_public_time(se_parse_datetime('2026-10-24 19:58:00'), 'approximate'));
