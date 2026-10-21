-- 20261123090000_assimilation_sms_notes.sql
-- A system note for every SMS sent to a person from an Assimilation list in
-- SMS Studio, so whoever calls them next knows what they were last told.
--
-- Keyed by the person (users.id), not by a follow-up case: most people on an
-- Assimilation list have no open case when they are texted, and the note must
-- still be there when one is opened later. The SMS queue worker writes a row
-- once BulkSMS accepts the message (includes/sms_functions.php,
-- sms_assim_note()) and keeps only each person's two latest rows.
--
-- body is the message exactly as it went out, merge fields filled in.
-- sms_log_id links back to History for the live delivery status. No foreign
-- keys, like user_status_history: a note must never stop a users or sms_log
-- row being removed.
--
-- Plain CREATE TABLE IF NOT EXISTS so the file is safe to apply twice.

CREATE TABLE IF NOT EXISTS assimilation_sms_notes (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    sms_log_id  INT UNSIGNED NULL,
    campaign_id INT UNSIGNED NULL,
    body        TEXT NOT NULL,
    sent_by     INT UNSIGNED NULL,
    sent_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_assim_sms_notes_user (user_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
