<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Households\HouseholdRepository;
use DigitalLibrary\Security\Role;
use PDO;
use DigitalLibrary\Audit\AuditLogger;

/**
 * Administrative account changes with lock-out safeguards:
 *  - administrators cannot change their own role or deactivate themselves;
 *  - the last active administrator can never be demoted or deactivated;
 *  - password resets and session revocation for oneself go through the
 *    normal account pages instead.
 * Checks run inside a transaction holding row locks on the active admins.
 */
final class UserAdministration
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly UserRepository $users,
        private readonly ?HouseholdRepository $households = null,
        private readonly bool $householdsEnabled = false,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Creates an account and, optionally, its household memberships, in
     * one transaction (phase H3). An account belongs to no household
     * unless the administrator names some: nothing is assigned by default.
     * The households are locked (in id order) before the account is
     * written, so a household deleted at that moment refuses the whole
     * creation instead of leaving an account without the access that was
     * intended. While the household feature is disabled no membership is
     * created, whatever was submitted.
     *
     * @param list<int> $householdIds distinct, ascending (HouseholdRepository::idList())
     *
     * @return array{id: int, household_ids: list<int>}
     *
     * @throws UserAdminException
     */
    public function create(User $actor, string $email, string $displayName, Role $role, string $passwordHash, string $locale, array $householdIds = []): array
    {
        if ($actor->role !== Role::Admin || !$actor->isActive) {
            throw new UserAdminException('errors.403.message');
        }
        if (!$this->householdsEnabled || $this->households === null) {
            $householdIds = [];
        }

        return $this->run($actor, function () use ($actor, $email, $displayName, $role, $passwordHash, $locale, $householdIds): array {
            foreach ($householdIds as $householdId) {
                if ($this->households->find($householdId) === null) {
                    throw new UserAdminException('households.error.assign_unknown');
                }
            }
            if ($this->users->emailExists($email)) {
                throw new UserAdminException('validation.email_taken');
            }
            $id = $this->users->create($email, $displayName, $role, $passwordHash, $locale, $actor->id);
            foreach ($householdIds as $householdId) {
                $this->households->addMember($householdId, $id, $actor->id);
            }

            $this->logger()->log('user.created', $actor->id, 'user', $id, ['email' => $email, 'role' => $role->value]);
            foreach ($householdIds as $householdId) {
                $this->logger()->log('household.member_added', $actor->id, 'household', $householdId, ['member_user_id' => $id, 'via' => 'user_creation']);
            }
            return ['id' => $id, 'household_ids' => $householdIds];
        });
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}> what changed (for the audit log)
     *
     * @throws UserAdminException
     */
    public function update(User $actor, User $target, string $email, string $displayName, Role $role, string $locale, bool $isActive): array
    {
        return $this->run($actor, function () use ($actor, $target, $email, $displayName, $role, $locale, $isActive): array {
            $current = $this->users->findById($target->id) ?? throw new UserAdminException('errors.404.message');

            if ($actor->id === $current->id && ($role !== $current->role || !$isActive)) {
                throw new UserAdminException('admin.users.cannot_change_self');
            }
            $loses = $current->role === Role::Admin && $current->isActive && ($role !== Role::Admin || !$isActive);
            if ($loses) {
                $admins = $this->users->lockActiveAdminIds();
                if (array_values(array_diff($admins, [$current->id])) === []) {
                    throw new UserAdminException('admin.users.last_admin');
                }
            }

            $this->users->updateProfile($current->id, $email, $displayName, $role, $locale, $isActive);
            if (!$isActive && $current->isActive) {
                $this->users->bumpAuthVersion($current->id);
            }

            $changes = [];
            $compare = [
                'email' => [$current->email, UserRepository::normalizeEmail($email)],
                'display_name' => [$current->displayName, $displayName],
                'role' => [$current->role->value, $role->value],
                'locale' => [$current->locale, $locale],
                'is_active' => [$current->isActive, $isActive],
            ];
            foreach ($compare as $field => [$from, $to]) {
                if ($from !== $to) {
                    $changes[$field] = ['from' => $from, 'to' => $to];
                }
            }

            if ($changes !== []) {
                $this->logger()->log('user.updated', $actor->id, 'user', $target->id, ['changes' => $changes]);
            }
            return $changes;
        });
    }

    /**
     * @throws UserAdminException
     */
    public function resetPassword(User $actor, User $target, string $passwordHash): void
    {
        if ($actor->id === $target->id) {
            throw new UserAdminException('admin.users.use_account_page');
        }
        // updatePassword() also bumps auth_version: all sessions end.
        $this->run($actor, function () use ($actor, $target, $passwordHash): void {
            $this->users->updatePassword($target->id, $passwordHash);
            $this->logger()->log('user.password_reset', $actor->id, 'user', $target->id);
        });
    }

    /**
     * @throws UserAdminException
     */
    public function revokeSessions(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new UserAdminException('admin.users.use_account_page');
        }
        $this->run($actor, function () use ($actor, $target): void {
            $this->users->bumpAuthVersion($target->id);
            $this->logger()->log('user.sessions_revoked', $actor->id, 'user', $target->id);
        });
    }

    private function logger(): AuditLogger
    {
        return $this->audit ?? new AuditLogger($this->pdo);
    }

    private function run(User $actor, callable $work): mixed
    {
        // Household locks precede all account locks, including with the flag off:
        // location administration also locks households in disabled mode.
        return (new AdministratorGuard($this->pdo))->run($actor->id, $work, $this->households, accounts: true);
    }
}
