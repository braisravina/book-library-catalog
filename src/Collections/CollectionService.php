<?php

declare(strict_types=1);

namespace DigitalLibrary\Collections;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Households\HouseholdRepository;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;

/**
 * Collection changes. Each is one guarded transaction (Households\WriteGuard)
 * that locks the collection row and checks the actor, as the database
 * describes them at that moment, against it.
 *
 * Households (phase H3). A private collection belongs to its owner alone.
 * A shared collection is shared with ONE household, chosen explicitly:
 *
 *  - administrators may choose any household; other users only a
 *    household they are a member of (a single membership is used without
 *    asking). A household that does not exist and one the user may not
 *    use give the same error;
 *  - a collection never becomes shared, and never changes household, on
 *    its own: only this service writes household_id, from an explicit
 *    choice;
 *  - while the household feature is disabled a shared collection goes to
 *    the temporary default household and behaves as it always did;
 *  - an owner who is no longer a member of the household their collection
 *    is shared with can still open, delete or re-home it (make it private,
 *    or share it with a household they belong to), but cannot add to or
 *    reorder what that household sees.
 *
 * Items are editions. A user can only add an edition they can see, and
 * only acts on the items they can see: an item out of their view is
 * answered like one that is not in the collection, and reordering moves a
 * book among the visible items while the others keep their places.
 * Collections stay curated lists: they never grant access to an edition
 * (items are filtered per viewer) or to inventory.
 */
final class CollectionService
{
    public const MOVES = ['up', 'down', 'top', 'bottom'];

