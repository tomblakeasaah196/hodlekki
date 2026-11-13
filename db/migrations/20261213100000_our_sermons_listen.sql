-- /db/migrations/20261213100000_our_sermons_listen.sql
--
-- Our sermons, PR S2: the members' listening page (owner, 13 Nov 2026).
-- Two tables keyed on our_sermons.id (PR S1's table):
--
--   our_sermon_notes     a member's private notes on a sermon, each stamped
--                        with the moment in the audio or video (at_seconds;
--                        NULL = not stamped). body is plain text, at most
--                        OUR_SERMON_NOTE_MAX characters (enforced in PHP).
--                        The member's alone: only includes/our_sermons/listen.php
--                        reads or writes it; no Envision screen, report, export
--                        or AI does.
--   our_sermon_progress  where a member stopped (position_seconds) so the
--                        page carries on from there, and when they first
--                        heard it to the end (finished_at). One row per
--                        member and sermon: UNIQUE (sermon_id, user_id).
--
-- No foreign keys, as elsewhere. Forward-only, safe to run twice.

CREATE TABLE IF NOT EXISTS our_sermon_notes (
    id INT NOT NULL AUTO_INCREMENT,
    sermon_id INT NOT NULL,
    user_id INT NOT NULL,
    at_seconds INT NULL DEFAULT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY ix_our_sermon_notes_user (user_id, sermon_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS our_sermon_progress (
    id INT NOT NULL AUTO_INCREMENT,
    sermon_id INT NOT NULL,
    user_id INT NOT NULL,
    position_seconds INT NOT NULL DEFAULT 0,
    finished_at DATETIME NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_our_sermon_progress (sermon_id, user_id),
    KEY ix_our_sermon_progress_user (user_id, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
