-- /db/migrations/20261213090100_perm_our_sermons.sql
--
-- Our sermons in the permission matrix (module key 'our_sermons',
-- OUR-SERMONS-PLAN.md "Permissions"). On day one:
--   * Everyone: View, church-wide (every signed-in person browses and listens);
--   * Resident Pastor and Assoc. Pastor roles: everything church-wide, with
--     Manage (the feed settings);
--   * the Envision department's seats (it owns the module, matched by name
--     as the day-one seed matched it for 'envision', plus any department that
--     owns 'envision' today): the same. A seat on a module that is not split
--     by department covers the whole module.
-- Levels: Create = paste a link; Edit = check and correct drafts, publish,
-- hide; Delete = remove a sermon; Manage = the RSS feeds.
-- Change any of it later in Roles & permissions.
-- Safe to run twice (NOT EXISTS), portable to MySQL 8, MariaDB 10.11 and the
-- SQLite test databases. Levels: 1 view, 2 create, 3 edit, 4 delete.

INSERT INTO perm_module_owners (module_key, department_id)
SELECT 'our_sermons', o.department_id
  FROM (
        SELECT d.id AS department_id FROM departments d WHERE d.name LIKE '%Envision%'
        UNION SELECT e.department_id FROM perm_module_owners e WHERE e.module_key = 'envision' AND e.department_id > 0
       ) o
 WHERE NOT EXISTS (SELECT 1 FROM perm_module_owners p
                    WHERE p.module_key = 'our_sermons' AND p.department_id = o.department_id);

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT 0, '@everyone', 'our_sermons', 1, 0, 0, 0, 'church', NULL
  FROM (SELECT 1 AS one) x
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = 0 AND g.seat_key = '@everyone' AND g.module_key = 'our_sermons');

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'our_sermons', 4, 0, 0, 1, 'church', NULL
  FROM roles r
 WHERE r.role_name IN ('Resident_Pastor', 'Assoc_Pastor')
   AND NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'our_sermons');

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT 0, t.seat_key, 'our_sermons', 4, 0, 0, 1, 'dept', NULL
  FROM perm_seat_types t
 WHERE t.seat_key IN ('pastor_in_charge', 'director', 'hod', 'deputy_hod', 'sub_unit_head', 'worker', 'member')
   AND NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = 0 AND g.seat_key = t.seat_key AND g.module_key = 'our_sermons');
