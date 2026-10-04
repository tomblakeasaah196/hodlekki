<?php
// /api/special_events_display_api.php
//
// The display API (guide §12.3) — the two calls a venue screen makes.
//
// A display is a browser on a laptop plugged into a projector. It has no ERP
// session and no device cookie, so it authenticates with a display key from
// the URL the crew pasted in. Keys are compared with hash_equals, a wrong key
// is logged and throttled, and nothing here ever returns a name: the screens
// read the snapshot files, and this endpoint only tells them which files to
// read and how the event is dressed.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';
header('Content-Type: application/json');

se_require_request_integrity();

$body   = se_request_body();
$action = se_str($body['action'] ?? '', 40);

/** The event, by public id or slug. Drafts need the preview key, as elsewhere. */
function se_display_event(PDO $pdo, array $body): array
{
    $event = null;

    $publicId = se_str($body['event'] ?? '', 12);
    if ($publicId !== '') {
        $event = se_event_find_by_public_id($pdo, $publicId);
    }
    if (!$event) {
        $slug = se_str($body['slug'] ?? '', SE_SLUG_MAX_LENGTH);
        if ($slug !== '') {
            $event = se_event_find_by_slug($pdo, $slug);
        }
    }
    if (!$event) {
        se_api_error('We could not find that event.', 'EVENT_NOT_FOUND');
    }

    return $event;
}

/**
 * Check a display key against the live state.
 *
 * Wrong keys are rate-limited per IP (30 in 10 minutes) and audited, because
 * a screen URL leaking is the one way an outsider could watch the room.
 */
function se_display_key_check(PDO $pdo, array $event, array $state, string $column, string $given): void
{
    $expected = (string) ($state[$column] ?? '');

    if ($given !== '' && $expected !== '' && se_hash_equals($expected, $given)) {
        return;
    }

    $ip = function_exists('security_client_ip') ? security_client_ip() : (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    se_rate_limit($pdo, 'display_key_bad', $ip, 30, 600);
    error_log('SE display: bad ' . $column . ' for event ' . (int) $event['id'] . ' from ' . $ip);

    se_api_error('That screen link is not valid. Ask the Studio for a fresh one.', 'FORBIDDEN');
}

try {
    switch ($action) {

        // ------------------------------------------------------------------
        // display_boot — everything a screen needs once, at startup.
        // ------------------------------------------------------------------
        case 'display_boot': {
            $event = se_display_event($pdo, $body);
            $kind  = se_enum($body['kind'] ?? 'stage', ['stage', 'lobby'], 'stage');

            if (!se_live_ready($pdo)) {
                se_api_error('The live screens are not available yet.', 'FEATURE_NOT_READY');
            }

            $state = se_live_state($pdo, (int) $event['id']);
            if (!$state) {
                se_api_error('That event is not live yet.', 'FEATURE_NOT_READY');
            }

            $key = se_str($body['key'] ?? '', 32);
            se_display_key_check($pdo, $event, $state, $kind === 'lobby' ? 'lobby_key' : 'stage_key', $key);

            $days     = se_event_days($pdo, (int) $event['id']);
            $settings = se_event_settings($event);
            $theme    = se_event_theme($event);
            $publicId = (string) $event['public_id'];

            // The stage reads public.json plus the private room channel; the
            // lobby reads only its own, so a lobby key can never show a
            // game answer even if the URL ends up on a church WhatsApp group.
            $snapshots = ['public' => se_live_url($publicId, 'public')];
            if ($kind === 'stage') {
                $snapshots['room'] = se_live_url($publicId, 'room-' . $state['room_key']);
            } else {
                $snapshots['lobby'] = se_live_url($publicId, 'lobby-' . $state['lobby_key']);
            }

            se_api_success('OK', [
                'server_ms' => se_epoch_ms(),
                'kind'      => $kind,
                'event'     => [
                    'public_id' => $publicId,
                    'slug'      => (string) $event['slug'],
                    'title'     => (string) $event['title'],
                    'edition'   => (string) ($event['edition_label'] ?? ''),
                    'tagline'   => (string) ($event['tagline'] ?? ''),
                    'organizer' => (string) ($event['organizer_label'] ?? 'Envision'),
                    'venue'     => (string) ($event['venue_name'] ?? ''),
                    'starts_ms' => se_epoch_ms(se_parse_datetime($days[0]['starts_at'] ?? ($event['starts_at'] ?? null))),
                    'doors_ms'  => se_epoch_ms(se_parse_datetime($days[0]['doors_open_at'] ?? null)),
                ],
                'theme' => [
                    'css'    => se_theme_css_vars($theme, se_teams($pdo, (int) $event['id'])),
                    'tokens' => $theme['tokens'],
                    'fonts'  => [
                        'display' => (string) $event['font_display'],
                        'body'    => (string) $event['font_body'],
                    ],
                ],
                'snapshots'   => $snapshots,
                'sfx'         => ['cues' => SE_SFX_CUES, 'sprite' => '/assets/se/audio/sfx.mp3', 'map' => '/assets/se/audio/sfx.json'],
                'checkin_url' => se_event_url((string) $event['slug'], 'in'),
                'show_names'  => se_bool(se_settings_path($settings, 'lobby.show_names', true)),
                'test_mode'   => se_bool($settings['test_mode'] ?? false),
                'tick_ms'     => SE_TICK_MS,
                // The stage key is echoed so the screen can tick without the
                // operator having to keep the URL in the address bar.
                'stage_key'   => $kind === 'stage' ? (string) $state['stage_key'] : null,
            ]);
        }

        // ------------------------------------------------------------------
        // tick — the stage display's heartbeat (§8.5.6).
        // ------------------------------------------------------------------
        case 'tick': {
            $event = se_display_event($pdo, $body);

            if (!se_live_ready($pdo)) {
                se_api_error('The live screens are not available yet.', 'FEATURE_NOT_READY');
            }

            $state = se_live_state($pdo, (int) $event['id']);
            if (!$state) {
                se_api_error('That event is not live yet.', 'FEATURE_NOT_READY');
            }

            se_display_key_check($pdo, $event, $state, 'stage_key', se_str($body['stage_key'] ?? '', 32));

            se_api_success('OK', se_live_tick($pdo, $event));
        }

        // ------------------------------------------------------------------
        default:
            se_api_error('Unknown action.', 'BAD_REQUEST');
    }
} catch (Throwable $e) {
    se_api_fail($e, 'display/' . $action);
}
