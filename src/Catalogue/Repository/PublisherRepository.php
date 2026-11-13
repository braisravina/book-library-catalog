<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\Visibility;

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
     * The publisher, when it publishes an edition the viewer may see.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $id, Visibility $visibility): ?array
    {
        $params = new Params();

        return $this->one(
            'SELECT p.* FROM publishers p WHERE p.id = ' . $params->add($id) . ' AND ' . $visibility->publisher('p', $params),
            $params->all(),
        );
    }

    /**
     * Publishers of visible editions; counts count visible editions.
     *
     * @return list<array<string, mixed>>
     */
    public function allWithCounts(Visibility $visibility): array
    {
        $params = new Params();

        return $this->many(
            'SELECT p.id, p.name,
                    (SELECT COUNT(*) FROM editions e WHERE e.publisher_id = p.id AND ' . $visibility->edition('e', $params) . ') AS edition_count
             FROM publishers p WHERE ' . $visibility->publisher('p', $params) . ' ORDER BY p.name, p.id',
            $params->all(),
        );
    }

    /**
     * Names offered while typing in the book forms: visible publishers only.
     *
     * @return list<string>
     */
    public function names(Visibility $visibility): array
    {
        $params = new Params();

        return array_column($this->many(
            'SELECT p.name FROM publishers p WHERE ' . $visibility->publisher('p', $params) . ' ORDER BY p.name LIMIT 1000',
            $params->all(),
        ), 'name');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function editions(int $id, Visibility $visibility): array
    {
        $params = new Params();

        return $this->many(
            'SELECT e.id, e.title, e.publication_year FROM editions e
             WHERE e.publisher_id = ' . $params->add($id) . ' AND ' . $visibility->edition('e', $params) . ' ORDER BY e.title, e.id',
            $params->all(),
        );
    }

    public function usage(int $id): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM editions WHERE publisher_id = :id', ['id' => $id]);
    }

    /**
     * Reassigns all editions to $targetId and deletes the source. Affected
     * editions get a new row_version. Must run inside a transaction.
     */
    public function mergeInto(int $sourceId, int $targetId): void
    {
        $this->run('UPDATE editions SET publisher_id = :t, row_version = row_version + 1 WHERE publisher_id = :s', ['t' => $targetId, 's' => $sourceId]);
        $this->run('DELETE FROM publishers WHERE id = :s', ['s' => $sourceId]);
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM publishers WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    public function options(int $excludeId): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $this->many(
            'SELECT id, name FROM publishers WHERE id <> :x ORDER BY name, id LIMIT 2000',
            ['x' => $excludeId],
        ));
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
