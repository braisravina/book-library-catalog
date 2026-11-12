<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class AuthorRepository extends Repository
{
    /**
     * Exact, case-insensitive but accent-sensitive match, so that distinct
     * people such as "José" and "Jose" are not merged silently.
     */
    public function findOrCreate(string $name, ?int $userId): int
    {
        $name = self::normalizeName($name);
        $id = $this->scalar('SELECT id FROM authors WHERE name COLLATE utf8mb4_0900_as_ci = :name ORDER BY id LIMIT 1', ['name' => $name]);
        if ($id !== null) {
            return (int) $id;
        }

        return $this->insert(
            'INSERT INTO authors (name, sort_name, created_by, created_at, updated_at) VALUES (:name, :sort, :uid, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['name' => $name, 'sort' => self::sortName($name), 'uid' => $userId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM authors WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function paginate(string $query, int $page, int $perPage): array
    {
        $where = '';
        $params = [];
        if ($query !== '') {
            $where = 'WHERE a.name LIKE :q';
            $params['q'] = \DigitalLibrary\Database\Params::likeContains($query);
        }
        $total = (int) $this->scalar("SELECT COUNT(*) FROM authors a {$where}", $params);
        $rows = $this->many(
            "SELECT a.id, a.name, a.sort_name, a.birth_year, a.death_year,
                    (SELECT COUNT(*) FROM work_authors wa WHERE wa.author_id = a.id) AS work_count
             FROM authors a {$where}
             ORDER BY a.sort_name, a.id
             LIMIT :limit OFFSET :offset",
            $params + ['limit' => $perPage, 'offset' => ($page - 1) * $perPage],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Works credited to the author, with the role and edition counts.
     *
     * @return list<array<string, mixed>>
     */
    public function works(int $authorId): array
    {
        return $this->many(
            'SELECT w.id, w.title, w.subtitle, w.first_published_year, wa.role,
                    (SELECT COUNT(*) FROM editions e WHERE e.work_id = w.id) AS edition_count,
                    (SELECT MIN(e.id) FROM editions e WHERE e.work_id = w.id) AS first_edition_id
             FROM work_authors wa JOIN works w ON w.id = wa.work_id
             WHERE wa.author_id = :id
             ORDER BY w.title, w.id',
            ['id' => $authorId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function contributions(int $authorId): array
    {
        return $this->many(
            'SELECT e.id, e.title, e.publication_year, ec.role
             FROM edition_contributors ec JOIN editions e ON e.id = ec.edition_id
             WHERE ec.author_id = :id ORDER BY e.title, e.id',
            ['id' => $authorId],
        );
    }

    public function update(int $id, string $name, string $sortName, ?int $birthYear, ?int $deathYear, ?string $notes): void
    {
        $this->run(
            'UPDATE authors SET name = :name, sort_name = :sort, birth_year = :birth, death_year = :death, notes = :notes,
                    updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['name' => self::normalizeName($name), 'sort' => $sortName, 'birth' => $birthYear, 'death' => $deathYear, 'notes' => $notes, 'id' => $id],
        );
    }

    /**
     * @return list<string>
     */
    public function names(int $limit = 500): array
    {
        return array_column($this->many('SELECT name FROM authors ORDER BY sort_name LIMIT :l', ['l' => $limit]), 'name');
    }

    /**
     * Number of credits (work authorships and edition contributions).
     */
    public function usage(int $id): int
    {
        return (int) $this->scalar(
            'SELECT (SELECT COUNT(*) FROM work_authors WHERE author_id = :a) + (SELECT COUNT(*) FROM edition_contributors WHERE author_id = :b)',
            ['a' => $id, 'b' => $id],
        );
    }

    /**
     * Moves every credit from $sourceId to $targetId and deletes the source.
     * Credits the target already holds are kept once (UPDATE IGNORE skips
     * rows that would duplicate a primary key). Affected works and editions
     * get a new row_version so stale edit forms cannot resurrect the source.
     * Must run inside a transaction.
     */
    public function mergeInto(int $sourceId, int $targetId): void
    {
        $this->run('UPDATE works SET row_version = row_version + 1 WHERE id IN (SELECT work_id FROM work_authors WHERE author_id = :s)', ['s' => $sourceId]);
        $this->run('UPDATE editions SET row_version = row_version + 1 WHERE id IN (SELECT edition_id FROM edition_contributors WHERE author_id = :s)', ['s' => $sourceId]);
        $this->run('UPDATE IGNORE work_authors SET author_id = :t WHERE author_id = :s', ['t' => $targetId, 's' => $sourceId]);
        $this->run('DELETE FROM work_authors WHERE author_id = :s', ['s' => $sourceId]);
        $this->run('UPDATE IGNORE edition_contributors SET author_id = :t WHERE author_id = :s', ['t' => $targetId, 's' => $sourceId]);
        $this->run('DELETE FROM edition_contributors WHERE author_id = :s', ['s' => $sourceId]);
        $this->run('DELETE FROM authors WHERE id = :s', ['s' => $sourceId]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM authors WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function options(int $excludeId, int $limit = 2000): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->many(
            'SELECT id, name FROM authors WHERE id <> :x ORDER BY sort_name, id LIMIT :l',
            ['x' => $excludeId, 'l' => $limit],
        ));
    }

    public static function normalizeName(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * Default sort key "Surname, Given names". Imperfect for compound
     * surnames (common in Spanish and Galician), so it remains editable.
     */
    public static function sortName(string $name): string
    {
        $name = self::normalizeName($name);
        if (str_contains($name, ',')) {
            return $name;
        }
        $parts = explode(' ', $name);
        if (count($parts) < 2) {
            return $name;
        }
        $last = array_pop($parts);

        return $last . ', ' . implode(' ', $parts);
    }
}
