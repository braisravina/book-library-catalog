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

    public function deny(): never
    {
        throw \DigitalLibrary\Http\HttpException::forbidden();
    }
}
