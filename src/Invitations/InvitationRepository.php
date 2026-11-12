<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

use DigitalLibrary\Catalogue\Repository\Repository;

/**
 * SQL for invitations. Status is derived: accepted, revoked, expired
 * (open but past expires_at) or pending.
 */
final class InvitationRepository extends Repository
{
    public const PER_PAGE = 25;
    public const STATUSES = ['pending', 'accepted', 'expired', 'revoked'];

    private const SELECT = "SELECT i.id, i.email, i.role_id, r.code AS role, i.invited_by, i.expires_at, i.accepted_at, i.accepted_user_id,
            i.revoked_at, i.revoked_by, i.replaced_by_id, i.created_at,
            iu.display_name AS invited_by_name, au.display_name AS accepted_user_name,
            CASE WHEN i.accepted_at IS NOT NULL THEN 'accepted'
                 WHEN i.revoked_at IS NOT NULL THEN 'revoked'
                 WHEN i.expires_at <= UTC_TIMESTAMP() THEN 'expired'
                 ELSE 'pending' END AS status
        FROM invitations i
        JOIN roles r ON r.id = i.role_id
        LEFT JOIN users iu ON iu.id = i.invited_by
        LEFT JOIN users au ON au.id = i.accepted_user_id";

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(self::SELECT . ' WHERE i.id = :id', ['id' => $id]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByHash(string $hash): ?array
    {
        return $this->one(self::SELECT . ' WHERE i.token_hash = :h', ['h' => $hash]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lockByHash(string $hash): ?array
    {
        return $this->one(
            'SELECT id, email, role_id, invited_by, expires_at, accepted_at, revoked_at, expires_at > UTC_TIMESTAMP() AS is_current
             FROM invitations WHERE token_hash = :h FOR UPDATE',
            ['h' => $hash],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function lock(int $id): ?array
    {
        return $this->one(
            'SELECT id, email, role_id, expires_at, accepted_at, revoked_at, expires_at > UTC_TIMESTAMP() AS is_current
             FROM invitations WHERE id = :id FOR UPDATE',
            ['id' => $id],
        );
    }

    /**
     * The open (not accepted, not revoked) invitation for an address, locked.
     *
     * @return array<string, mixed>|null
     */
    public function lockOpenForEmail(string $email): ?array
    {
        return $this->one(
            'SELECT id, expires_at > UTC_TIMESTAMP() AS is_current FROM invitations WHERE open_email = :e FOR UPDATE',
            ['e' => $email],
        );
    }

    public function create(string $email, int $roleId, string $tokenHash, int $invitedBy, string $expiresAt): int
    {
        return $this->insert(
            'INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, created_at)
             VALUES (:e, :r, :h, :b, :x, UTC_TIMESTAMP())',
            ['e' => $email, 'r' => $roleId, 'h' => $tokenHash, 'b' => $invitedBy, 'x' => $expiresAt],
        );
    }

    public function revoke(int $id, ?int $by, ?int $replacedBy = null): void
    {
        $this->run(
            'UPDATE invitations SET revoked_at = UTC_TIMESTAMP(), revoked_by = :b, replaced_by_id = :r
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL',
            ['b' => $by, 'r' => $replacedBy, 'id' => $id],
        );
    }

    public function setReplacement(int $id, int $replacedBy): void
    {
        $this->run('UPDATE invitations SET replaced_by_id = :r WHERE id = :id', ['r' => $replacedBy, 'id' => $id]);
    }

    /**
     * Marks the invitation used; false if it was already used or revoked.
     */
    public function markAccepted(int $id, int $userId): bool
    {
        return $this->run(
            'UPDATE invitations SET accepted_at = UTC_TIMESTAMP(), accepted_user_id = :u
             WHERE id = :id AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > UTC_TIMESTAMP()',
            ['u' => $userId, 'id' => $id],
        )->rowCount() === 1;
    }

    /**
     * @param string $status pending | accepted | expired | revoked | all
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function list(string $status, int $page): array
    {
        $where = match ($status) {
            'pending' => ' WHERE i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > UTC_TIMESTAMP()',
            'accepted' => ' WHERE i.accepted_at IS NOT NULL',
            'expired' => ' WHERE i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at <= UTC_TIMESTAMP()',
            'revoked' => ' WHERE i.accepted_at IS NULL AND i.revoked_at IS NOT NULL',
            default => '',
        };

        return [
            'rows' => $this->many(self::SELECT . $where . ' ORDER BY i.created_at DESC, i.id DESC LIMIT :limit OFFSET :offset',
                ['limit' => self::PER_PAGE, 'offset' => (max(1, $page) - 1) * self::PER_PAGE]),
            'total' => (int) $this->scalar('SELECT COUNT(*) FROM invitations i' . $where),
        ];
    }

    /**
     * Unused invitations (revoked, or expired) whose relevant date is before $before.
     */
    public function countPrunable(string $before): int
    {
        return (int) $this->scalar(
            'SELECT COUNT(*) FROM invitations WHERE accepted_at IS NULL
               AND ((revoked_at IS NOT NULL AND revoked_at < :b1) OR (revoked_at IS NULL AND expires_at < :b2))',
            ['b1' => $before, 'b2' => $before],
        );
    }

    /**
     * Deletes unused, revoked or expired invitations older than $before.
     * Accepted invitations are kept (they document how an account was created).
     */
    public function prune(string $before): int
    {
        // Clear replacement links pointing at rows about to be deleted (FK is SET NULL anyway).
        return $this->run(
            'DELETE FROM invitations WHERE accepted_at IS NULL
               AND ((revoked_at IS NOT NULL AND revoked_at < :b1) OR (revoked_at IS NULL AND expires_at < :b2))',
            ['b1' => $before, 'b2' => $before],
        )->rowCount();
    }
}
