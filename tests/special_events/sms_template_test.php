<?php
// /tests/special_events/sms_template_test.php — message templates
// (guide §16.3, Appendix E, §22.1).
//
// Two things matter and neither is obvious from reading the code:
//
//  1. Every default template must fit in ONE billable page with realistic
//     values. Appendix E does the arithmetic; this re-does it, so a well-
//     meant edit that adds eight characters shows up as a doubled SMS bill
//     before it reaches production rather than after.
//  2. se_message_render_event() must resolve the event-level tokens and
//     leave {{first_name}} and {{link}} alone, because the worker fills
//     those per recipient.

$event = [
    'id'            => 1,
    'slug'          => 'chara',
    'title'         => 'Chara',
    'edition_label' => '2026',
    'starts_at'     => '2026-10-24 17:00:00',
    'venue_name'    => 'HOD Lekki Centre',
    'venue_map_url' => 'https://maps.app.goo.gl/abc',
];

$days = [[
    'day_date'  => '2026-10-24',
    'starts_at' => '2026-10-24 17:00:00',
    'ends_at'   => '2026-10-24 21:00:00',
]];

$settings = se_settings_normalize(null);

// --------------------------------------------------------------------------
// Event-level substitution
// --------------------------------------------------------------------------

echo "    event tokens\n";
$rendered = se_message_render_event(
    'Hi {{first_name}}, {{event_title}} is on {{date}} at {{time}}, {{venue}}. {{link}}',
    $event, $days, $settings
);

ok('{{event_title}} includes the edition', str_contains($rendered, 'Chara 2026'), $rendered);
ok('{{date}} is short and human', str_contains($rendered, 'Sat 24 Oct'), $rendered);
ok('{{time}} has no leading zero', str_contains($rendered, '5:00 PM'), $rendered);
ok('{{venue}} is the venue name', str_contains($rendered, 'HOD Lekki Centre'), $rendered);
ok('{{first_name}} is left for the worker', str_contains($rendered, '{{first_name}}'), $rendered);
ok('{{link}} is left for the worker', str_contains($rendered, '{{link}}'), $rendered);

is_same('{{map}} resolves too', 'https://maps.app.goo.gl/abc',
    se_message_render_event('{{map}}', $event, $days, $settings));

is_same('an event with no edition keeps just the title', 'Chara',
    se_message_render_event('{{event_title}}', ['title' => 'Chara', 'edition_label' => null, 'starts_at' => null], [], $settings));

is_same('an event with no venue leaves an empty string, not the token', '',
    se_message_render_event('{{venue}}', ['title' => 'X', 'starts_at' => null], [], $settings));

is_same('an unknown token is left alone', '{{nonsense}}',
    se_message_render_event('{{nonsense}}', $event, $days, $settings));

is_same('the result is trimmed', 'Chara 2026',
    se_message_render_event('   {{event_title}}   ', $event, $days, $settings));

echo "    the day list wins over the event row\n";
$laterDay = [['day_date' => '2026-11-02', 'starts_at' => '2026-11-02 18:30:00', 'ends_at' => '2026-11-02 21:00:00']];
ok('the first DAY supplies the date, not se_events.starts_at',
    str_contains(se_message_render_event('{{date}} {{time}}', $event, $laterDay, $settings), 'Mon 2 Nov'),
    se_message_render_event('{{date}} {{time}}', $event, $laterDay, $settings));

// --------------------------------------------------------------------------
// Every default template, costed
// --------------------------------------------------------------------------

echo "    the Appendix E defaults all fit one page\n";

$kinds = ['reminder_1', 'reminder_2', 'thank_you', 'waitlist_promotion', 'link_on_demand'];

foreach ($kinds as $kind) {
    $template = se_message_template($settings, $kind);
    ok("{$kind} has a default template", $template !== '');

    $preview = se_message_preview($template, $event, $days, [], $settings);

    ok("{$kind} is plain GSM-7 (no emoji, no curly quotes)",
        $preview['encoding'] === 'GSM-7',
        $preview['encoding'] . ': ' . $preview['body']);

    ok("{$kind} is one billable page ({$preview['chars']} chars)",
        $preview['pages'] === 1,
        $preview['body']);

    ok("{$kind} has no token left unresolved",
        !preg_match('/\{\{[a-z_]+\}\}/', $preview['body']),
        $preview['body']);
}

echo "    a long venue is the thing that tips a message over\n";
// Appendix E warns about this explicitly, so it is worth a test: the point
// is not that a long venue is forbidden, but that the Studio's estimate
// notices it before a Producer sends 1 200 of them.
$longVenue = $event;
$longVenue['venue_name'] = 'The Household of David Lekki Centre Main Auditorium, Ground Floor';