    public function __construct(
        private readonly WriteGuard $guard,
        private readonly CollectionRepository $collections,
        private readonly EditionRepository $editions,
        private readonly HouseholdRepository $households,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * The households a collection may be shared with, as id => name
     * (empty while the household feature is disabled: nothing to choose).
     *
     * @return array<string, string>
     */
    public function householdOptions(User $user): array
    {
        if (!$this->guard->enabled()) {
            return [];
        }
        $rows = $this->authorizer->can($user, Permission::CollectionManageAny) ? $this->households->all() : $this->households->forUser($user->id);
        $options = [];
        foreach ($rows as $row) {
            $options[(string) $row['id']] = (string) $row['name'];
        }

        return $options;
    }

    /**
     * @throws CollectionException
     */
    public function create(User $user, CollectionInput $input): int
    {
        return $this->guard(fn (): int => $this->guard->run($user->id, function (WriteActor $actor) use ($input): int {
            if (!$this->authorizer->can($actor->user, Permission::CollectionManageOwn)) {
                throw new CollectionException('collections.error.forbidden');
            }
            $householdId = $this->household($actor, $input, null);
            if ($this->collections->nameTaken($actor->user->id, $input->name)) {
                throw new CollectionException('validation.name_taken');
            }

            $id = $this->collections->create($actor->user->id, $input, $householdId);
            $this->guard->audit('collection.created', $actor->user->id, 'collection', $id, ['visibility' => $input->visibility, 'household_id' => $householdId]);
            return $id;
        }));
    }

    /**
     * @return array{household_from: ?int, household_to: ?int}
     *
     * @throws CollectionException
     */
    public function update(User $user, int $id, CollectionInput $input): array
    {
        return $this->guard(fn (): array => $this->guard->run($user->id, function (WriteActor $actor) use ($id, $input): array {
            $collection = $this->managedLocked($actor, $id);
            $householdId = $this->household($actor, $input, $collection);
            if ($this->collections->nameTaken((int) $collection['owner_user_id'], $input->name, $id)) {
                throw new CollectionException('validation.name_taken');
            }
            $this->collections->update($id, $input, $householdId);
            $details = ['owner_user_id' => (int) $collection['owner_user_id'], 'visibility' => $input->visibility];
            if (self::householdOf($collection) !== $householdId) {
                $details['household_changed'] = ['from' => self::householdOf($collection), 'to' => $householdId];
            }
            $this->guard->audit('collection.updated', $actor->user->id, 'collection', $id, $details);

            return ['household_from' => self::householdOf($collection), 'household_to' => $householdId];
        }));
    }

    /**
     * Deletes the collection and its item list (never the catalogue records).
     *
     * @throws CollectionException
     */
    public function delete(User $user, int $id): void
    {
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($id): void {
            $collection = $this->managedLocked($actor, $id);
            $count = $this->collections->countItems($id);
            $this->collections->delete($id);
            $this->guard->audit('collection.deleted', $actor->user->id, 'collection', $id, ['owner_user_id' => (int) $collection['owner_user_id'], 'items' => $count]);
        }));
    }

    /**
     * @throws CollectionException
     */
    public function addItem(User $user, int $id, int $editionId): void
    {
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($id, $editionId): void {
            $this->sharedSpace($actor, $this->managedLocked($actor, $id));
            // Only an edition the actor can see now: a collection cannot be
            // used to probe edition ids or to hand a record to a household.
            if (!$this->editions->isVisible($editionId, $actor->visibility)) {
                throw new CollectionException('collections.error.edition_not_found');
            }
            if ($this->collections->hasItem($id, $editionId)) {
                throw new CollectionException('collections.error.already_in');
            }
            if ($this->collections->countItems($id) >= CollectionRepository::MAX_ITEMS) {
                throw new CollectionException('collections.error.full');
            }
            $this->collections->addItem($id, $editionId, $actor->user->id);
            $this->guard->audit('collection.item_added', $actor->user->id, 'collection', $id, ['edition_id' => $editionId]);
            $this->collections->touch($id);
        }));
    }

    /**
     * @throws CollectionException
     */
    public function removeItem(User $user, int $id, int $editionId): void
    {
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($id, $editionId): void {
            $this->sharedSpace($actor, $this->managedLocked($actor, $id));
            // An item out of the actor's view is answered like one that is not there.
            if (!$this->collections->hasVisibleItem($id, $editionId, $actor->visibility) || !$this->collections->removeItem($id, $editionId)) {
                throw new CollectionException('collections.error.not_in');
            }
            $this->guard->audit('collection.item_removed', $actor->user->id, 'collection', $id, ['edition_id' => $editionId]);
            $this->collections->touch($id);
        }));
    }

    /**
     * Moves one item among the items the actor can see. Items out of
     * their view keep their places, and positions are renumbered 1..n so
     * gaps and ties from earlier removals never make the order ambiguous.
     *
     * @throws CollectionException
     */
    public function move(User $user, int $id, int $editionId, string $direction): void
    {
        if (!in_array($direction, self::MOVES, true)) {
            throw new CollectionException('collections.error.invalid_move');
        }
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($id, $editionId, $direction): void {
            $this->sharedSpace($actor, $this->managedLocked($actor, $id));
            $positions = $this->collections->positions($id);
            $visible = array_keys($this->collections->visiblePositions($id, $actor->visibility));
            $index = array_search($editionId, $visible, true);
            if ($index === false) {
                throw new CollectionException('collections.error.not_in');
            }
            $target = match ($direction) {
                'up' => max(0, $index - 1),
                'down' => min(count($visible) - 1, $index + 1),
                'top' => 0,
                'bottom' => count($visible) - 1,
            };
            array_splice($visible, $index, 1);
            array_splice($visible, $target, 0, [$editionId]);
            // The visible items take, in their new order, the places the
            // visible items held; every other item stays where it is.
            $seen = array_flip($visible);
            $next = 0;
            foreach (array_keys($positions) as $i => $edition) {
                $placed = isset($seen[$edition]) ? $visible[$next++] : $edition;
                if ($positions[$placed] !== $i + 1) {
                    $this->collections->setPosition($id, $placed, $i + 1);
                }
            }
            $this->collections->touch($id);
        }));
    }

    /**
     * The collection, locked, once the actor has been shown to see it
     * (404 otherwise) and to be allowed to manage it (403 otherwise).
     *
     * @return array<string, mixed>
     */
    private function managedLocked(WriteActor $actor, int $id): array
    {
        $collection = $this->collections->lock($id) ?? throw new CollectionException('collections.error.not_found');
        $owner = (int) $collection['owner_user_id'];
        if (!$this->authorizer->canViewCollection($actor->user, $owner, (string) $collection['visibility'], $actor->reaches(self::householdOf($collection)))) {
            throw new CollectionException('collections.error.not_found');
        }
        if (!$this->authorizer->canManageCollection($actor->user, $owner)) {
            throw new CollectionException('collections.error.forbidden');
        }

        return $collection;
    }

    /**
     * Changing what a household sees in a shared collection needs access
     * to that household: an owner who left it re-homes the collection
     * first (administrators reach every household).
     *
     * @param array<string, mixed> $collection locked
     */
    private function sharedSpace(WriteActor $actor, array $collection): void
    {
        if ($collection['visibility'] === 'shared' && !$actor->reaches(self::householdOf($collection))) {
            throw new CollectionException('collections.error.household_access');
        }
    }

    /**
     * The household a collection will be shared with (null for a private
     * collection), decided here and nowhere else.
     *
     * @param array<string, mixed>|null $collection the locked collection being edited
     */
    private function household(WriteActor $actor, CollectionInput $input, ?array $collection): ?int
    {
        if ($input->visibility !== 'shared') {
            return null;
        }
        $current = $collection === null || $collection['visibility'] !== 'shared' ? null : self::householdOf($collection);
        if (!$this->guard->enabled()) {
            // Households are not in use: a submitted household is ignored.
            return $current ?? $this->households->defaultId();
        }
        $anyHousehold = $this->authorizer->can($actor->user, Permission::CollectionManageAny);
        $usable = fn (int $id): bool => ($anyHousehold || (!$actor->scope->unrestricted && $actor->scope->includes($id))) && $this->households->exists($id);
        if ($input->householdId !== '') {
            if (preg_match('/^[1-9]\d{0,9}$/', $input->householdId) !== 1 || !$usable((int) $input->householdId)) {
                // Unknown and not permitted are the same answer: the form is not an oracle for household ids.
                throw new CollectionException('validation.in', 'household_id');
            }

            return (int) $input->householdId;
        }
        if ($current !== null && $usable($current)) {
            return $current;
        }
        $own = $actor->scope->ids();
        if (!$anyHousehold && count($own) === 1) {
            return $own[0];
        }

        throw new CollectionException('collections.validation.household_required', 'household_id');
    }

    /**
     * @param array<string, mixed> $collection
     */
    private static function householdOf(array $collection): ?int
    {
        return ($collection['household_id'] ?? null) === null ? null : (int) $collection['household_id'];
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e)) {
                throw new CollectionException(str_contains($e->getMessage(), 'uq_collections_owner_name') ? 'validation.name_taken' : 'collections.error.already_in');
            }
            if (($e->errorInfo[1] ?? null) === 1452) { // edition deleted meanwhile
                throw new CollectionException('collections.error.edition_not_found');
            }
            throw $e;
        } catch (WriteDeniedException) {
            // The account was deactivated while the request was in flight.
            throw new CollectionException('collections.error.forbidden');
        }
    }
}
