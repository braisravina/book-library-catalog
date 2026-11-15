-- Household access control, phase H1 (3 of 3): move existing records into
-- a temporary default household.
--
-- Data only (no DDL). Every statement is idempotent, so the file can be
-- run again safely if it is interrupted: it never touches a row that
-- already has a household and never duplicates a membership.
--
--   1. The default household (system_key 'default') is created if missing.
--      Its name is a placeholder: the owner names the real households and
--      distributes the inventory later.
--   2. Every copy and location without a household is assigned to it. No
--      row is deleted and no other column is changed (row_version and
--      updated_at included).
--   3. Existing users keep the access they have today: accounts that are
--      active AND have signed in at least once become members. Disabled
--      accounts and accounts that have never signed in are NOT added, and
--      nothing here applies to accounts created later: a new account
--      belongs to no household until an administrator assigns one.
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4.

INSERT INTO households (name, description, system_key, created_at, updated_at)
SELECT 'Default household', 'Temporary household holding the records that existed before households were introduced.', 'default', UTC_TIMESTAMP(), UTC_TIMESTAMP()
FROM (SELECT 1 AS one) AS seed
WHERE NOT EXISTS (SELECT 1 FROM households WHERE system_key = 'default');

UPDATE copies
SET household_id = (SELECT id FROM households WHERE system_key = 'default')
WHERE household_id IS NULL;

UPDATE locations
SET household_id = (SELECT id FROM households WHERE system_key = 'default')
WHERE household_id IS NULL;

INSERT INTO household_members (household_id, user_id, added_by, created_at)
SELECT h.id, u.id, NULL, UTC_TIMESTAMP()
FROM users u
JOIN households h ON h.system_key = 'default'
WHERE u.is_active = 1
  AND u.last_login_at IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM household_members m WHERE m.household_id = h.id AND m.user_id = u.id);
