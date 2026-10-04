<?php
// /includes/special_events/constants.php
//
// Enums, limits, reserved slugs and defaults for the Special Events module
// (guide §20.4). Extensible vocabularies live here rather than in MySQL ENUMs
// so that adding a value needs no migration (§9.1).

// --------------------------------------------------------------------------
// Access
// --------------------------------------------------------------------------

/** Envision. Matched by ID exactly as includes/header.php does (§6.2). */
const SE_ENVISION_DEPT_ID = 3;

/** Roles that manage every event regardless of department (§6.2). */
const SE_MANAGER_ROLES = ['Super_Admin', 'Resident_Pastor', 'Assoc_Pastor'];

/** Envision roles in user_departments that grant manager level (§6.2). */
const SE_MANAGER_DEPT_ROLES = ['HOD', 'Director'];

/** Per-event crew roles (§6.2). Order is the order shown in the Studio. */
const SE_CREW_ROLES = [
    'producer'   => 'Producer',
    'host'       => 'Host / MC',
    'game_master' => 'Game master',
    'desk'       => 'Desk volunteer',
    'karaoke_dj' => 'Karaoke DJ',
    'media'      => 'Media',
    'followup'   => 'Follow-up liaison',
    'viewer'     => 'Viewer',
];

/**
 * Capability matrix, crew role => capabilities (§6.2).
 *
 * A `manager` holds every capability on every event, so this map only needs
 * to describe what a crew role adds. Capabilities whose features arrive in a
 * later PR are listed now so the matrix never has to be re-opened: the
 * endpoints that use them simply do not exist yet.
 */
const SE_CAPABILITIES = [
    'producer' => [
        'event.edit', 'event.publish', 'event.crew', 'event.keys_rotate',
        'event.capacity_override', 'assets.manage', 'host.control', 'game.control',
        'score.manage', 'team.rename', 'team.move', 'desk.checkin', 'karaoke.queue',
        'attendee.pii', 'attendee.search', 'attendee.export', 'handoff.run',
        'insights.view', 'audit.view',
    ],
    'host' => [
        'host.control', 'game.control', 'score.manage', 'team.rename',
        'karaoke.queue', 'insights.view',
    ],
    'game_master' => [
        'game.control', 'score.manage', 'team.rename', 'insights.view',
    ],
    'desk' => [
        'team.move', 'desk.checkin', 'attendee.search', 'insights.view',
    ],
    'karaoke_dj' => [
        'karaoke.queue', 'insights.view',
    ],
    'media' => [
        'assets.manage', 'insights.view',
    ],
    'followup' => [
        'attendee.pii', 'attendee.search', 'attendee.export', 'handoff.run',
        'insights.view',
    ],
    'viewer' => [
        'insights.view',
    ],
];

/** Capabilities a manager always holds. Kept as the union of the matrix. */
const SE_MANAGER_ONLY_CAPABILITIES = ['event.archive', 'event.delete', 'settings.manage'];

// --------------------------------------------------------------------------
// Slugs
// --------------------------------------------------------------------------

/** Slug format (§10.2 item 1): 1-40 chars, no leading/trailing hyphen. */
const SE_SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

const SE_SLUG_MAX_LENGTH = 40;

/** Reserved slugs (§10.2 item 2). These collide with routes or sub-paths. */
const SE_RESERVED_SLUGS = [
    'admin', 'api', 'assets', 'auth', 'calendar', 'desk', 'dj', 'e', 'edit',
    'hub', 'host', 'in', 'index', 'live', 'lobby', 'login', 'me', 'modules',
    'new', 'null', 'play', 'preview', 'privacy', 'stage', 'static', 'studio',
    'sw', 'sw-kill', 'test', 'undefined', 'uploads',
];

// --------------------------------------------------------------------------
// Vocabularies (VARCHAR columns checked against these, §9.1)
// --------------------------------------------------------------------------

const SE_GAME_TYPES = ['live_quiz', 'trivia', 'charades', 'buzzer', 'who_am_i', 'feud'];

const SE_CONTENT_TYPES = ['mcq', 'open', 'charade', 'clues', 'emoji', 'verse', 'survey'];

const SE_PROGRAM_KINDS = [
    'welcome', 'worship', 'prayer', 'word', 'game', 'karaoke', 'debate',
    'break', 'food', 'announcement', 'performance', 'buffer', 'closing', 'other',
];

