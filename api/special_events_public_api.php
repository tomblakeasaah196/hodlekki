<?php
// /api/special_events_public_api.php
//
// The public API behind /e/<slug> (guide §12.2). No ERP session, no CSRF
// token — the caller is a guest's phone. What stands in for authentication:
//
//   * X-SE-Request: 1 plus the Origin / Sec-Fetch-Site checks (§19.3), which
//     a cross-origin page cannot forge without a preflight we never grant;
//   * the device cookie, or a 22-character manage token, for anything that
//     touches one person's own registration;
//   * rate limits per device, per IP and per phone (§12.1).
//
// Implemented so far: time, bootstrap, lookup, register, wants_visit,
// request_link, claim_link, me, cancel, optout, card, beacon (PR2),
// check-in and transfer (PR3), songs and karaoke (PR4), and games, buzzing,
// suggestions and Family Feud surveys (PR5–PR6). Feedback belongs to PR7.

require_once '../includes/db.php';
require_once '../includes/special_events/bootstrap.php';
header('Content-Type: application/json');

// --------------------------------------------------------------------------
// time — the only GET (§12.1). Used to align countdowns with the server.
// --------------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['action'] ?? '') === 'time') {
    se_api_success('OK', ['server_ms' => se_epoch_ms()]);
}

se_require_request_integrity();

$body   = se_request_body();
$action = se_str($body['action'] ?? '', 60);

// --------------------------------------------------------------------------
// Event
// --------------------------------------------------------------------------

/**
 * Resolve the event from `event` (public id) or `slug`.
 *
 * Draft and unlisted events are reachable with the preview key only, exactly
 * as /e/index.php treats them, so a public id alone never leaks a draft.
 */
function se_public_event(PDO $pdo, array $body): array
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

    if ((string) $event['status'] === 'draft') {
        $key = se_str($body['k'] ?? '', 22);
        if ($key === '' || !se_hash_equals((string) $event['preview_key'], $key)) {
            se_api_error('We could not find that event.', 'EVENT_NOT_FOUND');
        }
    }

    return $event;
}

/** Writes are refused once an event is cancelled or archived (§12.1). */
function se_public_require_writable(array $event): void
{
    if ((string) $event['status'] === 'cancelled') {
        se_api_error('This event has been cancelled.', 'EVENT_CANCELLED');
    }
    if ((string) $event['status'] === 'archived') {
        se_api_error('This event is over.', 'EVENT_ARCHIVED');
    }
}

// --------------------------------------------------------------------------
// Caller identity
// --------------------------------------------------------------------------

/**
 * The rate-limit subject for this caller: the device cookie when there is
 * one, otherwise IP + user agent, so a brand-new phone is still bucketed.
 */
function se_public_subject(array $event): string
{
    $cookie = se_device_cookie_value((string) $event['public_id']);
    if ($cookie !== null) {
        return 'dev:' . $cookie;
    }

    return 'anon:' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
}

/** The two limits every bucket has: per device (or anon) and per IP. */
function se_public_limit(PDO $pdo, array $event, string $bucket, int $perDevice, int $perIp, int $window): void
{
    se_rate_limit_or_fail($pdo, $bucket, se_public_subject($event), $perDevice, $window);

    if (function_exists('security_client_ip')) {
        se_rate_limit_or_fail($pdo, $bucket . '_ip', security_client_ip(), $perIp, $window);
    }
}

/**
 * The registration this request may act on: the device's own, or the one a
 * manage token names ("device/token" auth in §12.2).
 *
 * @return array{0: array, 1: ?array, 2: ?string} [registration, device, token]
 */
function se_public_actor(PDO $pdo, array $event, array $body, bool $required = true): array
{
    $cookie = se_device_cookie_value((string) $event['public_id']);
    $device = se_device_load($pdo, $event, $cookie);

    $token = se_str($body['token'] ?? '', 64);
    if ($token !== '') {
        $row = se_token_lookup($pdo, $token, 'manage', (int) $event['id']);
        if (!$row) {
            se_api_error('That link has expired. Ask for a new one below.', 'TOKEN_INVALID');
        }
        $reg = se_registration_by_id($pdo, (int) $row['registration_id'], (int) $event['id']);
        if ($reg) {
            se_device_touch($pdo, $device);
            return [$reg, $device, $token];
        }
    }

    if ($device && $device['registration_id'] !== null) {
        $reg = se_registration_by_id($pdo, (int) $device['registration_id'], (int) $event['id']);
        if ($reg) {
            se_device_touch($pdo, $device);
            return [$reg, $device, null];
        }
    }

    if ($required) {
        se_api_error('We do not have a registration on this phone yet.', 'NOT_REGISTERED');
    }

    return [[], $device, null];
}

