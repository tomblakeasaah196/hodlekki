-- /db/migrations/20261210100000_trainings_live.sql
-- Trainings, PR 3 (docs/INTEGRATIONS.md, "Live classes"): the Google Meet of a live item, who pressed Join class,
-- who attended, and the Meet participants the tutor still has to look at. Every table is keyed on
-- training_items.id (PR 1's core); no foreign keys, as in the core, so a removed item never blocks anything.
-- Idempotent: safe to apply twice.

-- One Meet per live item: the Calendar event made on the connected Google account, and what was sent (so a
-- change to the item's title or time is noticed and sent again).
CREATE TABLE IF NOT EXISTS training_meetings (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    training_id INT NOT NULL,
    provider VARCHAR(12) NOT NULL DEFAULT 'google',
    status VARCHAR(10) NOT NULL DEFAULT 'active',
    event_id VARCHAR(255) NULL DEFAULT NULL,
    meet_url VARCHAR(255) NULL DEFAULT NULL,
    meet_code VARCHAR(40) NULL DEFAULT NULL,
    html_link VARCHAR(500) NULL DEFAULT NULL,
    title_sent VARCHAR(255) NULL DEFAULT NULL,
    starts_at DATETIME NULL DEFAULT NULL,
    ends_at DATETIME NULL DEFAULT NULL,
    conference_record LONGTEXT NULL,
    synced_at DATETIME NULL DEFAULT NULL,
    last_error VARCHAR(255) NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_meetings_item (item_id),
    KEY ix_training_meetings_training (training_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Each press of Join class: a certain identity and time, which the attendance match leans on.
CREATE TABLE IF NOT EXISTS training_join_log (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    joined_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_training_join_log_item (item_id, joined_at),
    KEY ix_training_join_log_user (user_id, joined_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who attended a live item. source: join | meet (matched automatically) | manual (the tutor decided; a later
-- sync never changes it). present: at least the item's present_minutes. participants: the Meet ids matched.
CREATE TABLE IF NOT EXISTS training_attendance (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    user_id INT NOT NULL,
    source VARCHAR(8) NOT NULL,
    minutes INT NULL DEFAULT NULL,
    present TINYINT(1) NOT NULL DEFAULT 0,
    confidence DECIMAL(4,3) NULL DEFAULT NULL,
    participants LONGTEXT NULL,
    resolved_by INT NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_attendance (item_id, user_id),
    KEY ix_training_attendance_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Needs a look": a Meet participant the match could not place, one row per name. status: open | assigned
-- (the tutor chose the learner) | ignored (a guest, the tutor's own device). suggestions: the best guesses.
CREATE TABLE IF NOT EXISTS training_meet_participants (
    id INT NOT NULL AUTO_INCREMENT,
    item_id INT NOT NULL,
    name_key VARCHAR(190) NOT NULL,
    display_name VARCHAR(190) NOT NULL,
    minutes INT NOT NULL DEFAULT 0,
    suggestions LONGTEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'open',
    user_id INT NULL DEFAULT NULL,
    resolved_by INT NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_training_meet_participants (item_id, name_key),
    KEY ix_training_meet_participants_open (item_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
