<?php
// /includes/special_events/settings.php
//
// Module-wide settings (se_settings, §20.2) and the per-event settings
// normaliser (§20.3). Appendix E of the engineering guide is the contract
// between the Studio and the server: SE_SETTINGS_SPEC below IS that document,
// expressed as a type spec so one walker can merge, coerce and validate it.
//
// Unknown keys are dropped, types coerced, enums checked (§20.3), so a stored
// document from an older version of the Studio always normalises forward.

// --------------------------------------------------------------------------
// Module settings (se_settings)
// --------------------------------------------------------------------------

/** Defaults for keys the seed migration does not insert (§20.2). */
function se_module_setting_defaults(): array
{
    return [
        'envision_department_id'     => (string) SE_ENVISION_DEPT_ID,
        'default_brand_primary'      => '#1D356A',
        'default_brand_secondary'    => '#D11920',
        'default_theme_preset'       => 'marquee',
        'default_privacy_notice_md'  => se_default_privacy_notice_md(),
        'privacy_contact_email'      => '',
        'retention_months_guest'     => '24',
        'ai_user_hourly_limit'       => '30',
        'ai_event_daily_limit'       => '300',
        'cron_last_run'              => '',
        'source_codes_json'          => se_json_encode([
            'wa' => 'WhatsApp', 'ig' => 'Instagram', 'fb' => 'Facebook',
            'tt' => 'TikTok', 'x' => 'X', 'flyer' => 'Flyer', 'poster' => 'Poster',
            'sms' => 'SMS', 'pulpit' => 'Church announcement', 'qr' => 'QR code',
            'email' => 'Email',
        ]),
    ];
}

/** Keys a manager may edit from Studio → Settings, with their input type. */
const SE_MODULE_SETTING_EDITABLE = [
    'envision_department_id'    => 'int',
    'default_brand_primary'     => 'hex',
    'default_brand_secondary'   => 'hex',
    'default_theme_preset'      => 'preset',
    'default_privacy_notice_md' => 'markdown',
    'privacy_contact_email'     => 'email',
    'retention_months_guest'    => 'int',
    'ai_user_hourly_limit'      => 'int',
    'ai_event_daily_limit'      => 'int',
];

/**
 * All module settings, defaults merged under the stored rows.
 * Cached per request; se_setting_save() clears the cache.
 */
function se_settings_all(PDO $pdo, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) {
        return $cache;
    }

    $values = se_module_setting_defaults();
    try {
        if (se_table_exists($pdo, 'se_settings')) {
            $rows = $pdo->query("SELECT setting_key, setting_value FROM se_settings")->fetchAll();
            foreach ($rows as $row) {
                $key = (string) $row['setting_key'];
                // A stored empty string still means "not set" for these two.
                if ($row['setting_value'] === null) {
                    continue;
                }
                $values[$key] = (string) $row['setting_value'];
            }
        }
    } catch (Throwable $e) {
        error_log('SE settings/all: ' . $e->getMessage());
    }

    return $cache = $values;
}

function se_setting_get(PDO $pdo, string $key, ?string $default = null): ?string
{
    $all = se_settings_all($pdo);

    return array_key_exists($key, $all) ? $all[$key] : $default;
}

/** Write one module setting. Manager-gated by the caller. */
function se_setting_save(PDO $pdo, string $key, ?string $value, ?int $actorId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO se_settings (setting_key, setting_value, updated_by)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)"
    );
    $stmt->execute([$key, $value, $actorId]);
    se_settings_all($pdo, true);
}

/** Share-kit source codes (§18.2) as code => label. */
function se_source_codes(PDO $pdo): array
{
    $decoded = se_json_decode(se_setting_get($pdo, 'source_codes_json'));
    $out     = [];
    foreach ($decoded as $code => $label) {
        $code = strtolower(se_str($code, 20));
        if ($code !== '' && preg_match('/^[a-z0-9_]+$/', $code)) {
            $out[$code] = se_line($label, 60);
        }
    }

    return $out;
}

