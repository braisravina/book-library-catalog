<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use PDOException;

/**
 * Private wishlists: entries from the catalogue or entered manually, ISBN
 * normalisation, duplicate prevention, status history, the acquisition
 * hand-off to the add-book flow and strict per-user isolation.
 */
final class WishlistTest extends IntegrationTestCase
{
    private int $aliceId;
    private int $bobId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);
    }

    public function testManualEntryIsCreatedEditedAndListed(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        self::assertSame(200, $this->request($browser, 'GET', '/wishlist/new')->status());
        $response = $this->add($browser, $token, ['title' => '  O   lapis  do carpinteiro ', 'author' => 'Manuel Rivas', 'isbn' => '84-376-0494-X', 'priority' => 'high', 'notes' => 'Hardback <b>please</b>']);
        self::assertSame(303, $response->status());
        $row = $this->pdo()->query('SELECT * FROM wishlist_items')->fetch();
        self::assertSame(['O lapis do carpinteiro', 'Manuel Rivas', '9788437604947', 'high', 'wanted', $this->aliceId],
            [$row['title'], $row['author'], $row['isbn_13'], $row['priority'], $row['status'], (int) $row['user_id']]);
        self::assertNull($row['edition_id'], 'No catalogue edition has this ISBN');
        self::assertSame('/wishlist#item-' . $row['id'], $response->header('Location'));

        $list = $this->request($browser, 'GET', '/wishlist')->body();
        self::assertStringContainsString('O lapis do carpinteiro', $list);
        self::assertStringContainsString('Hardback &lt;b&gt;please&lt;/b&gt;', $list);
        self::assertStringNotContainsString('<b>please</b>', $list);

        self::assertSame(200, $this->request($browser, 'GET', "/wishlist/{$row['id']}/edit")->status());
        self::assertSame(303, $this->request($browser, 'POST', "/wishlist/{$row['id']}", ['_csrf' => $token, 'title' => 'O lapis do carpinteiro', 'author' => 'Manuel Rivas', 'isbn' => '', 'priority' => 'low', 'notes' => ''])->status());
        $row = $this->pdo()->query('SELECT * FROM wishlist_items')->fetch();
        self::assertSame(['low', null, null], [$row['priority'], $row['isbn_13'], $row['notes']]);
    }

    public function testEntryFromTheCatalogueIsLinkedAndShownOnTheEditionPage(): void
    {
        $book = $this->makeBook($this->bobId, 'Rayuela', '9788437604947', ['Julio Cortázar'], [], [], $this->bobId);
        [$browser, $token] = $this->signedIn('alice@example.org');
        $edition = $this->request($browser, 'GET', "/editions/{$book['edition_id']}")->body();
        self::assertStringContainsString("/wishlist/new?edition={$book['edition_id']}", $edition);

        $form = $this->request($browser, 'GET', "/wishlist/new?edition={$book['edition_id']}");
        self::assertSame(200, $form->status());
        self::assertStringContainsString('value="Rayuela"', $form->body());
        self::assertStringContainsString('value="Julio Cortázar"', $form->body());
        self::assertStringContainsString('value="9788437604947"', $form->body());

        self::assertSame(303, $this->add($browser, $token, ['edition_id' => (string) $book['edition_id'], 'title' => 'Rayuela', 'author' => 'Julio Cortázar', 'isbn' => '9788437604947'])->status());
        $row = $this->pdo()->query('SELECT edition_id, work_id FROM wishlist_items')->fetch();
        self::assertSame([$book['edition_id'], $book['work_id']], [(int) $row['edition_id'], (int) $row['work_id']]);
        self::assertStringContainsString('On my wishlist', $this->request($browser, 'GET', "/editions/{$book['edition_id']}")->body());

        // A manual entry whose ISBN is catalogued is linked automatically.
        $other = $this->makeBook($this->bobId, 'Cien años de soledad', '9780307474728');
        self::assertSame(303, $this->add($browser, $token, ['title' => 'Cien años', 'isbn' => '0307474720'])->status());
        self::assertSame($other['edition_id'], $this->countRows("SELECT edition_id FROM wishlist_items WHERE title = 'Cien años'"));

        // Unknown or malformed editions are 404.
        self::assertSame(404, $this->request($browser, 'GET', '/wishlist/new?edition=999999')->status());
        self::assertSame(404, $this->request($browser, 'GET', '/wishlist/new?edition=abc')->status());
        self::assertSame(404, $this->add($browser, $token, ['edition_id' => '999999'])->status());
    }

    public function testDuplicatesAreRefused(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        self::assertSame(303, $this->add($browser, $token, ['title' => 'Rayuela', 'isbn' => '9788437604947'])->status());

        // Same ISBN in another notation, with a different title.
        $response = $this->add($browser, $token, ['title' => 'Hopscotch', 'isbn' => '843760494X']);
        self::assertSame(409, $response->status());
        self::assertStringContainsString('This book is already on your wishlist.', $response->body());
        self::assertStringContainsString('/wishlist/1/edit', $response->body());

        // Manual entries without ISBN: same title and author, ignoring case and accents.
        self::assertSame(303, $this->add($browser, $token, ['title' => 'Follas novas', 'author' => 'Rosalía de Castro'])->status());
        self::assertSame(409, $this->add($browser, $token, ['title' => 'FOLLAS NOVAS', 'author' => 'rosalia de castro'])->status());
        self::assertSame(303, $this->add($browser, $token, ['title' => 'Follas novas', 'author' => 'Another author'])->status());

        // Editing an entry into a duplicate is refused too.
        $id = $this->countRows("SELECT id FROM wishlist_items WHERE author = 'Another author'");
        self::assertSame(409, $this->request($browser, 'POST', "/wishlist/{$id}", ['_csrf' => $token, 'title' => 'X', 'author' => '', 'isbn' => '978-84-376-0494-7', 'priority' => 'normal', 'notes' => ''])->status());

        // Another user may want the same book.
        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(303, $this->add($bob, $bobToken, ['title' => 'Rayuela', 'isbn' => '9788437604947'])->status());
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM wishlist_items'));
    }

    public function testDatabaseRejectsTwoWantedEntriesWithTheSameIsbn(): void
    {
        $insert = fn (string $status) => $this->pdo()->exec(
            "INSERT INTO wishlist_items (user_id, title, isbn_13, status, created_at, updated_at)
             VALUES ({$this->aliceId}, 'T', '9788437604947', '{$status}', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $insert('archived');
        $insert('acquired');
        $insert('wanted');
        try {
            $insert('wanted');
            self::fail('Duplicate wanted ISBN accepted');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        $this->expectException(PDOException::class);
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, title, isbn_13, created_at, updated_at) VALUES ({$this->aliceId}, 'Bad', '12345', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    }

    public function testStatusChangesPreserveHistoryAndHandOffToTheAddBookFlow(): void
    {
        $book = $this->makeBook($this->bobId, 'Rayuela', '9788437604947');
        [$browser, $token] = $this->signedIn('alice@example.org');
        $this->add($browser, $token, ['edition_id' => (string) $book['edition_id'], 'title' => 'Rayuela', 'isbn' => '9788437604947']);
        $this->add($browser, $token, ['title' => 'Manual only']);
        $this->add($browser, $token, ['title' => 'With ISBN', 'isbn' => '9780306406157']);
        $ids = $this->pdo()->query('SELECT title, id FROM wishlist_items')->fetchAll(\PDO::FETCH_KEY_PAIR);

        foreach ($ids as $id) {
            self::assertSame(303, $this->setStatus($browser, $token, (int) $id, 'acquired')->status());
        }
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM wishlist_items WHERE status = 'acquired' AND status_changed_at IS NOT NULL"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$this->aliceId}"), 'No copy is created automatically');

        $acquired = $this->request($browser, 'GET', '/wishlist?status=acquired')->body();
        self::assertStringContainsString("/editions/{$book['edition_id']}/copies/new", $acquired);
        self::assertStringContainsString('/books/new?isbn=9780306406157', $acquired);
        self::assertStringContainsString('/books/new?manual=1', $acquired);
        self::assertStringNotContainsString('Rayuela', $this->request($browser, 'GET', '/wishlist')->body(), 'Acquired entries leave the wanted list');

        // Archive and restore; restoring is refused if it would duplicate a wanted entry.
        self::assertSame(303, $this->setStatus($browser, $token, (int) $ids['Rayuela'], 'archived')->status());
        $this->add($browser, $token, ['title' => 'Rayuela again', 'isbn' => '9788437604947']);
        $restore = $this->setStatus($browser, $token, (int) $ids['Rayuela'], 'wanted');
        self::assertSame(303, $restore->status());
        self::assertSame('archived', (string) $this->pdo()->query("SELECT status FROM wishlist_items WHERE id = {$ids['Rayuela']}")->fetchColumn());
        self::assertSame(303, $this->setStatus($browser, $token, (int) $ids['Manual only'], 'wanted')->status());
        self::assertSame('wanted', (string) $this->pdo()->query("SELECT status FROM wishlist_items WHERE id = {$ids['Manual only']}")->fetchColumn());

        // Invalid status values change nothing.
        $this->setStatus($browser, $token, (int) $ids['Manual only'], 'deleted');
        self::assertSame('wanted', (string) $this->pdo()->query("SELECT status FROM wishlist_items WHERE id = {$ids['Manual only']}")->fetchColumn());
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM wishlist_items'), 'Entries are never deleted');
        $all = $this->request($browser, 'GET', '/wishlist?status=all')->body();
        foreach (['Rayuela', 'Manual only', 'With ISBN', 'Rayuela again'] as $title) {
            self::assertStringContainsString($title, $all);
        }
    }

    public function testInvalidInputIsRejected(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $cases = [
            'missing title' => ['title' => '  '],
            'long title' => ['title' => str_repeat('t', 501)],
            'long author' => ['author' => str_repeat('a', 256)],
            'bad isbn' => ['isbn' => '9788437604940'],
            'bad priority' => ['priority' => 'urgent'],
            'control characters' => ['title' => "Bad\x01title"],
            'long notes' => ['notes' => str_repeat('n', 1001)],
        ];
        foreach ($cases as $label => $override) {
            self::assertSame(422, $this->add($browser, $token, $override)->status(), $label);
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM wishlist_items'));
    }

    public function testWishlistsArePrivateToTheirOwner(): void
    {
        [$alice, $aliceToken] = $this->signedIn('alice@example.org');
        $this->add($alice, $aliceToken, ['title' => 'Secret wish', 'notes' => 'birthday idea']);
        $id = $this->countRows('SELECT id FROM wishlist_items');

        foreach (['bob@example.org', 'admin@example.org'] as $email) {
            [$other, $token] = $this->signedIn($email);
            self::assertStringNotContainsString('Secret wish', $this->request($other, 'GET', '/wishlist?status=all')->body(), $email);
            self::assertSame(404, $this->request($other, 'GET', "/wishlist/{$id}/edit")->status(), $email);
            self::assertSame(404, $this->request($other, 'POST', "/wishlist/{$id}", ['_csrf' => $token, 'title' => 'Hacked', 'priority' => 'normal'])->status(), $email);
            self::assertSame(404, $this->setStatus($other, $token, $id, 'archived')->status(), $email);
            self::assertSame(404, $this->request($other, 'GET', '/wishlist/999999/edit')->status(), $email);
        }
        self::assertSame(['Secret wish', 'wanted'], array_values($this->pdo()->query("SELECT title, status FROM wishlist_items WHERE id = {$id}")->fetch(\PDO::FETCH_NUM)));

        // Viewers have no wishlist; guests are sent to the login page.
        [$viewer, $viewerToken] = $this->signedIn('viewer@example.org');
        self::assertSame(403, $this->request($viewer, 'GET', '/wishlist')->status());
        self::assertSame(403, $this->add($viewer, $viewerToken, ['title' => 'Viewer wish'])->status());
        self::assertStringNotContainsString('/wishlist', $this->request($viewer, 'GET', '/')->body());
        $guest = $this->request($this->browser(), 'GET', '/wishlist');
        self::assertSame('/login', $guest->header('Location'));
    }

    public function testWishlistChangesAreNotAudited(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $this->add($browser, $token, ['title' => 'Private title']);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'wishlist.%' OR details LIKE '%Private title%'"));
    }

    /**
     * @param array<string, string> $fields
     */
    private function add(Session $browser, string $token, array $fields): Response
    {
        return $this->request($browser, 'POST', '/wishlist', $fields + ['_csrf' => $token, 'title' => 'Untitled', 'author' => '', 'isbn' => '', 'priority' => 'normal', 'notes' => '']);
    }

    private function setStatus(Session $browser, string $token, int $id, string $status): Response
    {
        return $this->request($browser, 'POST', "/wishlist/{$id}/status", ['_csrf' => $token, 'status' => $status]);
    }
}
