-- 20261126090200_perm_day_one.sql
-- Roles & permissions, part 3 of 3: the day-one matrix.
--
-- Every cell reproduces what a person can actually do today, module by module,
-- as audited line by line (design notes: permissions-design.md §8; the frozen
-- copy of today's rules is includes/permissions/legacy_rules.php, and
-- bin/permissions_parity.php proves the two agree person by person).
--
-- Role names, department ids and department names appear ONCE, here, and are
-- resolved to ids as the file runs. After that nothing keys on a name: renaming
-- a role or a department changes no one's access.
--
-- Lead decisions built in (11 Nov 2026):
--   * The global Director / HOD roles carry no cells. What they gave is on the
--     Director / HOD SEAT columns (owner "every department", same scope), so
--     access comes only from the live roster (decision 2).
--   * Deputy HOD = the HOD column in the department's own modules, capped at
--     edit, no approve / manage, never below the Worker column (decision 5).
--   * Resident Pastor gets "Roles & permissions: View"; Manage stays with the
--     break-glass Super Admin (decision 7).
--
-- Portable SQL (MySQL, MariaDB and the SQLite tests): no ON DUPLICATE KEY, no
-- INSERT IGNORE — every insert is INSERT ... SELECT ... WHERE NOT EXISTS, so
-- re-running is a no-op and never overwrites a cell someone has since changed.
-- Levels: 0 none, 1 view, 2 create, 3 edit, 4 delete.

-- ---------------------------------------------------------------------------
-- 1. Seat types. seat_key is stable forever (other modules reference it);
--    roster_value is what user_departments.role_in_dept stores.
-- ---------------------------------------------------------------------------

INSERT INTO perm_seat_types (seat_key, roster_value, label, seat_rank, is_leader, covers_sub_units, max_holders, can_appoint, sort_order)
SELECT t.seat_key, t.roster_value, t.label, t.seat_rank, t.is_leader, t.covers_sub_units, t.max_holders, t.can_appoint, t.sort_order
  FROM (
        SELECT 'pastor_in_charge' AS seat_key, 'Assoc_Pastor' AS roster_value, 'Pastor in Charge' AS label,
               60 AS seat_rank, 1 AS is_leader, 1 AS covers_sub_units, 1 AS max_holders, 1 AS can_appoint, 10 AS sort_order
        UNION ALL SELECT 'director', 'Director', 'Director in Charge', 50, 1, 1, 1, 1, 20
        UNION ALL SELECT 'hod', 'HOD', 'Head of Department', 40, 1, 1, 1, 1, 30
        UNION ALL SELECT 'deputy_hod', 'Deputy_HOD', 'Deputy HOD', 35, 1, 1, 2, 0, 40
        UNION ALL SELECT 'sub_unit_head', 'Sub_Unit_Head', 'Sub-Unit Head', 30, 1, 1, NULL, 1, 50
        UNION ALL SELECT 'worker', 'Worker', 'Worker', 20, 0, 0, NULL, 0, 60
        UNION ALL SELECT 'member', 'Member', 'Member', 10, 0, 0, NULL, 0, 70
       ) t
 WHERE NOT EXISTS (SELECT 1 FROM perm_seat_types s WHERE s.seat_key = t.seat_key);

-- ---------------------------------------------------------------------------
-- 2. Seats in use: today any seat is possible in any department, so every
--    department starts with every seat type. The owner unticks what a
--    department does not have (e.g. HOD for Envision).
-- ---------------------------------------------------------------------------

INSERT INTO perm_department_seats (department_id, seat_key, is_enabled, max_holders)
SELECT d.id, t.seat_key, 1, NULL
  FROM departments d
 CROSS JOIN perm_seat_types t
 WHERE NOT EXISTS (SELECT 1 FROM perm_department_seats x WHERE x.department_id = d.id AND x.seat_key = t.seat_key);

-- ---------------------------------------------------------------------------
-- 3. Module owners: the departments whose seats get the seat columns of a
--    module. 0 = every department. Ids where today's code uses ids, names where
--    it uses names — resolved here, once.
-- ---------------------------------------------------------------------------

