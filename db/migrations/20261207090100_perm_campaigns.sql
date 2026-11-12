-- /db/migrations/20261207090100_perm_campaigns.sql
--
-- Email Campaigns in the permission matrix (module key 'campaigns', lead's
-- answers of 11 Nov 2026 to the campaigns design §17 q7):
--   * Super Admin: everything, church-wide, plus Manage (email settings). A
--     plain grant, so routine use is not logged as break-glass (as 'monitor').
--   * Resident Pastor: everything church-wide, no Manage.
--   * Assoc. Pastor role: create, edit and send church-wide.
--   * Pastor in charge, Director and HOD seats: everything for their own
--     department(s) — 'campaigns' is partitioned, so a seat applies in its
--     own department and its people are the only audience.
-- Change any of it later in Roles & permissions.
-- Safe to run twice (NOT EXISTS), portable to MySQL 8 and MariaDB 10.11.
-- Levels: 1 view, 2 create, 3 edit, 4 delete.

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'campaigns', c.level, 0, 0, c.m, 'church', NULL
  FROM (
        SELECT 'Super_Admin' AS role_name, 4 AS level, 1 AS m
        UNION ALL SELECT 'Resident_Pastor', 4, 0
        UNION ALL SELECT 'Assoc_Pastor', 3, 0
       ) c
  JOIN roles r ON r.role_name = c.role_name
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'campaigns');

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT 0, c.seat_key, 'campaigns', 4, 0, 0, 0, 'dept', NULL
  FROM (
        SELECT 'pastor_in_charge' AS seat_key
        UNION ALL SELECT 'director'
        UNION ALL SELECT 'hod'
       ) c
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = 0 AND g.seat_key = c.seat_key AND g.module_key = 'campaigns');
