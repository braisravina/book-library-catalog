<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Repository\CopyRepository;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Catalogue\Repository\WorkRepository;
use DigitalLibrary\Households\CopyHousehold;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Loans\LoanRepository;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;

/**
 * Creating, editing and deleting physical copies (phase H3).
 *
 * Every method is one guarded transaction (Households\WriteGuard): the
 * actor and their memberships are read again under the household locks,
 * the edition and its work are locked shared and the copy exclusively,
 * and every rule is then checked against those rows, not against what the
 * form or an earlier read said:
 *
 *  - a copy outside the actor's households does not exist for them
 *    (WriteDeniedException::notFound(), the same answer as a missing id);
 *  - only its owner (copy.manage_own) or an administrator may change it,
 *    and giving a copy to another owner needs copy.manage_any;
 *  - the household comes from Households\CopyHousehold, the owner must be
 *    able to reach it and the location must belong to it;
 *  - the inventory code is unique within the household;
 *  - "on loan" is driven by the loans table, and a copy with an open loan
 *    cannot change household.
 *
 * A copy may also be added to a book that is already catalogued by
 * naming its ISBN (createByIsbn): the shared work and edition are reused,
 * whoever holds them. Nothing about other households' copies is read or
 * returned, and no edition id is ever accepted for an edition the actor
 * cannot see.
 */
final class CopyService
{
    public function __construct(
        private readonly WriteGuard $guard,
        private readonly CopyRepository $copies,
        private readonly EditionRepository $editions,
        private readonly WorkRepository $works,
        private readonly LocationRepository $locations,
        private readonly LoanRepository $loans,
        private readonly UserRepository $users,
        private readonly CopyHousehold $households,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * Adds a copy to an edition the actor can see.
     *
     * @return array{copy_id: int, edition_id: int, household_id: ?int}
     *
     * @throws CopyValidationException
     * @throws WriteDeniedException
     */
    public function create(int $actorId, int $editionId, CopyInput $input): array
    {
        return $this->guardCode(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($editionId, $input): array {
            $edition = $this->editions->lock($editionId, false);
            // An edition out of view is answered like one that does not exist:
            // an id opens nothing (adding a copy of a book held elsewhere goes by ISBN).
            if ($edition === null || !$this->editions->isVisible($editionId, $actor->visibility)) {
                throw WriteDeniedException::notFound();
            }

            return $this->createWithin($actor, $edition, $input);
        }, accountIds: [$input->ownerId()]));
    }

