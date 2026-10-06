<?php
// /tests/special_events/program_publish_test.php — the programme's publish
// gate and the data behind the programme poster (guide §10.7.2, §14.2b).
//
// Two promises are being guarded here:
//
//   1. A run of show is a working document. Until somebody publishes it, the
//      event page must not mention it at all — and the gate must cost the
//      page nothing, so it is checked before the programme is ever read.
//   2. The crew screens are NOT gated. The stage, the host console and the
//      lobby run off the live snapshots, which keep the real programme
//      whether or not guests can see it.

echo "    the setting itself\n";
$defaults = se_settings_defaults();
// The spec default and the new-event default deliberately disagree; see
// se_settings_for_new_event(). An event that predates the key has no
// `published` in its settings_json, so the spec default is what its page
// reads — and a deploy must never take a live programme off a church's
// page before anybody has had the chance to flip a switch.
is_same('an event written before this key keeps its page', true, $defaults['program']['published']);
is_same('se_settings_default() reads the same path', true, se_settings_default('program.published'));
is_same('the time mode is untouched', 'approximate', $defaults['program']['public_time_mode']);

echo "    but a brand new event starts unpublished\n";
$fresh = se_settings_for_new_event([]);
is_same('created with the gate shut', false, $fresh['program']['published']);
is_same('null is the same as nothing', false, se_settings_for_new_event(null)['program']['published']);
is_same('a JSON string is accepted too', false, se_settings_for_new_event('{}')['program']['published']);
is_same('everything else is still the Appendix E default', $defaults['portal']['faq'], $fresh['portal']['faq']);
is_same('an explicit true is still honoured', true,
    se_settings_for_new_event(['program' => ['published' => true]])['program']['published']);
ok('a clone shuts the gate itself, like the song list', (bool) preg_match(
    "/\\\$settings\\['karaoke'\\]\\['list_published'\\] = false;.*?\\\$settings\\['program'\\]\\['published'\\] = false;/s",
    (string) file_get_contents(__DIR__ . '/../../includes/special_events/events.php')
));
is_same('a sibling key in the branch does not open the gate', false,
    se_settings_for_new_event(['program' => ['public_time_mode' => 'exact']])['program']['published']);
is_same('and that sibling is honoured', 'exact',
    se_settings_for_new_event(['program' => ['public_time_mode' => 'exact']])['program']['public_time_mode']);
ok('se_event_create() uses it', str_contains(
    (string) file_get_contents(__DIR__ . '/../../includes/special_events/events.php'),
    'se_settings_for_new_event($input[\'settings\'] ?? [])' 
));

$on = se_settings_normalize(['program' => ['published' => true]]);
is_same('publishing stores true', true, $on['program']['published']);
is_same('and keeps the default time mode', 'approximate', $on['program']['public_time_mode']);
is_same('"1" from a form is still true', true,
    se_settings_normalize(['program' => ['published' => '1']])['program']['published']);
is_same('unpublishing stores false', false,
    se_settings_normalize(['program' => ['published' => false]])['program']['published']);
is_same('a saved time mode survives a publish', 'order_only',
    se_settings_normalize(['program' => ['published' => true, 'public_time_mode' => 'order_only']])['program']['public_time_mode']);

echo "    the gate on the event page\n";

/** A PDO that would fatal if it were used: the gate must not get that far. */
final class SeUnusablePdo extends PDO
{
    public function __construct() {}
}

$event = ['id' => 1, 'slug' => 'chara', 'title' => 'Chara'];
is_same('unpublished means no section, without touching the database', null,
    se_portal_program_public(new SeUnusablePdo(), $event, [], se_settings_normalize(['program' => ['published' => false]])));
is_same('an explicit false is enough on its own', null,
    se_portal_program_public(new SeUnusablePdo(), $event, [], ['program' => ['published' => false]]));
ok('se_portal_program() renders nothing for a null programme', (static function (): bool {
    ob_start();
    se_portal_program(null);

    return trim((string) ob_get_clean()) === '';
})());
ok('a published programme does render a section', (static function (): bool {
    ob_start();
    se_portal_program([
        'days' => [[
            'day_id' => 7, 'day_date' => '2026-10-24', 'label' => 'Saturday',
            'items' => [[
                'id' => 1, 'title' => 'Worship', 'kind' => 'worship', 'blurb' => '',
                'host' => '', 'featured' => true, 'status' => 'planned',
                'time' => '~4:15 PM', 'duration_min' => 30,
            ]],
        ]],
        'now' => null, 'next' => null, 'drift_min' => 0, 'time_mode' => 'approximate',
    ]);
    $html = (string) ob_get_clean();

    return str_contains($html, 'Worship') && str_contains($html, 'se-programme');
})());

echo "    the page menu follows the gate\n";
$menu = static function (bool $hasProgram) use ($event): string {
    ob_start();
    se_portal_topbar($event + ['public_id' => 'abc'], 'Envision', $hasProgram);

    return (string) ob_get_clean();
};
ok('no programme, no menu entry for one', !str_contains($menu(false), '#se-programme'));
ok('published, the menu can jump to it', str_contains($menu(true), '#se-programme'));
ok('the chapters entry is still there either way', str_contains($menu(false), '#se-chapters'));
ok('and it no longer calls itself the programme',
    substr_count($menu(false), '>Programme<') === 0);

echo "    the crew never lose sight of it\n";
$live = (string) file_get_contents(__DIR__ . '/../../includes/special_events/live.php');
ok('the live snapshots do not read the publish gate', !str_contains($live, "['published']"));
ok('public.json still carries the programme', str_contains($live, 'se_program_public($pdo, $event, $days, $settings)'));

echo "    poster sizes\n";
is_same('two sizes, no more', ['a4', 'screen'], array_keys(SE_PROGRAM_POSTER_SIZES));
is_same('A4 is 210mm at 300dpi', 2480, SE_PROGRAM_POSTER_SIZES['a4']['w']);
is_same('A4 is 297mm at 300dpi', 3508, SE_PROGRAM_POSTER_SIZES['a4']['h']);
is_same('the screen size is 16:9', 16 / 9,
    SE_PROGRAM_POSTER_SIZES['screen']['w'] / SE_PROGRAM_POSTER_SIZES['screen']['h']);
is_same('and it is 1080p', 1920, SE_PROGRAM_POSTER_SIZES['screen']['w']);

echo "    the footnote matches the promise the page makes\n";
is_same('approximate times get the portal\'s own words',
    'Times are approximate — the night runs on joy, not a stopwatch.',
    se_program_poster_note('approximate'));
is_same('exact times need no excuse', '', se_program_poster_note('exact'));
is_same('order only says so', 'In this order on the night.', se_program_poster_note('order_only'));
is_same('an unknown mode falls back to approximate',
    se_program_poster_note('approximate'), se_program_poster_note('nonsense'));

echo "    the day line on the poster\n";
is_same('a named day keeps its name and gains the date', 'Saturday · Saturday 24 October',
    se_program_poster_day_label(['day_date' => '2026-10-24', 'label' => 'Saturday']));
is_same('an unnamed day is just the date', 'Saturday 24 October',
    se_program_poster_day_label(['day_date' => '2026-10-24', 'label' => null]));
is_same('a blank label does not leave a dangling separator', 'Saturday 24 October',
    se_program_poster_day_label(['day_date' => '2026-10-24', 'label' => '   ']));
is_same('an unreadable date falls back to the label', 'Night two',
    se_program_poster_day_label(['day_date' => 'not a date', 'label' => 'Night two']));
