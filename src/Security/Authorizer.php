<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

use DigitalLibrary\Auth\User;

/**
 * Central place for role-based and object-level authorization decisions.
 * Controllers must call these checks; templates may use them only to hide UI.
 */
final class Authorizer
{
    public function can(?User $user, Permission $permission): bool
    {
        return $user !== null && $user->isActive && $user->role->has($permission);
    }

    /**
     * Bibliographic records (works/editions): admins edit anything, members
     * edit records they created.
     */
    public function canEditRecord(?User $user, ?int $createdBy): bool
    {
        if ($this->can($user, Permission::CatalogueEditAny)) {
            return true;
        }

        return $createdBy !== null
            && $this->can($user, Permission::CatalogueEditOwn)
            && $user->id === $createdBy;
    }

    /**
     * Physical copies: admins manage any copy, members manage copies they own.
     */
    public function canManageCopy(?User $user, int $ownerId): bool
    {
        if ($this->can($user, Permission::CopyManageAny)) {
            return true;
        }

        return $this->can($user, Permission::CopyManageOwn) && $user->id === $ownerId;
    }

    /**
     * Lending and returning a physical copy: its owner (loan.manage_own) or
     * anyone with loan.manage_any (administrators).
     */
    public function canManageLoans(?User $user, int $copyOwnerId): bool
    {
        if ($this->can($user, Permission::LoanManageAny)) {
            return true;
        }

        return $this->can($user, Permission::LoanManageOwn) && $user->id === $copyOwnerId;
    }

    /**
     * Borrower identity is personal data: visible to whoever may manage the
     * loan and to the borrowing user themself, never to other users.
     */
    public function canSeeLoanDetails(?User $user, int $copyOwnerId, ?int $borrowerUserId): bool
    {
        return $this->canManageLoans($user, $copyOwnerId)
            || ($user !== null && $user->isActive && $borrowerUserId !== null && $user->id === $borrowerUserId);
    }

    /**
     * Wishlists are private: only their owner manages or sees them. No
     * administrative override is defined.
     */
    public function canManageWishlistItem(?User $user, int $itemUserId): bool
    {
        return $this->can($user, Permission::WishlistManageOwn) && $user->id === $itemUserId;
    }

    /**
     * Private collections: owner and collection.manage_any. Shared
     * collections: additionally every user with catalogue.view.
     */
    public function canViewCollection(?User $user, int $ownerId, string $visibility): bool
    {
        if ($user === null || !$user->isActive) {
            return false;
        }
        if ($user->id === $ownerId || $this->can($user, Permission::CollectionManageAny)) {
            return true;
        }

        return $visibility === 'shared' && $this->can($user, Permission::CatalogueView);
    }

    /**
     * Editing a collection (details, items, order, deletion) never follows
     * from being able to view it.
     */
    public function canManageCollection(?User $user, int $ownerId): bool
    {
        if ($this->can($user, Permission::CollectionManageAny)) {
            return true;
        }

        return $this->can($user, Permission::CollectionManageOwn) && $user->id === $ownerId;
    }

    public function deny(): never
    {
        throw \DigitalLibrary\Http\HttpException::forbidden();
    }
}
