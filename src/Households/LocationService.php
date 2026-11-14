<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;

/**
 * Location administration (location.manage: administrators), phase H3.
 *
 * Every household has its own, independent location tree:
 *
 *  - a root location belongs to the household chosen for it, explicitly;
 *  - a child belongs to the household of its parent, always (a household
 *    submitted for a child that differs from the parent's is refused);
 *  - a location can only be moved under a parent of its own household, or
 *    made a root of that household: editing never moves a location, or
 *    the copies stored in it, to another household (reassigning inventory
 *    between households is phase H4);
 *  - names are unique among siblings, and among the roots of a household.
 *
 * While the household feature is disabled there is one tree, as before: a
 * new root goes to the temporary default household, a submitted household
 * is ignored and parents are not compared by household.
 *
 * Each change is one guarded transaction (Households\WriteGuard) that
 * locks all household rows exclusively before the account and location
 * rows, including when the feature is disabled. This serialises tree
 * mutations (NULL roots have no unique-index protection, and disjoint
 * moves could otherwise form a cycle). It locks location rows in id
 * order, so two administrators cannot
 * build a cycle or a duplicate name between them, and a copy being saved
 * into a location (which locks the location shared) never meets a
 * deletion half-way.
 */
final class LocationService
{
    public function __construct(
        private readonly WriteGuard $guard,
        private readonly LocationRepository $locations,
        private readonly HouseholdRepository $households,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function enabled(): bool
    {
        return $this->guard->enabled();
    }

    /**
     * @param string $household the submitted household id ('' when absent); used for roots only
     *
     * @return array{id: int, household_id: ?int}
     *
     * @throws LocationException
     */
    public function create(int $actorId, ?int $parentId, string $household, string $name, string $kind, ?string $description): array
    {
        return $this->guardName(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($parentId, $household, $name, $kind, $description): array {
            $this->requireManager($actor);
            if ($parentId !== null) {
                $parent = $this->locations->lock($parentId, true) ?? throw new LocationException('validation.in', 'parent_id');
                $householdId = self::householdOf($parent);
                if ($this->enabled() && $household !== '' && $household !== (string) ($householdId ?? '')) {
                    // A child always belongs to its parent's household.
                    throw new LocationException('households.validation.location_parent_household', 'household_id');
                }
            } else {
                $householdId = $this->rootHousehold($household);
            }
            $this->requireFreeName($parentId, $householdId, $name, null);

            return ['id' => $this->locations->create($parentId, $householdId, $name, $kind, $description, $actor->user->id), 'household_id' => $householdId];
        }, exclusiveHouseholds: true));
    }

    /**
     * @return array{household_id: ?int}
     *
     * @throws LocationException
     * @throws WriteDeniedException notFound when the location does not exist
     */
    public function update(int $actorId, int $id, ?int $parentId, string $name, string $kind, ?string $description): array
    {
        return $this->guardName(fn (): array => $this->guard->run($actorId, function (WriteActor $actor) use ($id, $parentId, $name, $kind, $description): array {
            $this->requireManager($actor);
            // Both rows in id order, so two concurrent moves cannot deadlock.
            $rows = [];
            foreach (array_unique(array_filter([$id, $parentId], static fn (?int $v): bool => $v !== null)) as $lockId) {
                $rows[$lockId] = null;
            }
            ksort($rows);
            foreach (array_keys($rows) as $lockId) {
                $rows[$lockId] = $this->locations->lock($lockId, true);
            }
            $location = $rows[$id] ?? throw WriteDeniedException::notFound();
            $householdId = self::householdOf($location);
            if ($parentId !== null) {
                $parent = $rows[$parentId] ?? throw new LocationException('validation.in', 'parent_id');
                if ($this->locations->wouldCreateCycle($id, $parentId)) {
                    throw new LocationException('validation.parent_cycle', 'parent_id');
                }
                if ($this->enabled() && self::householdOf($parent) !== $householdId) {
                    // Never under another household's tree.
                    throw new LocationException('households.validation.location_parent_household', 'parent_id');
                }
            }
            $this->requireFreeName($parentId, $householdId, $name, $id);
            $this->locations->update($id, $parentId, $name, $kind, $description);

            return ['household_id' => $householdId];
        }, exclusiveHouseholds: true));
    }

    /**
     * @return array{name: string, household_id: ?int}
     *
     * @throws LocationException
     * @throws WriteDeniedException notFound when the location does not exist
     */
    public function delete(int $actorId, int $id): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($id): array {
            $this->requireManager($actor);
            $location = $this->locations->lock($id, true) ?? throw WriteDeniedException::notFound();
            if ($this->locations->childCount($id) > 0) {
                throw new LocationException('admin.tree.delete_has_children');
            }
            if ($this->locations->usage($id) > 0) {
                throw new LocationException('admin.locations.delete_in_use');
            }
            $this->locations->delete($id);

            return ['name' => (string) $location['name'], 'household_id' => self::householdOf($location)];
        }, exclusiveHouseholds: true);
    }

    /**
     * The household of a new root: chosen explicitly when households are
     * in use (it must exist), the default household otherwise.
     */
    private function rootHousehold(string $household): ?int
    {
        if (!$this->enabled()) {
            return $this->households->defaultId();
        }
        if ($household === '') {
            throw new LocationException('households.validation.location_household_required', 'household_id');
        }
        if (preg_match('/^[1-9]\d{0,9}$/', $household) !== 1 || !$this->households->exists((int) $household)) {
            throw new LocationException('validation.in', 'household_id');
        }

        return (int) $household;
    }

    private function requireFreeName(?int $parentId, ?int $householdId, string $name, ?int $exceptId): void
    {
        $taken = $parentId === null && $this->enabled()
            ? $this->locations->rootNameTaken($householdId, $name, $exceptId)
            : $this->locations->siblingNameTaken($parentId, $name, $exceptId);
        if ($taken) {
            throw new LocationException('validation.name_taken', 'name');
        }
    }

    private function requireManager(WriteActor $actor): void
    {
        if (!$this->authorizer->can($actor->user, Permission::LocationManage)) {
            throw WriteDeniedException::forbidden();
        }
    }

    /**
     * @param array<string, mixed> $location
     */
    private static function householdOf(array $location): ?int
    {
        return ($location['household_id'] ?? null) === null ? null : (int) $location['household_id'];
    }

    /**
     * A sibling with the same name created at the same moment (the unique
     * index on parent and name) is reported like the pre-check.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guardName(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_locations_parent_name')) {
                throw new LocationException('validation.name_taken', 'name');
            }
            throw $e;
        }
    }
}
