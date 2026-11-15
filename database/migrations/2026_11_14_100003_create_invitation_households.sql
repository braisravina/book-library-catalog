-- Household access control, phase H3 (4 of 4): invitations may carry the
-- households the invited account will join.
--
-- Additive only: one new table. An invitation without rows here grants no
-- household membership (the default). The memberships are created only
-- when the invitation is redeemed, in the same transaction as the account.
--
-- ON DELETE CASCADE on both sides: pruning an old invitation removes its
-- assignments, and deleting a household removes it from every pending
-- invitation, so a deleted household can never be granted later.
--
-- Portable: runs unchanged on MySQL 8.4 and MariaDB 11.4.

CREATE TABLE invitation_households (
    invitation_id INT UNSIGNED NOT NULL,
    household_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (invitation_id, household_id),
    KEY idx_invitation_households_household (household_id),
    CONSTRAINT fk_invitation_households_invitation FOREIGN KEY (invitation_id) REFERENCES invitations (id) ON DELETE CASCADE,
    CONSTRAINT fk_invitation_households_household FOREIGN KEY (household_id) REFERENCES households (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