/** The privacy notice skeleton shipped as the default (Appendix F). */
function se_default_privacy_notice_md(): string
{
    return <<<'MD'
## Who we are

{event} is run by Envision, the creative department of Household of David
Lekki Centre ("HOD Lekki", "we"). We are the controller of the information on
this page.

## What we collect

Your phone number, your name, your gender, your email address if you give us
one, your answers to the questions on the registration form, and — on the day
— your check-in, your team and how you take part in the games.

## Why we collect it

To run {event}: to save you a seat, to welcome you at the door, to send you
reminders, and to run the games. With your consent, we also keep your details
so we can say thank you and invite you to future events.

## Who sees it

The Envision team running this event. With your consent, our follow-up teams
(Reach and Embrace) so someone can say hello properly. Our SMS provider
delivers our text messages. We do not sell your data, and we never send your
personal details to an AI service.

## How long we keep it

While the event is running and afterwards for follow-up. If you have not given
consent to be contacted, guest details are anonymised two years after the
event is archived at the latest.

## Your choices

You can stop us contacting you at any time from your manage link, or ask us to
correct or delete your details by writing to {privacy_contact_email}.

## Your photo

If you make a share card with your photo, the photo is composited on your own
phone. It never leaves your device and we never receive it.
MD;
}

// --------------------------------------------------------------------------
// Per-event settings (Appendix E)
// --------------------------------------------------------------------------

/**
 * The Appendix E document as a type spec. Node types:
 *   obj          children[]
 *   bool, int, int_or_null, float, str, text, enum, hex
 *   int_list     item bounds + length bounds
 *   obj_list     children[] per item, max_items
 *   map_enum     keys fixed, values from `values`
 */
