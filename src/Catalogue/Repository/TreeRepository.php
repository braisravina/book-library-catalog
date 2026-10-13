<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Catalogue\Tree;

/**
 * Shared operations for adjacency-list tables. The table name comes from the
 * subclass constant, never from input.
 */
abstract class TreeRepository extends Repository
{
    abstract protected function table(): string;

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->many('SELECT * FROM ' . $this->table() . ' ORDER BY name, id');
    }

    /**
     * @return list<array<string, mixed>> depth-first, with depth and path
     */
    public function tree(): array
    {
        return Tree::flatten($this->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }

    public function exists(int $id): bool
    {
        return $this->scalar('SELECT 1 FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]) !== null;
    }

    public function childCount(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM ' . $this->table() . ' WHERE parent_id = :id', ['id' => $id]);
    }

    public function siblingNameTaken(?int $parentId, string $name, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM ' . $this->table() . ' WHERE name = :name AND id <> :x AND '
            . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = :parent');
        $params = ['name' => $name, 'x' => $excludeId ?? 0];
        if ($parentId !== null) {
            $params['parent'] = $parentId;
        }

        return $this->scalar($sql, $params) !== null;
    }

    /**
     * @return list<int> the node and all its descendants
     */
    public function descendantIds(int $id): array
    {
        return Tree::descendantIds($this->all(), $id);
    }

    public function wouldCreateCycle(int $id, ?int $newParentId): bool
    {
        return Tree::wouldCreateCycle($this->all(), $id, $newParentId);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM ' . $this->table() . ' WHERE id = :id', ['id' => $id]);
    }
}
