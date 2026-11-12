-- /db/migrations/20261204090000_se_drop_envision_setting.sql
--
-- Special Events: the "Envision department ID" setting is gone. Who may use
-- the Studio comes from Roles & permissions (module "special_events" and its
-- module owners) since permissions PR 2; nothing has read this value since.
-- Removes the stored row so Studio → Settings no longer lists it.
--
-- Portable and idempotent.

DELETE FROM se_settings WHERE setting_key = 'envision_department_id';
