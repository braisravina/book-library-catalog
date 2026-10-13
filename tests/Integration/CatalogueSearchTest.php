<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Catalogue\Repository\CategoryRepository;
use DigitalLibrary\Catalogue\Repository\LocationRepository;
use DigitalLibrary\Catalogue\CatalogueSearch;
use DigitalLibrary\Catalogue\SearchCriteria;
use DigitalLibrary\Security\Role;

final class CatalogueSearchTest extends IntegrationTestCase
{
    private int $ana;
    private int $bea;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->createUser('ana@example.org', Role::Member);
        $this->bea = $this->createUser('bea@example.org', Role::Member);
    }

    /**
     * @return list<string> titles
     */
    private function search(SearchCriteria $criteria): array
    {
        $search = new CatalogueSearch($this->pdo(), new CategoryRepository($this->pdo()), new LocationRepository($this->pdo()));

        return array_column($search->search($criteria)['rows'], 'title');
    }

    public function testTextSearchMatchesTitleAuthorAndIsbnAccentInsensitively(): void
    {
        $this->makeBook($this->ana, 'Cien años de soledad', '9788437604947', ['Gabriel García Márquez']);
        $this->makeBook($this->ana, 'Rayuela', '9780306406157', ['Julio Cortázar']);
        $this->makeBook($this->ana, 'Follas novas', null, ['Rosalía de Castro']);

        self::assertSame(['Cien años de soledad'], $this->search(new SearchCriteria('garcia marquez')));
        self::assertSame(['Cien años de soledad'], $this->search(new SearchCriteria('ANOS soledad')), 'Case and accent insensitive, words ANDed');
        self::assertSame(['Rayuela'], $this->search(new SearchCriteria('cortazar')));
        self::assertSame(['Rayuela'], $this->search(new SearchCriteria('0-306-40615-2')), 'ISBN-10 input finds the ISBN-13 record');
        self::assertSame(['Rayuela'], $this->search(new SearchCriteria('978-0-306-40615-7')));
        self::assertSame(['Cien años de soledad'], $this->search(new SearchCriteria('7604947')), 'Partial ISBN');
        self::assertSame([], $this->search(new SearchCriteria('rayuela soledad')));
    }

    public function testWildcardsAndInjectionAttemptsAreMatchedLiterally(): void
    {
        $this->makeBook($this->ana, '100% Galego');
        $this->makeBook($this->ana, 'Ordinary title');

        self::assertSame(['100% Galego'], $this->search(new SearchCriteria('%')), 'A bare % matches only a literal percent sign, not everything');
        self::assertSame(['100% Galego'], $this->search(new SearchCriteria('100%')));
        self::assertSame([], $this->search(new SearchCriteria('_')));
        self::assertSame([], $this->search(new SearchCriteria("' OR '1'='1")));
        self::assertSame([], $this->search(new SearchCriteria('") OR 1=1 -- ')));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM editions'), 'Nothing was modified');
    }

    public function testCategoryFilterIncludesDescendants(): void
    {
        $fiction = $this->insertNode('categories', 'Fiction', null);
        $scifi = $this->insertNode('categories', 'Science fiction', $fiction);
        $poetry = $this->insertNode('categories', 'Poetry', null);
        $this->makeBook($this->ana, 'Dune', null, [], [], [$scifi]);
        $this->makeBook($this->ana, 'Novel', null, [], [], [$fiction]);
        $this->makeBook($this->ana, 'Poems', null, [], [], [$poetry]);

        self::assertSame(['Dune', 'Novel'], $this->search(new SearchCriteria(categoryId: $fiction)));
        self::assertSame(['Dune'], $this->search(new SearchCriteria(categoryId: $scifi)));
        self::assertSame([], $this->search(new SearchCriteria(categoryId: 999999)));
    }

    public function testLanguageOwnerAndLocationFilters(): void
    {
        $house = $this->insertNode('locations', 'House', null);
        $shelf = $this->insertNode('locations', 'Shelf 1', $house);
        $office = $this->insertNode('locations', 'Office', null);

        $this->makeBook($this->ana, 'Ana at home', null, [], ['language' => 'gl'], [], $this->ana, $shelf);
        $this->makeBook($this->bea, 'Bea at office', null, [], ['language' => 'es'], [], $this->bea, $office);
        $shared = $this->makeBook($this->ana, 'Both', null, [], ['language' => 'gl'], [], $this->ana, $office);
        $this->pdo()->prepare('INSERT INTO copies (edition_id, owner_user_id, location_id, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
            ->execute([$shared['edition_id'], $this->bea, $shelf]);

        self::assertSame(['Ana at home', 'Both'], $this->search(new SearchCriteria(language: 'gl')));
        self::assertSame(['Bea at office', 'Both'], $this->search(new SearchCriteria(ownerId: $this->bea)));
        self::assertSame(['Ana at home', 'Both'], $this->search(new SearchCriteria(locationId: $house)), 'Location filter includes sub-locations');
        // Owner and location must match the SAME copy: Ana's "Both" copy is in
        // the office and Bea's is on the shelf, so Ana + House excludes it.
        self::assertSame(['Ana at home'], $this->search(new SearchCriteria(ownerId: $this->ana, locationId: $house)));
        self::assertSame(['Both'], $this->search(new SearchCriteria(query: 'both', ownerId: $this->bea, locationId: $shelf)));
    }

    public function testPaginationAndSorting(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->makeBook($this->ana, sprintf('Book %02d', $i), null, [], ['year' => 1950 + $i]);
        }
        $search = new CatalogueSearch($this->pdo(), new CategoryRepository($this->pdo()), new LocationRepository($this->pdo()));

        $first = $search->search(new SearchCriteria());
        self::assertSame(30, $first['total']);
        self::assertSame(2, $first['pages']);
        self::assertCount(SearchCriteria::PER_PAGE, $first['rows']);
        self::assertSame('Book 01', $first['rows'][0]['title']);

        $second = $search->search(new SearchCriteria(page: 2));
        self::assertCount(6, $second['rows']);
        self::assertSame('Book 30', $second['rows'][5]['title']);

        $beyond = $search->search(new SearchCriteria(page: 99));
        self::assertSame(2, $beyond['page'], 'Out-of-range pages clamp to the last page');

        self::assertSame('Book 30', $search->search(new SearchCriteria(sort: 'year'))['rows'][0]['title']);
        self::assertSame('Book 30', $search->search(new SearchCriteria(sort: 'recent'))['rows'][0]['title']);
    }

    public function testSearchPageThroughHttp(): void
    {
        $this->makeBook($this->ana, 'Cien años de soledad', '9788437604947', ['Gabriel García Márquez']);
        $this->makeBook($this->ana, 'Rayuela');
        [$browser] = $this->signedIn('ana@example.org');

        $html = $this->request($browser, 'GET', '/books?q=garcia&view=list')->body();
        self::assertStringContainsString('Cien años de soledad', $html);
        self::assertStringNotContainsString('Rayuela', $html);
        self::assertStringContainsString('1 result(s)', $html);

        $junk = $this->request($browser, 'GET', '/books?category=1%27&page=-5&sort=evil&q=' . str_repeat('x', 1000));
        self::assertSame(200, $junk->status(), 'Malformed parameters are tolerated');
    }

    private function insertNode(string $table, string $name, ?int $parent): int
    {
        if ($table === 'categories') {
            $this->pdo()->prepare('INSERT INTO categories (parent_id, name, slug, created_at, updated_at) VALUES (?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$parent, $name, bin2hex(random_bytes(6))]);
        } else {
            $this->pdo()->prepare('INSERT INTO locations (parent_id, name, created_at, updated_at) VALUES (?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())')
                ->execute([$parent, $name]);
        }

        return (int) $this->pdo()->lastInsertId();
    }
}
