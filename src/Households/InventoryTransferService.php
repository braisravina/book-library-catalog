<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Catalogue\Repository\CopyRepository;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Catalogue\Repository\WorkRepository;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;

/**
 * H4: administrator-only, immutable reviewed plans and atomic application.
 * All mutations acquire exclusive household locks before accounts, operation,
 * editions, works, copies, loans and locations. No nested CopyService calls.
 * Plan snapshots confer no authorization. Retry work is database-only, including
 * audit events on the same connection. NULL source is distinct from default.
 */
final class InventoryTransferService
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 100;
    public const MAX_LOCATIONS = 100;

    public function __construct(
        private readonly WriteGuard $guard,
        private readonly TransferRepository $records,
        private readonly HouseholdRepository $households,
        private readonly UserRepository $users,
        private readonly Authorizer $authorizer,
        private readonly EditionRepository $editions,
        private readonly WorkRepository $works,
        private readonly CopyRepository $copies,
        private readonly LocationRepository $locations,
        private readonly CopyHousehold $placement,
        private readonly AuditLogger $audit,
        private readonly int $limit = self::DEFAULT_LIMIT,
    ) {
        if ($limit < 1 || $limit > self::MAX_LIMIT) {
            throw new \InvalidArgumentException('HOUSEHOLD_TRANSFER_LIMIT must be between 1 and 100.');
        }
    }

    public function limit(): int
    {
        return $this->limit;
    }

    private function requireAdmin(WriteActor $actor): void
    {
        if (!$this->guard->enabled()) {
            throw WriteDeniedException::notFound();
        }
        if (!$this->authorizer->can($actor->user, Permission::HouseholdManage)
            || !$this->authorizer->can($actor->user, Permission::CopyManageAny)
            || !$this->authorizer->can($actor->user, Permission::LocationManage)) {
            throw WriteDeniedException::forbidden();
        }
    }

    /** Strictly typed service contract; no posted household is trusted as an assignment. */
    private function request(array $input): array
    {
        if (!array_key_exists('source', $input) || !array_key_exists('destination', $input)) {
            throw TransferException::because('input');
        }
        $request = array_intersect_key($input, array_flip(['kind', 'source', 'destination', 'selected', 'location', 'root', 'root_version', 'parent', 'owner', 'reason']));
        $request += ['kind' => 'copies', 'source' => null, 'destination' => null, 'selected' => [], 'location' => null, 'root' => null, 'root_version' => null, 'parent' => null, 'owner' => null, 'reason' => ''];
        if (!in_array($request['kind'], ['copies', 'subtree', 'owner'], true) || !is_string($request['reason'])
            || trim($request['reason']) === '' || mb_strlen($request['reason']) > 1000 || !is_array($request['selected'])) {
            throw TransferException::because('input');
        }
        foreach (['source', 'destination', 'location', 'root', 'root_version', 'parent', 'owner'] as $key) {
            if ($request[$key] !== null && (!is_int($request[$key]) || $request[$key] < 1 || $request[$key] > 4294967295)) {
                throw TransferException::because('input');
            }
        }
        $selected = [];
        foreach ($request['selected'] as $token) {
            if (!is_string($token) || !preg_match('/^([1-9]\d{0,9}):([1-9]\d{0,9})$/D', $token, $m)
                || (int) $m[1] > 4294967295 || (int) $m[2] > 4294967295 || isset($selected[(int) $m[1]])) {
                throw TransferException::because('input');
            }
            $selected[(int) $m[1]] = (int) $m[2];
        }
        if (count($selected) > $this->limit || ($request['kind'] !== 'subtree' && $selected === [])) {
            throw TransferException::because('limit');
        }
        if ($request['kind'] === 'subtree' && ($request['root'] === null || $request['root_version'] === null)) {
            throw TransferException::because('input');
        }
        if ($request['kind'] === 'owner') {
            if (count($selected) !== 1 || $request['source'] !== $request['destination'] || $request['owner'] === null) {
                throw TransferException::because('input');
            }
        } elseif ($request['destination'] === null || $request['source'] === $request['destination']) {
            throw TransferException::because('household');
        }
        ksort($selected);
        $request['selected'] = $selected;
        $request['reason'] = trim($request['reason']);
        return $request;
    }

    /** Read-only dry run: no plan, inventory or audit rows are written. */
    public function preview(int $actorId, array $input): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($input): array {
            $this->requireAdmin($actor);
            return $this->build($this->request($input), $actor);
        }, exclusiveHouseholds: true);
    }

    /** Saving is explicit: only administrative plan/item rows are created. */
    public function plan(int $actorId, array $input): int
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($input): int {
            $this->requireAdmin($actor);
            $plan = $this->build($this->request($input), $actor);
            return $this->records->save($actor->user->id, $plan);
        }, exclusiveHouseholds: true);
    }

    private function build(array $request, WriteActor $actor): array
    {
        foreach (['source', 'destination'] as $key) {
            if ($request[$key] !== null && !$this->households->exists($request[$key])) {
                throw TransferException::because('household');
            }
        }
        $tree = $this->records->locations();
        $byId = array_column($tree, null, 'id');
        $subtree = [];
        if ($request['kind'] === 'subtree') {
            $root = $byId[$request['root']] ?? throw TransferException::because('missing');
            if ($root['row_version'] !== $request['root_version']) {
                throw TransferException::because('stale', $root['id']);
            }
            $pending = [$root['id']];
            while ($pending !== []) {
                $id = array_pop($pending);
                if (isset($subtree[$id]) || count($subtree) >= self::MAX_LOCATIONS) {
                    throw TransferException::because('tree');
                }
                $node = $byId[$id];
                if ($node['household_id'] !== $request['source']) {
                    throw TransferException::because('tree', $id);
                }
                $subtree[$id] = $node;
                foreach ($tree as $child) {
                    if ($child['parent_id'] === $id) {
                        $pending[] = $child['id'];
                    }
                }
            }
            ksort($subtree);
            $parent = $request['parent'];
            if ($parent !== null && (isset($subtree[$parent]) || !isset($byId[$parent]) || $byId[$parent]['household_id'] !== $request['destination'])) {
                throw TransferException::because('tree');
            }
            $seen = [];
            for ($ancestor = $parent; $ancestor !== null; $ancestor = $byId[$ancestor]['parent_id']) {
                if (isset($seen[$ancestor]) || !isset($byId[$ancestor]) || $byId[$ancestor]['household_id'] !== $request['destination']) {
                    throw TransferException::because('tree');
                }
                $seen[$ancestor] = true;
            }
            if ($this->records->nameConflict($root['id'], $parent, $request['destination'])) {
                throw TransferException::because('name');
            }
            $ids = array_column($this->records->containedCopies(array_keys($subtree)), 'id');
            if (count($ids) > $this->limit) {
                throw TransferException::because('limit');
            }
            // Subtree preview derives the full set. If explicitly supplied, it must be exact.
            if ($request['selected'] !== [] && array_keys($request['selected']) !== $ids) {
                throw TransferException::because('stale');
            }
        } else {
            $ids = array_keys($request['selected']);
        }
        $rows = $this->records->copies($ids);
        if (count($rows) !== count($ids)) {
            throw TransferException::because('missing');
        }
        $errors = [];
        $fail = static function (string $key, ?int $id = null) use (&$errors): void {
            $errors[] = ['key' => 'transfers.error.' . $key, 'id' => $id];
        };
        foreach ($rows as $copy) {
            $id = $copy['id'];
            [$resolved, $placementError] = $this->placement->resolve($actor->user, $actor->scope, (string) ($request['destination'] ?? ''), $copy);
            if ($placementError !== null || $resolved !== $request['destination']) {
                $fail('household', $id);
            }
            if ($copy['household_id'] !== $request['source']) {
                $fail('source', $id);
            }
            if (isset($request['selected'][$id]) && $copy['row_version'] !== $request['selected'][$id]) {
                $fail('stale', $id);
            }
            if ($copy['status'] === 'on_loan' || $this->records->hasLoan($id, true)) {
                $fail('loan', $id);
            }
            $ownerId = $request['kind'] === 'owner' ? $request['owner'] : $copy['owner_user_id'];
            $owner = $this->users->findById($ownerId);
            if ($owner === null || !$owner->isActive || ($request['destination'] === null
                ? !$this->authorizer->can($owner, Permission::CopyManageAny)
                : !$this->placement->ownerFits($owner, $request['destination']))) {
                $fail('owner', $id);
            }
            if ($request['kind'] === 'owner' && ($ownerId === $copy['owner_user_id'] || $this->records->hasLoan($id, false))) {
                // Existing owner-based loan authorization would reveal historical borrowers.
                $fail('owner_history', $id);
            }
            $locationId = $request['kind'] === 'subtree' ? $copy['location_id'] : $request['location'];
            if ($request['kind'] === 'owner' && $locationId !== $copy['location_id']) {
                $fail('location', $id);
            }
            if ($locationId !== null && (!isset($byId[$locationId]) || ($request['kind'] !== 'subtree' && $byId[$locationId]['household_id'] !== $request['destination']))) {
                $fail('location', $id);
            }
            if ($request['kind'] !== 'owner' && $this->records->codeConflict($id, $request['destination'], $ids)) {
                $fail('code', $id);
            }
        }
        if ($errors !== []) {
            throw new TransferException($errors);
        }
        // Save both root edges/versions and complete copy identities for stale-plan detection.
        return ['source_household' => $request['source'] === null ? null : $this->households->find($request['source']),
            'destination_household' => $request['destination'] === null ? null : $this->households->find($request['destination']),
            'kind' => $request['kind'], 'source' => $request['source'], 'destination' => $request['destination'],
            'reason' => $request['reason'], 'location' => $request['location'], 'root' => $request['root'], 'parent' => $request['parent'], 'owner' => $request['owner'],
            'locations' => array_values($subtree), 'copies' => $rows,
            'destination_location' => ($request['kind'] === 'subtree' ? ($byId[$request['parent']] ?? null) : ($byId[$request['location']] ?? null))];
    }

    public function show(int $actorId, int $operationId): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($operationId): array {
            $this->requireAdmin($actor);
            $row = $this->records->operation($operationId) ?? throw WriteDeniedException::notFound();
            $row['plan'] = $this->decode($row);
            return $row;
        });
    }

    private function decode(array $row): array
    {
        if (!hash_equals($row['digest'], hash('sha256', $row['payload']))) {
            throw TransferException::because('stale');
        }
        return json_decode($row['payload'], true, 32, JSON_THROW_ON_ERROR);
    }

    public function apply(int $actorId, int $operationId, string $confirmation): array
    {
        // Admin check before even reading a plan to discover account lock IDs.
        $row = $this->show($actorId, $operationId);
        $plan = $row['plan'];
        $accounts = array_column($plan['copies'], 'owner_user_id');
        if ($plan['owner'] !== null) {
            $accounts[] = $plan['owner'];
        }
        try {
            return $this->guard->run($actorId, function (WriteActor $actor) use ($operationId, $confirmation): array {
                $this->requireAdmin($actor);
                $row = $this->records->operation($operationId, true) ?? throw WriteDeniedException::notFound();
                if (!hash_equals($row['digest'], $confirmation)) {
                    throw TransferException::because('confirmation');
                }
                if ($row['status'] === 'completed') {
                    $row['plan'] = $this->decode($row);
                    return $row;
                }
                if ($row['status'] !== 'planned') {
                    throw TransferException::because('cancelled');
                }
                $plan = $this->decode($row);
                // Lock all editions before all works, then all copies: never alternate levels.
                $editionIds = array_unique(array_column($plan['copies'], 'edition_id'));
                sort($editionIds, SORT_NUMERIC);
                $workIds = [];
                foreach ($editionIds as $id) {
                    $edition = $this->editions->lock($id, false) ?? throw TransferException::because('stale');
                    $workIds[] = $edition['work_id'];
                }
                $workIds = array_unique($workIds);
                sort($workIds, SORT_NUMERIC);
                foreach ($workIds as $id) {
                    $this->works->lock($id, false);
                }
                foreach ($plan['copies'] as $copy) {
                    $this->copies->lock($copy['id']) ?? throw TransferException::because('missing');
                }
                $locationIds = array_column($plan['locations'], 'id');
                if ($plan['destination_location'] !== null) {
                    $locationIds[] = $plan['destination_location']['id'];
                }
                $locationIds = array_unique($locationIds);
                sort($locationIds, SORT_NUMERIC);
                foreach ($locationIds as $id) {
                    $this->locations->lock($id, true) ?? throw TransferException::because('stale');
                }
                $input = array_intersect_key($plan, array_flip(['kind', 'source', 'destination', 'reason', 'location', 'root', 'parent', 'owner']));
                $input['selected'] = array_map(static fn (array $c): string => $c['id'] . ':' . $c['row_version'], $plan['copies']);
                $input['root_version'] = $plan['kind'] === 'subtree' ? $plan['locations'][array_search($plan['root'], array_column($plan['locations'], 'id'), true)]['row_version'] : null;
                $current = $this->build($this->request($input), $actor);
                if ($current !== $plan) {
                    throw TransferException::because('stale');
                }
                foreach ($plan['locations'] as $location) {
                    $this->records->moveLocation($location, $plan);
                }
                foreach ($plan['copies'] as $copy) {
                    $this->records->moveCopy($copy, $plan, $actor->user->id);
                    $this->audit->log('inventory.transferred', $actor->user->id, 'copy', $copy['id'], [
                        'operation_id' => $operationId, 'household_from' => $copy['household_id'], 'household_to' => $plan['destination'],
                        'location_from' => $copy['location_id'], 'location_to' => $plan['kind'] === 'subtree' ? $copy['location_id'] : $plan['location'],
                        'owner_from' => $copy['owner_user_id'], 'owner_to' => $plan['kind'] === 'owner' ? $plan['owner'] : $copy['owner_user_id'],
                    ]);
                }
                $this->audit->log('inventory.transfer_completed', $actor->user->id, 'inventory_transfer', $operationId,
                    ['reason' => $plan['reason'], 'kind' => $plan['kind'], 'copy_ids' => array_column($plan['copies'], 'id'), 'location_ids' => array_column($plan['locations'], 'id')]);
                $this->records->setStatus($operationId, 'completed');
                $row['status'] = 'completed';
                $row['plan'] = $plan;
                return $row;
            }, exclusiveHouseholds: true, accountIds: $accounts);
        } catch (\PDOException $e) {
            if (\DigitalLibrary\Catalogue\Repository\Repository::isDuplicateKey($e)
                && str_contains($e->getMessage(), 'uq_copies_scope_inventory_code')) {
                throw TransferException::because('code');
            }
            throw $e;
        }
    }

    public function cancel(int $actorId, int $id): void
    {
        $this->guard->run($actorId, function (WriteActor $actor) use ($id): void {
            $this->requireAdmin($actor);
            $row = $this->records->operation($id, true) ?? throw WriteDeniedException::notFound();
            if ($row['status'] === 'planned') {
                $this->records->setStatus($id, 'cancelled');
                $this->audit->log('inventory.transfer_cancelled', $actor->user->id, 'inventory_transfer', $id);
            }
        }, exclusiveHouseholds: true);
    }

    public function overview(int $actorId, ?int $source, int $after = 0): array
    {
        return $this->guard->run($actorId, function (WriteActor $actor) use ($source, $after): array {
            $this->requireAdmin($actor);
            return ['households' => $this->households->all(), 'copies' => $this->records->inventory($source, max(0, $after)),
                'locations' => $this->records->locations(), 'history' => $this->records->history(), 'diagnostics' => $this->records->diagnostics(),
                'source' => $source, 'limit' => $this->limit, 'owners' => $this->users->all()];
        });
    }
}
