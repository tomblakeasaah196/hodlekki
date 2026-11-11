-- 20261126090500_perm_retire_global_roles.sql
-- Roles & permissions PR 2: the global Director and HOD roles retire (lead
-- decision 2, 11 Nov 2026).
--
-- Appointing a Director or HOD used to also give the person a church-wide
-- role of the same name, which nothing ever took away. Since the day-one
-- matrix, everything those roles gave sits on the Director / HOD SEAT columns,
-- so access comes only from the live Departments roster. This file:
--   1. writes one history row per role row it removes (who, which role, when,
--      before → after), via = 'migration';
--   2. deletes every user_roles row of those two roles;
--   3. archives both roles (kept for the history; no one can hold them).
-- Run bin/permissions_parity.php BEFORE deploying this: it names everyone who
-- loses something because of it, and after this file the rows are gone.
--
-- Portable SQL (the SQLite tests run it). Re-running does nothing more.

INSERT INTO perm_history (actor_label, via, change_type, subject_user_id, role_id, before_json, after_json, reason)
SELECT 'migration', 'migration', 'user_role.remove', ur.user_id, r.id,
       '{"role":"held"}', '{"role":"retired"}',
       'Global Director/HOD role retired: access now comes from the Departments roster seat (decision 2)'
  FROM user_roles ur
  JOIN roles r ON r.id = ur.role_id
 WHERE r.role_name IN ('Director', 'HOD');

DELETE FROM user_roles
 WHERE role_id IN (SELECT id FROM roles WHERE role_name IN ('Director', 'HOD'));

UPDATE roles SET is_archived = 1 WHERE role_name IN ('Director', 'HOD');
