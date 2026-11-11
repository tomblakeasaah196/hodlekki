-- 20261126090400_perm_pastoral_notes.sql
-- Roles & permissions: notes marked for pastors.
--
-- Charis and Embrace let a note be marked "Pastors" (visible_to = 'Pastors').
-- Today only the pastoral roles (and Super Admins) may read those. A seat on
-- Charis covers the whole module, so "may manage Charis" cannot also mean "may
-- read pastors' notes": that is its own row in the matrix, pastoral_notes.
--
-- Portable SQL, re-runnable, never overwrites a cell someone has changed.

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'pastoral_notes', 1, 0, 0, 0, 'church', NULL
  FROM roles r
 WHERE r.role_name IN ('Resident_Pastor', 'Assoc_Pastor')
   AND NOT EXISTS (SELECT 1 FROM perm_grants g WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'pastoral_notes');
