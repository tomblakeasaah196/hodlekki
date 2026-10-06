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

/** What a producer sees for each game type and content type. */
const SE_GAME_TYPE_LABELS = [
    'live_quiz' => 'Live Quiz',
    'trivia'    => 'Bible Trivia (captains)',
    'buzzer'    => 'Buzzer',
    'who_am_i'  => 'Who Am I?',
    'charades'  => 'Bible Charades',
    'feud'      => 'Family Feud',
];

const SE_CONTENT_TYPE_LABELS = [
    'mcq'     => 'Multiple choice',
    'open'    => 'Question and answer',
    'emoji'   => 'Emoji puzzle',
    'verse'   => 'Finish the verse',
    'clues'   => 'Who am I? clues',
    'charade' => 'Charades phrase',
    'survey'  => 'Feud survey question',
];

/**
 * Which content each game type can play (§11.1). Live Quiz and Trivia need
 * answer choices, so a verse or emoji item only qualifies there once it has
 * them (se_game_item_playable()).
 */
const SE_GAME_CONTENT_TYPES = [
    'live_quiz' => ['mcq', 'verse', 'emoji'],
    'trivia'    => ['mcq', 'verse'],
    'buzzer'    => ['open', 'verse', 'emoji', 'mcq'],
    'who_am_i'  => ['clues'],
    'charades'  => ['charade'],
    'feud'      => ['survey'],
];

/** Game settings defaults (§11.14). Every number is editable per game. */
const SE_GAME_DEFAULTS = [
    'live_quiz' => [
        'preroll_ms' => 3000, 'duration_ms' => 20000, 'grace_ms' => 1500, 'points_mode' => 'standard',
        'team_base' => 1000, 'auto_lock' => true, 'auto_score' => true,
    ],
    'trivia' => [
        'preroll_ms' => 3000, 'duration_ms' => 30000, 'grace_ms' => 1500, 'points_correct' => 300,
        'use_suggestions_if_no_captain' => true, 'auto_lock' => true, 'auto_score' => true,
    ],
    'buzzer' => [
        'preroll_ms' => 2500, 'window_ms' => 15000, 'reopen_window_ms' => 10000, 'points_correct' => 300,
        'wrong_penalty' => 0, 'reopen_on_wrong' => true,
    ],
    'who_am_i' => [
        'preroll_ms' => 2500, 'window_ms' => 20000, 'points_by_clue' => [500, 400, 300, 200, 100],
        'wrong_penalty' => 0,
    ],
    'charades' => [
        'turn_ms' => 60000, 'points_per_word' => 200, 'max_passes' => 2,
    ],
    'feud' => [
        'round_multipliers' => [1, 1, 2, 3], 'faceoff_window_ms' => 15000,
    ],
];

/** Charades categories (Appendix C). */
const SE_CHARADE_CATEGORIES = ['person', 'story', 'object', 'place', 'miracle', 'parable'];

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
    'poster_screen'  => ['kind' => 'doc',   'max_bytes' => 15728640],
];

/** AI source roles purged by cron after 30 days (§14.1). */
const SE_ASSET_ROLES_TEMPORARY = ['program_source', 'songs_source'];

/**
 * Stage scenes (§11.12).
 *
 * `blank` is the §11.12 name for what PR1 called `blackout`; `finale` is kept
 * because §13.8 names it as the closing scene (§28.4).
 */
