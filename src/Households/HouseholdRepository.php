<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Catalogue\Repository\Repository;

/**
 * SQL for households and their memberships.
 *
 * A household is the access boundary for physical inventory (copies and
 * locations). Memberships are read from the database on every request
 * (never cached in the session), so a change applies to the member's next
 * request.
 */
final class HouseholdRepository extends Repository
{
    /** system_key of the temporary household existing records were moved into. */
    public const DEFAULT_KEY = 'default';

    /** Upper bound for list queries (a household installation has a handful). */
    public const MAX_LISTED = 500;

    private const SELECT = 'SELECT h.id, h.name, h.description, h.system_key, h.row_version, h.created_at, h.updated_at,
            (SELECT COUNT(*) FROM household_members m WHERE m.household_id = h.id) AS member_count,
            (SELECT COUNT(*) FROM copies c WHERE c.household_id = h.id) AS copy_count,
            (SELECT COUNT(*) FROM locations l WHERE l.household_id = h.id) AS location_count
        FROM households h';

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->many(self::SELECT . ' ORDER BY h.name, h.id LIMIT ' . self::MAX_LISTED);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE h.id = :id', ['id' => $id]);
    }

    /**
     * Locks the household row; membership and deletion changes of one
     * household are serialised by it.
     *
     * @return array<string, mixed>|null
     */
    public function lock(int $id): ?array
    {
        return $this->one('SELECT id, name, description, system_key, row_version FROM households WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    public function exists(int $id): bool
    {
        return $this->scalar('SELECT 1 FROM households WHERE id = :id', ['id' => $id]) !== null;
    }

    /**
     * The temporary default household, or null when it does not exist.
     */
    public function defaultId(): ?int
    {
        $id = $this->scalar('SELECT id FROM households WHERE system_key = :k', ['k' => self::DEFAULT_KEY]);

        return $id === null ? null : (int) $id;
    }

    public function nameTaken(string $name, ?int $exceptId = null): bool
    {
        return $this->scalar('SELECT 1 FROM households WHERE name = :n AND id <> :x', ['n' => $name, 'x' => $exceptId ?? 0]) !== null;
    }

    public function create(HouseholdInput $input, int $userId): int
    {
        return $this->insert(
            'INSERT INTO households (name, description, created_by, updated_by, created_at, updated_at)
             VALUES (:n, :d, :c, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['n' => $input->name, 'd' => $input->description === '' ? null : $input->description, 'c' => $userId, 'u' => $userId],
        );
    }

    /**
     * Conditional on the version the edit was based on.
     *
     * @return bool false when the record changed in the meantime
     */
    public function update(int $id, HouseholdInput $input, int $userId, int $expectedVersion): bool
    {
        return $this->run(
            'UPDATE households SET name = :n, description = :d, updated_by = :u, updated_at = UTC_TIMESTAMP(), row_version = row_version + 1
             WHERE id = :id AND row_version = :expected',
            ['n' => $input->name, 'd' => $input->description === '' ? null : $input->description, 'u' => $userId, 'id' => $id, 'expected' => $expectedVersion],
        )->rowCount() === 1;
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM households WHERE id = :id', ['id' => $id]);
    }

    public function copyCount(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE household_id = :id', ['id' => $id]);
    }

    public function locationCount(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM locations WHERE household_id = :id', ['id' => $id]);
    }

    public function memberCount(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM household_members WHERE household_id = :id', ['id' => $id]);
    }

    /**
     * Members of a household (administration only: it lists accounts).
     *
     * @return list<array<string, mixed>>
     */
    public function members(int $householdId): array
    {
        return $this->many(
            'SELECT u.id, u.display_name, u.email, u.is_active, r.code AS role_code, m.created_at AS member_since,
                    (SELECT COUNT(*) FROM copies c WHERE c.household_id = m.household_id AND c.owner_user_id = u.id) AS owned_copies
             FROM household_members m JOIN users u ON u.id = m.user_id JOIN roles r ON r.id = u.role_id
             WHERE m.household_id = :h ORDER BY u.display_name, u.id LIMIT ' . self::MAX_LISTED,
            ['h' => $householdId],
        );
    }

    /**
     * Active accounts that are not members yet (administration only).
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(int $householdId): array
    {
        return $this->many(
            'SELECT u.id, u.display_name, u.email FROM users u
             WHERE u.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM household_members m WHERE m.household_id = :h AND m.user_id = u.id)
             ORDER BY u.display_name, u.id LIMIT ' . self::MAX_LISTED,
            ['h' => $householdId],
        );
    }

    /**
     * Locks the account row and reports whether it exists and is active.
     *
     * @return array{id: int, is_active: bool}|null
     */
    public function lockUser(int $userId): ?array
    {
        $row = $this->one('SELECT id, is_active FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);

        return $row === null ? null : ['id' => (int) $row['id'], 'is_active' => (bool) $row['is_active']];
    }

    /**
     * Ids of the households a user belongs to (the basis of HouseholdScope).
     *
     * @return list<int>
     */
    public function idsForUser(int $userId): array
    {
        return array_map('intval', array_column(
            $this->many('SELECT household_id FROM household_members WHERE user_id = :u ORDER BY household_id', ['u' => $userId]),
            'household_id',
        ));
    }

    /**
     * The households a user belongs to.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return $this->many(
            'SELECT h.id, h.name, h.system_key FROM household_members m JOIN households h ON h.id = m.household_id
             WHERE m.user_id = :u ORDER BY h.name, h.id',
            ['u' => $userId],
        );
    }

    public function isMember(int $householdId, int $userId): bool
    {
        return $this->scalar('SELECT 1 FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $householdId, 'u' => $userId]) !== null;
    }

    public function addMember(int $householdId, int $userId, int $addedBy): void
    {
        $this->run(
            'INSERT INTO household_members (household_id, user_id, added_by, created_at) VALUES (:h, :u, :a, UTC_TIMESTAMP())',
            ['h' => $householdId, 'u' => $userId, 'a' => $addedBy],
        );
    }

    public function removeMember(int $householdId, int $userId): bool
    {
        return $this->run('DELETE FROM household_members WHERE household_id = :h AND user_id = :u', ['h' => $householdId, 'u' => $userId])->rowCount() === 1;
    }

    /**
     * Copies in the household owned by the user (reported when a membership
     * is removed: the copies stay in the household).
     */
    public function ownedCopies(int $householdId, int $userId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE household_id = :h AND owner_user_id = :u', ['h' => $householdId, 'u' => $userId]);
    }
}
