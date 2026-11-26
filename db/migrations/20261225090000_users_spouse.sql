-- /db/migrations/20261225090000_users_spouse.sql
--
-- Couples in church (owner, 26 Nov 2026). When a married person's spouse is
-- also in the register, Congregation links the two: users.spouse_id holds the
-- other person's users.id, on BOTH rows, and both carry the same wedding
-- anniversary. Charis then shows one wedding anniversary line per couple
-- ("Pastor Ebele & Min. Bolanle") instead of one per person.
--
-- NULL = no spouse in the register (single, or married to someone who is not
-- a member). Kept in step only by includes/couples.php; deleting a person
-- clears the link on their spouse. No foreign key, as elsewhere.
--
-- Guarded by information_schema checks and run through PREPARE (plain
-- ;-terminated SQL, no DELIMITER block). Re-running the file is a no-op.

SET @db := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.columns
      WHERE table_schema = @db AND table_name = 'users' AND column_name = 'spouse_id') = 0,
    'ALTER TABLE users ADD COLUMN spouse_id INT NULL DEFAULT NULL AFTER wedding_anniversary',
    'DO 0'
);
PREPARE users_spouse_col FROM @sql;
EXECUTE users_spouse_col;
DEALLOCATE PREPARE users_spouse_col;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.statistics
      WHERE table_schema = @db AND table_name = 'users' AND index_name = 'ix_users_spouse') = 0,
    'ALTER TABLE users ADD KEY ix_users_spouse (spouse_id)',
    'DO 0'
);
PREPARE users_spouse_ix FROM @sql;
EXECUTE users_spouse_ix;
DEALLOCATE PREPARE users_spouse_ix;
