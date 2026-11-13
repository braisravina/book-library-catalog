<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\HouseholdScope;
use DigitalLibrary\Security\Role;

/**
 * Decides which household a physical copy belongs to when it is created
 * or edited. Every copy write goes through here, so the rule lives in one
 * place.
 *
 * While the household feature is disabled (always, in production, until
 * the complete access boundary ships) the choice is never taken from the
 * request: a new copy goes to the temporary default household and an
 * existing copy keeps its household. A submitted household id is ignored.
 *
 * With the feature enabled the household is chosen explicitly:
 *  - administrators (copy.manage_any) may choose any household;
 *  - other users may choose only households they belong to; with exactly
 *    one membership it is used without asking;
 *  - a household that does not exist and one the user may not use give
 *    the same error, so the form is not an oracle for household ids;
 *  - an edit that leaves the household as it is always succeeds.
 */
final class CopyHousehold
{
    public function __construct(
        private readonly HouseholdRepository $households,
        private readonly Authorizer $authorizer,
        private readonly bool $enabled,
    ) {
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param string                    $requested the submitted household id ('' when absent)
     * @param array<string, mixed>|null $existing  the copy being edited, or null for a new copy
     *
     * @return array{0: ?int, 1: ?string} the household id (null: unassigned) and a
     *                                    validation error key, if refused
     */
    public function resolve(User $actor, HouseholdScope $scope, string $requested, ?array $existing): array
    {
        $current = $existing === null || ($existing['household_id'] ?? null) === null ? null : (int) $existing['household_id'];
        if (!$this->enabled) {
            return [$existing === null ? $this->households->defaultId() : $current, null];
        }
        if ($requested === '') {
            if ($existing !== null) {
                return [$current, null];
            }
            $ids = $scope->ids();
            if (!$scope->unrestricted && count($ids) === 1) {
                return [$ids[0], null];
            }

            return [null, 'households.validation.required'];
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $requested) !== 1) {
            return [$current, 'validation.in'];
        }
        $id = (int) $requested;
        if ($existing !== null && $id === $current) {
            return [$current, null];
        }
        if (!$this->authorizer->canAssignCopyToHousehold($actor, $scope, $id) || !$this->households->exists($id)) {
            return [$current, 'validation.in'];
        }

        return [$id, null];
    }

    /**
     * Whether $owner may own a copy held by the household: administrators
     * (who reach every household) and members of that household. Checked
     * only when the feature is enabled and the pair is being set.
     */
    public function ownerFits(User $owner, int $householdId): bool
    {
        return !$this->enabled || $owner->role === Role::Admin || $this->households->isMember($householdId, $owner->id);
    }

    /**
     * The households offered in a copy form, as id => name.
     *
     * @param array<string, mixed>|null $existing the copy being edited
     *
     * @return array<string, string>
     */
    public function options(User $actor, HouseholdScope $scope, ?array $existing): array
    {
        if (!$this->enabled) {
            return [];
        }
        $rows = $this->authorizer->canAssignCopyToAnyHousehold($actor) ? $this->households->all() : $this->households->forUser($actor->id);
        $options = [];
        foreach ($rows as $row) {
            $options[(string) $row['id']] = (string) $row['name'];
        }
        $current = $existing['household_id'] ?? null;
        if ($current !== null && !isset($options[(string) $current]) && isset($existing['household_name'])) {
            // The copy's present household stays selectable (keeping it is always allowed).
            $options[(string) $current] = (string) $existing['household_name'];
        }

        return $options;
    }
}
