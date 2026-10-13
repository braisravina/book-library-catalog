<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;

/**
 * End-to-end catalogue workflows through the full HTTP stack.
 */
final class CatalogueFlowTest extends IntegrationTestCase
{
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    public function testIsbnStepThenCreateBookWithAuthorsPublisherAndFirstCopy(): void
    {
        [$browser, $token] = $this->signedIn('member@example.org');

        self::assertStringContainsString('name="isbn"', $this->request($browser, 'GET', '/books/new')->body());
        $form = $this->request($browser, 'GET', '/books/new?isbn=978-84-376-0494-7');
        self::assertSame(200, $form->status());
        self::assertStringContainsString('value="9788437604947"', $form->body(), 'Valid new ISBN pre-fills the full form');

        $response = $this->request($browser, 'POST', '/books', [
            '_csrf' => $token,
            'title' => 'Cien años de soledad',
            'author_names' => ['Gabriel García Márquez'],
            'author_roles' => ['author'],
            'isbn' => '978-84-376-0494-7',
            'publisher' => 'Cátedra',
            'publication_year' => '2007',
            'language' => 'es',
            'contributor_names' => [''],
            'add_copy' => '1',
            'copy_condition' => 'good',
        ]);
        self::assertSame(303, $response->status());
        $location = (string) $response->header('Location');
        self::assertMatchesRegularExpression('#^/editions/\d+$#', $location);

        $edition = $this->pdo()->query('SELECT * FROM editions')->fetch();
        self::assertSame('9788437604947', $edition['isbn_13']);
        self::assertSame('843760494X', $edition['isbn_10']);
        self::assertSame('Cien años de soledad', $edition['title'], 'Edition title defaults to the work title');
        self::assertSame($this->memberId, (int) $edition['created_by']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM publishers WHERE name = 'Cátedra'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM work_authors wa JOIN authors a ON a.id = wa.author_id WHERE a.name = 'Gabriel García Márquez'"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$this->memberId} AND copy_condition = 'good'"));
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action IN ('work.created', 'edition.created', 'copy.created')"));

