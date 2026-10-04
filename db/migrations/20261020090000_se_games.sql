-- 20261020090000_se_games.sql
-- Decks and items, games, rounds, answers, buzzes, Family Feud survey and boards, score ledger.

CREATE TABLE IF NOT EXISTS se_decks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(120) NOT NULL,
    content_type  VARCHAR(20)  NOT NULL,
    scope         ENUM('library','event') NOT NULL DEFAULT 'library',
    event_id      INT UNSIGNED NULL,
    description   VARCHAR(255) NULL,
    translation   VARCHAR(10)  NOT NULL DEFAULT 'KJV',
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_decks_type (content_type, scope),
    CONSTRAINT fk_se_decks_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_deck_items (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    deck_id             INT UNSIGNED NOT NULL,
    sort_order          INT          NOT NULL DEFAULT 0,
    payload_json        MEDIUMTEXT   NOT NULL,
    difficulty          ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    scripture_ref       VARCHAR(60)  NULL,
    scripture_text      TEXT         NULL,
    media_asset_id      INT UNSIGNED NULL,
    review_status       ENUM('draft','approved','rejected') NOT NULL DEFAULT 'draft',
    reviewed_by         INT UNSIGNED NULL,
    reviewed_at         DATETIME     NULL,
    source              ENUM('manual','ai','import') NOT NULL DEFAULT 'manual',
    ai_job_id           INT UNSIGNED NULL,
    times_used          INT UNSIGNED NOT NULL DEFAULT 0,
    last_used_event_id  INT UNSIGNED NULL,
    last_used_at        DATETIME     NULL,
    created_by          INT UNSIGNED NULL,
    created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_deck_items_deck (deck_id, sort_order),
    KEY idx_se_deck_items_review (deck_id, review_status),
    CONSTRAINT fk_se_deck_items_deck FOREIGN KEY (deck_id) REFERENCES se_decks (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_games (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    type             VARCHAR(20)  NOT NULL,
    title            VARCHAR(120) NOT NULL,
    settings_json    TEXT         NOT NULL,
    weight           DECIMAL(4,2) NOT NULL DEFAULT 1.00,
    status           ENUM('draft','ready','live','paused','finished') NOT NULL DEFAULT 'draft',
    program_item_id  INT UNSIGNED NULL,
    sort_order       INT          NOT NULL DEFAULT 0,
    started_at       DATETIME     NULL,
    finished_at      DATETIME     NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_se_games_event (event_id, sort_order),
    CONSTRAINT fk_se_games_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_game_items (
    game_id       INT UNSIGNED NOT NULL,
    deck_item_id  INT UNSIGNED NOT NULL,
    sort_order    INT          NOT NULL,
    PRIMARY KEY (game_id, deck_item_id),
    KEY idx_se_game_items_order (game_id, sort_order),
    CONSTRAINT fk_se_game_items_game FOREIGN KEY (game_id) REFERENCES se_games (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_game_items_item FOREIGN KEY (deck_item_id) REFERENCES se_deck_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_rounds (
    id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id                   INT UNSIGNED NOT NULL,
    game_id                    INT UNSIGNED NOT NULL,
    round_no                   INT UNSIGNED NOT NULL,
    deck_item_id               INT UNSIGNED NULL,
    state                      ENUM('pending','armed','open','locked','revealed','scored','void') NOT NULL DEFAULT 'pending',
    attempt                    TINYINT UNSIGNED NOT NULL DEFAULT 1,
    team_id                    INT UNSIGNED NULL,
    opponent_team_id           INT UNSIGNED NULL,
    presenter_registration_id  INT UNSIGNED NULL,
    is_test                    TINYINT(1)   NOT NULL DEFAULT 0,
    arm_at                     DATETIME(3)  NULL,
    opens_at                   DATETIME(3)  NULL,
    closes_at                  DATETIME(3)  NULL,
    locked_at                  DATETIME(3)  NULL,
    revealed_at                DATETIME(3)  NULL,
    eligible_json              TEXT         NULL,
    state_json                 MEDIUMTEXT   NULL,
    result_json                MEDIUMTEXT   NULL,
    void_reason                VARCHAR(160) NULL,
    created_at                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_rounds_no (game_id, round_no),
    KEY idx_se_rounds_event_state (event_id, state),
    CONSTRAINT fk_se_rounds_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE,
    CONSTRAINT fk_se_rounds_game FOREIGN KEY (game_id) REFERENCES se_games (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_answers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id           INT UNSIGNED NOT NULL,
    round_id           INT UNSIGNED NOT NULL,
    registration_id    INT UNSIGNED NOT NULL,
    team_id            INT UNSIGNED NULL,
    role               ENUM('player','captain','suggestion') NOT NULL DEFAULT 'player',
    choice_index       TINYINT      NULL,
    answer_text        VARCHAR(160) NULL,
    received_at        DATETIME(3)  NOT NULL,
    client_elapsed_ms  INT UNSIGNED NULL,
    elapsed_ms         INT UNSIGNED NOT NULL DEFAULT 0,
    is_correct         TINYINT(1)   NULL,
    points             INT          NOT NULL DEFAULT 0,
    device_id          INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_answers_once (round_id, registration_id, role),
    KEY idx_se_answers_round_team (round_id, team_id, role),
    CONSTRAINT fk_se_answers_round FOREIGN KEY (round_id) REFERENCES se_rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_buzzes (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    round_id         INT UNSIGNED NOT NULL,
    attempt          TINYINT UNSIGNED NOT NULL DEFAULT 1,
    team_id          INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    effective_ms     BIGINT UNSIGNED NOT NULL,
    received_at      DATETIME(3)  NOT NULL,
    judged           ENUM('pending','correct','wrong','ignored') NOT NULL DEFAULT 'pending',
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_buzz_team (round_id, attempt, team_id),
    KEY idx_se_buzz_order (round_id, attempt, effective_ms),
    CONSTRAINT fk_se_buzzes_round FOREIGN KEY (round_id) REFERENCES se_rounds (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_survey_responses (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    deck_item_id     INT UNSIGNED NOT NULL,
    registration_id  INT UNSIGNED NOT NULL,
    answer_text      VARCHAR(80)  NOT NULL,
    answer_norm      VARCHAR(80)  NOT NULL,
    feud_answer_id   INT UNSIGNED NULL,
    is_test          TINYINT(1)   NOT NULL DEFAULT 0,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_survey_once (event_id, deck_item_id, registration_id),
    KEY idx_se_survey_item (event_id, deck_item_id),
    CONSTRAINT fk_se_survey_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_feud_answers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id      INT UNSIGNED NOT NULL,
    deck_item_id  INT UNSIGNED NOT NULL,
    label         VARCHAR(60)  NOT NULL,
    points        INT UNSIGNED NOT NULL,
    sort_order    TINYINT UNSIGNED NOT NULL,
    source        ENUM('survey','ai','manual') NOT NULL,
    approved      TINYINT(1)   NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_se_feud_board (event_id, deck_item_id, sort_order),
    CONSTRAINT fk_se_feud_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS se_score_events (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id         INT UNSIGNED NOT NULL,
    scope            ENUM('team','individual') NOT NULL,
    team_id          INT UNSIGNED NULL,
    registration_id  INT UNSIGNED NULL,
    game_id          INT UNSIGNED NULL,
    round_id         INT UNSIGNED NULL,
    kind             ENUM('auto','award','penalty','correction') NOT NULL,
    points           INT          NOT NULL,
    reason           VARCHAR(160) NULL,
    idempotency_key  VARCHAR(80)  NULL,
    created_by       INT UNSIGNED NULL,
    created_at       DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    voided_at        DATETIME     NULL,
    voided_by        INT UNSIGNED NULL,
    void_reason      VARCHAR(160) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_se_score_idem (event_id, idempotency_key),
    KEY idx_se_score_team (event_id, scope, team_id, voided_at),
    KEY idx_se_score_reg (event_id, scope, registration_id, voided_at),
    KEY idx_se_score_round (round_id),
    CONSTRAINT fk_se_score_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
