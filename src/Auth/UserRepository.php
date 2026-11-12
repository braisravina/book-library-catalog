<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Security\Role;
use PDO;

final class UserRepository
{
    private const SELECT = 'SELECT u.id, u.email, u.display_name, u.locale, u.is_active, u.auth_version,
            u.last_login_at, u.password_hash, r.code AS role_code
        FROM users u JOIN roles r ON r.id = u.role_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findById(int $id): ?User
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE u.id = :id', ['id' => $id]);

        return $row ? User::fromRow($row) : null;
    }

    /**
     * @return array{user: User, password_hash: string}|null
     */
    public function findCredentialsByEmail(string $email): ?array
    {
        $row = $this->fetchOne(self::SELECT . ' WHERE u.email = :email', ['email' => self::normalizeEmail($email)]);

        return $row ? ['user' => User::fromRow($row), 'password_hash' => (string) $row['password_hash']] : null;
    }

    public function passwordHash(int $id): ?string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $hash = $stmt->fetchColumn();

        return is_string($hash) ? $hash : null;
    }

    public function emailExists(string $email, ?int $excludeId = null): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email AND id <> :exclude');
        $stmt->execute(['email' => self::normalizeEmail($email), 'exclude' => $excludeId ?? 0]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Administrative profile update. Role changes take effect on the user's
     * next request because the user is reloaded from the database each time.
     */
    public function updateProfile(int $id, string $email, string $displayName, Role $role, string $locale, bool $isActive): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users u JOIN roles r ON r.code = :role
             SET u.email = :email, u.display_name = :name, u.role_id = r.id, u.locale = :locale,
                 u.is_active = :active, u.updated_at = UTC_TIMESTAMP()
             WHERE u.id = :id'
        );
        $stmt->execute([
            'role' => $role->value,
            'email' => self::normalizeEmail($email),
            'name' => $displayName,
            'locale' => $locale,
            'active' => $isActive ? 1 : 0,
            'id' => $id,
        ]);
    }

    /**
     * Invalidates every existing session of the user (sessions carry the
     * auth_version they were created with).
     */
    public function bumpAuthVersion(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET auth_version = auth_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Locks the active administrator rows for the rest of the current
     * transaction, so concurrent demotions cannot remove the last admin.
     *
     * @return list<int>
     */
    public function lockActiveAdminIds(): array
    {
        $stmt = $this->pdo->query(
            "SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id
             WHERE r.code = 'admin' AND u.is_active = 1 ORDER BY u.id FOR UPDATE"
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function create(string $email, string $displayName, Role $role, string $passwordHash, string $locale, ?int $createdBy): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (email, display_name, role_id, password_hash, locale, is_active, auth_version, created_by, created_at, updated_at)
             SELECT :email, :name, r.id, :hash, :locale, 1, 1, :created_by, UTC_TIMESTAMP(), UTC_TIMESTAMP()
             FROM roles r WHERE r.code = :role'
        );
        $stmt->execute([
            'email' => self::normalizeEmail($email),
            'name' => $displayName,
            'hash' => $passwordHash,
            'locale' => $locale,
            'created_by' => $createdBy,
            'role' => $role->value,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('Role is not seeded: ' . $role->value);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Changing the password bumps auth_version, which invalidates every other
     * session belonging to the user.
     */
    public function updatePassword(int $id, string $passwordHash, bool $bumpVersion = true): void
    {
        $sql = 'UPDATE users SET password_hash = :hash, password_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()'
            . ($bumpVersion ? ', auth_version = auth_version + 1' : '')
            . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute(['hash' => $passwordHash, 'id' => $id]);
    }

    public function recordLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id')->execute(['id' => $id]);
    }

    public function updateLocale(int $id, string $locale): void
    {
        $this->pdo->prepare('UPDATE users SET locale = :locale, updated_at = UTC_TIMESTAMP() WHERE id = :id')
            ->execute(['locale' => $locale, 'id' => $id]);
    }

    /**
     * @return list<User>
     */
    public function all(): array
    {
        $stmt = $this->pdo->query(self::SELECT . ' ORDER BY u.display_name, u.id');

        return array_map(User::fromRow(...), $stmt->fetchAll());
    }

    public function count(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }
}
