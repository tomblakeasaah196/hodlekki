<?php
// /tests/special_events/snapshot_privacy_test.php — public.json must never
// carry a person (guide §8.5.4, §19.5, §22.1).
//
// The snapshot files under /live/<public_id>/ are served by Apache with no
// PHP in front of them. `public.json` in particular is readable by anyone
// who knows the event's public id, which is in every portal URL. So the one
// rule that matters is: it contains counts, colours and scene state, and
// never a name, a phone number, an email, an unrevealed answer or a
// charades phrase.
//
// Two complementary checks here, because one alone is not enough:
//
//   1. Behavioural — the pure builders are fed hostile input (a scene
//      payload stuffed with a roster, a stale announcement) and must drop
//      it. This is the realistic leak: scene_payload_json is free-form JSON
//      written by the host console.
//   2. Structural — se_snapshot_public()'s source is read and checked for
//      the PII column names. A future edit that joins se_contacts into the
//      public snapshot fails this file before it reaches a review.

/**
 * PHP source with its comments removed.
 *
 * The scan below looks for column names in CODE. A comment that happens to
 * say "the phones" is not a leak, and a test that cannot tell the two apart
 * would be turned off within a week.
 */
function se_test_strip_comments(string $php): string
{
    $out = '';
    foreach (token_get_all('<?php ' . $php) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $out .= $token[1];
        } else {
            $out .= $token;
        }
    }

    return $out;
}

/** Every scalar in a nested structure, with its key path. */
function se_test_flatten(mixed $value, string $path = ''): array
{
    if (is_array($value)) {
        $out = [];
        foreach ($value as $key => $child) {
            $out = array_merge($out, se_test_flatten($child, $path === '' ? (string) $key : $path . '.' . $key));
        }
        return $out;
    }

    return [$path => $value];
}

// --------------------------------------------------------------------------

echo "    the scene payload drops anything it was not asked for\n";

// The realistic accident: a console bug, or a future feature, puts the
// roster into the scene payload. se_scene_payload_clean() is an allow-list,
// so the names never reach the stored JSON in the first place.
$hostile = [
    'title'    => 'Welcome',
    'subtitle' => 'Good to see you',
    'roster'   => [['display_name' => 'Ada Obi', 'phone' => '+2348031234567']],
    'answers'  => ['the answer is Mary'],
];

$clean = se_scene_payload_clean('welcome', $hostile);

is_same('the welcome scene keeps only its two strings',
    ['title', 'subtitle'], array_keys($clean));
ok('and nothing resembling a person survives',
    !str_contains(se_json_encode($clean), 'Ada')
    && !str_contains(se_json_encode($clean), '803')
    && !str_contains(se_json_encode($clean), 'Mary'),
    se_json_encode($clean));

is_same('a scene with no payload of its own stores nothing at all',
    null, se_scene_payload_clean('teams', $hostile));
is_same('nor does an unknown scene', null, se_scene_payload_clean('not_a_scene', $hostile));

$break = se_scene_payload_clean('break', ['minutes' => 15, 'label' => 'Tea', 'secret' => 'x']);
is_same('the break scene keeps its three fields only',
    ['minutes', 'label', 'ends_ms'], array_keys($break));
is_same('and clamps the minutes', 15, $break['minutes']);
is_same('a silly break length is clamped, not rejected',
    240, se_scene_payload_clean('break', ['minutes' => 9999])['minutes']);

echo "    the scene block published to every phone\n";

$state = [
    'scene'              => 'welcome',
    'scene_payload_json' => se_json_encode(['since_ms' => 1700000000000, 'payload' => ['title' => 'Welcome']]),
    'announcement_json'  => null,
    'sfx_seq'            => 3,
    'sfx_cue'            => 'fanfare',
];

$scene = se_scene_payload($state);
is_same('the scene block is key, since_ms and payload',
    ['key', 'since_ms', 'payload'], array_keys($scene));
is_same('the scene key survives', 'welcome', $scene['key']);
is_same('and so does the start time, for the stage countdown', 1700000000000, $scene['since_ms']);

