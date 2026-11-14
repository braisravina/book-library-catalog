-- Household access control, phase H3 (3 of 4): existing shared collections
-- go to the temporary default household.
--
-- Data only (no DDL), idempotent: it touches only shared collections that
-- have no household yet. Private collections are not changed in any way:
-- no collection becomes shared, and no visibility value is rewritten.
-- updated_at is left as it is.
--
-- Until households are enabled every shared collection is visible to
-- every user, exactly as before; afterwards the members of the default
-- household (the accounts that existed when households were introduced)
-- keep seeing them.
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4.

UPDATE collections
SET household_id = (SELECT id FROM households WHERE system_key = 'default')
WHERE visibility = 'shared' AND household_id IS NULL;