/** Asset roles with their kind and byte cap (§14.1). */
const SE_ASSET_ROLES = [
    'logo'           => ['kind' => 'image', 'max_bytes' => 5242880],
    'wordmark'       => ['kind' => 'image', 'max_bytes' => 5242880],
    'hero'           => ['kind' => 'image', 'max_bytes' => 8388608],
    'hero_video'     => ['kind' => 'video', 'max_bytes' => 26214400],
    'hero_poster'    => ['kind' => 'image', 'max_bytes' => 8388608],
    'flyer'          => ['kind' => 'doc',   'max_bytes' => 15728640],
    'illustration'   => ['kind' => 'image', 'max_bytes' => 8388608],
    'background'     => ['kind' => 'image', 'max_bytes' => 8388608],
    'gallery'        => ['kind' => 'image', 'max_bytes' => 8388608],
    'sponsor'        => ['kind' => 'image', 'max_bytes' => 8388608],
    'sfx'            => ['kind' => 'audio', 'max_bytes' => 10485760],
    'music'          => ['kind' => 'audio', 'max_bytes' => 10485760],
    'lottie'         => ['kind' => 'json',  'max_bytes' => 1048576],
    'template'       => ['kind' => 'svg',   'max_bytes' => 2097152],
    'program_source' => ['kind' => 'doc',   'max_bytes' => 15728640],
    'songs_source'   => ['kind' => 'doc',   'max_bytes' => 15728640],
    'og_card'        => ['kind' => 'image', 'max_bytes' => 8388608],
    'story'          => ['kind' => 'image', 'max_bytes' => 8388608],
    'square'         => ['kind' => 'image', 'max_bytes' => 8388608],
    'portrait'       => ['kind' => 'image', 'max_bytes' => 8388608],
    'projector'      => ['kind' => 'image', 'max_bytes' => 8388608],
    'poster_a4'      => ['kind' => 'doc',   'max_bytes' => 15728640],
    'poster_a3'      => ['kind' => 'doc',   'max_bytes' => 15728640],
];

/** AI source roles purged by cron after 30 days (§14.1). */
const SE_ASSET_ROLES_TEMPORARY = ['program_source', 'songs_source'];

const SE_SCENES = [
    'standby', 'welcome', 'program', 'game', 'karaoke', 'leaderboard',
    'announcement', 'teams', 'finale', 'blackout',
];

const SE_SFX_CUES = [
    'tick', 'arm', 'reveal', 'correct', 'wrong', 'buzz', 'strike', 'ding',
    'team_name', 'fanfare', 'applause', 'drumroll', 'whoosh',
];

/** "How did you hear about us?" options (§20.4). */
const SE_HOW_HEARD = [
    'whatsapp'            => 'WhatsApp',
    'instagram'           => 'Instagram',
    'facebook'            => 'Facebook',
    'tiktok'              => 'TikTok',
    'friend_family'       => 'A friend or family',
    'church_announcement' => 'Church announcement',
    'flyer_poster'        => 'Flyer or poster',
    'sms'                 => 'SMS',
    'other'               => 'Something else',
];

/** Custom registration question types (se_form_fields.type). */
const SE_FORM_FIELD_TYPES = ['text', 'textarea', 'select', 'multiselect', 'checkbox', 'number'];

/** Font pairs offered in the Studio (§13.1.2). display => body. */
const SE_FONT_PAIRS = [
    'Unbounded'            => 'Inter',
    'Syne'                 => 'Manrope',
    'Bricolage Grotesque'  => 'Inter',
    'Space Grotesk'        => 'Inter',
    'Fraunces'             => 'Inter',
    'Monoton'              => 'Inter',
];

const SE_THEME_PRESETS = ['marquee'];

// --------------------------------------------------------------------------
// Limits
// --------------------------------------------------------------------------

const SE_MIN_TEAMS = 2;
const SE_MAX_TEAMS = 8;
const SE_MAX_EVENT_DAYS = 14;
const SE_MAX_FORM_FIELDS = 20;
const SE_MAX_JSON_BODY_BYTES = 1048576;   // 1 MB (§12.1)
const SE_SNAPSHOT_DEBOUNCE_MS = 400;
const SE_TICK_MS = 1000;

/** Token / code lengths (§10.3, §9.3). */
const SE_PUBLIC_ID_LENGTH = 12;
const SE_PREVIEW_KEY_LENGTH = 22;
const SE_MANAGE_TOKEN_LENGTH = 22;
const SE_REG_CODE_LENGTH = 8;
const SE_REF_CODE_LENGTH = 6;
const SE_DISPLAY_KEY_LENGTH = 22;

// --------------------------------------------------------------------------
// Schema expectations for se_schema_check() (Appendix A rule 3)
// --------------------------------------------------------------------------