        $page = $this->request($browser, 'GET', $location)->body();
        self::assertStringContainsString('Cien años de soledad', $page);
        self::assertStringContainsString('Gabriel García Márquez', $page);
        self::assertStringContainsString('843760494X', $page);
    }

    public function testExistingIsbnOffersViewOrAddCopyInEitherIsbnForm(): void
    {
        $book = $this->makeBook($this->memberId, 'Programming', '978-0-306-40615-7');
        [$browser] = $this->signedIn('member@example.org');

        foreach (['9780306406157', '0-306-40615-2'] as $isbn) {
            $response = $this->request($browser, 'GET', '/books/new?isbn=' . rawurlencode($isbn));
            self::assertSame(200, $response->status());
            self::assertStringContainsString('href="/editions/' . $book['edition_id'] . '"', $response->body());
            self::assertStringContainsString('href="/editions/' . $book['edition_id'] . '/copies/new"', $response->body());
        }
    }

    public function testInvalidIsbnIsRejectedAtTheIsbnStep(): void
    {
        [$browser] = $this->signedIn('member@example.org');
        $response = $this->request($browser, 'GET', '/books/new?isbn=978-0-306-40615-8');
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Enter a valid ISBN-10 or ISBN-13.', $response->body());
    }

    public function testPostingADuplicateIsbnCreatesNothing(): void
    {
        $this->makeBook($this->memberId, 'Existing', '9780306406157');
        [$browser, $token] = $this->signedIn('member@example.org');

        $response = $this->request($browser, 'POST', '/books', ['_csrf' => $token, 'title' => 'Copycat', 'isbn' => '0306406152', 'add_copy' => '1']);
        self::assertSame(409, $response->status());
        self::assertStringContainsString('Already in the catalogue', $response->body());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM works'));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM copies'));
    }

    public function testBooksWithoutIsbnWarnAboutSameTitleUntilConfirmed(): void
    {
        $this->makeBook($this->memberId, 'Os Eoas');
        [$browser, $token] = $this->signedIn('member@example.org');
        $body = ['_csrf' => $token, 'title' => 'os eoas', 'publisher' => 'Galaxia'];

        $warning = $this->request($browser, 'POST', '/books', $body);
        self::assertSame(422, $warning->status());
        self::assertStringContainsString('Possible duplicate', $warning->body());
        self::assertStringContainsString('name="confirm_new"', $warning->body());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM editions'));

        self::assertSame(303, $this->request($browser, 'POST', '/books', $body + ['confirm_new' => '1'])->status());
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM editions'));
    }

    public function testValidationErrorsAreReportedAndInputIsKept(): void
    {
        [$browser, $token] = $this->signedIn('member@example.org');
        $response = $this->request($browser, 'POST', '/books', [
            '_csrf' => $token, 'title' => '', 'subtitle' => '<b>kept</b>', 'publication_year' => 'MMXX', 'isbn' => '123',
        ]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('This field is required.', $response->body());
        self::assertStringContainsString('&lt;b&gt;kept&lt;/b&gt;', $response->body());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM works'));
    }

    public function testSeveralOwnersRegisterCopiesOfOneEdition(): void
    {
        $otherId = $this->createUser('other@example.org', Role::Member);
        $book = $this->makeBook($this->memberId, 'Shared book', '9780306406157', [], [], [], $this->memberId);
        [$browser, $token] = $this->signedIn('other@example.org');

        $response = $this->request($browser, 'POST', "/editions/{$book['edition_id']}/copies", [
            '_csrf' => $token, 'owner_user_id' => (string) $otherId, 'status' => 'available', 'copy_condition' => 'fine', 'is_signed' => '1',
            'price' => '12,50', 'currency' => 'EUR', 'acquired_on' => '2024-05-01',
        ]);
        self::assertSame(303, $response->status());
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM editions'));
        self::assertSame(2, $this->countRows("SELECT COUNT(DISTINCT owner_user_id) FROM copies WHERE edition_id = {$book['edition_id']}"));
        self::assertSame(1250, $this->countRows("SELECT purchase_price_cents FROM copies WHERE owner_user_id = {$otherId}"));
    }

    public function testAuthorsAndPublishersAreReusedButAccentDistinctNamesAreNot(): void
    {
        $this->makeBook($this->memberId, 'One', null, ['Rosalía de Castro'], ['publisher' => 'Galaxia']);
        $this->makeBook($this->memberId, 'Two', null, ['rosalía de castro'], ['publisher' => 'galaxia']);
        $this->makeBook($this->memberId, 'Three', null, ['Jose Saramago']);
        $this->makeBook($this->memberId, 'Four', null, ['José Saramago']);

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM authors WHERE name LIKE 'Ros%'"), 'Case-insensitive reuse');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM publishers'));
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM authors WHERE name LIKE '%Saramago'"), 'Accents distinguish people');
    }

    public function testEditingAnEditionToAnExistingIsbnIsRejected(): void
    {
        $this->makeBook($this->memberId, 'First', '9780306406157');
        $second = $this->makeBook($this->memberId, 'Second', '9788437604947');
        [$browser, $token] = $this->signedIn('member@example.org');

        $response = $this->request($browser, 'POST', "/editions/{$second['edition_id']}", ['_csrf' => $token, 'edition_title' => 'Second', 'isbn' => '0306406152']);
        self::assertSame(409, $response->status());
        self::assertSame('9788437604947', $this->pdo()->query("SELECT isbn_13 FROM editions WHERE id = {$second['edition_id']}")->fetchColumn());

        $ok = $this->request($browser, 'POST', "/editions/{$second['edition_id']}", ['_csrf' => $token, 'edition_title' => 'Second (revised)', 'isbn' => '', 'contributor_names' => ['Ana Translator'], 'contributor_roles' => ['translator']]);
        self::assertSame(303, $ok->status());
        self::assertNull($this->pdo()->query("SELECT isbn_13 FROM editions WHERE id = {$second['edition_id']}")->fetchColumn(), 'ISBN can be cleared');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM edition_contributors WHERE role = 'translator'"));
    }

    public function testEditWorkAuthorsAndCategoriesAndAddEdition(): void
    {
        $catId = $this->insertCategory('Novel');
        $book = $this->makeBook($this->memberId, 'Rayuela', null, ['Julio Cortázar']);
        [$browser, $token] = $this->signedIn('member@example.org');

        $response = $this->request($browser, 'POST', "/works/{$book['work_id']}", [
            '_csrf' => $token, 'title' => 'Rayuela', 'original_language' => 'es', 'first_published_year' => '1963',
            'author_names' => ['Julio Cortázar', 'Julio Cortázar', 'Some Editor'], 'author_roles' => ['author', 'author', 'editor'],
            'categories' => [(string) $catId],
        ]);
        self::assertSame(303, $response->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM work_authors WHERE work_id = {$book['work_id']}"), 'Duplicate rows collapse');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM work_categories WHERE work_id = {$book['work_id']}"));

        $bad = $this->request($browser, 'POST', "/works/{$book['work_id']}", ['_csrf' => $token, 'title' => 'Rayuela', 'categories' => ['999999']]);
        self::assertSame(422, $bad->status(), 'Unknown category ids are rejected');

        $edition = $this->request($browser, 'POST', "/works/{$book['work_id']}/editions", ['_csrf' => $token, 'edition_title' => 'Hopscotch', 'language' => 'en', 'isbn' => '9780394752846']);
        self::assertSame(303, $edition->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM editions WHERE work_id = {$book['work_id']}"));
        self::assertStringContainsString('Hopscotch', $this->request($browser, 'GET', "/works/{$book['work_id']}")->body());
    }

    public function testCatalogueRendersGridListAndEscapesTitles(): void
    {
        $this->makeBook($this->memberId, '<script>alert("t")</script>', null, ['<img src=x onerror=alert(1)>']);
        [$browser] = $this->signedIn('member@example.org');

        foreach (['/books', '/books?view=list', '/'] as $path) {
            $html = $this->request($browser, 'GET', $path)->body();
            self::assertStringNotContainsString('<script>alert', $html, $path);
            self::assertStringNotContainsString('<img src=x', $html, $path);
            self::assertStringContainsString('&lt;script&gt;', $html, $path);
        }
        $id = $this->countRows('SELECT id FROM editions');
        $detail = $this->request($browser, 'GET', "/editions/{$id}")->body();
        self::assertStringNotContainsString('<script>alert', $detail);
    }

    public function testMissingRecordsAre404(): void
    {
        [$browser] = $this->signedIn('member@example.org');
        foreach (['/editions/999999', '/works/999999', '/authors/999999', '/publishers/999999', '/covers/999999', '/copies/999999/edit', '/editions/abc'] as $path) {
            self::assertSame(404, $this->request($browser, 'GET', $path)->status(), $path);
        }
    }

    private function insertCategory(string $name, ?int $parent = null): int
    {
        $this->pdo()->prepare('INSERT INTO categories (parent_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$parent, $name, strtolower($name) . '-' . random_int(1, 1_000_000)]);

        return (int) $this->pdo()->lastInsertId();
    }
}
