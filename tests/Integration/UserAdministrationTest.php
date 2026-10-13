<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;

final class UserAdministrationTest extends IntegrationTestCase
{
    private int $adminId;
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    /**
     * @return array<string, string>
     */
    private function profile(string $email, string $name, string $role, string $locale = 'en', bool $active = true): array
    {
        return ['email' => $email, 'display_name' => $name, 'role' => $role, 'locale' => $locale] + ($active ? ['is_active' => '1'] : []);
    }

    public function testAdminEditsProfileRoleAndLocaleWithAudit(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        self::assertSame(200, $this->request($admin, 'GET', "/admin/users/{$this->memberId}/edit")->status());

        $response = $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('Renamed@Example.org', 'Renamed', 'viewer', 'gl'));
        self::assertSame(303, $response->status());

        $row = $this->pdo()->query("SELECT u.email, u.display_name, u.locale, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$this->memberId}")->fetch();
        self::assertSame(['renamed@example.org', 'Renamed', 'gl', 'viewer'], [$row['email'], $row['display_name'], $row['locale'], $row['code']]);
        $details = json_decode((string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'user.updated'")->fetchColumn(), true);
        self::assertEquals(['from' => 'member', 'to' => 'viewer'], $details['changes']['role']);
        self::assertEquals(['from' => 'en', 'to' => 'gl'], $details['changes']['locale']);
    }

    public function testRoleChangeAppliesOnTheNextRequestWithoutSignOut(): void
    {
        [$member] = $this->signedIn('member@example.org');
        self::assertSame(200, $this->request($member, 'GET', '/books/new')->status());

        [$admin, $token] = $this->signedIn('admin@example.org');
        $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('member@example.org', 'Member', 'viewer'));

        self::assertSame(403, $this->request($member, 'GET', '/books/new')->status(), 'Demoted immediately');
        self::assertSame(200, $this->request($member, 'GET', '/books')->status(), 'Still signed in');
    }

    public function testDeactivationEndsSessionsAndBlocksLogin(): void
    {
        [$member] = $this->signedIn('member@example.org');
        [$admin, $token] = $this->signedIn('admin@example.org');

        $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('member@example.org', 'Member', 'member', 'en', false));
        self::assertSame(303, $this->request($member, 'GET', '/books')->status(), 'Existing session is gone');
        self::assertSame(422, $this->login($this->browser(), 'member@example.org')->status());

