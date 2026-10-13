-- 20261114090000_bible_core.sql
-- ---------------------------------------------------------------------------
-- The Bible: reading, highlights, notes, study journal, reading plans.
--
-- Nothing here touches an existing table. Scripture itself is NOT stored in
-- the database:
--   * the KJV ships as data files in includes/bible/kjv/ (public domain);
--   * NKJV, NIV and AMP come from API.Bible and live in bible_chapter_cache
--     for at most 29 days, because API.Bible's terms require cached text to
--     be refreshed at least every 30 days.
--
-- Everything a member writes is private to them: every table is keyed by
-- user_id and every query in includes/bible/ filters on it.
--
-- Positions inside a chapter are (verse, offset): offset counts characters
-- (Unicode code points) into that verse's text in that translation, and an
-- end position is exclusive. That is what lets a highlight cover exactly the
-- words a member selected.
--
-- Runs on MySQL 8.0 and MariaDB 10.11 (both exercised by CI): no DELIMITER
-- blocks, no generated columns, no functional indexes, no foreign keys to
-- pre-existing tables (users.id's exact type differs between hosts), and
-- every statement is idempotent.
-- ---------------------------------------------------------------------------

-- Where each member stopped reading, and their reader settings (theme, text
-- size, voice …) as a small JSON object.
CREATE TABLE IF NOT EXISTS bible_user_state (
    user_id      INT UNSIGNED      NOT NULL,
    translation  VARCHAR(10)       NOT NULL DEFAULT 'KJV',
    book         TINYINT UNSIGNED  NOT NULL DEFAULT 43,
    chapter      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    verse        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    prefs        TEXT              NULL,
    updated_at   DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exact-word highlights. One row per contiguous coloured run; applying a new
-- colour over part of an old highlight splits or trims the old row (see
-- bible_hl_plan()). `quote` keeps the highlighted words for the public-domain
-- KJV only — licensed translations are never stored outside the 29-day cache.
CREATE TABLE IF NOT EXISTS bible_highlights (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    translation   VARCHAR(10)       NOT NULL,
    book          TINYINT UNSIGNED  NOT NULL,
    chapter       SMALLINT UNSIGNED NOT NULL,
    verse_start   SMALLINT UNSIGNED NOT NULL,
    offset_start  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    verse_end     SMALLINT UNSIGNED NOT NULL,
    offset_end    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    color         VARCHAR(10)       NOT NULL DEFAULT 'yellow',
    quote         VARCHAR(1000)     NULL DEFAULT NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bible_hl_chapter (user_id, translation, book, chapter),
    KEY idx_bible_hl_recent (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notes. kind = 'verse' is pinned to a verse or an exact phrase (the anchor
-- columns); kind = 'journal' is a free study entry that links to any number
-- of passages through bible_note_refs.
CREATE TABLE IF NOT EXISTS bible_notes (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    kind          VARCHAR(10)       NOT NULL DEFAULT 'verse',
    title         VARCHAR(160)      NULL DEFAULT NULL,
    body          MEDIUMTEXT        NULL,
    translation   VARCHAR(10)       NULL DEFAULT NULL,
    book          TINYINT UNSIGNED  NULL DEFAULT NULL,
    chapter       SMALLINT UNSIGNED NULL DEFAULT NULL,
    verse_start   SMALLINT UNSIGNED NULL DEFAULT NULL,
    offset_start  SMALLINT UNSIGNED NULL DEFAULT NULL,
    verse_end     SMALLINT UNSIGNED NULL DEFAULT NULL,
    offset_end    SMALLINT UNSIGNED NULL DEFAULT NULL,
    quote         VARCHAR(1000)     NULL DEFAULT NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_bible_note_chapter (user_id, book, chapter),
    KEY idx_bible_note_recent (user_id, kind, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Passages a journal entry links to. user_id is repeated so "which of my
-- entries mention this chapter?" never needs a join.
CREATE TABLE IF NOT EXISTS bible_note_refs (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    note_id       INT UNSIGNED      NOT NULL,
    user_id       INT UNSIGNED      NOT NULL,
    book          TINYINT UNSIGNED  NOT NULL,
    chapter       SMALLINT UNSIGNED NOT NULL,
    verse_start   SMALLINT UNSIGNED NULL DEFAULT NULL,
    chapter_end   SMALLINT UNSIGNED NOT NULL,
    verse_end     SMALLINT UNSIGNED NULL DEFAULT NULL,
    sort_order    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_bible_ref_note (note_id),
    KEY idx_bible_ref_chapter (user_id, book, chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each member's own tags ("faith", "prayer", "family").
CREATE TABLE IF NOT EXISTS bible_tags (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    name          VARCHAR(40)       NOT NULL,
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bible_tag (user_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bible_note_tags (
    note_id       INT UNSIGNED      NOT NULL,
    tag_id        INT UNSIGNED      NOT NULL,
    PRIMARY KEY (note_id, tag_id),
    KEY idx_bible_note_tag (tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Licensed chapters fetched from API.Bible. Never served after 29 days:
-- bible_api_chapter() refetches, and deletes the row if it cannot.
-- fums_token is reported by the reader's browser every time the chapter is
-- shown (API.Bible's Fair Use Management System), cache hit or not.
CREATE TABLE IF NOT EXISTS bible_chapter_cache (
    translation   VARCHAR(10)       NOT NULL,
    book          TINYINT UNSIGNED  NOT NULL,
    chapter       SMALLINT UNSIGNED NOT NULL,
    api_bible_id  VARCHAR(64)       NOT NULL,
    payload       MEDIUMTEXT        NOT NULL,
    copyright     TEXT              NULL,
    fums_token    VARCHAR(512)      NULL DEFAULT NULL,
    fetched_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (translation, book, chapter),
    KEY idx_bible_cache_age (fetched_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API.Bible calls per calendar month. The free Starter plan allows about
-- 5,000; BIBLE_API_MONTHLY_CAP (default 4500) stops us short of it.
CREATE TABLE IF NOT EXISTS bible_api_usage (
    month         CHAR(7)           NOT NULL,
    calls         INT UNSIGNED      NOT NULL DEFAULT 0,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Module settings: the API.Bible catalogue we discovered for the key (which
-- Bible id is NIV, NKJV, AMP, with the copyright text we must display).
CREATE TABLE IF NOT EXISTS bible_config (
    name          VARCHAR(64)       NOT NULL,
    value         MEDIUMTEXT        NULL,
    updated_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per chapter a member finished on a given day. Streaks are the run
-- of consecutive days with at least one row; plans tick themselves off from
-- it. The unique key makes "mark as read" safe to send twice.
CREATE TABLE IF NOT EXISTS bible_reading_log (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    read_on       DATE              NOT NULL,
    book          TINYINT UNSIGNED  NOT NULL,
    chapter       SMALLINT UNSIGNED NOT NULL,
    translation   VARCHAR(10)       NOT NULL DEFAULT 'KJV',
    source        VARCHAR(10)       NOT NULL DEFAULT 'read',
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_bible_read (user_id, read_on, book, chapter),
    KEY idx_bible_read_chapter (user_id, book, chapter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reading plans a member has started. The plans themselves are defined in
-- code (includes/bible/plans.php); only the member's progress lives here.
CREATE TABLE IF NOT EXISTS bible_plan_enrollments (
    id            INT UNSIGNED      NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED      NOT NULL,
    plan_key      VARCHAR(40)       NOT NULL,
    start_date    DATE              NOT NULL,
    status        VARCHAR(10)       NOT NULL DEFAULT 'active',
    created_at    DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at  DATETIME          NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_bible_plan_user (user_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bible_plan_progress (
    enrollment_id INT UNSIGNED      NOT NULL,
    day_no        SMALLINT UNSIGNED NOT NULL,
    completed_at  DATETIME          NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (enrollment_id, day_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
