-- /db/migrations/20261126090000_campaigns_core.sql
--
-- Email: personal addresses, consent, the domain's sending queue and hourly
-- budget, campaigns, and the automation engine (Embrace, Assimilation, Charis).
-- Guide and contracts: docs/EMAIL.md.
--
-- PERSONAL EMAIL is its own record, never users.email. users.email is the
-- sign-in name for members (grace@hodlc.com) and the address first timers typed
-- on Connect, and Embrace, Congregation and Profile all overwrite it, so a
-- confirmed address kept there would be silently replaced. One confirmed address
-- per person and one person per confirmed address are enforced by the UNIQUE
-- keys on confirmed_user_id / confirmed_email, which are set only while the row
-- is confirmed (NULLs never collide), never by check-then-insert.
--
-- CONSENT is an append-only log with the exact wording shown, stored once by
-- its hash. The current answer is the latest row for a person, purpose and
-- channel. Account mail (confirm links, password resets) needs no consent and
-- writes nothing here.
--
-- ONE QUEUE, ONE BUDGET. Everything the system sends as system@ goes through
-- email_outbox, and every send on the domain (the mailbox's included) takes
-- from email_rate_buckets first. The hour's row is reserved by a conditional
-- UPDATE, so two workers can never overshoot the host's hourly cap.
--
-- NO DOUBLE SENDS. automation_runs is unique per rule and occasion, and
-- automation_sends per rule, step, person and streak/week; both are claimed by
-- INSERT before anything is queued. automation_person_day enforces "one
-- member-facing automation message per person per day" across modules.
--
-- Portable on purpose: no ALTER of an existing table, no foreign keys to users
-- (as the rest of the schema), no ON DUPLICATE KEY in the code that uses these
-- tables, and one column per line ending in ENGINE= so tests/campaigns builds
-- its SQLite database from this very file.

CREATE TABLE IF NOT EXISTS email_addresses (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    email VARCHAR(190) NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'pending',
    source VARCHAR(20) NOT NULL DEFAULT 'profile',
    confirmed_user_id INT NULL DEFAULT NULL,
    confirmed_email VARCHAR(190) NULL DEFAULT NULL,
    token_hash CHAR(64) NULL DEFAULT NULL,
    token_expires_at DATETIME NULL DEFAULT NULL,
    sends INT NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL DEFAULT NULL,
    confirmed_at DATETIME NULL DEFAULT NULL,
    confirmed_ip VARCHAR(45) NULL DEFAULT NULL,
    ended_at DATETIME NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_addr_confirmed_user (confirmed_user_id),
    UNIQUE KEY uq_email_addr_confirmed_email (confirmed_email),
    UNIQUE KEY uq_email_addr_token (token_hash),
    KEY ix_email_addr_user (user_id, status),
    KEY ix_email_addr_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The exact sentence a person agreed to. A changed sentence is a new row.
CREATE TABLE IF NOT EXISTS email_consent_texts (
    id INT NOT NULL AUTO_INCREMENT,
    purpose VARCHAR(20) NOT NULL,
    body TEXT NOT NULL,
    body_sha CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_consent_text_sha (body_sha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_consents (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    email VARCHAR(190) NULL DEFAULT NULL,
    purpose VARCHAR(20) NOT NULL,
    channel VARCHAR(10) NOT NULL,
    action VARCHAR(10) NOT NULL,
    method VARCHAR(30) NOT NULL,
    text_id INT NULL DEFAULT NULL,
    recorded_by INT NULL DEFAULT NULL,
    outbox_id INT NULL DEFAULT NULL,
    ip VARCHAR(45) NULL DEFAULT NULL,
    user_agent VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_email_consents_current (user_id, purpose, channel, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per address, not per person: hard bounces, complaints, repeated soft bounces.
CREATE TABLE IF NOT EXISTS email_suppressions (
    id INT NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    reason VARCHAR(20) NOT NULL,
    detail VARCHAR(255) NULL DEFAULT NULL,
    soft_count INT NOT NULL DEFAULT 0,
    until_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    released_at DATETIME NULL DEFAULT NULL,
    released_by INT NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_suppressions_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every email the system sends. Bodies are pruned after 90 days; the row stays,
-- because ref is the unsubscribe token and message_id_hash answers the mailbox's
-- "which automation is this a reply to?".
CREATE TABLE IF NOT EXISTS email_outbox (
    id INT NOT NULL AUTO_INCREMENT,
    ref CHAR(22) NOT NULL,
    lane VARCHAR(16) NOT NULL,
    priority INT NOT NULL DEFAULT 50,
    purpose VARCHAR(10) NOT NULL DEFAULT 'news',
    flow VARCHAR(60) NOT NULL,
    user_id INT NULL DEFAULT NULL,
    to_email VARCHAR(190) NOT NULL,
    to_name VARCHAR(190) NULL DEFAULT NULL,
    from_name VARCHAR(190) NULL DEFAULT NULL,
    reply_to VARCHAR(190) NULL DEFAULT NULL,
    reply_to_name VARCHAR(190) NULL DEFAULT NULL,
    subject VARCHAR(255) NOT NULL,
    html MEDIUMTEXT NULL,
    text_body MEDIUMTEXT NULL,
    headers_json TEXT NULL,
    links_json TEXT NULL,
    campaign_id INT NULL DEFAULT NULL,
    automation_send_id INT NULL DEFAULT NULL,
    message_id VARCHAR(190) NULL DEFAULT NULL,
    message_id_hash CHAR(64) NULL DEFAULT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'queued',
    attempts INT NOT NULL DEFAULT 0,
    not_before DATETIME NULL DEFAULT NULL,
    send_by DATETIME NULL DEFAULT NULL,
    locked_at DATETIME NULL DEFAULT NULL,
    sent_at DATETIME NULL DEFAULT NULL,
    last_error VARCHAR(500) NULL DEFAULT NULL,
    opened_at DATETIME NULL DEFAULT NULL,
    clicked_at DATETIME NULL DEFAULT NULL,
    bounced_at DATETIME NULL DEFAULT NULL,
    pruned_at DATETIME NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_email_outbox_ref (ref),
    KEY ix_email_outbox_due (status, priority, id),
    KEY ix_email_outbox_user (user_id, created_at),
    KEY ix_email_outbox_campaign (campaign_id, status),
    KEY ix_email_outbox_msgid (message_id_hash),
    KEY ix_email_outbox_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What happened to a sent email. No IP address and no user agent are kept.
CREATE TABLE IF NOT EXISTS email_events (
    id INT NOT NULL AUTO_INCREMENT,
    outbox_id INT NOT NULL,
    kind VARCHAR(12) NOT NULL,
    link_index INT NULL DEFAULT NULL,
    detail VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_email_events_outbox (outbox_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per clock hour (Africa/Lagos), 'YYYY-MM-DD HH'.
CREATE TABLE IF NOT EXISTS email_rate_buckets (
    hour_key CHAR(13) NOT NULL,
    sent_total INT NOT NULL DEFAULT 0,
    sent_bulk INT NOT NULL DEFAULT 0,
    refused_at DATETIME NULL DEFAULT NULL,
    learned_cap INT NULL DEFAULT NULL,
    PRIMARY KEY (hour_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_settings (
    skey VARCHAR(40) NOT NULL,
    svalue TEXT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (skey)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Saved designs (block documents), reused by campaigns and automation steps.
CREATE TABLE IF NOT EXISTS email_templates (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    doc_json MEDIUMTEXT NOT NULL,
    is_archived TINYINT NOT NULL DEFAULT 0,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_email_templates_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    subject VARCHAR(255) NOT NULL DEFAULT '',
    preheader VARCHAR(255) NOT NULL DEFAULT '',
    doc_json MEDIUMTEXT NULL,
    audience_json TEXT NULL,
    department_id INT NULL DEFAULT NULL,
    reply_to VARCHAR(190) NULL DEFAULT NULL,
    sms_fallback TINYINT NOT NULL DEFAULT 0,
    sms_text VARCHAR(480) NULL DEFAULT NULL,
    track_opens TINYINT NOT NULL DEFAULT 1,
    status VARCHAR(12) NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL DEFAULT NULL,
    queued_at DATETIME NULL DEFAULT NULL,
    finished_at DATETIME NULL DEFAULT NULL,
    total INT NOT NULL DEFAULT 0,
    emailed INT NOT NULL DEFAULT 0,
    texted INT NOT NULL DEFAULT 0,
    skipped INT NOT NULL DEFAULT 0,
    sms_campaign_id INT NULL DEFAULT NULL,
    created_by INT NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    sent_by INT NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY ix_email_campaigns_status (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One rule per module and kind: embrace/journey, assimilation/absence,
-- charis/absence. config_json holds the schedule, the audience and the steps
-- (each member step carries its email document and its SMS text).
CREATE TABLE IF NOT EXISTS automation_rules (
    id INT NOT NULL AUTO_INCREMENT,
    module VARCHAR(20) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    name VARCHAR(120) NOT NULL,
    is_enabled TINYINT NOT NULL DEFAULT 0,
    config_json MEDIUMTEXT NOT NULL,
    version INT NOT NULL DEFAULT 1,
    enabled_at DATETIME NULL DEFAULT NULL,
    baseline_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    updated_by INT NULL DEFAULT NULL,
    updated_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_automation_rules_module_kind (module, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_runs (
    id INT NOT NULL AUTO_INCREMENT,
    rule_id INT NOT NULL,
    occasion_key VARCHAR(64) NOT NULL,
    occasion_at DATETIME NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'running',
    people INT NOT NULL DEFAULT 0,
    emailed INT NOT NULL DEFAULT 0,
    texted INT NOT NULL DEFAULT 0,
    alerted INT NOT NULL DEFAULT 0,
    skipped INT NOT NULL DEFAULT 0,
    sms_campaign_id INT NULL DEFAULT NULL,
    detail VARCHAR(500) NULL DEFAULT NULL,
    started_at DATETIME NOT NULL,
    finished_at DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_automation_runs_occasion (rule_id, occasion_key),
    KEY ix_automation_runs_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_sends (
    id INT NOT NULL AUTO_INCREMENT,
    rule_id INT NOT NULL,
    step_key VARCHAR(40) NOT NULL,
    user_id INT NOT NULL,
    dedupe_key VARCHAR(40) NOT NULL,
    run_id INT NULL DEFAULT NULL,
    channel VARCHAR(8) NOT NULL DEFAULT 'none',
    outcome VARCHAR(24) NOT NULL,
    outbox_id INT NULL DEFAULT NULL,
    sms_queue_id INT NULL DEFAULT NULL,
    detail VARCHAR(255) NULL DEFAULT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_automation_sends_once (rule_id, step_key, user_id, dedupe_key),
    KEY ix_automation_sends_user (user_id, created_at),
    KEY ix_automation_sends_run (run_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS automation_person_day (
    user_id INT NOT NULL,
    day DATE NOT NULL,
    rule_id INT NOT NULL,
    step_key VARCHAR(40) NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
