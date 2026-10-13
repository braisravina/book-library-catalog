<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Role;
use PDO;

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
    ) {
    }

    /**
     * @return array<string, array{from: mixed, to: mixed}> what changed (for the audit log)
     *
     * @throws UserAdminException
     */
    public function update(User $actor, User $target, string $email, string $displayName, Role $role, string $locale, bool $isActive): array
    {
        return Transaction::run($this->pdo, function () use ($actor, $target, $email, $displayName, $role, $locale, $isActive): array {
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
        $this->users->updatePassword($target->id, $passwordHash);
    }

    /**
     * @throws UserAdminException
     */
    public function revokeSessions(User $actor, User $target): void
    {
        if ($actor->id === $target->id) {
            throw new UserAdminException('admin.users.use_account_page');
        }
        $this->users->bumpAuthVersion($target->id);
    }
}
