<?php
// /tests/special_events/capacity_state_test.php — the registration-state
// ladder, seats-left and walk-in seating (guide §10.4, §22.1).
//
// Table-driven on purpose. These three functions are pure, and they decide
// whether a person gets a seat, so the cheapest way to keep them honest is
// to state every rung of the ladder as a row and read the table back
// against §10.4.2 whenever the spec moves.

$now = new DateTimeImmutable('2026-10-01 12:00:00', se_tz());

/** A published event with sane defaults; each case overrides what it tests. */
function cap_event(array $overrides = []): array
{
    return $overrides + [
        'id'                       => 1,
        'online_capacity'          => 100,
        'walkin_capacity'          => null,
        'waitlist_enabled'         => 1,
        'waitlist_capacity'        => null,
        'auto_close_at_capacity'   => 1,
        'reg_override'             => 'none',
        'reg_override_note'        => null,
        'reg_opens_at'             => null,
        'reg_closes_at'            => null,
        'seats_left_mode'          => 'never',
        'seats_left_threshold_pct' => 70,
        'walkin_enabled'           => 1,
        'walkin_hard_cap'          => 0,
        'self_cancel_enabled'      => 1,
    ];
}

function cap_counts(int $online = 0, int $waitlisted = 0, int $walkin = 0): array
{
    return [
        'online_taken' => $online,
        'walkin_taken' => $walkin,
        'waitlisted'   => $waitlisted,
        'confirmed'    => $online + $walkin,
        'cancelled'    => 0,
    ];
}

/** The phase block se_event_phase() hands over. */
function cap_phase(string $phase = 'upcoming', ?string $firstDayStart = '2026-10-24 17:00:00'): array
{
    return [
        'phase'     => $phase,
        'first_day' => $firstDayStart !== null ? ['starts_at' => $firstDayStart] : null,
    ];
}

// --------------------------------------------------------------------------
// §10.4.2 — the state ladder, rung by rung, in order
// --------------------------------------------------------------------------

echo "    the state ladder\n";

