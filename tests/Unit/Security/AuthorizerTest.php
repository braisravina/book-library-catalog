<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Security\Role;
use PHPUnit\Framework\TestCase;

final class AuthorizerTest extends TestCase
{
    private Authorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new Authorizer();
    }

    public function testRolePermissionMatrix(): void
    {
        $admin = $this->user(1, Role::Admin);
        $member = $this->user(2, Role::Member);
        $viewer = $this->user(3, Role::Viewer);

        foreach (Permission::cases() as $permission) {
            self::assertTrue($this->authorizer->can($admin, $permission), $permission->value);
        }

        self::assertTrue($this->authorizer->can($member, Permission::CatalogueView));
        self::assertTrue($this->authorizer->can($member, Permission::CatalogueCreate));
        self::assertFalse($this->authorizer->can($member, Permission::CatalogueEditAny));
        self::assertFalse($this->authorizer->can($member, Permission::UserManage));
        self::assertFalse($this->authorizer->can($member, Permission::CatalogueDelete));

        self::assertTrue($this->authorizer->can($viewer, Permission::CatalogueView));
        foreach (Permission::cases() as $permission) {
            if ($permission !== Permission::CatalogueView) {
                self::assertFalse($this->authorizer->can($viewer, $permission), $permission->value);
            }
        }
    }

    public function testGuestsAndInactiveUsersHaveNoPermissions(): void
    {
        self::assertFalse($this->authorizer->can(null, Permission::CatalogueView));
        self::assertFalse($this->authorizer->can($this->user(1, Role::Admin, false), Permission::CatalogueView));
    }

    public function testObjectLevelRecordEditing(): void
    {
        $member = $this->user(2, Role::Member);

        self::assertTrue($this->authorizer->canEditRecord($member, 2));
        self::assertFalse($this->authorizer->canEditRecord($member, 9), 'Members cannot edit others\' records');
        self::assertFalse($this->authorizer->canEditRecord($member, null), 'Orphaned records need an admin');
        self::assertTrue($this->authorizer->canEditRecord($this->user(1, Role::Admin), 9));
        self::assertFalse($this->authorizer->canEditRecord($this->user(3, Role::Viewer), 3), 'Viewers never edit, even own');
        self::assertFalse($this->authorizer->canEditRecord(null, 1));
    }

    public function testObjectLevelCopyManagement(): void
    {
        self::assertTrue($this->authorizer->canManageCopy($this->user(2, Role::Member), 2));
        self::assertFalse($this->authorizer->canManageCopy($this->user(2, Role::Member), 3));
        self::assertTrue($this->authorizer->canManageCopy($this->user(1, Role::Admin), 3));
        self::assertFalse($this->authorizer->canManageCopy($this->user(3, Role::Viewer), 3));
        self::assertFalse($this->authorizer->canManageCopy($this->user(2, Role::Member, false), 2));
    }

    private function user(int $id, Role $role, bool $active = true): User
    {
        return new User($id, "u{$id}@example.org", "User {$id}", $role, 'en', $active, 1);
    }
}
