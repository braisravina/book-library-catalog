<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Repository\AuthorRepository;
use DigitalLibrary\Catalogue\Repository\CoverRepository;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\PublisherRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Catalogue\Repository\WorkRepository;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Upload\StoredImage;
use PDO;

/**
 * Multi-table catalogue writes. Every method is one guarded transaction
 * (Households\WriteGuard), and ISBN uniqueness is checked up front and
 * enforced by the unique index (a concurrent insert surfaces as
 * DuplicateIsbnException, never a 500).
 *
 * Shared bibliographic records (phase H3). Works and editions belong to
 * no household: one record describes the book for everyone who holds a
 * copy. Changing them therefore follows one rule, checked inside the
 * transaction against the locked record:
 *
 *  - administrators (catalogue.edit_any) change any record;
 *  - a member (catalogue.edit_own) changes a record they created, and
 *    only while it is not held outside their households: no copy of the
 *    edition (for a work: of any of its editions) belongs to a household
 *    they are not a member of, and no edition of the work is out of their
 *    view. Once another household holds the book, its description is
 *    changed by an administrator;
 *  - covers follow the rule of their edition;
 *  - seeing a record never implies being allowed to change it.
 *
 * The record is locked exclusively before the rule is evaluated, and
 * every inventory write locks its edition and work shared (CopyService),
 * so "nobody else holds it" cannot be overtaken by a copy that another
 * household is adding at that moment. A record outside the actor's view
 * is WriteDeniedException::notFound(), exactly like a missing id; a
 * record they see but may not change is forbidden() and says nothing
 * about who else holds it.
 */
