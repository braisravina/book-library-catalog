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

    public function testLoanManagementFollowsCopyOwnership(): void
    {
        $member = $this->user(2, Role::Member);
        self::assertTrue($this->authorizer->canManageLoans($member, 2));
        self::assertFalse($this->authorizer->canManageLoans($member, 3), 'Members cannot lend others\' copies');
        self::assertTrue($this->authorizer->canManageLoans($this->user(1, Role::Admin), 3));
        self::assertFalse($this->authorizer->canManageLoans($this->user(3, Role::Viewer), 3), 'Viewers never lend, even own');
        self::assertFalse($this->authorizer->canManageLoans($this->user(2, Role::Member, false), 2));
        self::assertFalse($this->authorizer->canManageLoans(null, 2));

        // Borrower details: owner, administrators and the borrowing user only.
        self::assertTrue($this->authorizer->canSeeLoanDetails($member, 2, null));
        self::assertTrue($this->authorizer->canSeeLoanDetails($this->user(3, Role::Viewer), 2, 3));
        self::assertFalse($this->authorizer->canSeeLoanDetails($this->user(4, Role::Member), 2, 3));
        self::assertFalse($this->authorizer->canSeeLoanDetails($this->user(4, Role::Member), 2, null));
        self::assertTrue($this->authorizer->canSeeLoanDetails($this->user(1, Role::Admin), 2, null));
    }

    public function testWishlistsHaveNoAdministratorOverride(): void
    {
        self::assertTrue($this->authorizer->canManageWishlistItem($this->user(2, Role::Member), 2));
        self::assertFalse($this->authorizer->canManageWishlistItem($this->user(2, Role::Member), 3));
        self::assertFalse($this->authorizer->canManageWishlistItem($this->user(1, Role::Admin), 3), 'Administrators cannot open others\' wishlists');
        self::assertTrue($this->authorizer->canManageWishlistItem($this->user(1, Role::Admin), 1));
        self::assertFalse($this->authorizer->canManageWishlistItem($this->user(3, Role::Viewer), 3));
        self::assertFalse($this->authorizer->canManageWishlistItem(null, 2));
    }

    public function testCollectionVisibilityAndManagement(): void
    {
        $owner = $this->user(2, Role::Member);
        $other = $this->user(4, Role::Member);
        $viewer = $this->user(3, Role::Viewer);
        $admin = $this->user(1, Role::Admin);

        self::assertTrue($this->authorizer->canViewCollection($owner, 2, 'private'));
        self::assertFalse($this->authorizer->canViewCollection($other, 2, 'private'));
        self::assertFalse($this->authorizer->canViewCollection($viewer, 2, 'private'));
        self::assertTrue($this->authorizer->canViewCollection($admin, 2, 'private'), 'collection.manage_any grants access to private collections');
        foreach ([$owner, $other, $viewer, $admin] as $user) {
            self::assertTrue($this->authorizer->canViewCollection($user, 2, 'shared'));
        }
        self::assertFalse($this->authorizer->canViewCollection(null, 2, 'shared'), 'No anonymous access');
        self::assertFalse($this->authorizer->canViewCollection($this->user(4, Role::Member, false), 2, 'shared'));
        self::assertFalse($this->authorizer->canViewCollection($other, 2, 'public'), 'Unknown visibilities are not shared');

        self::assertTrue($this->authorizer->canManageCollection($owner, 2));
        self::assertFalse($this->authorizer->canManageCollection($other, 2), 'Viewing a shared collection does not imply editing');
        self::assertFalse($this->authorizer->canManageCollection($viewer, 3), 'Viewers never manage collections');
        self::assertTrue($this->authorizer->canManageCollection($admin, 2));
        self::assertFalse($this->authorizer->canManageCollection(null, 2));
    }

    private function user(int $id, Role $role, bool $active = true): User
    {
        return new User($id, "u{$id}@example.org", "User {$id}", $role, 'en', $active, 1);
    }
}
