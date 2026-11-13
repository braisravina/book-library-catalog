<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use DigitalLibrary\Tests\Support\RouteTable;

/**
 * Phase H2: what a user reads never includes another household's inventory.
 *
 * Two households, Casa and Piso. Everything that belongs to Piso carries a
 * marker: "zzinv" in inventory and personal data (owner names and e-mail
 * addresses, the household and location names, notes, inventory codes,
 * borrowers) and "qqbib" in the bibliographic records only Piso holds
 * (titles, authors, publishers). A member of Casa must never be sent
 * either marker, with one deliberate exception: entering an ISBN that is
 * already catalogued shows the shared bibliographic description (qqbib),
 * and still nothing about who holds it (zzinv).
 */
final class HouseholdReadIsolationTest extends IntegrationTestCase
{
    private const INVENTORY = 'zzinv';
    private const BIBLIOGRAPHIC = 'qqbib';

    private int $adminId;
    private int $anaId;
    private int $veraId;
    private int $beaId;
    private int $carlId;
    private int $casa;
    private int $piso;
    private int $casaShelf;
    private int $pisoShelf;

    /** @var array{work_id: int, edition_id: int, copy_id: ?int} Casa's own book (Ana's copy) */
    private array $casaBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} held by Piso only */
    private array $pisoBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} catalogued by Ana, one copy in each household */
    private array $sharedBook;
    private int $sharedPisoCopy;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} catalogued by Ana, held by Piso only */
    private array $givenBook;
    private int $givenPisoCopy;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} Piso's book that Ana borrowed */
    private array $lentBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} a copy without any household */
    private array $orphanBook;
    private int $pisoLoan;
    private int $borrowedLoan;
    private int $pisoCover;
    private int $casaCover;
    private int $pisoAuthor;
    private int $pisoPublisher;
    private int $sharedCollection;
    private int $anaCollection;
    /** @var list<string> */
    private array $temp = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->anaId = $this->createUser('ana@example.org', Role::Member);
        $this->veraId = $this->createUser('vera@example.org', Role::Viewer);
        $this->beaId = $this->createUser('zzinv-bea@example.org', Role::Member);
        $this->carlId = $this->createUser('carl@example.org', Role::Member);
        $this->casa = $this->createHousehold('Casa', [$this->anaId, $this->veraId]);
        $this->piso = $this->createHousehold('Zzinv Piso', [$this->beaId]);
        $this->casaShelf = $this->location('Casa shelf', $this->casa);
        $this->pisoShelf = $this->location('Zzinv shelf', $this->piso);

        $this->casaBook = $this->makeBook($this->anaId, 'Casa Book', self::isbn(1), ['Casa Author'], ['publisher' => 'Casa Press', 'language' => 'es'], [], $this->anaId, $this->casaShelf, $this->casa);
        $this->pisoBook = $this->makeBook($this->beaId, 'Qqbib Title', self::isbn(2), ['Qqbib Author'], ['publisher' => 'Qqbib Press', 'language' => 'fr'], [], $this->beaId, $this->pisoShelf, $this->piso);
        $this->sharedBook = $this->makeBook($this->anaId, 'Shared Classic', self::isbn(3), ['Shared Author'], ['publisher' => 'Casa Press'], [], $this->anaId, $this->casaShelf, $this->casa);
        $this->sharedPisoCopy = $this->copy($this->sharedBook['edition_id'], $this->beaId, $this->piso, $this->pisoShelf);
        $this->givenBook = $this->makeBook($this->anaId, 'Given Away', self::isbn(4), ['Shared Author']);
        $this->givenPisoCopy = $this->copy($this->givenBook['edition_id'], $this->beaId, $this->piso, $this->pisoShelf);
        $this->lentBook = $this->makeBook($this->beaId, 'Borrowed Novel', self::isbn(5), ['Qqbib Lender'], ['publisher' => 'Qqbib Press'], [], $this->beaId, $this->pisoShelf, $this->piso);
        $this->orphanBook = $this->makeBook($this->beaId, 'Qqbib Orphan', self::isbn(6), ['Qqbib Author'], [], [], $this->beaId);
        $this->pdo()->exec("UPDATE copies SET household_id = NULL WHERE id = {$this->orphanBook['copy_id']}");
        $this->pdo()->exec("UPDATE copies SET notes = 'zzinv private note', inventory_code = CONCAT('ZZINV-', id), purchase_price_cents = 987650, currency = 'EUR'
            WHERE household_id = {$this->piso} OR household_id IS NULL");

        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, notes, created_at, updated_at)
            VALUES ({$this->pisoBook['copy_id']}, 'Zzinv Borrower', '2026-01-02', 'zzinv loan note', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pisoLoan = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("UPDATE copies SET status = 'on_loan' WHERE id = {$this->pisoBook['copy_id']}");
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, due_on, notes, created_at, updated_at)
            VALUES ({$this->lentBook['copy_id']}, {$this->anaId}, '2026-02-01', '2026-03-01', 'zzinv lender note', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->borrowedLoan = (int) $this->pdo()->lastInsertId();

        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, description, visibility, created_at, updated_at)
            VALUES ({$this->beaId}, 'Shared picks', 'Open to everyone', 'shared', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->sharedCollection = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at)
            VALUES ({$this->anaId}, 'Ana list', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->anaCollection = (int) $this->pdo()->lastInsertId();
        foreach ([$this->sharedCollection, $this->anaCollection] as $collection) {
            foreach ([$this->pisoBook['edition_id'], $this->casaBook['edition_id'], $this->lentBook['edition_id']] as $position => $edition) {
                $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES ({$collection}, {$edition}, {$position} + 1, UTC_TIMESTAMP())");
            }
        }
        // A wish Ana linked to the Piso edition (as when she could still see it).
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, edition_id, work_id, title, isbn_13, created_at, updated_at)
            VALUES ({$this->anaId}, {$this->pisoBook['edition_id']}, {$this->pisoBook['work_id']}, 'Wanted by Ana', '" . self::isbn(2) . "', UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        $this->pisoAuthor = (int) $this->pdo()->query("SELECT id FROM authors WHERE name = 'Qqbib Author'")->fetchColumn();
        $this->pisoPublisher = (int) $this->pdo()->query("SELECT id FROM publishers WHERE name = 'Qqbib Press'")->fetchColumn();

        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        self::$trustUploads = true;
        $this->pisoCover = $this->cover('zzinv-bea@example.org', $this->pisoBook['edition_id']);
        $this->casaCover = $this->cover('ana@example.org', $this->casaBook['edition_id']);
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    // --- The leak canary ---------------------------------------------------------

    /**
     * Every GET route that returns record data, requested by a member of
     * Casa, by a viewer of Casa and by a member with no household at all.
     */
    public function testNoReadRouteSendsAnotherHouseholdsData(): void
    {
        $urls = $this->crawl();
        foreach (['ana@example.org', 'vera@example.org', 'carl@example.org'] as $email) {
            [$browser] = $this->signedIn($email);
            foreach ($urls as $name => $list) {
                foreach ($list as $url) {
                    $response = $this->request($browser, 'GET', $url);
                    self::assertContains($response->status(), [200, 303, 403, 404], "{$email} GET {$url} ({$name})");
                    $this->assertNoMarker($response, "{$email} GET {$url} ({$name})");
                }
            }
        }
    }

    /**
     * The crawl covers every GET route the household matrix classifies as
     * record data, so a new route cannot be added without being tested.
     */
    public function testTheCrawlCoversEveryReadRoute(): void
    {
        $expected = array_keys(array_filter(RouteTable::recordRoutes(), static fn (array $r): bool => $r[0] === 'GET'));
        $crawled = array_keys(array_filter($this->crawl(), static fn (array $urls): bool => $urls !== []));
        sort($expected);
        sort($crawled);
        // The duplicate-ISBN notice (books.create with a catalogued ISBN) is
        // tested on its own: it shows the shared description by design.
        self::assertSame($expected, $crawled, 'Every record-reading GET route is crawled (add new routes to crawl())');
        foreach (RouteTable::recordRoutes() as $name => [$method]) {
            if ($method === 'GET') {
                self::assertContains(RouteTable::matrix()[$name]['read'] ?? RouteTable::matrix()[$name]['phase'], ['H2'], "{$name}: read visibility is enforced from phase H2");
            }
        }
    }

    /**
     * URLs per route name. They aim at Piso's records by id, at the shared
     * records, and at every list, search and download.
     *
     * @return array<string, list<string>>
     */
    private function crawl(): array
    {
        $b = $this->pisoBook;
        $s = $this->sharedBook;
        $g = $this->givenBook;
        $isbn = self::isbn(2);

        return [
            'home' => ['/'],
            'books.index' => [
                '/books', '/books?q=Title', '/books?q=Orphan', '/books?q=private+note', "/books?q={$isbn}", '/books?q=' . substr($isbn, 3, 8), "/books?owner={$this->beaId}",
                "/books?location={$this->pisoShelf}", '/books?language=fr', '/books?sort=recent', '/books?q=Borrowed', '/books?page=2',
            ],
            'books.create' => ['/books/new', '/books/new?manual=1', '/books/new?isbn=' . self::isbn(99) . '&manual=1'],
            'works.show' => ["/works/{$b['work_id']}", "/works/{$s['work_id']}", "/works/{$g['work_id']}", "/works/{$this->lentBook['work_id']}", "/works/{$this->orphanBook['work_id']}"],
            'works.edit' => ["/works/{$b['work_id']}/edit", "/works/{$s['work_id']}/edit", "/works/{$g['work_id']}/edit"],
            'editions.create' => ["/works/{$b['work_id']}/editions/new", "/works/{$b['work_id']}/editions/new?isbn={$isbn}", "/works/{$s['work_id']}/editions/new?isbn={$isbn}"],
            'editions.show' => ["/editions/{$b['edition_id']}", "/editions/{$s['edition_id']}", "/editions/{$g['edition_id']}", "/editions/{$this->lentBook['edition_id']}", "/editions/{$this->orphanBook['edition_id']}"],
            'editions.open_library' => ["/editions/{$b['edition_id']}/open-library", "/editions/{$b['edition_id']}/open-library?prepared=1", "/editions/{$s['edition_id']}/open-library"],
            'editions.edit' => ["/editions/{$b['edition_id']}/edit", "/editions/{$s['edition_id']}/edit", "/editions/{$g['edition_id']}/edit"],
            'copies.create' => ["/editions/{$b['edition_id']}/copies/new", "/editions/{$s['edition_id']}/copies/new", "/editions/{$g['edition_id']}/copies/new"],
            'copies.edit' => ["/copies/{$b['copy_id']}/edit", "/copies/{$this->sharedPisoCopy}/edit", "/copies/{$this->givenPisoCopy}/edit", "/copies/{$this->orphanBook['copy_id']}/edit"],
            'copies.show' => ["/copies/{$b['copy_id']}", "/copies/{$this->sharedPisoCopy}", "/copies/{$this->givenPisoCopy}", "/copies/{$this->lentBook['copy_id']}", "/copies/{$this->orphanBook['copy_id']}"],
            'loans.create' => ["/copies/{$b['copy_id']}/lend", "/copies/{$this->sharedPisoCopy}/lend", "/copies/{$s['copy_id']}/lend"],
            'loans.index' => ['/loans', '/loans?scope=lent&status=all', '/loans?scope=borrowed&status=all', '/loans?scope=all&status=all', '/loans?status=overdue'],
            'loans.return' => ["/loans/{$this->pisoLoan}/return", "/loans/{$this->borrowedLoan}/return"],
            'wishlist.index' => ['/wishlist', '/wishlist?status=all'],
            'wishlist.create' => ['/wishlist/new', "/wishlist/new?edition={$b['edition_id']}", "/wishlist/new?edition={$s['edition_id']}"],
            'wishlist.edit' => ['/wishlist/1/edit'],
            'collections.index' => ['/collections', '/collections?scope=shared', '/collections?scope=mine', '/collections?scope=all'],
            'collections.create' => ['/collections/new'],
            'collections.show' => ["/collections/{$this->sharedCollection}", "/collections/{$this->anaCollection}", "/collections/{$this->sharedCollection}?page=2"],
            'collections.edit' => ["/collections/{$this->sharedCollection}/edit", "/collections/{$this->anaCollection}/edit"],
            'export.index' => ['/export'],
            'export.personal.json' => ['/export/personal.json'],
            'export.personal.csv' => array_map(static fn (string $d): string => "/export/personal/{$d}.csv", \DigitalLibrary\Export\PersonalExport::DATASETS),
            'covers.show' => ["/covers/{$this->pisoCover}", "/covers/{$this->pisoCover}?size=thumb"],
            'authors.index' => ['/authors', '/authors?q=Lender', '/authors?q=Author', '/authors?page=2'],
            'authors.show' => ["/authors/{$this->pisoAuthor}", '/authors/' . $this->id('authors', 'Shared Author')],
            'publishers.index' => ['/publishers'],
            'publishers.show' => ["/publishers/{$this->pisoPublisher}", '/publishers/' . $this->id('publishers', 'Casa Press')],
        ];
    }

    // --- Direct object references ------------------------------------------------

    /**
     * A record of another household is answered exactly like a record that
     * does not exist: same status, same page.
     */
    public function testRecordsOfAnotherHouseholdAnswerExactlyLikeMissingOnes(): void
    {
        [$ana] = $this->signedIn('ana@example.org');
        $b = $this->pisoBook;
        $targets = [
            "/works/{$b['work_id']}", "/works/{$b['work_id']}/edit", "/works/{$b['work_id']}/editions/new",
            "/editions/{$b['edition_id']}", "/editions/{$b['edition_id']}/open-library", "/editions/{$b['edition_id']}/edit", "/editions/{$b['edition_id']}/copies/new",
            "/copies/{$b['copy_id']}", "/copies/{$b['copy_id']}/edit", "/copies/{$b['copy_id']}/lend",
            "/copies/{$this->sharedPisoCopy}", "/copies/{$this->sharedPisoCopy}/edit", "/copies/{$this->sharedPisoCopy}/lend",
            "/copies/{$this->orphanBook['copy_id']}", "/loans/{$this->pisoLoan}/return", "/loans/{$this->borrowedLoan}/return",
            "/covers/{$this->pisoCover}", "/authors/{$this->pisoAuthor}", "/publishers/{$this->pisoPublisher}",
            "/wishlist/new?edition={$b['edition_id']}",
        ];
        foreach ($targets as $url) {
            $real = $this->request($ana, 'GET', $url);
            $missing = $this->request($ana, 'GET', (string) preg_replace('/\d+/', '999999', $url, 1));
            self::assertSame(404, $real->status(), $url);
            self::assertSame(404, $missing->status(), $url);
            self::assertSame(self::neutral($missing->body()), self::neutral($real->body()), "{$url} is indistinguishable from a missing record");
        }

        // The same people see their own records, and an administrator sees all of them.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        [$admin] = $this->signedIn('admin@example.org');
        foreach (["/works/{$b['work_id']}", "/editions/{$b['edition_id']}", "/copies/{$b['copy_id']}", "/covers/{$this->pisoCover}", "/authors/{$this->pisoAuthor}", "/publishers/{$this->pisoPublisher}"] as $url) {
            self::assertSame(200, $this->request($bea, 'GET', $url)->status(), $url);
            self::assertSame(200, $this->request($admin, 'GET', $url)->status(), $url);
        }
        self::assertSame(404, $this->request($bea, 'GET', "/copies/{$this->orphanBook['copy_id']}")->status(), 'A copy without a household is reachable by administrators only');
        self::assertSame(200, $this->request($admin, 'GET', "/copies/{$this->orphanBook['copy_id']}")->status());
        self::assertSame(404, $this->request($bea, 'GET', "/editions/{$this->casaBook['edition_id']}")->status(), 'Isolation holds in both directions');
    }

    /**
     * State-changing requests against another household's records are 404
     * before anything is read, validated or written, so no error page or
     * re-displayed form can show their data.
     */
    public function testWritesAgainstAnotherHouseholdsRecordsAre404AndChangeNothing(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $b = $this->pisoBook;
        $before = $this->snapshot();
        $posts = [
            "/works/{$b['work_id']}" => ['title' => ''],
            "/works/{$b['work_id']}/editions" => ['title' => ''],
            "/editions/{$b['edition_id']}" => ['title' => ''],
            "/editions/{$b['edition_id']}/copies" => ['owner_user_id' => (string) $this->anaId, 'status' => 'available', 'inventory_code' => 'ZZINV-' . $b['copy_id']],
            "/editions/{$b['edition_id']}/covers" => [],
            "/copies/{$b['copy_id']}" => ['owner_user_id' => (string) $this->anaId, 'status' => 'lost', 'row_version' => '1'],
            "/copies/{$b['copy_id']}/delete" => [],
            "/copies/{$b['copy_id']}/lend" => ['borrower_name' => 'x', 'loaned_on' => '2026-01-01'],
            "/copies/{$this->sharedPisoCopy}" => ['owner_user_id' => (string) $this->anaId, 'status' => 'lost', 'row_version' => '1'],
            "/copies/{$this->sharedPisoCopy}/lend" => ['borrower_name' => 'x', 'loaned_on' => '2026-01-01'],
            "/loans/{$this->pisoLoan}/return" => [],
            "/loans/{$this->borrowedLoan}/return" => [],
            "/covers/{$this->pisoCover}/delete" => [],
            "/collections/{$this->anaCollection}/items" => ['edition_id' => (string) $this->orphanBook['edition_id']],
            '/collections/add-item' => ['collection_id' => (string) $this->anaCollection, 'edition_id' => (string) $this->orphanBook['edition_id']],
            '/wishlist' => ['edition_id' => (string) $b['edition_id'], 'title' => 'x'],
        ];
        foreach ($posts as $url => $body) {
            $response = $this->request($ana, 'POST', $url, ['_csrf' => $token] + $body);
            self::assertSame(404, $response->status(), "POST {$url}");
            $this->assertNoMarker($response, "POST {$url}");
        }
        self::assertSame($before, $this->snapshot(), 'Nothing was written');
    }

    // --- Shared bibliographic records ----------------------------------------------

    /**
     * A record stays in view for the user who catalogued it when another
     * household adds a copy, or holds the only copies, and still shows
     * nothing of that household's inventory.
     */
    public function testCreatorsKeepTheirBibliographicRecordsButNeverSeeOtherHouseholdsCopies(): void
    {
        [$ana] = $this->signedIn('ana@example.org');

        $shared = $this->request($ana, 'GET', "/editions/{$this->sharedBook['edition_id']}");
        self::assertSame(200, $shared->status());
        self::assertStringContainsString('Shared Classic', $shared->body());
        self::assertStringContainsString("/copies/{$this->sharedBook['copy_id']}", $shared->body(), 'Her own copy is listed');
        self::assertStringNotContainsString("/copies/{$this->sharedPisoCopy}", $shared->body(), 'The other household\'s copy is not');
        $this->assertNoMarker($shared, 'shared edition');

        // Held by Piso only: Ana catalogued it, so she still sees the description, with no copies.
        $given = $this->request($ana, 'GET', "/editions/{$this->givenBook['edition_id']}");
        self::assertSame(200, $given->status());
        self::assertStringContainsString('Given Away', $given->body());
        self::assertStringNotContainsString("/copies/{$this->givenPisoCopy}", $given->body());
        $this->assertNoMarker($given, 'edition held elsewhere');
        self::assertSame(200, $this->request($ana, 'GET', "/works/{$this->givenBook['work_id']}")->status());
        self::assertSame(200, $this->request($ana, 'GET', "/editions/{$this->givenBook['edition_id']}/edit")->status(), 'She can still open her own record for editing');
        self::assertStringContainsString('Given Away', $this->request($ana, 'GET', '/books?q=Given')->body());

        // Vera belongs to Casa but catalogued nothing: the same edition does not exist for her.
        [$vera] = $this->signedIn('vera@example.org');
        self::assertSame(404, $this->request($vera, 'GET', "/editions/{$this->givenBook['edition_id']}")->status());
        self::assertSame(200, $this->request($vera, 'GET', "/editions/{$this->sharedBook['edition_id']}")->status(), 'Seen through Casa\'s copy');

        // Bea sees it through Piso's copy, and only Piso's copy.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        $body = $this->request($bea, 'GET', "/editions/{$this->sharedBook['edition_id']}")->body();
        self::assertStringContainsString("/copies/{$this->sharedPisoCopy}", $body);
        self::assertStringNotContainsString("/copies/{$this->sharedBook['copy_id']}", $body);
        self::assertStringNotContainsString('Casa shelf', $body);
        self::assertStringNotContainsString('>Ana<', $body);

        // Carl has no household: only what he catalogues himself.
        [$carl, $token] = $this->signedIn('carl@example.org');
        self::assertStringContainsString('0 result(s)', $this->request($carl, 'GET', '/books')->body());
        $created = $this->request($carl, 'POST', '/books', ['_csrf' => $token, 'title' => 'Carl Own', 'confirm_new' => '1']);
        self::assertSame(303, $created->status());
        self::assertSame(200, $this->request($carl, 'GET', (string) $created->header('Location'))->status());
        self::assertStringContainsString('1 result(s)', $this->request($carl, 'GET', '/books')->body());
    }

    /**
     * ISBN-13 is unique across the shared catalogue, so an ISBN held only
     * by another household is reported, with the shared description and
     * nothing else: no copies, owner, household, location or link.
     */
    public function testDuplicateIsbnShowsTheSharedDescriptionWithoutHoldings(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $isbn = self::isbn(2);
        $edition = $this->pisoBook['edition_id'];

        foreach ([
            $this->request($ana, 'GET', "/books/new?isbn={$isbn}"),
            $this->request($ana, 'GET', "/books/new?isbn={$isbn}&manual=1"),
            $this->request($ana, 'POST', '/books', ['_csrf' => $token, 'title' => 'Mine', 'isbn' => $isbn]),
        ] as $i => $response) {
            self::assertSame($i === 2 ? 409 : 200, $response->status());
            $body = $response->body();
            self::assertStringContainsString('Qqbib Title', $body, 'The shared bibliographic description is shown');
            self::assertStringContainsString('already described in the shared catalogue', $body);
            self::assertStringNotContainsString('Physical copies', $body, 'No copy count');
            self::assertStringNotContainsString("/editions/{$edition}", $body, 'No link to the record or to adding a copy');
            self::assertStringNotContainsStringIgnoringCase(self::INVENTORY, $body, 'Nothing about who holds it');
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM editions WHERE isbn_13 = '{$isbn}'"), 'Nothing was created');

        // Bea, who holds it, gets the usual notice with her household's copy count.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        $own = $this->request($bea, 'GET', "/books/new?isbn={$isbn}")->body();
        self::assertStringContainsString('Physical copies: 1', $own);
        self::assertStringContainsString("/editions/{$edition}", $own);

        // The shared record Ana can see counts only Casa's copy.
        $shared = $this->request($ana, 'GET', '/books/new?isbn=' . self::isbn(3))->body();
        self::assertStringContainsString('Physical copies: 1', $shared);

        // "ISBN already used" on the edition forms names the other record only when it is visible.
        $taken = $this->request($ana, 'POST', "/works/{$this->casaBook['work_id']}/editions", ['_csrf' => $token, 'edition_title' => 'Second', 'isbn' => $isbn]);
        self::assertSame(409, $taken->status());
        self::assertStringNotContainsString("/editions/{$edition}", $taken->body());
        $this->assertNoMarker($taken, 'new edition with a taken ISBN');
        $edit = $this->request($ana, 'POST', "/editions/{$this->casaBook['edition_id']}", ['_csrf' => $token, 'edition_title' => 'Casa Book', 'isbn' => $isbn, 'row_version' => $this->rowVersion('editions', $this->casaBook['edition_id'])]);
        self::assertSame(409, $edit->status());
        self::assertStringNotContainsString("/editions/{$edition}", $edit->body());
        $this->assertNoMarker($edit, 'edit with a taken ISBN');
        $visible = $this->request($ana, 'POST', "/works/{$this->casaBook['work_id']}/editions", ['_csrf' => $token, 'edition_title' => 'Second', 'isbn' => self::isbn(3)]);
        self::assertStringContainsString("/editions/{$this->sharedBook['edition_id']}", $visible->body(), 'A visible duplicate is still linked');
    }

    /**
     * Same-title warnings and "possibly the same work" suggestions are
     * taken from visible records only.
     */
    public function testTitleWarningsAndSuggestionsNeverRevealOtherHouseholdsRecords(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');

        // Same title as Piso's book, no ISBN: no warning, the book is created.
        $response = $this->request($ana, 'POST', '/books', ['_csrf' => $token, 'title' => 'Qqbib Title']);
        self::assertSame(303, $response->status(), 'No "possible duplicate" stop for a title only another household holds');
        // Same title as her own book: the warning still works.
        $warned = $this->request($ana, 'POST', '/books', ['_csrf' => $token, 'title' => 'Casa Book']);
        self::assertSame(422, $warned->status());
        self::assertStringContainsString("/editions/{$this->casaBook['edition_id']}", $warned->body());

        $works = new \DigitalLibrary\Catalogue\Repository\WorkRepository($this->pdo());
        $visibility = $this->visibilityOf($this->veraId);
        self::assertSame([], $works->similar('Qqbib Orphan', ['Qqbib Author'], $visibility));
        self::assertSame(['Shared Classic'], array_column($works->similar('Shared Classic', [], $visibility), 'title'));
        self::assertSame([1], array_column($works->similar('Shared Classic', [], $visibility), 'edition_count'));
        self::assertNotSame([], $works->similar('Qqbib Orphan', [], \DigitalLibrary\Security\Visibility::unrestricted()));

        // The lists offered while typing hold visible authors and publishers only.
        $form = $this->request($ana, 'GET', '/books/new?manual=1')->body();
        self::assertStringContainsString('Casa Author', $form);
        self::assertStringContainsString('Casa Press', $form);
        $this->assertNoMarker($this->request($ana, 'GET', '/books/new?manual=1'), 'book form');
    }

    // --- Counts, filters and lists -------------------------------------------------

    public function testCountsTotalsAndFiltersAreComputedOverVisibleRecordsOnly(): void
    {
        [$ana] = $this->signedIn('ana@example.org');
        [$vera] = $this->signedIn('vera@example.org');
        [$admin] = $this->signedIn('admin@example.org');

        // Ana: Casa Book, Shared Classic (copies) and Given Away (catalogued by her).
        self::assertSame(['works' => 3, 'editions' => 3, 'copies' => 2, 'authors' => 2, 'my_copies' => 2], $this->stats($this->anaId));
        // Vera: only what Casa holds.
        self::assertSame(['works' => 2, 'editions' => 2, 'copies' => 2, 'authors' => 2, 'my_copies' => 0], $this->stats($this->veraId));
        self::assertSame(['works' => 0, 'editions' => 0, 'copies' => 0, 'authors' => 0, 'my_copies' => 0], $this->stats($this->carlId));
        self::assertSame(['works' => 6, 'editions' => 6, 'copies' => 7, 'authors' => 4, 'my_copies' => 0], $this->stats($this->adminId));

        self::assertStringContainsString('3 result(s)', $this->request($ana, 'GET', '/books')->body());
        self::assertStringContainsString('2 result(s)', $this->request($vera, 'GET', '/books')->body());
        self::assertStringContainsString('6 result(s)', $this->request($admin, 'GET', '/books')->body());
        foreach (['/books?q=Title', '/books?q=' . self::isbn(2), "/books?owner={$this->beaId}", "/books?location={$this->pisoShelf}", '/books?language=fr'] as $url) {
            self::assertStringContainsString('0 result(s)', $this->request($ana, 'GET', $url)->body(), $url);
        }
        self::assertStringContainsString('2 result(s)', $this->request($ana, 'GET', "/books?location={$this->casaShelf}")->body());
        // The owner filter matches on the same visible copy: Bea's copy of the shared book does not count.
        self::assertStringContainsString('2 result(s)', $this->request($ana, 'GET', "/books?owner={$this->anaId}")->body());

        // Filters offer only the user's own households' locations, co-members and visible languages.
        $index = $this->request($ana, 'GET', '/books')->body();
        self::assertStringContainsString('Casa shelf', $index);
        self::assertStringContainsString('>Vera<', $index, 'A household co-member is offered as owner');
        self::assertStringNotContainsString('>Carl<', $index, 'Users of no shared household are not listed');
        self::assertStringNotContainsString('>Admin<', $index);
        self::assertStringNotContainsString('value="fr"', $index, 'A language only another household holds is not offered');
        self::assertStringContainsString('value="es"', $index);

        // Copy counts on the search page count visible copies.
        $search = new \DigitalLibrary\Catalogue\CatalogueSearch($this->pdo(), new \DigitalLibrary\Catalogue\Repository\CategoryRepository($this->pdo()), new \DigitalLibrary\Catalogue\Repository\LocationRepository($this->pdo()));
        $counts = array_column($search->search(new \DigitalLibrary\Catalogue\SearchCriteria(), $this->visibilityOf($this->anaId))['rows'], 'copy_count', 'title');
        self::assertEquals(['Casa Book' => 1, 'Given Away' => 0, 'Shared Classic' => 1], $counts);

        // Authors and publishers: visible ones, with counts over visible records.
        $authors = $this->request($ana, 'GET', '/authors')->body();
        self::assertStringContainsString('Author, Casa', $authors);
        self::assertStringContainsString('Author, Shared', $authors);
        $publishers = (new \DigitalLibrary\Catalogue\Repository\PublisherRepository($this->pdo()))->allWithCounts($this->visibilityOf($this->anaId));
        self::assertEquals(['Casa Press' => 2], array_column($publishers, 'edition_count', 'name'));
        $authorRows = (new \DigitalLibrary\Catalogue\Repository\AuthorRepository($this->pdo()))->paginate('', 1, 50, $this->visibilityOf($this->veraId));
        self::assertEquals(['Casa Author' => 1, 'Shared Author' => 1], array_column($authorRows['rows'], 'work_count', 'name'), 'Given Away does not count for Vera');
        self::assertSame(2, $authorRows['total']);
    }

    public function testCollectionsListOnlyVisibleEditionsAndHideUnknownOwners(): void
    {
        [$ana] = $this->signedIn('ana@example.org');

        // A shared collection of someone in another household: only the edition Ana can see, counted as one.
        $shared = $this->request($ana, 'GET', "/collections/{$this->sharedCollection}");
        self::assertSame(200, $shared->status());
        self::assertStringContainsString('Casa Book', $shared->body());
        self::assertStringNotContainsString('Borrowed Novel', $shared->body());
        self::assertStringContainsString('1 books', $shared->body());
        self::assertStringContainsString('Collection of another user', $shared->body());
        $this->assertNoMarker($shared, 'shared collection');

        $list = $this->request($ana, 'GET', '/collections?scope=shared')->body();
        self::assertStringContainsString('Shared picks', $list);
        self::assertStringContainsString('Another user', $list);
        self::assertMatchesRegularExpression('#<td>1</td>#', $list);

        // Her own collection holds a record she can no longer see: not listed, not counted.
        $own = $this->request($ana, 'GET', "/collections/{$this->anaCollection}")->body();
        self::assertStringContainsString('Casa Book', $own);
        self::assertStringNotContainsString('Borrowed Novel', $own);
        self::assertStringContainsString('1 books', $own);

        // The owner and an administrator see all three, with the owner's name.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        self::assertStringContainsString('2 books', $this->request($bea, 'GET', "/collections/{$this->sharedCollection}")->body(), 'Casa Book is outside Bea\'s view');
        [$admin] = $this->signedIn('admin@example.org');
        $all = $this->request($admin, 'GET', "/collections/{$this->sharedCollection}")->body();
        self::assertStringContainsString('3 books', $all);
        self::assertStringContainsString('Collection of Zzinv-bea', $all);
    }

    public function testLoansWishlistAndExportFollowTheSameBoundary(): void
    {
        [$ana] = $this->signedIn('ana@example.org');

        // Borrowed from a household Ana cannot reach: her own loan is listed, title and dates only.
        $borrowed = $this->request($ana, 'GET', '/loans?scope=borrowed&status=all');
        self::assertStringContainsString('Borrowed Novel', $borrowed->body());
        self::assertStringContainsString('2026-03-01', $borrowed->body());
        self::assertStringNotContainsString("/copies/{$this->lentBook['copy_id']}", $borrowed->body(), 'No link to a copy she cannot open');
        $this->assertNoMarker($borrowed, 'borrowed loans');
        self::assertStringContainsString('0 result(s)', $this->request($ana, 'GET', '/loans?scope=lent&status=all')->body());

        // The wish linked to an edition out of view is listed without a link to it.
        $wishlist = $this->request($ana, 'GET', '/wishlist');
        self::assertStringContainsString('Wanted by Ana', $wishlist->body());
        self::assertStringNotContainsString("/editions/{$this->pisoBook['edition_id']}", $wishlist->body());

        $json = json_decode($this->request($ana, 'GET', '/export/personal.json')->body(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertSame([$this->casaBook['copy_id'], $this->sharedBook['copy_id']], array_column($json['copies'], 'id'));
        self::assertSame(['Casa shelf', 'Casa shelf'], array_column($json['copies'], 'location'));
        self::assertCount(1, $json['loans']);
        self::assertSame(['Borrowed Novel', 'borrower', null, null, null, null, null, '2026-03-01'], [
            $json['loans'][0]['edition_title'], $json['loans'][0]['my_role'], $json['loans'][0]['copy_id'], $json['loans'][0]['edition_id'],
            $json['loans'][0]['owner_user_id'], $json['loans'][0]['owner'], $json['loans'][0]['notes'], $json['loans'][0]['due_on'],
        ]);
        self::assertSame([$this->casaBook['edition_id']], array_column($json['collection_items'], 'edition_id'));
        self::assertSame([[null, null]], array_map(static fn (array $w): array => [$w['edition_id'], $w['work_id']], $json['wishlist']));

        // Bea's export is complete for her own household.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        $bea = json_decode($this->request($bea, 'GET', '/export/personal.json')->body(), true, 512, JSON_THROW_ON_ERROR)['data'];
        self::assertCount(4, $bea['copies'], 'Her four Piso copies; the one without a household is not included');
        self::assertSame(['lender', 'lender'], array_column($bea['loans'], 'my_role'));
        self::assertSame('zzinv lender note', $bea['loans'][1]['notes']);
    }

    /**
     * Lending offers and accepts only members of the copy's household (or a
     * free-text name), so it cannot be used to learn who else has an account.
     */
    public function testLendingNeitherListsNorAcceptsUsersOfOtherHouseholds(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $copy = $this->casaBook['copy_id'];

        $form = $this->request($ana, 'GET', "/copies/{$copy}/lend")->body();
        self::assertStringContainsString('>Vera<', $form);
        self::assertStringNotContainsString('>Carl<', $form);
        self::assertStringNotContainsString('>Admin<', $form);
        $this->assertNoMarker($this->request($ana, 'GET', "/copies/{$copy}/lend"), 'lend form');

        $lend = fn (int $borrower): Response => $this->request($ana, 'POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'user', 'borrower_user_id' => (string) $borrower, 'loaned_on' => '2026-01-05']);
        $other = $lend($this->beaId);
        $missing = $lend(999999);
        self::assertSame(422, $other->status());
        self::assertSame(self::neutral($missing->body()), self::neutral($other->body()), 'A user of another household is refused like an account that does not exist');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$copy}"));
        self::assertSame(303, $lend($this->veraId)->status(), 'A household co-member is accepted');

        // Administrators may lend to any active user (unchanged).
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        $adminForm = $this->request($admin, 'GET', "/copies/{$this->sharedBook['copy_id']}/lend")->body();
        self::assertStringContainsString('>Carl<', $adminForm);
        self::assertSame(303, $this->request($admin, 'POST', "/copies/{$this->sharedBook['copy_id']}/lend", ['_csrf' => $adminToken, 'borrower_type' => 'user', 'borrower_user_id' => (string) $this->carlId, 'loaned_on' => '2026-01-05'])->status());
    }

    // --- Membership changes --------------------------------------------------------

    /**
     * Removing a membership takes effect on the member's next request, in
     * the session they already have: pages, direct URLs they had open,
     * covers their browser has cached, searches and exports.
     */
    public function testRevokedMembershipAppliesToTheNextRequestEverywhere(): void
    {
        [$vera] = $this->signedIn('vera@example.org');
        $edition = $this->casaBook['edition_id'];
        $urls = ["/works/{$this->casaBook['work_id']}", "/editions/{$edition}", "/editions/{$edition}/open-library", "/copies/{$this->casaBook['copy_id']}",
            "/covers/{$this->casaCover}", "/covers/{$this->casaCover}?size=thumb", '/authors/' . $this->id('authors', 'Casa Author'), '/publishers/' . $this->id('publishers', 'Casa Press')];
        foreach ($urls as $url) {
            self::assertContains($this->request($vera, 'GET', $url)->status(), [200, 303], "Before: {$url}");
        }
        $cover = $this->request($vera, 'GET', "/covers/{$this->casaCover}");
        self::assertSame('private, no-cache', $cover->header('Cache-Control'), 'Browsers must revalidate covers on every use');
        $etag = (string) $cover->header('ETag');
        self::assertSame(304, $this->request($vera, 'GET', "/covers/{$this->casaCover}", [], ['HTTP_IF_NONE_MATCH' => $etag])->status());
        self::assertStringContainsString('2 result(s)', $this->request($vera, 'GET', '/books')->body());
        self::assertStringContainsString('Casa Book', $this->request($vera, 'GET', '/')->body());

        $this->pdo()->exec("DELETE FROM household_members WHERE user_id = {$this->veraId}");

        foreach ($urls as $url) {
            self::assertSame(404, $this->request($vera, 'GET', $url)->status(), "After: {$url}");
        }
        self::assertSame(404, $this->request($vera, 'GET', "/covers/{$this->casaCover}", [], ['HTTP_IF_NONE_MATCH' => $etag])->status(), 'A cached cover is not confirmed either');
        self::assertStringContainsString('0 result(s)', $this->request($vera, 'GET', '/books')->body());
        $home = $this->request($vera, 'GET', '/')->body();
        self::assertStringNotContainsString('Casa Book', $home);
        self::assertSame(['works' => 0, 'editions' => 0, 'copies' => 0, 'authors' => 0, 'my_copies' => 0], $this->stats($this->veraId));
        self::assertStringNotContainsString('Casa', $this->request($vera, 'GET', '/export/personal.json')->body());
        self::assertStringNotContainsString('Casa Book', $this->request($vera, 'GET', '/authors')->body() . $this->request($vera, 'GET', '/publishers')->body());

        // Joining another household opens exactly that household, again without signing in anew.
        $this->addHouseholdMember($this->piso, $this->veraId);
        self::assertSame(200, $this->request($vera, 'GET', "/editions/{$this->pisoBook['edition_id']}")->status());
        self::assertSame(200, $this->request($vera, 'GET', "/covers/{$this->pisoCover}")->status());
        self::assertSame(404, $this->request($vera, 'GET', "/editions/{$edition}")->status());
    }

    /**
     * An owner removed from the household where their copy is kept loses
     * the copy with it (it stays in the household), including in exports.
     */
    public function testOwnersLoseACopyThatStaysInAHouseholdTheyLeft(): void
    {
        [$ana] = $this->signedIn('ana@example.org');
        $copy = $this->casaBook['copy_id'];
        self::assertSame(200, $this->request($ana, 'GET', "/copies/{$copy}/edit")->status());

        $this->pdo()->exec("DELETE FROM household_members WHERE user_id = {$this->anaId}");

        foreach (["/copies/{$copy}", "/copies/{$copy}/edit", "/copies/{$copy}/lend"] as $url) {
            self::assertSame(404, $this->request($ana, 'GET', $url)->status(), $url);
        }
        // The edition she catalogued is still hers to see, without copies.
        $edition = $this->request($ana, 'GET', "/editions/{$this->casaBook['edition_id']}");
        self::assertSame(200, $edition->status());
        self::assertStringNotContainsString("/copies/{$copy}", $edition->body());
        self::assertStringNotContainsString('Casa shelf', $edition->body());
        self::assertSame([], json_decode($this->request($ana, 'GET', '/export/personal.json')->body(), true)['data']['copies']);
        self::assertSame(0, $this->stats($this->anaId)['my_copies']);
    }

    // --- Administrators and the feature switch ---------------------------------------

    public function testAdministratorsSeeEverythingIncludingUnassignedCopies(): void
    {
        [$admin] = $this->signedIn('admin@example.org');
        $body = $this->request($admin, 'GET', "/editions/{$this->sharedBook['edition_id']}")->body();
        self::assertStringContainsString("/copies/{$this->sharedBook['copy_id']}", $body);
        self::assertStringContainsString("/copies/{$this->sharedPisoCopy}", $body);
        self::assertStringContainsString('Zzinv shelf', $body);
        self::assertSame(200, $this->request($admin, 'GET', "/copies/{$this->orphanBook['copy_id']}")->status());
        self::assertStringContainsString('Qqbib Orphan', $this->request($admin, 'GET', '/books?q=Orphan')->body());
        self::assertStringContainsString('>Zzinv-bea<', $this->request($admin, 'GET', '/books')->body(), 'Every active user is offered as owner');
    }

    /**
     * With HOUSEHOLDS_ENABLED off (the only state production accepts)
     * nothing is filtered: every signed-in user sees the whole catalogue,
     * exactly as before households existed.
     */
    public function testWhileTheFeatureIsDisabledNothingIsFiltered(): void
    {
        self::$configOverrides = [];
        [$carl] = $this->signedIn('carl@example.org');

        self::assertStringContainsString('6 result(s)', $this->request($carl, 'GET', '/books')->body());
        foreach (["/works/{$this->pisoBook['work_id']}", "/editions/{$this->pisoBook['edition_id']}", "/copies/{$this->pisoBook['copy_id']}", "/copies/{$this->orphanBook['copy_id']}",
            "/authors/{$this->pisoAuthor}", "/publishers/{$this->pisoPublisher}", "/collections/{$this->sharedCollection}"] as $url) {
            self::assertSame(200, $this->request($carl, 'GET', $url)->status(), $url);
        }
        $edition = $this->request($carl, 'GET', "/editions/{$this->sharedBook['edition_id']}")->body();
        self::assertStringContainsString("/copies/{$this->sharedBook['copy_id']}", $edition);
        self::assertStringContainsString("/copies/{$this->sharedPisoCopy}", $edition);
        self::assertStringContainsString('Zzinv shelf', $edition);
        self::assertSame('private, max-age=86400', $this->request($carl, 'GET', "/covers/{$this->pisoCover}")->header('Cache-Control'), 'Cover caching is unchanged');
        self::assertStringContainsString('Physical copies: 1', $this->request($carl, 'GET', '/books/new?isbn=' . self::isbn(2))->body());
        self::assertStringContainsString('3 books', $this->request($carl, 'GET', "/collections/{$this->sharedCollection}")->body());
        self::assertStringContainsString('Collection of Zzinv-bea', $this->request($carl, 'GET', "/collections/{$this->sharedCollection}")->body());
        self::assertStringContainsString('>Zzinv-bea<', $this->request($carl, 'GET', '/books')->body());
        self::assertSame(['works' => 6, 'editions' => 6, 'copies' => 7, 'authors' => 4, 'my_copies' => 0], (new \DigitalLibrary\Catalogue\Repository\StatsRepository($this->pdo()))->dashboard(\DigitalLibrary\Security\Visibility::unrestricted(), $this->carlId));

        // Production never enables it, whatever the setting says.
        $production = new \DigitalLibrary\Core\Services(self::config()->with('APP_ENV', 'production')->with('HOUSEHOLDS_ENABLED', 'true'), dirname(__DIR__, 2));
        self::assertFalse($production->householdsEnabled());
        self::assertTrue($production->visibility(\DigitalLibrary\Auth\User::fromRow(['id' => $this->carlId, 'email' => 'carl@example.org', 'display_name' => 'Carl', 'role_code' => 'member', 'locale' => 'en', 'is_active' => 1, 'auth_version' => 1]))->isUnrestricted());
    }

    // --- Helpers -------------------------------------------------------------------

    private function assertNoMarker(Response $response, string $what): void
    {
        $text = $response->body() . "\n" . implode("\n", array_map(static fn (string $k, string $v): string => "{$k}: {$v}", array_keys($response->headers()), $response->headers()));
        foreach ([self::INVENTORY, self::BIBLIOGRAPHIC] as $marker) {
            $at = stripos($text, $marker);
            self::assertFalse($at, "{$what} leaks \"{$marker}\": …" . ($at === false ? '' : substr($text, max(0, $at - 120), 260)) . '…');
        }
    }

    /**
     * A page without what legitimately differs between two requests: the
     * CSRF token and the requested path echoed in the language form.
     */
    private static function neutral(string $body): string
    {
        return (string) preg_replace(['/value="[0-9a-f]{64}"/', '/name="return_to" value="[^"]*"/', '/\d+/'], ['value="T"', 'name="return_to" value="P"', 'N'], $body);
    }

    private static function isbn(int $n): string
    {
        $digits = '978000000' . sprintf('%03d', $n);
        $sum = 0;
        foreach (str_split($digits) as $i => $d) {
            $sum += (int) $d * ($i % 2 === 0 ? 1 : 3);
        }

        return $digits . ((10 - $sum % 10) % 10);
    }

    private function location(string $name, int $householdId): int
    {
        $this->pdo()->prepare('INSERT INTO locations (parent_id, household_id, name, created_at, updated_at) VALUES (NULL, :h, :n, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['h' => $householdId, 'n' => $name]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function copy(int $editionId, int $ownerId, int $householdId, int $locationId): int
    {
        $this->pdo()->prepare(
            "INSERT INTO copies (edition_id, owner_user_id, household_id, location_id, status, created_by, updated_by, created_at, updated_at)
             VALUES (:e, :o, :h, :l, 'available', :c, :u, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute(['e' => $editionId, 'o' => $ownerId, 'h' => $householdId, 'l' => $locationId, 'c' => $ownerId, 'u' => $ownerId]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function cover(string $email, int $editionId): int
    {
        $image = imagecreatetruecolor(60, 90);
        $file = (string) tempnam(sys_get_temp_dir(), 'cov');
        imagepng($image, $file);
        $this->temp[] = $file;
        [$browser, $token] = $this->signedIn($email);
        $response = $this->request($browser, 'POST', "/editions/{$editionId}/covers", ['_csrf' => $token], [], [
            'cover' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
        ]);
        self::assertSame(303, $response->status());

        return (int) $this->pdo()->query("SELECT id FROM covers WHERE edition_id = {$editionId}")->fetchColumn();
    }

    private function id(string $table, string $name): int
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM {$table} WHERE name = :n");
        $stmt->execute(['n' => $name]);

        return (int) $stmt->fetchColumn();
    }

    private function services(): \DigitalLibrary\Core\Services
    {
        $services = new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2));
        $services->set(\PDO::class, $this->pdo());

        return $services;
    }

    private function visibilityOf(int $userId): \DigitalLibrary\Security\Visibility
    {
        $services = $this->services();

        return $services->visibility($services->users()->findById($userId));
    }

    /**
     * @return array{works: int, editions: int, copies: int, authors: int, my_copies: int}
     */
    private function stats(int $userId): array
    {
        return $this->services()->stats()->dashboard($this->visibilityOf($userId), $userId);
    }

    /**
     * @return array<string, string> table => checksum of its contents
     */
    private function snapshot(): array
    {
        $out = [];
        foreach (['works', 'editions', 'copies', 'loans', 'covers', 'collection_items', 'wishlist_items', 'audit_log'] as $table) {
            $out[$table] = md5((string) json_encode($this->pdo()->query("SELECT * FROM {$table} ORDER BY 1, 2")->fetchAll(\PDO::FETCH_NUM)));
        }

        return $out;
    }
}
