-- 20261125090000_monitor_core.sql
--
-- The error monitor and health monitor (docs/monitor.md).
--
-- Every table belongs to the monitor and is prefixed monitor_. Nothing here
-- alters a platform table. The code that fills these tables ships before this
-- migration runs (bin/deploy.sh copies the tree, then runs db/migrate.php), so
-- every writer falls back to the spool file outside the docroot until the
-- tables exist, and the cron imports what was spooled.
--
-- Times are Lagos time (DATETIME), written by PHP rather than NOW(), so the
-- same SQL runs on MySQL 8, MariaDB 10.11 and the SQLite test database.
-- Columns are one per line, closed by ENGINE=, so tests/monitor/lib.php can
-- translate this file for SQLite: keep that shape when adding a table.
--
-- No personal data: events keep the ROLE that met the error and a keyed hash
-- of who it was (to count people), never a name, id, phone, email or IP.

-- One row per distinct problem: the same message from the same place.
CREATE TABLE IF NOT EXISTS monitor_error_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    fingerprint CHAR(40) NOT NULL,
    kind VARCHAR(12) NOT NULL,
    level VARCHAR(12) NOT NULL,
    source VARCHAR(12) NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NULL,
    exception_class VARCHAR(190) NULL,
    file VARCHAR(255) NULL,
    line INT NULL,
    route VARCHAR(255) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'new',
    status_changed_at DATETIME NULL,
    status_by INT NULL,
    fixed_at DATETIME NULL,
    fixed_release VARCHAR(12) NULL,
    first_seen_at DATETIME NOT NULL,
    last_seen_at DATETIME NOT NULL,
    first_release VARCHAR(12) NULL,
    last_release VARCHAR(12) NULL,
    occurrences INT UNSIGNED NOT NULL DEFAULT 0,
    regressions INT UNSIGNED NOT NULL DEFAULT 0,
    regressed_at DATETIME NULL,
    spiked_at DATETIME NULL,
    spike_alerted_at DATETIME NULL,
    alerted_at DATETIME NULL,
    trace MEDIUMTEXT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monitor_groups_fp (fingerprint),
    KEY idx_monitor_groups_status (status, last_seen_at),
    KEY idx_monitor_groups_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Occurrences: the latest 50 per problem, and at most 20 an hour of each
-- (fatal errors always), so a noisy warning cannot fill the table.
CREATE TABLE IF NOT EXISTS monitor_error_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id INT UNSIGNED NOT NULL,
    ref CHAR(8) NOT NULL,
    occurred_at DATETIME NOT NULL,
    source VARCHAR(12) NOT NULL,
    method VARCHAR(8) NULL,
    path VARCHAR(255) NULL,
    host VARCHAR(64) NULL,
    role VARCHAR(40) NULL,
    user_hash CHAR(16) NULL,
    release_id VARCHAR(12) NULL,
    device VARCHAR(8) NULL,
    browser VARCHAR(60) NULL,
    duration_ms INT UNSIGNED NULL,
    memory_mb INT UNSIGNED NULL,
    message TEXT NULL,
    trace MEDIUMTEXT NULL,
    context TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_events_group (group_id, occurred_at),
    KEY idx_monitor_events_time (occurred_at),
    KEY idx_monitor_events_ref (ref)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- How often, hour by hour: the trend lines and before/after a fix (90 days).
