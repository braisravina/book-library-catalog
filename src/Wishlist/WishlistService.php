<?php

declare(strict_types=1);

namespace DigitalLibrary\Wishlist;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Authorizer;
use PDO;

/**
 * Wishlist changes. Each runs in a transaction holding the owner's user row
 * lock, so the duplicate checks cannot race; the unique (user, wanted ISBN)
 * index backs this up. Entries of other users are reported as not found.
 */
final class WishlistService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly WishlistRepository $items,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * @param ?array{id: int, work_id: ?int} $edition catalogue edition the entry was created from
     *
     * @throws WishlistException
     */
    public function add(User $user, WishlistInput $input, ?array $edition = null): int
    {
        if (!$this->authorizer->canManageWishlistItem($user, $user->id)) {
            throw new WishlistException('wishlist.error.forbidden');
        }
        $isbn13 = $input->isbn13();

        return $this->guard(fn (): int => Transaction::run($this->pdo, function () use ($user, $input, $isbn13, $edition): int {
            $this->items->lockOwner($user->id);
            $edition ??= $isbn13 === null ? null : $this->items->editionForIsbn($isbn13);
            $this->refuseDuplicate($user->id, $isbn13, $edition['id'] ?? null, $input, null);

            return $this->items->create($user->id, $edition['id'] ?? null, $edition['work_id'] ?? null, $input, $isbn13);
        }));
    }

    /**
     * @throws WishlistException
     */
    public function update(User $user, int $itemId, WishlistInput $input): void
    {
        $isbn13 = $input->isbn13();
        $this->guard(fn () => Transaction::run($this->pdo, function () use ($user, $itemId, $input, $isbn13): void {
            $this->items->lockOwner($user->id);
            $item = $this->ownedLocked($user, $itemId);
            // Changing the ISBN re-resolves the catalogue link; otherwise it is kept.
            if ($isbn13 !== $item['isbn_13']) {
                $edition = $isbn13 === null ? null : $this->items->editionForIsbn($isbn13);
            } else {
                $edition = $item['edition_id'] === null ? null : ['id' => (int) $item['edition_id'], 'work_id' => $item['work_id'] === null ? null : (int) $item['work_id']];
            }
            if ($item['status'] === 'wanted') {
                $this->refuseDuplicate($user->id, $isbn13, $edition['id'] ?? null, $input, $itemId);
            }
            $this->items->update($itemId, $edition['id'] ?? null, $edition['work_id'] ?? null, $input, $isbn13);
        }));
    }

    /**
     * Moves an entry between wanted, acquired and archived. Entries are
     * never deleted, so the history of what was wanted is preserved.
     *
     * @throws WishlistException
     */
    public function setStatus(User $user, int $itemId, string $status): void
    {
        if (!in_array($status, WishlistRepository::STATUSES, true)) {
            throw new WishlistException('wishlist.error.invalid_status');
        }
        $this->guard(fn () => Transaction::run($this->pdo, function () use ($user, $itemId, $status): void {
            $this->items->lockOwner($user->id);
            $item = $this->ownedLocked($user, $itemId);
            if ($item['status'] === $status) {
                return;
            }
            if ($status === 'wanted') {
                $this->refuseDuplicate($user->id, $item['isbn_13'], $item['edition_id'] === null ? null : (int) $item['edition_id'], WishlistInput::fromRecord($item), $itemId);
            }
            $this->items->setStatus($itemId, $status);
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedLocked(User $user, int $itemId): array
    {
        $item = $this->items->lock($itemId);
        if ($item === null || !$this->authorizer->canManageWishlistItem($user, (int) $item['user_id'])) {
            throw new WishlistException('wishlist.error.not_found');
        }

        return $item;
    }

    private function refuseDuplicate(int $userId, ?string $isbn13, ?int $editionId, WishlistInput $input, ?int $excludeId): void
    {
        $existing = $this->items->wantedDuplicate($userId, $isbn13, $editionId, $input->title, $input->author, $excludeId);
        if ($existing !== null) {
            throw new WishlistException('wishlist.error.duplicate', $existing);
        }
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
            if (Repository::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_wishlist_wanted_isbn')) {
                throw new WishlistException('wishlist.error.duplicate');
            }
            throw $e;
        }
    }
}