/**
 * Tables this module expects, grouped by the migration that creates them.
 * `se_schema_check()` compares this with information_schema and reports
 * anything missing, including a table an earlier half-applied run created
 * with an outdated definition (CREATE TABLE IF NOT EXISTS never alters one).
 *
 * Only the columns the code depends on are listed — enough to catch a stale
 * table — not every column in Appendix A.
 */
const SE_SCHEMA_EXPECTED = [
    '20261006090000_se_core.sql' => [
        'se_settings'    => ['setting_key', 'setting_value', 'updated_by', 'updated_at'],
        'se_series'      => ['id', 'name', 'description', 'created_by', 'created_at', 'archived_at'],
        'se_events'      => [
            'id', 'public_id', 'preview_key', 'series_id', 'cloned_from_event_id', 'slug',
            'title', 'edition_label', 'tagline', 'description_md', 'organizer_label',
            'venue_name', 'venue_address', 'venue_map_url', 'venue_notes',
            'starts_at', 'ends_at', 'status', 'cancel_reason', 'visibility',
            'theme_preset', 'brand_primary', 'brand_secondary', 'brand_accent',
            'palette_json', 'font_display', 'font_body',
            'logo_asset_id', 'hero_asset_id', 'hero_video_asset_id', 'og_asset_id',
            'reg_opens_at', 'reg_closes_at', 'reg_override', 'reg_override_note',
            'reg_override_by', 'reg_override_at', 'online_capacity',
            'auto_close_at_capacity', 'waitlist_enabled', 'waitlist_capacity',
            'waitlist_promotion', 'waitlist_notify_sms', 'self_cancel_enabled',
            'walkin_enabled', 'walkin_capacity', 'walkin_hard_cap',
            'seats_left_mode', 'seats_left_threshold_pct', 'team_rr_pointer',
            'next_player_no', 'next_karaoke_no', 'settings_json', 'ticketing_enabled',
            'row_version', 'created_by', 'updated_by', 'created_at', 'updated_at',
            'published_at', 'cancelled_at', 'archived_at',
        ],
        'se_slugs'       => ['slug', 'event_id', 'is_canonical', 'created_at'],
        'se_event_days'  => ['id', 'event_id', 'day_date', 'label', 'doors_open_at', 'starts_at', 'ends_at', 'checkin_closes_at'],
    ],
    '20261006090100_se_people.sql' => [
        'se_contacts'      => ['id', 'phone_e164', 'first_name', 'last_name', 'email', 'gender', 'member_user_id', 'consent_followup', 'opted_out_at', 'erased_at'],
        'se_registrations' => ['id', 'event_id', 'contact_id', 'reg_code', 'ref_code', 'status', 'seat_pool', 'channel', 'is_member', 'is_test', 'display_name', 'team_id', 'player_no'],
        'se_access_tokens' => ['id', 'event_id', 'registration_id', 'purpose', 'token_hash', 'expires_at'],
        'se_devices'       => ['id', 'event_id', 'registration_id', 'token_hash', 'mode', 'joined_games_at'],
        'se_form_fields'   => ['id', 'event_id', 'field_key', 'label', 'type', 'options_json', 'is_required', 'audience', 'sort_order', 'is_active'],
    ],
    '20261006090200_se_ops.sql' => [
        'se_crew'          => ['id', 'event_id', 'user_id', 'role', 'added_by', 'added_at', 'revoked_at'],
        'se_assets'        => ['id', 'event_id', 'kind', 'role', 'title', 'alt_text', 'path', 'mime', 'bytes', 'width', 'height', 'sha256', 'variants_json', 'meta_json', 'deleted_at'],
        'se_audit_log'     => ['id', 'event_id', 'actor_user_id', 'actor_registration_id', 'action', 'entity', 'entity_id', 'detail_json', 'ip_hash', 'created_at'],
        'se_rate_limits'   => ['bucket', 'subject_hash', 'window_start', 'hits'],
        'se_metrics_daily' => ['event_id', 'metric_date', 'metric', 'dim', 'value'],
        'se_ai_jobs'       => ['id', 'event_id', 'task', 'input_json', 'source_asset_id', 'result_json', 'status', 'error', 'created_by'],
        'se_ai_requests'   => ['id', 'job_id', 'event_id', 'user_id', 'task', 'model', 'prompt_version', 'input_tokens', 'output_tokens', 'latency_ms', 'ok'],
        'se_message_runs'  => ['id', 'event_id', 'kind', 'run_key', 'scheduled_for', 'status', 'sms_campaign_id', 'recipients', 'est_units'],
    ],
];

