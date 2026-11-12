-- /db/migrations/20261202090000_perm_expiry_on.sql
--
-- Permissions PR 3: end dates count from now on (design §5.7, lead decision 4).
--
-- A seat ends on its own end date, else its ministry year's end; a role ends
-- on its ends_on; break-glass (Super Admin) never ends. Until now the check
-- was off (perm_config.expiry_enforced = 0, set by the day-one migration), so
-- nobody could lose access on a date without having been told. This PR ships
-- what makes it safe to switch on: the reminders (cron/permissions_daily.php,
-- 14 days before), one-click Renew, the year-end summary and the rollover link.
--
-- On the day this ran locally (12 Nov 2026) no live seat or role was past its
-- end, so switching it on changes nobody's access today.
--
-- Portable and idempotent.

INSERT INTO perm_config (cfg_key, cfg_value)
SELECT 'expiry_enforced', '1'
 WHERE NOT EXISTS (SELECT 1 FROM perm_config WHERE cfg_key = 'expiry_enforced');

UPDATE perm_config SET cfg_value = '1' WHERE cfg_key = 'expiry_enforced';
