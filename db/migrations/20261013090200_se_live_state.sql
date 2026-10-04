-- 20261013090200_se_live_state.sql
-- Live control state per event (scene, active round, display keys, version).

CREATE TABLE IF NOT EXISTS se_live_state (
    event_id            INT UNSIGNED    NOT NULL,
    version             BIGINT UNSIGNED NOT NULL DEFAULT 1,
    scene               VARCHAR(30)     NOT NULL DEFAULT 'standby',
    scene_payload_json  TEXT            NULL,
    active_game_id      INT UNSIGNED    NULL,
    active_round_id     INT UNSIGNED    NULL,
    announcement_json   TEXT            NULL,
    sfx_seq             INT UNSIGNED    NOT NULL DEFAULT 0,
    sfx_cue             VARCHAR(20)     NULL,
    dirty               TINYINT(1)      NOT NULL DEFAULT 0,
    last_published_at   DATETIME(3)     NULL,
    room_key            CHAR(22)        NOT NULL,
    lobby_key           CHAR(22)        NOT NULL,
    stage_key           CHAR(22)        NOT NULL,
    updated_at          DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    updated_by          INT UNSIGNED    NULL,
    PRIMARY KEY (event_id),
    CONSTRAINT fk_se_live_event FOREIGN KEY (event_id) REFERENCES se_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
