<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Catalogue\Tree;
use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\Visibility;

final class LocationRepository extends TreeRepository
{
    protected function table(): string
    {
        return 'locations';
    }

    /**
     * Every location belongs to one household, decided by
     * Households\LocationService: the chosen household for a root, the
     * parent's household for a child.
     */
    public function create(?int $parentId, ?int $householdId, string $name, string $kind, ?string $description, int $userId): int
    {
        return $this->insert(
            'INSERT INTO locations (parent_id, household_id, name, kind, description, created_by, created_at, updated_at)
             VALUES (:p, :h, :n, :k, :d, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['p' => $parentId, 'h' => $householdId, 'n' => $name, 'k' => $kind, 'd' => $description, 'u' => $userId],
        );
    }

    /**
     * Locks the location row for the rest of the transaction: exclusively
     * for a change of the tree (Households\LocationService), shared when a
     * copy is being stored in it (Catalogue\CopyService), so the location
     * cannot disappear or be re-parented under the copy.
     *
     * @return array<string, mixed>|null
     */
    public function lock(int $id, bool $exclusive): ?array
    {
        return $this->one('SELECT * FROM locations WHERE id = :id ' . ($exclusive ? 'FOR UPDATE' : 'LOCK IN SHARE MODE'), ['id' => $id]);
    }

    /**
     * Whether another root location of the household (null: of the
     * locations without one) already has the name. Roots of different
     * households may share a name: each household has its own tree.
     */
    public function rootNameTaken(?int $householdId, string $name, ?int $excludeId = null): bool
    {
        return $this->scalar(
            'SELECT 1 FROM locations WHERE parent_id IS NULL AND name = :name AND id <> :x AND '
                . ($householdId === null ? 'household_id IS NULL' : 'household_id = :h') . ' LIMIT 1',
            ['name' => $name, 'x' => $excludeId ?? 0] + ($householdId === null ? [] : ['h' => $householdId]),
        ) !== null;
    }

    public function update(int $id, ?int $parentId, string $name, string $kind, ?string $description): void
    {
        $this->run(
            'UPDATE locations SET parent_id = :p, name = :n, kind = :k, description = :d, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['p' => $parentId, 'n' => $name, 'k' => $kind, 'd' => $description, 'id' => $id],
        );
    }

    /**
     * Locations of households in the viewer's view (every location for
     * administrators, including ones without a household).
     *
     * @return list<array<string, mixed>>
     */
    public function visible(Visibility $visibility): array
    {
        $params = new Params();

        return $this->many(
            'SELECT l.* FROM locations l WHERE ' . $visibility->location('l', $params) . ' ORDER BY l.name, l.id',
            $params->all(),
        );
    }

    /**
     * @return list<array<string, mixed>> depth-first, with depth and path
     */
    public function visibleTree(Visibility $visibility): array
    {
        return Tree::flatten($this->visible($visibility));
    }

    /**
     * @return array<int, string> id => breadcrumb path, visible locations only
     */
    public function visiblePaths(Visibility $visibility): array
    {
        return Tree::paths($this->visible($visibility));
    }

    public function householdId(int $id): ?int
    {
        $household = $this->scalar('SELECT household_id FROM locations WHERE id = :id', ['id' => $id]);

        return $household === null ? null : (int) $household;
    }

    public function usage(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE location_id = :id', ['id' => $id]);
    }
}