/**
 * Tables each later phase adds (§9.4). The Studio hides a tab whose tables
 * are missing, and se_schema_check() reports them as "not yet migrated"
 * rather than as an error.
 */
const SE_SCHEMA_LATER_PHASES = [
    'B' => ['se_teams', 'se_checkins', 'se_team_moves', 'se_event_verses', 'se_bible_cache',
            'se_program_items', 'se_songs', 'se_event_songs', 'se_karaoke_entries', 'se_live_state'],
    'C' => ['se_decks', 'se_deck_items', 'se_games', 'se_game_items', 'se_rounds',
            'se_answers', 'se_buzzes', 'se_survey_responses', 'se_feud_answers', 'se_score_events'],
    'D' => ['se_feedback', 'se_handoffs', 'se_handoff_items'],
];

/**
 * Studio tabs, with the table that must exist before the tab is shown.
 * `null` means the tab is always available. PR1 ships the tabs whose
 * gate table is in Phase A; the rest unhide themselves as migrations land.
 */
const SE_STUDIO_TABS = [
    'overview'     => ['label' => 'Overview',     'requires' => null],
    'details'      => ['label' => 'Details',      'requires' => null],
    'brand'        => ['label' => 'Brand',        'requires' => null],
    'registration' => ['label' => 'Registration', 'requires' => 'se_form_fields'],
    'program'      => ['label' => 'Programme',    'requires' => 'se_program_items'],
    'teams'        => ['label' => 'Teams',        'requires' => 'se_teams'],
    'karaoke'      => ['label' => 'Karaoke',      'requires' => 'se_songs'],
    'games'        => ['label' => 'Games',        'requires' => 'se_games'],
    'assets'       => ['label' => 'Assets',       'requires' => 'se_assets'],
    'crew'         => ['label' => 'Crew',         'requires' => 'se_crew'],
    'messages'     => ['label' => 'Messages',     'requires' => 'se_message_runs'],
    'attendees'    => ['label' => 'Attendees',    'requires' => 'se_registrations'],
    'live'         => ['label' => 'Live',         'requires' => 'se_live_state'],
    'insights'     => ['label' => 'Insights',     'requires' => 'se_metrics_daily'],
    'handoff'      => ['label' => 'Hand-off',     'requires' => 'se_handoffs'],
    'settings'     => ['label' => 'Settings',     'requires' => null],
];

/**
 * Tabs PR1 actually implements. A tab whose gate table exists but whose UI
 * belongs to a later PR stays hidden, so the Studio never shows a dead tab
 * (build_prompts.md PR1: "Tabs belonging to later PRs stay hidden").
 */
const SE_STUDIO_TABS_READY = [
    'overview', 'details', 'brand', 'registration', 'assets', 'crew', 'settings',
];

// --------------------------------------------------------------------------
// Front-end preload lists (§8.6.1). Checked by tests/special_events/preload_test.php
// --------------------------------------------------------------------------

/**
 * Critical modules per surface, emitted as <link rel="modulepreload">.
 * MUST stay in sync with each entry module's static import graph — the
 * preload test walks the imports and fails on a mismatch.
 */
const SE_PRELOAD = [
    'portal' => [
        '/assets/se/js/portal/main.js',
        '/assets/se/js/core/boot.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/store.js',
    ],
    'studio' => [
        '/assets/se/js/studio/main.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/theme.js',
    ],
];

// --------------------------------------------------------------------------
// Audit actions (§19.10). Used to validate the `action` passed to se_audit().
// --------------------------------------------------------------------------

const SE_AUDIT_ACTIONS = [
    'event_create', 'event_update', 'event_publish', 'event_unpublish',
    'event_cancel', 'event_archive', 'event_delete', 'slug_change', 'slug_reclaim',
    'override_set', 'capacity_change', 'register', 'register_bot_suspected',
    'cancel', 'promote', 'checkin', 'checkin_undo', 'walkin_override',
    'transfer_code_issued', 'device_transfer', 'team_assign', 'team_move',
    'team_rename', 'captain_set', 'scene_set', 'program_op', 'game_op',
    'round_op', 'score_award', 'score_void', 'karaoke_op', 'crew_add',
    'crew_revoke', 'keys_rotate', 'export', 'handoff_run', 'erase', 'optout',
    'ai_job_apply', 'messages_run', 'adhoc_message', 'test_mode', 'notice_sent',
    'client_error', 'asset_upload', 'asset_delete', 'settings_save',
];
