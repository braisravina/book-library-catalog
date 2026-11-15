-- Household access control, phase H1 (2 of 3): inventory belongs to a household.
--
-- Additive only: a NULLABLE household_id on copies and locations, with
-- indexes and foreign keys. No existing column, index or constraint is
-- changed, so the previous application version keeps working on this
-- schema (its INSERT statements name their columns and simply leave
-- household_id NULL).
--
-- The column stays nullable on purpose (expand phase). NOT NULL and any
-- change to uniqueness are a separate, later migration (phase H4), written
-- only after rollback compatibility has been evaluated. Until then a NULL
-- household means "not assigned": no household member may rely on it.
--
-- ON DELETE RESTRICT: a household that still holds inventory cannot be
-- deleted.
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4.

ALTER TABLE copies
    ADD COLUMN household_id INT UNSIGNED NULL AFTER owner_user_id,
    ADD KEY idx_copies_household_edition (household_id, edition_id),
    ADD KEY idx_copies_edition_household (edition_id, household_id),
    ADD CONSTRAINT fk_copies_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE RESTRICT;

ALTER TABLE locations
    ADD COLUMN household_id INT UNSIGNED NULL AFTER parent_id,
    ADD KEY idx_locations_household (household_id),
    ADD CONSTRAINT fk_locations_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE RESTRICT;
