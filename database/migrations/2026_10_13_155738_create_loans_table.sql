-- Phase 4A: lending of physical copies.
--
-- One row per loan; returned loans are kept as history. At most one ACTIVE
-- loan per copy is enforced by the database itself: active_copy_id is the
-- copy id while the loan is open and NULL once returned, and it is UNIQUE
-- (NULLs never collide). The application additionally locks the copy row
-- (SELECT ... FOR UPDATE) while lending or returning.
--
-- Borrowers are either application users (borrower_user_id) or people
-- without an account (borrower_name only; no contact details are stored).
-- Overdue is derived from due_on at read time; nothing needs a background job.

CREATE TABLE loans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    copy_id INT UNSIGNED NOT NULL,
    borrower_user_id INT UNSIGNED NULL,
    borrower_name VARCHAR(150) NULL,
    loaned_on DATE NOT NULL,
    due_on DATE NULL,
    returned_on DATE NULL,
    notes VARCHAR(1000) NULL,
    active_copy_id INT UNSIGNED GENERATED ALWAYS AS (IF(returned_on IS NULL, copy_id, NULL)) STORED,
    created_by INT UNSIGNED NULL,
    returned_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_loans_active_copy (active_copy_id),
    KEY idx_loans_copy (copy_id, loaned_on),
    KEY idx_loans_borrower (borrower_user_id, returned_on),
    KEY idx_loans_due (returned_on, due_on),
    CONSTRAINT chk_loans_borrower CHECK (borrower_user_id IS NOT NULL OR borrower_name IS NOT NULL),
    CONSTRAINT chk_loans_due CHECK (due_on IS NULL OR due_on >= loaned_on),
    CONSTRAINT chk_loans_returned CHECK (returned_on IS NULL OR returned_on >= loaned_on),
    -- RESTRICT: copies with lending history cannot be deleted (history is kept);
    -- users are deactivated, never deleted.
    CONSTRAINT fk_loans_copy FOREIGN KEY (copy_id) REFERENCES copies (id) ON DELETE RESTRICT,
    CONSTRAINT fk_loans_borrower FOREIGN KEY (borrower_user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_loans_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_loans_returned_by FOREIGN KEY (returned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
