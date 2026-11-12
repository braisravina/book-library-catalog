<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Repository\AuthorRepository;
use DigitalLibrary\Catalogue\Repository\CopyRepository;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\PublisherRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Catalogue\Repository\WorkRepository;
use DigitalLibrary\Database\Transaction;
use PDO;

/**
 * Multi-table catalogue writes. Every method runs in one transaction, and
 * ISBN uniqueness is checked up front and enforced by the unique index (a
 * concurrent insert surfaces as DuplicateIsbnException, never a 500).
 */
final class BookService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WorkRepository $works,
        private readonly EditionRepository $editions,
        private readonly AuthorRepository $authors,
        private readonly PublisherRepository $publishers,
        private readonly CopyRepository $copies,
    ) {
    }

    /**
     * Creates a work with its first edition and, optionally, a first copy.
     *
     * @return array{work_id: int, edition_id: int, copy_id: ?int}
     *
     * @throws DuplicateIsbnException
     */
    public function createBook(WorkInput $work, EditionInput $edition, ?CopyInput $copy, int $userId, ?int $metadataRecordId = null): array
    {
        return $this->guardDuplicate(fn (): array => Transaction::run($this->pdo, function () use ($work, $edition, $copy, $userId, $metadataRecordId): array {
            $this->assertIsbnFree($edition, null);
            $workId = $this->works->create($work->toRecord(), $userId);
            $this->works->setAuthors($workId, $this->resolvePeople($work->authors, $userId));
            $this->works->setCategories($workId, $work->categoryIds);
            $editionId = $this->insertEdition($workId, $edition, $userId);
            if ($metadataRecordId !== null) {
                $this->editions->linkMetadata($editionId, $metadataRecordId);
            }
            $copyId = $copy === null ? null : $this->copies->create($editionId, $copy->toRecord(), $userId);

            return ['work_id' => $workId, 'edition_id' => $editionId, 'copy_id' => $copyId];
        }));
    }

    /**
     * @throws DuplicateIsbnException
     */
    public function addEdition(int $workId, EditionInput $edition, int $userId): int
    {
        return $this->guardDuplicate(fn (): int => Transaction::run($this->pdo, function () use ($workId, $edition, $userId): int {
            $this->assertIsbnFree($edition, null);

            return $this->insertEdition($workId, $edition, $userId);
        }));
    }

    /**
     * @throws DuplicateIsbnException
     * @throws EditConflictException  the edition changed after the form was loaded
     */
    public function updateEdition(int $editionId, EditionInput $edition, int $userId, int $expectedVersion): void
    {
        $this->guardDuplicate(fn () => Transaction::run($this->pdo, function () use ($editionId, $edition, $userId, $expectedVersion): void {
            $this->assertIsbnFree($edition, $editionId);
            if (!$this->editions->update($editionId, $edition->toRecord($this->publisherId($edition)), $userId, $expectedVersion)) {
                throw new EditConflictException();
            }
            $this->editions->setContributors($editionId, $this->resolvePeople($edition->contributors, $userId));
        }));
    }

    /**
     * @throws EditConflictException the work changed after the form was loaded
     */
    public function updateWork(int $workId, WorkInput $work, int $userId, int $expectedVersion): void
    {
        Transaction::run($this->pdo, function () use ($workId, $work, $userId, $expectedVersion): void {
            if (!$this->works->update($workId, $work->toRecord(), $userId, $expectedVersion)) {
                throw new EditConflictException();
            }
            $this->works->setAuthors($workId, $this->resolvePeople($work->authors, $userId));
            $this->works->setCategories($workId, $work->categoryIds);
        });
    }

    public function existingEditionForIsbn(Isbn $isbn, ?int $excludeId = null): ?int
    {
        return $this->editions->idByIsbn13($isbn->isbn13, $excludeId);
    }

    private function insertEdition(int $workId, EditionInput $edition, int $userId): int
    {
        $editionId = $this->editions->create($workId, $edition->toRecord($this->publisherId($edition)), $userId);
        $this->editions->setContributors($editionId, $this->resolvePeople($edition->contributors, $userId));

        return $editionId;
    }

    private function assertIsbnFree(EditionInput $edition, ?int $excludeId): void
    {
        $isbn = $edition->parsedIsbn();
        if ($isbn === null) {
            return;
        }
        $existing = $this->editions->idByIsbn13($isbn->isbn13, $excludeId);
        if ($existing !== null) {
            throw new DuplicateIsbnException($existing);
        }
    }

    private function publisherId(EditionInput $edition): ?int
    {
        return $edition->publisher === '' ? null : $this->publishers->findOrCreate($edition->publisher);
    }

    /**
     * @param list<array{name: string, role: string}> $people
     *
     * @return list<array{author_id: int, role: string}>
     */
    private function resolvePeople(array $people, int $userId): array
    {
        return array_map(fn (array $p): array => [
            'author_id' => $this->authors->findOrCreate($p['name'], $userId),
            'role' => $p['role'],
        ], $people);
    }

    /**
     * Converts a unique-index violation on the ISBN columns (a concurrent
     * insert won the race) into the same domain exception as the pre-check.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guardDuplicate(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e) && preg_match('/uq_editions_isbn_1[03]/', $e->getMessage())) {
                $existing = $this->duplicateFromMessage($e->getMessage());
                if ($existing !== null) {
                    throw new DuplicateIsbnException($existing);
                }
            }
            throw $e;
        }
    }

    private function duplicateFromMessage(string $message): ?int
    {
        if (!preg_match("/Duplicate entry '([0-9X]{10,13})'/", $message, $m)) {
            return null;
        }
        $column = strlen($m[1]) === 13 ? 'isbn_13' : 'isbn_10';
        $stmt = $this->pdo->prepare("SELECT id FROM editions WHERE {$column} = :v");
        $stmt->execute(['v' => $m[1]]);
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
