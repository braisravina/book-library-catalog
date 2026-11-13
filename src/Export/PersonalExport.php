<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

use DigitalLibrary\Database\Params;
use DigitalLibrary\Security\Visibility;
use PDO;

/**
 * A user's own data. The user id always comes from the authenticated
 * session (never from the request), so there is nothing to tamper with.
 *
 * Included: the user's account profile (no password hash or session data),
 * wishlist entries, owned copies, own collections (private and shared) with
 * their items, and loans the user may see: loans of copies they own (with
 * the borrower, as on the copy page) and loans where they are the borrower.
 *
 * Households (phase H2): the export follows what the user may see now.
 * Copies and loans of copies they own are included only while the copy is
 * in one of their households; collection items only for visible editions;
 * locations only of their households. Loans where they are the borrower
 * are always included (their own history) but, when the copy has left
 * their view, with the title and dates only. Wishlist links to a catalogue
 * record are given only for records they may see.
 */
final class PersonalExport extends ExportSource
{
    public const DATASETS = ['account', 'wishlist', 'copies', 'collections', 'collection_items', 'loans'];

    public function __construct(PDO $pdo, private readonly int $userId, private readonly Visibility $visibility)
    {
        parent::__construct($pdo);
    }

    /**
     * @return array<string, Dataset>
     */
    public function datasets(): array
    {
        $user = ['owner' => $this->userId];
        $copies = new Params();
        $copyWhere = 'c.owner_user_id = :owner AND ' . $this->visibility->copy('c', $copies);
        $locations = new Params();
        $locationWhere = $this->visibility->location('t', $locations);
        $items = new Params();
        $itemWhere = 'c.owner_user_id = :owner AND ' . $this->visibility->edition('e', $items);
        $all = [
            new Dataset('account', ['id', 'display_name', 'email', 'role', 'locale', 'created_at'], fn () => $this->account()),
            new Dataset('wishlist', ['id', 'title', 'author', 'isbn_13', 'edition_id', 'work_id', 'priority', 'notes', 'status',
                'status_changed_at', 'created_at', 'updated_at'], fn () => $this->wishlist()),
            new Dataset('copies', self::COPY_COLUMNS, fn () => $this->copyRows($copyWhere, $user + $copies->all(), $this->treePaths('locations', $locationWhere, $locations->all()))),
            new Dataset('collections', self::COLLECTION_COLUMNS, fn () => $this->collectionRows('c.owner_user_id = :owner', $user)),
            new Dataset('collection_items', self::COLLECTION_ITEM_COLUMNS, fn () => $this->collectionItemRows($itemWhere, $user + $items->all())),
            new Dataset('loans', ['id', 'my_role', 'copy_id', 'edition_id', 'edition_title', 'owner_user_id', 'owner', 'borrower_user_id', 'borrower',
                'loaned_on', 'due_on', 'returned_on', 'notes', 'created_at'], fn () => $this->loans()),
        ];
        $out = [];
        foreach ($all as $dataset) {
            $out[$dataset->name] = $dataset;
        }

        return $out;
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function account(): \Generator
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.id, u.display_name, u.email, r.code AS role, u.locale, u.created_at FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = :id'
        );
        $stmt->execute(['id' => $this->userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            yield ['id' => (int) $row['id'], 'created_at' => self::dateTime($row['created_at'])] + $row;
        }
    }

    /**
     * @return \Generator<array<string, mixed>>
     */
    private function wishlist(): \Generator
    {
        $params = new Params();
        // The link to a catalogue record is given only when it may be seen.
        // (Built once per use: a named placeholder cannot appear twice.)
        $linked = fn (): string => 'EXISTS (SELECT 1 FROM editions le WHERE le.id = w.edition_id AND ' . $this->visibility->edition('le', $params) . ')';
        foreach ($this->keyset(
            'SELECT w.id, w.title, w.author, w.isbn_13, CASE WHEN ' . $linked() . ' THEN w.edition_id END AS edition_id,
                    CASE WHEN ' . $linked() . ' THEN w.work_id END AS work_id, w.priority, w.notes, w.status, w.status_changed_at,
                    w.created_at, w.updated_at FROM wishlist_items w',
            'w.user_id = :owner',
            ['owner' => $this->userId] + $params->all(),
            [['w.id', 'id', 0]],
        ) as $row) {
            yield [
                'id' => (int) $row['id'],
                'edition_id' => $row['edition_id'] === null ? null : (int) $row['edition_id'],
                'work_id' => $row['work_id'] === null ? null : (int) $row['work_id'],
                'status_changed_at' => self::dateTime($row['status_changed_at']),
                'created_at' => self::dateTime($row['created_at']),
                'updated_at' => self::dateTime($row['updated_at']),
            ] + $row;
        }
    }

    /**
     * Loans of the user's own copies while those copies are in view, and
     * every loan where the user is the borrower. For a borrowed copy that
     * is no longer in view only the title and the dates are given: the
     * copy, its owner and the lender's notes are withheld.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function loans(): \Generator
    {
        $params = new Params();
        $visible = fn (): string => $this->visibility->copy('c', $params);
        foreach ($this->keyset(
            'SELECT l.id, l.copy_id, c.edition_id, e.title AS edition_title, c.owner_user_id, o.display_name AS owner,
                    l.borrower_user_id, COALESCE(b.display_name, l.borrower_name) AS borrower, l.loaned_on, l.due_on, l.returned_on,
                    l.notes, l.created_at, (' . $visible() . ') AS copy_visible
             FROM loans l JOIN copies c ON c.id = l.copy_id JOIN editions e ON e.id = c.edition_id
             JOIN users o ON o.id = c.owner_user_id LEFT JOIN users b ON b.id = l.borrower_user_id',
            '(c.owner_user_id = :owner AND ' . $visible() . ') OR l.borrower_user_id = :borrower',
            ['owner' => $this->userId, 'borrower' => $this->userId] + $params->all(),
            [['l.id', 'id', 0]],
        ) as $row) {
            $inView = (int) $row['copy_visible'] === 1;
            unset($row['copy_visible']);
            $mine = $inView && (int) $row['owner_user_id'] === $this->userId;
            yield [
                'id' => (int) $row['id'],
                'my_role' => $mine ? 'lender' : 'borrower',
                'copy_id' => $inView ? (int) $row['copy_id'] : null,
                'edition_id' => $inView ? (int) $row['edition_id'] : null,
                'owner_user_id' => $inView ? (int) $row['owner_user_id'] : null,
                'owner' => $inView ? $row['owner'] : null,
                'borrower_user_id' => $row['borrower_user_id'] === null ? null : (int) $row['borrower_user_id'],
                'notes' => $inView ? $row['notes'] : null,
                'created_at' => self::dateTime($row['created_at']),
            ] + $row;
        }
    }
}