const SE_SCENES = [
    'standby', 'welcome', 'program', 'teams', 'game', 'leaderboard',
    'karaoke', 'announcement', 'break', 'blank', 'recap', 'finale',
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
/**
 * The shortest a human can plausibly fill the registration sheet in, in
 * milliseconds. A faster submission is treated as a bot (§12.2, §19.4).
 */
/**
 * Share-kit source codes (§18.2). `?s=<code>` on any portal link is stored on
 * the registration and counted in the daily metrics, so Envision can see
 * which poster, status or announcement actually brought people.
 */
const SE_SOURCE_CODES = [
    'wa'     => 'WhatsApp',
    'ig'     => 'Instagram',
    'fb'     => 'Facebook',
    'tt'     => 'TikTok',
    'x'      => 'X',
    'flyer'  => 'Flyer',
    'poster' => 'Poster',
    'sms'    => 'SMS',
    'pulpit' => 'Church announcement',
    'qr'     => 'QR code',
    'email'  => 'Email',
];

/** What the Studio's AI copywriter can be asked for (§15.8). */
const SE_COPYWRITE_PURPOSES = [
    'tagline', 'description', 'activity_blurb', 'faq_answer',
    'sms_reminder', 'sms_thanks', 'card_headline',
];

/** How each purpose is described to the model, and to the Studio's menu. */
const SE_COPYWRITE_LABELS = [
    'tagline'        => 'a one-line event tagline',
    'description'    => 'the portal description paragraph',
    'activity_blurb' => 'a blurb for one activity',
    'faq_answer'     => 'an answer to a frequently asked question',
    'sms_reminder'   => 'an SMS reminder',
    'sms_thanks'     => 'an SMS thank-you after the event',
    'card_headline'  => 'a headline for a share card',
];

/** The length each purpose must respect (§15.8). */
const SE_COPYWRITE_LIMITS = [
    'tagline'        => 'at most 12 words',
    'description'    => 'at most 120 words',
    'activity_blurb' => 'at most 25 words',
    'faq_answer'     => 'at most 60 words',
    'sms_reminder'   => 'at most 150 characters excluding {{link}}',
    'sms_thanks'     => 'at most 150 characters excluding {{link}}',
    'card_headline'  => 'at most 6 words',
];

/** Maximum characters kept after AI generation for each copywriting purpose. */
const SE_COPYWRITE_CHAR_LIMITS = [
    'tagline'        => 180,
    'description'    => 1200,
    'activity_blurb' => 320,
    'faq_answer'     => 900,
    'sms_reminder'   => 220,
    'sms_thanks'     => 220,
    'card_headline'  => 120,
];

/**
 * Purpose-specific craft notes sent to the model (§15.8). One generic
 * instruction produced flat, interchangeable copy; each purpose now says what
 * a good answer actually looks like.
 */
const SE_COPYWRITE_GUIDANCE = [
    'tagline' => 'One line a designer could set in large type. No full stop needed, no sub-clause pile-up, '
        . 'no colon-plus-slogan formula in every option. Concrete beats abstract.',
    'description' => 'This is the paragraph a first-time guest reads on the event page before deciding to come. '
        . 'Aim for 70 to 110 words per option — a full, confident paragraph, never one or two thin sentences. '
        . 'Open with something a guest can picture, say plainly what the evening is and who it is for, name one or '
        . 'two real things that will happen, and close with a simple, unpushy invitation. Mention the date and venue '
        . 'naturally only if they are in the facts. Do not quote or restate the tagline as a sentence; the page '
        . 'already shows it directly above this paragraph. Plain prose only: no headings, no bullet lists, no emoji, '
        . 'no hashtags, no ALL CAPS.',
    'activity_blurb' => 'One or two sentences that tell a guest what actually happens and why it is fun. '
        . 'Name the activity once; do not oversell it.',
    'faq_answer' => 'Answer the question directly in the first sentence, then add the one practical detail that '
        . 'saves the guest another question. Calm, factual, friendly.',
    'sms_reminder' => 'Sounds like a person texting, not a broadcast. Lead with what, when and where; keep the '
        . 'link at the end.',
    'sms_thanks' => 'Warm, short and specific. Thank them for coming, point once at what comes next.',
    'card_headline' => 'Short enough to read at a glance on a phone-sized share card. Punchy, not shouty.',
];

/**
 * The three angles the model must take, one per variant, so the options are
 * genuinely different instead of three rewordings of the same sentence.
 */
const SE_COPYWRITE_ANGLES = [
    'description' => [
        'a guest-first warm invitation — written to someone who has never been to this church, easing them in',
        'activity-forward and high-energy — lead with what will actually happen during the evening',
        'community and belonging — the people in the room, coming together, bringing a friend along',
    ],
    'tagline' => [
        'invitational and warm',
        'playful and energetic, built around the main activity',
        'belonging-focused — "us", together, a room full of people',
    ],
    'activity_blurb' => [
        'what a guest will do, step by step',
        'the fun and the energy of it',
        'why it is easy to join in even if you are shy',
    ],
    'faq_answer' => [
        'the plain, direct answer',
        'the answer plus the practical detail that follows it',
        'the answer in a reassuring, guest-calming voice',
    ],
    'sms_reminder' => [
        'plain and practical',
        'warm and personal',
        'short and excited',
    ],
    'sms_thanks' => [
        'simple gratitude',
        'gratitude plus what comes next',
        'warm and personal',
    ],
    'card_headline' => [
        'invitational',
        'activity-led',
        'belonging-led',
    ],
];

/**
 * Filler and clichés the copy must avoid. Checked in the prompt and used by
 * the Studio's review hints — never used to silently rewrite model output.
 */
const SE_COPYWRITE_BANNED_PHRASES = [
    "you don't want to miss it",
    'you do not want to miss it',
    'your dose of happiness',
    'something for everyone',
    'good vibes',
    'warm joy',
    'fun for all ages',
    'unforgettable experience',
    'save the date',
    'mark your calendar',
];

/**
 * Target word window per purpose, used for the prompt text and for the
 * Studio's soft word-count hint. [min, max]; max is the hard limit.
 */
const SE_COPYWRITE_WORD_TARGETS = [
    'description' => [70, 120],
];

/**
 * How similar two variants may be before the later one is treated as a
 * near-duplicate (Jaccard overlap of their word sets, 0..1).
 */
const SE_COPYWRITE_DUPLICATE_THRESHOLD = 0.72;

const SE_REGISTER_MIN_FILL_MS = 1500;

const SE_MANAGE_TOKEN_LENGTH = 22;
const SE_REG_CODE_LENGTH = 8;
const SE_REF_CODE_LENGTH = 6;
/** Desk transfer codes: 6 digits, single use, ten minutes (§9.3, §10.3.6). */
const SE_TRANSFER_CODE_TTL_MIN = 10;
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
    '20261013090000_se_checkin_teams.sql' => [
        'se_teams'        => ['id', 'event_id', 'sort_order', 'color_hex', 'color_label', 'name', 'name_set_at', 'name_set_by', 'captain_registration_id', 'team_key'],
        'se_checkins'     => ['id', 'event_id', 'registration_id', 'day_date', 'method', 'is_walkin', 'is_test', 'device_id', 'checked_in_by', 'verse_id', 'checked_in_at'],
        'se_team_moves'   => ['id', 'event_id', 'registration_id', 'from_team_id', 'to_team_id', 'method', 'reason', 'moved_by'],
        'se_event_verses' => ['id', 'event_id', 'translation', 'ref_display', 'text', 'text_source', 'prayer_template', 'sort_order', 'is_active', 'approved_by', 'approved_at', 'second_approved_by', 'times_used'],
        'se_bible_cache'  => ['translation', 'ref_norm', 'ref_display', 'text', 'fetched_at'],
    ],
    '20261013090100_se_program_karaoke.sql' => [
        'se_program_items'     => [
            'id', 'event_id', 'day_id', 'sort_order', 'kind', 'title', 'public_blurb',
            'icon', 'host_name', 'planned_start_at', 'duration_min', 'is_public',
            'is_featured', 'game_id', 'media_asset_id', 'crew_notes', 'source',
            'status', 'started_at', 'ended_at',
        ],
        'se_songs'             => ['id', 'title', 'artist', 'duration_sec', 'title_norm', 'artist_norm', 'tags', 'created_by'],
        'se_event_songs'       => ['event_id', 'song_id', 'is_active', 'sort_order', 'added_by', 'added_at'],
        'se_karaoke_entries'   => [
            'id', 'event_id', 'registration_id', 'song_id', 'status', 'source',
            'enforce_unique', 'is_test', 'queue_no', 'position', 'held_at',
            'queued_at', 'on_stage_at', 'finished_at', 'updated_by',
            'active_song_key', 'active_singer_key',
        ],
    ],
    '20261020090000_se_games.sql' => [
        'se_decks' => ['id','title','content_type','scope','event_id','translation'],
        'se_deck_items' => ['id','deck_id','payload_json','review_status','scripture_ref','scripture_text','times_used'],
        'se_games' => ['id','event_id','type','title','settings_json','weight','status','program_item_id'],
        'se_game_items' => ['game_id','deck_item_id','sort_order'],
        'se_rounds' => ['id','event_id','game_id','round_no','deck_item_id','state','attempt','opens_at','closes_at','eligible_json','result_json'],
        'se_answers' => ['id','event_id','round_id','registration_id','role','choice_index','elapsed_ms'],
        'se_buzzes' => ['id','event_id','round_id','attempt','team_id','registration_id','effective_ms','judged'],
        'se_survey_responses' => ['id','event_id','deck_item_id','registration_id','answer_text','answer_norm','is_test'],
        'se_feud_answers' => ['id','event_id','deck_item_id','label','points','approved'],
        'se_score_events' => ['id','event_id','scope','team_id','registration_id','round_id','kind','points','idempotency_key','voided_at'],
    ],
    '20261013090200_se_live_state.sql' => [
        'se_live_state' => ['event_id', 'version', 'scene', 'scene_payload_json', 'announcement_json', 'sfx_seq', 'sfx_cue', 'room_key', 'lobby_key', 'stage_key', 'dirty', 'last_published_at', 'updated_at', 'updated_by'],
    ],
    '20261027090000_se_post_event.sql' => [
        'se_feedback' => ['id', 'event_id', 'registration_id', 'nps', 'favorite', 'one_word', 'comment', 'wants_visit', 'future_optin'],
        'se_handoffs' => ['id', 'event_id', 'reach_campaign_id', 'summary_json', 'created_by', 'created_at'],
        'se_handoff_items' => ['id', 'handoff_id', 'event_id', 'registration_id', 'contact_id', 'destination', 'outcome', 'target_table', 'target_id', 'done_contact_key'],
    ],
    '20261103090000_se_verses_ai_flag.sql' => [
        'se_event_verses' => ['suggested_by_ai'],
    ],
];

