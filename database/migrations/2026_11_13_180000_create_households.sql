-- Household access control, phase H1 (1 of 3): households and memberships.
--
-- Additive only: two new tables, nothing existing is changed. A household is
-- the access boundary for physical inventory (copies and locations); users
-- may belong to several households. Global roles are unchanged: there are
-- no per-household roles.
--
-- system_key marks households the application itself relies on. The only
-- one is 'default', the temporary household that existing records are
-- moved into by the backfill migration; ordinary households leave it NULL
-- (a UNIQUE key allows any number of NULLs on MySQL and MariaDB).
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4 (no generated
-- columns, no engine-specific syntax).

CREATE TABLE households (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(1000) NULL,
    system_key VARCHAR(32) NULL,
    row_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_households_name (name),
    UNIQUE KEY uq_households_system_key (system_key),
    CONSTRAINT fk_households_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_households_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- A household with members, copies or locations cannot be deleted by
-- accident: memberships are removed explicitly (and audited) by the
-- application before the household row.
CREATE TABLE household_members (
    household_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    added_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (household_id, user_id),
    KEY idx_household_members_user (user_id, household_id),
    CONSTRAINT fk_household_members_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE RESTRICT,
    CONSTRAINT fk_household_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_household_members_added_by FOREIGN KEY (added_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
