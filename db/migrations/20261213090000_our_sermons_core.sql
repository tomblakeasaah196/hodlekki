-- /db/migrations/20261213090000_our_sermons_core.sql
--
-- Our sermons (owner, 13 Nov 2026; OUR-SERMONS-PLAN.md, PR S1): the members'
-- sermon library, separate from the public Word Library (/sermons and its
-- `sermons` table stay as they are). Envision manages it: new episodes of the
-- Lekki show arrive from its RSS feed as drafts, or Envision pastes a Spotify
-- or YouTube link; members browse by list or calendar once a sermon is
-- published. The two tables are a shared contract: S2 (the listening page)
-- adds our_sermon_notes / our_sermon_progress keyed on our_sermons.id, S3 (AI)
-- writes summary / key_points / scriptures / series / ai_status / ai_drafted_at.
--
--   our_sermons       one sermon. date_preached is the day it was PREACHED
--                     (parsed from the episode title, or typed), never the day
--                     Spotify published it (feed_published_at keeps that).
--                     date_source: title | guess | manual (a guessed date is
--                     flagged on the draft). service: a key of OUR_SERMONS_SERVICES.
--                     key_points / scriptures: JSON arrays of strings.
--                     source: feed | paste. status: draft | published | hidden.
--                     ai_status: pending | done | failed | off.
--                     notified_at: set once, when publishing first told
--                     everyone, so a sermon is never announced twice.
--                     artwork_url: our own copy under /uploads/sermons/artwork/;
--                     artwork_source: where it was copied from (reused).
--   our_sermon_feeds  the RSS feeds Envision pasted, polled every 15 minutes
--                     (conditional GET with etag / last_modified).
--   our_sermon_feed_skips  feed episodes Envision deleted, so the poller does
--                     not bring them back as drafts (Paste link still can).
--
-- The UNIQUE keys are the dedupe: a feed episode (feed_guid), a Spotify
-- episode and a YouTube video each belong to one sermon at most (NULLs repeat
-- freely). No foreign keys, as elsewhere. Times come from PHP (Lagos).
-- Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS our_sermons (
    id INT NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    preacher VARCHAR(120) NULL,
    date_preached DATE NOT NULL,
    date_source VARCHAR(10) NOT NULL DEFAULT 'manual',
    service VARCHAR(20) NULL,
    series VARCHAR(120) NULL,
    summary TEXT NULL,
    key_points JSON NULL,
    scriptures JSON NULL,
    artwork_url VARCHAR(255) NULL,
    artwork_source VARCHAR(500) NULL,
    audio_url VARCHAR(1000) NULL,
    duration_seconds INT NULL,
    spotify_url VARCHAR(255) NULL,
    spotify_episode_id VARCHAR(32) NULL,
    youtube_url VARCHAR(255) NULL,
    youtube_id VARCHAR(16) NULL,
    source VARCHAR(10) NOT NULL DEFAULT 'paste',
    feed_id INT NULL,
    feed_guid VARCHAR(255) NULL,
    feed_title VARCHAR(255) NULL,
    feed_published_at DATETIME NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'draft',
    ai_status VARCHAR(10) NOT NULL DEFAULT 'off',
    ai_drafted_at DATETIME NULL,
    published_at DATETIME NULL,
    published_by INT NULL,
    notified_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    updated_by INT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_our_sermons_guid (feed_guid),
    UNIQUE KEY uq_our_sermons_spotify (spotify_episode_id),
    UNIQUE KEY uq_our_sermons_youtube (youtube_id),
    KEY ix_our_sermons_status_date (status, date_preached),
    KEY ix_our_sermons_preacher (preacher),
    KEY ix_our_sermons_series (series)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS our_sermon_feeds (
    id INT NOT NULL AUTO_INCREMENT,
    url VARCHAR(500) NOT NULL,
    title VARCHAR(255) NULL,
    is_active TINYINT NOT NULL DEFAULT 1,
    import_from DATE NULL,
    last_checked_at DATETIME NULL,
    last_ok_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    etag VARCHAR(255) NULL,
    last_modified VARCHAR(64) NULL,
    items_seen INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_our_sermon_feeds_url (url),
    KEY ix_our_sermon_feeds_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS our_sermon_feed_skips (
    id INT NOT NULL AUTO_INCREMENT,
    feed_guid VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    created_by INT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_our_sermon_feed_skips_guid (feed_guid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
