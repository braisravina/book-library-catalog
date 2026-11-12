<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Catalogue\Slug;

final class CategoryRepository extends TreeRepository
{
    protected function table(): string
    {
        return 'categories';
    }

    public function create(?int $parentId, string $name, ?string $description): int
    {
        return $this->insert(
            'INSERT INTO categories (parent_id, name, slug, description, created_at, updated_at)
             VALUES (:p, :n, :s, :d, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['p' => $parentId, 'n' => $name, 's' => $this->uniqueSlug($name), 'd' => $description],
        );
    }

    public function update(int $id, ?int $parentId, string $name, ?string $description): void
    {
        $this->run(
            'UPDATE categories SET parent_id = :p, name = :n, slug = :s, description = :d, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['p' => $parentId, 'n' => $name, 's' => $this->uniqueSlug($name, $id), 'd' => $description, 'id' => $id],
        );
    }

    public function usage(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM work_categories WHERE category_id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forWork(int $workId): array
    {
        $paths = \DigitalLibrary\Catalogue\Tree::paths($this->all());
        $rows = $this->many(
            'SELECT c.id, c.name FROM work_categories wc JOIN categories c ON c.id = wc.category_id WHERE wc.work_id = :id',
            ['id' => $workId],
        );
        foreach ($rows as &$row) {
            $row['path'] = $paths[(int) $row['id']] ?? $row['name'];
        }
        unset($row);
        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));

        return $rows;
    }

    private function uniqueSlug(string $name, ?int $excludeId = null): string
    {
        $base = Slug::from($name, 150);
        $slug = $base;
        for ($i = 2; $this->scalar('SELECT 1 FROM categories WHERE slug = :s AND id <> :x', ['s' => $slug, 'x' => $excludeId ?? 0]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }

        return $slug;
    }
}