/**
 * Tables each later phase adds (§9.4). The Studio hides a tab whose tables
 * are missing, and se_schema_check() reports them as "not yet migrated"
 * rather than as an error.
 */
const SE_SCHEMA_LATER_PHASES = [
    'C' => ['se_decks', 'se_deck_items', 'se_games', 'se_game_items', 'se_rounds',
            'se_answers', 'se_buzzes', 'se_survey_responses', 'se_feud_answers', 'se_score_events'],
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
    'checkin'      => ['label' => 'Check-in',     'requires' => 'se_checkins'],
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
    'overview', 'details', 'brand', 'registration', 'checkin', 'program', 'teams',
    'karaoke', 'games', 'messages', 'live', 'attendees', 'insights', 'handoff', 'assets', 'crew', 'settings',
];

/**
 * Karaoke entry statuses (§10.8.2), grouped the way the rules read.
 *
 * `active` is what the UNIQUE(active_singer_key) index covers; `claiming`
 * adds `done`, which is what the unique-song rule covers — a song that has
 * already been sung is not free again.
 */
const SE_KARAOKE_STATUSES = [
    'held', 'queued', 'up_next', 'on_stage', 'done', 'skipped', 'no_show',
    'released', 'cancelled',
];

const SE_KARAOKE_ACTIVE   = ['held', 'queued', 'up_next', 'on_stage'];
const SE_KARAOKE_CLAIMING = ['held', 'queued', 'up_next', 'on_stage', 'done'];
const SE_KARAOKE_QUEUED   = ['queued', 'up_next', 'on_stage'];

