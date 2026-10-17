-- /db/migrations/20261120090000_hymnal_core.sql
--
-- The Hymnary: Household of David's own song library.
--
-- The 511 public-domain hymns live in files (includes/hymnal/corpus/), not in
-- here, so that reading and singing work with no database at all — the tree
-- reaches production before db/migrate.php runs, and a hymn should never be
-- unreadable because a migration is pending.
--
-- These tables hold what the corpus cannot: the songs our own music team adds
-- (the modern worship the congregation actually sings, which no free corpus
-- carries), the Yoruba and other language versions, what each member marks and
-- where they stopped, and the record of who published what.
--
-- NUMBERING. A HOD number is permanent and gets read out from the pulpit, so it
-- is never reused and never shifts. The corpus owns 1-1999 through its
-- committed ledger; every row here takes a number from 2000 up, allocated
-- inside a transaction and guaranteed by the unique key, never by
-- check-then-insert. See HYMNAL_DB_NUMBER_FLOOR in includes/hymnal/songs.php.
--
-- LICENCE. Every row carries its own licence record. A song is only readable
-- once someone has said under what right we hold it, because the library mixes
-- public-domain hymns with songs held under the church's copyright licence, and
-- "it was already in the system" is not a licence.

CREATE TABLE IF NOT EXISTS hymnal_songs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    number INT UNSIGNED NOT NULL,
    slug VARCHAR(190) NOT NULL,
    title VARCHAR(255) NOT NULL,
    alt_titles VARCHAR(500) NOT NULL DEFAULT '',
    first_line VARCHAR(500) NOT NULL DEFAULT '',
    writer VARCHAR(255) NOT NULL DEFAULT '',
    composer VARCHAR(255) NOT NULL DEFAULT '',
    arranger VARCHAR(255) NOT NULL DEFAULT '',
    tune_name VARCHAR(190) NOT NULL DEFAULT '',
    year SMALLINT UNSIGNED NULL DEFAULT NULL,
    song_key VARCHAR(16) NOT NULL DEFAULT '',
    time_sig VARCHAR(16) NOT NULL DEFAULT '',
    bpm SMALLINT UNSIGNED NULL DEFAULT NULL,
    metre VARCHAR(64) NOT NULL DEFAULT '',
    themes VARCHAR(500) NOT NULL DEFAULT '',
    scripture VARCHAR(500) NOT NULL DEFAULT '',
    licence_code VARCHAR(32) NOT NULL DEFAULT 'CCLI',
    licence_detail VARCHAR(500) NOT NULL DEFAULT '',
    licence_holder VARCHAR(190) NOT NULL DEFAULT '',
    ccli_number VARCHAR(32) NOT NULL DEFAULT '',
    source_note VARCHAR(500) NOT NULL DEFAULT '',
    status VARCHAR(16) NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT UNSIGNED NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    published_by INT UNSIGNED NULL DEFAULT NULL,
    published_at DATETIME NULL DEFAULT NULL,
    retired_reason VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (id),
    UNIQUE KEY uq_hymnal_songs_number (number),
    UNIQUE KEY uq_hymnal_songs_slug (slug),
    KEY ix_hymnal_songs_status (status),
    KEY ix_hymnal_songs_title (title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per language. English is normally the primary; a Yoruba version is a
-- sibling row against the same song and the same tune, never a separate song,
-- so a chorister can read either set of words off one score.
CREATE TABLE IF NOT EXISTS hymnal_song_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_id INT UNSIGNED NOT NULL,
    lang VARCHAR(8) NOT NULL DEFAULT 'en',
    title VARCHAR(255) NOT NULL DEFAULT '',
    sections MEDIUMTEXT NOT NULL,
    chordpro MEDIUMTEXT NOT NULL,
    sing_order VARCHAR(255) NOT NULL DEFAULT '',
    is_primary TINYINT UNSIGNED NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hymnal_versions_song_lang (song_id, lang),
    KEY ix_hymnal_versions_song (song_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A song has at most one score. `voices` holds the named parts and the sol-fa
-- generated from the ABC by hymnal_solfa_voice(), so choir mode reads a Studio
-- song exactly as it reads a corpus hymn.
CREATE TABLE IF NOT EXISTS hymnal_song_scores (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    song_id INT UNSIGNED NOT NULL,
    abc MEDIUMTEXT NOT NULL,
    doh VARCHAR(16) NOT NULL DEFAULT '',
    voices MEDIUMTEXT NOT NULL,
    arrangement_licence VARCHAR(32) NOT NULL DEFAULT '',
    arrangement_note VARCHAR(500) NOT NULL DEFAULT '',
    created_by INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hymnal_scores_song (song_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which shelves a Studio song sits on. The shelf list itself is code
-- (includes/hymnal/collections.php), so adding a shelf needs no migration.
CREATE TABLE IF NOT EXISTS hymnal_song_collections (
    song_id INT UNSIGNED NOT NULL,
    collection VARCHAR(64) NOT NULL,
    PRIMARY KEY (song_id, collection),
    KEY ix_hymnal_song_collections_collection (collection)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keyed by HOD number rather than by song id, so one table covers both the
-- corpus hymns (which have no row anywhere) and the Studio songs.
CREATE TABLE IF NOT EXISTS hymnal_favourites (
    user_id INT UNSIGNED NOT NULL,
    song_number INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, song_number),
    KEY ix_hymnal_favourites_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Where a member stopped and how they like the page. Private to them.
CREATE TABLE IF NOT EXISTS hymnal_user_state (
    user_id INT UNSIGNED NOT NULL,
    mode VARCHAR(16) NOT NULL DEFAULT 'congregation',
    last_number INT UNSIGNED NULL DEFAULT NULL,
    lang VARCHAR(8) NOT NULL DEFAULT 'en',
    prefs MEDIUMTEXT NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What a member has opened lately, for "pick up where you were".
CREATE TABLE IF NOT EXISTS hymnal_recent (
    user_id INT UNSIGNED NOT NULL,
    song_number INT UNSIGNED NOT NULL,
    opened_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, song_number),
    KEY ix_hymnal_recent_user_time (user_id, opened_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- An AI reading of something the music team uploaded, waiting for a person to
-- check it. NOTHING here is a song until someone has read it and pressed save:
-- `status` only ever reaches 'applied' through a human action, and the song it
-- became is recorded so the two can be compared later.
CREATE TABLE IF NOT EXISTS hymnal_ai_jobs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by INT UNSIGNED NOT NULL,
    kind VARCHAR(32) NOT NULL DEFAULT 'extract',
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    input_kind VARCHAR(16) NOT NULL DEFAULT 'text',
    input_note VARCHAR(500) NOT NULL DEFAULT '',
    result MEDIUMTEXT NOT NULL,
    error VARCHAR(500) NOT NULL DEFAULT '',
    applied_song_id INT UNSIGNED NULL DEFAULT NULL,
    applied_by INT UNSIGNED NULL DEFAULT NULL,
    applied_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_hymnal_ai_jobs_user (created_by, created_at),
    KEY ix_hymnal_ai_jobs_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per AI call attempt, successful or not. http_status is always set,
-- with 0 meaning the request never reached Google, which is the difference
-- between "the key is wrong" and "the network is down".
CREATE TABLE IF NOT EXISTS hymnal_ai_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    job_id INT UNSIGNED NULL DEFAULT NULL,
    task VARCHAR(64) NOT NULL,
    model VARCHAR(190) NOT NULL DEFAULT '',
    http_status SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ok TINYINT UNSIGNED NOT NULL DEFAULT 0,
    duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_hymnal_ai_requests_user_time (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every Studio action that changes what the congregation sees.
CREATE TABLE IF NOT EXISTS hymnal_audit (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NULL DEFAULT NULL,
    action VARCHAR(64) NOT NULL,
    song_id INT UNSIGNED NULL DEFAULT NULL,
    song_number INT UNSIGNED NULL DEFAULT NULL,
    detail VARCHAR(500) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_hymnal_audit_song (song_id),
    KEY ix_hymnal_audit_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module switches, so the office can turn the AI help or the Studio off
-- without a deploy.
CREATE TABLE IF NOT EXISTS hymnal_config (
    config_key VARCHAR(64) NOT NULL,
    config_value VARCHAR(500) NOT NULL DEFAULT '',
    updated_by INT UNSIGNED NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (config_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
