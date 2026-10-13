<?php

declare(strict_types=1);

namespace DigitalLibrary\Collections;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;
use PDO;

/**
 * Collection changes. Each runs in a transaction that locks the collection
 * row and re-checks Authorizer::canManageCollection() against its owner.
 */
final class CollectionService
{
    public const MOVES = ['up', 'down', 'top', 'bottom'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly CollectionRepository $collections,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * @throws CollectionException
     */
    public function create(User $user, CollectionInput $input): int
    {
        if (!$this->authorizer->can($user, Permission::CollectionManageOwn)) {
            throw new CollectionException('collections.error.forbidden');
        }

        return $this->guard(fn (): int => Transaction::run($this->pdo, function () use ($user, $input): int {
            if ($this->collections->nameTaken($user->id, $input->name)) {
                throw new CollectionException('validation.name_taken');
            }

            return $this->collections->create($user->id, $input);
        }));
    }

    /**
     * @throws CollectionException
     */
    public function update(User $user, int $id, CollectionInput $input): void
    {
        $this->guard(fn () => Transaction::run($this->pdo, function () use ($user, $id, $input): void {
            $collection = $this->managedLocked($user, $id);
            if ($this->collections->nameTaken((int) $collection['owner_user_id'], $input->name, $id)) {
                throw new CollectionException('validation.name_taken');
            }
            $this->collections->update($id, $input);
        }));
    }

    /**
     * Deletes the collection and its item list (never the catalogue records).
     *
     * @throws CollectionException
     */
    public function delete(User $user, int $id): void
    {
        Transaction::run($this->pdo, function () use ($user, $id): void {
            $this->managedLocked($user, $id);
            $this->collections->delete($id);
        });
    }

    /**
     * @throws CollectionException
     */
    public function addItem(User $user, int $id, int $editionId): void
    {
        $this->guard(fn () => Transaction::run($this->pdo, function () use ($user, $id, $editionId): void {
            $this->managedLocked($user, $id);
            if ($this->collections->hasItem($id, $editionId)) {
                throw new CollectionException('collections.error.already_in');
            }
            if ($this->collections->countItems($id) >= CollectionRepository::MAX_ITEMS) {
                throw new CollectionException('collections.error.full');
            }
            $this->collections->addItem($id, $editionId, $user->id);
            $this->collections->touch($id);
        }));
    }

    /**
     * @throws CollectionException
     */
    public function removeItem(User $user, int $id, int $editionId): void
    {
        Transaction::run($this->pdo, function () use ($user, $id, $editionId): void {
            $this->managedLocked($user, $id);
            if (!$this->collections->removeItem($id, $editionId)) {
                throw new CollectionException('collections.error.not_in');
            }
            $this->collections->touch($id);
        });
    }

    /**
     * Moves one item; positions are renumbered 1..n so gaps and ties from
     * earlier removals never make the order ambiguous.
     *
     * @throws CollectionException
     */
    public function move(User $user, int $id, int $editionId, string $direction): void
    {
        if (!in_array($direction, self::MOVES, true)) {
            throw new CollectionException('collections.error.invalid_move');
        }
        Transaction::run($this->pdo, function () use ($user, $id, $editionId, $direction): void {
            $this->managedLocked($user, $id);
            $positions = $this->collections->positions($id);
            $order = array_keys($positions);
            $index = array_search($editionId, $order, true);
            if ($index === false) {
                throw new CollectionException('collections.error.not_in');
            }
            $target = match ($direction) {
                'up' => max(0, $index - 1),
                'down' => min(count($order) - 1, $index + 1),
                'top' => 0,
                'bottom' => count($order) - 1,
            };
            array_splice($order, $index, 1);
            array_splice($order, $target, 0, [$editionId]);
            foreach ($order as $i => $edition) {
                if ($positions[$edition] !== $i + 1) {
                    $this->collections->setPosition($id, $edition, $i + 1);
                }
            }
            $this->collections->touch($id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function managedLocked(User $user, int $id): array
    {
        $collection = $this->collections->lock($id) ?? throw new CollectionException('collections.error.not_found');
        if (!$this->authorizer->canManageCollection($user, (int) $collection['owner_user_id'])) {
            throw new CollectionException('collections.error.forbidden');
        }

        return $collection;
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
        }
    }
}
