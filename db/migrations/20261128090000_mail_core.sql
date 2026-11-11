-- /db/migrations/20261128090000_mail_core.sql
--
-- Mail (PR 1 of 4): the church's mailboxes inside the ERP. Runbook and
-- contracts: docs/MAIL.md.
--
-- The mail itself stays on this server's mail service (Dovecot, mail.hodlekki.org).
-- These tables hold:
--   * the mailboxes the ERP made or brought in, with their passwords encrypted
--     by MAIL_VAULT_KEY (never shown to anyone: mail is read in the ERP only);
--   * who works from a shared mailbox (grants to matrix roles, department seats,
--     departments or people) and last night's resolved list, for audits;
--   * a copy of the last 12 months (headers and text, for fast lists and search);
--     older mail and every attachment are read from the server when opened;
--   * the queue of cPanel actions the cron carries out with the server's own
--     `uapi` (no API token lives in .env);
--   * export requests for a departed holder's mail (two approvals).
--
-- Removing a mailbox deletes its rows here (foreign keys cascade) after the job
-- has deleted it on the server. Jobs and export history keep the address in
-- their own columns, so the trail survives the mailbox.
--
-- Dates are DATETIME in Africa/Lagos, written from PHP. Person columns are
-- INT users.id without foreign keys (users predates the migrations folder).
-- Reserved words avoided: refs, to_list, setting_key.
--
-- Idempotent: CREATE TABLE IF NOT EXISTS only; applied twice in CI on MySQL 8.0
-- and MariaDB 10.11 (the `_mail_` marker in tests/special_events/db_setup.php).

