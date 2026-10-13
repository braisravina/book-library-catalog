<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Security\Role;

final class User
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $displayName,
        public readonly Role $role,
        public readonly string $locale,
        public readonly bool $isActive,
        public readonly int $authVersion,
        public readonly ?string $lastLoginAt = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['display_name'],
            Role::from((string) $row['role_code']),
            (string) $row['locale'],
            (bool) $row['is_active'],
            (int) $row['auth_version'],
            isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        );
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }
}
