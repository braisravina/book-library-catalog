-- Household inventory management H4: additive durable plans and results.
-- No existing inventory, identifiers or memberships are changed. No down migration.
-- Snapshots are application-decoded text, with no engine-specific JSON operators.
-- Evidence survives deletion of an inventory record: item identifiers are snapshots,
-- deliberately not cascading foreign keys. Administrators are deactivated, not deleted.
CREATE TABLE inventory_transfers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id INT UNSIGNED NOT NULL,
    kind VARCHAR(16) NOT NULL,
    source_household_id INT UNSIGNED NULL,
    destination_household_id INT UNSIGNED NULL,
    reason VARCHAR(1000) NOT NULL,
    payload MEDIUMTEXT NOT NULL,
    digest CHAR(64) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'planned',
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_inventory_transfers_created (created_at, id),
    CONSTRAINT fk_inventory_transfers_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT chk_inventory_transfers_kind CHECK (kind IN ('copies', 'subtree', 'owner')),
    CONSTRAINT chk_inventory_transfers_status CHECK (status IN ('planned', 'completed', 'cancelled'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE inventory_transfer_items (
    operation_id INT UNSIGNED NOT NULL,
    copy_id INT UNSIGNED NOT NULL,
    expected_version INT UNSIGNED NOT NULL,
    source_location_id INT UNSIGNED NULL,
    destination_location_id INT UNSIGNED NULL,
    owner_user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (operation_id, copy_id),
    CONSTRAINT fk_inventory_transfer_items_operation FOREIGN KEY (operation_id) REFERENCES inventory_transfers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
