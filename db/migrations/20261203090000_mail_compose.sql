-- /db/migrations/20261203090000_mail_compose.sql
--
-- Mail (PR 3 of 4, writing and signatures). Docs: docs/MAIL.md §7d.
-- mail_outbox itself is PR 1's (20261128090000_mail_core); two tables of PR 3's own:
--
--   mail_signatures     a person's own phone numbers for their signature, the only
--                       part of the church's design a person edits. No row: the
--                       number on their profile (users.phone) is used.
--   mail_compose_files  files attached to a message being written, kept in the
--                       database (uploads/ is reachable from the web; mail must
--                       not be) in chunks of at most 512 KB, so no single row
--                       nears max_allowed_packet. They go with their outbox row
--                       (foreign key) and once the message has left.
--
-- Person columns are INT users.id without foreign keys, as in mail_core.
-- Forward-only and idempotent: CREATE TABLE IF NOT EXISTS only.

CREATE TABLE IF NOT EXISTS mail_signatures (
    user_id     INT NOT NULL PRIMARY KEY,
    phone       VARCHAR(30) NULL,
    mobile      VARCHAR(30) NULL,
    created_at  DATETIME NULL,
    updated_at  DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_compose_files (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    outbox_id   CHAR(36) NOT NULL,
    file_key    VARCHAR(24) NOT NULL,
    chunk_no    SMALLINT UNSIGNED NOT NULL,
    content     MEDIUMBLOB NOT NULL,
    created_at  DATETIME NULL,
    UNIQUE KEY uq_mail_compose_files_chunk (outbox_id, file_key, chunk_no),
    CONSTRAINT fk_mail_compose_files_outbox FOREIGN KEY (outbox_id) REFERENCES mail_outbox (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