/**
 * The longest a single karaoke entry can claim to be (§10.8).
 *
 * Two hours, because a medley or a worship set does occasionally get typed
 * in as one row, and silently dropping its length would make the DJ's
 * "minutes left" lie.
 */
const SE_SONG_MAX_SECONDS = 7200;

/** Statuses the DJ console may set directly (§10.8.2). */
const SE_KARAOKE_DJ_STATUSES = ['queued', 'up_next', 'on_stage', 'done', 'skipped', 'no_show'];

/** How a public programme shows its times (§10.7.2). */
const SE_PROGRAM_TIME_MODES = ['exact', 'approximate', 'order_only'];

/** Segments an ad-hoc message may be sent to (§16.6). */
const SE_MESSAGE_SEGMENTS = [
    'confirmed', 'waitlisted', 'checked_in', 'confirmed_not_checked_in',
    'karaoke_singers', 'cancelled',
];

/** Message kinds the cron schedules on its own (§16.2). */
const SE_SCHEDULED_MESSAGE_KINDS = ['reminder_1', 'reminder_2'];

// --------------------------------------------------------------------------
// Front-end preload lists (§8.6.1). Checked by tests/special_events/preload_test.php
// --------------------------------------------------------------------------

/**
 * Critical modules per surface, emitted as <link rel="modulepreload">.
 * MUST stay in sync with each entry module's static import graph — the
 * preload test walks the imports and fails on a mismatch.
 */