final class BookService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WriteGuard $guard,
        private readonly WorkRepository $works,
        private readonly EditionRepository $editions,
        private readonly AuthorRepository $authors,
        private readonly PublisherRepository $publishers,
        private readonly CoverRepository $covers,
        private readonly CopyService $copies,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * Creates a work with its first edition and, optionally, a first copy
     * (placed and checked like any other copy: CopyService).
     *
     * @return array{work_id: int, edition_id: int, copy_id: ?int, copy_household_id: ?int}
     *
     * @throws DuplicateIsbnException
     * @throws CopyValidationException the first copy was refused
     * @throws WriteDeniedException
     */
    public function createBook(int $actorId, WorkInput $work, EditionInput $edition, ?CopyInput $copy, ?int $metadataRecordId = null): array
    {
        return $this->guardDuplicate(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($work, $edition, $copy, $metadataRecordId): array {
            $this->requireCreate($actor);
            $userId = $actor->user->id;
            $this->assertIsbnFree($edition, null);
            $workId = $this->works->create($work->toRecord(), $userId);
            $this->works->setAuthors($workId, $this->resolvePeople($work->authors, $userId));
            $this->works->setCategories($workId, $work->categoryIds);
            $editionId = $this->insertEdition($workId, $edition, $userId);
            if ($metadataRecordId !== null) {
                $this->editions->linkMetadata($editionId, $metadataRecordId);
            }
            $created = $copy === null ? null : $this->copies->createWithin(
                $actor,
                ['id' => $editionId, 'work_id' => $workId, 'created_by' => $userId, 'isbn_13' => $edition->parsedIsbn()?->isbn13, 'title' => $edition->title],
                $copy,
            );

            $this->guard->audit('work.created', $userId, 'work', $workId, ['title' => $work->title]);
            $this->guard->audit('edition.created', $userId, 'edition', $editionId, ['isbn_13' => $edition->parsedIsbn()?->isbn13]);
            return ['work_id' => $workId, 'edition_id' => $editionId, 'copy_id' => $created['copy_id'] ?? null, 'copy_household_id' => $created['household_id'] ?? null];
        }, accountIds: $copy === null ? [] : [$copy->ownerId()]));
    }

    /**
     * Adds an edition to a work the actor can see. It describes a new
     * publication and changes nothing of the work itself.
     *
     * @throws DuplicateIsbnException
     * @throws WriteDeniedException
     */
    public function addEdition(int $actorId, int $workId, EditionInput $edition): int
    {
        return $this->guardDuplicate(fn (): int => $this->guard->run($actorId, function (WriteActor $actor) use ($workId, $edition): int {
            $this->requireCreate($actor);
            if ($this->works->lock($workId, false) === null || $this->works->find($workId, $actor->visibility) === null) {
                throw WriteDeniedException::notFound();
            }
            $this->assertIsbnFree($edition, null);

            $id = $this->insertEdition($workId, $edition, $actor->user->id);
            $this->guard->audit('edition.created', $actor->user->id, 'edition', $id, ['work_id' => $workId, 'isbn_13' => $edition->parsedIsbn()?->isbn13]);
            return $id;
        }));
    }

    /**
     * @throws DuplicateIsbnException
     * @throws EditConflictException  the edition changed after the form was loaded
     * @throws WriteDeniedException
     */
    public function updateEdition(int $actorId, int $editionId, EditionInput $edition, int $expectedVersion): void
    {
        $this->guardDuplicate(fn () => $this->guard->run($actorId, function (WriteActor $actor) use ($editionId, $edition, $expectedVersion): void {
            $this->lockEditableEdition($actor, $editionId);
            $userId = $actor->user->id;
            $this->assertIsbnFree($edition, $editionId);
            if (!$this->editions->update($editionId, $edition->toRecord($this->publisherId($edition)), $userId, $expectedVersion)) {
                throw new EditConflictException();
            }
            $this->editions->setContributors($editionId, $this->resolvePeople($edition->contributors, $userId));
            $this->guard->audit('edition.updated', $userId, 'edition', $editionId);
        }));
    }

    /**
     * @throws EditConflictException the work changed after the form was loaded
     * @throws WriteDeniedException
     */
    public function updateWork(int $actorId, int $workId, WorkInput $work, int $expectedVersion): void
    {
        $this->guard->run($actorId, function (WriteActor $actor) use ($workId, $work, $expectedVersion): void {
            $locked = $this->works->lock($workId, true);
            if ($locked === null || $this->works->find($workId, $actor->visibility) === null) {
                throw WriteDeniedException::notFound();
            }
            if (!$this->authorizer->canEditSharedRecord($actor->user, $locked['created_by'], $this->works->sharedBeyondView($workId, $actor->visibility))) {
                throw WriteDeniedException::forbidden();
            }
            $userId = $actor->user->id;
            if (!$this->works->update($workId, $work->toRecord(), $userId, $expectedVersion)) {
                throw new EditConflictException();
            }
            $this->works->setAuthors($workId, $this->resolvePeople($work->authors, $userId));
            $this->works->setCategories($workId, $work->categoryIds);
            $this->guard->audit('work.updated', $userId, 'work', $workId);
        });
    }

    /**
     * Stores an uploaded or imported cover as the edition's primary cover.
     * The image file is written before this is called; the caller removes
     * it again when this throws.
     *
     * @throws WriteDeniedException
     */
    public function addCover(int $actorId, int $editionId, StoredImage $image, string $action = 'cover.uploaded', array $details = []): int
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($editionId, $image, $action, $details): int {
            $this->lockEditableEdition($actor, $editionId);

            $id = $this->covers->addPrimary($editionId, $image, $actor->user->id);
            $this->guard->audit($action, $actor->user->id, 'cover', $id, ['edition_id' => $editionId, 'sha256' => $image->sha256] + $details);
            return $id;
        });
    }

    /**
     * Removes a cover record. The caller deletes the image file afterwards.
     *
     * @return array{edition_id: int, storage_key: string}
     *
     * @throws WriteDeniedException
     */
    public function deleteCover(int $actorId, int $coverId): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($coverId): array {
            // Found through the actor's view (404 otherwise), then locked through its edition.
            $cover = $this->covers->find($coverId, $actor->visibility) ?? throw WriteDeniedException::notFound();
            $editionId = (int) $cover['edition_id'];
            $this->lockEditableEdition($actor, $editionId);
            if (!$this->covers->delete($coverId, $editionId)) {
                throw WriteDeniedException::notFound();
            }

            $this->guard->audit('cover.deleted', $actor->user->id, 'cover', $coverId, ['edition_id' => $editionId]);
            return ['edition_id' => $editionId, 'storage_key' => (string) $cover['storage_key']];
        });
    }

    /**
     * Administrators only (catalogue.delete). Refused while editions exist.
     *
     * @return array{title: string}
     *
     * @throws RecordInUseException
     * @throws WriteDeniedException
     */
    public function deleteWork(int $actorId, int $workId): array
    {
        return $this->guardInUse(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($workId): array {
            $this->requireDelete($actor);
            $work = $this->works->lock($workId, true) ?? throw WriteDeniedException::notFound();
            if ($this->works->editionCount($workId) > 0) {
                throw new RecordInUseException();
            }
            $this->works->delete($workId);
            $this->guard->audit('work.deleted', $actor->user->id, 'work', $workId, ['title' => $work['title']]);

            return ['title' => $work['title']];
        }));
    }

    /**
     * Administrators only (catalogue.delete). Refused while physical
     * copies exist, in whichever household.
     *
     * @return array{title: string, isbn_13: ?string, work_id: int, storage_keys: list<string>}
     *         the cover files to remove once the transaction has ended
     *
     * @throws RecordInUseException
     * @throws WriteDeniedException
     */
    public function deleteEdition(int $actorId, int $editionId): array
    {
        return $this->guardInUse(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($editionId): array {
            $this->requireDelete($actor);
            $edition = $this->editions->lock($editionId, true) ?? throw WriteDeniedException::notFound();
            if ($this->editions->copyCount($editionId) > 0) {
                throw new RecordInUseException();
            }
            $keys = [];
            foreach ($this->covers->forEdition($editionId) as $cover) {
                $this->covers->delete((int) $cover['id'], $editionId);
                $keys[] = (string) $cover['storage_key'];
            }
            $this->editions->delete($editionId);
            $this->guard->audit('edition.deleted', $actor->user->id, 'edition', $editionId, ['title' => $edition['title'], 'isbn_13' => $edition['isbn_13']]);

            return ['title' => $edition['title'], 'isbn_13' => $edition['isbn_13'], 'work_id' => $edition['work_id'], 'storage_keys' => $keys];
        }));
    }

    public function existingEditionForIsbn(Isbn $isbn, ?int $excludeId = null): ?int
    {
        return $this->editions->idByIsbn13($isbn->isbn13, $excludeId);
    }

    /**
     * Locks the edition exclusively and applies the editing rule for
     * shared records: 404 when it is out of the actor's view, 403 when
     * they see it but may not change it.
     *
     * @return array{id: int, work_id: int, created_by: ?int, isbn_13: ?string, title: string}
     */
    private function lockEditableEdition(WriteActor $actor, int $editionId): array
    {
        $edition = $this->editions->lock($editionId, true);
        if ($edition === null || !$this->editions->isVisible($editionId, $actor->visibility)) {
            throw WriteDeniedException::notFound();
        }
        if (!$this->authorizer->canEditSharedRecord($actor->user, $edition['created_by'], $this->editions->heldOutsideView($editionId, $actor->visibility))) {
            throw WriteDeniedException::forbidden();
        }

        return $edition;
    }

    private function requireCreate(WriteActor $actor): void
    {
        if (!$this->authorizer->can($actor->user, Permission::CatalogueCreate)) {
            throw WriteDeniedException::forbidden();
        }
    }

    private function requireDelete(WriteActor $actor): void
    {
        if (!$this->authorizer->can($actor->user, Permission::CatalogueDelete)) {
            throw WriteDeniedException::forbidden();
        }
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

    /**
     * A record that gained an edition or a copy at the same moment (the
     * foreign key refuses the delete) is reported like the pre-check.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guardInUse(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw new RecordInUseException();
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
