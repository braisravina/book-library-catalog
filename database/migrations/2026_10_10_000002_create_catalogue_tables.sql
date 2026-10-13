-- Bibliographic records. A work is the abstract book; an edition is a
-- specific published manifestation (and is what an ISBN identifies).
-- Physical copies live in the holdings migration.

CREATE TABLE authors (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    sort_name VARCHAR(255) NOT NULL,
    birth_year SMALLINT NULL,
    death_year SMALLINT NULL,
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_authors_sort_name (sort_name),
    FULLTEXT KEY ft_authors_name (name),
    CONSTRAINT fk_authors_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE publishers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_publishers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE works (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(500) NOT NULL,
    subtitle VARCHAR(500) NULL,
    original_title VARCHAR(500) NULL,
    original_language VARCHAR(12) NULL,
    first_published_year SMALLINT NULL,
    description TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_works_title (title(191)),
    KEY idx_works_created_by (created_by),
    FULLTEXT KEY ft_works_titles (title, subtitle, original_title),
    CONSTRAINT fk_works_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_works_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_authors (
    work_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    role ENUM('author', 'editor', 'compiler') NOT NULL DEFAULT 'author',
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (work_id, author_id, role),
    KEY idx_work_authors_author (author_id),
    CONSTRAINT fk_work_authors_work FOREIGN KEY (work_id) REFERENCES works (id) ON DELETE CASCADE,
    CONSTRAINT fk_work_authors_author FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Local copy of metadata fetched from external providers, so entries remain
-- reviewable and the catalogue stays usable when providers are unavailable.
CREATE TABLE metadata_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider ENUM('open_library', 'google_books') NOT NULL,
    isbn_13 CHAR(13) NOT NULL,
    payload JSON NOT NULL,
    fetched_by INT UNSIGNED NULL,
    fetched_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_metadata_provider_isbn (provider, isbn_13),
    CONSTRAINT fk_metadata_fetched_by FOREIGN KEY (fetched_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE editions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    subtitle VARCHAR(500) NULL,
    publisher_id INT UNSIGNED NULL,
    publication_year SMALLINT NULL,
    edition_statement VARCHAR(255) NULL,
    language VARCHAR(12) NULL,
    page_count SMALLINT UNSIGNED NULL,
    format ENUM('hardcover', 'paperback', 'mass_market', 'spiral', 'leather', 'boxed_set', 'other') NULL,
    -- ISBNs are optional (older books) but unique when present. NULLs do not
    -- collide in MySQL unique indexes. isbn_13 is always derived for
    -- 978-prefixed ISBN-10s so duplicates are caught across both forms.
    isbn_13 CHAR(13) NULL,
    isbn_10 CHAR(10) NULL,
    legal_deposit VARCHAR(64) NULL,
    description TEXT NULL,
    metadata_record_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_editions_isbn_13 (isbn_13),
    UNIQUE KEY uq_editions_isbn_10 (isbn_10),
    KEY idx_editions_work (work_id),
    KEY idx_editions_publisher (publisher_id),
    KEY idx_editions_language (language),
    KEY idx_editions_created_by (created_by),
    FULLTEXT KEY ft_editions_titles (title, subtitle),
    CONSTRAINT chk_editions_isbn_13 CHECK (isbn_13 IS NULL OR isbn_13 REGEXP '^97[89][0-9]{10}$'),
    CONSTRAINT chk_editions_isbn_10 CHECK (isbn_10 IS NULL OR isbn_10 REGEXP '^[0-9]{9}[0-9X]$'),
    CONSTRAINT fk_editions_work FOREIGN KEY (work_id) REFERENCES works (id) ON DELETE RESTRICT,
    CONSTRAINT fk_editions_publisher FOREIGN KEY (publisher_id) REFERENCES publishers (id) ON DELETE SET NULL,
    CONSTRAINT fk_editions_metadata FOREIGN KEY (metadata_record_id) REFERENCES metadata_records (id) ON DELETE SET NULL,
    CONSTRAINT fk_editions_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_editions_updated_by FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Edition-specific contributors (translators, illustrators, ...).
CREATE TABLE edition_contributors (
    edition_id INT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    role ENUM('translator', 'illustrator', 'editor', 'introduction', 'other') NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (edition_id, author_id, role),
    KEY idx_edition_contributors_author (author_id),
    CONSTRAINT fk_edition_contributors_edition FOREIGN KEY (edition_id) REFERENCES editions (id) ON DELETE CASCADE,
    CONSTRAINT fk_edition_contributors_author FOREIGN KEY (author_id) REFERENCES authors (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Hierarchical categories (adjacency list).
CREATE TABLE categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    description VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    UNIQUE KEY uq_categories_parent_name (parent_id, name),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE work_categories (
    work_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (work_id, category_id),
    KEY idx_work_categories_category (category_id),
    CONSTRAINT fk_work_categories_work FOREIGN KEY (work_id) REFERENCES works (id) ON DELETE CASCADE,
    CONSTRAINT fk_work_categories_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Cover images are stored on disk outside the web root; this row holds the
-- metadata. The generated column enforces at most one primary cover per edition.
-- edition_id is RESTRICT (MySQL forbids cascading FKs on generated-column base
-- columns, and the application must delete the image files anyway).
CREATE TABLE covers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    edition_id INT UNSIGNED NOT NULL,
    storage_key VARCHAR(64) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,
    source ENUM('upload', 'open_library', 'google_books') NOT NULL DEFAULT 'upload',
    source_url VARCHAR(1000) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    primary_edition_id INT UNSIGNED GENERATED ALWAYS AS (IF(is_primary = 1, edition_id, NULL)) STORED,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_covers_storage_key (storage_key),
    UNIQUE KEY uq_covers_primary (primary_edition_id),
    KEY idx_covers_edition (edition_id),
    CONSTRAINT fk_covers_edition FOREIGN KEY (edition_id) REFERENCES editions (id) ON DELETE RESTRICT,
    CONSTRAINT fk_covers_uploaded_by FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
