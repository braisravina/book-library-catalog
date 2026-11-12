<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;

/**
 * Role-based and record-level authorization on every catalogue operation,
 * plus CSRF coverage of every state-changing route.
 */
final class CataloguePermissionTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
    private int $viewerId;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $aliceBook;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->viewerId = $this->createUser('viewer@example.org', Role::Viewer);
        $this->aliceBook = $this->makeBook($this->aliceId, "Alice's book", '9780306406157', ['Some Author'], [], [], $this->aliceId);
    }

    public function testGuestsAreRedirectedFromEveryCatalogueRoute(): void
    {
        $e = $this->aliceBook['edition_id'];
        foreach (['/books', '/books/new', "/editions/{$e}", "/works/{$this->aliceBook['work_id']}", '/authors', '/publishers',
            "/covers/1", '/admin/categories', '/admin/locations', "/editions/{$e}/copies/new"] as $path) {
            $response = $this->request($this->browser(), 'GET', $path);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'), $path);
        }
    }

    public function testViewerIsReadOnly(): void
    {
        [$browser, $token] = $this->signedIn('viewer@example.org');
        $e = $this->aliceBook['edition_id'];
        $w = $this->aliceBook['work_id'];

        foreach (['/books', "/editions/{$e}", "/works/{$w}", '/authors', '/publishers'] as $path) {
            self::assertSame(200, $this->request($browser, 'GET', $path)->status(), $path);
        }
        foreach (['/books/new', "/editions/{$e}/edit", "/works/{$w}/edit", "/works/{$w}/editions/new", "/editions/{$e}/copies/new", "/copies/{$this->aliceBook['copy_id']}/edit", '/admin/categories', '/admin/locations'] as $path) {
            self::assertSame(403, $this->request($browser, 'GET', $path)->status(), $path);
        }
        foreach (['/books' => ['title' => 'X'], "/editions/{$e}/copies" => ['owner_user_id' => (string) $this->viewerId, 'status' => 'available'],
            "/editions/{$e}" => ['edition_title' => 'Hacked'], "/editions/{$e}/covers" => []] as $path => $body) {
            self::assertSame(403, $this->request($browser, 'POST', $path, ['_csrf' => $token] + $body)->status(), $path);
        }
        $page = $this->request($browser, 'GET', "/editions/{$e}")->body();
        self::assertStringNotContainsString('/copies/new', $page, 'No add-copy control for viewers');
        self::assertStringNotContainsString("/editions/{$e}/edit", $page);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM works WHERE title = 'X'"));
        self::assertSame("Alice's book", $this->pdo()->query("SELECT title FROM editions WHERE id = {$e}")->fetchColumn());
    }

    public function testMemberCannotEditAnotherMembersRecords(): void
    {
        [$browser, $token] = $this->signedIn('bob@example.org');
        $e = $this->aliceBook['edition_id'];
        $w = $this->aliceBook['work_id'];

        self::assertSame(403, $this->request($browser, 'GET', "/editions/{$e}/edit")->status());
        self::assertSame(403, $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'row_version' => $this->rowVersion('editions', (int) $e), 'edition_title' => 'Bob was here'])->status());
        self::assertSame(403, $this->request($browser, 'GET', "/works/{$w}/edit")->status());
        self::assertSame(403, $this->request($browser, 'POST', "/works/{$w}", ['_csrf' => $token, 'row_version' => $this->rowVersion('works', (int) $w), 'title' => 'Bob was here'])->status());
        self::assertSame(403, $this->request($browser, 'POST', "/editions/{$e}/covers", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM editions WHERE title = 'Bob was here'"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM works WHERE title = 'Bob was here'"));

        // ...but may add his own copy of Alice's edition and a new edition of her work.
        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->bobId, 'status' => 'available'])->status());
        self::assertSame(303, $this->request($browser, 'POST', "/works/{$w}/editions", ['_csrf' => $token, 'edition_title' => 'Bob edition'])->status());
    }

    public function testCopyObjectLevelAuthorizationPreventsIdor(): void
    {
        [$browser, $token] = $this->signedIn('bob@example.org');
        $aliceCopy = $this->aliceBook['copy_id'];
        $e = $this->aliceBook['edition_id'];

        self::assertSame(403, $this->request($browser, 'GET', "/copies/{$aliceCopy}/edit")->status());
        self::assertSame(403, $this->request($browser, 'POST', "/copies/{$aliceCopy}", ['_csrf' => $token, 'row_version' => $this->rowVersion('copies', (int) $aliceCopy), 'owner_user_id' => (string) $this->bobId, 'status' => 'lost'])->status());
        self::assertSame(403, $this->request($browser, 'POST', "/copies/{$aliceCopy}/delete", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE id = {$aliceCopy} AND owner_user_id = {$this->aliceId} AND status = 'available'"));

        // Creating a copy owned by someone else, or transferring one's own copy, needs copy.manage_any.
        self::assertSame(403, $this->request($browser, 'POST', "/editions/{$e}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->aliceId, 'status' => 'available'])->status());
        $this->request($browser, 'POST', "/editions/{$e}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->bobId, 'status' => 'available']);
        $bobCopy = $this->countRows("SELECT id FROM copies WHERE owner_user_id = {$this->bobId}");
        self::assertSame(403, $this->request($browser, 'POST', "/copies/{$bobCopy}", ['_csrf' => $token, 'row_version' => $this->rowVersion('copies', (int) $bobCopy), 'owner_user_id' => (string) $this->aliceId, 'status' => 'available'])->status());
        self::assertSame(303, $this->request($browser, 'POST', "/copies/{$bobCopy}", ['_csrf' => $token, 'row_version' => $this->rowVersion('copies', (int) $bobCopy), 'owner_user_id' => (string) $this->bobId, 'status' => 'lost'])->status());
        self::assertSame(303, $this->request($browser, 'POST', "/copies/{$bobCopy}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$this->bobId}"));
    }

    public function testMemberCanEditOwnRecordsButNotSharedEntitiesOrAdminAreas(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $e = $this->aliceBook['edition_id'];
        $authorId = $this->countRows('SELECT id FROM authors');
        $this->pdo()->exec("INSERT INTO publishers (name, created_at, updated_at) VALUES ('P', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $publisherId = (int) $this->pdo()->lastInsertId();

        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'row_version' => $this->rowVersion('editions', (int) $e), 'edition_title' => 'Renamed by Alice'])->status());
        self::assertSame(403, $this->request($browser, 'POST', "/editions/{$e}/delete", ['_csrf' => $token])->status(), 'Deletion is admin-only');
        self::assertSame(403, $this->request($browser, 'POST', "/authors/{$authorId}", ['_csrf' => $token, 'name' => 'X', 'sort_name' => 'X'])->status());
        self::assertSame(403, $this->request($browser, 'POST', "/publishers/{$publisherId}", ['_csrf' => $token, 'name' => 'X'])->status());
        self::assertSame(403, $this->request($browser, 'POST', '/admin/categories', ['_csrf' => $token, 'name' => 'X'])->status());
        self::assertSame(403, $this->request($browser, 'POST', '/admin/locations', ['_csrf' => $token, 'name' => 'X', 'kind' => 'room'])->status());
    }

    public function testAdminManagesAnythingAndDeletionRespectsIntegrity(): void
    {
        [$browser, $token] = $this->signedIn('admin@example.org');
        $e = $this->aliceBook['edition_id'];

        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'row_version' => $this->rowVersion('editions', (int) $e), 'edition_title' => 'Admin edit'])->status());
        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->bobId, 'status' => 'available'])->status(), 'Admins choose any owner');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$this->bobId}"));

        $refused = $this->request($browser, 'POST', "/editions/{$e}/delete", ['_csrf' => $token]);
        self::assertSame(303, $refused->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM editions WHERE id = {$e}"), 'Editions with copies are not deleted');

        $this->pdo()->exec("DELETE FROM copies WHERE edition_id = {$e}");
        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM editions WHERE id = {$e}"));
        self::assertSame(303, $this->request($browser, 'POST', "/works/{$this->aliceBook['work_id']}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM works'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'edition.deleted'"));
    }

    public function testInactiveOrUnknownOwnersCannotBeAssigned(): void
    {
        $inactive = $this->createUser('gone@example.org', Role::Member, active: false);
        [$browser, $token] = $this->signedIn('admin@example.org');
        $e = $this->aliceBook['edition_id'];
        foreach ([(string) $inactive, '999999', 'abc'] as $owner) {
            self::assertSame(422, $this->request($browser, 'POST', "/editions/{$e}/copies", ['_csrf' => $token, 'owner_user_id' => $owner, 'status' => 'available'])->status(), $owner);
        }
    }

    public function testEveryStateChangingRouteRequiresCsrf(): void
    {
        [$browser] = $this->signedIn('admin@example.org');
        $router = (new \DigitalLibrary\Core\Application(new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2))))->router();
        $routes = (new \ReflectionProperty($router, 'routes'))->getValue($router);
        $checked = 0;
        foreach ($routes as $route) {
            if ($route->method !== 'POST' || in_array($route->pattern, ['/login'], true)) {
                continue;
            }
            $path = (string) preg_replace('/\{\w+(?::[^}]+)?\}/', '1', $route->pattern);
            self::assertSame(419, $this->request($browser, 'POST', $path, ['title' => 'x'])->status(), $route->pattern);
            $checked++;
        }
        self::assertGreaterThanOrEqual(23, $checked, 'All POST routes except /login were exercised');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM works'), 'No CSRF-less request changed anything');
    }

    public function testAuditTrailRecordsCatalogueChanges(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $this->request($browser, 'POST', "/editions/{$this->aliceBook['edition_id']}", ['_csrf' => $token, 'row_version' => $this->rowVersion('editions', (int) $this->aliceBook['edition_id']), 'edition_title' => 'Audited']);
        $row = $this->pdo()->query("SELECT user_id, entity_type, entity_id FROM audit_log WHERE action = 'edition.updated'")->fetch();
        self::assertSame([$this->aliceId, 'edition', $this->aliceBook['edition_id']], [(int) $row['user_id'], $row['entity_type'], (int) $row['entity_id']]);
    }
}
