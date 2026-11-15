-- H4: additive location optimistic locking. All existing fields and IDs survive.
-- Old named-column writers remain schema compatible, but cannot provide H4 stale-form
-- guarantees. Keep household_id nullable. Portable MariaDB 11.4 / MySQL 8.4.
ALTER TABLE locations ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1;
