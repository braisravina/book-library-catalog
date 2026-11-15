<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Auth\AdministratorGuard;
use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Security\Authorizer;
use PDO;

/**
 * Household administration: creating, renaming and deleting households
 * and assigning members. Administrators only (household.manage), checked
 * here as well as on the routes.
 *
 * Every change runs in one transaction that locks the household row, so
 * concurrent membership changes and deletions of one household are
 * serialised. Safeguards:
 *  - the temporary default household cannot be deleted;
 *  - a household that still has members, copies, locations or shared
 *    collections cannot be deleted (memberships are removed one by one,
 *    each audited);
 *  - only active accounts can be added (a disabled account never gains
 *    access through a membership).
 */
final class HouseholdService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly HouseholdRepository $households,
        private readonly Authorizer $authorizer,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @throws HouseholdException
     */
    public function create(User $actor, HouseholdInput $input): int
    {
        $this->requireManager($actor);

        return $this->guardName(fn (): int => $this->run($actor, function () use ($actor, $input): int {
            if ($this->households->nameTaken($input->name)) {
                throw new HouseholdException('households.error.name_taken');
            }

            $id = $this->households->create($input, $actor->id);
            $this->logger()->log('household.created', $actor->id, 'household', $id, ['name' => $input->name]);

            return $id;
        }));
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}> what changed (for the audit log)
     *
     * @throws HouseholdException
     */
    public function update(User $actor, int $id, HouseholdInput $input, int $expectedVersion): array
    {
        $this->requireManager($actor);

        return $this->guardName(fn (): array => $this->run($actor, function () use ($actor, $id, $input, $expectedVersion): array {
            $current = $this->households->lock($id) ?? throw new HouseholdException('households.error.not_found');
            if ($this->households->nameTaken($input->name, $id)) {
                throw new HouseholdException('households.error.name_taken');
            }
            if (!$this->households->update($id, $input, $actor->id, $expectedVersion)) {
                throw new HouseholdException('households.error.conflict');
            }
            $changes = [];
            if ((string) $current['name'] !== $input->name) {
                $changes['name'] = ['from' => (string) $current['name'], 'to' => $input->name];
            }
            if ((string) ($current['description'] ?? '') !== $input->description) {
                $changes['description'] = ['from' => (string) ($current['description'] ?? ''), 'to' => $input->description];
            }

            if ($changes !== []) {
                $this->logger()->log('household.updated', $actor->id, 'household', $id, ['changes' => $changes]);
            }
            return $changes;
        }));
    }

    /**
     * @return array<string, mixed> the deleted household (for the audit log)
     *
     * @throws HouseholdException
     */
    public function delete(User $actor, int $id): array
    {
        $this->requireManager($actor);

        return $this->run($actor, function () use ($actor, $id): array {
            $household = $this->households->lock($id) ?? throw new HouseholdException('households.error.not_found');
            if ($household['system_key'] !== null) {
                throw new HouseholdException('households.error.default_protected');
            }
            if ($this->households->copyCount($id) > 0 || $this->households->locationCount($id) > 0 || $this->households->collectionCount($id) > 0) {
                throw new HouseholdException('households.error.in_use');
            }
            if ($this->households->memberCount($id) > 0) {
                throw new HouseholdException('households.error.has_members');
            }
            $this->households->delete($id);
            $this->logger()->log('household.deleted', $actor->id, 'household', $id, ['name' => (string) $household['name']]);

            return $household;
        });
    }

    /**
     * @throws HouseholdException
     */
    public function addMember(User $actor, int $householdId, int $userId): void
    {
        $this->requireManager($actor);
        try {
            $this->run($actor, function () use ($actor, $householdId, $userId): void {
                $this->households->lock($householdId) ?? throw new HouseholdException('households.error.not_found');
                $user = (new \DigitalLibrary\Auth\UserRepository($this->pdo))->findById($userId) ?? throw new HouseholdException('households.error.user_not_found');
                if (!$user->isActive) {
                    throw new HouseholdException('households.error.user_inactive');
                }
                if ($this->households->isMember($householdId, $userId)) {
                    throw new HouseholdException('households.error.already_member');
                }
                $this->households->addMember($householdId, $userId, $actor->id);
                $this->logger()->log('household.member_added', $actor->id, 'household', $householdId, ['member_user_id' => $userId]);
            }, [$userId]);
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e)) {
                throw new HouseholdException('households.error.already_member');
            }
            throw $e;
        }
    }

    /**
     * @return int copies in the household that the removed member owns
     *             (they stay in the household)
     *
     * @throws HouseholdException
     */
    public function removeMember(User $actor, int $householdId, int $userId): int
    {
        $this->requireManager($actor);

        return $this->run($actor, function () use ($actor, $householdId, $userId): int {
            $this->households->lock($householdId) ?? throw new HouseholdException('households.error.not_found');
            if (!$this->households->removeMember($householdId, $userId)) {
                throw new HouseholdException('households.error.not_member');
            }

            $owned = $this->households->ownedCopies($householdId, $userId);
            $this->logger()->log('household.member_removed', $actor->id, 'household', $householdId, ['member_user_id' => $userId, 'owned_copies' => $owned]);
            return $owned;
        });
    }

    private function logger(): AuditLogger
    {
        return $this->audit ?? new AuditLogger($this->pdo);
    }

    private function run(User $actor, callable $work, array $accountIds = []): mixed
    {
        return (new AdministratorGuard($this->pdo))->run($actor->id, $work, $this->households, true, false, $accountIds);
    }

    private function requireManager(User $actor): void
    {
        if (!$this->authorizer->canManageHouseholds($actor)) {
            throw new HouseholdException('households.error.forbidden');
        }
    }

    /**
     * Maps a concurrent duplicate name onto the domain error.
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
            if (Repository::isDuplicateKey($e) && str_contains($e->getMessage(), 'uq_households_name')) {
                throw new HouseholdException('households.error.name_taken');
            }
            throw $e;
        }
    }
}