CREATE TABLE IF NOT EXISTS mail_accounts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    kind              ENUM('department','church','personal') NOT NULL,
    email             VARCHAR(190) NOT NULL,
    local_part        VARCHAR(64)  NOT NULL,
    display_name      VARCHAR(120) NOT NULL,
    department_id     INT NULL,
    owner_user_id     INT NULL,
    managed           TINYINT(1) NOT NULL DEFAULT 1,
    is_pastoral       TINYINT(1) NOT NULL DEFAULT 0,
    ai_enabled        TINYINT(1) NOT NULL DEFAULT 0,
    secret            TEXT NULL,
    secret_pending    TEXT NULL,
    status            ENUM('pending','active','needs_reset','suspended','leaving','failed') NOT NULL DEFAULT 'pending',
    leaving_since     DATETIME NULL,
    remove_after      DATETIME NULL,
    disk_quota_mb     INT UNSIGNED NOT NULL DEFAULT 1024,
    disk_used_mb      INT UNSIGNED NULL,
    disk_checked_at   DATETIME NULL,
    disk_warned_at    DATETIME NULL,
    last_error        VARCHAR(500) NULL,
    capabilities      JSON NULL,
    folders_synced_at DATETIME NULL,
    last_synced_at    DATETIME NULL,
    change_stamp      INT UNSIGNED NOT NULL DEFAULT 0,
    created_by        INT NULL,
    suspended_by      INT NULL,
    suspended_at      DATETIME NULL,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uq_mail_accounts_email (email),
    KEY idx_mail_accounts_owner (kind, owner_user_id),
    KEY idx_mail_accounts_sync (status, last_synced_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who works from a shared mailbox. subject_type: role (matrix role id), seat
-- (department id + seat-type key), department (any active seat), user.
CREATE TABLE IF NOT EXISTS mail_grants (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id   INT UNSIGNED NOT NULL,
    subject_type ENUM('role','seat','department','user') NOT NULL,
    subject_id   INT NOT NULL,
    seat_type    VARCHAR(40) NOT NULL DEFAULT '',
    level        ENUM('read','send','assign','manage') NOT NULL,
    created_by   INT NULL,
    created_at   DATETIME NULL,
    UNIQUE KEY uq_mail_grants (account_id, subject_type, subject_id, seat_type),
    CONSTRAINT fk_mail_grants_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Last night's resolved readers: the "lost access -> new password" diff and audits.
CREATE TABLE IF NOT EXISTS mail_access_snapshot (
    account_id  INT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    level       VARCHAR(10) NOT NULL,
    seen_at     DATETIME NOT NULL,
    PRIMARY KEY (account_id, user_id),
    CONSTRAINT fk_mail_snapshot_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_folders (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id        INT UNSIGNED NOT NULL,
    path              VARCHAR(255) NOT NULL,
    name              VARCHAR(255) NOT NULL,
    delimiter         VARCHAR(4) NULL,
    role              ENUM('inbox','sent','drafts','archive','spam','trash','other') NOT NULL DEFAULT 'other',
    attributes        JSON NULL,
    selectable        TINYINT(1) NOT NULL DEFAULT 1,
    uidvalidity       BIGINT UNSIGNED NULL,
    uidnext           BIGINT UNSIGNED NULL,
    highestmodseq     BIGINT UNSIGNED NULL,
    unread_count      INT UNSIGNED NOT NULL DEFAULT 0,
    total_count       INT UNSIGNED NOT NULL DEFAULT 0,
    server_unseen     INT UNSIGNED NULL,
    server_total      INT UNSIGNED NULL,
    status_uidnext    BIGINT UNSIGNED NULL,
    full_pass_at      DATETIME NULL,
    initial_synced_at DATETIME NULL,
    synced_at         DATETIME NULL,
    created_at        DATETIME NULL,
    updated_at        DATETIME NULL,
    UNIQUE KEY uq_mail_folders_path (account_id, path),
    KEY idx_mail_folders_role (account_id, role),
    CONSTRAINT fk_mail_folders_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversations. Teamwork (PR 4) and the AI summary (PR 4) live here too.
CREATE TABLE IF NOT EXISTS mail_threads (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    subject          VARCHAR(500) NULL,
    subject_key      VARCHAR(191) NULL,
    snippet          VARCHAR(300) NULL,
    participants     JSON NULL,
    message_count    INT UNSIGNED NOT NULL DEFAULT 0,
    unread_count     INT UNSIGNED NOT NULL DEFAULT 0,
    has_attachments  TINYINT(1) NOT NULL DEFAULT 0,
    first_message_at DATETIME NULL,
    last_message_at  DATETIME NULL,
    last_inbound_at  DATETIME NULL,
    status           ENUM('open','waiting','done') NOT NULL DEFAULT 'open',
    status_by        INT NULL,
    status_at        DATETIME NULL,
    assigned_to      INT NULL,
    assigned_by      INT NULL,
    assigned_at      DATETIME NULL,
    ai_summary       JSON NULL,
    ai_summary_at    DATETIME NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    KEY idx_mail_threads_last (account_id, last_message_at),
    KEY idx_mail_threads_subject (account_id, subject_key),
    KEY idx_mail_threads_assigned (assigned_to),
    CONSTRAINT fk_mail_threads_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One message in one folder. body_html is stored as sent: never print it unsanitised.
CREATE TABLE IF NOT EXISTS mail_messages (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id       INT UNSIGNED NOT NULL,
    folder_id        INT UNSIGNED NOT NULL,
    thread_id        INT UNSIGNED NULL,
    uid              BIGINT UNSIGNED NOT NULL,
    message_id       VARCHAR(255) NULL,
    message_id_hash  CHAR(40) NULL,
    in_reply_to      VARCHAR(255) NULL,
    refs             TEXT NULL,
    subject          VARCHAR(500) NULL,
    from_name        VARCHAR(255) NULL,
    from_email       VARCHAR(255) NULL,
    to_list          JSON NULL,
    cc_list          JSON NULL,
    bcc_list         JSON NULL,
    reply_to_list    JSON NULL,
    to_text          TEXT NULL,
    sent_at          DATETIME NULL,
    received_at      DATETIME NULL,
    size             INT UNSIGNED NOT NULL DEFAULT 0,
    is_seen          TINYINT(1) NOT NULL DEFAULT 0,
    is_flagged       TINYINT(1) NOT NULL DEFAULT 0,
    is_answered      TINYINT(1) NOT NULL DEFAULT 0,
    is_draft         TINYINT(1) NOT NULL DEFAULT 0,
    has_attachments  TINYINT(1) NOT NULL DEFAULT 0,
    is_outbound      TINYINT(1) NOT NULL DEFAULT 0,
    is_auto          TINYINT(1) NOT NULL DEFAULT 0,
    snippet          VARCHAR(300) NULL,
    body_text        MEDIUMTEXT NULL,
    body_html        MEDIUMTEXT NULL,
    body_state       ENUM('pending','cached','remote','error') NOT NULL DEFAULT 'pending',
    parts            JSON NULL,
    list_unsubscribe VARCHAR(500) NULL,
    seen_by          INT NULL,
    seen_at          DATETIME NULL,
    sent_by          INT NULL,
    created_at       DATETIME NULL,
    updated_at       DATETIME NULL,
    UNIQUE KEY uq_mail_messages_uid (folder_id, uid),
    KEY idx_mail_messages_thread (thread_id, received_at),
    KEY idx_mail_messages_hash (account_id, message_id_hash),
    KEY idx_mail_messages_reply (account_id, in_reply_to(191)),
    KEY idx_mail_messages_list (folder_id, thread_id, received_at, is_seen, is_flagged, has_attachments),
    FULLTEXT KEY ft_mail_messages_search (subject, from_name, from_email, to_text, body_text),
    CONSTRAINT fk_mail_messages_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_messages_folder  FOREIGN KEY (folder_id)  REFERENCES mail_folders (id)  ON DELETE CASCADE,
    CONSTRAINT fk_mail_messages_thread  FOREIGN KEY (thread_id)  REFERENCES mail_threads (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_attachments (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    message_id  INT UNSIGNED NOT NULL,
    section     VARCHAR(32)  NOT NULL,
    filename    VARCHAR(255) NOT NULL,
    mime        VARCHAR(127) NOT NULL,
    encoding    VARCHAR(32)  NULL,
    size        INT UNSIGNED NOT NULL DEFAULT 0,
    content_id  VARCHAR(255) NULL,
    is_inline   TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_mail_attachments_message (message_id),
    CONSTRAINT fk_mail_attachments_message FOREIGN KEY (message_id) REFERENCES mail_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Drafts, queued, scheduled and sent mail written in the ERP (PR 3 owns the payload keys).
CREATE TABLE IF NOT EXISTS mail_outbox (
    id                  CHAR(36) NOT NULL PRIMARY KEY,
    account_id          INT UNSIGNED NOT NULL,
    user_id             INT NOT NULL,
    kind                ENUM('new','reply','reply_all','forward') NOT NULL DEFAULT 'new',
    status              ENUM('draft','queued','sending','sent','failed','cancelled') NOT NULL DEFAULT 'draft',
    thread_id           INT UNSIGNED NULL,
    reply_to_message_id INT UNSIGNED NULL,
    payload             JSON NOT NULL,
    ai_assisted         TINYINT(1) NOT NULL DEFAULT 0,
    message_id          VARCHAR(255) NULL,
    message_id_hash     CHAR(40) NULL,
    send_at             DATETIME NULL,
    sent_at             DATETIME NULL,
    attempts            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error          VARCHAR(500) NULL,
    idempotency_key     VARCHAR(64) NULL,
    created_at          DATETIME NULL,
    updated_at          DATETIME NULL,
    UNIQUE KEY uq_mail_outbox_idem (idempotency_key),
    KEY idx_mail_outbox_user (user_id),
    KEY idx_mail_outbox_hash (message_id_hash),
    KEY idx_mail_outbox_due (status, send_at),
    CONSTRAINT fk_mail_outbox_account FOREIGN KEY (account_id) REFERENCES mail_accounts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What happened to a conversation (PR 4 writes it; sync keeps it through merges).
CREATE TABLE IF NOT EXISTS mail_thread_activity (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    thread_id   INT UNSIGNED NOT NULL,
    user_id     INT NULL,
    type        VARCHAR(30) NOT NULL,
    data        JSON NULL,
    created_at  DATETIME NULL,
    KEY idx_mail_activity_thread (thread_id, created_at),
    CONSTRAINT fk_mail_activity_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who is viewing or replying now (PR 4), rows of about a minute.
CREATE TABLE IF NOT EXISTS mail_presence (
    thread_id   INT UNSIGNED NOT NULL,
    user_id     INT NOT NULL,
    state       ENUM('viewing','replying') NOT NULL DEFAULT 'viewing',
    expires_at  DATETIME NOT NULL,
    updated_at  DATETIME NULL,
    PRIMARY KEY (thread_id, user_id),
    KEY idx_mail_presence_expires (expires_at),
    CONSTRAINT fk_mail_presence_thread FOREIGN KEY (thread_id) REFERENCES mail_threads (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value JSON NULL,
    updated_by    INT NULL,
    updated_at    DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cPanel actions the cron carries out with the server's own `uapi`. params never
-- holds a password; the job makes one and keeps it only in mail_accounts (encrypted).
CREATE TABLE IF NOT EXISTS mail_admin_jobs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    action        VARCHAR(30) NOT NULL,
    account_id    INT UNSIGNED NULL,
    email         VARCHAR(190) NULL,
    params        JSON NULL,
    requested_by  INT NULL,
    status        ENUM('queued','running','done','failed','cancelled') NOT NULL DEFAULT 'queued',
    attempts      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    result        JSON NULL,
    error         VARCHAR(500) NULL,
    created_at    DATETIME NULL,
    started_at    DATETIME NULL,
    finished_at   DATETIME NULL,
    KEY idx_mail_jobs_status (status, id),
    KEY idx_mail_jobs_account (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A departed holder's mail: readable by nobody unless two different people
-- holding the two export abilities approve (docs/MAIL.md, "Export").
CREATE TABLE IF NOT EXISTS mail_export_requests (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    account_id          INT UNSIGNED NULL,
    email               VARCHAR(190) NOT NULL,
    requested_by        INT NOT NULL,
    reason              TEXT NOT NULL,
    status              ENUM('pending','approved','refused','ready','expired','cancelled') NOT NULL DEFAULT 'pending',
    admin_approved_by   INT NULL,
    admin_approved_at   DATETIME NULL,
    pastor_approved_by  INT NULL,
    pastor_approved_at  DATETIME NULL,
    refused_by          INT NULL,
    refused_at          DATETIME NULL,
    ready_at            DATETIME NULL,
    expires_at          DATETIME NULL,
    created_at          DATETIME NULL,
    updated_at          DATETIME NULL,
    KEY idx_mail_exports_account (account_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_export_events (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    request_id  INT UNSIGNED NOT NULL,
    user_id     INT NULL,
    event       VARCHAR(30) NOT NULL,
    detail      VARCHAR(255) NULL,
    created_at  DATETIME NULL,
    KEY idx_mail_export_events (request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Day one in the permissions matrix (lead, 11 Nov 2026): the Resident Pastor gives the pastoral approval of a
-- departed holder's export. Everything else about Mail starts empty (Super Admins pass through break-glass, which
-- does NOT count for this pastoral side). Portable, re-runnable: INSERT ... SELECT ... WHERE NOT EXISTS.
INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'mail.export_pastoral', 0, 1, 0, 0, 'church', NULL
  FROM roles r
 WHERE r.role_name = 'Resident_Pastor'
   AND NOT EXISTS (SELECT 1 FROM perm_grants g WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'mail.export_pastoral');
