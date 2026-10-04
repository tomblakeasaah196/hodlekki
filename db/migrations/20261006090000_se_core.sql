-- 20261006090000_se_core.sql
-- Special Events core: module settings, series, events, slugs and event days.
-- Standalone module (decision D2): nothing here references events/checkins/attendance.

CREATE TABLE IF NOT EXISTS se_settings (
    setting_key    VARCHAR(60)  NOT NULL,
    setting_value  MEDIUMTEXT   NULL,
    updated_by     INT UNSIGNED NULL,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_series (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    description  TEXT         NULL,
    created_by   INT UNSIGNED NULL,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    archived_at  DATETIME     NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_events (
    id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id                 CHAR(12)     NOT NULL,
    preview_key               CHAR(22)     NOT NULL,
    series_id                 INT UNSIGNED NULL,
    cloned_from_event_id      INT UNSIGNED NULL,
    slug                      VARCHAR(40)  NOT NULL,
    title                     VARCHAR(120) NOT NULL,
    edition_label             VARCHAR(40)  NULL,
    tagline                   VARCHAR(160) NULL,
    description_md            MEDIUMTEXT   NULL,
    organizer_label           VARCHAR(80)  NOT NULL DEFAULT 'Envision',
    venue_name                VARCHAR(160) NULL,
    venue_address             VARCHAR(255) NULL,
    venue_map_url             VARCHAR(500) NULL,
    venue_notes               VARCHAR(255) NULL,
    starts_at                 DATETIME     NOT NULL,
    ends_at                   DATETIME     NOT NULL,
    status                    ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft',
    cancel_reason             VARCHAR(255) NULL,
    visibility                ENUM('public','unlisted') NOT NULL DEFAULT 'unlisted',
    theme_preset              VARCHAR(30)  NOT NULL DEFAULT 'marquee',
    brand_primary             CHAR(7)      NOT NULL DEFAULT '#1D356A',
    brand_secondary           CHAR(7)      NOT NULL DEFAULT '#D11920',
    brand_accent              CHAR(7)      NULL,
    palette_json              TEXT         NULL,
    font_display              VARCHAR(60)  NOT NULL DEFAULT 'Unbounded',
    font_body                 VARCHAR(60)  NOT NULL DEFAULT 'Inter',
    logo_asset_id             INT UNSIGNED NULL,
    hero_asset_id             INT UNSIGNED NULL,
    hero_video_asset_id       INT UNSIGNED NULL,
    og_asset_id               INT UNSIGNED NULL,
    reg_opens_at              DATETIME     NULL,
    reg_closes_at             DATETIME     NULL,
    reg_override              ENUM('none','force_open','force_closed') NOT NULL DEFAULT 'none',
    reg_override_note         VARCHAR(255) NULL,
    reg_override_by           INT UNSIGNED NULL,
    reg_override_at           DATETIME     NULL,
    online_capacity           INT UNSIGNED NULL,
    auto_close_at_capacity    TINYINT(1)   NOT NULL DEFAULT 1,
    waitlist_enabled          TINYINT(1)   NOT NULL DEFAULT 0,
    waitlist_capacity         INT UNSIGNED NULL,
    waitlist_promotion        ENUM('auto_confirm','manual') NOT NULL DEFAULT 'auto_confirm',
    waitlist_notify_sms       TINYINT(1)   NOT NULL DEFAULT 1,
    self_cancel_enabled       TINYINT(1)   NOT NULL DEFAULT 0,
    walkin_enabled            TINYINT(1)   NOT NULL DEFAULT 1,
    walkin_capacity           INT UNSIGNED NULL,
    walkin_hard_cap           TINYINT(1)   NOT NULL DEFAULT 0,
    seats_left_mode           ENUM('never','threshold','always') NOT NULL DEFAULT 'never',
    seats_left_threshold_pct  TINYINT UNSIGNED NOT NULL DEFAULT 70,
    team_rr_pointer           TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_player_no            INT UNSIGNED NOT NULL DEFAULT 1,
    next_karaoke_no           INT UNSIGNED NOT NULL DEFAULT 1,
    settings_json             MEDIUMTEXT   NULL,
    ticketing_enabled         TINYINT(1)   NOT NULL DEFAULT 0,
    row_version               INT UNSIGNED NOT NULL DEFAULT 1,
    created_by                INT UNSIGNED NULL,
    updated_by                INT UNSIGNED NULL,
    created_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    published_at              DATETIME     NULL,
    cancelled_at              DATETIME     NULL,
    archived_at               DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_events_public_id (public_id),
    UNIQUE KEY uniq_se_events_slug (slug),
    KEY idx_se_events_status_start (status, starts_at),
    KEY idx_se_events_series (series_id),
    CONSTRAINT fk_se_events_series FOREIGN KEY (series_id) REFERENCES se_series (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_slugs (
    slug          VARCHAR(40)  NOT NULL,
    event_id      INT UNSIGNED NOT NULL,
    is_canonical  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (slug),
    KEY idx_se_slugs_event (event_id),
    CONSTRAINT fk_se_slugs_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_event_days (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    day_date           DATE         NOT NULL,
    label              VARCHAR(60)  NULL,
    doors_open_at      DATETIME     NOT NULL,
    starts_at          DATETIME     NOT NULL,
    ends_at            DATETIME     NOT NULL,
    checkin_closes_at  DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_event_days (event_id, day_date),
    CONSTRAINT fk_se_event_days_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
