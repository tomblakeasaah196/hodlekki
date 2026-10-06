-- /db/migrations/20261006120000_se_attendee_delete.sql
--
-- Studio → Attendees: a real "Delete" (§12.5).
--
-- `status` already carries three of the four answers and none of them can be
-- the fourth. 'cancelled' belongs to "Release seat" and to the portal's own
-- self-cancel; 'removed' carries the block that makes the portal refuse a
-- return (`outcome: 'blocked'`, §12.2). The crew needs a delete that takes
-- someone off the list AND frees the number, so that the same person can
-- register again — which is neither of those.
--
-- So the marker lives in its own column, and `status` keeps its meaning:
-- a deleted registration is 'cancelled' (or 'removed', when the crew also
-- asked to block the number) and `deleted_at` says why it is really gone.
--
-- Both columns are nullable and nothing reads them before the deploy runs
-- `php db/migrate.php`, so this migration is safe on a live database.

ALTER TABLE se_registrations
    ADD COLUMN deleted_at DATETIME     NULL DEFAULT NULL AFTER cancelled_at,
    ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at,
    ADD KEY idx_se_reg_deleted (event_id, deleted_at);
