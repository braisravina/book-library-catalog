<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use PDO;

/**
 * With HOUSEHOLDS_ENABLED off (the default, and the only state production
 * allows) phase H3 changes nothing a user can observe: one library, one
 * location tree, inventory codes unique across it, shared collections
 * seen by everyone, any active account as a borrower, and creators
 * editing their own records. Household data that exists in the database
 * (memberships, other households) is ignored, and nothing a request
 * submits can switch a household rule on.
 */
final class HouseholdFeatureDisabledTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
    private int $viewerId;
    private int $other;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $aliceBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $bobBook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->viewerId = $this->createUser('viewer@example.org', Role::Viewer);
        // Household rows exist (the default one, and one more as if the feature had been tried):
        // Alice is a member of the default household only, Bob of the other one, the viewer of none.
        $this->addHouseholdMember($this->defaultHouseholdId(), $this->aliceId);
        $this->other = $this->createHousehold('Other household', [$this->bobId]);
        $this->aliceBook = $this->makeBook($this->aliceId, 'Alice book', '9788437604947', [], [], [], $this->aliceId);
        $this->bobBook = $this->makeBook($this->bobId, 'Bob book', '9780132350884', [], [], [], $this->bobId, null, $this->other);
    }

    public function testConfigurationKeepsTheFeatureOff(): void
    {
        $services = new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2));
        self::assertFalse($services->householdsEnabled());
        self::assertFalse($services->writeGuard()->enabled());
        // Production refuses it whatever the setting says, and refuses to start with it on.
        $production = self::config()->with('APP_ENV', 'production')->with('HOUSEHOLDS_ENABLED', 'true');
        self::assertFalse((new \DigitalLibrary\Core\Services($production, dirname(__DIR__, 2)))->householdsEnabled());
        $errors = array_column((new \DigitalLibrary\Core\ConfigValidator(dirname(__DIR__, 2)))->validate($production)['errors'], 0);
        self::assertContains('HOUSEHOLDS_ENABLED', $errors);
        foreach (['.env.example', '.env.production.example'] as $file) {
            self::assertMatchesRegularExpression('/^HOUSEHOLDS_ENABLED=false$/m', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file), $file);
        }
    }

    public function testCataloguingAndCopiesBehaveAsBeforeHouseholds(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $default = $this->defaultHouseholdId();

        // Everything is visible and a creator edits their own records, whoever else holds a copy.
        self::assertSame(200, $this->request($alice, 'GET', "/editions/{$this->bobBook['edition_id']}")->status());
        $this->makeCopy($this->aliceBook['edition_id'], $this->bobId, $this->other);
        $page = $this->request($alice, 'GET', "/editions/{$this->aliceBook['edition_id']}")->body();
        self::assertStringContainsString("/editions/{$this->aliceBook['edition_id']}/edit", $page);
        self::assertStringNotContainsString('part of the shared catalogue', $page);
        self::assertSame(303, $this->request($alice, 'POST', "/editions/{$this->aliceBook['edition_id']}", ['_csrf' => $token, 'edition_title' => 'Alice book, 2nd', 'isbn' => '9788437604947', 'row_version' => $this->rowVersion('editions', $this->aliceBook['edition_id'])])->status());
        self::assertSame(303, $this->request($alice, 'POST', "/works/{$this->aliceBook['work_id']}", ['_csrf' => $token, 'title' => 'Alice book, 2nd', 'row_version' => $this->rowVersion('works', $this->aliceBook['work_id'])])->status());
        self::assertSame(403, $this->request($alice, 'POST', "/editions/{$this->bobBook['edition_id']}", ['_csrf' => $token, 'edition_title' => 'Not hers', 'isbn' => '9780132350884', 'row_version' => '1'])->status(), 'Members still edit only what they created');

        // Inventory codes are unique across the whole library, whatever household a row carries.
        $this->pdo()->exec("UPDATE copies SET inventory_code = 'B-1' WHERE id = {$this->bobBook['copy_id']}");
        $add = fn (array $fields): Response => $this->request($alice, 'POST', "/editions/{$this->bobBook['edition_id']}/copies", $fields + ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available']);
        $taken = $add(['inventory_code' => 'B-1']);
        self::assertSame(422, $taken->status());
        self::assertStringContainsString('already in use', $taken->body());
        // A submitted household is ignored; the copy goes to the default household.
        self::assertSame(303, $add(['inventory_code' => 'A-1', 'household_id' => (string) $this->other])->status());
        self::assertSame((string) $default, (string) $this->pdo()->query("SELECT household_id FROM copies WHERE inventory_code = 'A-1'")->fetchColumn());
        self::assertSame(422, $this->request($alice, 'POST', "/copies/{$this->aliceBook['copy_id']}", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'inventory_code' => 'B-1', 'row_version' => $this->rowVersion('copies', (int) $this->aliceBook['copy_id'])])->status());
        $form = $this->request($alice, 'GET', "/editions/{$this->bobBook['edition_id']}/copies/new")->body();
        self::assertStringNotContainsString('name="household_id"', $form);
        self::assertStringNotContainsString('<optgroup', $form);
        self::assertStringNotContainsString('data-household-locations', $form);

        // A catalogued ISBN is offered as before: the record and "add another copy" by edition.
        $notice = $this->request($alice, 'GET', '/books/new?isbn=9780132350884')->body();
        self::assertStringContainsString("/editions/{$this->bobBook['edition_id']}/copies/new", $notice);
        self::assertStringNotContainsString('/books/copies/new', $notice);
        self::assertSame("/editions/{$this->bobBook['edition_id']}/copies/new", $this->request($alice, 'GET', '/books/copies/new?isbn=9780132350884')->header('Location'));

        // A member with no membership at all works as always.
        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        $this->pdo()->exec('DELETE FROM household_members');
        self::assertSame(303, $this->request($bob, 'POST', "/copies/{$this->bobBook['copy_id']}", ['_csrf' => $bobToken, 'owner_user_id' => (string) $this->bobId, 'status' => 'lost', 'inventory_code' => 'B-1', 'row_version' => $this->rowVersion('copies', (int) $this->bobBook['copy_id'])])->status());
        self::assertSame((string) $this->other, (string) $this->pdo()->query("SELECT household_id FROM copies WHERE id = {$this->bobBook['copy_id']}")->fetchColumn(), 'Editing keeps the household');
        self::assertSame(303, $this->request($bob, 'POST', '/books', ['_csrf' => $bobToken, 'title' => 'Brand new', 'confirm_new' => '1', 'add_copy' => '1'])->status());
    }

    public function testLendingCollectionsAndWishlistsBehaveAsBeforeHouseholds(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $copy = (int) $this->aliceBook['copy_id'];

        // Any active account can borrow, for the owner and for an administrator.
        $form = $this->request($alice, 'GET', "/copies/{$copy}/lend")->body();
        foreach (['>Bob<', '>Viewer<', '>Admin<'] as $name) {
            self::assertStringContainsString($name, $form);
        }
        $lend = fn (Session $browser, string $csrf, int $borrower): Response => $this->request($browser, 'POST', "/copies/{$copy}/lend", ['_csrf' => $csrf, 'borrower_type' => 'user', 'borrower_user_id' => (string) $borrower, 'loaned_on' => '2026-01-05']);
        self::assertSame(303, $lend($alice, $token, $this->bobId)->status());
        $loan = (int) $this->pdo()->query('SELECT id FROM loans')->fetchColumn();
        self::assertSame(303, $this->request($alice, 'POST', "/loans/{$loan}/return", ['_csrf' => $token])->status());
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $lend($admin, $adminToken, $this->viewerId)->status());

        // A shared collection is seen by every signed-in user and carries no household choice.
        self::assertStringNotContainsString('name="household_id"', $this->request($alice, 'GET', '/collections/new')->body());
        self::assertSame(303, $this->request($alice, 'POST', '/collections', ['_csrf' => $token, 'name' => 'For everyone', 'visibility' => 'shared', 'household_id' => (string) $this->other])->status());
        $shared = (int) $this->pdo()->query("SELECT id FROM collections WHERE name = 'For everyone'")->fetchColumn();
        self::assertSame((string) $this->defaultHouseholdId(), (string) $this->pdo()->query("SELECT household_id FROM collections WHERE id = {$shared}")->fetchColumn(), 'The submitted household is ignored');
        self::assertSame(303, $this->request($alice, 'POST', "/collections/{$shared}/items", ['_csrf' => $token, 'edition_id' => (string) $this->bobBook['edition_id']])->status());
        // A shared collection written by the previous version (no household) is just as visible.
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES ({$this->aliceId}, 'Older shared', 'shared', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $older = (int) $this->pdo()->lastInsertId();
        foreach (['bob@example.org', 'viewer@example.org'] as $email) {
            [$browser] = $this->signedIn($email);
            foreach ([$shared, $older] as $id) {
                self::assertSame(200, $this->request($browser, 'GET', "/collections/{$id}")->status(), "{$email} {$id}");
            }
            $list = $this->request($browser, 'GET', '/collections?scope=shared')->body();
            self::assertStringContainsString('For everyone', $list);
            self::assertStringContainsString('Older shared', $list);
            self::assertStringNotContainsString('Other household', $list);
        }
        self::assertStringContainsString("/collections/{$shared}", $this->request($alice, 'GET', "/editions/{$this->bobBook['edition_id']}")->body());
        // Its owner manages it without any membership; private stays private.
        $this->pdo()->exec('DELETE FROM household_members');
        self::assertSame(303, $this->request($alice, 'POST', "/collections/{$shared}/items/{$this->bobBook['edition_id']}/move", ['_csrf' => $token, 'direction' => 'top'])->status());
        self::assertSame(303, $this->request($alice, 'POST', "/collections/{$shared}", ['_csrf' => $token, 'name' => 'For everyone', 'visibility' => 'private'])->status());
        self::assertNull($this->pdo()->query("SELECT household_id FROM collections WHERE id = {$shared}")->fetchColumn());
        [$bob] = $this->signedIn('bob@example.org');
        self::assertSame(404, $this->request($bob, 'GET', "/collections/{$shared}")->status());

        // A wishlist entry is linked to whichever edition holds the ISBN.
        self::assertSame(303, $this->request($alice, 'POST', '/wishlist', ['_csrf' => $token, 'title' => 'Wanted', 'author' => '', 'isbn' => '9780132350884', 'priority' => 'normal', 'notes' => ''])->status());
        self::assertSame((string) $this->bobBook['edition_id'], (string) $this->pdo()->query('SELECT edition_id FROM wishlist_items')->fetchColumn());
    }

    public function testAdministrationBehavesAsBeforeHouseholds(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $default = $this->defaultHouseholdId();

        // One location tree: a root needs no household (it goes to the default one), and a
        // submitted household is ignored.
        $page = $this->request($admin, 'GET', '/admin/locations')->body();
        self::assertStringNotContainsString('name="household_id"', $page);
        self::assertStringNotContainsString('<h3>', $page);
        $post = fn (string $path, array $fields): Response => $this->request($admin, 'POST', $path, $fields + ['_csrf' => $token, 'kind' => 'room', 'description' => '']);
        self::assertSame(303, $post('/admin/locations', ['name' => 'House', 'household_id' => (string) $this->other])->status());
        $house = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'House'")->fetchColumn();
        self::assertSame((string) $default, (string) $this->pdo()->query("SELECT household_id FROM locations WHERE id = {$house}")->fetchColumn());
        self::assertSame(422, $post('/admin/locations', ['name' => 'House'])->status(), 'Root names are unique across the one tree');
        // A location carrying another household (or none) can be moved anywhere in the tree, as before.
        $this->pdo()->exec("INSERT INTO locations (parent_id, household_id, name, created_at, updated_at) VALUES (NULL, {$this->other}, 'Annex', UTC_TIMESTAMP(), UTC_TIMESTAMP()), (NULL, NULL, 'Legacy', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        foreach (['Annex', 'Legacy'] as $name) {
            $id = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = '{$name}'")->fetchColumn();
            self::assertSame(303, $post("/admin/locations/{$id}", ['name' => $name, 'parent_id' => (string) $house])->status(), $name);
            self::assertSame((string) $house, (string) $this->pdo()->query("SELECT parent_id FROM locations WHERE id = {$id}")->fetchColumn());
        }
        // A copy may use any location.
        $annex = (int) $this->pdo()->query("SELECT id FROM locations WHERE name = 'Annex'")->fetchColumn();
        self::assertSame(303, $this->request($admin, 'POST', "/copies/{$this->aliceBook['copy_id']}", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available', 'location_id' => (string) $annex, 'row_version' => $this->rowVersion('copies', (int) $this->aliceBook['copy_id'])])->status());

        // Accounts and invitations carry no households, whatever is submitted.
        self::assertStringNotContainsString('household_ids', $this->request($admin, 'GET', '/admin/users/new')->body());
        self::assertStringNotContainsString('household_ids', $this->request($admin, 'GET', '/admin/invitations/new')->body());
        $members = $this->countRows('SELECT COUNT(*) FROM household_members');
        self::assertSame(303, $this->request($admin, 'POST', '/admin/users', ['_csrf' => $token, 'email' => 'new@example.org', 'display_name' => 'New', 'role' => 'member', 'locale' => 'en', 'password' => self::PASSWORD, 'household_ids' => [(string) $this->other, 'x']])->status());
        self::assertSame(200, $this->request($admin, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => 'guest@example.org', 'role' => 'member', 'expires_on' => gmdate('Y-m-d', strtotime('+3 days')), 'household_ids' => [(string) $this->other]])->status());
        self::assertSame($members, $this->countRows('SELECT COUNT(*) FROM household_members'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitation_households'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'household.member_added'"));
        // The household pages do not exist.
        self::assertSame(404, $this->request($admin, 'GET', '/admin/households')->status());
        self::assertSame(404, $this->request($admin, 'POST', "/admin/households/{$this->other}/members", ['_csrf' => $token, 'user_id' => (string) $this->aliceId])->status());

        // The administrator export has the columns it always had.
        $csv = $this->request($admin, 'GET', '/admin/export/copies.csv')->body();
        self::assertStringNotContainsString('household', strtok($csv, "\n"));
        $json = json_decode($this->request($admin, 'GET', '/admin/export/catalogue.json')->body(), true);
        foreach (['copies', 'locations', 'collections'] as $dataset) {
            foreach ($json['data'][$dataset] as $row) {
                self::assertArrayNotHasKey('household_id', $row, $dataset);
            }
        }
    }

    /**
     * No household row is locked while the feature is off: a guarded
     * write does not even wait for a household row that someone else holds.
     */
    public function testPendingAssignmentsAreIgnoredAfterDisablingHouseholds(): void
    {
        $services = function (): \DigitalLibrary\Core\Services {
            $services = new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2));
            $services->set(PDO::class, $this->pdo());
            return $services;
        };
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $enabled = $services();
        $admin = $enabled->users()->findById($this->adminId);
        $expires = gmdate('Y-m-d', strtotime('+3 days'));
        $pending = $enabled->invitationService()->issue($admin, 'pending@example.org', Role::Member, $expires, [$this->other]);
        $replace = $enabled->invitationService()->issue($admin, 'replace@example.org', Role::Member, $expires, [$this->other]);
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM invitation_households'));
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'false';
        $disabled = $services()->invitationService();
        $result = $disabled->redeem(\DigitalLibrary\Invitations\InvitationToken::hash($pending['token']), 'pending@example.org', 'Pending', 'fixture hash', 'en');
        self::assertSame([], $result['household_ids']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members WHERE user_id = ' . $result['user_id']));
        $replacement = $disabled->replace($admin, $replace['id'], $expires);
        self::assertSame([], $replacement['household_ids']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitation_households WHERE invitation_id = ' . $replacement['id']));
    }

    public function testGuardedWritesTakeNoHouseholdLocks(): void
    {
        $other = ConnectionFactory::fromConfig(self::config());
        $other->beginTransaction();
        $other->query('SELECT id FROM households FOR UPDATE')->fetchAll();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            [$alice, $token] = $this->signedIn('alice@example.org');
            $response = $this->request($alice, 'POST', "/copies/{$this->aliceBook['copy_id']}", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'lost', 'row_version' => $this->rowVersion('copies', (int) $this->aliceBook['copy_id'])]);
            self::assertSame(303, $response->status());
            self::assertSame(303, $this->request($alice, 'POST', "/copies/{$this->aliceBook['copy_id']}/delete", ['_csrf' => $token])->status());
            self::assertSame(303, $this->request($alice, 'POST', '/wishlist', ['_csrf' => $token, 'title' => 'Wanted', 'author' => '', 'isbn' => '', 'priority' => 'normal', 'notes' => ''])->status());
        } finally {
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $other->rollBack();
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM copies WHERE id = {$this->aliceBook['copy_id']}"));
    }
}
