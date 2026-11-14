<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Security\HouseholdScope;
use DigitalLibrary\Security\Visibility;

/**
 * The acting user as the database describes them inside a guarded write
 * transaction (Households\WriteGuard): the account (role, active), its
 * household memberships and what it may see, all read after the locks
 * that keep them stable until the transaction ends. Never built from the
 * session or from what an earlier request (or the form) said.
 */
final class WriteActor
{
    public function __construct(
        public readonly User $user,
        public readonly HouseholdScope $scope,
        public readonly Visibility $visibility,
    ) {
    }

    /**
     * Whether inventory of this household (null: none assigned) is within
     * the actor's reach. Always true for administrators and while the
     * household feature is disabled.
     */
    public function reaches(?int $householdId): bool
    {
        return $this->visibility->includesHousehold($householdId);
    }
}