function se_settings_spec(): array
{
    static $spec = null;
    if ($spec !== null) {
        return $spec;
    }

    $fieldMode = ['type' => 'enum', 'values' => ['off', 'optional', 'required']];
    $gameInt   = static fn(int $d, int $max = 600000) => ['type' => 'int', 'default' => $d, 'min' => 0, 'max' => $max];

    return $spec = [
        'registration' => ['type' => 'obj', 'children' => [
            'fields' => ['type' => 'obj', 'children' => [
                'gender'           => $fieldMode + ['default' => 'required'],
                'email'            => $fieldMode + ['default' => 'optional'],
                'how_heard'        => $fieldMode + ['default' => 'optional'],
                'karaoke_interest' => ['type' => 'bool', 'default' => true],
            ]],
            'consent_mode' => ['type' => 'enum', 'default' => 'required_followup',
                               'values' => ['required_followup', 'notice_plus_optional_optin']],
            'consent_text' => ['type' => 'text', 'max' => 600, 'default' =>
                'I agree that HOD Lekki Centre may keep my details and contact me after this event (for example a thank-you and invitations to other events). I can opt out anytime.'],
            'optin_text' => ['type' => 'text', 'max' => 600, 'default' =>
                'Keep me posted about future HOD Lekki events.'],
            'ask_wants_visit_after_submit' => ['type' => 'bool', 'default' => true],
            'play_ahead_enabled'           => ['type' => 'bool', 'default' => true],
            'link_on_demand_enabled'       => ['type' => 'bool', 'default' => true],
            'companions_max'               => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 10],
            // Set by the Registration tab when a capacity number — or "no
            // limit" — has been chosen on purpose. The H.1 publish checklist
            // needs it because online_capacity = NULL is both "unlimited" and
            // "never visited this tab" (§28.4).
            'capacity_reviewed'            => ['type' => 'bool', 'default' => false],
            'min_age_note' => ['type' => 'text', 'max' => 300, 'default' =>
                'For ages 16 and above. Younger guests are welcome with a parent or guardian.'],
        ]],

        'checkin' => ['type' => 'obj', 'children' => [
            'opens_minutes_before' => ['type' => 'int', 'default' => 60, 'min' => 0, 'max' => 1440],
            'self_checkin_enabled' => ['type' => 'bool', 'default' => true],
            'desk_enabled'         => ['type' => 'bool', 'default' => true],
            'ask_missing_gender'   => ['type' => 'bool', 'default' => true],
            'welcome_card' => ['type' => 'obj', 'children' => [
                'enabled'   => ['type' => 'bool', 'default' => true],
                'signature' => ['type' => 'str', 'max' => 120, 'default' => '{title} {edition} by {organizer}'],
            ]],
        ]],

        'lobby' => ['type' => 'obj', 'children' => [
            'show_names' => ['type' => 'bool', 'default' => true],
        ]],

        'teams' => ['type' => 'obj', 'children' => [
            'enabled'     => ['type' => 'bool', 'default' => true],
            'count'       => ['type' => 'int', 'default' => 4, 'min' => SE_MIN_TEAMS, 'max' => SE_MAX_TEAMS],
            'naming_mode' => ['type' => 'enum', 'default' => 'crew_entered',
                              'values' => ['crew_entered', 'preset', 'self_named']],
            'balance'     => ['type' => 'enum_list', 'default' => ['size', 'gender', 'membership'],
                              'values' => ['size', 'gender', 'membership'], 'max_items' => 3],
        ]],

        'karaoke' => ['type' => 'obj', 'children' => [
            'enabled'                 => ['type' => 'bool', 'default' => true],
            'prepick_enabled'         => ['type' => 'bool', 'default' => true],
            'unique_songs'            => ['type' => 'bool', 'default' => true],
            'list_published'          => ['type' => 'bool', 'default' => false],
            'max_singers'             => ['type' => 'int_or_null', 'default' => null, 'min' => 1, 'max' => 1000],
            'songs_per_person'        => ['type' => 'int', 'default' => 1, 'min' => 1, 'max' => 5],
            'release_holds_after_min' => ['type' => 'int', 'default' => 30, 'min' => 0, 'max' => 1440],
            'avg_song_min'            => ['type' => 'int', 'default' => 4, 'min' => 1, 'max' => 30],
        ]],

        'games' => ['type' => 'obj', 'children' => [
            'enabled'          => ['type' => 'bool', 'default' => true],
            'public_mvp_count' => ['type' => 'int', 'default' => 5, 'min' => 0, 'max' => 20],
            'defaults' => ['type' => 'obj', 'children' => [
                'live_quiz' => ['type' => 'obj', 'children' => [
                    'preroll_ms'       => $gameInt(3000, 30000),
                    'duration_ms'      => $gameInt(20000, 600000),
                    'grace_ms'         => $gameInt(1500, 10000),
                    'points_mode'      => ['type' => 'enum', 'default' => 'standard', 'values' => ['standard', 'flat', 'double']],
                    'team_base'        => $gameInt(1000, 10000),
                    'streak_bonus'     => ['type' => 'bool', 'default' => false],
                    'auto_lock'        => ['type' => 'bool', 'default' => true],
                    'auto_score'       => ['type' => 'bool', 'default' => true],
                    'show_podium_every' => ['type' => 'int', 'default' => 1, 'min' => 0, 'max' => 50],
                ]],
                'trivia' => ['type' => 'obj', 'children' => [
                    'preroll_ms'                   => $gameInt(3000, 30000),
                    'duration_ms'                  => $gameInt(30000, 600000),
                    'grace_ms'                     => $gameInt(1500, 10000),
                    'points_correct'               => $gameInt(300, 10000),
                    'speed_bonus_max'              => $gameInt(0, 10000),
                    'use_suggestions_if_no_captain' => ['type' => 'bool', 'default' => true],
                    'auto_lock'                    => ['type' => 'bool', 'default' => true],
                    'auto_score'                   => ['type' => 'bool', 'default' => true],
                ]],
                'buzzer' => ['type' => 'obj', 'children' => [
                    'preroll_ms'           => $gameInt(2500, 30000),
                    'window_ms'            => $gameInt(15000, 600000),
                    'reopen_window_ms'     => $gameInt(10000, 600000),
                    'points_correct'       => $gameInt(300, 10000),
                    'wrong_penalty'        => $gameInt(0, 10000),
                    'reopen_on_wrong'      => ['type' => 'bool', 'default' => true],
                    'who_can_buzz'         => ['type' => 'enum', 'default' => 'anyone', 'values' => ['anyone', 'captain']],
                    'buzzer_player_points' => $gameInt(0, 10000),
                    'fairness_ms'          => $gameInt(300, 5000),
                ]],
                'who_am_i' => ['type' => 'obj', 'children' => [
                    'points_by_clue'          => ['type' => 'int_list', 'default' => [500, 400, 300, 200, 100],
                                                  'min' => 0, 'max' => 10000, 'max_items' => 10],
                    'lockout_until_next_clue' => ['type' => 'bool', 'default' => true],
                    'fairness_ms'             => $gameInt(300, 5000),
                ]],
                'charades' => ['type' => 'obj', 'children' => [
                    'turn_ms'          => $gameInt(60000, 600000),
                    'points_per_word'  => $gameInt(200, 10000),
                    'max_passes'       => ['type' => 'int', 'default' => 2, 'min' => 0, 'max' => 10],
                    'presenter_points' => $gameInt(0, 10000),
                    'turns_per_team'   => ['type' => 'int', 'default' => 2, 'min' => 1, 'max' => 10],
                ]],
                'feud' => ['type' => 'obj', 'children' => [
                    'min_responses'     => ['type' => 'int', 'default' => 25, 'min' => 1, 'max' => 1000],
                    'board_size_min'    => ['type' => 'int', 'default' => 5, 'min' => 1, 'max' => 12],
                    'board_size_max'    => ['type' => 'int', 'default' => 8, 'min' => 1, 'max' => 12],
                    'round_multipliers' => ['type' => 'int_list', 'default' => [1, 1, 2, 3],
                                            'min' => 1, 'max' => 10, 'max_items' => 10],
                    'matchups'          => ['type' => 'enum', 'default' => 'round_robin',
                                            'values' => ['round_robin', 'knockout', 'manual']],
                    'steal_enabled'     => ['type' => 'bool', 'default' => true],
                ]],
            ]],
        ]],

        'feud' => ['type' => 'obj', 'children' => [
            'survey_closes_at' => ['type' => 'datetime_or_null', 'default' => null],
        ]],

        'program' => ['type' => 'obj', 'children' => [
            // The same three modes se_program_public_time() renders. 'hidden'
            // used to be listed here instead of 'order_only', so choosing
            // "Order only" in the Studio was silently reset to 'approximate'.
            'public_time_mode' => ['type' => 'enum', 'default' => 'approximate',
                                   'values' => SE_PROGRAM_TIME_MODES],
        ]],

        'portal' => ['type' => 'obj', 'children' => [
            'intro_line'            => ['type' => 'text_or_null', 'max' => 300, 'default' => null],
            'show_countdown'        => ['type' => 'bool', 'default' => true],
            'hero_video_enabled'    => ['type' => 'bool', 'default' => true],
            'chapters_from_featured' => ['type' => 'bool', 'default' => true],
            // Portal music (§13.3 S0b). The volume is a percentage and the
            // default is low on purpose: see SE_MUSIC_DEFAULT_VOLUME.
            'music_enabled'         => ['type' => 'bool', 'default' => true],
            'music_volume'          => ['type' => 'int',  'default' => SE_MUSIC_DEFAULT_VOLUME,
                                        'min' => 5, 'max' => 100],
            'music_shuffle'         => ['type' => 'bool', 'default' => false],
            'faq' => ['type' => 'obj_list', 'max_items' => 20, 'children' => [
                'q' => ['type' => 'str',  'max' => 160, 'default' => ''],
                'a' => ['type' => 'text', 'max' => 1000, 'default' => ''],
            ], 'default' => [
                ['q' => 'Is it free?',                   'a' => "Yes! Just register so we can save you a seat."],
                ['q' => 'What should I wear?',           'a' => "Come comfortable and colourful — it's a night of joy."],
                ['q' => 'Do I have to sing?',            'a' => "Not at all. Karaoke is optional — cheering counts too."],
                ['q' => 'Can I bring a friend?',         'a' => "Please do! Share your invite link so they can register too."],
                ['q' => 'What time should I arrive?',    'a' => "Doors open an hour early. Check-in is quick: scan the QR at the entrance."],
                ['q' => 'Will there be food?',           'a' => "We'll share details closer to the day."],
            ]],
        ]],

        'recap' => ['type' => 'obj', 'children' => [
            'public' => ['type' => 'bool', 'default' => true],
        ]],

        'share_cards' => ['type' => 'obj', 'children' => [
            'im_going' => ['type' => 'bool', 'default' => true],
            'welcome'  => ['type' => 'bool', 'default' => true],
            'team'     => ['type' => 'bool', 'default' => true],
            'my_night' => ['type' => 'bool', 'default' => true],
        ]],

        'messages' => ['type' => 'obj', 'children' => [
            'max_lateness_min' => ['type' => 'int', 'default' => 90, 'min' => 0, 'max' => 1440],
            'reminder_1' => ['type' => 'obj', 'children' => [
                'enabled'  => ['type' => 'bool', 'default' => true],
                'at'       => ['type' => 'time', 'default' => '18:00'],
                'template' => ['type' => 'text', 'max' => 480, 'default' =>
                    '{{first_name}}, {{event_title}} is tomorrow, {{time}} at {{venue}}! Check in at the entrance. Your link: {{link}}'],
            ]],
            'reminder_2' => ['type' => 'obj', 'children' => [
                'enabled'        => ['type' => 'bool', 'default' => true],
                'minutes_before' => ['type' => 'int', 'default' => 120, 'min' => 0, 'max' => 1440],
                'template'       => ['type' => 'text', 'max' => 480, 'default' =>
                    '{{first_name}}, {{event_title}} starts at {{time}}! Scan the QR at the entrance to check in & meet your team. {{link}}'],
            ]],
            'thank_you' => ['type' => 'obj', 'children' => [
                'enabled'  => ['type' => 'bool', 'default' => true],
                'at'       => ['type' => 'time', 'default' => '09:00'],
                'template' => ['type' => 'text', 'max' => 480, 'default' =>
                    'Thank you for coming to {{event_title}}, {{first_name}}! Get your My Night card & tell us how it went (1 min): {{link}}'],
            ]],
            'waitlist_promotion' => ['type' => 'obj', 'children' => [
                'template' => ['type' => 'text', 'max' => 480, 'default' =>
                    "Good news {{first_name}}! A seat opened at {{event_title}} on {{date}}. You're in! Details: {{link}}"],
            ]],
            'link_on_demand' => ['type' => 'obj', 'children' => [
                'template' => ['type' => 'text', 'max' => 480, 'default' =>
                    '{{first_name}}, here is your {{event_title}} link: {{link}}'],
            ]],
        ]],

        'stage' => ['type' => 'obj', 'children' => [
            'volume'           => ['type' => 'float', 'default' => 0.8, 'min' => 0.0, 'max' => 1.0],
            'show_score_strip' => ['type' => 'bool', 'default' => true],
        ]],

        'realtime' => ['type' => 'obj', 'children' => [
            'driver'      => ['type' => 'enum', 'default' => 'poll', 'values' => ['poll', 'ably']],
            'min_poll_ms' => ['type' => 'int', 'default' => 1000, 'min' => 250, 'max' => 60000],
        ]],

        'display' => ['type' => 'obj', 'children' => [
            'name_format' => ['type' => 'enum', 'default' => 'first_initial',
                              'values' => ['first_initial', 'first_only', 'hidden']],
        ]],

        'test_mode' => ['type' => 'bool', 'default' => false],
    ];
}

