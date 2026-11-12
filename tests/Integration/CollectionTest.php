<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;

/**
 * Personal and shared collections: lifecycle, items and ordering,
 * visibility (private vs shared), view-is-not-edit and IDOR protection.
 */
final class CollectionTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
    /** @var list<int> */
    private array $editions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);
        foreach (['Rayuela', 'Follas novas', 'Os eidos', 'Cantares gallegos'] as $title) {
            $this->editions[] = $this->makeBook($this->aliceId, $title)['edition_id'];
        }
    }

    public function testCollectionLifecycle(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        self::assertSame(200, $this->request($browser, 'GET', '/collections/new')->status());
        self::assertStringContainsString('value="private" checked', $this->request($browser, 'GET', '/collections/new')->body(), 'Private by default');

        $response = $this->create($browser, $token, ['name' => '  Galician   poetry ', 'description' => 'Favourites <i>ever</i>']);
        self::assertSame(303, $response->status());
        $id = $this->countRows('SELECT id FROM collections');
        self::assertSame("/collections/{$id}", $response->header('Location'));
        $row = $this->pdo()->query('SELECT name, visibility, owner_user_id FROM collections')->fetch();
        self::assertSame(['Galician poetry', 'private', $this->aliceId], [$row['name'], $row['visibility'], (int) $row['owner_user_id']]);

        $page = $this->request($browser, 'GET', "/collections/{$id}");
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Favourites &lt;i&gt;ever&lt;/i&gt;', $page->body());
        self::assertStringContainsString('Galician poetry', $this->request($browser, 'GET', '/collections')->body());

        // Duplicate names per owner are refused (case- and accent-insensitively); other owners may reuse them.
        self::assertSame(422, $this->create($browser, $token, ['name' => 'GALICIAN POETRY'])->status());
        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(303, $this->create($bob, $bobToken, ['name' => 'Galician poetry'])->status());

        // Edit.
        self::assertSame(200, $this->request($browser, 'GET', "/collections/{$id}/edit")->status());
        self::assertSame(303, $this->update($browser, $token, $id, ['name' => 'Poesía galega', 'visibility' => 'shared'])->status());
        self::assertSame('shared', (string) $this->pdo()->query("SELECT visibility FROM collections WHERE id = {$id}")->fetchColumn());
        self::assertSame(422, $this->update($browser, $token, $id, ['name' => ''])->status());
        self::assertSame(422, $this->update($browser, $token, $id, ['visibility' => 'public'])->status(), 'There is no public visibility');

        // Delete needs the confirmation box; catalogue records are kept.
        $this->addItem($browser, $token, $id, $this->editions[0]);
        $refused = $this->request($browser, 'POST', "/collections/{$id}/delete", ['_csrf' => $token]);
        self::assertSame("/collections/{$id}/edit", $refused->header('Location'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM collections WHERE id = {$id}"));
        self::assertSame(303, $this->request($browser, 'POST', "/collections/{$id}/delete", ['_csrf' => $token, 'confirm' => '1'])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM collections WHERE id = {$id}"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$id}"));
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM editions'));

        foreach (['collection.created', 'collection.updated', 'collection.item_added', 'collection.deleted'] as $action) {
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = '{$action}' AND entity_id = {$id}"), $action);
        }
    }

    public function testItemsCanBeAddedRemovedAndReordered(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $this->create($browser, $token, ['name' => 'Reading list']);
        $id = $this->countRows('SELECT id FROM collections');
        [$a, $b, $c, $d] = $this->editions;

        // Added from the edition page (collection chosen in a select).
        $edition = $this->request($browser, 'GET', "/editions/{$a}")->body();
        self::assertStringContainsString('action="/collections/add-item"', $edition);
        $response = $this->request($browser, 'POST', '/collections/add-item', ['_csrf' => $token, 'collection_id' => (string) $id, 'edition_id' => (string) $a]);
        self::assertSame("/editions/{$a}", $response->header('Location'));
        foreach ([$b, $c, $d] as $e) {
            self::assertSame(303, $this->addItem($browser, $token, $id, $e)->status());
        }
        self::assertSame([$a, $b, $c, $d], $this->order($id));
        self::assertStringContainsString('Reading list', $this->request($browser, 'GET', "/editions/{$a}")->body());

        // Adding twice is refused; unknown editions are 404.
        $this->addItem($browser, $token, $id, $a);
        self::assertSame(4, $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$id}"));
        self::assertSame(404, $this->addItem($browser, $token, $id, 999999)->status());

        // Reordering.
        $this->move($browser, $token, $id, $c, 'up');
        self::assertSame([$a, $c, $b, $d], $this->order($id));
        $this->move($browser, $token, $id, $d, 'top');
        self::assertSame([$d, $a, $c, $b], $this->order($id));
        $this->move($browser, $token, $id, $d, 'bottom');
        self::assertSame([$a, $c, $b, $d], $this->order($id));
        $this->move($browser, $token, $id, $a, 'up');
        self::assertSame([$a, $c, $b, $d], $this->order($id), 'Moving the first item up is a no-op');
        $this->move($browser, $token, $id, $a, 'sideways');
        self::assertSame([$a, $c, $b, $d], $this->order($id));
        self::assertSame(404, $this->move($browser, $token, $id, 999999, 'up')->status());

        // Removing leaves gaps that later moves renumber.
        self::assertSame(303, $this->request($browser, 'POST', "/collections/{$id}/items/{$c}/remove", ['_csrf' => $token])->status());
        self::assertSame(404, $this->request($browser, 'POST', "/collections/{$id}/items/{$c}/remove", ['_csrf' => $token])->status());
        $this->move($browser, $token, $id, $d, 'up');
        self::assertSame([$a, $d, $b], $this->order($id));
        self::assertSame([1, 2, 3], array_map('intval', $this->pdo()->query("SELECT position FROM collection_items WHERE collection_id = {$id} ORDER BY position")->fetchAll(\PDO::FETCH_COLUMN)));

        $page = $this->request($browser, 'GET', "/collections/{$id}")->body();
        self::assertLessThan(strpos($page, 'Cantares gallegos'), strpos($page, 'Rayuela'));
        self::assertStringContainsString('Move up', $page);

        // Deleting a catalogue edition removes it from collections.
        $this->pdo()->exec("DELETE FROM copies WHERE edition_id = {$b}");
        $this->pdo()->exec("DELETE FROM editions WHERE id = {$b}");
        self::assertSame([$a, $d], $this->order($id));
    }

    public function testPrivateCollectionsAreHiddenFromOtherMembersAndViewers(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $this->create($alice, $token, ['name' => 'Secret shelf', 'visibility' => 'private']);
        $private = $this->countRows("SELECT id FROM collections WHERE name = 'Secret shelf'");
        $this->addItem($alice, $token, $private, $this->editions[0]);

        foreach (['bob@example.org', 'viewer@example.org'] as $email) {
            [$browser] = $this->signedIn($email);
            self::assertSame(404, $this->request($browser, 'GET', "/collections/{$private}")->status(), $email);
            self::assertStringNotContainsString('Secret shelf', $this->request($browser, 'GET', '/collections?scope=shared')->body(), $email);
            self::assertStringNotContainsString('Secret shelf', $this->request($browser, 'GET', '/collections?scope=all')->body(), $email);
            self::assertStringNotContainsString('Secret shelf', $this->request($browser, 'GET', "/editions/{$this->editions[0]}")->body(), $email);
        }

        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(404, $this->request($bob, 'GET', "/collections/{$private}/edit")->status());
        self::assertSame(404, $this->update($bob, $bobToken, $private, ['name' => 'Mine now'])->status());
        self::assertSame(404, $this->request($bob, 'POST', "/collections/{$private}/delete", ['_csrf' => $bobToken, 'confirm' => '1'])->status());
        self::assertSame(404, $this->addItem($bob, $bobToken, $private, $this->editions[1])->status());
        self::assertSame(404, $this->request($bob, 'POST', '/collections/add-item', ['_csrf' => $bobToken, 'collection_id' => (string) $private, 'edition_id' => (string) $this->editions[1]])->status());
        self::assertSame(404, $this->request($bob, 'POST', "/collections/{$private}/items/{$this->editions[0]}/remove", ['_csrf' => $bobToken])->status());
        self::assertSame(404, $this->move($bob, $bobToken, $private, $this->editions[0], 'down')->status());
        self::assertSame(['Secret shelf', 1], [(string) $this->pdo()->query("SELECT name FROM collections WHERE id = {$private}")->fetchColumn(), $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$private}")]);

        // Administrators (collection.manage_any) can see and manage it.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertStringContainsString('Secret shelf', $this->request($admin, 'GET', '/collections?scope=all')->body());
        self::assertSame(200, $this->request($admin, 'GET', "/collections/{$private}")->status());
        self::assertSame(303, $this->update($admin, $adminToken, $private, ['name' => 'Secret shelf', 'description' => 'Tidied by admin'])->status());
        self::assertSame($this->aliceId, $this->countRows("SELECT owner_user_id FROM collections WHERE id = {$private}"), 'Ownership is unchanged');
        $audit = $this->pdo()->query("SELECT user_id, details FROM audit_log WHERE action = 'collection.updated'")->fetch();
        self::assertSame($this->adminId, (int) $audit['user_id']);
        self::assertEquals(['owner_user_id' => $this->aliceId, 'visibility' => 'private'], json_decode((string) $audit['details'], true));
    }

    public function testSharedCollectionsAreReadOnlyForOthers(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $this->create($alice, $token, ['name' => 'Club picks', 'visibility' => 'shared']);
        $shared = $this->countRows('SELECT id FROM collections');
        $this->addItem($alice, $token, $shared, $this->editions[2]);

        [$viewer, $viewerToken] = $this->signedIn('viewer@example.org');
        $index = $this->request($viewer, 'GET', '/collections');
        self::assertSame(200, $index->status());
        self::assertStringContainsString('Club picks', $index->body());
        self::assertStringNotContainsString('/collections/new', $index->body());
        $page = $this->request($viewer, 'GET', "/collections/{$shared}");
        self::assertSame(200, $page->status());
        self::assertStringContainsString('Os eidos', $page->body());
        self::assertStringNotContainsString('/move"', $page->body());
        self::assertStringNotContainsString("/collections/{$shared}/edit", $page->body());
        self::assertStringContainsString('Club picks', $this->request($viewer, 'GET', "/editions/{$this->editions[2]}")->body());
        self::assertSame(403, $this->request($viewer, 'GET', '/collections/new')->status());
        self::assertSame(403, $this->create($viewer, $viewerToken, ['name' => 'Viewer list'])->status());
        self::assertSame(403, $this->addItem($viewer, $viewerToken, $shared, $this->editions[0])->status());

        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(200, $this->request($bob, 'GET', "/collections/{$shared}")->status());
        self::assertSame(403, $this->request($bob, 'GET', "/collections/{$shared}/edit")->status());
        self::assertSame(403, $this->update($bob, $bobToken, $shared, ['name' => 'Bob picks'])->status());
        self::assertSame(403, $this->request($bob, 'POST', "/collections/{$shared}/delete", ['_csrf' => $bobToken, 'confirm' => '1'])->status());
        self::assertSame(403, $this->addItem($bob, $bobToken, $shared, $this->editions[0])->status());
        self::assertSame(403, $this->request($bob, 'POST', "/collections/{$shared}/items/{$this->editions[2]}/remove", ['_csrf' => $bobToken])->status());
        self::assertSame(403, $this->move($bob, $bobToken, $shared, $this->editions[2], 'down')->status());
        self::assertStringNotContainsString('action="/collections/add-item"', $this->request($bob, 'GET', "/editions/{$this->editions[2]}")->body(), 'Bob has no collections to add to');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$shared}"));

        // Unknown collections are 404; guests are sent to log in.
        self::assertSame(404, $this->request($bob, 'GET', '/collections/999999')->status());
        self::assertSame('/login', $this->request($this->browser(), 'GET', "/collections/{$shared}")->header('Location'));
        self::assertSame('/login', $this->request($this->browser(), 'GET', '/collections')->header('Location'));
    }

    /**
     * @param array<string, string> $fields
     */
    private function create(Session $browser, string $token, array $fields): Response
    {
        return $this->request($browser, 'POST', '/collections', $fields + ['_csrf' => $token, 'name' => 'Untitled', 'description' => '', 'visibility' => 'private']);
    }

    /**
     * @param array<string, string> $fields
     */
    private function update(Session $browser, string $token, int $id, array $fields): Response
    {
        $current = $this->pdo()->query("SELECT name, description, visibility FROM collections WHERE id = {$id}")->fetch();

        return $this->request($browser, 'POST', "/collections/{$id}", $fields + ['_csrf' => $token, 'name' => (string) $current['name'], 'description' => (string) $current['description'], 'visibility' => (string) $current['visibility']]);
    }

    private function addItem(Session $browser, string $token, int $id, int $editionId): Response
    {
        return $this->request($browser, 'POST', "/collections/{$id}/items", ['_csrf' => $token, 'edition_id' => (string) $editionId, 'return' => 'collection']);
    }

    private function move(Session $browser, string $token, int $id, int $editionId, string $direction): Response
    {
        return $this->request($browser, 'POST', "/collections/{$id}/items/{$editionId}/move", ['_csrf' => $token, 'direction' => $direction]);
    }

    /**
     * @return list<int>
     */
    private function order(int $id): array
    {
        return array_map('intval', $this->pdo()->query("SELECT edition_id FROM collection_items WHERE collection_id = {$id} ORDER BY position, edition_id")->fetchAll(\PDO::FETCH_COLUMN));
    }
}
