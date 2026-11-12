-- Phase 4A: private per-user wishlists.
--
-- An entry may point at a catalogued edition (and its work) or be entered
-- manually; title/author are always stored so entries stay meaningful if the
-- catalogue record is later deleted (references are then set to NULL).
-- Entries are never deleted by the application: status moves between
-- wanted, acquired and archived, preserving history.
--
-- Duplicate protection: at most one WANTED entry per user and ISBN is
-- enforced by a unique index on a generated column; other duplicate checks
-- run in the application under a per-user row lock.

CREATE TABLE wishlist_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    edition_id INT UNSIGNED NULL,
    work_id INT UNSIGNED NULL,
    title VARCHAR(500) NOT NULL,
    author VARCHAR(255) NULL,
    isbn_13 CHAR(13) NULL,
    priority ENUM('high', 'normal', 'low') NOT NULL DEFAULT 'normal',
    notes VARCHAR(1000) NULL,
    status ENUM('wanted', 'acquired', 'archived') NOT NULL DEFAULT 'wanted',
    status_changed_at DATETIME NULL,
    wanted_isbn_13 CHAR(13) GENERATED ALWAYS AS (IF(status = 'wanted', isbn_13, NULL)) STORED,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wishlist_wanted_isbn (user_id, wanted_isbn_13),
    KEY idx_wishlist_user_status (user_id, status, priority),
    KEY idx_wishlist_edition (edition_id),
    KEY idx_wishlist_work (work_id),
    CONSTRAINT chk_wishlist_isbn CHECK (isbn_13 IS NULL OR isbn_13 REGEXP '^97[89][0-9]{10}$'),
    CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_wishlist_edition FOREIGN KEY (edition_id) REFERENCES editions (id) ON DELETE SET NULL,
    CONSTRAINT fk_wishlist_work FOREIGN KEY (work_id) REFERENCES works (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
