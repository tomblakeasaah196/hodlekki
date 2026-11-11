-- 20261126090100_perm_platform_columns.sql
-- Roles & permissions, part 2 of 3: what the matrix needs from existing tables.
--
--   roles             label (renaming a role changes nothing: code keys on the
--                     id), description, is_break_glass (Super Admin: sees
--                     everything, every use logged), is_system, is_archived,
--                     appoint_rank (a role's standing when appointing seats:
--                     Resident Pastor 80, Assoc Pastor 70 — above the Pastor in
--                     charge seat at 60, so pastors keep appointing as today),
--                     sort_order, created_by/at. role_name stays as the frozen
--                     legacy key.
--   user_roles        starts_on / ends_on (inclusive last day), assigned_by/at,
--                     reminded_at. is_frozen keeps its meaning.
--   user_departments  reminded_at. role_in_dept becomes VARCHAR(30): production
--                     declares it ENUM('Worker','Sub_Unit_Head','HOD','Director',
--                     'Assoc_Pastor'), which cannot store the new Deputy_HOD seat
--                     — nor the 'Member' seat the Departments screen already
--                     offers. Existing values are kept as they are.
--
-- Then three one-time data steps, each guarded so this file applies on any
-- schema (the integration harness has only stand-ins) and re-runs as a no-op:
--   * the Finance department owns requisition disbursement and the Charis
--     countersign (decision 3: the Finance Director is that department's
--     Director seat);
--   * that seat is pre-filled with the account charis_api.php hard-codes today
--     (id 22) — only if the seat is empty in the live ministry year and that
--     account exists. Expected to be a no-op: the account already holds it;
--   * role_assignment_logs is copied into perm_history so History starts whole.
--
-- MySQL/MariaDB only (information_schema + PREPARE, no DELIMITER), like
-- 20261111090000_department_ministry_years.sql. The SQLite tests stand these
-- columns in instead.

SET @db := DATABASE();

-- ---------------------------------------------------------------------------
-- 1. roles
-- ---------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'label') = 0,
    'ALTER TABLE roles ADD COLUMN label VARCHAR(80) NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'description') = 0,
    'ALTER TABLE roles ADD COLUMN description VARCHAR(255) NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'is_break_glass') = 0,
    'ALTER TABLE roles ADD COLUMN is_break_glass TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'is_system') = 0,
    'ALTER TABLE roles ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'is_archived') = 0,
    'ALTER TABLE roles ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'appoint_rank') = 0,
    'ALTER TABLE roles ADD COLUMN appoint_rank SMALLINT NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'sort_order') = 0,
    'ALTER TABLE roles ADD COLUMN sort_order SMALLINT NOT NULL DEFAULT 0', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'created_by') = 0,
    'ALTER TABLE roles ADD COLUMN created_by INT NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'roles' AND column_name = 'created_at') = 0,
    'ALTER TABLE roles ADD COLUMN created_at DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

-- ---------------------------------------------------------------------------
-- 2. user_roles
-- ---------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'starts_on') = 0,
    'ALTER TABLE user_roles ADD COLUMN starts_on DATE NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'ends_on') = 0,
    'ALTER TABLE user_roles ADD COLUMN ends_on DATE NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'assigned_by') = 0,
    'ALTER TABLE user_roles ADD COLUMN assigned_by INT NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'assigned_at') = 0,
    'ALTER TABLE user_roles ADD COLUMN assigned_at DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_roles') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_roles' AND column_name = 'reminded_at') = 0,
    'ALTER TABLE user_roles ADD COLUMN reminded_at DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

-- ---------------------------------------------------------------------------
-- 3. user_departments
-- ---------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'user_departments') = 1
    AND (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_departments' AND column_name = 'reminded_at') = 0,
    'ALTER TABLE user_departments ADD COLUMN reminded_at DATETIME NULL DEFAULT NULL', 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_departments'
                  AND column_name = 'role_in_dept' AND data_type = 'enum') = 1,
    "ALTER TABLE user_departments MODIFY COLUMN role_in_dept VARCHAR(30) NULL DEFAULT 'Worker'", 'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

-- ---------------------------------------------------------------------------
-- 4. Role flags (data). Labels are what people see; nothing keys on them.
-- ---------------------------------------------------------------------------

