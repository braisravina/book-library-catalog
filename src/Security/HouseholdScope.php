<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

use DigitalLibrary\Auth\User;

/**
 * The households a user may reach, computed for one request.
 *
 *  - Administrators are unrestricted (every household, and inventory that
 *    has no household yet).
 *  - Every other active user reaches exactly the households they are a
 *    member of. No membership means no household.
 *  - Nobody (signed out or deactivated) reaches nothing.
 *
 * It is built from the database on each request and never stored in the
 * session, so adding or removing a membership applies to the member's next
 * request without signing out.
 *
 * Phase H1 introduces the scope and uses it for copy assignment only.
 * Read paths are filtered with it in phases H2/H3 (see
 * config/household_access.php for the per-route plan).
 */
final class HouseholdScope
{
    /**
     * @param list<int> $ids
     */
    private function __construct(
        public readonly bool $unrestricted,
        private readonly array $ids,
    ) {
    }

    public static function all(): self
    {
        return new self(true, []);
    }

    public static function none(): self
    {
        return new self(false, []);
    }

    /**
     * @param iterable<int|string> $ids
     */
    public static function of(iterable $ids): self
    {
        $clean = [];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $clean[(int) $id] = true;
            }
        }
        $clean = array_keys($clean);
        sort($clean);

        return new self(false, $clean);
    }

    /**
     * @param callable(int): iterable<int|string> $memberships household ids for a user id
     */
    public static function forUser(?User $user, callable $memberships): self
    {
        if ($user === null || !$user->isActive) {
            return self::none();
        }
        if ($user->role === Role::Admin) {
            return self::all();
        }

        return self::of($memberships($user->id));
    }

    /**
     * Whether inventory of the given household is within reach. Inventory
     * without a household (null) is reachable only when unrestricted.
     */
    public function includes(?int $householdId): bool
    {
        if ($this->unrestricted) {
            return true;
        }

        return $householdId !== null && in_array($householdId, $this->ids, true);
    }

    /**
     * The member households (empty when unrestricted: check $unrestricted first).
     *
     * @return list<int>
     */
    public function ids(): array
    {
        return $this->ids;
    }

    public function isEmpty(): bool
    {
        return !$this->unrestricted && $this->ids === [];
    }
}
