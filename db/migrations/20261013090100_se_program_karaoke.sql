-- 20261013090100_se_program_karaoke.sql
-- Run-of-show items, global song library, event song lists, karaoke entries.

CREATE TABLE IF NOT EXISTS se_program_items (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id          INT UNSIGNED NOT NULL,
    day_id            INT UNSIGNED NOT NULL,
    sort_order        INT          NOT NULL,
    kind              VARCHAR(20)  NOT NULL DEFAULT 'other',
    title             VARCHAR(120) NOT NULL,
    public_blurb      VARCHAR(400) NULL,
    icon              VARCHAR(40)  NULL,
    host_name         VARCHAR(120) NULL,
    planned_start_at  DATETIME     NULL,
    duration_min      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    is_public         TINYINT(1)   NOT NULL DEFAULT 1,
    is_featured       TINYINT(1)   NOT NULL DEFAULT 0,
    game_id           INT UNSIGNED NULL,
    media_asset_id    INT UNSIGNED NULL,
    crew_notes        TEXT         NULL,
    source            ENUM('manual','ai') NOT NULL DEFAULT 'manual',
    status            ENUM('planned','live','done','skipped') NOT NULL DEFAULT 'planned',
    started_at        DATETIME     NULL,
    ended_at          DATETIME     NULL,
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_program_order (event_id, day_id, sort_order),
    CONSTRAINT fk_se_program_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_program_day FOREIGN KEY (day_id) REFERENCES se_event_days (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_songs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(160) NOT NULL,
    artist        VARCHAR(160) NOT NULL DEFAULT '',
    duration_sec  SMALLINT UNSIGNED NULL,
    title_norm    VARCHAR(160) NOT NULL,
    artist_norm   VARCHAR(160) NOT NULL DEFAULT '',
    tags          VARCHAR(160) NULL,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_songs_norm (title_norm, artist_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_songs (
    event_id    INT UNSIGNED NOT NULL,
    song_id     INT UNSIGNED NOT NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    added_by    INT UNSIGNED NULL,
    added_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, song_id),
    KEY idx_se_event_songs_song (song_id),
    CONSTRAINT fk_se_event_songs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_event_songs_song FOREIGN KEY (song_id) REFERENCES se_songs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_karaoke_entries (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    registration_id    INT UNSIGNED NOT NULL,
    song_id            INT UNSIGNED NOT NULL,
    status             ENUM('held','queued','up_next','on_stage','done','skipped','no_show','released','cancelled') NOT NULL,
    source             ENUM('prepick','checkin','portal','desk','dj') NOT NULL,
    enforce_unique     TINYINT(1)   NOT NULL DEFAULT 1,
    is_test            TINYINT(1)   NOT NULL DEFAULT 0,
    queue_no           INT UNSIGNED NULL,
    position           INT          NULL,
    held_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    queued_at          DATETIME     NULL,
    on_stage_at        DATETIME     NULL,
    finished_at        DATETIME     NULL,
    updated_by         INT UNSIGNED NULL,
    active_song_key    INT UNSIGNED GENERATED ALWAYS AS (IF(enforce_unique = 1 AND status IN ('held','queued','up_next','on_stage','done'), song_id, NULL)) STORED,
    active_singer_key  INT UNSIGNED GENERATED ALWAYS AS (IF(status IN ('held','queued','up_next','on_stage'), registration_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_karaoke_song (event_id, active_song_key),
    UNIQUE KEY uniq_se_karaoke_singer (event_id, active_singer_key),
    UNIQUE KEY uniq_se_karaoke_no (event_id, queue_no),
    KEY idx_se_karaoke_queue (event_id, status, position),
    CONSTRAINT fk_se_karaoke_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_karaoke_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_se_karaoke_song FOREIGN KEY (song_id) REFERENCES se_songs (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