        $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('member@example.org', 'Member', 'member'));
        self::assertSame(303, $this->login($this->browser(), 'member@example.org')->status(), 'Reactivated');
    }

    public function testAdminCannotDemoteOrDeactivateThemselves(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $this->createUser('second-admin@example.org', Role::Admin);

        $response = $this->request($admin, 'POST', "/admin/users/{$this->adminId}", ['_csrf' => $token] + $this->profile('admin@example.org', 'Admin', 'member'));
        self::assertSame(409, $response->status());
        self::assertStringContainsString('cannot change your own role', $response->body());

        $response = $this->request($admin, 'POST', "/admin/users/{$this->adminId}", ['_csrf' => $token] + $this->profile('admin@example.org', 'Admin', 'admin', 'en', false));
        self::assertSame(409, $response->status());
        self::assertSame(1, $this->countRows("SELECT is_active FROM users WHERE id = {$this->adminId}"));

        self::assertSame(303, $this->request($admin, 'POST', "/admin/users/{$this->adminId}", ['_csrf' => $token] + $this->profile('admin@example.org', 'New Name', 'admin', 'es'))->status(), 'Own name and language may change');
    }

    public function testTheLastActiveAdministratorCannotBeRemoved(): void
    {
        $otherId = $this->createUser('other-admin@example.org', Role::Admin);
        [$admin, $token] = $this->signedIn('admin@example.org');

        // Demoting the other admin is fine while this one remains...
        self::assertSame(303, $this->request($admin, 'POST', "/admin/users/{$otherId}", ['_csrf' => $token] + $this->profile('other-admin@example.org', 'Other', 'member'))->status());

        // ...but with an inactive admin left over, the signed-in admin is the last active one.
        $inactiveAdmin = $this->createUser('dormant@example.org', Role::Admin, active: false);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1"));

        // Re-promote the other, then make the signed-in admin the target from the other's session.
        $this->request($admin, 'POST', "/admin/users/{$otherId}", ['_csrf' => $token] + $this->profile('other-admin@example.org', 'Other', 'admin'));
        [$other, $otherToken] = $this->signedIn('other-admin@example.org');
        self::assertSame(303, $this->request($other, 'POST', "/admin/users/{$this->adminId}", ['_csrf' => $otherToken] + $this->profile('admin@example.org', 'Admin', 'member'))->status());

        // Now "other" is the only active admin; nobody can remove them, and the dormant admin does not count.
        [$admin2] = [$other];
        $response = $this->request($admin2, 'POST', "/admin/users/{$inactiveAdmin}", ['_csrf' => $otherToken] + $this->profile('dormant@example.org', 'Dormant', 'member', 'en', false));
        self::assertSame(303, $response->status(), 'Changing an inactive admin is unaffected');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'admin' AND u.is_active = 1"));
    }

    public function testLastAdminGuardInTheService(): void
    {
        $users = new \DigitalLibrary\Auth\UserRepository($this->pdo());
        $service = new \DigitalLibrary\Auth\UserAdministration($this->pdo(), $users);
        $memberActor = $users->findById($this->memberId);
        $admin = $users->findById($this->adminId);

        // The guard applies even if a caller bypassed the self-change rule.
        try {
            $service->update($memberActor, $admin, 'admin@example.org', 'Admin', Role::Viewer, 'en', true);
            self::fail('Expected refusal');
        } catch (\DigitalLibrary\Auth\UserAdminException $e) {
            self::assertSame('admin.users.last_admin', $e->getMessage());
        }
        self::assertSame('admin', $this->pdo()->query("SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$this->adminId}")->fetchColumn());
    }

    public function testPasswordResetSignsOutAndReplacesPassword(): void
    {
        [$member] = $this->signedIn('member@example.org');
        [$admin, $token] = $this->signedIn('admin@example.org');

        $weak = $this->request($admin, 'POST', "/admin/users/{$this->memberId}/password", ['_csrf' => $token, 'new_password' => 'short', 'new_password_confirmation' => 'short']);
        self::assertSame(422, $weak->status());
        $mismatch = $this->request($admin, 'POST', "/admin/users/{$this->memberId}/password", ['_csrf' => $token, 'new_password' => 'a brand new passphrase', 'new_password_confirmation' => 'something else entirely']);
        self::assertSame(422, $mismatch->status());

        $ok = $this->request($admin, 'POST', "/admin/users/{$this->memberId}/password", ['_csrf' => $token, 'new_password' => 'a brand new passphrase', 'new_password_confirmation' => 'a brand new passphrase']);
        self::assertSame(303, $ok->status());
        self::assertSame(303, $this->request($member, 'GET', '/books')->status(), 'Old session ended');
        self::assertSame(422, $this->login($this->browser(), 'member@example.org')->status(), 'Old password rejected');
        self::assertSame(303, $this->login($this->browser(), 'member@example.org', 'a brand new passphrase')->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'user.password_reset' AND entity_id = {$this->memberId}"));
        self::assertStringNotContainsString('brand new', (string) $this->pdo()->query('SELECT GROUP_CONCAT(COALESCE(details, \'\')) FROM audit_log')->fetchColumn());
    }

    public function testRevokeSessionsAndSelfServiceRefusals(): void
    {
        [$member] = $this->signedIn('member@example.org');
        [$admin, $token] = $this->signedIn('admin@example.org');

        self::assertSame(303, $this->request($admin, 'POST', "/admin/users/{$this->memberId}/sessions", ['_csrf' => $token])->status());
        self::assertSame(303, $this->request($member, 'GET', '/books')->status());
        self::assertSame(303, $this->login($this->browser(), 'member@example.org')->status(), 'Password unchanged');

        self::assertSame(409, $this->request($admin, 'POST', "/admin/users/{$this->adminId}/sessions", ['_csrf' => $token])->status());
        self::assertSame(409, $this->request($admin, 'POST', "/admin/users/{$this->adminId}/password", ['_csrf' => $token, 'new_password' => 'a brand new passphrase', 'new_password_confirmation' => 'a brand new passphrase'])->status());
        self::assertSame(200, $this->request($admin, 'GET', '/admin/users')->status(), 'Admin still signed in');
    }

    public function testEmailUniquenessAndValidation(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $taken = $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('ADMIN@example.org', 'M', 'member'));
        self::assertSame(422, $taken->status());
        self::assertStringContainsString('already exists', $taken->body());
        self::assertSame(422, $this->request($admin, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('member@example.org', 'M', 'superuser'))->status());
        self::assertSame(404, $this->request($admin, 'GET', '/admin/users/999999/edit')->status());
    }

    public function testNonAdminsCannotUseUserAdministration(): void
    {
        [$member, $token] = $this->signedIn('member@example.org');
        self::assertSame(403, $this->request($member, 'GET', "/admin/users/{$this->adminId}/edit")->status());
        self::assertSame(403, $this->request($member, 'POST', "/admin/users/{$this->memberId}", ['_csrf' => $token] + $this->profile('member@example.org', 'M', 'admin'))->status(), 'No self-promotion');
        self::assertSame(403, $this->request($member, 'POST', "/admin/users/{$this->adminId}/password", ['_csrf' => $token, 'new_password' => 'a brand new passphrase', 'new_password_confirmation' => 'a brand new passphrase'])->status());
        self::assertSame(403, $this->request($member, 'POST', "/admin/users/{$this->adminId}/sessions", ['_csrf' => $token])->status());
        self::assertSame('member', $this->pdo()->query("SELECT r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = {$this->memberId}")->fetchColumn());
    }
}
