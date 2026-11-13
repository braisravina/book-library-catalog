<?php

declare(strict_types=1);

namespace DigitalLibrary\Wishlist;

use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\Visibility;

/**
 * SQL for wishlist entries. Every read is scoped by user; callers pass the
 * owner explicitly so there is no way to list another user's entries.
 */
final class WishlistRepository extends Repository
{
    public const PER_PAGE = 25;
    public const STATUSES = ['wanted', 'acquired', 'archived'];

    /**
     * Entry columns for the viewer (always the entry's owner): own_copies
     * counts their copies that are in view, and edition_visible tells
     * whether the linked catalogue edition may be linked to.
     */
    private static function select(Visibility $visibility, Params $params): string
    {
        return 'SELECT w.id, w.user_id, w.edition_id, w.work_id, w.title, w.author, w.isbn_13, w.priority, w.notes,
                w.status, w.status_changed_at, w.created_at, w.updated_at,
                (SELECT COUNT(*) FROM copies c WHERE c.edition_id = w.edition_id AND c.owner_user_id = w.user_id
                    AND ' . $visibility->copy('c', $params) . ') AS own_copies,
                EXISTS (SELECT 1 FROM editions le WHERE le.id = w.edition_id AND ' . $visibility->edition('le', $params) . ') AS edition_visible
            FROM wishlist_items w';
    }

    /**
     * Serialises wishlist changes of one user (duplicate checks run under it).
     */
    public function lockOwner(int $userId): bool
    {
        return $this->scalar('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id, Visibility $visibility): ?array
    {
        $params = new Params();

        return $this->one(self::select($visibility, $params) . ' WHERE w.id = ' . $params->add($id), $params->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lock(int $id): ?array
    {
        return $this->one('SELECT id, user_id, edition_id, work_id, title, author, isbn_13, priority, notes, status FROM wishlist_items WHERE id = :id FOR UPDATE', ['id' => $id]);
    }

    /**
     * A WANTED entry of this user that the given data would duplicate:
     * same ISBN-13, same catalogue edition or (for entries with neither)
     * the same title and author, compared case- and accent-insensitively.
     */
    public function wantedDuplicate(int $userId, ?string $isbn13, ?int $editionId, string $title, string $author, ?int $excludeId = null): ?int
    {
        $exclude = $excludeId === null ? '' : ' AND id <> :exclude';
        $params = ['user' => $userId] + ($excludeId === null ? [] : ['exclude' => $excludeId]);
        if ($isbn13 !== null || $editionId !== null) {
            $id = $this->scalar(
                "SELECT id FROM wishlist_items WHERE user_id = :user AND status = 'wanted'{$exclude}
                   AND (isbn_13 = :isbn OR edition_id = :edition) ORDER BY id LIMIT 1",
                $params + ['isbn' => $isbn13, 'edition' => $editionId],
            );
        } else {
            $id = $this->scalar(
                "SELECT id FROM wishlist_items WHERE user_id = :user AND status = 'wanted'{$exclude}
                   AND title = :title AND COALESCE(author, '') = :author ORDER BY id LIMIT 1",
                $params + ['title' => $title, 'author' => $author],
            );
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * Catalogue edition (and its work) holding this ISBN-13, if any.
     *
     * @return array{id: int, work_id: int}|null
     */
    public function editionForIsbn(string $isbn13): ?array
    {
        $row = $this->one('SELECT id, work_id FROM editions WHERE isbn_13 = :isbn', ['isbn' => $isbn13]);

        return $row === null ? null : ['id' => (int) $row['id'], 'work_id' => (int) $row['work_id']];
    }

    public function create(int $userId, ?int $editionId, ?int $workId, WishlistInput $input, ?string $isbn13): int
    {
        return $this->insert(
            'INSERT INTO wishlist_items (user_id, edition_id, work_id, title, author, isbn_13, priority, notes, status, created_at, updated_at)
             VALUES (:u, :e, :w, :t, :a, :i, :p, :n, \'wanted\', UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['u' => $userId, 'e' => $editionId, 'w' => $workId, 't' => $input->title, 'a' => $input->author === '' ? null : $input->author,
                'i' => $isbn13, 'p' => $input->priority, 'n' => $input->notes === '' ? null : $input->notes],
        );
    }

    public function update(int $id, ?int $editionId, ?int $workId, WishlistInput $input, ?string $isbn13): void
    {
        $this->run(
            'UPDATE wishlist_items SET edition_id = :e, work_id = :w, title = :t, author = :a, isbn_13 = :i, priority = :p, notes = :n,
                    updated_at = UTC_TIMESTAMP()
             WHERE id = :id',
            ['e' => $editionId, 'w' => $workId, 't' => $input->title, 'a' => $input->author === '' ? null : $input->author,
                'i' => $isbn13, 'p' => $input->priority, 'n' => $input->notes === '' ? null : $input->notes, 'id' => $id],
        );
    }

    public function setStatus(int $id, string $status): void
    {
        $this->run(
            'UPDATE wishlist_items SET status = :s, status_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP() WHERE id = :id',
            ['s' => $status, 'id' => $id],
        );
    }

    /**
     * @param string $status wanted | acquired | archived | all
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function forUser(int $userId, string $status, int $page, Visibility $visibility): array
    {
        $where = static fn (Params $params): string => ' WHERE w.user_id = ' . $params->add($userId)
            . ($status === 'all' ? '' : ' AND w.status = ' . $params->add($status));
        $order = $status === 'wanted'
            ? " ORDER BY FIELD(w.priority, 'high', 'normal', 'low'), w.created_at DESC, w.id DESC"
            : ' ORDER BY COALESCE(w.status_changed_at, w.created_at) DESC, w.id DESC';
        $params = new Params();
        $rows = $this->many(
            self::select($visibility, $params) . $where($params) . $order . ' LIMIT ' . $params->add(self::PER_PAGE)
                . ' OFFSET ' . $params->add((max(1, $page) - 1) * self::PER_PAGE),
            $params->all(),
        );
        $count = new Params();

        return [
            'rows' => $rows,
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM wishlist_items w' . $where($count), $count->all()),
        ];
    }

    /**
     * @return array<string, int> status => count
     */
    public function countsForUser(int $userId): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->many('SELECT status, COUNT(*) AS n FROM wishlist_items WHERE user_id = :u GROUP BY status', ['u' => $userId]) as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The user's wanted entry for a catalogue edition (by link or ISBN).
     *
     * @return array<string, mixed>|null
     */
    public function wantedForEdition(int $userId, int $editionId, ?string $isbn13): ?array
    {
        return $this->one(
            "SELECT id, priority FROM wishlist_items
             WHERE user_id = :u AND status = 'wanted' AND (edition_id = :e OR isbn_13 = :i) ORDER BY id LIMIT 1",
            ['u' => $userId, 'e' => $editionId, 'i' => $isbn13],
        );
    }
}