/**
 * GSAP and its plugins, loaded as deferred UMD globals on the animated
 * surfaces (§13.1.4). They are not ES modules in the vendored build, so they
 * cannot go in the import map; @se/core/motion.js reads window.gsap and
 * degrades to no animation when it is absent.
 */
const SE_GSAP_SCRIPTS = [
    '/assets/se/vendor/gsap-3.13.0/gsap.min.js',
    '/assets/se/vendor/gsap-3.13.0/ScrollTrigger.min.js',
    '/assets/se/vendor/gsap-3.13.0/SplitText.min.js',
];

const SE_PRELOAD = [
    'portal' => [
        '/assets/se/js/portal/main.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/motion.js',
        '/assets/se/js/core/boot.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/phone.js',
    ],
    'studio' => [
        '/assets/se/js/studio/main.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/boot.js',
        '/assets/se/js/core/theme.js',
        '/assets/se/js/core/store.js',
    ],

    // The displays are unattended all evening, so everything they need is
    // on the first paint — there is nobody standing there to reload them.
    'stage' => [
        '/assets/se/js/stage/main.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/clock.js',
        '/assets/se/js/core/realtime.js',
        '/assets/se/js/core/sfx.js',
        '/assets/se/js/core/qr.js',
        '/assets/se/js/core/boot.js',
        '/assets/se/js/core/svg.js',
    ],
    'lobby' => [
        '/assets/se/js/lobby/main.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/clock.js',
        '/assets/se/js/core/realtime.js',
        '/assets/se/js/core/qr.js',
        '/assets/se/js/core/boot.js',
        '/assets/se/js/core/svg.js',
    ],
    'host' => [
        '/assets/se/js/host/main.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/clock.js',
        '/assets/se/js/core/boot.js',
    ],
    'desk' => [
        '/assets/se/js/desk/main.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/phone.js',
        '/assets/se/js/core/boot.js',
    ],
    'dj' => [
        '/assets/se/js/dj/main.js',
        '/assets/se/js/core/html.js',
        '/assets/se/js/core/store.js',
        '/assets/se/js/core/api.js',
        '/assets/se/js/core/clock.js',
        '/assets/se/js/core/boot.js',
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
    'client_error', 'asset_upload', 'asset_delete', 'settings_save', 'verse_save',
];
