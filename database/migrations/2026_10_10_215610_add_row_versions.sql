-- Phase 3: optimistic concurrency control. Every update of a work, edition
-- or physical copy must name the version it was based on; a stale edit is
-- rejected instead of silently overwriting someone else's change.
-- Additive only; existing rows start at version 1.

ALTER TABLE works ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER description;
ALTER TABLE editions ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER description;
ALTER TABLE copies ADD COLUMN row_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER notes;