INSERT INTO perm_module_owners (module_key, department_id)
SELECT o.module_key, o.department_id
  FROM (
        SELECT 'idi' AS module_key, d.id AS department_id FROM departments d WHERE d.id = 1
        UNION SELECT 'reach', d.id FROM departments d WHERE d.name LIKE '%Reach%' OR d.name LIKE '%Evangelism%'
        UNION SELECT 'embrace', d.id FROM departments d WHERE d.id IN (1, 2)
        UNION SELECT 'charis', d.id FROM departments d
               WHERE d.id IN (1, 10) OR d.name LIKE '%Charis%' OR d.name LIKE '%Welfare%' OR d.name = 'IDI'
        UNION SELECT 'academy', d.id FROM departments d WHERE d.name LIKE '%Academy%' OR d.name LIKE '%School%'
        UNION SELECT 'junior_church', d.id FROM departments d WHERE d.id IN (1, 11)
        UNION SELECT 'river_of_life', d.id FROM departments d WHERE d.id = 4
        UNION SELECT 'hymnal', d.id FROM departments d
               WHERE d.id = (SELECT MIN(r.id) FROM departments r WHERE r.name LIKE '%River of Life%')
                  OR (d.id = 4 AND NOT EXISTS (SELECT 1 FROM departments r WHERE r.name LIKE '%River of Life%'))
        UNION SELECT 'envision', d.id FROM departments d WHERE d.name LIKE '%Envision%'
        UNION SELECT 'special_events', d.id FROM departments d WHERE d.id = 3
        UNION SELECT 'assets', d.id FROM departments d
               WHERE d.name LIKE '%IDI%' OR d.name LIKE '%Envision%' OR d.name LIKE '%River of Life%'
        UNION SELECT 'assimilation', 0
        UNION SELECT 'congregation', 0
        UNION SELECT 'events', 0
        UNION SELECT 'contact_extractor', 0
        UNION SELECT 'finance', 0
        UNION SELECT 'finance.countersign', 0
        UNION SELECT 'pastoral', 0
        UNION SELECT 'testimonies', 0
        UNION SELECT 'regions', 0
        UNION SELECT 'zoe', 0
        UNION SELECT 'announcements', 0
        UNION SELECT 'sms_studio', 0
       ) o
 WHERE NOT EXISTS (SELECT 1 FROM perm_module_owners p WHERE p.module_key = o.module_key AND p.department_id = o.department_id);

-- ---------------------------------------------------------------------------
-- 4. Role columns. Found by today's role name, stored by role id.
--    Super_Admin has no cells: it is break-glass (roles.is_break_glass).
-- ---------------------------------------------------------------------------

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT r.id, '', c.module_key, c.level, c.a, c.x, c.m, c.scope, c.view_scope
  FROM roles r
  JOIN (
        SELECT 'Resident_Pastor' AS role_name, 'dashboard' AS module_key, 1 AS level, 0 AS a, 0 AS x, 0 AS m,
               'church' AS scope, NULL AS view_scope
        UNION ALL SELECT 'Assoc_Pastor', 'dashboard', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'idi', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'idi', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'congregation', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'congregation', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'reach', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'reach', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'embrace', 4, 1, 1, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'embrace', 4, 1, 1, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'assimilation', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'assimilation', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'charis', 4, 1, 1, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'charis', 4, 1, 1, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'academy', 4, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'academy', 4, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'junior_church', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'junior_church', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'river_of_life', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'river_of_life', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'envision', 4, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'envision', 4, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'special_events', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'special_events', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'zoe', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'zoe', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'departments', 3, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'departments', 3, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'tribes', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'tribes', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'events', 4, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'events', 4, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'events.private_notes', 3, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'events.private_notes', 3, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'contact_extractor', 1, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'contact_extractor', 1, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'sms_studio', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'sms_studio', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'assets', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'assets', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'pastoral', 3, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'pastoral', 3, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'announcements', 4, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'announcements', 4, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'testimonies', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'testimonies', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'hymnal', 3, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'hymnal', 3, 1, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'regions', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Assoc_Pastor', 'regions', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'finance', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'requisition', 2, 1, 1, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'requisition.disburse', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'roles', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'security', 3, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'alerts', 1, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'Resident_Pastor', 'bible', 1, 0, 0, 1, 'church', NULL
       ) c ON c.role_name = r.role_name
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = r.id AND g.seat_key = '' AND g.module_key = c.module_key);

-- ---------------------------------------------------------------------------
-- 5. Seat columns and the Everyone column ('@everyone': every signed-in
--    member). A seat cell applies in the module's owner departments (or, for a
--    partitioned module — dashboard, departments, requisition — in the seat's
--    own department). scope 'dept' on a module whose data is not split by
--    department means "the whole module": the department's data IS the module.
-- ---------------------------------------------------------------------------

