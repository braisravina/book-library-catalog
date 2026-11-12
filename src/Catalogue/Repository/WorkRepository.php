<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class WorkRepository extends Repository
{
    public const FIELDS = ['title', 'subtitle', 'original_title', 'original_language', 'first_published_year', 'description'];

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM works WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data keys from FIELDS
     */
    public function create(array $data, int $userId): int
    {
        return $this->insert(
            'INSERT INTO works (title, subtitle, original_title, original_language, first_published_year, description,
                                created_by, updated_by, created_at, updated_at)
             VALUES (:title, :subtitle, :original_title, :original_language, :first_published_year, :description,
                     :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            self::only($data) + ['created_by' => $userId, 'updated_by' => $userId],
        );
    }

    /**
     * Conditional on the version the edit was based on.
     *
     * @param array<string, mixed> $data keys from FIELDS
     *
     * @return bool false when the record changed in the meantime
     */
    public function update(int $id, array $data, int $userId, int $expectedVersion): bool
    {
        return $this->run(
            'UPDATE works SET title = :title, subtitle = :subtitle, original_title = :original_title,
                    original_language = :original_language, first_published_year = :first_published_year,
                    description = :description, updated_by = :updated_by, updated_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
             WHERE id = :id AND row_version = :expected',
            self::only($data) + ['updated_by' => $userId, 'id' => $id, 'expected' => $expectedVersion],
        )->rowCount() === 1;
    }

    /**
     * @return list<array{id: int, name: string, role: string, position: int}>
     */
    public function authors(int $workId): array
    {
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'name' => (string) $r['name'], 'role' => (string) $r['role'], 'position' => (int) $r['position'],
        ], $this->many(
            'SELECT a.id, a.name, wa.role, wa.position FROM work_authors wa JOIN authors a ON a.id = wa.author_id
             WHERE wa.work_id = :id ORDER BY wa.position, a.sort_name',
            ['id' => $workId],
        ));
    }

    /**
     * Replaces the author list. Duplicate (author, role) pairs are collapsed.
     *
     * @param list<array{author_id: int, role: string}> $authors in display order
     */
    public function setAuthors(int $workId, array $authors): void
    {
        $this->run('DELETE FROM work_authors WHERE work_id = :id', ['id' => $workId]);
        $seen = [];
        foreach ($authors as $position => $author) {
            $key = $author['author_id'] . '|' . $author['role'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->run(
                'INSERT INTO work_authors (work_id, author_id, role, position) VALUES (:w, :a, :r, :p)',
                ['w' => $workId, 'a' => $author['author_id'], 'r' => $author['role'], 'p' => $position],
            );
        }
    }

    /**
     * @return list<int>
     */
    public function categoryIds(int $workId): array
    {
        return array_map('intval', array_column($this->many('SELECT category_id FROM work_categories WHERE work_id = :id', ['id' => $workId]), 'category_id'));
    }

    /**
     * @param list<int> $categoryIds already validated to exist
     */
    public function setCategories(int $workId, array $categoryIds): void
    {
        $this->run('DELETE FROM work_categories WHERE work_id = :id', ['id' => $workId]);
        foreach (array_unique($categoryIds) as $categoryId) {
            $this->run('INSERT INTO work_categories (work_id, category_id) VALUES (:w, :c)', ['w' => $workId, 'c' => $categoryId]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editions(int $workId): array
    {
        return $this->many(
            'SELECT e.id, e.title, e.subtitle, e.publication_year, e.language, e.format, e.isbn_13, e.edition_statement,
                    p.name AS publisher_name,
                    (SELECT COUNT(*) FROM copies c WHERE c.edition_id = e.id) AS copy_count,
                    (SELECT cv.id FROM covers cv WHERE cv.edition_id = e.id AND cv.is_primary = 1) AS cover_id
             FROM editions e LEFT JOIN publishers p ON p.id = e.publisher_id
             WHERE e.work_id = :id ORDER BY e.publication_year IS NULL, e.publication_year, e.id',
            ['id' => $workId],
        );
    }

    public function editionCount(int $workId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM editions WHERE work_id = :id', ['id' => $workId]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM works WHERE id = :id', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function only(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $data[$field] ?? null;
        }

        return $out;
    }
}
