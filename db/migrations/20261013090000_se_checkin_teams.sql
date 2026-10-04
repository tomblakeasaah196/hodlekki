-- 20261013090000_se_checkin_teams.sql
-- Teams, check-ins, team move history, welcome verses, Bible lookup cache.

CREATE TABLE IF NOT EXISTS se_teams (
    id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                 INT UNSIGNED NOT NULL,
    sort_order               TINYINT UNSIGNED NOT NULL,
    color_hex                CHAR(7)      NOT NULL,
    color_label              VARCHAR(30)  NOT NULL,
    name                     VARCHAR(40)  NULL,
    name_set_at              DATETIME     NULL,
    name_set_by              INT UNSIGNED NULL,
    captain_registration_id  INT UNSIGNED NULL,
    team_key                 CHAR(22)     NOT NULL,
    created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_teams_order (event_id, sort_order),
    UNIQUE KEY uniq_se_teams_key (team_key),
    CONSTRAINT fk_se_teams_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_checkins (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    day_date         DATE         NOT NULL,
    method           ENUM('self','desk','studio') NOT NULL,
    is_walkin        TINYINT(1)   NOT NULL DEFAULT 0,
    is_test          TINYINT(1)   NOT NULL DEFAULT 0,
    device_id        INT UNSIGNED NULL,
    checked_in_by    INT UNSIGNED NULL,
    verse_id         INT UNSIGNED NULL,
    checked_in_at    DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_checkins_day (event_id, registration_id, day_date),
    KEY idx_se_checkins_time (event_id, checked_in_at),
    CONSTRAINT fk_se_checkins_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_checkins_reg FOREIGN KEY (registration_id) REFERENCES se_registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_team_moves (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    from_team_id     INT UNSIGNED NULL,
    to_team_id       INT UNSIGNED NOT NULL,
    method           ENUM('auto','crew') NOT NULL,
    reason           VARCHAR(160) NULL,
    moved_by         INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_team_moves_reg (event_id, registration_id),
    CONSTRAINT fk_se_team_moves_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_verses (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    translation      VARCHAR(10)  NOT NULL DEFAULT 'KJV',
    ref_display      VARCHAR(60)  NOT NULL,
    text             TEXT         NOT NULL,
    text_source      ENUM('lookup','manual') NOT NULL DEFAULT 'lookup',
    prayer_template  VARCHAR(300) NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    approved_by      INT UNSIGNED NULL,
    approved_at      DATETIME     NULL,
    second_approved_by INT UNSIGNED NULL,
    times_used       INT UNSIGNED NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_verses_event (event_id, is_active),
    CONSTRAINT fk_se_verses_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_bible_cache (
    translation  VARCHAR(10)  NOT NULL,
    ref_norm     VARCHAR(60)  NOT NULL,
    ref_display  VARCHAR(60)  NOT NULL,
    text         TEXT         NOT NULL,
    fetched_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (translation, ref_norm)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
