<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class PublisherRepository extends Repository
{
    public function findOrCreate(string $name): int
    {
        $name = AuthorRepository::normalizeName($name);
        $id = $this->scalar('SELECT id FROM publishers WHERE name COLLATE utf8mb4_0900_as_ci = :name ORDER BY id LIMIT 1', ['name' => $name]);
        if ($id !== null) {
            return (int) $id;
        }
        try {
            return $this->insert('INSERT INTO publishers (name, created_at, updated_at) VALUES (:name, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['name' => $name]);
        } catch (\PDOException $e) {
            // The unique index uses the accent-insensitive default collation;
            // fall back to the existing row that it considers equal.
            if (!self::isDuplicateKey($e)) {
                throw $e;
            }

            return (int) $this->scalar('SELECT id FROM publishers WHERE name = :name ORDER BY id LIMIT 1', ['name' => $name]);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM publishers WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->many(
            'SELECT p.id, p.name, (SELECT COUNT(*) FROM editions e WHERE e.publisher_id = p.id) AS edition_count
             FROM publishers p ORDER BY p.name, p.id'
        );
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->many('SELECT name FROM publishers ORDER BY name LIMIT 1000'), 'name');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editions(int $id): array
    {
        return $this->many(
            'SELECT e.id, e.title, e.publication_year FROM editions e WHERE e.publisher_id = :id ORDER BY e.title, e.id',
            ['id' => $id],
        );
    }

    /**
     * @return bool false when another publisher already has the name
     */
    public function rename(int $id, string $name): bool
    {
        try {
            $this->run('UPDATE publishers SET name = :name, updated_at = UTC_TIMESTAMP() WHERE id = :id', ['name' => AuthorRepository::normalizeName($name), 'id' => $id]);

            return true;
        } catch (\PDOException $e) {
            if (self::isDuplicateKey($e)) {
                return false;
            }
            throw $e;
        }
    }
}
