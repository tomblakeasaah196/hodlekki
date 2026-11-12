-- /db/migrations/20261202090100_perm_zoe_owner.sql
--
-- Permissions PR 3: the Zoe department owns the Zoe module.
--
-- Notifications and the Zoe roster no longer find "the Zoe department" by
-- name or by id in code; they ask which departments own the module
-- (perm_module_leaders / perm_module_owners). Zoe was owned only by "every
-- department" (0), which is not a team. Adding the Zoe department as an owner
-- changes nobody's access: the 0 row already makes Zoe's seat cells apply in
-- every department. Matched the way the day-one seed matched departments.
--
-- Portable and idempotent.

INSERT INTO perm_module_owners (module_key, department_id)
SELECT 'zoe', d.id
  FROM departments d
 WHERE (d.id = 7 OR d.name LIKE '%Zoe%')
   AND NOT EXISTS (SELECT 1 FROM perm_module_owners o WHERE o.module_key = 'zoe' AND o.department_id = d.id);
