-- /db/migrations/20261202090000_perm_expiry_on.sql
--
-- Permissions PR 3: end dates count from now on (design §5.7, lead decision 4)
-- — but ONLY when that ends nobody's access at the moment of deploy.
--
-- A seat ends on its own end date, else its ministry year's end; a role ends
-- on its ends_on; break-glass (Super Admin) never ends. The check is switched
-- by perm_config.expiry_enforced (0 since the day-one migration). This PR
-- ships what makes it safe: the reminders (cron/permissions_daily.php, 14
-- days before), one-click Renew, the year-end summary and the rollover link.
--
-- It is switched on here only if no live roster seat and no live dated role
-- is already past its end date (they would stop giving access the moment
-- this ran, before anyone was told). Otherwise it stays off: Roles &
-- permissions and System Health then show "N seats past their end date",
-- someone renews or ends them, and a Super Admin presses "Enforce end dates"
-- (perm_expiry_switch_on(), refused while any remain; kept in perm_history).
-- The daily job never switches it on. bin/permissions_parity.php lists who
-- would lose access in its "End dates" section.
--
-- Counted strictly: every live roster row (is_active = 1, Active), whatever
-- its seat.
--
-- Schemas differ (the integration jobs skip the ministry-years migration), so
-- the UPDATE is built from information_schema like
-- 20261126090100_perm_platform_columns:
--   - seats are checked only where ministry_years and user_departments'
--     roster_status / ministry_year_id / end_date exist (without them a seat
--     has no end date);
--   - roles only where user_roles.ends_on exists; is_frozen / is_archived /
--     is_break_glass are used where present.
-- MySQL / MariaDB only (PREPARE). tests/permissions/admin_tests.php runs the
-- same conditions on SQLite, read from the literals below. Idempotent.

SET @db := DATABASE();

-- 1. The switch row exists (off), as the day-one migration left it.
INSERT INTO perm_config (cfg_key, cfg_value)
SELECT 'expiry_enforced', '0'
 WHERE NOT EXISTS (SELECT 1 FROM perm_config WHERE cfg_key = 'expiry_enforced');

-- 2. What the schema has.
SET @has_years := (SELECT COUNT(*) FROM information_schema.tables
                    WHERE table_schema = @db AND table_name = 'ministry_years') = 1
              AND (SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = @db AND table_name = 'user_departments'
                      AND column_name IN ('roster_status', 'ministry_year_id', 'end_date')) = 3;
SET @has_role_end := (SELECT COUNT(*) FROM information_schema.columns
                       WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'ends_on') = 1;
SET @frozen := IF((SELECT COUNT(*) FROM information_schema.columns
                    WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'is_frozen') = 1,
                  'COALESCE(ur.is_frozen, 0) = 0', '1 = 1');
SET @archived := IF((SELECT COUNT(*) FROM information_schema.columns
                      WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'is_archived') = 1,
                    'COALESCE(r.is_archived, 0) = 0', '1 = 1');
SET @breakglass := IF((SELECT COUNT(*) FROM information_schema.columns
                        WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'is_break_glass') = 1,
                      'COALESCE(r.is_break_glass, 0) = 0', '1 = 1');

-- 3. The conditions (literals: the SQLite tests read these exact strings).
SET @update := 'UPDATE perm_config SET cfg_value = ''1'' WHERE cfg_key = ''expiry_enforced''';
SET @seat_check := ' AND NOT EXISTS (SELECT 1 FROM user_departments ud LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id WHERE ud.is_active = 1 AND COALESCE(ud.roster_status, ''Active'') = ''Active'' AND COALESCE(ud.end_date, y.end_date) < CURRENT_DATE)';
SET @role_check := ' AND NOT EXISTS (SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE {frozen} AND {archived} AND {breakglass} AND ur.ends_on IS NOT NULL AND ur.ends_on < CURRENT_DATE)';
SET @role_check := REPLACE(REPLACE(REPLACE(@role_check, '{frozen}', @frozen), '{archived}', @archived), '{breakglass}', @breakglass);

-- 4. On, only when nothing live is already past its end date.
SET @sql := CONCAT(@update, IF(@has_years, @seat_check, ''), IF(@has_role_end, @role_check, ''));
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;
