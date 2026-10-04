<?php
// /api/special_events_live_api.php
//
// The crew API behind the host console, the desk and the DJ booth
// (guide §12.4).
//
// Auth is an ERP session plus the X-SE-CSRF header plus a capability on this
// specific event — being signed in to the ERP is never enough. Every
// mutating action carries `expected_version`; if the live state has moved on
// since the console last polled, the action is refused with STALE_VERSION
// and the fresh state, so two producers tapping at once can never silently
// overwrite each other.
//
// PR3 ships the show controls (scenes, announcements, sound), the team
// actions and the whole desk. Game, score, programme and karaoke actions are
// routed to a deliberate FEATURE_NOT_READY so the console can show the tab
// greyed out instead of crashing on a missing case.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';
header('Content-Type: application/json');

se_require_request_integrity();
se_require_csrf();

$body   = se_request_body();
$action = se_str($body['action'] ?? '', 40);

/** Actions whose tables arrive with PR4/PR5. Routed, but honest about it. */
const SE_LIVE_LATER_ACTIONS = [
    'program', 'program_move',
    'game_start', 'game_pause', 'game_finish', 'round_next', 'round_arm',
    'round_lock', 'round_reveal', 'round_score', 'round_void',
    'charades_turn', 'charades_start', 'charades_mark', 'buzz_judge', 'clue_next',
    'feud_faceoff', 'feud_control', 'feud_reveal', 'feud_strike', 'feud_steal',
    'feud_bank', 'feud_reveal_all',
    'score_adjust', 'score_void',
    'karaoke_queue', 'karaoke_set', 'karaoke_move', 'karaoke_add',
];

/** The event this request is about. Crew actions always name it by public id. */
function se_live_event(PDO $pdo, array $body): array
{
    $publicId = se_str($body['event'] ?? '', 12);
    $event    = $publicId !== '' ? se_event_find_by_public_id($pdo, $publicId) : null;

    if (!$event && isset($body['event_id'])) {
        $event = se_event_find($pdo, se_int($body['event_id'], 0));
    }
    if (!$event) {
        se_api_error('We could not find that event.', 'EVENT_NOT_FOUND');
    }

    return $event;
}

/** `expected_version` from the console's last poll, or null when not sent. */
function se_live_expected(array $body): ?int
{
    return isset($body['expected_version']) ? se_int_or_null($body['expected_version'], 0) : null;
}

