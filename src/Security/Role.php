<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

enum Role: string
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Member => [
                Permission::CatalogueView,
                Permission::CatalogueCreate,
                Permission::CatalogueEditOwn,
                Permission::CopyManageOwn,
            ],
            self::Viewer => [
                Permission::CatalogueView,
            ],
        };
    }

    public function has(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function labelKey(): string
    {
        return 'roles.' . $this->value;
    }
}
