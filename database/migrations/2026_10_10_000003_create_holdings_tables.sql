-- Physical holdings: where things are, who owns which copy, and collections.

-- Hierarchical storage locations, e.g. House > Study > Bookcase A > Shelf 3.
CREATE TABLE locations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    kind ENUM('site', 'room', 'bookcase', 'shelf', 'box', 'other') NOT NULL DEFAULT 'other',
    description VARCHAR(500) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_parent_name (parent_id, name),
    CONSTRAINT fk_locations_parent FOREIGN KEY (parent_id) REFERENCES locations (id) ON DELETE RESTRICT,
    CONSTRAINT fk_locations_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- One row per physical book. Several users can own copies of the same
-- edition without duplicating the bibliographic record. Users are
-- deactivated rather than deleted, so ownership is RESTRICTed.
CREATE TABLE copies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    edition_id INT UNSIGNED NOT NULL,
    owner_user_id INT UNSIGNED NOT NULL,
    location_id INT UNSIGNED NULL,
    inventory_code VARCHAR(64) NULL,
    copy_condition ENUM('new', 'fine', 'very_good', 'good', 'fair', 'poor') NULL,
    status ENUM('available', 'on_loan', 'lost', 'withdrawn') NOT NULL DEFAULT 'available',
    is_signed TINYINT(1) NOT NULL DEFAULT 0,
    acquired_on DATE NULL,
    acquisition_source VARCHAR(255) NULL,
    purchase_price_cents INT UNSIGNED NULL,
    currency CHAR(3) NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_copies_inventory_code (inventory_code),
    KEY idx_copies_edition (edition_id),
    KEY idx_copies_owner (owner_user_id),
    KEY idx_copies_location (location_id),
    KEY idx_copies_status (status),
    CONSTRAINT chk_copies_currency CHECK (currency IS NULL OR currency REGEXP '^[A-Z]{3}$'),
    CONSTRAINT fk_copies_edition FOREIGN KEY (edition_id) REFERENCES editions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_copies_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_copies_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL,
    CONSTRAINT fk_copies_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_copies_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE collections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_user_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    visibility ENUM('private', 'shared') NOT NULL DEFAULT 'shared',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_collections_owner_name (owner_user_id, name),
    CONSTRAINT fk_collections_owner FOREIGN KEY (owner_user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE collection_items (
    collection_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NOT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 0,
    added_by INT UNSIGNED NULL,
    added_at DATETIME NOT NULL,
    PRIMARY KEY (collection_id, edition_id),
    KEY idx_collection_items_edition (edition_id),
    CONSTRAINT fk_collection_items_collection FOREIGN KEY (collection_id) REFERENCES collections (id) ON DELETE CASCADE,
    CONSTRAINT fk_collection_items_edition FOREIGN KEY (edition_id) REFERENCES editions (id) ON DELETE CASCADE,
    CONSTRAINT fk_collection_items_added_by FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
