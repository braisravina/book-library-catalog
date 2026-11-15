-- Household access control, phase H3 (2 of 4): a shared collection is
-- shared with one explicitly chosen household.
--
-- Additive only: a NULLABLE household_id on collections, with an index and
-- a foreign key. Private collections leave it NULL. Nothing existing is
-- changed, so the previous application version keeps working on this
-- schema (it leaves the column NULL; a shared collection without a
-- household is then seen by its owner and administrators only once
-- households are enabled, never by more people than before).
--
-- ON DELETE RESTRICT: a household that still has shared collections
-- cannot be deleted (as with copies and locations).
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4.

ALTER TABLE collections
    ADD COLUMN household_id INT UNSIGNED NULL AFTER visibility,
    ADD KEY idx_collections_household (household_id, visibility, name),
    ADD CONSTRAINT fk_collections_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE RESTRICT;
