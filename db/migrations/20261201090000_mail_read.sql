-- /db/migrations/20261201090000_mail_read.sql
--
-- Mail (PR 2 of 4, reading): the senders whose pictures a person always shows.
-- Docs: docs/MAIL.md §7c.
--
-- Pictures from the internet stay hidden in the reader until the person chooses
-- "Show pictures" (once) or "Always show" (that sender, for that person only):
-- a remote picture tells the sender the message was opened. One row per person
-- and sender address (lower case). Nothing else about reading is stored here:
-- read and flagged states live on mail_messages (PR 1) and on the server.
--
-- Forward-only and idempotent: safe to run twice.

CREATE TABLE IF NOT EXISTS mail_image_trust (
    user_id     INT NOT NULL,
    sender      VARCHAR(190) NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, sender)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
