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
     * Changing a shared bibliographic record (work, edition, cover), phase
     * H3. Administrators change any record. A member changes a record they
     * created only while it is not held outside their households
     * ($heldElsewhere, decided by WorkRepository::sharedBeyondView() /
     * EditionRepository::heldOutsideView()): once another household holds
     * the book, its description is an administrator's to change. Being
     * able to see a record never grants this.
     */
    public function canEditSharedRecord(?User $user, ?int $createdBy, bool $heldElsewhere): bool
    {
        if ($this->can($user, Permission::CatalogueEditAny)) {
            return true;
        }

        return !$heldElsewhere && $this->canEditRecord($user, $createdBy);
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
     * collections: additionally every user with catalogue.view who reaches
     * the household the collection is shared with ($householdInScope:
     * Visibility::includesHousehold() of the collection's household; always
     * true while the household feature is disabled). Sharing is with one
     * explicitly chosen household, never with every household of the owner.
     */
    public function canViewCollection(?User $user, int $ownerId, string $visibility, bool $householdInScope): bool
    {
        if ($user === null || !$user->isActive) {
            return false;
        }
        if ($user->id === $ownerId || $this->can($user, Permission::CollectionManageAny)) {
            return true;
        }

        return $visibility === 'shared' && $householdInScope && $this->can($user, Permission::CatalogueView);
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

    /**
     * Creating, renaming and deleting households and assigning their
     * members: administrators only.
     */
    public function canManageHouseholds(?User $user): bool
    {
        return $this->can($user, Permission::HouseholdManage);
    }

    /**
     * Placing a copy in any household (copy.manage_any: administrators).
     */
    public function canAssignCopyToAnyHousehold(?User $user): bool
    {
        return $this->can($user, Permission::CopyManageAny);
    }

    /**
     * Placing a copy in a household: administrators in any household,
     * other users only in households they are a member of. Membership
     * alone is not enough: the user must also be allowed to manage copies.
     */
    public function canAssignCopyToHousehold(?User $user, HouseholdScope $scope, int $householdId): bool
    {
        if ($this->canAssignCopyToAnyHousehold($user)) {
            return true;
        }

        return $this->can($user, Permission::CopyManageOwn) && !$scope->unrestricted && $scope->includes($householdId);
    }

    public function deny(): never
    {
        throw \DigitalLibrary\Http\HttpException::forbidden();
    }
}