$ladder = [
    // [label, event overrides, counts, phase, expected]
    ['a live event is closed to online registration',
        [], cap_counts(0), cap_phase('live'), 'closed'],
    ['so is one that is over',
        [], cap_counts(0), cap_phase('post'), 'closed'],
    ['so is a draft',
        [], cap_counts(0), cap_phase('draft'), 'closed'],
    ['so is a cancelled event',
        [], cap_counts(0), cap_phase('cancelled'), 'closed'],

    ['force_closed beats everything below it',
        ['reg_override' => 'force_closed'], cap_counts(0), cap_phase(), 'closed_manual'],
    ['force_closed beats force_open being absent, and an empty event',
        ['reg_override' => 'force_closed', 'online_capacity' => null], cap_counts(0), cap_phase(), 'closed_manual'],

    ['before reg_opens_at it is not open yet',
        ['reg_opens_at' => '2026-10-05 09:00:00'], cap_counts(0), cap_phase(), 'not_open_yet'],
    ['exactly at reg_opens_at it IS open',
        ['reg_opens_at' => '2026-10-01 12:00:00'], cap_counts(0), cap_phase(), 'open'],

    ['after reg_closes_at it is closed',
        ['reg_closes_at' => '2026-09-30 23:59:00'], cap_counts(0), cap_phase(), 'closed_deadline'],
    ['exactly at reg_closes_at it is closed',
        ['reg_closes_at' => '2026-10-01 12:00:00'], cap_counts(0), cap_phase(), 'closed_deadline'],
    ['with no reg_closes_at the first day start is the deadline',
        [], cap_counts(0), cap_phase('upcoming', '2026-09-30 17:00:00'), 'closed_deadline'],
    ['reg_closes_at wins over the first day start',
        ['reg_closes_at' => '2026-10-20 23:59:00'], cap_counts(0), cap_phase('upcoming', '2026-09-30 17:00:00'), 'open'],

    ['force_open reopens a full event',
        ['reg_override' => 'force_open'], cap_counts(100), cap_phase(), 'open'],
    ['force_open does NOT beat the deadline',
        ['reg_override' => 'force_open', 'reg_closes_at' => '2026-09-30 23:59:00'],
        cap_counts(0), cap_phase(), 'closed_deadline'],
    ['force_open does NOT beat a non-upcoming phase',
        ['reg_override' => 'force_open'], cap_counts(0), cap_phase('live'), 'closed'],

    ['unlimited capacity is always open',
        ['online_capacity' => null], cap_counts(99999), cap_phase(), 'open'],
    ['an empty string capacity means unlimited too',
        ['online_capacity' => ''], cap_counts(99999), cap_phase(), 'open'],

    ['one seat left is open',
        [], cap_counts(99), cap_phase(), 'open'],
    ['exactly full with a waitlist is waitlist',
        [], cap_counts(100), cap_phase(), 'waitlist'],
    ['over capacity with a waitlist is still waitlist',
        [], cap_counts(140), cap_phase(), 'waitlist'],

    ['a soft cap keeps taking people',
        ['auto_close_at_capacity' => 0], cap_counts(100), cap_phase(), 'open'],
    ['a soft cap keeps taking people well past the number',
        ['auto_close_at_capacity' => 0], cap_counts(500), cap_phase(), 'open'],

    ['full with no waitlist is full',
        ['waitlist_enabled' => 0], cap_counts(100), cap_phase(), 'full'],
    ['a waitlist with room is waitlist',
        ['waitlist_capacity' => 20], cap_counts(100, 19), cap_phase(), 'waitlist'],
    ['a full waitlist is full',
        ['waitlist_capacity' => 20], cap_counts(100, 20), cap_phase(), 'full'],
    ['an over-full waitlist is full',
        ['waitlist_capacity' => 20], cap_counts(100, 25), cap_phase(), 'full'],
    ['a zero waitlist capacity is full',
        ['waitlist_capacity' => 0], cap_counts(100), cap_phase(), 'full'],

    ['zero capacity with a waitlist goes straight to waitlist',
        ['online_capacity' => 0], cap_counts(0), cap_phase(), 'waitlist'],
    ['zero capacity with no waitlist is full from the start',
        ['online_capacity' => 0, 'waitlist_enabled' => 0], cap_counts(0), cap_phase(), 'full'],
];

foreach ($ladder as [$label, $overrides, $counts, $phase, $expected]) {
    is_same($label, $expected, se_registration_state(cap_event($overrides), $phase, $counts, $now));
}

echo "    accepts()\n";
foreach (['open' => true, 'waitlist' => true, 'full' => false, 'closed' => false,
          'closed_manual' => false, 'closed_deadline' => false, 'not_open_yet' => false] as $state => $expected) {
    is_same("{$state} accepts a registration", $expected, se_registration_state_accepts($state));
}

echo "    the message for each state\n";
foreach (['open', 'waitlist', 'full', 'not_open_yet', 'closed_deadline', 'closed_manual', 'closed'] as $state) {
    $message = se_registration_state_message($state, cap_event());
    ok("{$state} has a sentence", $message !== '' && !str_contains($message, '{'), $message);
}
is_same('a pause note is shown verbatim',
    'Back on Monday.',
    se_registration_state_message('closed_manual', cap_event(['reg_override_note' => 'Back on Monday.'])));
is_same('an empty pause note falls back',
    'Registration is paused.',
    se_registration_state_message('closed_manual', cap_event(['reg_override_note' => '  '])));

// --------------------------------------------------------------------------
// §10.4.1 — free seats
// --------------------------------------------------------------------------

echo "    free seats\n";
is_same('online free', 40, se_online_free(cap_event(), cap_counts(60)));
is_same('online free never goes negative', 0, se_online_free(cap_event(), cap_counts(140)));
is_same('unlimited online is null', null, se_online_free(cap_event(['online_capacity' => null]), cap_counts(60)));
is_same('walk-in free', 10, se_walkin_free(cap_event(['walkin_capacity' => 30]), cap_counts(0, 0, 20)));
is_same('unlimited walk-in is null', null, se_walkin_free(cap_event(), cap_counts(0, 0, 20)));

