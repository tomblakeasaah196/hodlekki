-- 20261126090300_perm_monitor_grant.sql
--
-- The system monitor in the permission matrix: Super Admins get it as a plain
-- grant (view + manage), so routine visits to System Health are not logged as
-- break-glass use. Its own migration because 20261126090200_perm_day_one had
-- already run on the live site when the 'monitor' module key was added.
-- Safe to run twice (NOT EXISTS), portable to MySQL 8 and MariaDB 10.11.

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', 'monitor', 1, 0, 0, 1, 'church', NULL
  FROM roles r
 WHERE r.role_name = 'Super_Admin'
   AND NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = 'monitor');
