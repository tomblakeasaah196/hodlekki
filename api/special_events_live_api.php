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
// PR3 shipped the show controls and desk, PR4 the programme and karaoke,
// PR5 the quiz engine and score ledger, and PR6 the party games and finale.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';
header('Content-Type: application/json');

se_require_request_integrity();
se_require_csrf();

$body   = se_request_body();
$action = se_str($body['action'] ?? '', 40);

/** Actions whose tables arrive with PR4/PR5. Routed, but honest about it. */
const SE_LIVE_LATER_ACTIONS = [];

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
        // Run of show (§10.7.2)
        // ------------------------------------------------------------------
        case 'program': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $program = se_program_op(
                $pdo,
                $event,
                se_int($body['item_id'] ?? 0, 0),
                se_str($body['op'] ?? '', 10),
                se_live_expected($body),
                se_live_actor()
            );

            $state = se_live_state($pdo, (int) $event['id']);
            se_api_success('Done.', [
                'program' => $program,
                'version' => $state ? (int) $state['version'] : 0,
            ]);
        }

        case 'program_move': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'host.control');

            $program = se_program_move(
                $pdo,
                $event,
                se_int($body['item_id'] ?? 0, 0),
                se_int_or_null($body['before_id'] ?? null, 0),
                se_live_expected($body),
                se_live_actor()
            );

            $state = se_live_state($pdo, (int) $event['id']);
            se_api_success('Moved.', [
                'program' => $program,
                'version' => $state ? (int) $state['version'] : 0,
            ]);
        }

        // ------------------------------------------------------------------
        // Karaoke (§10.8.2, §13.12)
        // ------------------------------------------------------------------
        case 'karaoke_queue': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'karaoke.queue');

            $state = se_live_state($pdo, (int) $event['id'], false);
            se_api_success('OK', se_karaoke_queue($pdo, $event) + [
                'version'   => $state ? (int) $state['version'] : 0,
                'server_ms' => se_epoch_ms(),
                'songs'     => se_songs_event_list($pdo, $event, ['per_page' => 100, 'active_only' => true])['items'],
            ]);
        }

        case 'karaoke_set': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'karaoke.queue');

            $queue = se_karaoke_set_status(
                $pdo,
                $event,
                se_int($body['entry_id'] ?? 0, 0),
                se_str($body['status'] ?? '', 20),
                se_live_expected($body),
                se_live_actor()
            );

            $state = se_live_state($pdo, (int) $event['id']);
            se_api_success('Updated.', $queue + ['version' => $state ? (int) $state['version'] : 0]);
        }

        case 'karaoke_move': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'karaoke.queue');

            $queue = se_karaoke_move(
                $pdo,
                $event,
                se_int($body['entry_id'] ?? 0, 0),
                se_int_or_null($body['before_id'] ?? null, 0),
                se_live_expected($body),
                se_live_actor()
            );

            $state = se_live_state($pdo, (int) $event['id']);
            se_api_success('Moved.', $queue + ['version' => $state ? (int) $state['version'] : 0]);
        }

        case 'karaoke_add': {
            $event = se_live_event($pdo, $body);
            se_require_capability($pdo, (int) $event['id'], 'karaoke.queue');

            $out = se_karaoke_add($pdo, $event, [
                'registration_id' => $body['registration_id'] ?? null,
                'player_no'       => $body['player_no'] ?? null,
            ], se_int($body['song_id'] ?? 0, 0), se_live_actor());

            se_api_success('Added to the queue.', $out);
        }

        case 'game_start': case 'game_pause': case 'game_finish': { $event=se_live_event($pdo,$body);se_require_capability($pdo,(int)$event['id'],'game.control');$status=['game_start'=>'live','game_pause'=>'paused','game_finish'=>'finished'][$action];$out=se_game_status_set($pdo,$event,se_int($body['game_id']??0,0),$status,se_live_expected($body),se_live_actor());se_api_success('Game updated.',['status'=>$status,'version'=>(int)$out['version']]); }
        // Games engine and scoring (PR5)
        case 'round_next': { $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control'); $g=se_game_list($pdo,(int)$event['id']); $game=null; foreach($g as $candidate){ if((int)$candidate['id']===(int)($body['game_id']??0)){ $game=$candidate; break; } } if(!$game) se_api_error('Game not found.','EVENT_NOT_FOUND'); se_api_success('Round ready.',se_round_next_live($pdo,$event,$game,se_bool(se_event_settings($event)['test_mode']??false),se_live_expected($body),se_live_actor())); }
        case 'round_arm': { $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control'); $s=$pdo->prepare('SELECT * FROM se_games WHERE id=? AND event_id=?');$s->execute([se_int($body['game_id']??0,0),(int)$event['id']]);$game=$s->fetch(PDO::FETCH_ASSOC)?:[];$settings=se_game_settings($game); se_api_success('Round armed.',se_round_arm_live($pdo,$event,se_int($body['round_id']??0,0),se_int($body['preroll_ms']??($settings['preroll_ms']??3000),2500),se_int($body['duration_ms']??($settings['duration_ms']??20000),1),se_live_expected($body),se_live_actor())); }
        case 'round_lock': case 'round_reveal': case 'round_score': case 'round_void': { $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control'); $to=['round_lock'=>'locked','round_reveal'=>'revealed','round_score'=>'scored','round_void'=>'void'][$action]; if($action==='round_score') se_api_success('Scored.',se_round_score_live($pdo,$event,se_int($body['round_id']??0,0),se_live_expected($body),se_live_actor())); $out=se_round_transition_live($pdo,$event,se_int($body['round_id']??0,0),$to,se_live_expected($body),se_live_actor(),se_line($body['reason']??'',160)); se_api_success('Round updated.',['version'=>(int)$out['version'],'round_id'=>se_int($body['round_id']??0,0),'state'=>$to]); }
        case 'score_adjust': { $event=se_live_event($pdo,$body);se_require_capability($pdo,(int)$event['id'],'score.manage');se_api_success('Score saved.',se_score_adjust_live($pdo,$event,$body,se_live_expected($body),se_live_actor())); }
        case 'score_void': { $event=se_live_event($pdo,$body);se_require_capability($pdo,(int)$event['id'],'score.manage');se_api_success('Score voided.',se_score_void_live($pdo,$event,se_int($body['score_id']??$body['score_event_id']??0,0),se_line($body['reason']??'',160),se_live_expected($body),se_live_actor())); }

        // Party games and finale (§11.8–§11.11)
        case 'clue_next': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            se_api_success('Next clue.', se_clue_next($pdo,$event,se_int($body['round_id']??0,0),se_live_expected($body),se_live_actor()));
        }
        case 'charades_turn': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            $player = ($body['player_no'] ?? '') === 'random' ? 'random' : se_int($body['player_no']??0,0);
            se_api_success('Presenter selected.', se_charades_turn($pdo,$event,se_int($body['round_id']??0,0),se_int($body['team_id']??0,0),$player,se_bool($body['show_on_console']??false),se_live_expected($body),se_live_actor()));
        }
        case 'charades_start': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            se_api_success('Turn starting.', se_charades_start($pdo,$event,se_int($body['round_id']??0,0),se_live_expected($body),se_live_actor()));
        }
        case 'charades_mark': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            se_api_success('Marked.', se_charades_mark($pdo,$event,se_int($body['round_id']??0,0),se_enum($body['result']??'', ['correct','pass'], ''),se_live_expected($body),se_live_actor()));
        }
        case 'charades_end': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            se_api_success('Turn ended.', se_charades_end($pdo,$event,se_int($body['round_id']??0,0),se_live_expected($body),se_live_actor()));
        }
        case 'buzz_judge': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            se_api_success('Buzz judged.', se_buzz_judge_party($pdo,$event,se_int($body['round_id']??0,0),se_int($body['buzz_id']??0,0),se_bool($body['correct']??false),se_live_expected($body),se_live_actor()));
        }
        case 'feud_faceoff': case 'feud_control': case 'feud_reveal': case 'feud_strike': case 'feud_steal': case 'feud_bank': case 'feud_reveal_all': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'game.control');
            $op=substr($action,5);
            se_api_success('Feud board updated.', se_feud_update($pdo,$event,se_int($body['round_id']??0,0),$op,$body,se_live_expected($body),se_live_actor()));
        }
        case 'finale': {
            $event=se_live_event($pdo,$body); se_require_capability($pdo,(int)$event['id'],'host.control');
            se_api_success('Finale started.', se_finale_start($pdo,$event,se_live_expected($body),se_live_actor()));
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
