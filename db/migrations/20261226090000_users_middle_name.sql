-- /db/migrations/20261226090000_users_middle_name.sql
--
-- A person's middle name (owner, 28 Nov 2026): optional, but kept on the
-- record and shown wherever the register's full name is (Congregation, the
-- person's details, Profile, Embrace, exports, certificates, search).
--
-- NULL = none given. Written only through includes/person_name.php's
-- person_middle_clean() (trimmed, single-spaced, at most 100 characters,
-- blank becomes NULL). Code shipped before this file runs selects NULL in its
-- place (person_middle_sql()), so the deploy gap never fails a page.
--
-- Guarded by an information_schema check and run through PREPARE (plain
-- ;-terminated SQL, no DELIMITER block). Re-running the file is a no-op.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'middle_name') = 0,
    'ALTER TABLE users ADD COLUMN middle_name VARCHAR(100) NULL DEFAULT NULL AFTER first_name',
    'DO 0'
);
PREPARE users_middle_col FROM @sql;
EXECUTE users_middle_col;
DEALLOCATE PREPARE users_middle_col;
