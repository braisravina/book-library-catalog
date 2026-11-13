<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use PDO;

/**
 * Which household a copy belongs to (phase H1). The household is decided
 * by Households\CopyHousehold: never taken from the request while the
 * feature is disabled, and an authorized, explicit choice when enabled.
 */
final class HouseholdCopyAssignmentTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
    private int $casa;
    private int $piso;
    private int $editionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->casa = $this->createHousehold('Casa', [$this->aliceId]);
        $this->piso = $this->createHousehold('Piso', [$this->bobId]);
        $this->editionId = $this->makeBook($this->adminId, 'Shared title', '9788437604947')['edition_id'];
    }

    private function enable(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
    }

    private function household(int $copyId): ?int
    {
        $value = $this->pdo()->query("SELECT household_id FROM copies WHERE id = {$copyId}")->fetchColumn();

        return $value === null ? null : (int) $value;
    }

    private function newestCopy(): int
    {
        return (int) $this->pdo()->query('SELECT MAX(id) FROM copies')->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditDetails(string $action): array
    {
        $stmt = $this->pdo()->prepare('SELECT details FROM audit_log WHERE action = :a ORDER BY id DESC LIMIT 1');
        $stmt->execute(['a' => $action]);

        return json_decode((string) $stmt->fetchColumn(), true);
    }

    private function location(string $name, ?int $householdId): int
    {
        $this->pdo()->prepare('INSERT INTO locations (parent_id, household_id, name, created_at, updated_at) VALUES (NULL, :h, :n, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['h' => $householdId, 'n' => $name]);

        return (int) $this->pdo()->lastInsertId();
    }

    public function testWhileDisabledCopiesGoToTheDefaultHouseholdAndTheRequestIsIgnored(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $default = $this->defaultHouseholdId();

        $form = $this->request($alice, 'GET', "/editions/{$this->editionId}/copies/new")->body();
        self::assertStringNotContainsString('name="household_id"', $form, 'No household choice is offered');

        // A forged household id is ignored, whether the user's own, another household's or nonsense.
        foreach ([(string) $this->casa, (string) $this->piso, '999999', 'x'] as $forged) {
            $response = $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'household_id' => $forged]);
            self::assertSame(303, $response->status(), $forged);
            self::assertSame($default, $this->household($this->newestCopy()), $forged);
        }
        self::assertEquals($default, $this->auditDetails('copy.created')['household_id']);

        // Add-a-book with a first copy.
        $response = $this->request($alice, 'POST', '/books', ['_csrf' => $token, 'title' => 'New book', 'add_copy' => '1', 'copy_household_id' => (string) $this->piso, 'confirm_new' => '1']);
        self::assertSame(303, $response->status());
        self::assertSame($default, $this->household($this->newestCopy()));
        self::assertStringNotContainsString('name="copy_household_id"', $this->request($alice, 'GET', '/books/new?manual=1')->body());

        // Editing keeps the household, including "none" for a copy written by the previous version.
        $copyId = $this->newestCopy();
        $legacy = $this->insertLegacyCopy($this->editionId, $this->aliceId);
        foreach ([$copyId => $default, $legacy => null] as $id => $expected) {
            $response = $this->request($alice, 'POST', "/copies/{$id}", ['_csrf' => $token, 'row_version' => $this->rowVersion('copies', $id), 'owner_user_id' => (string) $this->aliceId, 'status' => 'lost', 'household_id' => (string) $this->piso]);
            self::assertSame(303, $response->status());
            self::assertSame($expected, $this->household($id));
            self::assertSame('lost', $this->pdo()->query("SELECT status FROM copies WHERE id = {$id}")->fetchColumn());
        }
        self::assertArrayNotHasKey('household_changed', $this->auditDetails('copy.updated'));
    }

    public function testMemberWithOneHouseholdGetsItAndCannotUseAnother(): void
    {
        $this->enable();
        [$alice, $token] = $this->signedIn('alice@example.org');
        $payload = ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available'];

        $form = $this->request($alice, 'GET', "/editions/{$this->editionId}/copies/new")->body();
        self::assertStringContainsString('name="household_id"', $form);
        self::assertStringContainsString('>Casa<', $form);
        self::assertStringNotContainsString('>Piso<', $form, 'Other households are not listed');
        self::assertStringNotContainsString('Default household', $form);

        self::assertSame(303, $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload)->status());
        self::assertSame($this->casa, $this->household($this->newestCopy()), 'The only membership is used');
        self::assertSame(303, $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + ['household_id' => (string) $this->casa])->status());
        self::assertSame($this->casa, $this->household($this->newestCopy()));
        $copies = $this->countRows('SELECT COUNT(*) FROM copies');

        // Another household, the default one and a household that does not exist all give the same answer.
        $this->request($alice, 'GET', '/books'); // shows the pending "copy registered" messages
        $bodies = [];
        foreach ([(string) $this->piso, (string) $this->defaultHouseholdId(), '999999', '0', '-1', '1 OR 1=1'] as $forged) {
            $response = $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + ['household_id' => $forged]);
            self::assertSame(422, $response->status(), $forged);
            $bodies[$forged] = $response->body();
        }
        self::assertSame($copies, $this->countRows('SELECT COUNT(*) FROM copies'), 'Nothing was created');
        self::assertCount(1, array_unique(array_slice($bodies, 0, 3)), 'Byte-identical answers for "not yours", "the default household" and "does not exist"');
    }

    public function testMemberWithSeveralHouseholdsMustChooseAndWithNoneCannotAddCopies(): void
    {
        $this->enable();
        $this->addHouseholdMember($this->piso, $this->aliceId);
        [$alice, $token] = $this->signedIn('alice@example.org');
        $payload = ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available'];

        $response = $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Choose the household this copy belongs to', $response->body());
        self::assertSame(303, $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + ['household_id' => (string) $this->piso])->status());
        self::assertSame($this->piso, $this->household($this->newestCopy()));

        // Removed from every household: the next request can no longer add a copy anywhere.
        $this->pdo()->exec("DELETE FROM household_members WHERE user_id = {$this->aliceId}");
        foreach ([[], ['household_id' => (string) $this->piso], ['household_id' => (string) $this->casa]] as $extra) {
            self::assertSame(422, $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + $extra)->status());
        }
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM copies'));
    }

    public function testAdministratorAssignsAnyHouseholdButTheOwnerMustReachIt(): void
    {
        $this->enable();
        [$admin, $token] = $this->signedIn('admin@example.org');

        $form = $this->request($admin, 'GET', "/editions/{$this->editionId}/copies/new")->body();
        foreach (['>Casa<', '>Piso<', '>Default household<'] as $option) {
            self::assertStringContainsString($option, $form);
        }
        $response = $this->request($admin, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->adminId, 'status' => 'available']);
        self::assertSame(422, $response->status(), 'Administrators always choose explicitly');

        $response = $this->request($admin, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'household_id' => (string) $this->piso]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('The owner is not a member of this household', $response->body());

        self::assertSame(303, $this->request($admin, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'household_id' => (string) $this->casa])->status());
        self::assertSame($this->casa, $this->household($this->newestCopy()));
        self::assertSame(303, $this->request($admin, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->adminId, 'status' => 'available', 'household_id' => (string) $this->piso])->status(), 'An administrator owner reaches every household');
        self::assertSame($this->piso, $this->household($this->newestCopy()));
        self::assertEquals(['edition_id' => $this->editionId, 'owner_user_id' => $this->adminId, 'household_id' => $this->piso], $this->auditDetails('copy.created'));
        self::assertSame(422, $this->request($admin, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->adminId, 'status' => 'available', 'household_id' => '999999'])->status());
    }

    public function testLocationMustBelongToTheCopysHousehold(): void
    {
        $this->enable();
        $casaShelf = $this->location('Casa shelf', $this->casa);
        $pisoShelf = $this->location('Piso shelf', $this->piso);
        [$alice, $token] = $this->signedIn('alice@example.org');
        $payload = ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'household_id' => (string) $this->casa];

        $response = $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + ['location_id' => (string) $pisoShelf]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('This location belongs to another household', $response->body());
        self::assertSame(303, $this->request($alice, 'POST', "/editions/{$this->editionId}/copies", $payload + ['location_id' => (string) $casaShelf])->status());
        self::assertSame([$this->casa, $casaShelf], array_map('intval', $this->pdo()->query('SELECT household_id, location_id FROM copies ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_NUM)));

        // The first copy of a new book follows the same rules.
        $book = ['_csrf' => $token, 'title' => 'Another book', 'add_copy' => '1', 'confirm_new' => '1'];
        self::assertSame(422, $this->request($alice, 'POST', '/books', $book + ['copy_household_id' => (string) $this->piso])->status());
        self::assertSame(422, $this->request($alice, 'POST', '/books', $book + ['copy_household_id' => (string) $this->casa, 'copy_location_id' => (string) $pisoShelf])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM works WHERE title = 'Shared title'") + $this->countRows("SELECT COUNT(*) FROM works WHERE title = 'Another book'"), 'Nothing was created by the refused requests');
        self::assertSame(303, $this->request($alice, 'POST', '/books', $book + ['copy_location_id' => (string) $casaShelf])->status());
        self::assertSame($this->casa, $this->household($this->newestCopy()));
        self::assertStringContainsString('name="copy_household_id"', $this->request($alice, 'GET', '/books/new?manual=1')->body());
    }

    public function testMovingACopyBetweenHouseholdsIsAuthorizedAndAudited(): void
    {
        $this->enable();
        $this->addHouseholdMember($this->piso, $this->aliceId);
        $attic = $this->createHousehold('Attic');
        $copyId = (int) $this->makeBook($this->aliceId, 'Mine', null, [], [], [], $this->aliceId, null, $this->casa)['copy_id'];
        [$alice, $token] = $this->signedIn('alice@example.org');
        $update = fn (array $extra): \DigitalLibrary\Http\Response => $this->request($alice, 'POST', "/copies/{$copyId}", ['_csrf' => $token, 'row_version' => $this->rowVersion('copies', $copyId), 'owner_user_id' => (string) $this->aliceId, 'status' => 'available'] + $extra);

        // Not naming a household, or naming the present one, changes nothing.
        self::assertSame(303, $update([])->status());
        self::assertSame(303, $update(['household_id' => (string) $this->casa])->status());
        self::assertSame($this->casa, $this->household($copyId));
        self::assertArrayNotHasKey('household_changed', $this->auditDetails('copy.updated'));

        // To another of the owner's households: allowed and audited.
        self::assertSame(303, $update(['household_id' => (string) $this->piso])->status());
        self::assertSame($this->piso, $this->household($copyId));
        self::assertEquals(['from' => $this->casa, 'to' => $this->piso], $this->auditDetails('copy.updated')['household_changed']);

        // To a household the owner does not belong to: refused, nothing changes.
        foreach ([(string) $attic, (string) $this->defaultHouseholdId(), '999999'] as $forged) {
            self::assertSame(422, $update(['household_id' => $forged])->status(), $forged);
            self::assertSame($this->piso, $this->household($copyId));
        }

        // Membership gives no right to edit someone else's copy (owner-only, unchanged).
        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(403, $this->request($bob, 'GET', "/copies/{$copyId}/edit")->status());
        self::assertSame(403, $this->request($bob, 'POST', "/copies/{$copyId}", ['_csrf' => $bobToken, 'row_version' => $this->rowVersion('copies', $copyId), 'owner_user_id' => (string) $this->bobId, 'status' => 'lost', 'household_id' => (string) $this->piso])->status());
        self::assertSame(403, $this->request($bob, 'POST', "/copies/{$copyId}/delete", ['_csrf' => $bobToken])->status());

        // The owner keeps editing their copy after leaving the household, but cannot move it back in.
        $this->pdo()->exec("DELETE FROM household_members WHERE user_id = {$this->aliceId} AND household_id = {$this->piso}");
        self::assertSame(303, $update(['status' => 'lost'])->status());
        self::assertSame($this->piso, $this->household($copyId));
        self::assertSame(303, $update(['household_id' => (string) $this->casa])->status());
        self::assertSame(422, $update(['household_id' => (string) $this->piso])->status());
        self::assertSame($this->casa, $this->household($copyId));

        // Administrators move any copy, as long as the owner can reach the household.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        $adminUpdate = fn (int $household): \DigitalLibrary\Http\Response => $this->request($admin, 'POST', "/copies/{$copyId}", ['_csrf' => $adminToken, 'row_version' => $this->rowVersion('copies', $copyId), 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'household_id' => (string) $household]);
        self::assertSame(422, $adminUpdate($attic)->status());
        $this->addHouseholdMember($attic, $this->aliceId);
        self::assertSame(303, $adminUpdate($attic)->status());
        self::assertSame($attic, $this->household($copyId));
    }

    public function testRepositoryUpdateLeavesTheHouseholdUnlessItIsNamed(): void
    {
        $copyId = (int) $this->makeBook($this->aliceId, 'Mine', null, [], [], [], $this->aliceId, null, $this->casa)['copy_id'];
        $copies = new \DigitalLibrary\Catalogue\Repository\CopyRepository($this->pdo());
        $record = ['owner_user_id' => $this->aliceId, 'status' => 'lost'];

        self::assertTrue($copies->update($copyId, $record, $this->aliceId, 1));
        self::assertSame($this->casa, $this->household($copyId), 'An update that does not carry household_id keeps the household');
        self::assertTrue($copies->update($copyId, $record + ['household_id' => $this->piso], $this->aliceId, 2));
        self::assertSame($this->piso, $this->household($copyId));
        self::assertFalse($copies->update($copyId, $record + ['household_id' => $this->casa], $this->aliceId, 2), 'Stale version');
        self::assertSame($this->piso, $this->household($copyId));
        self::assertSame('Piso', $copies->find($copyId)['household_name']);
    }

    public function testViewersCannotAddCopiesToTheirHousehold(): void
    {
        $this->enable();
        $viewerId = $this->createUser('viewer@example.org', Role::Viewer);
        $this->addHouseholdMember($this->casa, $viewerId);
        [$viewer, $token] = $this->signedIn('viewer@example.org');

        self::assertSame(403, $this->request($viewer, 'GET', "/editions/{$this->editionId}/copies/new")->status());
        self::assertSame(403, $this->request($viewer, 'POST', "/editions/{$this->editionId}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $viewerId, 'status' => 'available', 'household_id' => (string) $this->casa])->status());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM copies'));
    }

    public function testNewLocationsInheritTheParentsHousehold(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $default = $this->defaultHouseholdId();
        $casaRoot = $this->location('Casa root', $this->casa);

        self::assertSame(303, $this->request($admin, 'POST', '/admin/locations', ['_csrf' => $token, 'name' => 'New root', 'kind' => 'site'])->status());
        self::assertSame(303, $this->request($admin, 'POST', '/admin/locations', ['_csrf' => $token, 'name' => 'Casa shelf', 'kind' => 'shelf', 'parent_id' => (string) $casaRoot])->status());

        self::assertSame($default, (int) $this->pdo()->query("SELECT household_id FROM locations WHERE name = 'New root'")->fetchColumn());
        self::assertSame($this->casa, (int) $this->pdo()->query("SELECT household_id FROM locations WHERE name = 'Casa shelf'")->fetchColumn());
    }
}
