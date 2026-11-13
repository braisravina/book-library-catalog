<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Household administration (phase H1): administrators only, behind the
 * development-only feature switch, audited, with safeguards on deletion
 * and membership.
 */
final class HouseholdAdminTest extends IntegrationTestCase
{
    private int $adminId;
    private int $memberId;
    private int $viewerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->memberId = $this->createUser('member@example.org', Role::Member);
        $this->viewerId = $this->createUser('viewer@example.org', Role::Viewer);
    }

    private function enable(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
    }

    /**
     * Every household route with a harmless request for it.
     *
     * @return list<array{string, string}>
     */
    private function routes(int $householdId): array
    {
        return [
            ['GET', '/admin/households'],
            ['GET', '/admin/households/new'],
            ['POST', '/admin/households'],
            ['GET', "/admin/households/{$householdId}/edit"],
            ['POST', "/admin/households/{$householdId}"],
            ['POST', "/admin/households/{$householdId}/delete"],
            ['POST', "/admin/households/{$householdId}/members"],
            ['POST', "/admin/households/{$householdId}/members/{$this->memberId}/remove"],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastAudit(string $action): ?array
    {
        $stmt = $this->pdo()->prepare('SELECT user_id, entity_type, entity_id, details FROM audit_log WHERE action = :a ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $action]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['details'] = $row['details'] === null ? [] : json_decode((string) $row['details'], true);

        return $row;
    }

    /**
     * @return list<int>
     */
    private function scopeOf(int $userId): array
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());

        return $services->householdScope($services->users()->findById($userId))->ids();
    }

    public function testEveryRouteIsNotFoundWhileTheFeatureIsDisabledEvenForAdministrators(): void
    {
        $household = $this->createHousehold('Casa');
        [$admin, $token] = $this->signedIn('admin@example.org');

        foreach ($this->routes($household) as [$method, $path]) {
            $response = $this->request($admin, $method, $path, $method === 'POST' ? ['_csrf' => $token, 'name' => 'Changed', 'user_id' => (string) $this->memberId, 'row_version' => '1'] : []);
            self::assertSame(404, $response->status(), "{$method} {$path}");
        }
        self::assertSame(['Casa'], $this->pdo()->query('SELECT name FROM households WHERE system_key IS NULL')->fetchAll(PDO::FETCH_COLUMN), 'Nothing was changed');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'household.%'"));
        self::assertStringNotContainsString('/admin/households', $this->request($admin, 'GET', '/books')->body(), 'No navigation entry');
    }

    public function testProductionNeverEnablesTheFeature(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $services = new Services(new Config(['APP_ENV' => $environment, 'HOUSEHOLDS_ENABLED' => 'true']), dirname(__DIR__, 2));
            self::assertFalse($services->householdsEnabled(), $environment);
        }
        $services = new Services(new Config(['HOUSEHOLDS_ENABLED' => 'true']), dirname(__DIR__, 2));
        self::assertFalse($services->householdsEnabled(), 'No APP_ENV means production');
        $services = new Services(new Config(['APP_ENV' => 'development']), dirname(__DIR__, 2));
        self::assertFalse($services->householdsEnabled(), 'Off unless switched on');
        $services = new Services(new Config(['APP_ENV' => 'development', 'HOUSEHOLDS_ENABLED' => 'true']), dirname(__DIR__, 2));
        self::assertTrue($services->householdsEnabled());
    }

    public function testOnlyAdministratorsReachHouseholdAdministration(): void
    {
        $this->enable();
        $household = $this->createHousehold('Casa', [$this->memberId, $this->viewerId]);

        foreach (['member@example.org', 'viewer@example.org'] as $email) {
            [$browser, $token] = $this->signedIn($email);
            foreach ($this->routes($household) as [$method, $path]) {
                $response = $this->request($browser, $method, $path, $method === 'POST' ? ['_csrf' => $token, 'name' => 'Mine now', 'user_id' => (string) $this->adminId, 'row_version' => '1'] : []);
                self::assertSame(403, $response->status(), "{$email} {$method} {$path}");
            }
            self::assertStringNotContainsString('/admin/households', $this->request($browser, 'GET', '/books')->body());
        }
        foreach ($this->routes($household) as [$method, $path]) {
            if ($method === 'GET') {
                $response = $this->request($this->browser(), 'GET', $path);
                self::assertSame(303, $response->status());
                self::assertSame('/login', $response->header('Location'));
            }
        }
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"));
        self::assertSame('Casa', $this->pdo()->query("SELECT name FROM households WHERE id = {$household}")->fetchColumn());
    }

    public function testStateChangingRoutesRequireTheCsrfToken(): void
    {
        $this->enable();
        $household = $this->createHousehold('Casa');
        [$admin] = $this->signedIn('admin@example.org');

        foreach ($this->routes($household) as [$method, $path]) {
            if ($method === 'POST') {
                self::assertSame(419, $this->request($admin, 'POST', $path, ['name' => 'X', 'user_id' => (string) $this->memberId])->status(), $path);
            }
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members'));
    }

    public function testAdministratorCreatesRenamesAndListsHouseholdsWithAudit(): void
    {
        $this->enable();
        [$admin, $token] = $this->signedIn('admin@example.org');
        self::assertStringContainsString('/admin/households', $this->request($admin, 'GET', '/books')->body());

        $index = $this->request($admin, 'GET', '/admin/households');
        self::assertSame(200, $index->status());
        self::assertStringContainsString('Default household', $index->body());
        self::assertStringContainsString('must not be used in production', $index->body(), 'The page says the feature is incomplete');

        $response = $this->request($admin, 'POST', '/admin/households', ['_csrf' => $token, 'name' => '  Casa <b>Ravina</b> ', 'description' => 'Main house']);
        self::assertSame(303, $response->status());
        $id = (int) $this->pdo()->query("SELECT id FROM households WHERE name = 'Casa <b>Ravina</b>'")->fetchColumn();
        self::assertSame("/admin/households/{$id}/edit", $response->header('Location'));
        self::assertNull($this->pdo()->query("SELECT system_key FROM households WHERE id = {$id}")->fetchColumn());
        $audit = $this->lastAudit('household.created');
        self::assertSame([$this->adminId, 'household', $id], [(int) $audit['user_id'], $audit['entity_type'], (int) $audit['entity_id']]);

        $edit = $this->request($admin, 'GET', "/admin/households/{$id}/edit");
        self::assertStringContainsString('Casa &lt;b&gt;Ravina&lt;/b&gt;', $edit->body(), 'Names are escaped');
        self::assertStringNotContainsString('<b>Ravina</b>', $edit->body());

        // Validation and uniqueness.
        self::assertSame(422, $this->request($admin, 'POST', '/admin/households', ['_csrf' => $token, 'name' => ''])->status());
        self::assertSame(422, $this->request($admin, 'POST', '/admin/households', ['_csrf' => $token, 'name' => str_repeat('x', 151)])->status());
        $duplicate = $this->request($admin, 'POST', '/admin/households', ['_csrf' => $token, 'name' => 'casa <B>ravina</B>']);
        self::assertSame(422, $duplicate->status());
        self::assertStringContainsString('Another household already has this name', $duplicate->body());
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM households'));

        // Rename with the optimistic lock.
        $response = $this->request($admin, 'POST', "/admin/households/{$id}", ['_csrf' => $token, 'name' => 'Casa', 'description' => '', 'row_version' => '1']);
        self::assertSame(303, $response->status());
        self::assertSame(['Casa', null, 2], $this->pdo()->query("SELECT name, description, row_version FROM households WHERE id = {$id}")->fetch(PDO::FETCH_NUM));
        $audit = $this->lastAudit('household.updated');
        self::assertEquals(['from' => 'Casa <b>Ravina</b>', 'to' => 'Casa'], $audit['details']['changes']['name']);

        $stale = $this->request($admin, 'POST', "/admin/households/{$id}", ['_csrf' => $token, 'name' => 'Overwritten', 'row_version' => '1']);
        self::assertSame(409, $stale->status());
        self::assertStringContainsString('changed by someone else', $stale->body());
        self::assertSame('Casa', $this->pdo()->query("SELECT name FROM households WHERE id = {$id}")->fetchColumn());

        $taken = $this->request($admin, 'POST', "/admin/households/{$id}", ['_csrf' => $token, 'name' => 'Default household', 'row_version' => '2']);
        self::assertSame(422, $taken->status());

        // Saving without a change records nothing.
        $events = $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.updated'");
        self::assertSame(303, $this->request($admin, 'POST', "/admin/households/{$id}", ['_csrf' => $token, 'name' => 'Casa', 'row_version' => '2'])->status());
        self::assertSame($events, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.updated'"));

        self::assertSame(404, $this->request($admin, 'GET', '/admin/households/999999/edit')->status());
    }

    public function testMembershipIsAssignedAuditedAndAppliesImmediately(): void
    {
        $this->enable();
        $household = $this->createHousehold('Casa');
        $inactiveId = $this->createUser('gone@example.org', Role::Member, self::PASSWORD, false);
        // Catalogued by the member, so the edition is in their view without any household.
        $book = $this->makeBook($this->memberId, 'A book');
        [$member] = $this->signedIn('member@example.org');
        [$admin, $token] = $this->signedIn('admin@example.org');

        self::assertSame([], $this->scopeOf($this->memberId), 'A new account belongs to no household');
        $form = $this->request($member, 'GET', "/editions/{$book['edition_id']}/copies/new")->body();
        self::assertStringNotContainsString('>Casa<', $form);

        $response = $this->request($admin, 'POST', "/admin/households/{$household}/members", ['_csrf' => $token, 'user_id' => (string) $this->memberId]);
        self::assertSame(303, $response->status());
        self::assertSame("/admin/households/{$household}/edit#members", $response->header('Location'));
        self::assertSame([$this->adminId], array_map('intval', $this->pdo()->query("SELECT added_by FROM household_members WHERE household_id = {$household} AND user_id = {$this->memberId}")->fetchAll(PDO::FETCH_COLUMN)));
        $audit = $this->lastAudit('household.member_added');
        self::assertSame(['member_user_id' => $this->memberId], $audit['details']);
        self::assertStringNotContainsString('member@example.org', json_encode($audit), 'No e-mail address or name in the audit trail');

        // The member's existing session sees the household on its very next request.
        self::assertSame([$household], $this->scopeOf($this->memberId));
        self::assertStringContainsString('>Casa<', $this->request($member, 'GET', "/editions/{$book['edition_id']}/copies/new")->body());

        $page = $this->request($admin, 'GET', "/admin/households/{$household}/edit")->body();
        self::assertStringContainsString('member@example.org', $page);
        self::assertStringNotContainsString('gone@example.org', $page, 'Deactivated accounts are not offered');

        // Refusals: twice, deactivated, unknown, malformed.
        foreach ([(string) $this->memberId => 'already a member', (string) $inactiveId => 'deactivated account cannot be added', '999999' => 'account does not exist', 'abc' => 'account does not exist'] as $userId => $message) {
            $response = $this->request($admin, 'POST', "/admin/households/{$household}/members", ['_csrf' => $token, 'user_id' => (string) $userId]);
            self::assertSame(303, $response->status());
            self::assertStringContainsString($message, $this->request($admin, 'GET', "/admin/households/{$household}/edit")->body(), (string) $userId);
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.member_added'"));
        self::assertSame(404, $this->request($admin, 'POST', '/admin/households/999999/members', ['_csrf' => $token, 'user_id' => (string) $this->memberId])->status());

        // Removal: immediate, audited, and the member's copies stay in the household.
        $owned = $this->makeBook($this->memberId, 'Owned', null, [], [], [], $this->memberId, null, $household);
        $response = $this->request($admin, 'POST', "/admin/households/{$household}/members/{$this->memberId}/remove", ['_csrf' => $token]);
        self::assertSame(303, $response->status());
        self::assertSame([], $this->scopeOf($this->memberId));
        self::assertStringNotContainsString('>Casa<', $this->request($member, 'GET', "/editions/{$book['edition_id']}/copies/new")->body());
        self::assertEquals(['member_user_id' => $this->memberId, 'owned_copies' => 1], $this->lastAudit('household.member_removed')['details']);
        self::assertSame($household, (int) $this->pdo()->query("SELECT household_id FROM copies WHERE id = {$owned['copy_id']}")->fetchColumn());

        $this->request($admin, 'POST', "/admin/households/{$household}/members/{$this->memberId}/remove", ['_csrf' => $token]);
        self::assertStringContainsString('not a member of the household', $this->request($admin, 'GET', "/admin/households/{$household}/edit")->body());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.member_removed'"));
    }

    public function testADeactivatedMemberReachesNoHousehold(): void
    {
        $household = $this->createHousehold('Casa', [$this->memberId]);
        self::assertSame([$household], $this->scopeOf($this->memberId));

        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->memberId}");
        self::assertSame([], $this->scopeOf($this->memberId));
    }

    public function testDeletionIsRefusedForTheDefaultAndForHouseholdsInUse(): void
    {
        $this->enable();
        [$admin, $token] = $this->signedIn('admin@example.org');
        $default = $this->defaultHouseholdId();

        $this->request($admin, 'POST', "/admin/households/{$default}/delete", ['_csrf' => $token]);
        self::assertStringContainsString('cannot be deleted', $this->request($admin, 'GET', "/admin/households/{$default}/edit")->body());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM households WHERE id = {$default}"));

        $withMembers = $this->createHousehold('With members', [$this->memberId]);
        $this->request($admin, 'POST', "/admin/households/{$withMembers}/delete", ['_csrf' => $token]);
        self::assertStringContainsString('still has members', $this->request($admin, 'GET', "/admin/households/{$withMembers}/edit")->body());

        $withCopies = $this->createHousehold('With copies');
        $this->makeBook($this->adminId, 'Held', null, [], [], [], $this->adminId, null, $withCopies);
        $this->request($admin, 'POST', "/admin/households/{$withCopies}/delete", ['_csrf' => $token]);
        self::assertStringContainsString('still has copies or locations', $this->request($admin, 'GET', "/admin/households/{$withCopies}/edit")->body());

        $withLocations = $this->createHousehold('With locations');
        $this->pdo()->exec("INSERT INTO locations (parent_id, household_id, name, created_at, updated_at) VALUES (NULL, {$withLocations}, 'Attic', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->request($admin, 'POST', "/admin/households/{$withLocations}/delete", ['_csrf' => $token]);
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM households'), 'Default and the three in use');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.deleted'"));

        $empty = $this->createHousehold('Empty');
        $response = $this->request($admin, 'POST', "/admin/households/{$empty}/delete", ['_csrf' => $token]);
        self::assertSame(303, $response->status());
        self::assertSame('/admin/households', $response->header('Location'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM households WHERE id = {$empty}"));
        $audit = $this->lastAudit('household.deleted');
        self::assertSame([$empty, 'Empty'], [(int) $audit['entity_id'], $audit['details']['name']]);
    }

    public function testNewAccountsGetNoHouseholdHoweverTheyAreCreated(): void
    {
        $this->enable();
        [$admin, $token] = $this->signedIn('admin@example.org');
        $response = $this->request($admin, 'POST', '/admin/users', ['_csrf' => $token, 'email' => 'new@example.org', 'display_name' => 'New', 'role' => 'member', 'locale' => 'en', 'password' => 'a long enough passphrase 42']);
        self::assertSame(303, $response->status());
        $newId = (int) $this->pdo()->query("SELECT id FROM users WHERE email = 'new@example.org'")->fetchColumn();

        self::assertGreaterThan(0, $newId);
        self::assertSame([], $this->scopeOf($newId));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members'));
    }

    public function testServiceRefusesNonAdministratorsWhateverTheRoute(): void
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $member = $services->users()->findById($this->memberId);
        $household = $this->createHousehold('Casa');

        $attempts = [
            fn () => $services->householdService()->create($member, new \DigitalLibrary\Households\HouseholdInput('Mine', '')),
            fn () => $services->householdService()->update($member, $household, new \DigitalLibrary\Households\HouseholdInput('Mine', ''), 1),
            fn () => $services->householdService()->delete($member, $household),
            fn () => $services->householdService()->addMember($member, $household, $this->memberId),
            fn () => $services->householdService()->removeMember($member, $household, $this->memberId),
        ];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('A member changed a household');
            } catch (\DigitalLibrary\Households\HouseholdException $e) {
                self::assertSame('households.error.forbidden', $e->getMessage());
            }
        }
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM households'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members'));
    }
}
