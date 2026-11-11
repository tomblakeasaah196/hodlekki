-- /db/migrations/20261130090000_campaigns_throttle.sql
--
-- The public Connect card can ask us to email a confirmation link to any
-- address, so links started from it are throttled per requester (hashed IP)
-- and in total per hour, on top of the per-address limit in email_outbox.
-- One row per confirmation link actually queued from Connect. Rows older than
-- a day are deleted as new ones arrive. Guide: docs/EMAIL.md.

CREATE TABLE IF NOT EXISTS email_link_throttle (
    id INT NOT NULL AUTO_INCREMENT,
    kind VARCHAR(20) NOT NULL,
    key_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_email_link_throttle_kind (kind, created_at),
    KEY ix_email_link_throttle_key (kind, key_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
