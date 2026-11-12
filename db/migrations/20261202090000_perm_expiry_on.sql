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
-- The daily job never switches it on by itself. bin/permissions_parity.php
-- lists who would lose access in its "End dates" section.
--
-- Counted strictly: every live roster row (is_active = 1, Active), whatever
-- its seat. Portable (MySQL, MariaDB, SQLite) and idempotent.

-- 1. The switch row exists (off), as the day-one migration left it.
INSERT INTO perm_config (cfg_key, cfg_value)
SELECT 'expiry_enforced', '0'
 WHERE NOT EXISTS (SELECT 1 FROM perm_config WHERE cfg_key = 'expiry_enforced');

-- 2. On, only when nothing live is already past its end date.
UPDATE perm_config
   SET cfg_value = '1'
 WHERE cfg_key = 'expiry_enforced'
   AND NOT EXISTS (
        SELECT 1
          FROM user_departments ud
          LEFT JOIN ministry_years y ON y.id = ud.ministry_year_id
         WHERE ud.is_active = 1
           AND COALESCE(ud.roster_status, 'Active') = 'Active'
           AND COALESCE(ud.end_date, y.end_date) < CURRENT_DATE)
   AND NOT EXISTS (
        SELECT 1
          FROM user_roles ur
          JOIN roles r ON r.id = ur.role_id
         WHERE COALESCE(ur.is_frozen, 0) = 0
           AND COALESCE(r.is_archived, 0) = 0
           AND COALESCE(r.is_break_glass, 0) = 0
           AND ur.ends_on IS NOT NULL
           AND ur.ends_on < CURRENT_DATE);