    /**
     * Adds a copy of a book that is already catalogued, found by its
     * ISBN-13. The edition need not be visible to the actor: ISBNs are
     * unique across the shared catalogue, so this is how a second
     * household records its own copy without duplicating the edition.
     *
     * @return array{copy_id: int, edition_id: int, household_id: ?int}
     *
     * @throws CopyValidationException
     * @throws WriteDeniedException notFound when no edition holds the ISBN (any more)
     */
    public function createByIsbn(int $actorId, Isbn $isbn, CopyInput $input): array
    {
        return $this->guardCode(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($isbn, $input): array {
            $edition = $this->editions->lockByIsbn13($isbn->isbn13);
            if ($edition === null) {
                throw WriteDeniedException::notFound();
            }

            return $this->createWithin($actor, $edition, $input, ['via' => 'isbn']);
        }, accountIds: [$input->ownerId()]));
    }

    /**
     * @return array{edition_id: int, owner_from: int, owner_to: int, household_from: ?int, household_to: ?int}
     *
     * @throws CopyValidationException
     * @throws EditConflictException the copy changed after the form was loaded
     * @throws WriteDeniedException
     */
    public function update(int $actorId, int $copyId, CopyInput $input, int $expectedVersion): array
    {
        return $this->guardCode(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($copyId, $input, $expectedVersion): array {
            $copy = $this->lockManaged($actor, $copyId, true);
            $this->requireOwnerChoice($actor, $input);
            // H4: edits cannot bypass reviewed cross-household or ownership workflows.
            if ($this->guard->enabled() && (($input->householdId !== '' && $input->householdId !== (string) ($copy['household_id'] ?? ''))
                || $input->ownerId() !== (int) $copy['owner_user_id'])) {
                throw new CopyValidationException(['household_id' => ['transfers.error.workflow']]);
            }
            // Even feature-off legacy ownership edits must not expose historical borrowers.
            if ($input->ownerId() !== (int) $copy['owner_user_id'] && $this->loans->hasAnyLoan($copyId)) {
                throw new CopyValidationException(['owner_user_id' => ['transfers.error.owner_history']]);
            }

            $householdId = $this->validate($actor, $input, $copy);
            if (!$this->copies->update($copyId, ['household_id' => $householdId] + $input->toRecord(), $actor->user->id, $expectedVersion)) {
                throw new EditConflictException();
            }

            $details = ['edition_id' => (int) $copy['edition_id']];
            if ((int) $copy['owner_user_id'] !== $input->ownerId()) {
                $details['owner_changed'] = ['from' => (int) $copy['owner_user_id'], 'to' => $input->ownerId()];
            }
            if (self::householdOf($copy) !== $householdId) {
                $details['household_changed'] = ['from' => self::householdOf($copy), 'to' => $householdId];
            }
            $this->guard->audit('copy.updated', $actor->user->id, 'copy', $copyId, $details);
            return [
                'edition_id' => (int) $copy['edition_id'],
                'owner_from' => (int) $copy['owner_user_id'],
                'owner_to' => $input->ownerId(),
                'household_from' => self::householdOf($copy),
                'household_to' => $householdId,
            ];
        }, accountIds: [$input->ownerId()]));
    }

    /**
     * Copies with lending history are withdrawn, never deleted.
     *
     * @return array{edition_id: int, owner_user_id: int, household_id: ?int}
     *
     * @throws CopyHasHistoryException
     * @throws WriteDeniedException
     */
    public function delete(int $actorId, int $copyId): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($copyId): array {
            $copy = $this->lockManaged($actor, $copyId, false);
            if ($this->loans->hasAnyLoan($copyId)) {
                throw new CopyHasHistoryException();
            }
            $this->copies->delete($copyId);
            $this->guard->audit('copy.deleted', $actor->user->id, 'copy', $copyId, ['edition_id' => (int) $copy['edition_id'], 'owner_user_id' => (int) $copy['owner_user_id'], 'household_id' => self::householdOf($copy)]);

            return [
                'edition_id' => (int) $copy['edition_id'],
                'owner_user_id' => (int) $copy['owner_user_id'],
                'household_id' => self::householdOf($copy),
            ];
        });
    }

    /**
     * The copy part of a guarded transaction that is already open (the
     * first copy of a book being created: BookService::createBook()).
     *
     * @param array{id: int, work_id: int, created_by: ?int, isbn_13: ?string, title: string} $edition
     *        locked by the caller, or created in this transaction
     *
     * @return array{copy_id: int, edition_id: int, household_id: ?int}
     *
     * @throws CopyValidationException
     * @throws WriteDeniedException
     */
    public function createWithin(WriteActor $actor, array $edition, CopyInput $input, array $auditDetails = []): array
    {
        if (!$this->authorizer->can($actor->user, Permission::CopyManageOwn)) {
            throw WriteDeniedException::forbidden();
        }
        // Keeps an edit of the work and a first copy from another household apart.
        $this->works->lock($edition['work_id'], false);
        $this->requireOwnerChoice($actor, $input);

        $householdId = $this->validate($actor, $input, null);
        $copyId = $this->copies->create($edition['id'], ['household_id' => $householdId] + $input->toRecord(), $actor->user->id);

        $this->guard->audit('copy.created', $actor->user->id, 'copy', $copyId, ['edition_id' => $edition['id'], 'owner_user_id' => $input->ownerId(), 'household_id' => $householdId] + $auditDetails);
        return ['copy_id' => $copyId, 'edition_id' => $edition['id'], 'household_id' => $householdId];
    }

    /**
     * The copy, locked, once the actor has been shown to reach it and to
     * be allowed to manage it (404 before 403).
     *
     * @return array<string, mixed>
     */
    private function lockManaged(WriteActor $actor, int $copyId, bool $lockRecord): array
    {
        if ($lockRecord) {
            // Lock order: edition, work, then the copy.
            $editionId = $this->copies->editionIdOf($copyId) ?? throw WriteDeniedException::notFound();
            $edition = $this->editions->lock($editionId, false) ?? throw WriteDeniedException::notFound();
            $this->works->lock($edition['work_id'], false);
        }
        $copy = $this->copies->lock($copyId);
        if ($copy === null || !$actor->reaches(self::householdOf($copy))) {
            throw WriteDeniedException::notFound();
        }
        if (!$this->authorizer->canManageCopy($actor->user, (int) $copy['owner_user_id'])) {
            throw WriteDeniedException::forbidden();
        }

        return $copy;
    }

    /**
     * Assigning a copy to someone else (creating or transferring) requires
     * copy.manage_any.
     */
    private function requireOwnerChoice(WriteActor $actor, CopyInput $input): void
    {
        if ($input->ownerId() !== $actor->user->id && !$this->authorizer->can($actor->user, Permission::CopyManageAny)) {
            throw WriteDeniedException::forbidden();
        }
    }

    /**
     * Every rule that depends on the database, checked against the locked
     * rows. Returns the household the copy will belong to.
     *
     * @param array<string, mixed>|null $copy the locked copy being edited
     *
     * @throws CopyValidationException
     */
    private function validate(WriteActor $actor, CopyInput $input, ?array $copy): ?int
    {
        $errors = [];
        $owner = $this->users->findById($input->ownerId());
        if ($owner === null || !$owner->isActive) {
            $errors['owner_user_id'][] = 'validation.in';
        }

        // A location of another household is refused like one that does not exist.
        $locationId = $input->locationId();
        // Locked shared: the location cannot be deleted or moved until the copy is saved.
        $location = $locationId === null ? null : $this->locations->lock($locationId, false);
        if ($locationId !== null && ($location === null || !$actor->reaches(self::householdOf($location)))) {
            $errors['location_id'][] = 'validation.in';
            $location = null;
        }

        [$householdId, $householdError] = $this->households->resolve($actor->user, $actor->scope, $input->householdId, $copy);
        if ($householdError !== null) {
            $errors['household_id'][] = $householdError;
        }
        $previous = $copy === null ? null : self::householdOf($copy);
        $moved = $copy !== null && $previous !== $householdId;
        $scoped = $this->households->enabled();
        if ($householdError === null && $scoped) {
            $ownerChanged = $copy === null || (int) $copy['owner_user_id'] !== $input->ownerId();
            if ($householdId !== null && ($moved || $ownerChanged) && $owner !== null && !isset($errors['owner_user_id'])
                && !$this->households->ownerFits($owner, $householdId)) {
                $errors['owner_user_id'][] = 'households.validation.owner_not_member';
            }
            // The location belongs to the copy's household (each household has its own tree).
            if ($location !== null && self::householdOf($location) !== $householdId) {
                $errors['location_id'][] = 'households.validation.location_household';
            }
        }

        if ($input->inventoryCode !== '' && $this->copies->inventoryCodeTaken($input->inventoryCode, $copy === null ? null : (int) $copy['id'], $scoped, $householdId)) {
            $errors['inventory_code'][] = 'validation.inventory_taken';
        }

        // "On loan" is driven by the loans table: it is set by lending and
        // cleared by recording the return, never edited by hand.
        $onLoan = $copy !== null && $this->loans->hasActiveLoan((int) $copy['id']);
        if ($onLoan && $input->status !== 'on_loan') {
            $errors['status'][] = 'loans.validation.status_active_loan';
        } elseif (!$onLoan && $input->status === 'on_loan' && ($copy === null || $copy['status'] !== 'on_loan')) {
            $errors['status'][] = 'loans.validation.status_use_lending';
        }
        // A copy that is out on loan stays where it is until it is returned.
        if ($onLoan && $moved && $householdError === null) {
            $errors['household_id'][] = 'households.validation.on_loan';
        }

        if ($errors !== []) {
            throw new CopyValidationException($errors);
        }

        return $householdId;
    }

    /**
     * @param array<string, mixed> $row a copy or a location
     */
    private static function householdOf(array $row): ?int
    {
        return ($row['household_id'] ?? null) === null ? null : (int) $row['household_id'];
    }

    /**
     * A concurrent insert of the same inventory code in the same household
     * surfaces as the validation error, never as a server error.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guardCode(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_copies_scope_inventory_code')) {
                throw new CopyValidationException(['inventory_code' => ['validation.inventory_taken']]);
            }
            throw $e;
        }
    }
}
