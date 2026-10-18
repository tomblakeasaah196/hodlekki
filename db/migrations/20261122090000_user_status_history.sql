-- 20261122090000_user_status_history.sql
-- A record of every change to users.spiritual_status.
--
-- Two things need it:
--   1. The Sunday ladder (includes/status_ladder.php) moves 1st Timers to 2nd,
--      3rd and then Visitor by itself. Each automatic move is written here with
--      source = 'attendance_ladder', which is how Embrace tells "graduated from
--      the ladder, follow-up still owed" apart from an ordinary Visitor.
--   2. Event reports used to read each person's CURRENT status, so promoting
--      someone quietly shrank the "first timers" count on every past service.
--      With this table a report can ask what a person's status was on the day
--      of the event: the from_status of the first change made after that day,
--      or the current status when there has been none.
--
-- from_status / to_status are plain strings ('' = blank), not a foreign key,
-- and there is deliberately no foreign key on user_id: the history must never
-- constrain a users row being removed. api/congregation_api.php removes a
-- person's rows itself when it deletes them.
--
-- Plain CREATE TABLE IF NOT EXISTS so the file is safe to apply twice.

CREATE TABLE IF NOT EXISTS user_status_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    from_status VARCHAR(32) NOT NULL DEFAULT '',
    to_status   VARCHAR(32) NOT NULL DEFAULT '',
    source      VARCHAR(32) NOT NULL,
    changed_by  INT UNSIGNED NULL DEFAULT NULL,
    changed_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ush_user_changed (user_id, changed_at),
    KEY idx_ush_source_to (source, to_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