/** A phone from the request, or INVALID_PHONE. */
function se_public_phone(mixed $raw): array
{
    $phone = se_phone_normalize($raw);
    if ($phone === null) {
        se_api_error('Please enter a mobile number, e.g. 0803 123 4567.', 'INVALID_PHONE');
    }

    return $phone;
}

// --------------------------------------------------------------------------
// Shared payloads
// --------------------------------------------------------------------------

/** The public view of an event, as the portal and the boot payload need it. */
function se_public_event_payload(PDO $pdo, array $event, array $days, array $settings, array $phase, array $counts): array
{
    $first = $days[0] ?? null;

    return [
        'public_id'  => (string) $event['public_id'],
        'slug'       => (string) $event['slug'],
        'title'      => (string) $event['title'],
        'edition'    => (string) ($event['edition_label'] ?? ''),
        'tagline'    => (string) ($event['tagline'] ?? ''),
        'organizer'  => (string) ($event['organizer_label'] ?? 'Envision'),
        'status'     => (string) $event['status'],
        'cancel_reason' => (string) ($event['cancel_reason'] ?? ''),
        'venue'      => [
            'name'    => (string) ($event['venue_name'] ?? ''),
            'address' => (string) ($event['venue_address'] ?? ''),
            'map_url' => (string) ($event['venue_map_url'] ?? ''),
            'notes'   => (string) ($event['venue_notes'] ?? ''),
        ],
        'starts_at'    => se_iso($first['starts_at'] ?? $event['starts_at']),
        'starts_at_ms' => se_epoch_ms(se_parse_datetime($first['starts_at'] ?? $event['starts_at'])),
        'days'         => array_map(static fn(array $d) => [
            'date'       => (string) $d['day_date'],
            'label'      => (string) ($d['label'] ?? ''),
            'doors_ms'   => se_epoch_ms(se_parse_datetime($d['doors_open_at'])),
            'starts_ms'  => se_epoch_ms(se_parse_datetime($d['starts_at'])),
            'ends_ms'    => se_epoch_ms(se_parse_datetime($d['ends_at'])),
        ], $days),
        'url'   => se_event_url((string) $event['slug']),
        'phase' => (string) ($phase['phase'] ?? 'upcoming'),
    ];
}

/** `reg` — what the sticky CTA switches on. */
function se_public_reg_payload(array $event, array $settings, array $phase, array $counts, ?DateTimeImmutable $now = null): array
{
    $state = se_registration_state($event, $phase, $counts, $now);

    return [
        'state'      => $state,
        'message'    => se_registration_state_message($state, $event),
        'seats_left' => se_seats_left($event, $counts),
        'opens_at'   => se_iso($event['reg_opens_at'] ?? null),
        'closes_at'  => se_iso($event['reg_closes_at'] ?? ($phase['first_day']['starts_at'] ?? null)),
        'self_cancel'   => se_bool($event['self_cancel_enabled'] ?? 0),
        'link_on_demand' => se_bool($settings['registration']['link_on_demand_enabled'] ?? true),
        'companions_max' => (int) ($settings['registration']['companions_max'] ?? 0),
    ];
}

// --------------------------------------------------------------------------
// Routing
// --------------------------------------------------------------------------

