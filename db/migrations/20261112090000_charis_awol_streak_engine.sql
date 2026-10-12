-- 20261112090000_charis_awol_streak_engine.sql
-- Rebuilds AWOL Monitoring around a consecutive-absence streak.
--
-- The previous engine counted total misses inside a rolling window, so an
-- absence BEFORE a member's last attendance still counted against them: a
-- member present on 4 Oct could still read "3 MISSED". The streak engine
-- counts only unbroken absences since the member was last in the house, and
-- any presence resets it to zero.
--
-- Forward-only. Legacy columns (services_missed, missed_threshold,
-- period_weeks, service_types) are LEFT IN PLACE so a half-deployed tree
-- keeps reading rows it understands.

-- Consecutive Sunday absences required before a member is flagged.
-- Seeded from the existing missed_threshold so the live rule (3) carries over.
ALTER TABLE `charis_awol_config`
    ADD COLUMN `consecutive_threshold` INT UNSIGNED NOT NULL DEFAULT 3 AFTER `missed_threshold`;

-- Unbroken weeks away before a member leaves the active list for Dormant.
-- Deliberately NOT seeded from period_weeks: that column means "rolling focus
-- window" (live value 3), and reusing it would archive everyone after three
-- weeks. 12 weeks is the new, independent default.
ALTER TABLE `charis_awol_config`
    ADD COLUMN `archive_weeks` INT UNSIGNED NOT NULL DEFAULT 12 AFTER `period_weeks`;

-- Per-service behaviour: {"Sunday_Service":{"counts_absence":true,"resets_clock":true},
--                         "Midweek_Service":{"counts_absence":false,"resets_clock":true}}
-- Midweek absence must never flag anyone (members travel far and miss midweek
-- for work), but midweek presence still proves they are engaged and resets.
ALTER TABLE `charis_awol_config`
    ADD COLUMN `service_rules` JSON NULL AFTER `service_types`;

ALTER TABLE `charis_awol_config_history`
    ADD COLUMN `consecutive_threshold` INT UNSIGNED NOT NULL DEFAULT 3 AFTER `missed_threshold`;
ALTER TABLE `charis_awol_config_history`
    ADD COLUMN `archive_weeks` INT UNSIGNED NOT NULL DEFAULT 12 AFTER `period_weeks`;
ALTER TABLE `charis_awol_config_history`
    ADD COLUMN `service_rules` JSON NULL AFTER `service_types`;

-- Carry the live threshold across so the rule does not silently change on deploy.
UPDATE `charis_awol_config`
   SET `consecutive_threshold` = GREATEST(1, `missed_threshold`)
 WHERE `id` = 1;

-- Default asymmetry for any row that predates service_rules.
UPDATE `charis_awol_config`
   SET `service_rules` = '{"Sunday_Service":{"counts_absence":true,"resets_clock":true},"Midweek_Service":{"counts_absence":false,"resets_clock":true}}'
 WHERE `id` = 1 AND `service_rules` IS NULL;

-- Sundays that did not hold. A Sunday with zero attendance church-wide is
-- treated as not held and SKIPPED (it never increments a streak, and never
-- resets one). Rows are written by the engine so a broken check-in scanner
-- stays visible instead of silently pausing everybody's counter.
CREATE TABLE IF NOT EXISTS `charis_awol_skipped_services` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `service_date` DATE NOT NULL,
    `event_category` VARCHAR(64) NOT NULL,
    `reason` VARCHAR(64) NOT NULL DEFAULT 'zero_attendance',
    `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_charis_skipped_service` (`service_date`, `event_category`),
    KEY `idx_charis_skipped_detected` (`detected_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Auto-resolution audit. When IDI marks someone present, their AWOL case and
-- any manual flag close themselves and the system writes the archive note --
-- no worker action, full trace.
CREATE TABLE IF NOT EXISTS `charis_awol_auto_resolutions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `target_user_id` BIGINT UNSIGNED NOT NULL,
    `assignment_id` BIGINT UNSIGNED NULL,
    `followup_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `case_type` VARCHAR(32) NOT NULL DEFAULT 'AWOL',
    `attended_on` DATE NOT NULL,
    `event_category` VARCHAR(64) NULL,
    `streak_at_resolution` INT UNSIGNED NOT NULL DEFAULT 0,
    `note_id` BIGINT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_charis_auto_resolution` (`target_user_id`, `followup_id`, `attended_on`),
    KEY `idx_charis_auto_res_user` (`target_user_id`),
    KEY `idx_charis_auto_res_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- System-generated archive notes have no human author. Attributing one to the
-- assigned worker would be a lie in the audit trail, so author_id becomes
-- nullable and is_system marks the rows the engine wrote itself.
ALTER TABLE `charis_welfare_notes`
    MODIFY COLUMN `author_id` BIGINT UNSIGNED NULL;

ALTER TABLE `charis_welfare_notes`
    ADD COLUMN `is_system` TINYINT(1) NOT NULL DEFAULT 0 AFTER `visible_to`;
