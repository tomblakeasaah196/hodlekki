-- 20261110090000_se_portal_music.sql
-- Portal music (guide §13.3 S0b): the soft background playlist a guest hears
-- while they read the page and fill the registration sheet.
--
-- One row per track per event, ordered, capped at five by PHP
-- (SE_MUSIC_MAX). The audio itself is an ordinary se_assets row with role
-- 'music', so it goes through the same magic-byte detection, the same size
-- cap and the same uploads/se/.htaccess as every other file.
--
-- `rights_confirmed` is the point of the table as much as the playlist is.
-- Nothing plays on a public portal unless a named human ticked the box, and
-- the row remembers WHO ticked it and WHEN. The producer's own copy of the
-- claim sits beside it so a later reviewer can see what was asserted without
-- going to the audit log.

CREATE TABLE IF NOT EXISTS se_music (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id             INT UNSIGNED NOT NULL,
    asset_id             INT UNSIGNED NOT NULL,
    title                VARCHAR(120) NOT NULL DEFAULT '',
    artist               VARCHAR(120) NOT NULL DEFAULT '',
    bpm                  SMALLINT UNSIGNED NULL,
    sort_order           INT          NOT NULL DEFAULT 0,
    is_active            TINYINT(1)   NOT NULL DEFAULT 1,
    rights_confirmed     TINYINT(1)   NOT NULL DEFAULT 0,
    rights_confirmed_by  INT UNSIGNED NULL,
    rights_confirmed_at  DATETIME     NULL,
    created_by           INT UNSIGNED NULL,
    created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_music_asset (event_id, asset_id),
    KEY idx_se_music_order (event_id, sort_order, id),
    CONSTRAINT fk_se_music_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_music_asset FOREIGN KEY (asset_id) REFERENCES se_assets (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
