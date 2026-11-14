-- Household access control, phase H3 (1 of 4): inventory codes are unique
-- within a household instead of across the whole installation.
--
-- Two households may use the same inventory code; inside one household a
-- code still identifies one copy. The database enforces it:
--
--   * inventory_scope_id is a stored generated column: the household of
--     the copy, or 0 for a copy that has no household. NULL never reaches
--     the unique index (two NULLs never collide in a UNIQUE key, so a
--     plain (household_id, inventory_code) index would let unassigned
--     copies repeat a code). Copies without a household therefore form one
--     scope of their own, in which codes stay unique.
--   * uq_copies_scope_inventory_code (inventory_scope_id, inventory_code)
--     replaces uq_copies_inventory_code (inventory_code). Copies without a
--     code (NULL) are unaffected, as before.
--
-- Existing data: every code is unique across all copies today, so it is
-- unique within each scope and the new index is always built. No row, no
-- inventory code and no household is changed; row_version and updated_at
-- are untouched.
--
-- This is the one place where an index is removed. It is a single ALTER,
-- which InnoDB applies atomically: the new index exists before the old
-- one is gone, or nothing changes. household_id stays NULLABLE.
--
-- Rollback compatibility: the previous application version keeps working
-- on this schema. Its INSERT and UPDATE statements name their columns (a
-- generated column is never written), and its own "code already in use"
-- check is stricter than the index (it looks at every household).
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4 (an integer
-- generated column; the foreign key on household_id is ON DELETE RESTRICT,
-- which both engines allow on the base column of a generated column).

ALTER TABLE copies
    ADD COLUMN inventory_scope_id INT UNSIGNED GENERATED ALWAYS AS (COALESCE(household_id, 0)) STORED AFTER household_id,
    ADD UNIQUE KEY uq_copies_scope_inventory_code (inventory_scope_id, inventory_code),
    DROP INDEX uq_copies_inventory_code;
