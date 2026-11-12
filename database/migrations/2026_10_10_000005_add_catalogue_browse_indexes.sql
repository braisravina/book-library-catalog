-- Phase 2: indexes for catalogue browsing, sorting and find-or-create lookups.
-- Additive only; no existing table definitions change.

CREATE INDEX idx_authors_name ON authors (name(191));
CREATE INDEX idx_editions_title ON editions (title(191));
CREATE INDEX idx_editions_created_at ON editions (created_at);
CREATE INDEX idx_copies_edition_owner ON copies (edition_id, owner_user_id);
