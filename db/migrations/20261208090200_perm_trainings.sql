-- /db/migrations/20261208090200_perm_trainings.sql
--
-- Trainings in the permission matrix (module key 'trainings', the plan's
-- "trainings permission row"). It takes over from the retired 'academy' row:
-- the same owner departments and the same cells, copied as they stand today,
-- so nobody gains or loses anything and any change the owner already made to
-- the Academy row carries over. On day one that is:
--   * Resident Pastor and Assoc. Pastor roles: everything church-wide, with
--     Approve (confirm graduates) and Manage;
--   * the Academy department's seats (perm_module_owners): the same;
--   * Everyone: Create on their own records (join from the catalogue, do the
--     items, hand in work). Learners see only their own trainings.
-- The old 'academy' rows are then removed: every one of them was copied first
-- (or 'trainings' already had that cell), and a row for a key the code no
-- longer has would only sit in the matrix where nobody can change it.
-- Change any of it later in Roles & permissions.
-- Safe to run twice (NOT EXISTS), portable to MySQL 8, MariaDB 10.11 and the
-- SQLite test databases.

INSERT INTO perm_module_owners (module_key, department_id)
SELECT 'trainings', o.department_id
  FROM perm_module_owners o
 WHERE o.module_key = 'academy'
   AND NOT EXISTS (SELECT 1 FROM perm_module_owners p
                    WHERE p.module_key = 'trainings' AND p.department_id = o.department_id);

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT g.role_id, g.seat_key, 'trainings', g.level, g.can_approve, g.can_export, g.can_manage, g.scope, g.view_scope
  FROM perm_grants g
 WHERE g.module_key = 'academy'
   AND NOT EXISTS (SELECT 1 FROM perm_grants t
                    WHERE t.role_id = g.role_id AND t.seat_key = g.seat_key AND t.module_key = 'trainings');

DELETE FROM perm_grants WHERE module_key = 'academy';

DELETE FROM perm_module_owners WHERE module_key = 'academy';