INSERT INTO perm_grants (role_id, seat_key, module_key, level, can_approve, can_export, can_manage, scope, view_scope)
SELECT 0, c.seat_key, c.module_key, c.level, c.a, c.x, c.m, c.scope, c.view_scope
  FROM (
        -- Everyone: own records, plus what every member can read today.
        SELECT '@everyone' AS seat_key, 'dashboard' AS module_key, 1 AS level, 0 AS a, 0 AS x, 0 AS m,
               'own' AS scope, NULL AS view_scope
        UNION ALL SELECT '@everyone', 'member_portal', 3, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'profile', 3, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'notifications', 3, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'bible', 3, 0, 0, 0, 'own', 'church'
        UNION ALL SELECT '@everyone', 'hymnal', 1, 0, 0, 0, 'church', NULL
        UNION ALL SELECT '@everyone', 'library', 2, 0, 0, 0, 'own', 'church'
        UNION ALL SELECT '@everyone', 'regions', 2, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'academy', 2, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'pastoral', 2, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'announcements', 2, 0, 0, 0, 'own', 'church'
        UNION ALL SELECT '@everyone', 'zoe', 2, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'tribes', 2, 0, 0, 0, 'own', NULL
        UNION ALL SELECT '@everyone', 'requisition', 1, 0, 0, 0, 'own', NULL

        -- What the retired global Director role gave, now on the Director seat
        -- (owner: every department; same church-wide scope as before).
        UNION ALL SELECT 'director', 'congregation', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'events', 4, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'director', 'contact_extractor', 1, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'director', 'finance', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'finance.countersign', 0, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'pastoral', 3, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'testimonies', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'regions', 4, 0, 1, 1, 'church', NULL
        UNION ALL SELECT 'director', 'zoe', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'director', 'announcements', 4, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'director', 'sms_studio', 4, 0, 0, 1, 'church', NULL
        -- ... and the retired global HOD role, on the HOD seat.
        UNION ALL SELECT 'hod', 'congregation', 4, 0, 0, 0, 'church', NULL
        UNION ALL SELECT 'hod', 'events', 4, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'hod', 'contact_extractor', 1, 0, 1, 0, 'church', NULL
        UNION ALL SELECT 'hod', 'finance.countersign', 0, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'hod', 'zoe', 4, 0, 0, 1, 'church', NULL
        UNION ALL SELECT 'hod', 'announcements', 4, 1, 0, 0, 'church', NULL
        UNION ALL SELECT 'hod', 'sms_studio', 4, 0, 0, 1, 'church', NULL

        -- Assimilation managers: a live Director / HOD seat in any department.
        UNION ALL SELECT 'director', 'assimilation', 4, 0, 1, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'assimilation', 4, 0, 1, 1, 'dept', NULL

        -- Partitioned modules: each seat in its own department.
        UNION ALL SELECT 'pastor_in_charge', 'dashboard', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'dashboard', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'dashboard', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'dashboard', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'pastor_in_charge', 'departments', 3, 0, 1, 0, 'dept', 'church'
        UNION ALL SELECT 'director', 'departments', 3, 0, 1, 0, 'dept', 'church'
        UNION ALL SELECT 'hod', 'departments', 3, 0, 1, 0, 'dept', 'church'
        UNION ALL SELECT 'deputy_hod', 'departments', 3, 0, 1, 0, 'dept', 'church'
        UNION ALL SELECT 'pastor_in_charge', 'requisition', 2, 0, 0, 0, 'dept', 'own'
        UNION ALL SELECT 'director', 'requisition', 2, 1, 0, 0, 'dept', 'dept'
        UNION ALL SELECT 'hod', 'requisition', 2, 0, 0, 0, 'dept', 'own'
        UNION ALL SELECT 'deputy_hod', 'requisition', 2, 0, 0, 0, 'dept', 'own'

        -- Department-owned modules (owners in section 3).
        UNION ALL SELECT 'pastor_in_charge', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'idi', 1, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'idi', 1, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'reach', 4, 0, 1, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'reach', 4, 0, 1, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'reach', 4, 0, 1, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'reach', 4, 0, 1, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'reach', 4, 0, 1, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'reach', 4, 0, 1, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'reach', 4, 0, 1, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'director', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'worker', 'embrace', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'member', 'embrace', 4, 1, 1, 1, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'charis', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'charis', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'charis', 4, 1, 1, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'charis', 3, 0, 1, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'charis', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'charis', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'charis', 3, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'director', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'worker', 'academy', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'member', 'academy', 4, 1, 0, 1, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'junior_church', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'junior_church', 4, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'river_of_life', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'river_of_life', 4, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'hymnal', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'hymnal', 3, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'director', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'worker', 'envision', 4, 1, 0, 1, 'dept', NULL
        UNION ALL SELECT 'member', 'envision', 4, 1, 0, 1, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'special_events', 2, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'special_events', 4, 0, 0, 1, 'dept', NULL
        UNION ALL SELECT 'hod', 'special_events', 4, 0, 0, 1, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'special_events', 3, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'special_events', 2, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'special_events', 2, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'special_events', 2, 0, 0, 0, 'dept', NULL

        UNION ALL SELECT 'pastor_in_charge', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'director', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'hod', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'deputy_hod', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'sub_unit_head', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'worker', 'assets', 4, 0, 0, 0, 'dept', NULL
        UNION ALL SELECT 'member', 'assets', 4, 0, 0, 0, 'dept', NULL

        -- The Finance Director: the Finance department's Director seat (owner
        -- row written by 20261126090100_perm_platform_columns.sql).
        UNION ALL SELECT 'director', 'requisition.disburse', 3, 0, 0, 0, 'dept', NULL
       ) c
 WHERE NOT EXISTS (SELECT 1 FROM perm_grants g
                    WHERE g.role_id = 0 AND g.seat_key = c.seat_key AND g.module_key = c.module_key);

-- ---------------------------------------------------------------------------
-- 6. Switches. Seat end dates are enforced only once the reminders and the
--    renew/roll-over actions exist (PR 3 sets this to 1).
-- ---------------------------------------------------------------------------

INSERT INTO perm_config (cfg_key, cfg_value)
SELECT 'expiry_enforced', '0'
  FROM (SELECT 1 AS one) AS seed
 WHERE NOT EXISTS (SELECT 1 FROM perm_config WHERE cfg_key = 'expiry_enforced');