/** The actor's user id. se_require_capability() has already proved it exists. */
function se_live_actor(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

/** Everything the host console redraws itself from (§12.4 `console`). */
function se_live_console(PDO $pdo, array $event): array
{
    $eventId  = (int) $event['id'];
    $days     = se_event_days($pdo, $eventId);
    $settings = se_event_settings($event);
    $state    = se_live_state($pdo, $eventId);
    $phase    = se_event_phase($event, $days);
    $counts   = se_capacity_counts($pdo, $eventId);
    $theme    = se_event_theme($event);

    $teams = [];
    foreach (se_teams($pdo, $eventId) as $team) {
        $teams[] = se_team_public($team, $theme) + [
            'team_key' => (string) $team['team_key'],
            'captain_registration_id' => $team['captain_registration_id'] !== null
                ? (int) $team['captain_registration_id'] : null,
        ];
    }

    return [
        'server_ms' => se_epoch_ms(),
        'event'     => [
            'public_id' => (string) $event['public_id'],
            'slug'      => (string) $event['slug'],
            'title'     => (string) $event['title'],
            'edition'   => (string) ($event['edition_label'] ?? ''),
            'phase'     => (string) ($phase['phase'] ?? 'upcoming'),
            'test_mode' => se_bool($settings['test_mode'] ?? false),
        ],
        'state' => $state ? [
            'version'      => (int) $state['version'],
            'scene'        => (string) $state['scene'],
            'scene_payload' => se_scene_payload($state),
            'announcement' => se_announcement_payload($state),
            'sfx'          => ['seq' => (int) $state['sfx_seq'], 'cue' => $state['sfx_cue']],
        ] : null,
        'scenes'  => SE_SCENES,
        'cues'    => SE_SFX_CUES,
        'teams'   => $teams,
        'roster'  => se_teams_roster($pdo, $eventId),
        'counts'  => [
            'checked_in'   => se_checkin_total($pdo, $eventId, se_now()->format('Y-m-d')),
            'confirmed'    => (int) ($counts['confirmed'] ?? 0),
            'joined_games' => se_joined_games_count($pdo, $eventId),
        ],
        'checkin'  => se_checkin_window($event, $days),
        'health'   => se_live_health($pdo, $event, $state),
        'displays' => se_display_links($event, $state),
        // PR4 fills these from se_program_items, se_games and se_rounds.
        'program'  => null,
        'game'     => null,
        'round'    => null,
        'karaoke'  => null,
    ];
}

/** The desk's lighter poll: counts and the window, never any game data. */
function se_desk_state(PDO $pdo, array $event): array
{
    $eventId = (int) $event['id'];
    $days    = se_event_days($pdo, $eventId);
    $counts  = se_capacity_counts($pdo, $eventId);
    $state   = se_live_state($pdo, $eventId, false);

    return [
        'server_ms'  => se_epoch_ms(),
        'checked_in' => se_checkin_total($pdo, $eventId, se_now()->format('Y-m-d')),
        'confirmed'  => (int) ($counts['confirmed'] ?? 0),
        'walkins'    => (int) ($counts['walkin_taken'] ?? 0),
        'walkin_free' => se_walkin_free($event, $counts),
        'seats_left' => se_seats_left($event, $counts),
        'checkin'    => se_checkin_window($event, $days),
        'test_mode'  => se_bool(se_event_settings($event)['test_mode'] ?? false),
        'health'     => se_live_health($pdo, $event, $state),
        'teams'      => array_map(
            static fn(array $t): array => se_team_public($t, se_event_theme($event)),
            se_teams($pdo, $eventId)
        ),
    ];
}

try {
    if (in_array($action, SE_LIVE_LATER_ACTIONS, true)) {
        se_api_error('That control arrives in a later release.', 'FEATURE_NOT_READY');
    }

    switch ($action) {

        // ------------------------------------------------------------------
        // Polls
        // ------------------------------------------------------------------
        case 'console': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            if (se_live_ready($pdo)) {
                se_live_tick($pdo, $event, se_live_actor());
                $event = se_event_find($pdo, (int) $event['id']) ?? $event;
            }

            se_api_success('OK', se_live_console($pdo, $event));
        }

        case 'desk_state': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'desk.checkin');

            se_api_success('OK', se_desk_state($pdo, $event));
        }

        // ------------------------------------------------------------------
        // Show controls (§11.12)
        // ------------------------------------------------------------------
        case 'scene': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $state = se_scene_set(
                $pdo,
                $event,
                se_str($body['scene'] ?? '', 30),
                $body['payload'] ?? [],
                se_live_expected($body),
                se_live_actor()
            );

            se_api_success('Scene set.', ['version' => (int) $state['version'], 'scene' => (string) $state['scene']]);
        }

        case 'announce': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $state = se_announce(
                $pdo,
                $event,
                se_line($body['text'] ?? '', 160),
                se_int($body['seconds'] ?? 20, 3, 600, 20),
                se_live_expected($body),
                se_live_actor()
            );

            se_api_success('On screen.', ['version' => (int) $state['version']]);
        }

        case 'announce_clear': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $state = se_announce_clear($pdo, $event, se_live_expected($body), se_live_actor());

            se_api_success('Cleared.', ['version' => (int) $state['version']]);
        }

        case 'sound': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $state = se_sound_cue(
                $pdo,
                $event,
                se_str($body['cue'] ?? '', 20),
                se_live_expected($body),
                se_live_actor()
            );

            se_api_success('Played.', [
                'version' => (int) $state['version'],
                'sfx'     => ['seq' => (int) $state['sfx_seq'], 'cue' => $state['sfx_cue']],
            ]);
        }

        case 'publish_now': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            se_live_publish($pdo, (int) $event['id'], true);
            $state = se_live_state($pdo, (int) $event['id']);

            se_api_success('Screens refreshed.', ['version' => $state ? (int) $state['version'] : 0]);
        }

        // ------------------------------------------------------------------
        // Teams (§10.6.4)
        // ------------------------------------------------------------------
        case 'team_name': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'team.rename');

            $out = se_team_rename(
                $pdo,
                $event,
                se_int($body['team_id'] ?? 0, 0),
                se_line($body['name'] ?? '', 40),
                se_live_expected($body),
                se_live_actor()
            );

            se_api_success('Named.', [
                'team'    => se_team_public($out['team'], se_event_theme($event)),
                'version' => (int) $out['state']['version'],
            ]);
        }

        case 'team_move': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'team.move');

            $out = se_team_move(
                $pdo,
                $event,
                se_int($body['registration_id'] ?? 0, 0),
                se_int($body['team_id'] ?? 0, 0),
                se_line($body['reason'] ?? '', 160),
                se_live_actor()
            );

            se_api_success($out['moved'] ? 'Moved.' : 'Already on that team.', [
                'team' => se_team_public($out['team'], se_event_theme($event)),
            ]);
        }

        case 'captain_set': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'team.rename');

            // Either a registration id or the player number the host can see
            // on the badge — on a loud stage the number is what gets shouted.
            $registrationId = se_int_or_null($body['registration_id'] ?? null, 1);
            if ($registrationId === null && isset($body['player_no'])) {
                $stmt = $pdo->prepare("SELECT id FROM se_registrations WHERE event_id = ? AND player_no = ?");
                $stmt->execute([(int) $event['id'], se_int($body['player_no'], 0)]);
                $found = $stmt->fetchColumn();
                if ($found === false) {
                    se_api_error('No one here has that number.', 'EVENT_NOT_FOUND');
                }
                $registrationId = (int) $found;
            }

            $team = se_captain_set(
                $pdo,
                $event,
                se_int($body['team_id'] ?? 0, 0),
                $registrationId,
                se_live_actor()
            );

            se_api_success('Captain set.', ['team' => se_team_public($team, se_event_theme($event))]);
        }

        // ------------------------------------------------------------------
        // Desk (§10.5, §13.11)
        // ------------------------------------------------------------------
        case 'desk_search': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'attendee.search');

            $days = se_event_days($pdo, (int) $event['id']);

            se_api_success('OK', [
                'results' => se_desk_search($pdo, $event, $days, se_line($body['q'] ?? '', 60)),
            ]);
        }

        case 'desk_checkin':
        case 'desk_walkin': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'desk.checkin');

            $days = se_event_days($pdo, (int) $event['id']);
            $in   = [
                'gender'             => $body['gender'] ?? null,
                'override_walkin_cap' => se_bool($body['override_walkin_cap'] ?? false),
            ];

            if ($action === 'desk_walkin') {
                $phone = se_phone_normalize($body['phone'] ?? '');
                if ($phone === null) {
                    se_api_error('Please enter a mobile number, e.g. 0803 123 4567.', 'INVALID_PHONE');
                }
                $settings = se_event_settings($event);
                $in += [
                    'phone'        => $phone,
                    'first_name'   => $body['first_name'] ?? '',
                    'last_name'    => $body['last_name'] ?? '',
                    'email'        => $body['email'] ?? null,
                    'consent'      => se_bool($body['consent'] ?? false),
                    'consent_text' => (string) ($settings['registration']['consent_text'] ?? ''),
                    'karaoke_interest' => se_bool($body['karaoke_interest'] ?? false),
                ];
            } else {
                $in['registration_id'] = se_int($body['registration_id'] ?? 0, 0);
            }

            $result = se_checkin($pdo, $event, $days, $in, [
                'method'        => 'desk',
                'device'        => null,
                'actor_user_id' => se_live_actor(),
                'ip_hash'       => se_ip_hash(),
                'is_crew'       => true,
            ]);

            // The desk screen shows the guest's card, so `for` is always the
            // person being checked in, never the tablet's own identity.
            $payload = $result['payload'];
            $payload['for'] = 'other';
            unset($payload['room_key'], $payload['team_key']);

            se_api_success(
                $result['outcome'] === 'checked_in' ? 'Checked in.' : 'Already checked in.',
                $payload
            );
        }

        case 'desk_transfer_code': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'desk.checkin');

            $days = se_event_days($pdo, (int) $event['id']);
            $out  = se_transfer_code_issue(
                $pdo,
                $event,
                $days,
                se_int($body['registration_id'] ?? 0, 0),
                se_live_actor()
            );

            se_api_success('Read this code to the guest.', $out);
        }

        case 'desk_undo_checkin': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'desk.checkin');

            $days = se_event_days($pdo, (int) $event['id']);
            $out  = se_checkin_undo(
                $pdo,
                $event,
                $days,
                se_int($body['registration_id'] ?? 0, 0),
                se_line($body['reason'] ?? '', 160),
                se_live_actor()
            );

            se_api_success('Undone.', $out);
        }

        // ------------------------------------------------------------------
        // Test mode (§11.13) — producer only
        // ------------------------------------------------------------------
        case 'test_mode': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'event.edit');

            if (se_bool($body['reset'] ?? false)) {
                $removed = se_reset_rehearsal($pdo, $event, se_live_actor());
                se_api_success('Rehearsal cleared.', ['removed' => $removed]);
            }

            $fresh = se_test_mode_set($pdo, $event, se_bool($body['on'] ?? false), se_live_actor());

            se_api_success(
                se_bool($body['on'] ?? false) ? 'Test mode is ON.' : 'Test mode is off.',
                ['test_mode' => se_bool(se_event_settings($fresh)['test_mode'] ?? false)]
            );
        }

        // ------------------------------------------------------------------
        default:
            se_api_error('Unknown action.', 'BAD_REQUEST');
    }
} catch (Throwable $e) {
    se_api_fail($e, 'live/' . $action);
}
