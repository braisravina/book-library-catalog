<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class LocationRepository extends TreeRepository
{
    protected function table(): string
    {
        return 'locations';
    }

    public function create(?int $parentId, string $name, string $kind, ?string $description, int $userId): int
    {
        return $this->insert(
            'INSERT INTO locations (parent_id, name, kind, description, created_by, created_at, updated_at)
             VALUES (:p, :n, :k, :d, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['p' => $parentId, 'n' => $name, 'k' => $kind, 'd' => $description, 'u' => $userId],
        );
    }

    public function update(int $id, ?int $parentId, string $name, string $kind, ?string $description): void
    {
        $this->run(
            'UPDATE locations SET parent_id = :p, name = :n, kind = :k, description = :d, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['p' => $parentId, 'n' => $name, 'k' => $kind, 'd' => $description, 'id' => $id],
        );
    }

    public function usage(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM copies WHERE location_id = :id', ['id' => $id]);
    }
}
