<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Administrator-issued, single-use invitations.
 *
 * Issuing returns the raw token exactly once; only its SHA-256 hash is
 * stored. Redemption runs in one transaction that locks the invitation row,
 * re-checks it is unused, unrevoked and unexpired, creates the account and
 * marks the invitation accepted with a conditional UPDATE, so two
 * concurrent redemptions cannot both succeed. Every redemption failure is
 * reported with the same generic message.
 */
final class InvitationService
{
    /** Roles an invitation may grant. Administrator invitations do not exist. */
    public const INVITABLE_ROLES = [Role::Member, Role::Viewer];
    public const GENERIC_ERROR = 'invitations.error.invalid';

    public function __construct(
        private readonly PDO $pdo,
        private readonly InvitationRepository $invitations,
        private readonly UserRepository $users,
        private readonly Authorizer $authorizer,
        private readonly int $maxDays = 30,
    ) {
    }

    /**
     * @return array{id: int, token: string, expires_at: string}
     *
     * @throws InvitationException
     */
    public function issue(User $admin, string $email, Role $role, string $expiresOn): array
    {
        $this->requireAdmin($admin);
        $email = self::normaliseEmail($email);
        $expiresAt = $this->expiry($expiresOn);
        if (!in_array($role, self::INVITABLE_ROLES, true)) {
            throw new InvitationException('invitations.error.role');
        }
        $token = InvitationToken::generate();

        $id = $this->guard(fn (): int => Transaction::run($this->pdo, function () use ($admin, $email, $role, $expiresAt, $token): int {
            if ($this->users->emailExists($email)) {
                throw new InvitationException('invitations.error.email_registered');
            }
            $open = $this->invitations->lockOpenForEmail($email);
            if ($open !== null) {
                if ((int) $open['is_current'] === 1) {
                    throw new InvitationException('invitations.error.pending_exists');
                }
                // An expired invitation is closed before issuing a new one.
                $this->invitations->revoke((int) $open['id'], $admin->id);
            }

            return $this->invitations->create($email, self::roleId($role), InvitationToken::hash($token), $admin->id, $expiresAt);
        }));

        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /**
     * @throws InvitationException
     */
    public function revoke(User $admin, int $id): void
    {
        $this->requireAdmin($admin);
        Transaction::run($this->pdo, function () use ($admin, $id): void {
            $row = $this->invitations->lock($id) ?? throw new InvitationException('invitations.error.not_found');
            if ($row['accepted_at'] !== null || $row['revoked_at'] !== null) {
                throw new InvitationException('invitations.error.not_open');
            }
            $this->invitations->revoke($id, $admin->id);
        });
    }

    /**
     * Revokes an unused invitation and issues a new token for the same
     * address and role (e.g. the link was lost or has expired).
     *
     * @return array{id: int, token: string, expires_at: string, email: string, role: Role}
     *
     * @throws InvitationException
     */
    public function replace(User $admin, int $id, string $expiresOn): array
    {
        $this->requireAdmin($admin);
        $expiresAt = $this->expiry($expiresOn);
        $token = InvitationToken::generate();

        return $this->guard(fn (): array => Transaction::run($this->pdo, function () use ($admin, $id, $expiresAt, $token): array {
            $row = $this->invitations->lock($id) ?? throw new InvitationException('invitations.error.not_found');
            if ($row['accepted_at'] !== null) {
                throw new InvitationException('invitations.error.not_open');
            }
            $email = (string) $row['email'];
            if ($this->users->emailExists($email)) {
                throw new InvitationException('invitations.error.email_registered');
            }
            $open = $this->invitations->lockOpenForEmail($email);
            if ($open !== null && (int) $open['id'] !== $id) {
                throw new InvitationException('invitations.error.pending_exists');
            }
            $this->invitations->revoke($id, $admin->id);
            $role = self::roleFromId((int) $row['role_id']);
            $newId = $this->invitations->create($email, self::roleId($role), InvitationToken::hash($token), $admin->id, $expiresAt);
            $this->invitations->setReplacement($id, $newId);

            return ['id' => $newId, 'token' => $token, 'expires_at' => $expiresAt, 'email' => $email, 'role' => $role];
        }));
    }

    /**
     * The invitation behind a token hash if it can still be redeemed.
     *
     * @return array<string, mixed>|null
     */
    public function redeemable(string $tokenHash): ?array
    {
        $row = $this->invitations->findByHash($tokenHash);

        return $row !== null && $row['status'] === 'pending' ? $row : null;
    }

    /**
     * Creates the invited account. The invitee must type the invited address.
     *
     * @return array{user_id: int, invitation_id: int, role: Role}
     *
     * @throws InvitationException always with GENERIC_ERROR, except 'invitations.error.email_mismatch'
     */
    public function redeem(string $tokenHash, string $email, string $displayName, string $passwordHash, string $locale): array
    {
        return $this->guard(fn (): array => Transaction::run($this->pdo, function () use ($tokenHash, $email, $displayName, $passwordHash, $locale): array {
            $row = $this->invitations->lockByHash($tokenHash) ?? throw new InvitationException(self::GENERIC_ERROR, 'unknown');
            if ($row['accepted_at'] !== null) {
                throw new InvitationException(self::GENERIC_ERROR, 'used');
            }
            if ($row['revoked_at'] !== null) {
                throw new InvitationException(self::GENERIC_ERROR, 'revoked');
            }
            if ((int) $row['is_current'] !== 1) {
                throw new InvitationException(self::GENERIC_ERROR, 'expired');
            }
            if (!hash_equals(self::normaliseEmail((string) $row['email']), self::normaliseEmail($email))) {
                throw new InvitationException('invitations.error.email_mismatch', 'email_mismatch');
            }
            if ($this->users->emailExists((string) $row['email'])) {
                throw new InvitationException(self::GENERIC_ERROR, 'email_registered');
            }
            $role = self::roleFromId((int) $row['role_id']);
            $userId = $this->users->create((string) $row['email'], $displayName, $role, $passwordHash, $locale, $row['invited_by'] === null ? null : (int) $row['invited_by']);
            if (!$this->invitations->markAccepted((int) $row['id'], $userId)) {
                // Unreachable under the row lock; rolls back the new account if it ever happens.
                throw new InvitationException(self::GENERIC_ERROR, 'race');
            }

            return ['user_id' => $userId, 'invitation_id' => (int) $row['id'], 'role' => $role];
        }));
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * Latest allowed expiry date (UTC).
     */
    public function maxExpiryDate(string $today): string
    {
        return gmdate('Y-m-d', strtotime($today . ' +' . $this->maxDays . ' days'));
    }

    /**
     * An expiry date (Y-m-d, UTC) becomes the end of that day; it must be
     * between today and the configured maximum.
     */
    private function expiry(string $date): string
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        $today = gmdate('Y-m-d');
        if ($d === false || $d->format('Y-m-d') !== $date || $date < $today || $date > $this->maxExpiryDate($today)) {
            throw new InvitationException('invitations.error.expiry');
        }

        return $date . ' 23:59:59';
    }

    private function requireAdmin(User $admin): void
    {
        if (!$this->authorizer->can($admin, Permission::InvitationManage)) {
            throw new InvitationException('invitations.error.forbidden');
        }
    }

    private static function roleId(Role $role): int
    {
        return match ($role) {
            Role::Admin => 1,
            Role::Member => 2,
            Role::Viewer => 3,
        };
    }

    private static function roleFromId(int $id): Role
    {
        return match ($id) {
            2 => Role::Member,
            3 => Role::Viewer,
            default => throw new InvitationException(self::GENERIC_ERROR, 'role'),
        };
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function guard(callable $work): mixed
    {
        try {
            return $work();
        } catch (\PDOException $e) {
            if (Repository::isDuplicateKey($e)) {
                // Concurrent issue for the same address, or the address was registered meanwhile.
                throw new InvitationException(str_contains($e->getMessage(), 'uq_invitations_open_email') ? 'invitations.error.pending_exists' : self::GENERIC_ERROR, 'duplicate');
            }
            if (in_array($e->errorInfo[1] ?? null, [1205, 1213], true)) {
                throw new InvitationException(self::GENERIC_ERROR, 'busy');
            }
            throw $e;
        }
    }
}
