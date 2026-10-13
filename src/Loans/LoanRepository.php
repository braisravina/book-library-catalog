<?php

declare(strict_types=1);

namespace DigitalLibrary\Loans;

use DigitalLibrary\Catalogue\Repository\Repository;

final class LoanRepository extends Repository
{
    public const PER_PAGE = 25;

    /** Columns for loan listings (copy, edition, owner and borrower names). */
    private const SELECT = "SELECT l.id, l.copy_id, l.borrower_user_id, l.borrower_name, l.loaned_on, l.due_on, l.returned_on,
            l.notes, l.created_at, c.owner_user_id, c.edition_id, c.inventory_code, e.title AS edition_title,
            ou.display_name AS owner_name, bu.display_name AS borrower_display_name,
            (l.returned_on IS NULL AND l.due_on IS NOT NULL AND l.due_on < UTC_DATE()) AS is_overdue
        FROM loans l
        JOIN copies c ON c.id = l.copy_id
        JOIN editions e ON e.id = c.edition_id
        JOIN users ou ON ou.id = c.owner_user_id
        LEFT JOIN users bu ON bu.id = l.borrower_user_id";

    /**
     * Locks the copy row for the rest of the transaction.
     *
     * @return array<string, mixed>|null
     */
    public function lockCopy(int $copyId): ?array
    {
        return $this->one('SELECT id, owner_user_id, status, edition_id FROM copies WHERE id = :id FOR UPDATE', ['id' => $copyId]);
    }

    /**
     * Locks the loan row for the rest of the transaction.
     *
     * @return array<string, mixed>|null
     */
    public function lockLoan(int $loanId): ?array
    {
        return $this->one('SELECT id, copy_id, loaned_on, returned_on FROM loans WHERE id = :id FOR UPDATE', ['id' => $loanId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE l.id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeForCopy(int $copyId): ?array
    {
        return $this->one(self::SELECT . ' WHERE l.copy_id = :id AND l.returned_on IS NULL', ['id' => $copyId]);
    }

    /**
     * Active loans keyed by copy id (for edition pages).
     *
     * @param list<int> $copyIds
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForCopies(array $copyIds): array
    {
        if ($copyIds === []) {
            return [];
        }
        $params = [];
        $names = [];
        foreach (array_values(array_unique($copyIds)) as $i => $id) {
            $params['c' . $i] = $id;
            $names[] = ':c' . $i;
        }
        $out = [];
        foreach ($this->many(self::SELECT . ' WHERE l.returned_on IS NULL AND l.copy_id IN (' . implode(', ', $names) . ')', $params) as $row) {
            $out[(int) $row['copy_id']] = $row;
        }

        return $out;
    }

    public function create(int $copyId, ?int $borrowerUserId, ?string $borrowerName, string $loanedOn, ?string $dueOn, ?string $notes, int $userId): int
    {
        return $this->insert(
            'INSERT INTO loans (copy_id, borrower_user_id, borrower_name, loaned_on, due_on, notes, created_by, created_at, updated_at)
             VALUES (:c, :bu, :bn, :lo, :du, :n, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['c' => $copyId, 'bu' => $borrowerUserId, 'bn' => $borrowerName, 'lo' => $loanedOn, 'du' => $dueOn, 'n' => $notes, 'u' => $userId],
        );
    }

    public function markReturned(int $loanId, string $returnedOn, int $userId): void
    {
        $this->run(
            'UPDATE loans SET returned_on = :r, returned_by = :u, updated_at = UTC_TIMESTAMP() WHERE id = :id AND returned_on IS NULL',
            ['r' => $returnedOn, 'u' => $userId, 'id' => $loanId],
        );
    }

    public function setCopyStatus(int $copyId, string $status): void
    {
        $this->run('UPDATE copies SET status = :s, row_version = row_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id', ['s' => $status, 'id' => $copyId]);
    }

    public function countForCopy(int $copyId): int
    {
        return (int) $this->scalar('SELECT COUNT(*) FROM loans WHERE copy_id = :id', ['id' => $copyId]);
    }

    /**
     * Full history of one copy, newest first (bounded).
     *
     * @return list<array<string, mixed>>
     */
    public function historyForCopy(int $copyId, int $limit = 200): array
    {
        return $this->many(self::SELECT . ' WHERE l.copy_id = :id ORDER BY l.loaned_on DESC, l.id DESC LIMIT :l', ['id' => $copyId, 'l' => $limit]);
    }

    /**
     * Paginated loan list.
     *
     * @param ?int   $ownerId    restrict to copies owned by this user (null: any owner)
     * @param ?int   $borrowerId restrict to loans to this user
     * @param string $status     active | overdue | returned | all
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function search(?int $ownerId, ?int $borrowerId, string $status, int $page): array
    {
        $where = [];
        $params = [];
        if ($ownerId !== null) {
            $where[] = 'c.owner_user_id = :owner';
            $params['owner'] = $ownerId;
        }
        if ($borrowerId !== null) {
            $where[] = 'l.borrower_user_id = :borrower';
            $params['borrower'] = $borrowerId;
        }
        $where[] = match ($status) {
            'overdue' => 'l.returned_on IS NULL AND l.due_on IS NOT NULL AND l.due_on < UTC_DATE()',
            'returned' => 'l.returned_on IS NOT NULL',
            'all' => '1 = 1',
            default => 'l.returned_on IS NULL',
        };
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $order = $status === 'returned' || $status === 'all'
            ? ' ORDER BY l.loaned_on DESC, l.id DESC'
            : ' ORDER BY l.due_on IS NULL, l.due_on, l.loaned_on, l.id';
        $total = (int) $this->scalar(
            'SELECT COUNT(*) FROM loans l JOIN copies c ON c.id = l.copy_id' . $whereSql,
            $params,
        );
        $rows = $this->many(
            self::SELECT . $whereSql . $order . ' LIMIT :limit OFFSET :offset',
            $params + ['limit' => self::PER_PAGE, 'offset' => (max(1, $page) - 1) * self::PER_PAGE],
        );

        return ['rows' => $rows, 'total' => $total];
    }

    public function countOverdueForOwner(int $ownerId): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM loans l JOIN copies c ON c.id = l.copy_id
             WHERE c.owner_user_id = :o AND l.returned_on IS NULL AND l.due_on IS NOT NULL AND l.due_on < UTC_DATE()',
            ['o' => $ownerId],
        );
    }
}
