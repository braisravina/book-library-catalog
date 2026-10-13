<?php

declare(strict_types=1);

namespace DigitalLibrary\Collections;

use DigitalLibrary\Catalogue\Repository\Repository;

/**
 * SQL for collections and their (edition-level) items. Visibility filtering
 * happens here for lists; single records are checked by the Authorizer.
 */
final class CollectionRepository extends Repository
{
    public const PER_PAGE = 25;
    public const ITEMS_PER_PAGE = 50;
    public const MAX_ITEMS = 2000;

    private const SELECT = 'SELECT c.id, c.owner_user_id, c.name, c.description, c.visibility, c.created_at, c.updated_at,
            u.display_name AS owner_name,
            (SELECT COUNT(*) FROM collection_items ci WHERE ci.collection_id = c.id) AS item_count
        FROM collections c
        JOIN users u ON u.id = c.owner_user_id';

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE c.id = :id', ['id' => $id]);
    }

    /**
     * Locks the collection row; item changes of one collection are serialised by it.
     *
     * @return array<string, mixed>|null
     */
    public function lock(int $id): ?array
    {
        return $this->one('SELECT id, owner_user_id, name, visibility FROM collections WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * @param string $scope mine | shared (other users' shared) | all (every collection; administrators only)
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(string $scope, int $userId, int $page): array
    {
        [$where, $params] = match ($scope) {
            'mine' => [' WHERE c.owner_user_id = :user', ['user' => $userId]],
            'all' => ['', []],
            default => [" WHERE c.visibility = 'shared' AND c.owner_user_id <> :user", ['user' => $userId]],
        };

        return [
            'rows' => $this->many(self::SELECT . $where . ' ORDER BY c.name, c.id LIMIT :limit OFFSET :offset',
                $params + ['limit' => self::PER_PAGE, 'offset' => (max(1, $page) - 1) * self::PER_PAGE]),
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM collections c' . $where, $params),
        ];
    }

    public function nameTaken(int $ownerId, string $name, ?int $exceptId = null): bool
    {
        return $this->scalar(
            'SELECT 1 FROM collections WHERE owner_user_id = :o AND name = :n' . ($exceptId === null ? '' : ' AND id <> :id'),
            ['o' => $ownerId, 'n' => $name] + ($exceptId === null ? [] : ['id' => $exceptId]),
        ) !== null;
    }

    public function create(int $ownerId, CollectionInput $input): int
    {
        return $this->insert(
            'INSERT INTO collections (owner_user_id, name, description, visibility, created_at, updated_at)
             VALUES (:o, :n, :d, :v, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['o' => $ownerId, 'n' => $input->name, 'd' => $input->description === '' ? null : $input->description, 'v' => $input->visibility],
        );
    }

    public function update(int $id, CollectionInput $input): void
    {
        $this->run(
            'UPDATE collections SET name = :n, description = :d, visibility = :v, updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['n' => $input->name, 'd' => $input->description === '' ? null : $input->description, 'v' => $input->visibility, 'id' => $id],
        );
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM collections WHERE id = :id', ['id' => $id]);
    }

    public function touch(int $id): void
    {
        $this->run('UPDATE collections SET updated_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function items(int $collectionId, int $page): array
    {
        return [
            'rows' => $this->many(
                "SELECT ci.edition_id, ci.position, ci.added_at, e.title, e.subtitle, e.publication_year,
                        (SELECT cv.id FROM covers cv WHERE cv.edition_id = e.id AND cv.is_primary = 1) AS cover_id,
                        (SELECT GROUP_CONCAT(a.name ORDER BY wa.position SEPARATOR ', ')
                           FROM work_authors wa JOIN authors a ON a.id = wa.author_id
                          WHERE wa.work_id = e.work_id AND wa.role = 'author') AS authors
                 FROM collection_items ci
                 JOIN editions e ON e.id = ci.edition_id
                 WHERE ci.collection_id = :c
                 ORDER BY ci.position, ci.edition_id
                 LIMIT :limit OFFSET :offset",
                ['c' => $collectionId, 'limit' => self::ITEMS_PER_PAGE, 'offset' => (max(1, $page) - 1) * self::ITEMS_PER_PAGE],
            ),
            'total' => $this->countItems($collectionId),
        ];
    }

    public function countItems(int $collectionId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM collection_items WHERE collection_id = :c', ['c' => $collectionId]);
    }

    public function hasItem(int $collectionId, int $editionId): bool
    {
        return $this->scalar('SELECT 1 FROM collection_items WHERE collection_id = :c AND edition_id = :e', ['c' => $collectionId, 'e' => $editionId]) !== null;
    }

    public function addItem(int $collectionId, int $editionId, int $userId): void
    {
        $this->run(
            'INSERT INTO collection_items (collection_id, edition_id, position, added_by, added_at)
             SELECT :c, :e, COALESCE(MAX(position), 0) + 1, :u, UTC_TIMESTAMP() FROM collection_items WHERE collection_id = :c2',
            ['c' => $collectionId, 'e' => $editionId, 'u' => $userId, 'c2' => $collectionId],
        );
    }

    public function removeItem(int $collectionId, int $editionId): bool
    {
        return $this->run('DELETE FROM collection_items WHERE collection_id = :c AND edition_id = :e', ['c' => $collectionId, 'e' => $editionId])->rowCount() > 0;
    }

    /**
     * Current order as edition id => position (bounded by MAX_ITEMS).
     *
     * @return array<int, int>
     */
    public function positions(int $collectionId): array
    {
        $out = [];
        foreach ($this->many('SELECT edition_id, position FROM collection_items WHERE collection_id = :c ORDER BY position, edition_id', ['c' => $collectionId]) as $row) {
            $out[(int) $row['edition_id']] = (int) $row['position'];
        }

        return $out;
    }

    public function setPosition(int $collectionId, int $editionId, int $position): void
    {
        $this->run('UPDATE collection_items SET position = :p WHERE collection_id = :c AND edition_id = :e', ['p' => $position, 'c' => $collectionId, 'e' => $editionId]);
    }

    /**
     * Collections containing an edition that the given user may see.
     *
     * @return list<array<string, mixed>>
     */
    public function containing(int $editionId, int $userId, bool $seeAll): array
    {
        return $this->many(
            'SELECT c.id, c.name, c.visibility, c.owner_user_id FROM collections c
             JOIN collection_items ci ON ci.collection_id = c.id AND ci.edition_id = :e
             WHERE ' . ($seeAll ? '1 = 1' : "(c.owner_user_id = :u OR c.visibility = 'shared')") . '
             ORDER BY c.name, c.id LIMIT 100',
            ['e' => $editionId] + ($seeAll ? [] : ['u' => $userId]),
        );
    }

    /**
     * The user's own collections that do not contain the edition yet.
     *
     * @return list<array<string, mixed>>
     */
    public function addableFor(int $editionId, int $userId): array
    {
        return $this->many(
            'SELECT c.id, c.name FROM collections c
             WHERE c.owner_user_id = :u
               AND NOT EXISTS (SELECT 1 FROM collection_items ci WHERE ci.collection_id = c.id AND ci.edition_id = :e)
             ORDER BY c.name, c.id LIMIT 200',
            ['u' => $userId, 'e' => $editionId],
        );
    }
}
