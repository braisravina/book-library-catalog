<?php

declare(strict_types=1);

namespace DigitalLibrary\Wishlist;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Security\Authorizer;

/**
 * Wishlist changes. Each is one guarded transaction (Households\WriteGuard)
 * holding the owner's account row exclusively, so the duplicate checks
 * cannot race; the unique (user, wanted ISBN) index backs this up.
 * Entries of other users are reported as not found.
 *
 * Links to the catalogue (phase H3). An entry may point at a catalogue
 * edition, but only at one the user is allowed to see at the moment it is
 * saved, checked inside the transaction against their memberships as
 * they are then:
 *
 *  - starting an entry from an edition requires that edition to be visible;
 *  - an ISBN is linked automatically only to a visible edition. A book
 *    that only other households hold is never linked, so the wishlist
 *    cannot be used to learn or to reference what they hold; the entry
 *    keeps its ISBN and is linked on a later save, once the book is in
 *    the user's own library;
 *  - a link whose edition has left the user's view is dropped the next
 *    time the entry is saved (it is never shown or exported meanwhile).
 *
 * Without households every edition is visible and the behaviour is the
 * one before households.
 */
final class WishlistService
{
    public function __construct(
        private readonly WriteGuard $guard,
        private readonly WishlistRepository $items,
        private readonly Authorizer $authorizer,
    ) {
    }

    /**
     * @param ?int $editionId catalogue edition the entry was started from
     *
     * @throws WishlistException
     */
    public function add(User $user, WishlistInput $input, ?int $editionId = null): int
    {
        $isbn13 = $input->isbn13();

        return $this->guard(fn (): int => $this->guard->run($user->id, function (WriteActor $actor) use ($input, $isbn13, $editionId): int {
            $userId = $actor->user->id;
            if (!$this->authorizer->canManageWishlistItem($actor->user, $userId)) {
                throw new WishlistException('wishlist.error.forbidden');
            }
            if ($editionId !== null) {
                $edition = $this->items->visibleEdition($editionId, $actor->visibility) ?? throw new WishlistException('wishlist.error.edition_not_found');
            } else {
                $edition = $isbn13 === null ? null : $this->items->editionForIsbn($isbn13, $actor->visibility);
            }
            $this->refuseDuplicate($userId, $isbn13, $edition['id'] ?? null, $input, null);

            return $this->items->create($userId, $edition['id'] ?? null, $edition['work_id'] ?? null, $input, $isbn13);
        }, exclusiveActor: true));
    }

    /**
     * @throws WishlistException
     */
    public function update(User $user, int $itemId, WishlistInput $input): void
    {
        $isbn13 = $input->isbn13();
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($itemId, $input, $isbn13): void {
            $item = $this->ownedLocked($actor, $itemId);
            // The link is kept while its edition is still in view and the
            // ISBN is unchanged; otherwise it is resolved again, to a
            // visible edition only.
            $edition = $item['edition_id'] === null ? null : $this->items->visibleEdition((int) $item['edition_id'], $actor->visibility);
            if ($edition === null || $isbn13 !== $item['isbn_13']) {
                $edition = $isbn13 === null ? null : $this->items->editionForIsbn($isbn13, $actor->visibility);
            }
            if ($item['status'] === 'wanted') {
                $this->refuseDuplicate($actor->user->id, $isbn13, $edition['id'] ?? null, $input, $itemId);
            }
            $this->items->update($itemId, $edition['id'] ?? null, $edition['work_id'] ?? null, $input, $isbn13);
        }, exclusiveActor: true));
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
        $this->guard(fn () => $this->guard->run($user->id, function (WriteActor $actor) use ($itemId, $status): void {
            $item = $this->ownedLocked($actor, $itemId);
            if ($item['status'] === $status) {
                return;
            }
            if ($status === 'wanted') {
                $this->refuseDuplicate($actor->user->id, $item['isbn_13'], $item['edition_id'] === null ? null : (int) $item['edition_id'], WishlistInput::fromRecord($item), $itemId);
            }
            $this->items->setStatus($itemId, $status);
        }, exclusiveActor: true));
    }

    /**
     * @return array<string, mixed>
     */
    private function ownedLocked(WriteActor $actor, int $itemId): array
    {
        $item = $this->items->lock($itemId);
        if ($item === null || !$this->authorizer->canManageWishlistItem($actor->user, (int) $item['user_id'])) {
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
        } catch (WriteDeniedException) {
            // The account was deactivated while the request was in flight.
            throw new WishlistException('wishlist.error.forbidden');
        }
    }
}