CREATE TABLE IF NOT EXISTS monitor_error_hourly (
    group_id INT UNSIGNED NOT NULL,
    hour DATETIME NOT NULL,
    n INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (group_id, hour),
    KEY idx_monitor_hourly_hour (hour)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Distinct people a problem reached, per day (keyed hashes only).
CREATE TABLE IF NOT EXISTS monitor_error_people (
    group_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    user_hash CHAR(16) NOT NULL,
    PRIMARY KEY (group_id, day, user_hash),
    KEY idx_monitor_people_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The pages a problem happened on.
CREATE TABLE IF NOT EXISTS monitor_error_paths (
    group_id INT UNSIGNED NOT NULL,
    path_hash CHAR(40) NOT NULL,
    path VARCHAR(255) NOT NULL,
    n INT UNSIGNED NOT NULL DEFAULT 0,
    last_seen_at DATETIME NOT NULL,
    PRIMARY KEY (group_id, path_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- What the team wrote about a problem; user_id NULL = written by the monitor.
CREATE TABLE IF NOT EXISTS monitor_error_notes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id INT UNSIGNED NOT NULL,
    user_id INT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_notes_group (group_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- The current state of each health check.
CREATE TABLE IF NOT EXISTS monitor_checks (
    check_key VARCHAR(60) NOT NULL,
    grp VARCHAR(40) NOT NULL,
    label VARCHAR(120) NOT NULL,
    state VARCHAR(8) NOT NULL,
    detail TEXT NULL,
    fix TEXT NULL,
    since DATETIME NOT NULL,
    checked_at DATETIME NOT NULL,
    alerted_at DATETIME NULL,
    alerted_state VARCHAR(8) NULL,
    PRIMARY KEY (check_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every change of state, for timelines (90 days).
CREATE TABLE IF NOT EXISTS monitor_check_history (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    check_key VARCHAR(60) NOT NULL,
    state VARCHAR(8) NOT NULL,
    detail VARCHAR(255) NULL,
    at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_history_key (check_key, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Results posted by the outside probe (.github/workflows/monitor-probe.yml).
CREATE TABLE IF NOT EXISTS monitor_probes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id VARCHAR(40) NOT NULL,
    received_at DATETIME NOT NULL,
    probe_at DATETIME NOT NULL,
    target VARCHAR(40) NOT NULL,
    ok TINYINT NOT NULL DEFAULT 0,
    http_status INT NULL,
    ttfb_ms INT NULL,
    total_ms INT NULL,
    detail TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_probes_target (target, received_at),
    KEY idx_monitor_probes_time (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Real-user speed, one row per page view that reported (14 days).
CREATE TABLE IF NOT EXISTS monitor_rum (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    at DATETIME NOT NULL,
    page VARCHAR(190) NOT NULL,
    device VARCHAR(8) NOT NULL,
    conn VARCHAR(8) NULL,
    nav VARCHAR(12) NULL,
    lcp INT NULL,
    inp INT NULL,
    cls INT NULL,
    ttfb INT NULL,
    fcp INT NULL,
    release_id VARCHAR(12) NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_rum_time (at),
    KEY idx_monitor_rum_page (page, at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Real-user speed per day, page, device and metric: the 75th percentile and
-- how many views were good / need improvement / poor (CLS is x1000).
CREATE TABLE IF NOT EXISTS monitor_rum_daily (
    day DATE NOT NULL,
    page VARCHAR(190) NOT NULL,
    device VARCHAR(8) NOT NULL,
    metric VARCHAR(6) NOT NULL,
    n INT UNSIGNED NOT NULL DEFAULT 0,
    p75 INT NOT NULL DEFAULT 0,
    good INT UNSIGNED NOT NULL DEFAULT 0,
    ni INT UNSIGNED NOT NULL DEFAULT 0,
    poor INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (day, page, device, metric)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Who gets alert emails. An address receives nothing until its owner clicks
-- the confirmation link (token_hash = sha256 of the link's secret); every
-- email carries a stop link, signed with the monitor's key (alerts.php).
CREATE TABLE IF NOT EXISTS monitor_recipients (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    token_hash CHAR(64) NULL,
    token_expires_at DATETIME NULL,
    added_by INT NULL,
    added_at DATETIME NOT NULL,
    confirmed_at DATETIME NULL,
    removed_at DATETIME NULL,
    last_sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_monitor_recipients_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every alert sent (email or bell), for the hourly caps and the audit trail.
CREATE TABLE IF NOT EXISTS monitor_alert_log (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    at DATETIME NOT NULL,
    channel VARCHAR(8) NOT NULL,
    kind VARCHAR(20) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    recipients INT UNSIGNED NOT NULL DEFAULT 0,
    ok TINYINT NOT NULL DEFAULT 0,
    error VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_monitor_alert_log_time (at, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Small settings and bookkeeping: log positions, the wall key, digest days.
CREATE TABLE IF NOT EXISTS monitor_state (
    k VARCHAR(120) NOT NULL,
    v MEDIUMTEXT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
