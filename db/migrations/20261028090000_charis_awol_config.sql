-- 20261028090000_charis_awol_config.sql
-- Guarded singleton configuration table and audit history for Charis AWOL Monitoring.

CREATE TABLE IF NOT EXISTS `charis_awol_config` (
    `id` INT UNSIGNED NOT NULL DEFAULT 1,
    `services_missed` INT UNSIGNED NOT NULL DEFAULT 2,
    `missed_threshold` INT UNSIGNED NOT NULL DEFAULT 2,
    `period_weeks` INT UNSIGNED NOT NULL DEFAULT 5,
    `service_types` JSON NOT NULL,
    `spiritual_statuses` JSON NOT NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `chk_charis_awol_config_singleton` CHECK (`id` = 1),
    KEY `idx_charis_awol_config_updated` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `charis_awol_config_history` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `config_id` INT UNSIGNED NOT NULL DEFAULT 1,
    `services_missed` INT UNSIGNED NOT NULL,
    `missed_threshold` INT UNSIGNED NOT NULL,
    `period_weeks` INT UNSIGNED NOT NULL,
    `service_types` JSON NOT NULL,
    `spiritual_statuses` JSON NOT NULL,
    `updated_by` BIGINT UNSIGNED NULL,
    `change_reason` VARCHAR(255) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_charis_awol_history_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