$short = se_message_preview(se_message_template($settings, 'reminder_1'), $event, $days, [], $settings);
$long  = se_message_preview(se_message_template($settings, 'reminder_1'), $longVenue, $days, [], $settings);

ok('a longer venue produces a longer message', $long['chars'] > $short['chars']);
ok('and the page count is reported honestly', $long['pages'] >= $short['pages']);

echo "    the manage link is costed at its real length\n";
// A 22-character token on https://hodlc.lpc.cm/e/chara/me/ is 53 characters
// (Appendix E). The preview must spend those characters, not pretend
// {{link}} is six.
$preview = se_message_preview('{{link}}', $event, $days, [], $settings);
ok('the preview substitutes a full-length sample link',
    $preview['chars'] >= 40, $preview['body']);
ok('…and it looks like the real manage URL',
    str_contains($preview['body'], '/e/chara/me/'), $preview['body']);

echo "    kind labels\n";
foreach ($kinds as $kind) {
    ok("{$kind} has a human label", !empty(SE_MESSAGE_KIND_LABELS[$kind]));
}
ok('adhoc has one too', !empty(SE_MESSAGE_KIND_LABELS['adhoc']));

echo "    segment arithmetic itself\n";
is_same('160 GSM-7 characters is one page', 1, sms_segments(str_repeat('a', 160))['pages']);
is_same('161 is two', 2, sms_segments(str_repeat('a', 161))['pages']);
is_same('an emoji switches to Unicode', 'Unicode', sms_segments('Hi 🎉')['encoding']);

// --------------------------------------------------------------------------
// When the reminders go out (§16.2, §16.4)
// --------------------------------------------------------------------------
//
// The schedule is pure arithmetic over the days, and every slot carries a
// run key. The key is the thing that makes a reminder un-sendable twice, so
// it is asserted literally: change the format and you change what "already
// sent" means for events already in the database.

echo "    reminder schedule\n";

$on = se_settings_normalize([
    'messages' => [
        'reminder_1' => ['enabled' => true, 'at' => '18:00'],
        'reminder_2' => ['enabled' => true, 'minutes_before' => 120],
    ],
]);

$slots = se_message_schedule($event, $days, $on);
is_same('one day gives two reminders', 2, count($slots));
is_same('reminder 1 is the evening before', '2026-10-23 18:00', $slots[0]['scheduled_for']->format('Y-m-d H:i'));
is_same('reminder 2 is two hours before the doors', '2026-10-24 15:00', $slots[1]['scheduled_for']->format('Y-m-d H:i'));
is_same('reminder 1 keys off the event date', 'reminder_1:20261024', $slots[0]['run_key']);
is_same('reminder 2 keys off its own day', 'reminder_2:20261024', $slots[1]['run_key']);

$twoDays = [
    ['day_date' => '2026-10-24', 'starts_at' => '2026-10-24 17:00:00', 'ends_at' => '2026-10-24 21:00:00'],
    ['day_date' => '2026-10-25', 'starts_at' => '2026-10-25 16:00:00', 'ends_at' => '2026-10-25 20:00:00'],
];

$multi = se_message_schedule($event, $twoDays, $on);
is_same('a two-day event still gets one "evening before"', 1,
    count(array_filter($multi, static fn(array $s): bool => $s['kind'] === 'reminder_1')));
is_same('…but a day-of reminder for each day', 2,
    count(array_filter($multi, static fn(array $s): bool => $s['kind'] === 'reminder_2')));

$keys = array_map(static fn(array $s): string => $s['run_key'], $multi);
is_same('and the two day-of keys differ, so neither blocks the other',
    2, count(array_unique(array_filter($keys, static fn(string $k): bool => str_starts_with($k, 'reminder_2')))));
ok('the second day\'s label names the day, so a producer can tell them apart',
    str_contains($multi[2]['label'], 'Oct') || str_contains($multi[2]['label'], '25'),
    $multi[2]['label']);

$off = se_settings_normalize([
    'messages' => [
        'reminder_1' => ['enabled' => false],
        'reminder_2' => ['enabled' => false],
    ],
]);
is_same('switching them off schedules nothing at all', 0, count(se_message_schedule($event, $days, $off)));

$noDays = se_message_schedule(['id' => 1, 'slug' => 'x', 'title' => 'X'], [], $on);
is_same('an event with no days has no schedule either', 0, count($noDays));