// --------------------------------------------------------------------------
// §10.4.8 — seats left
// --------------------------------------------------------------------------

echo "    seats left\n";
$seatsLeft = [
    ['never mode hides the number', ['seats_left_mode' => 'never'], cap_counts(99), null],
    ['always mode shows it from zero', ['seats_left_mode' => 'always'], cap_counts(0), 100],
    ['always mode shows it near the end', ['seats_left_mode' => 'always'], cap_counts(96), 4],
    ['always mode shows zero, not null', ['seats_left_mode' => 'always'], cap_counts(100), 0],
    ['always mode clamps an overshoot to zero', ['seats_left_mode' => 'always'], cap_counts(130), 0],
    ['threshold mode is hidden below the threshold', ['seats_left_mode' => 'threshold'], cap_counts(69), null],
    ['threshold mode appears exactly at it', ['seats_left_mode' => 'threshold'], cap_counts(70), 30],
    ['threshold mode stays visible above it', ['seats_left_mode' => 'threshold'], cap_counts(90), 10],
    ['a custom threshold is honoured',
        ['seats_left_mode' => 'threshold', 'seats_left_threshold_pct' => 90], cap_counts(80), null],
    ['…and fires at its own number',
        ['seats_left_mode' => 'threshold', 'seats_left_threshold_pct' => 90], cap_counts(90), 10],
    ['unlimited capacity never shows a number',
        ['seats_left_mode' => 'always', 'online_capacity' => null], cap_counts(50), null],
];

foreach ($seatsLeft as [$label, $overrides, $counts, $expected]) {
    is_same($label, $expected, se_seats_left(cap_event($overrides), $counts));
}

// --------------------------------------------------------------------------
// §10.4.6 — which pool a walk-in takes
// --------------------------------------------------------------------------

echo "    walk-in seating\n";
$walkin = [
    ['a free online seat is reused first', [], cap_counts(50), false, 'online'],
    ['unlimited online always reuses online', ['online_capacity' => null], cap_counts(9999), false, 'online'],
    ['full online falls through to the walk-in pool', [], cap_counts(100), false, 'walkin'],
    ['walk-ins switched off means the desk decides',
        ['walkin_enabled' => 0], cap_counts(100), false, null],
    ['…unless the desk overrides',
        ['walkin_enabled' => 0], cap_counts(100), true, 'walkin'],
    ['a walk-in pool with room is used',
        ['walkin_capacity' => 20], cap_counts(100, 0, 5), false, 'walkin'],
    ['a soft walk-in cap keeps letting people in',
        ['walkin_capacity' => 20, 'walkin_hard_cap' => 0], cap_counts(100, 0, 20), false, 'walkin'],
    ['a hard walk-in cap stops at the number',
        ['walkin_capacity' => 20, 'walkin_hard_cap' => 1], cap_counts(100, 0, 20), false, null],
    ['…and the desk can still override it',
        ['walkin_capacity' => 20, 'walkin_hard_cap' => 1], cap_counts(100, 0, 20), true, 'walkin'],
];

foreach ($walkin as [$label, $overrides, $counts, $override, $expected]) {
    is_same($label, $expected, se_seat_for_walkin(cap_event($overrides), $counts, $override));
}

// --------------------------------------------------------------------------
// Purity: the same inputs, the same answer, no hidden clock
// --------------------------------------------------------------------------

echo "    purity\n";
$event = cap_event(['online_capacity' => 10]);
$counts = cap_counts(5);
$first = se_registration_state($event, cap_phase(), $counts, $now);
for ($i = 0; $i < 5; $i++) {
    is_same('state is stable on repeat', $first, se_registration_state($event, cap_phase(), $counts, $now));
}
ok('the event array is not mutated', $event === cap_event(['online_capacity' => 10]));
ok('the counts array is not mutated', $counts === cap_counts(5));
