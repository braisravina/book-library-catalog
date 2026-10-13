-- Phase 4B: administrator-issued invitations (table created in Phase 1).
--
-- * At most one OPEN invitation (not accepted, not revoked) per e-mail
--   address: unique index on a stored generated column. Expired invitations
--   are revoked before a new one is issued for the same address.
-- * Invitations can only grant the member (2) or viewer (3) role; there are
--   no administrator invitations.
-- * Only the SHA-256 hash of the random token is stored (unchanged).
-- * Single use is enforced in InvitationService by a conditional UPDATE
--   under a row lock (accepted_at IS NULL) inside the account-creating
--   transaction, backed by users.email being unique.

ALTER TABLE invitations
    ADD COLUMN open_email VARCHAR(254) GENERATED ALWAYS AS (IF(accepted_at IS NULL AND revoked_at IS NULL, email, NULL)) STORED,
    ADD COLUMN revoked_by INT UNSIGNED NULL,
    ADD COLUMN replaced_by_id INT UNSIGNED NULL,
    ADD UNIQUE KEY uq_invitations_open_email (open_email),
    ADD KEY idx_invitations_expires (expires_at),
    ADD CONSTRAINT chk_invitations_role CHECK (role_id IN (2, 3)),
    ADD CONSTRAINT chk_invitations_expiry CHECK (expires_at > created_at),
    ADD CONSTRAINT fk_invitations_revoked_by FOREIGN KEY (revoked_by) REFERENCES users (id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_invitations_replaced_by FOREIGN KEY (replaced_by_id) REFERENCES invitations (id) ON DELETE SET NULL;
