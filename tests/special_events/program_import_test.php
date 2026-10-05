<?php
// /tests/special_events/program_import_test.php
//
// The source schedule may arrive as a WhatsApp paste, a screenshot or a PDF.
// The model turns a written range into start/end clocks; normalisation owns the
// durable duration arithmetic and must stay correct even before a human edits
// the review table.

$importDays = [[
    'id' => 41,
    'day_date' => '2026-10-24',
    'starts_at' => '2026-10-24 15:00:00',
    'ends_at' => '2026-10-24 22:00:00',
]];

$sourceSchedule = [
    ['title' => 'Photo Booth and games', 'kind' => 'other', 'start_time' => '15:30', 'end_time' => '16:30'],
    ['title' => 'Welcome', 'kind' => 'welcome', 'start_time' => '16:30', 'end_time' => '16:35'],
    ['title' => 'Bingo', 'kind' => 'game', 'start_time' => '16:35', 'end_time' => '16:45'],
    ['title' => 'Kahoot', 'kind' => 'game', 'start_time' => '16:45', 'end_time' => '17:00'],
    ['title' => 'Ministration', 'kind' => 'other', 'start_time' => '17:00', 'end_time' => '17:10'],
    ['title' => 'Draw your swords', 'kind' => 'game', 'start_time' => '17:10', 'end_time' => '17:20'],
    ['title' => 'Charades', 'kind' => 'game', 'start_time' => '17:20', 'end_time' => '17:35'],
    ['title' => 'Preach this item', 'kind' => 'other', 'start_time' => '17:35', 'end_time' => '17:45'],
    ['title' => 'Pastor Ebele', 'kind' => 'word', 'start_time' => '17:45', 'end_time' => '18:00'],
    ['title' => 'Plead your case', 'kind' => 'game', 'start_time' => '18:00', 'end_time' => '18:25'],
    ['title' => 'Announcement', 'kind' => 'announcement', 'start_time' => '18:25', 'end_time' => '18:30'],
    ['title' => 'Karaoke', 'kind' => 'karaoke', 'start_time' => '18:30', 'end_time' => '18:45'],
];

$normalised = se_program_import_normalize($sourceSchedule, $importDays);

is_same('all ranged programme rows are retained', 12, count($normalised));
is_same('the first range starts at 3:30 PM on the event day', '2026-10-24T15:30:00+01:00', $normalised[0]['start_time']);
is_same('a 3:30 PM–4:30 PM range is 60 minutes', 60, $normalised[0]['duration_min']);
is_same('a five-minute range stays five minutes', 5, $normalised[1]['duration_min']);

$rangeWins = se_program_import_normalize([
    ['title' => 'Photo booth', 'start_time' => '15:30', 'end_time' => '16:30', 'duration_min' => 10],
], $importDays);
is_same('a written range wins if a model also returns a conflicting duration', 60, $rangeWins[0]['duration_min']);
is_same('Kahoot range stays editable at 15 minutes', 15, $normalised[3]['duration_min']);
is_same('the final karaoke range stays editable at 15 minutes', 15, $normalised[11]['duration_min']);
ok('an explicit range is not marked as guessed', !$normalised[0]['duration_guessed']);
is_same('all rows attach to the event day', 41, $normalised[8]['day_id']);

$nextStart = se_program_import_normalize([
    ['title' => 'Welcome', 'kind' => 'welcome', 'start_time' => '16:30'],
    ['title' => 'Bingo', 'kind' => 'game', 'start_time' => '16:35'],
], $importDays);
is_same('a missing duration is derived from the next start', 5, $nextStart[0]['duration_min']);
ok('a next-start duration is marked as guessed for review', $nextStart[0]['duration_guessed']);
is_same('the last row without a duration falls back to ten minutes', 10, $nextStart[1]['duration_min']);

$amPm = se_program_import_normalize([
    ['title' => 'Photo booth', 'start_time' => '3:30 PM', 'end_time' => '4:30 PM'],
], $importDays);
is_same('a legible 12-hour fallback retains the right clock', '2026-10-24T15:30:00+01:00', $amPm[0]['start_time']);
is_same('a legible 12-hour fallback retains the range duration', 60, $amPm[0]['duration_min']);

$overLimit = [];
for ($i = 1; $i <= 65; $i++) {
    $overLimit[] = ['title' => 'Programme item ' . $i];
}
is_same('programme normalisation enforces the 60-row AI import cap', 60,
    count(se_program_import_normalize($overLimit, $importDays)));
