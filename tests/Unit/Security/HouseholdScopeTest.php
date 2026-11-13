<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Auth\User;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\HouseholdScope;
use DigitalLibrary\Security\Permission;
use DigitalLibrary\Security\Role;
use PHPUnit\Framework\TestCase;

final class HouseholdScopeTest extends TestCase
{
    private static function user(int $id, Role $role, bool $active = true): User
    {
        return new User($id, "u{$id}@example.org", "User {$id}", $role, 'en', $active, 1);
    }

    public function testAdministratorsReachEveryHouseholdWithoutLookingUpMemberships(): void
    {
        $scope = HouseholdScope::forUser(self::user(1, Role::Admin), static fn (): array => self::fail('Not looked up for administrators'));

        self::assertTrue($scope->unrestricted);
        self::assertTrue($scope->includes(7));
        self::assertTrue($scope->includes(null), 'Unassigned inventory is reachable by administrators only');
        self::assertFalse($scope->isEmpty());
    }

    public function testMembersAndViewersReachExactlyTheirHouseholds(): void
    {
        foreach ([Role::Member, Role::Viewer] as $role) {
            $scope = HouseholdScope::forUser(self::user(2, $role), static fn (int $id): array => $id === 2 ? ['5', 3, 5] : self::fail('Wrong user'));

            self::assertFalse($scope->unrestricted);
            self::assertSame([3, 5], $scope->ids(), 'Deduplicated, integers, ordered');
            self::assertTrue($scope->includes(3));
            self::assertTrue($scope->includes(5));
            self::assertFalse($scope->includes(4));
            self::assertFalse($scope->includes(null), 'Unassigned inventory is never reachable through a membership');
        }
    }

    public function testNoMembershipMeansNoHousehold(): void
    {
        $scope = HouseholdScope::forUser(self::user(2, Role::Member), static fn (): array => []);

        self::assertTrue($scope->isEmpty());
        self::assertFalse($scope->includes(1));
        self::assertFalse($scope->includes(null));
    }

    public function testSignedOutAndDeactivatedAccountsReachNothingEvenAsAdministrator(): void
    {
        $never = static fn (): array => self::fail('Not looked up');
        foreach ([null, self::user(3, Role::Member, false), self::user(4, Role::Admin, false)] as $user) {
            $scope = HouseholdScope::forUser($user, $never);

            self::assertTrue($scope->isEmpty());
            self::assertFalse($scope->unrestricted);
            self::assertFalse($scope->includes(1));
        }
    }

    public function testInvalidIdsAreDropped(): void
    {
        self::assertSame([2], HouseholdScope::of([0, -1, 'x', 2, '2'])->ids());
    }

    public function testOnlyAdministratorsManageHouseholds(): void
    {
        $authorizer = new Authorizer();

        self::assertTrue($authorizer->canManageHouseholds(self::user(1, Role::Admin)));
        self::assertFalse($authorizer->canManageHouseholds(self::user(2, Role::Member)));
        self::assertFalse($authorizer->canManageHouseholds(self::user(3, Role::Viewer)));
        self::assertFalse($authorizer->canManageHouseholds(self::user(4, Role::Admin, false)));
        self::assertFalse($authorizer->canManageHouseholds(null));
        self::assertFalse(Role::Member->has(Permission::HouseholdManage));
        self::assertFalse(Role::Viewer->has(Permission::HouseholdManage));
    }

    public function testCopyAssignmentNeedsCopyPermissionAndMembership(): void
    {
        $authorizer = new Authorizer();
        $admin = self::user(1, Role::Admin);
        $member = self::user(2, Role::Member);
        $viewer = self::user(3, Role::Viewer);

        self::assertTrue($authorizer->canAssignCopyToHousehold($admin, HouseholdScope::all(), 9), 'Administrators: any household');
        self::assertTrue($authorizer->canAssignCopyToHousehold($member, HouseholdScope::of([4]), 4));
        self::assertFalse($authorizer->canAssignCopyToHousehold($member, HouseholdScope::of([4]), 5), 'Not a member');
        self::assertFalse($authorizer->canAssignCopyToHousehold($member, HouseholdScope::none(), 4));
        self::assertFalse($authorizer->canAssignCopyToHousehold($member, HouseholdScope::all(), 4), 'An unrestricted scope is never honoured for a non-administrator');
        self::assertFalse($authorizer->canAssignCopyToHousehold($viewer, HouseholdScope::of([4]), 4), 'Membership alone gives no right to add copies');
        self::assertFalse($authorizer->canAssignCopyToHousehold(null, HouseholdScope::of([4]), 4));
        self::assertFalse($authorizer->canAssignCopyToAnyHousehold($member));
    }
}