/** The complete default settings document (Appendix E). */
function se_settings_defaults(): array
{
    return se_settings_normalize([]);
}

/**
 * Merge stored settings over the Appendix E defaults, coercing every value.
 *
 * @param array|string|null $input Stored JSON, or an already-decoded array.
 */
function se_settings_normalize(array|string|null $input): array
{
    if (is_string($input) || $input === null) {
        $input = se_json_decode($input);
    }

    return se_settings_walk(se_settings_spec(), $input);
}

/** Recursive worker for se_settings_normalize(). */
function se_settings_walk(array $spec, mixed $input): array
{
    $out   = [];
    $input = is_array($input) ? $input : [];

    foreach ($spec as $key => $node) {
        $given  = $input[$key] ?? null;
        $has    = array_key_exists($key, $input);
        $type   = $node['type'];
        $default = $node['default'] ?? null;

        switch ($type) {
            case 'obj':
                $out[$key] = se_settings_walk($node['children'], $given);
                break;

            case 'bool':
                $out[$key] = $has ? se_bool($given) : (bool) $default;
                break;

            case 'int':
                $out[$key] = $has
                    ? se_int($given, $node['min'] ?? null, $node['max'] ?? null, (int) $default)
                    : (int) $default;
                break;

            case 'int_or_null':
                $out[$key] = $has ? se_int_or_null($given, $node['min'] ?? null, $node['max'] ?? null) : $default;
                break;

            case 'float':
                $out[$key] = $has
                    ? se_float($given, (float) $node['min'], (float) $node['max'], (float) $default)
                    : (float) $default;
                break;

            case 'str':
                $out[$key] = $has ? se_line($given, $node['max'] ?? 255) : (string) $default;
                break;

            case 'text':
                $out[$key] = $has ? se_str($given, $node['max'] ?? 2000) : (string) $default;
                break;

            case 'text_or_null':
                if (!$has || $given === null || (is_string($given) && trim($given) === '')) {
                    $out[$key] = $has && $given !== null ? null : $default;
                } else {
                    $out[$key] = se_str($given, $node['max'] ?? 2000);
                }
                break;

            case 'enum':
                $out[$key] = se_enum($given, $node['values'], (string) $default);
                break;

            case 'hex':
                $out[$key] = $has ? (se_normalize_hex((string) $given) ?? (string) $default) : (string) $default;
                break;

            case 'time':
                $out[$key] = $has ? (se_normalize_time_of_day($given) ?? (string) $default) : (string) $default;
                break;

            case 'datetime_or_null':
                $out[$key] = null;
                if ($has && is_string($given) && trim($given) !== '') {
                    $dt = se_parse_datetime($given);
                    $out[$key] = $dt ? se_sql_datetime($dt) : null;
                }
                break;

            case 'enum_list':
                $list = [];
                if ($has && is_array($given)) {
                    foreach ($given as $v) {
                        $v = is_string($v) ? trim($v) : '';
                        if (in_array($v, $node['values'], true) && !in_array($v, $list, true)) {
                            $list[] = $v;
                        }
                    }
                }
                $out[$key] = $list !== [] ? array_slice($list, 0, $node['max_items'] ?? 10) : (array) $default;
                break;

            case 'int_list':
                $list = [];
                if ($has && is_array($given)) {
                    foreach (array_slice($given, 0, $node['max_items'] ?? 10) as $v) {
                        if (is_numeric($v)) {
                            $list[] = se_int($v, $node['min'] ?? null, $node['max'] ?? null);
                        }
                    }
                }
                $out[$key] = $list !== [] ? $list : (array) $default;
                break;

            case 'obj_list':
                if (!$has || !is_array($given)) {
                    $out[$key] = (array) $default;
                    break;
                }
                $list = [];
                foreach (array_slice($given, 0, $node['max_items'] ?? 20) as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $row = se_settings_walk($node['children'], $item);
                    // Drop an entry whose every value is empty.
                    if (implode('', array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $row)) !== '') {
                        $list[] = $row;
                    }
                }
                $out[$key] = $list;
                break;

            default:
                // An unknown spec type is a programming error, not user input.
                throw new LogicException("se_settings_walk: unknown spec type '{$type}' for '{$key}'");
        }
    }

    return $out;
}

/** "HH:MM" (24h) or null. */
function se_normalize_time_of_day(mixed $value): ?string
{
    if (!is_string($value) || !preg_match('/^(\d{1,2}):(\d{2})$/', trim($value), $m)) {
        return null;
    }
    $h = (int) $m[1];
    $i = (int) $m[2];
    if ($h > 23 || $i > 59) {
        return null;
    }

    return sprintf('%02d:%02d', $h, $i);
}

/**
 * Read a dotted path out of a normalised settings array:
 *   se_settings_path($settings, 'registration.consent_mode')
 */
function se_settings_path(array $settings, string $path, mixed $default = null): mixed
{
    $node = $settings;
    foreach (explode('.', $path) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            return $default;
        }
        $node = $node[$part];
    }

    return $node;
}
