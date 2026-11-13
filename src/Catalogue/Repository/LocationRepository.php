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

    public function create(?int $parentId, string $name, string $kind, ?string $description, int $userId): int
    {
        // A location belongs to one household: a child inherits its parent's,
        // a new root goes to the temporary default household. Managing
        // separate location trees per household is phase H3.
        $household = $parentId !== null
            ? $this->scalar('SELECT household_id FROM locations WHERE id = :id', ['id' => $parentId])
            : $this->scalar("SELECT id FROM households WHERE system_key = 'default'");

        return $this->insert(
            'INSERT INTO locations (parent_id, household_id, name, kind, description, created_by, created_at, updated_at)
             VALUES (:p, :h, :n, :k, :d, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['p' => $parentId, 'h' => $household === null ? null : (int) $household, 'n' => $name, 'k' => $kind, 'd' => $description, 'u' => $userId],
        );
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
