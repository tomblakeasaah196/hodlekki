-- /db/migrations/20261215090000_congregation_contact_log.sql
--
-- Congregation: reaching a person from their details (owner, 13 Nov 2026).
-- One row each time someone calls, opens WhatsApp, texts or emails a person
-- from the Congregation page, so pastors can see who reached them and when.
--
--   person_id   the person reached (users.id)
--   channel     call | whatsapp | sms | email
--   by_user_id  who reached them (users.id)
--   ref         sms: sms_log.id; email: mail_outbox.id; call / WhatsApp: NULL.
--               UNIQUE (channel, ref), so one email or text is logged once
--               (NULLs never collide).
--
-- Only the fact of contact is kept: never the message (SMS Studio's History
-- and the mailbox's Sent folder keep their own). Written and read only by
-- includes/congregation_contact.php.
--
-- No foreign keys, as elsewhere. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS congregation_contacts (
    id INT NOT NULL AUTO_INCREMENT,
    person_id INT NOT NULL,
    channel VARCHAR(16) NOT NULL,
    by_user_id INT NOT NULL,
    ref VARCHAR(64) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_congregation_contacts_ref (channel, ref),
    KEY ix_congregation_contacts_person (person_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
