-- /db/migrations/20261205090000_mail_team_alerts.sql
--
-- Mail (PR 4 of 4: teamwork, alerts and AI). Docs: docs/MAIL.md §7e.
-- Assignment, status, presence and activity use PR 1's columns and tables (mail_threads, mail_presence,
-- mail_thread_activity); AI summaries use mail_threads.ai_summary. Two tables of PR 4's own:
--
--   mail_user_prefs   a person's alert choices. account_id 0: their general choices (daily summary, which key
--                     alerts go to their confirmed personal email); a mailbox's id: push for new mail in that
--                     mailbox ('all', 'mine' = assigned to me, 'off'). No row: the defaults (push 'all').
--   mail_alert_log    key alerts sent to a personal email, for the limits: one per conversation per hour,
--                     20 a day per person.
--
-- Person columns are INT users.id without foreign keys, as in mail_core.
-- Forward-only and idempotent: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS mail_user_prefs (
    user_id     INT NOT NULL,
    account_id  INT UNSIGNED NOT NULL DEFAULT 0,
    push        ENUM('all','mine','off') NULL,
    data        JSON NULL,
    updated_at  DATETIME NULL,
    PRIMARY KEY (user_id, account_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_alert_log (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NOT NULL,
    kind        VARCHAR(20) NOT NULL,
    thread_id   INT UNSIGNED NULL,
    sent_at     DATETIME NOT NULL,
    KEY idx_mail_alert_user (user_id, sent_at),
    KEY idx_mail_alert_thread (user_id, thread_id, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