UPDATE roles SET label = REPLACE(role_name, '_', ' ') WHERE label IS NULL OR label = '';
UPDATE roles SET is_break_glass = 1, is_system = 1 WHERE role_name = 'Super_Admin';
UPDATE roles SET is_system = 1 WHERE role_name IN ('Resident_Pastor', 'Assoc_Pastor');
UPDATE roles SET appoint_rank = 80 WHERE role_name = 'Resident_Pastor' AND appoint_rank IS NULL;
UPDATE roles SET appoint_rank = 70 WHERE role_name = 'Assoc_Pastor' AND appoint_rank IS NULL;

-- ---------------------------------------------------------------------------
-- 5. The Finance department (resolved by name ONCE, here, to its id).
-- ---------------------------------------------------------------------------

SET @has_type := (SELECT COUNT(*) FROM information_schema.columns
                   WHERE table_schema = @db AND table_name = 'departments' AND column_name = 'type');

SET @sql := IF(@has_type = 1,
    "INSERT INTO perm_module_owners (module_key, department_id)
     SELECT m.module_key, d.id
       FROM departments d
       JOIN (SELECT 'requisition.disburse' AS module_key UNION ALL SELECT 'finance.countersign') m
      WHERE d.name = 'Finance' AND d.type = 'Administrative'
        AND NOT EXISTS (SELECT 1 FROM perm_module_owners o WHERE o.module_key = m.module_key AND o.department_id = d.id)",
    'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;

SET @year_ready := (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'ministry_years')
                 + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = @db AND table_name = 'user_departments'
                     AND column_name IN ('ministry_year_id', 'roster_status', 'membership_type', 'season'));

SET @sql := IF(@has_type = 1 AND @year_ready = 5,
    "INSERT INTO user_departments (user_id, department_id, role_in_dept, membership_type, season, ministry_year_id, roster_status, is_active)
     SELECT u.id, d.id, 'Director', 'Primary', YEAR(y.start_date), y.id, 'Active', 1
       FROM users u
       JOIN departments d ON d.name = 'Finance' AND d.type = 'Administrative'
       JOIN ministry_years y ON y.is_active = 1
      WHERE u.id = 22
        AND NOT EXISTS (SELECT 1 FROM user_departments s
                         WHERE s.department_id = d.id AND s.ministry_year_id = y.id
                           AND s.role_in_dept = 'Director' AND s.roster_status = 'Active')
        AND NOT EXISTS (SELECT 1 FROM user_departments s
                         WHERE s.department_id = d.id AND s.ministry_year_id = y.id AND s.user_id = u.id)
      ORDER BY y.start_date DESC, d.id ASC
      LIMIT 1",
    'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s;
SET @prefilled := ROW_COUNT();
DEALLOCATE PREPARE perm_s;

INSERT INTO perm_history (actor_label, via, change_type, subject_user_id, department_id, seat_key, after_json, reason)
SELECT 'migration', 'migration', 'seat.add', 22,
       (SELECT MIN(d.id) FROM departments d WHERE d.name = 'Finance'),
       'director', '{"seat":"director"}',
       'Finance Director seat pre-filled from the account charis_api.php hard-coded (decision 3)'
  FROM (SELECT 1 AS one) AS seed
 WHERE @prefilled > 0;

-- ---------------------------------------------------------------------------
-- 6. History starts whole: copy the old role log once.
-- ---------------------------------------------------------------------------

SET @sql := IF((SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = @db AND table_name = 'role_assignment_logs') = 1,
    "INSERT INTO perm_history (created_at, actor_user_id, via, change_type, subject_user_id, role_id, reason)
     SELECT COALESCE(l.created_at, NOW()), l.performed_by, 'legacy_log',
            CASE l.action_type
                WHEN 'Granted' THEN 'user_role.add'
                WHEN 'Revoked' THEN 'user_role.remove'
                WHEN 'Frozen' THEN 'user_role.freeze'
                WHEN 'Unfrozen' THEN 'user_role.unfreeze'
                ELSE 'user_role.title'
            END,
            l.target_user_id, l.role_id, LEFT(l.action_details, 255)
       FROM role_assignment_logs l
      WHERE NOT EXISTS (SELECT 1 FROM perm_history h WHERE h.via = 'legacy_log')
      ORDER BY l.id",
    'DO 0');
PREPARE perm_s FROM @sql; EXECUTE perm_s; DEALLOCATE PREPARE perm_s;
