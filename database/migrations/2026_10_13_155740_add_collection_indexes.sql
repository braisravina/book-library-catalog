-- Phase 4A: indexes for collection browsing (tables exist since Phase 1).
-- Additive only.

CREATE INDEX idx_collection_items_position ON collection_items (collection_id, position);
CREATE INDEX idx_collections_visibility ON collections (visibility, name);