try {
    switch ($action) {

        // ------------------------------------------------------------------
        case 'time': {
            se_api_success('OK', ['server_ms' => se_epoch_ms()]);
        }

        // ------------------------------------------------------------------
        case 'bootstrap': {
            $event    = se_public_event($pdo, $body);
            $days     = se_event_days($pdo, (int) $event['id']);
            $settings = se_event_settings($event);
            $phase    = se_event_phase($event, $days);
            $counts   = se_capacity_counts($pdo, (int) $event['id']);

            [$reg, $device] = se_public_actor($pdo, $event, $body, false);

            se_api_success('OK', [
                'server_ms' => se_epoch_ms(),
                'event'     => se_public_event_payload($pdo, $event, $days, $settings, $phase, $counts),
                'phase'     => (string) ($phase['phase'] ?? 'upcoming'),
                'reg'       => se_public_reg_payload($event, $settings, $phase, $counts),
                'me'        => $reg ? se_me_payload($pdo, $event, $days, $reg, $device) : null,
            ]);
        }

        // ------------------------------------------------------------------
        case 'lookup': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'lookup', 20, 400, 600);

            $purpose = se_enum($body['purpose'] ?? 'register', ['register', 'checkin'], 'register');

            $phone = se_public_phone($body['phone'] ?? '');
            se_rate_limit_or_fail($pdo, 'lookup_phone', (int) $event['id'] . ':' . $phone['e164'], 10, 600);

            $settings = se_event_settings($event);
            [, $device] = se_public_actor($pdo, $event, $body, false);

            if ($purpose === 'checkin') {
                if (!se_checkin_ready($pdo)) {
                    se_api_error('Check-in is not available yet.', 'FEATURE_NOT_READY');
                }
                $days = se_event_days($pdo, (int) $event['id']);
                se_api_success('OK', se_lookup_phone_checkin($pdo, $event, $days, $settings, $phone, $device));
            }

            se_api_success('OK', se_lookup_phone($pdo, $event, $settings, $phone, $device));
        }

        // ------------------------------------------------------------------
        case 'register': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'register', 5, 150, 600);

            if (!se_table_exists($pdo, 'se_registrations')) {
                se_api_error('Registration is not available yet.', 'FEATURE_NOT_READY');
            }

            $days     = se_event_days($pdo, (int) $event['id']);
            $settings = se_event_settings($event);
            $now      = se_now();

            // --- Honeypot and timing (§12.2, §19.4) -----------------------
            // A bot gets a success it cannot tell from the real one, so it
            // never learns what tripped it. Nothing is written.
            $hp   = se_str($body['hp'] ?? '', 200);
            $tMs  = se_int($body['t_ms'] ?? 0, 0);
            if ($hp !== '' || $tMs < SE_REGISTER_MIN_FILL_MS) {
                se_audit($pdo, (int) $event['id'], 'register_bot_suspected',
                    ['hp' => $hp !== '', 't_ms' => $tMs], 'event', (int) $event['id']);
                se_api_success("You're in!", [
                    'outcome'      => 'confirmed',
                    'for'          => 'self',
                    'reg_code'     => se_random_code(SE_REG_CODE_LENGTH),
                    'display_name' => se_display_name(se_clean_name($body['first_name'] ?? 'Guest')),
                ]);
            }

            $phone = se_public_phone($body['phone'] ?? '');

            // --- Consent (§20.1) ------------------------------------------
            $consentMode = (string) ($settings['registration']['consent_mode'] ?? 'required_followup');
            $consent     = se_bool($body['consent'] ?? false);
            if ($consentMode === 'required_followup' && !$consent) {
                se_api_error('Please tick the box to continue.', 'CONSENT_REQUIRED');
            }

            // --- Names and the member check -------------------------------
            $contact = se_contact_find_by_phone($pdo, $phone['e164']);
            $member  = se_find_member($pdo, $phone['e164']);

            $firstName = se_name_case(se_clean_name($body['first_name'] ?? ''));
            $lastName  = se_name_case(se_clean_name($body['last_name'] ?? ''));

            if ($firstName === '' && $member) { $firstName = $member['first_name']; }
            if ($lastName === ''  && $member) { $lastName  = $member['last_name']; }
            if ($firstName === '' && $contact) { $firstName = (string) $contact['first_name']; }
            if ($lastName === ''  && $contact) { $lastName  = (string) $contact['last_name']; }

            $errors = [];
            if ($firstName === '') {
                $errors['first_name'] = 'Please tell us your first name.';
            }

            $fields = (array) ($settings['registration']['fields'] ?? []);

            $gender = se_normalize_gender($body['gender'] ?? null)
                ?? se_normalize_gender($contact['gender'] ?? null)
                ?? ($member['gender'] ?? null);
            if (($fields['gender'] ?? 'required') === 'required' && $gender === null) {
                $errors['gender'] = 'Please choose one.';
            }

            $email = se_contact_clean_email($body['email'] ?? null)
                ?? ($contact['email'] ?? null)
                ?? ($member['email'] ?? null);
            if (($fields['email'] ?? 'optional') === 'required' && $email === null) {
                $errors['email'] = 'Please enter a valid email address.';
            }
            if (($body['email'] ?? '') !== '' && se_contact_clean_email($body['email'] ?? null) === null) {
                $errors['email'] = 'That email address does not look right.';
            }

            $howHeard      = se_str($body['how_heard'] ?? '', 30);
            $howHeardOther = se_line($body['how_heard_other'] ?? '', 80);
            if ($howHeard !== '' && !isset(SE_HOW_HEARD[$howHeard])) {
                $errors['how_heard'] = 'Please choose one of the options.';
            }
            if (($fields['how_heard'] ?? 'optional') === 'required' && $howHeard === '') {
                $errors['how_heard'] = 'Please choose one of the options.';
            }
            if ($howHeard !== 'other') {
                $howHeardOther = '';
            }

            if ($errors) {
                se_api_error('Please check the highlighted fields.', 'VALIDATION', ['fields' => $errors]);
            }

            // --- Custom questions ------------------------------------------
            $isMember   = $member !== null;
            $formFields = se_form_fields($pdo, (int) $event['id'], $isMember);
            $answers    = se_answers_validate($formFields, $body['answers'] ?? []);

            // --- Member name correction (§10.3.2) ---------------------------
            $nameCorrection = null;
            if ($member) {
                $onFile = trim($member['first_name'] . ' ' . $member['last_name']);
                $typed  = trim($firstName . ' ' . $lastName);
                if ($onFile !== '' && strcasecmp($onFile, $typed) !== 0 && se_clean_name($body['first_name'] ?? '') !== '') {
                    $nameCorrection = mb_substr($typed, 0, 160, 'UTF-8');
                }
            }

            // --- Attribution (§18.2) ---------------------------------------
            $src      = se_str($body['src'] ?? '', 20);
            $src      = preg_match('/^[a-z0-9_-]{1,20}$/i', $src) ? strtolower($src) : null;
            $referrer = se_registration_by_ref($pdo, (int) $event['id'], se_str($body['ref'] ?? '', 8));

            $ctx = [
                'now'     => $now,
                'ip_hash' => se_ip_hash(),
                'device'  => null,
            ];

            // The device row is created before the lock, so binding inside it
            // is a single UPDATE rather than an INSERT under contention.
            $cookie        = se_device_cookie_value((string) $event['public_id']);
            $ctx['device'] = se_device_ensure($pdo, $event, $days, $cookie);

            $in = [
                'phone'            => $phone,
                'first_name'       => $firstName,
                'last_name'        => $lastName,
                'gender'           => $gender,
                'email'            => $email,
                'consent'          => $consent,
                'consent_text'     => (string) ($settings['registration']['consent_text'] ?? ''),
                'how_heard'        => $howHeard !== '' ? $howHeard : null,
                'how_heard_other'  => $howHeardOther !== '' ? $howHeardOther : null,
                'karaoke_interest' => se_bool($body['karaoke_interest'] ?? false)
                    && se_bool($settings['registration']['fields']['karaoke_interest'] ?? true),
                'answers'          => $answers,
                'src'              => $src,
                'referred_by_registration_id' => $referrer ? (int) $referrer['id'] : null,
                'name_correction'  => $nameCorrection,
                'channel'          => 'portal',
                'is_test'          => se_bool($settings['test_mode'] ?? false),
            ];

            $result = se_register($pdo, $event, $settings, $days, $in, $ctx);

            if ($result['outcome'] === 'blocked') {
                se_api_error('Please see the desk about your registration.', 'BLOCKED');
            }
            if ($result['outcome'] === 'closed') {
                $state = (string) $result['state'];
                $code  = match ($state) {
                    'not_open_yet' => 'REG_NOT_OPEN',
                    'full'         => 'CAPACITY_FULL',
                    default        => 'REG_CLOSED',
                };
                se_api_error(se_registration_state_message($state, $event), $code, ['state' => $state]);
            }

            $reg      = $result['registration'];
            $ownsIt   = !empty($result['device_owns']);
            $outcome  = $result['outcome'] === 'already' ? 'already' : $result['outcome'];

            $data = [
                'outcome'      => $outcome,
                'for'          => $ownsIt ? 'self' : 'other',
                'reg_code'     => (string) $reg['reg_code'],
                'display_name' => (string) $reg['display_name'],
                'first_name'   => (string) $reg['first_name'],
                'status'       => (string) $reg['status'],
                'ref_url'      => se_event_url((string) $event['slug']) . '?r=' . rawurlencode((string) $reg['ref_code']),
                'waitlist_position' => se_waitlist_position($pdo, $reg),
                'ask_wants_visit'   => se_bool($settings['registration']['ask_wants_visit_after_submit'] ?? true)
                    && !se_bool($reg['is_member']),
                'can_make_card'     => se_bool($settings['share_cards']['im_going'] ?? true),
            ];

            // The manage link is only ever handed to the device that holds
            // the registration (§10.3.5) — otherwise it would let whoever
            // typed the number take over someone else's seat.
            if ($ownsIt) {
                $data['manage_url'] = se_manage_url($pdo, $event, $days, (int) $reg['id']);
            }

            $message = match (true) {
                $outcome === 'waitlisted' => "You're #" . ($data['waitlist_position'] ?? 1) . ' on the waitlist',
                $outcome === 'already'    => "You're already in, " . $reg['display_name'] . ' 🎉',
                default                   => "You're in, " . $reg['first_name'] . '! 🎉',
            };

            se_api_success($message, $data);
        }

        // ------------------------------------------------------------------
        case 'wants_visit': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'wants_visit', 30, 900, 600);

            [$reg] = se_public_actor($pdo, $event, $body);
            $value = se_bool($body['value'] ?? false);
            se_wants_visit_set($pdo, $reg, $value);

            se_api_success($value ? 'We will reach out. Thank you!' : 'No problem.', ['wants_visit' => $value]);
        }

        // ------------------------------------------------------------------
        case 'request_link': {
            // Always the same answer, whether or not the number is on the
            // list (§12.2). Otherwise this endpoint becomes a way to ask
            // "is 0803… coming to this event?".
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'request_link', 3, 30, 3600);

            $settings = se_event_settings($event);
            if (!se_bool($settings['registration']['link_on_demand_enabled'] ?? true)) {
                se_api_error('Please see the desk for your link.', 'FEATURE_DISABLED');
            }

            $phone = se_public_phone($body['phone'] ?? '');
            $same  = 'link:' . (int) $event['id'] . ':' . $phone['e164'];
            se_rate_limit_or_fail($pdo, 'request_link_phone', $same, 1, 1800);
            se_rate_limit_or_fail($pdo, 'request_link_event', $same, 3, 86400 * 30);

            $days    = se_event_days($pdo, (int) $event['id']);
            $contact = se_contact_find_by_phone($pdo, $phone['e164']);

            if ($contact) {
                $reg = se_registration_find($pdo, (int) $event['id'], (int) $contact['id']);
                if ($reg && in_array((string) $reg['status'], ['confirmed', 'waitlisted'], true)) {
                    se_messages_enqueue_link($pdo, $event, $days, $reg);
                }
            }

            se_api_success('If that number is registered, we have texted the link.', ['sent' => true]);
        }

        // ------------------------------------------------------------------
        case 'claim_link': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'claim_link', 5, 100, 600);

            $token = se_str($body['token'] ?? '', 64);
            $row   = $token !== '' ? se_token_lookup($pdo, $token, 'manage', (int) $event['id']) : null;
            if (!$row) {
                se_api_error('That link has expired. Ask for a new one below.', 'TOKEN_INVALID');
            }

            $reg = se_registration_by_id($pdo, (int) $row['registration_id'], (int) $event['id']);
            if (!$reg) {
                se_api_error('That link has expired. Ask for a new one below.', 'TOKEN_INVALID');
            }

            $days   = se_event_days($pdo, (int) $event['id']);
            $cookie = se_device_cookie_value((string) $event['public_id']);
            $device = se_device_ensure($pdo, $event, $days, $cookie);

            // A manage link is proof of ownership, so unlike a registration it
            // may re-point a device that already holds someone else (§10.3.6).
            se_device_bind_force($pdo, $device, (int) $reg['id']);
            $device = se_device_load($pdo, $event, $cookie);

            se_api_success('Welcome back, ' . $reg['first_name'] . '!', [
                'me' => se_me_payload($pdo, $event, $days, $reg, $device, $token),
            ]);
        }

        // ------------------------------------------------------------------
        case 'me': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'me', 120, 4000, 600);

            [$reg, $device, $token] = se_public_actor($pdo, $event, $body);
            $days = se_event_days($pdo, (int) $event['id']);

            se_api_success('OK', se_me_payload($pdo, $event, $days, $reg, $device, $token));
        }

        // ------------------------------------------------------------------
        case 'cancel': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'cancel', 10, 200, 600);

            [$reg] = se_public_actor($pdo, $event, $body);
            $days  = se_event_days($pdo, (int) $event['id']);

            $result = se_registration_cancel($pdo, $event, $days, $reg, 'self', se_line($body['reason'] ?? '', 160));

            se_api_success(
                $result['status'] === 'cancelled'
                    ? 'Your seat has been released. We hope to see you another time.'
                    : 'Nothing to cancel.',
                ['status' => $result['status'] === 'cancelled' ? 'cancelled' : 'noop']
            );
        }

        // ------------------------------------------------------------------
        case 'optout': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'optout', 30, 900, 600);

            [$reg] = se_public_actor($pdo, $event, $body);
            se_contact_optout($pdo, $event, $reg);

            se_api_success('Done — we will not contact you again.', ['opted_out' => true]);
        }

        case 'feedback': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'feedback', 20, 1000, 600);
            [$reg] = se_public_actor($pdo, $event, $body);
            se_api_success('Thank you — your feedback is saved.', se_feedback_save($pdo, $event, $reg, $body));
        }

        case 'survey': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'survey', 30, 1500, 600);
            [$reg] = se_public_actor($pdo, $event, $body);
            se_api_success('Answer saved.', se_survey_save(
                $pdo,
                $event,
                $reg,
                se_int($body['item_id'] ?? 0, 0),
                se_line($body['text'] ?? '', 80)
            ));
        }

        // Games player actions (§12.2)
        case 'join_games': case 'answer': case 'suggest': case 'buzz': {
            $event=se_public_event($pdo,$body); se_public_require_writable($event); se_public_limit($pdo,$event,$action,120,6000,60); [$reg,$device]=se_public_actor($pdo,$event,$body);
            if($action!=='join_games') se_game_require_player($pdo,$event,$reg,$device);
            if($action==='join_games') $out=se_game_join($pdo,$event,$reg,$device);
            elseif($action==='answer') $out=se_game_answer($pdo,$event,$reg,$body);
            elseif($action==='suggest') $out=se_game_suggest($pdo,$event,$reg,$body);
            else $out=se_game_buzz($pdo,$event,$reg,$body);
            se_api_success('OK',$out);
        }

        // ------------------------------------------------------------------
        // Karaoke (§10.8, §12.2)
        // ------------------------------------------------------------------
        case 'songs': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'songs', 120, 3000, 600);

            if (!se_karaoke_ready($pdo)) {
                se_api_error('Karaoke is not available yet.', 'FEATURE_NOT_READY');
            }

            // The list is only readable by somebody who holds a seat: the
            // device's own registration, or a manage token.
            se_public_actor($pdo, $event, $body);

            $settings = se_event_settings($event);
            if (!se_bool($settings['karaoke']['enabled'] ?? false)) {
                se_api_error('There is no karaoke at this event.', 'FEATURE_DISABLED');
            }
            if (!se_bool($settings['karaoke']['list_published'] ?? false)) {
                se_api_error('The song list is coming soon 🎤', 'LIST_NOT_PUBLISHED');
            }

            se_api_success('OK', se_songs_event_list($pdo, $event, [
                'q'           => $body['q'] ?? '',
                'page'        => $body['page'] ?? 1,
                'per_page'    => 40,
                'active_only' => true,
            ]));
        }

        case 'pick_song': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'pick_song', 20, 600, 600);

            if (!se_karaoke_ready($pdo)) {
                se_api_error('Karaoke is not available yet.', 'FEATURE_NOT_READY');
            }

            [$reg] = se_public_actor($pdo, $event, $body);

            $karaoke = se_karaoke_pick(
                $pdo,
                $event,
                $reg,
                se_int($body['song_id'] ?? 0, 0),
                se_bool(se_event_phase($event, se_event_days($pdo, (int) $event['id']))['checkin_open'] ?? false)
                    ? 'portal' : 'prepick'
            );

            se_api_success('That song is yours 🎤', ['karaoke' => $karaoke]);
        }

        case 'release_song': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'release_song', 20, 600, 600);

            [$reg] = se_public_actor($pdo, $event, $body);
            se_karaoke_release($pdo, $event, $reg);

            se_api_success('Released. Somebody else can sing it now.', ['karaoke' => null]);
        }

        // ------------------------------------------------------------------
        case 'checkin': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'checkin', 10, 600, 600);

            if (!se_checkin_ready($pdo)) {
                se_api_error('Check-in is not available yet.', 'FEATURE_NOT_READY');
            }

            $days   = se_event_days($pdo, (int) $event['id']);
            $phone  = se_public_phone($body['phone'] ?? '');
            se_rate_limit_or_fail($pdo, 'checkin_phone', (int) $event['id'] . ':' . $phone['e164'], 10, 600);

            // The device row is created here rather than on the lookup, so a
            // browsing guest never gets a cookie they did not need (§10.3.5).
            $cookie = null;
            $device = se_device_ensure($pdo, $event, $days, $cookie);

            $settings    = se_event_settings($event);
            $consentMode = (string) ($settings['registration']['consent_mode'] ?? 'required_followup');

            $result = se_checkin($pdo, $event, $days, [
                'phone'        => $phone,
                'first_name'   => $body['first_name'] ?? null,
                'last_name'    => $body['last_name'] ?? null,
                'gender'       => $body['gender'] ?? null,
                'email'        => $body['email'] ?? null,
                'consent'      => se_bool($body['consent'] ?? false),
                'consent_text' => $consentMode === 'off'
                    ? ''
                    : (string) ($settings['registration']['consent_text'] ?? ''),
                'karaoke_interest' => se_bool($body['karaoke_interest'] ?? false),
            ], [
                'method'   => 'self',
                'device'   => $device,
                'days'     => $days,
                'ip_hash'  => se_ip_hash(),
                // A signed-in crew member rehearsing on their own phone may
                // check in before the doors open while test mode is on.
                'is_crew'  => isset($_SESSION['user_id'])
                    && se_has_capability($pdo, (int) $event['id'], 'desk', (int) $_SESSION['user_id']),
            ]);

            $payload = $result['payload'];
            $payload['readonly'] = ($device['mode'] ?? 'full') === 'readonly'
                || $result['outcome'] === 'already_elsewhere';

            se_api_success(
                $result['outcome'] === 'checked_in'
                    ? 'You are in!'
                    : 'You are already checked in.',
                $payload
            );
        }

        // ------------------------------------------------------------------
        case 'transfer': {
            $event = se_public_event($pdo, $body);
            se_public_require_writable($event);
            se_public_limit($pdo, $event, 'transfer', 5, 100, 600);

            $days   = se_event_days($pdo, (int) $event['id']);
            $cookie = null;
            $device = se_device_ensure($pdo, $event, $days, $cookie);

            $out = se_transfer_code_redeem($pdo, $event, se_str($body['code'] ?? '', 10), $device);
            $reg = se_registration_by_id($pdo, $out['registration_id'], (int) $event['id']);

            if (!$reg) {
                se_api_error('That code is no longer valid.', 'CODE_INVALID');
            }

            se_api_success(
                'This phone is now yours for tonight.',
                se_me_payload($pdo, $event, $days, $reg, se_device_load($pdo, $event, $cookie))
            );
        }

        // ------------------------------------------------------------------
        case 'card': {
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'card', 60, 2000, 600);

            [$reg] = se_public_actor($pdo, $event, $body);
            $days     = se_event_days($pdo, (int) $event['id']);
            $settings = se_event_settings($event);
            $kind     = se_enum($body['kind'] ?? 'im_going', ['im_going', 'welcome', 'team', 'my_night'], 'im_going');

            se_api_success('OK', se_card_payload($pdo, $event, $days, $settings, $kind, $reg));
        }

        // ------------------------------------------------------------------
        case 'beacon': {
            // Counters only: no identity, no path, no free text (§18.1).
            $event = se_public_event($pdo, $body);
            se_public_limit($pdo, $event, 'beacon', 60, 3000, 600);

            $metric = se_enum($body['m'] ?? '', ['view', 'reg_start', 'reg_done', 'share', 'card'], '');
            if ($metric === '') {
                se_api_success('OK', []);
            }

            se_metric_bump($pdo, (int) $event['id'], $metric, se_str($body['d'] ?? '', 20));
            se_api_success('OK', []);
        }

        // ------------------------------------------------------------------
        default:
            se_api_error('Unknown action.', 'BAD_REQUEST');
    }
} catch (Throwable $e) {
    se_api_fail($e, 'public/' . $action);
}
