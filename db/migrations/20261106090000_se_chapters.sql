-- 20261106090000_se_chapters.sql
-- Portal chapters (guide §13.3 S3) become editable content instead of
-- hard-coded PHP: one row per chapter per event, ordered, with an icon from
-- the catalogue and an optional background image from the event's asset kit.
--
-- Existing events are seeded with the four chapters they were already being
-- shown (Mic, Teams, Games, Night) so nothing changes on a live portal until
-- somebody edits it. New events are seeded lazily by se_chapters_list().

CREATE TABLE IF NOT EXISTS se_chapters (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED NOT NULL,
    chapter_key   VARCHAR(40)  NOT NULL,
    title         VARCHAR(120) NOT NULL,
    blurb         VARCHAR(400) NOT NULL DEFAULT '',
    icon          VARCHAR(30)  NOT NULL DEFAULT 'spark',
    bg_asset_id   INT UNSIGNED NULL,
    feature       VARCHAR(20)  NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_chapters_key (event_id, chapter_key),
    KEY idx_se_chapters_order (event_id, sort_order, id),
    CONSTRAINT fk_se_chapters_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_chapters_asset FOREIGN KEY (bg_asset_id) REFERENCES se_assets (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed every existing event, in the order the producers asked for: the mic,
-- then the teams, then the games, then the night. INSERT IGNORE leans on
-- UNIQUE(event_id, chapter_key), so re-running this file after a half-applied
-- deploy cannot duplicate a chapter.
INSERT IGNORE INTO se_chapters (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active)
SELECT e.id, 'mic', 'The Mic',
       'Tick karaoke when you register — then pick your song before the night, so the queue is ready when you are.',
       'mic', 'karaoke', 10, 1
FROM se_events e;

INSERT IGNORE INTO se_chapters (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active)
SELECT e.id, 'teams', 'The Teams',
       'At check-in you''ll join a colour team — balanced, friendly, and very competitive by round two.',
       'orbit', 'teams', 20, 1
FROM se_events e;

INSERT IGNORE INTO se_chapters (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active)
SELECT e.id, 'games', 'The Games',
       'Charades, Live Quiz, Trivia, Buzzer and Family Feud — played on your phone, scored on the big screen.',
       'cards', 'games', 30, 1
FROM se_events e;

INSERT IGNORE INTO se_chapters (event_id, chapter_key, title, blurb, icon, feature, sort_order, is_active)
SELECT e.id, 'night', 'The Night',
       'Doors, welcome, games, karaoke, awards. Come as you are and bring someone with you.',
       'timeline', NULL, 40, 1
FROM se_events e;