$noPayload = se_scene_payload(['scene' => 'blank', 'scene_payload_json' => null]);
is_same('a blank scene publishes a null payload, not an empty object', null, $noPayload['payload']);
is_same('and a null start time rather than zero', null, $noPayload['since_ms']);

echo "    announcements expire on their own\n";

$live = se_announcement_payload([
    'announcement_json' => se_json_encode([
        'text' => 'The bus leaves at 9:30', 'until_ms' => se_epoch_ms() + 60000, 'seconds' => 60,
    ]),
]);
is_same('a current announcement is published', 'The bus leaves at 9:30', $live['text']);
is_same('the block is just text and an expiry', ['text', 'until_ms'], array_keys($live));

is_same('an expired announcement disappears without anybody clearing it',
    null,
    se_announcement_payload([
        'announcement_json' => se_json_encode([
            'text' => 'Old news', 'until_ms' => se_epoch_ms() - 1000,
        ]),
    ]));

is_same('an empty announcement is no announcement',
    null, se_announcement_payload(['announcement_json' => se_json_encode(['text' => ''])]));
is_same('and neither is a missing one',
    null, se_announcement_payload(['announcement_json' => null]));

$forever = se_announcement_payload([
    'announcement_json' => se_json_encode(['text' => 'Standing notice', 'until_ms' => 0]),
]);
is_same('an announcement with no expiry stays up', null, $forever['until_ms']);

echo "    public team rows carry colour, never people\n";

$theme = se_event_theme(['brand_primary' => '#1D356A', 'brand_secondary' => '#D11920']);
$team  = [
    'id' => 7, 'sort_order' => 0, 'color_hex' => '#D11920', 'color_label' => 'Red',
    'name' => 'The Lions', 'team_key' => str_repeat('k', 22),
    'captain_registration_id' => 42,
];

$public = se_team_public($team, $theme);
is_same('a public team row is exactly the Appendix B fields',
    ['id', 'name', 'label', 'hex', 'on', 'glow', 'ring'], array_keys($public));
ok('the team key never leaves the server in a public row',
    !str_contains(se_json_encode($public), 'kkkk'), se_json_encode($public));
ok('nor does the captain\'s registration id',
    !str_contains(se_json_encode($public), '42'), se_json_encode($public));

echo "    no personal column is named anywhere in the public builder\n";

$source = (string) file_get_contents(__DIR__ . '/../../includes/special_events/live.php');

// Isolate se_snapshot_public()'s body: from its signature to the start of
// the next top-level function.
$start = strpos($source, 'function se_snapshot_public(');
ok('se_snapshot_public() exists to be checked', $start !== false);

$rest = substr($source, (int) $start);
$end  = strpos($rest, "\nfunction ", 1);
$body = se_test_strip_comments($end !== false ? substr($rest, 0, $end) : $rest);

foreach ([
    'display_name', 'first_name', 'last_name', 'phone_e164', 'phone', 'email',
    'se_contacts', 'reg_code', 'ref_code', 'player_no', 'captain',
] as $forbidden) {
    ok("public.json builder never mentions {$forbidden}",
        !str_contains($body, $forbidden),
        'found in se_snapshot_public()');
}

// The lobby snapshot is the one place names are allowed — and only when the
// event has asked for them. If that guard ever disappears this fails.
$lobbyStart = strpos($source, 'function se_snapshot_lobby(');
$lobbyRest  = substr($source, (int) $lobbyStart);
$lobbyEnd   = strpos($lobbyRest, "\nfunction ", 1);
$lobbyBody  = $lobbyEnd !== false ? substr($lobbyRest, 0, $lobbyEnd) : $lobbyRest;

ok('the lobby snapshot still checks lobby.show_names before printing anyone',
    str_contains($lobbyBody, 'show_names'));

echo "    the published key set matches Appendix B\n";

// The top-level keys the stage, the lobby and every phone rely on. Adding
// one is fine; this test exists so that it is a deliberate act.
foreach (['event', 'reg', 'counts', 'teams', 'program', 'scene', 'game', 'announcement', 'sfx'] as $key) {
    ok("public.json still publishes `{$key}`",
        (bool) preg_match("/'" . preg_quote($key, '/') . "'\s*=>/", $body));
}
