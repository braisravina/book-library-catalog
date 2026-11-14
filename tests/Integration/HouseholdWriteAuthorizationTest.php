<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use PDO;

/**
 * Household write authorization and workflow isolation (phase H3), with
 * the household feature enabled.
 *
 * Two households, Casa and Piso, with nothing in common but the shared
 * catalogue. Every test checks both sides of a rule: what the user may do,
 * and that an attempt across the boundary changes nothing in the database
 * and answers exactly like a record that does not exist.
 *
 * Piso's inventory carries the marker "zzinv" (inventory codes, notes,
 * locations, names), so any page can be searched for it.
 */
final class HouseholdWriteAuthorizationTest extends IntegrationTestCase
{
    private const INVENTORY = 'zzinv';

    private int $adminId;
    private int $anaId;
    private int $veraId;
    private int $beaId;
    private int $carlId;
    private int $doraId;
    private int $casa;
    private int $piso;
    private int $casaShelf;
    private int $pisoShelf;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $casaBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $pisoBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $sharedBook;
    private int $sharedPisoCopy;
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
        $this->doraId = $this->createUser('dora@example.org', Role::Member);
        $this->casa = $this->createHousehold('Casa', [$this->anaId, $this->veraId, $this->doraId]);
        $this->piso = $this->createHousehold('Zzinv Piso', [$this->beaId, $this->doraId]);
        $this->casaShelf = $this->location('Casa shelf', $this->casa);
        $this->pisoShelf = $this->location('Zzinv shelf', $this->piso);

        $this->casaBook = $this->makeBook($this->anaId, 'Casa Book', self::isbn(1), ['Casa Author'], [], [], $this->anaId, $this->casaShelf, $this->casa);
        $this->pisoBook = $this->makeBook($this->beaId, 'Piso Title', self::isbn(2), ['Piso Author'], [], [], $this->beaId, $this->pisoShelf, $this->piso);
        $this->sharedBook = $this->makeBook($this->anaId, 'Shared Classic', self::isbn(3), ['Shared Author'], [], [], $this->anaId, $this->casaShelf, $this->casa);
        $this->sharedPisoCopy = $this->makeCopy($this->sharedBook['edition_id'], $this->beaId, $this->piso, $this->pisoShelf);
        $this->pdo()->exec("UPDATE copies SET notes = 'zzinv private note', inventory_code = CONCAT('ZZINV-', id) WHERE household_id = {$this->piso}");

        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
    }

    protected function tearDown(): void
    {
        foreach ($this->temp as $file) {
            @unlink($file);
        }
    }

    // --- Shared bibliographic metadata --------------------------------------------

    /**
     * A member edits a work or edition they created only while no other
     * household holds it; afterwards it is an administrator's to change.
     */
    public function testCreatorsEditTheirRecordsOnlyWhileNoOtherHouseholdHoldsThem(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $own = $this->casaBook;
        $shared = $this->sharedBook;

        // Held by Casa only: Ana catalogued it and may change it.
        self::assertSame(200, $this->request($ana, 'GET', "/editions/{$own['edition_id']}/edit")->status());
        self::assertSame(200, $this->request($ana, 'GET', "/works/{$own['work_id']}/edit")->status());
        self::assertSame(303, $this->saveEdition($ana, $token, $own['edition_id'], 'Casa Book, revised')->status());
        self::assertSame(303, $this->saveWork($ana, $token, $own['work_id'], 'Casa Book, revised')->status());
        self::assertSame('Casa Book, revised', $this->value("SELECT title FROM editions WHERE id = {$own['edition_id']}"));
        self::assertSame('Casa Book, revised', $this->value("SELECT title FROM works WHERE id = {$own['work_id']}"));

        // Also held by Piso: she still sees it, with her own copy, but cannot change it.
        $page = $this->request($ana, 'GET', "/editions/{$shared['edition_id']}");
        self::assertSame(200, $page->status());
        self::assertStringNotContainsString("/editions/{$shared['edition_id']}/edit", $page->body(), 'No edit action is offered');
        self::assertStringNotContainsString("/works/{$shared['work_id']}/edit", $page->body());
        self::assertStringContainsString('part of the shared catalogue', $page->body());
        self::assertStringNotContainsString('action="/editions/' . $shared['edition_id'] . '/covers"', $page->body(), 'Covers follow the edition');
        $this->assertNoInventory($page, 'the shared edition');

        $before = $this->snapshot();
        foreach ([
            ['GET', "/editions/{$shared['edition_id']}/edit", []],
            ['GET', "/works/{$shared['work_id']}/edit", []],
            ['POST', "/editions/{$shared['edition_id']}", $this->editionBody($token, $shared['edition_id'], 'Hijacked')],
            ['POST', "/works/{$shared['work_id']}", $this->workBody($token, $shared['work_id'], 'Hijacked')],
            ['POST', "/editions/{$shared['edition_id']}/covers", ['_csrf' => $token]],
        ] as [$method, $path, $body]) {
            $response = $this->request($ana, $method, $path, $body);
            self::assertSame(403, $response->status(), "{$method} {$path}");
            $this->assertNoInventory($response, "{$method} {$path}");
            // The refusal is the ordinary "access denied" page: nothing about who holds the book.
            self::assertStringNotContainsString('household', strtolower(self::mainOf($response->body())), "{$method} {$path}");
        }
        self::assertSame($before, $this->snapshot(), 'Refused edits change nothing');

        // An administrator changes shared records.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $this->saveEdition($admin, $adminToken, $shared['edition_id'], 'Shared Classic (2nd)')->status());
        self::assertSame(303, $this->saveWork($admin, $adminToken, $shared['work_id'], 'Shared Classic (2nd)')->status());
        self::assertSame('Shared Classic (2nd)', $this->value("SELECT title FROM works WHERE id = {$shared['work_id']}"));

        // Seeing a record never grants editing: Dora reaches both households but catalogued nothing.
        [$dora, $doraToken] = $this->signedIn('dora@example.org');
        self::assertSame(200, $this->request($dora, 'GET', "/editions/{$own['edition_id']}")->status());
        self::assertSame(403, $this->request($dora, 'GET', "/editions/{$own['edition_id']}/edit")->status());
        self::assertSame(403, $this->saveEdition($dora, $doraToken, $own['edition_id'], 'Not mine')->status());
        self::assertSame(403, $this->saveWork($dora, $doraToken, $own['work_id'], 'Not mine')->status());
        // Viewers have no editing permission at all.
        [$vera, $veraToken] = $this->signedIn('vera@example.org');
        self::assertSame(403, $this->saveEdition($vera, $veraToken, $own['edition_id'], 'Viewer')->status());
        self::assertSame('Casa Book, revised', $this->value("SELECT title FROM editions WHERE id = {$own['edition_id']}"));
    }

    /**
     * A record is shared when a household the creator does not belong to
     * holds a copy. A creator who belongs to every household that holds
     * it keeps the right; so does one whose record nobody else holds any
     * more.
     */
    public function testTheRuleFollowsTheHoldingsAndTheMemberships(): void
    {
        // Dora belongs to Casa and Piso and catalogues a book held by both: still exclusive to her households.
        $book = $this->makeBook($this->doraId, 'Dora Book', self::isbn(7), [], [], [], $this->doraId, null, $this->casa);
        $pisoCopy = $this->makeCopy($book['edition_id'], $this->beaId, $this->piso);
        [$dora, $token] = $this->signedIn('dora@example.org');
        self::assertSame(303, $this->saveEdition($dora, $token, $book['edition_id'], 'Dora Book, 2nd')->status());

        // She leaves Piso: the same record is now held outside her households.
        $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$this->piso} AND user_id = {$this->doraId}");
        self::assertSame(403, $this->saveEdition($dora, $token, $book['edition_id'], 'Dora Book, 3rd')->status(), 'Applies to her next request, without signing in again');
        self::assertSame(403, $this->saveWork($dora, $token, $book['work_id'], 'Dora Book, 3rd')->status());

        // Piso's copy is removed: nobody else holds it, and it is hers again.
        $this->pdo()->exec("DELETE FROM copies WHERE id = {$pisoCopy}");
        self::assertSame(303, $this->saveEdition($dora, $token, $book['edition_id'], 'Dora Book, 3rd')->status());

        // A copy without a household (reachable by administrators only) counts as held elsewhere.
        $this->makeCopy($book['edition_id'], $this->adminId, 0);
        self::assertSame(403, $this->saveEdition($dora, $token, $book['edition_id'], 'Dora Book, 4th')->status());
        self::assertSame('Dora Book, 3rd', $this->value("SELECT title FROM editions WHERE id = {$book['edition_id']}"));

        // A work is shared as soon as one of its editions is: Ana's work gains an edition that only Piso holds.
        [$ana, $anaToken] = $this->signedIn('ana@example.org');
        self::assertSame(303, $this->saveWork($ana, $anaToken, $this->casaBook['work_id'], 'Casa Book')->status());
        $editionId = $this->services()->editions()->create($this->casaBook['work_id'], ['title' => 'Piso printing'], $this->beaId);
        self::assertSame(403, $this->saveWork($ana, $anaToken, $this->casaBook['work_id'], 'Casa Book again')->status(), 'An edition out of her view belongs to someone else');
        self::assertSame(303, $this->saveEdition($ana, $anaToken, $this->casaBook['edition_id'], 'Casa Book edition')->status(), 'Her own edition is still exclusive');
        $this->makeCopy($editionId, $this->beaId, $this->piso);
        self::assertSame(403, $this->saveWork($ana, $anaToken, $this->casaBook['work_id'], 'Casa Book again')->status());
    }

    /**
     * The record version protects against stale forms as before, and the
     * authorization is evaluated before it: a stale form never becomes a
     * way to learn about, or overwrite, a record that is no longer the
     * user's to change.
     */
    public function testStaleFormsAndOptimisticLocking(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $edition = $this->casaBook['edition_id'];
        $stale = $this->editionBody($token, $edition, 'From a stale form');
        self::assertSame(303, $this->saveEdition($ana, $token, $edition, 'First save')->status());

        $conflict = $this->request($ana, 'POST', "/editions/{$edition}", $stale);
        self::assertSame(409, $conflict->status());
        self::assertSame('First save', $this->value("SELECT title FROM editions WHERE id = {$edition}"));

        // Piso adds a copy while Ana's form is open: her save is refused (403), whatever version it carries.
        $form = $this->editionBody($token, $edition, 'After the form was opened');
        $this->makeCopy($edition, $this->beaId, $this->piso);
        self::assertSame(403, $this->request($ana, 'POST', "/editions/{$edition}", $form)->status());
        self::assertSame(403, $this->request($ana, 'POST', "/editions/{$edition}", $stale)->status(), 'Authorization comes before the version check');
        self::assertSame('First save', $this->value("SELECT title FROM editions WHERE id = {$edition}"));
    }

    public function testCoversFollowTheEditionsEditingRule(): void
    {
        self::$trustUploads = true;
        [$ana, $token] = $this->signedIn('ana@example.org');

        self::assertSame(303, $this->upload($ana, $token, $this->casaBook['edition_id'])->status());
        $cover = (int) $this->value("SELECT id FROM covers WHERE edition_id = {$this->casaBook['edition_id']}");
        self::assertGreaterThan(0, $cover);

        // Shared edition: neither upload nor delete, and no file is left behind.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $this->upload($admin, $adminToken, $this->sharedBook['edition_id'])->status());
        $sharedCover = (int) $this->value("SELECT id FROM covers WHERE edition_id = {$this->sharedBook['edition_id']}");
        $files = $this->coverFiles();
        self::assertSame(403, $this->upload($ana, $token, $this->sharedBook['edition_id'])->status());
        self::assertSame(403, $this->request($ana, 'POST', "/covers/{$sharedCover}/delete", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM covers WHERE edition_id = {$this->sharedBook['edition_id']}"));
        self::assertSame($files, $this->coverFiles(), 'A refused upload stores no file');

        // An edition of another household: 404, exactly like a missing one.
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        self::assertSame(404, $this->upload($bea, $beaToken, $this->casaBook['edition_id'])->status());
        self::assertSame(404, $this->request($bea, 'POST', "/covers/{$cover}/delete", ['_csrf' => $beaToken])->status());
        self::assertSame(404, $this->request($bea, 'POST', '/covers/999999/delete', ['_csrf' => $beaToken])->status());

        self::assertSame(303, $this->request($ana, 'POST', "/covers/{$cover}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM covers WHERE id = {$cover}"));
    }

    public function testAuthorsAndPublishersStayWithAdministrators(): void
    {
        $author = (int) $this->value("SELECT id FROM authors WHERE name = 'Casa Author'");
        [$ana, $token] = $this->signedIn('ana@example.org');
        $before = $this->snapshot();
        foreach (["/authors/{$author}", "/authors/{$author}/delete", "/authors/{$author}/merge"] as $path) {
            self::assertSame(403, $this->request($ana, 'POST', $path, ['_csrf' => $token, 'name' => 'Renamed', 'target_id' => '1'])->status(), $path);
        }
        self::assertSame(403, $this->request($ana, 'GET', "/authors/{$author}/edit")->status());
        self::assertSame($before, $this->snapshot());
    }

    // --- Physical copies ---------------------------------------------------------

    /**
     * Identifiers of another household's inventory open nothing: every
     * write is answered like a missing record and changes nothing.
     */
    public function testAnotherHouseholdsCopiesCannotBeReachedByIdentifier(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $before = $this->snapshot();
        $copyBody = ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'lost', 'row_version' => '1', 'notes' => 'taken over'];

        $missing = $this->request($ana, 'POST', '/copies/999999', $copyBody);
        foreach ([$this->pisoBook['copy_id'], $this->sharedPisoCopy] as $copy) {
            foreach ([
                ['POST', "/copies/{$copy}", $copyBody],
                ['POST', "/copies/{$copy}/delete", ['_csrf' => $token]],
                ['POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'name', 'borrower_name' => 'X', 'loaned_on' => '2026-01-05']],
                ['GET', "/copies/{$copy}/edit", []],
            ] as [$method, $path, $body]) {
                $response = $this->request($ana, $method, $path, $body);
                self::assertSame(404, $response->status(), "{$method} {$path}");
                $this->assertNoInventory($response, "{$method} {$path}");
            }
            self::assertSame(self::neutral($missing->body()), self::neutral($this->request($ana, 'POST', "/copies/{$copy}", $copyBody)->body()), 'The same page as for a copy that does not exist');
        }
        // An edition only Piso holds: no copy through its id.
        $response = $this->request($ana, 'POST', "/editions/{$this->pisoBook['edition_id']}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'available']);
        self::assertSame(404, $response->status(), 'An edition id never bypasses visibility');
        self::assertSame($before, $this->snapshot(), 'Nothing was written');

        // A household, an owner or a location that is not hers is refused like one that does not exist.
        $own = $this->casaBook['copy_id'];
        $body = fn (array $fields): array => $fields + ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'available', 'row_version' => $this->rowVersion('copies', $own)];
        $foreign = $this->request($ana, 'POST', "/copies/{$own}", $body(['household_id' => (string) $this->piso]));
        $unknown = $this->request($ana, 'POST', "/copies/{$own}", $body(['household_id' => '999999']));
        self::assertSame(422, $foreign->status());
        self::assertSame(self::neutral($unknown->body()), self::neutral($foreign->body()), 'Unknown and forbidden households are the same answer');
        $foreignLocation = $this->request($ana, 'POST', "/copies/{$own}", $body(['location_id' => (string) $this->pisoShelf]));
        $unknownLocation = $this->request($ana, 'POST', "/copies/{$own}", $body(['location_id' => '999999']));
        self::assertSame(422, $foreignLocation->status());
        self::assertSame(self::neutral($unknownLocation->body()), self::neutral($foreignLocation->body()));
        $this->assertNoInventory($foreignLocation, 'a location of another household');
        self::assertSame(403, $this->request($ana, 'POST', "/copies/{$own}", $body(['owner_user_id' => (string) $this->beaId]))->status(), 'Giving a copy away needs copy.manage_any');
        self::assertSame($before, $this->snapshot());

        // Membership gives no right to edit another member's copy.
        [$dora, $doraToken] = $this->signedIn('dora@example.org');
        self::assertSame(403, $this->request($dora, 'POST', "/copies/{$own}", ['_csrf' => $doraToken, 'owner_user_id' => (string) $this->doraId, 'status' => 'lost', 'row_version' => '1'])->status());
        self::assertSame(403, $this->request($dora, 'POST', "/copies/{$own}/delete", ['_csrf' => $doraToken])->status());
        self::assertSame($before, $this->snapshot());
    }

    public function testCopiesArePlacedOnlyInAuthorizedHouseholdsWithMatchingOwnerAndLocation(): void
    {
        [$dora, $token] = $this->signedIn('dora@example.org');
        $edition = $this->sharedBook['edition_id'];
        $post = fn (array $fields): Response => $this->request($dora, 'POST', "/editions/{$edition}/copies", $fields + ['_csrf' => $token, 'owner_user_id' => (string) $this->doraId, 'status' => 'available']);

        // Two memberships: the household must be chosen, and the location must belong to it.
        self::assertSame(422, $post([])->status());
        self::assertSame(422, $post(['household_id' => (string) $this->casa, 'location_id' => (string) $this->pisoShelf])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$this->doraId}"));
        self::assertSame(303, $post(['household_id' => (string) $this->piso, 'location_id' => (string) $this->pisoShelf])->status());
        $copy = (int) $this->value("SELECT id FROM copies WHERE owner_user_id = {$this->doraId}");
        self::assertSame((string) $this->piso, (string) $this->value("SELECT household_id FROM copies WHERE id = {$copy}"));

        // The form lists her locations by household, and nothing else.
        $form = $this->request($dora, 'GET', "/editions/{$edition}/copies/new")->body();
        self::assertStringContainsString('data-household-locations="household_id"', $form);
        self::assertStringContainsString('<optgroup label="Casa" data-household="' . $this->casa . '">', $form);
        self::assertStringContainsString('data-household="' . $this->piso . '">', $form);
        [$ana] = $this->signedIn('ana@example.org');
        $anaForm = $this->request($ana, 'GET', "/editions/{$edition}/copies/new");
        self::assertStringContainsString('Casa shelf', $anaForm->body());
        $this->assertNoInventory($anaForm, 'the copy form of a member of Casa');

        // Moving it to Casa: the location has to follow, and the move is audited.
        $move = fn (array $fields): Response => $this->request($dora, 'POST', "/copies/{$copy}", $fields + ['_csrf' => $token, 'owner_user_id' => (string) $this->doraId, 'status' => 'available', 'row_version' => $this->rowVersion('copies', $copy)]);
        self::assertSame(422, $move(['household_id' => (string) $this->casa, 'location_id' => (string) $this->pisoShelf])->status());
        self::assertSame(303, $move(['household_id' => (string) $this->casa, 'location_id' => (string) $this->casaShelf])->status());
        self::assertEquals(['from' => $this->piso, 'to' => $this->casa], $this->auditDetails('copy.updated')['household_changed']);

        // An administrator may place a copy anywhere, but its owner must be able to reach it.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        $adminPost = fn (int $owner, int $household): Response => $this->request($admin, 'POST', "/editions/{$edition}/copies", ['_csrf' => $adminToken, 'owner_user_id' => (string) $owner, 'status' => 'available', 'household_id' => (string) $household]);
        $refused = $adminPost($this->anaId, $this->piso);
        self::assertSame(422, $refused->status());
        self::assertStringContainsString('not a member of this household', $refused->body());
        self::assertSame(303, $adminPost($this->anaId, $this->casa)->status());

        // Viewers cannot add copies at all.
        [$vera, $veraToken] = $this->signedIn('vera@example.org');
        self::assertSame(403, $this->request($vera, 'POST', "/editions/{$edition}/copies", ['_csrf' => $veraToken, 'owner_user_id' => (string) $this->veraId, 'status' => 'available'])->status());
    }

    /**
     * Inventory codes are unique within a household, not across the
     * installation, and a refusal never points at another household.
     */
    public function testInventoryCodesAreUniquePerHousehold(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $pisoCode = (string) $this->value("SELECT inventory_code FROM copies WHERE id = {$this->pisoBook['copy_id']}");
        $own = $this->casaBook['copy_id'];
        $save = fn (int $copy, string $code): Response => $this->request($ana, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'available', 'inventory_code' => $code, 'row_version' => $this->rowVersion('copies', $copy)]);

        // The code Piso uses is free in Casa: no error, so no hint that it exists elsewhere.
        self::assertSame(303, $save($own, $pisoCode)->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM copies WHERE inventory_code = '{$pisoCode}'"));

        // Within Casa it is taken now.
        $second = $this->sharedBook['copy_id'];
        $taken = $save($second, $pisoCode);
        self::assertSame(422, $taken->status());
        self::assertStringContainsString('already in use', $taken->body());
        $this->assertNoInventory($taken, 'the "code in use" error', [$pisoCode]);
        self::assertSame(303, $save($second, 'CASA-2')->status());

        // New copies follow the same rule, and Piso's member is refused its own duplicate only.
        $add = $this->request($ana, 'POST', "/editions/{$this->casaBook['edition_id']}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'available', 'inventory_code' => 'CASA-2']);
        self::assertSame(422, $add->status());
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        $beaAdd = fn (string $code): Response => $this->request($bea, 'POST', "/editions/{$this->pisoBook['edition_id']}/copies", ['_csrf' => $beaToken, 'owner_user_id' => (string) $this->beaId, 'status' => 'available', 'inventory_code' => $code]);
        self::assertSame(303, $beaAdd('CASA-2')->status(), 'Casa\'s code is free in Piso');
        self::assertSame(422, $beaAdd($pisoCode)->status());

        // Copies without a household form one scope of their own, enforced by the database (never by NULLs).
        $first = $this->makeCopy($this->casaBook['edition_id'], $this->adminId, 0, null, 'NOHOME-1');
        try {
            $this->makeCopy($this->casaBook['edition_id'], $this->adminId, 0, null, 'NOHOME-1');
            self::fail('Two copies without a household share an inventory code');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
            self::assertStringContainsString('uq_copies_scope_inventory_code', $e->getMessage());
        }
        self::assertSame('0', (string) $this->value("SELECT inventory_scope_id FROM copies WHERE id = {$first}"));
        // Moving a copy into a household where its code is taken is refused as well.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        $this->pdo()->exec("UPDATE copies SET inventory_code = 'CASA-2' WHERE id = {$first}");
        $moved = $this->request($admin, 'POST', "/copies/{$first}", ['_csrf' => $adminToken, 'owner_user_id' => (string) $this->adminId, 'status' => 'available', 'inventory_code' => 'CASA-2', 'household_id' => (string) $this->casa, 'row_version' => $this->rowVersion('copies', $first)]);
        self::assertSame(422, $moved->status());
        self::assertNull($this->value("SELECT household_id FROM copies WHERE id = {$first}"));
    }

    public function testACopyOnLoanCannotChangeHousehold(): void
    {
        [$dora, $token] = $this->signedIn('dora@example.org');
        $copy = $this->makeCopy($this->sharedBook['edition_id'], $this->doraId, $this->casa);
        self::assertSame(303, $this->request($dora, 'POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'name', 'borrower_name' => 'Friend', 'loaned_on' => '2026-01-05'])->status());

        $move = fn (): Response => $this->request($dora, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->doraId, 'status' => 'on_loan', 'household_id' => (string) $this->piso, 'row_version' => $this->rowVersion('copies', $copy)]);
        $refused = $move();
        self::assertSame(422, $refused->status());
        self::assertStringContainsString('Record its return before moving it', $refused->body());
        self::assertSame((string) $this->casa, (string) $this->value("SELECT household_id FROM copies WHERE id = {$copy}"));

        // Once returned it can move; the loan history goes with the copy.
        $loan = (int) $this->value("SELECT id FROM loans WHERE copy_id = {$copy}");
        self::assertSame(303, $this->request($dora, 'POST', "/loans/{$loan}/return", ['_csrf' => $token])->status());
        self::assertSame(303, $this->request($dora, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->doraId, 'status' => 'available', 'household_id' => (string) $this->piso, 'row_version' => $this->rowVersion('copies', $copy)])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$copy}"));
        // A copy with history is withdrawn, never deleted.
        self::assertSame(303, $this->request($dora, 'POST', "/copies/{$copy}/delete", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE id = {$copy}"));
    }

    // --- Add a copy by ISBN ------------------------------------------------------

    /**
     * A book that only another household holds is not entered again: the
     * user adds their own copy to the shared edition, found by its ISBN.
     */
    public function testAddingACopyOfABookThatAnotherHouseholdHolds(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $isbn = self::isbn(2);
        $edition = $this->pisoBook['edition_id'];
        self::assertSame(404, $this->request($ana, 'GET', "/editions/{$edition}")->status(), 'Not part of her library');

        // The ISBN step says it is catalogued and offers "add my copy", by ISBN.
        $notice = $this->request($ana, 'GET', "/books/new?isbn={$isbn}");
        self::assertStringContainsString('Piso Title', $notice->body());
        self::assertStringContainsString('/books/copies/new?isbn=' . $isbn, $notice->body());
        self::assertStringNotContainsString("/editions/{$edition}", $notice->body(), 'No link to, or id of, the record');
        $this->assertNoInventory($notice, 'the duplicate notice');

        // The form shows the shared description only.
        $form = $this->request($ana, 'GET', "/books/copies/new?isbn={$isbn}");
        self::assertSame(200, $form->status());
        self::assertStringContainsString('Piso Title', $form->body());
        self::assertStringContainsString('name="isbn" value="' . $isbn . '"', $form->body());
        self::assertStringContainsString('action="/books/copies"', $form->body());
        self::assertStringNotContainsString("/editions/{$edition}", $form->body());
        self::assertStringContainsString('Casa shelf', $form->body());
        $this->assertNoInventory($form, 'the add-by-ISBN form');

        // Rules are those of any copy: Piso's household or location cannot be used.
        $post = fn (array $fields): Response => $this->request($ana, 'POST', '/books/copies', $fields + ['_csrf' => $token, 'isbn' => $isbn, 'owner_user_id' => (string) $this->anaId, 'status' => 'available']);
        $editions = $this->countRows('SELECT COUNT(*) FROM editions');
        foreach ([['household_id' => (string) $this->piso], ['location_id' => (string) $this->pisoShelf]] as $fields) {
            $refused = $post($fields);
            self::assertSame(422, $refused->status());
            $this->assertNoInventory($refused, 'a refused add-by-ISBN');
        }
        self::assertSame(403, $post(['owner_user_id' => (string) $this->beaId])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE edition_id = {$edition}"));

        $response = $post(['location_id' => (string) $this->casaShelf, 'inventory_code' => 'CASA-9']);
        self::assertSame(303, $response->status());
        self::assertSame("/editions/{$edition}", $response->header('Location'));
        $copy = $this->pdo()->query("SELECT * FROM copies WHERE edition_id = {$edition} AND owner_user_id = {$this->anaId}")->fetch(PDO::FETCH_ASSOC);
        self::assertSame([(string) $this->casa, (string) $this->casaShelf], [(string) $copy['household_id'], (string) $copy['location_id']]);
        self::assertSame($editions, $this->countRows('SELECT COUNT(*) FROM editions'), 'The shared edition is reused, not duplicated');
        self::assertSame('isbn', $this->auditDetails('copy.created')['via']);

        // She now sees the edition, with her copy and none of Piso's, and cannot change its description.
        $page = $this->request($ana, 'GET', "/editions/{$edition}");
        self::assertSame(200, $page->status());
        self::assertStringContainsString("/copies/{$copy['id']}", $page->body());
        self::assertStringNotContainsString("/copies/{$this->pisoBook['copy_id']}", $page->body());
        $this->assertNoInventory($page, 'the adopted edition');
        self::assertSame(403, $this->request($ana, 'GET', "/editions/{$edition}/edit")->status());

        // The creator, in turn, can no longer change a record that Casa holds too.
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        self::assertSame(403, $this->saveEdition($bea, $beaToken, $edition, 'Piso Title, changed')->status());
        self::assertSame('Piso Title', $this->value("SELECT title FROM editions WHERE id = {$edition}"));
    }

    public function testAddByIsbnRespectsRolesMembershipsAndUnknownIsbns(): void
    {
        $isbn = self::isbn(2);
        $before = $this->snapshot();

        // Viewers cannot add copies; a member without a household has nowhere to put one.
        [$vera, $veraToken] = $this->signedIn('vera@example.org');
        self::assertSame(403, $this->request($vera, 'GET', "/books/copies/new?isbn={$isbn}")->status());
        self::assertSame(403, $this->request($vera, 'POST', '/books/copies', ['_csrf' => $veraToken, 'isbn' => $isbn, 'owner_user_id' => (string) $this->veraId, 'status' => 'available'])->status());
        [$carl, $carlToken] = $this->signedIn('carl@example.org');
        $notice = $this->request($carl, 'GET', "/books/new?isbn={$isbn}")->body();
        self::assertStringNotContainsString('/books/copies/new', $notice);
        self::assertStringContainsString('Ask an administrator', $notice);
        $refused = $this->request($carl, 'POST', '/books/copies', ['_csrf' => $carlToken, 'isbn' => $isbn, 'owner_user_id' => (string) $this->carlId, 'status' => 'available']);
        self::assertSame(422, $refused->status());
        $this->assertNoInventory($refused, 'a member without households');

        // Not an ISBN, or not catalogued: nothing to add a copy to.
        [$ana, $token] = $this->signedIn('ana@example.org');
        foreach (['', 'nonsense', '9780000000000'] as $bad) {
            self::assertSame(404, $this->request($ana, 'POST', '/books/copies', ['_csrf' => $token, 'isbn' => $bad, 'owner_user_id' => (string) $this->anaId, 'status' => 'available'])->status(), $bad);
        }
        self::assertSame(404, $this->request($ana, 'POST', '/books/copies', ['_csrf' => $token, 'isbn' => self::isbn(99), 'owner_user_id' => (string) $this->anaId, 'status' => 'available'])->status());
        self::assertSame(303, $this->request($ana, 'GET', '/books/copies/new?isbn=' . self::isbn(99))->status(), 'Not catalogued: the ordinary "add a book" flow');
        // An edition id in the request is ignored: only the ISBN counts.
        self::assertSame(404, $this->request($ana, 'POST', '/books/copies', ['_csrf' => $token, 'edition_id' => (string) $this->pisoBook['edition_id'], 'owner_user_id' => (string) $this->anaId, 'status' => 'available'])->status());
        self::assertSame($before, $this->snapshot());

        // A visible edition goes to its ordinary copy form.
        $visible = $this->request($ana, 'GET', '/books/copies/new?isbn=' . self::isbn(1));
        self::assertSame("/editions/{$this->casaBook['edition_id']}/copies/new", $visible->header('Location'));
        // The existing ISBN and lookup flow is unchanged for a new ISBN.
        self::assertSame(200, $this->request($ana, 'GET', '/books/new?isbn=' . self::isbn(99) . '&manual=1')->status());
        $created = $this->request($ana, 'POST', '/books', ['_csrf' => $token, 'title' => 'Brand new', 'isbn' => self::isbn(99), 'add_copy' => '1', 'copy_location_id' => (string) $this->casaShelf]);
        self::assertSame(303, $created->status());
        self::assertSame((string) $this->casa, (string) $this->value('SELECT household_id FROM copies ORDER BY id DESC LIMIT 1'));
        // Entering an ISBN another household holds again is answered with the notice, not a second edition.
        $again = $this->request($ana, 'POST', '/books', ['_csrf' => $token, 'title' => 'Piso Title', 'isbn' => $isbn]);
        self::assertSame(409, $again->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM editions WHERE isbn_13 = '{$isbn}'"));
        $this->assertNoInventory($again, 'the duplicate answer on POST');
    }

    // --- Loans ----------------------------------------------------------------------

    public function testBorrowersWithAnAccountMustBelongToTheCopysHousehold(): void
    {
        $copy = $this->casaBook['copy_id'];
        [$ana, $token] = $this->signedIn('ana@example.org');
        $lend = fn (Session $browser, string $csrf, array $fields): Response => $this->request($browser, 'POST', "/copies/{$copy}/lend", $fields + ['_csrf' => $csrf, 'loaned_on' => '2026-01-05']);
        $toUser = static fn (int $id): array => ['borrower_type' => 'user', 'borrower_user_id' => (string) $id];

        foreach ([$this->beaId, $this->carlId, $this->adminId, 999999] as $outsider) {
            self::assertSame(422, $lend($ana, $token, $toUser($outsider))->status(), (string) $outsider);
        }
        // A deactivated member cannot borrow either.
        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->veraId}");
        self::assertSame(422, $lend($ana, $token, $toUser($this->veraId))->status());
        $this->pdo()->exec("UPDATE users SET is_active = 1 WHERE id = {$this->veraId}");
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));

        // Administrators are bound by the same rule; the free-text borrower always works.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(422, $lend($admin, $adminToken, $toUser($this->beaId))->status());
        self::assertSame(422, $lend($admin, $adminToken, $toUser($this->carlId))->status());
        self::assertSame(303, $lend($admin, $adminToken, $toUser($this->doraId))->status());
        $loan = (int) $this->value("SELECT id FROM loans WHERE copy_id = {$copy}");
        self::assertSame(303, $this->request($ana, 'POST', "/loans/{$loan}/return", ['_csrf' => $token])->status());
        self::assertSame(303, $lend($ana, $token, ['borrower_type' => 'name', 'borrower_name' => 'A neighbour'])->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$copy}"));

        // A copy without a household can only be lent to a free-text borrower.
        $unassigned = $this->makeCopy($this->casaBook['edition_id'], $this->adminId, 0);
        $response = $this->request($admin, 'POST', "/copies/{$unassigned}/lend", ['_csrf' => $adminToken, 'borrower_type' => 'user', 'borrower_user_id' => (string) $this->anaId, 'loaned_on' => '2026-01-05']);
        self::assertSame(422, $response->status());
    }

    /**
     * After a membership is removed the former member can neither lend
     * nor take back that household's copies, and the loans that exist
     * stay as they are.
     */
    public function testRevokedMembersCanNoLongerLendOrReturn(): void
    {
        [$dora, $token] = $this->signedIn('dora@example.org');
        $copy = $this->makeCopy($this->pisoBook['edition_id'], $this->doraId, $this->piso);
        self::assertSame(303, $this->request($dora, 'POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'user', 'borrower_user_id' => (string) $this->beaId, 'loaned_on' => '2026-01-05'])->status());
        $loan = (int) $this->value("SELECT id FROM loans WHERE copy_id = {$copy}");
        $history = $this->pdo()->query("SELECT * FROM loans WHERE id = {$loan}")->fetch(PDO::FETCH_ASSOC);

        // The administrator removes her from Piso (through the application).
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $this->request($admin, 'POST', "/admin/households/{$this->piso}/members/{$this->doraId}/remove", ['_csrf' => $adminToken])->status());

        foreach ([
            ['POST', "/loans/{$loan}/return", ['_csrf' => $token]],
            ['GET', "/loans/{$loan}/return", []],
            ['POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'name', 'borrower_name' => 'X', 'loaned_on' => '2026-01-05']],
            ['POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->doraId, 'status' => 'lost', 'row_version' => $this->rowVersion('copies', $copy)]],
            ['POST', "/copies/{$copy}/delete", ['_csrf' => $token]],
        ] as [$method, $path, $body]) {
            self::assertSame(404, $this->request($dora, $method, $path, $body)->status(), "{$method} {$path}");
        }
        self::assertSame($history, $this->pdo()->query("SELECT * FROM loans WHERE id = {$loan}")->fetch(PDO::FETCH_ASSOC), 'The loan is untouched');
        self::assertSame('on_loan', $this->value("SELECT status FROM copies WHERE id = {$copy}"));

        // The service refuses on its own, too (the controller is not the only check).
        $services = $this->services();
        try {
            $services->loanService()->returnLoan($services->users()->findById($this->doraId), $loan);
            self::fail('A former member returned a loan');
        } catch (\DigitalLibrary\Loans\LoanException $e) {
            self::assertSame('loans.error.copy_not_found', $e->getMessage());
        }

        // The borrower keeps seeing their loan; an administrator can close it.
        [$bea] = $this->signedIn('zzinv-bea@example.org');
        self::assertStringContainsString('Piso Title', $this->request($bea, 'GET', '/loans?scope=borrowed')->body());
        self::assertSame(303, $this->request($admin, 'POST', "/loans/{$loan}/return", ['_csrf' => $adminToken])->status());
        self::assertNotNull($this->value("SELECT returned_on FROM loans WHERE id = {$loan}"));
    }

    // --- Collections -----------------------------------------------------------------

    public function testSharedCollectionsBelongToOneExplicitlyChosenHousehold(): void
    {
        [$dora, $token] = $this->signedIn('dora@example.org');
        $create = fn (Session $browser, string $csrf, array $fields): Response => $this->request($browser, 'POST', '/collections', $fields + ['_csrf' => $csrf, 'description' => '']);

        // The form offers her own households only.
        $form = $this->request($dora, 'GET', '/collections/new')->body();
        self::assertStringContainsString('name="household_id"', $form);
        self::assertStringContainsString('>Casa<', $form);

        // Shared needs an explicit household when she has several; private never has one.
        self::assertSame(422, $create($dora, $token, ['name' => 'Picks', 'visibility' => 'shared'])->status());
        self::assertSame(303, $create($dora, $token, ['name' => 'Mine', 'visibility' => 'private', 'household_id' => (string) $this->casa])->status());
        self::assertNull($this->value("SELECT household_id FROM collections WHERE name = 'Mine'"), 'A private collection is not tied to a household');
        self::assertSame(303, $create($dora, $token, ['name' => 'Picks', 'visibility' => 'shared', 'household_id' => (string) $this->piso])->status());
        $picks = (int) $this->value("SELECT id FROM collections WHERE name = 'Picks'");
        self::assertSame((string) $this->piso, (string) $this->value("SELECT household_id FROM collections WHERE id = {$picks}"));
        self::assertEquals($this->piso, $this->auditDetails('collection.created')['household_id']);

        // A household that is not hers, and one that does not exist, are the same refusal.
        [$ana, $anaToken] = $this->signedIn('ana@example.org');
        $foreign = $create($ana, $anaToken, ['name' => 'Sneaky', 'visibility' => 'shared', 'household_id' => (string) $this->piso]);
        $unknown = $create($ana, $anaToken, ['name' => 'Sneaky', 'visibility' => 'shared', 'household_id' => '999999']);
        self::assertSame(422, $foreign->status());
        self::assertSame(self::neutral($unknown->body()), self::neutral($foreign->body()));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM collections WHERE name = 'Sneaky'"));
        // With one membership the household is used without asking.
        self::assertSame(303, $create($ana, $anaToken, ['name' => 'Casa picks', 'visibility' => 'shared'])->status());
        $casaPicks = (int) $this->value("SELECT id FROM collections WHERE name = 'Casa picks'");
        self::assertSame((string) $this->casa, (string) $this->value("SELECT household_id FROM collections WHERE id = {$casaPicks}"));

        // Seen by the members of that household only: not by Casa-only users, not through the list, not by URL.
        foreach (['ana@example.org', 'vera@example.org', 'carl@example.org'] as $email) {
            [$browser] = $this->signedIn($email);
            self::assertSame(404, $this->request($browser, 'GET', "/collections/{$picks}")->status(), $email);
            $list = $this->request($browser, 'GET', '/collections?scope=shared')->body();
            self::assertStringNotContainsString('Picks</a>', str_replace('Casa picks</a>', '', $list), $email);
        }
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        self::assertSame(200, $this->request($bea, 'GET', "/collections/{$picks}")->status());
        self::assertStringContainsString('Picks', $this->request($bea, 'GET', '/collections?scope=shared')->body());
        self::assertSame(404, $this->request($bea, 'GET', "/collections/{$casaPicks}")->status());
        // Seeing is not managing, and the totals count only what is listed.
        self::assertSame(403, $this->request($bea, 'POST', "/collections/{$picks}", ['_csrf' => $beaToken, 'name' => 'Taken', 'visibility' => 'shared'])->status());
        self::assertSame(404, $this->request($bea, 'POST', "/collections/{$casaPicks}", ['_csrf' => $beaToken, 'name' => 'Taken', 'visibility' => 'shared'])->status());
        self::assertSame(404, $this->request($bea, 'POST', "/collections/{$casaPicks}/delete", ['_csrf' => $beaToken, 'confirm' => '1'])->status());
        $rows = $this->services()->collections()->list('shared', $this->beaId, 1, $this->visibilityOf($this->beaId));
        self::assertSame([1, 1], [count($rows['rows']), $rows['total']], 'The total does not count collections shared with other households');

        // The edition page lists only collections the viewer may see.
        $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES ({$picks}, {$this->sharedBook['edition_id']}, 1, UTC_TIMESTAMP()), ({$casaPicks}, {$this->sharedBook['edition_id']}, 1, UTC_TIMESTAMP())");
        $page = $this->request($ana, 'GET', "/editions/{$this->sharedBook['edition_id']}")->body();
        self::assertStringContainsString("/collections/{$casaPicks}", $page);
        self::assertStringNotContainsString("/collections/{$picks}", $page);

        // An administrator may share with any household; nothing ever becomes shared by itself.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $this->request($admin, 'POST', "/collections/{$picks}", ['_csrf' => $adminToken, 'name' => 'Picks', 'visibility' => 'shared', 'household_id' => (string) $this->casa])->status());
        self::assertEquals(['from' => $this->piso, 'to' => $this->casa], $this->auditDetails('collection.updated')['household_changed']);
        self::assertSame(200, $this->request($ana, 'GET', "/collections/{$picks}")->status());
        self::assertSame('private', $this->value("SELECT visibility FROM collections WHERE name = 'Mine'"));
    }

    public function testCollectionItemsReorderingAndDirectUrlsRespectVisibility(): void
    {
        // Shared with Casa by Dora; it holds one book of each household and the shared one.
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, household_id, created_at, updated_at) VALUES ({$this->doraId}, 'Mixed', 'shared', {$this->casa}, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $mixed = (int) $this->pdo()->lastInsertId();
        $piso = $this->pisoBook['edition_id'];
        $casa = $this->casaBook['edition_id'];
        $shared = $this->sharedBook['edition_id'];
        foreach ([$casa, $piso, $shared] as $position => $edition) {
            $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES ({$mixed}, {$edition}, {$position} + 1, UTC_TIMESTAMP())");
        }
        // Ana's own private collection.
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES ({$this->anaId}, 'Ana list', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $own = (int) $this->pdo()->lastInsertId();

        // A member of Casa sees the collection without Piso's book, and it cannot be added to her own list.
        [$ana, $token] = $this->signedIn('ana@example.org');
        $page = $this->request($ana, 'GET', "/collections/{$mixed}");
        self::assertStringContainsString('2 books', $page->body());
        self::assertStringNotContainsString('Piso Title', $page->body());
        $this->assertNoInventory($page, 'a shared collection');
        $before = $this->snapshot();
        $probe = $this->request($ana, 'POST', "/collections/{$own}/items", ['_csrf' => $token, 'edition_id' => (string) $piso]);
        $missing = $this->request($ana, 'POST', "/collections/{$own}/items", ['_csrf' => $token, 'edition_id' => '999999']);
        self::assertSame(404, $probe->status());
        self::assertSame(self::neutral($missing->body()), self::neutral($probe->body()), 'An edition out of view is the same answer as a missing one');
        self::assertSame(404, $this->request($ana, 'POST', '/collections/add-item', ['_csrf' => $token, 'collection_id' => (string) $own, 'edition_id' => (string) $piso])->status());
        // She sees Dora's collection but does not manage it.
        foreach (["/collections/{$mixed}/items/{$casa}/remove", "/collections/{$mixed}/items/{$casa}/move", "/collections/{$mixed}/items"] as $path) {
            self::assertSame(403, $this->request($ana, 'POST', $path, ['_csrf' => $token, 'direction' => 'down', 'edition_id' => (string) $casa])->status(), $path);
        }
        self::assertSame($before, $this->snapshot());
        self::assertSame(303, $this->request($ana, 'POST', "/collections/{$own}/items", ['_csrf' => $token, 'edition_id' => (string) $casa])->status());

        // Dora leaves Piso: Piso's book is out of her view, in her own collection too.
        $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$this->piso} AND user_id = {$this->doraId}");
        [$dora, $doraToken] = $this->signedIn('dora@example.org');
        self::assertStringContainsString('2 books', $this->request($dora, 'GET', "/collections/{$mixed}")->body());
        $order = fn (): array => array_map('intval', $this->pdo()->query("SELECT edition_id FROM collection_items WHERE collection_id = {$mixed} ORDER BY position, edition_id")->fetchAll(PDO::FETCH_COLUMN));
        // An item she cannot see is "not in the collection", for moving and for removing.
        $notIn = $this->request($dora, 'POST', "/collections/{$mixed}/items/999999/move", ['_csrf' => $doraToken, 'direction' => 'up']);
        foreach (['move', 'remove'] as $action) {
            $response = $this->request($dora, 'POST', "/collections/{$mixed}/items/{$piso}/{$action}", ['_csrf' => $doraToken, 'direction' => 'up']);
            self::assertSame(404, $response->status(), $action);
            self::assertSame(self::neutral($notIn->body()), self::neutral($response->body()), $action);
        }
        self::assertSame([$casa, $piso, $shared], $order());

        // Reordering works on what she sees; the hidden item keeps its place.
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$mixed}/items/{$shared}/move", ['_csrf' => $doraToken, 'direction' => 'up'])->status());
        self::assertSame([$shared, $piso, $casa], $order(), 'The two visible books swapped; the hidden one stayed second');
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$mixed}/items/{$shared}/move", ['_csrf' => $doraToken, 'direction' => 'bottom'])->status());
        self::assertSame([$casa, $piso, $shared], $order());
        self::assertSame([1, 2, 3], array_map('intval', $this->pdo()->query("SELECT position FROM collection_items WHERE collection_id = {$mixed} ORDER BY position")->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$mixed}/items/{$casa}/remove", ['_csrf' => $doraToken])->status());
        self::assertSame([$piso, $shared], $order());

        // An administrator sees and orders everything.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertStringContainsString('Piso Title', $this->request($admin, 'GET', "/collections/{$mixed}")->body());
        self::assertSame(303, $this->request($admin, 'POST', "/collections/{$mixed}/items/{$piso}/move", ['_csrf' => $adminToken, 'direction' => 'down'])->status());
        self::assertSame([$shared, $piso], $order());
    }

    public function testAnOwnerWhoLeftTheHouseholdMustRehomeTheirSharedCollection(): void
    {
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, household_id, created_at, updated_at) VALUES ({$this->doraId}, 'Piso shelf', 'shared', {$this->piso}, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $id = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$this->piso} AND user_id = {$this->doraId}");
        [$dora, $token] = $this->signedIn('dora@example.org');

        // Still hers to open, but she cannot change what Piso sees.
        self::assertSame(200, $this->request($dora, 'GET', "/collections/{$id}")->status());
        $add = $this->request($dora, 'POST', "/collections/{$id}/items", ['_csrf' => $token, 'edition_id' => (string) $this->casaBook['edition_id']]);
        self::assertSame(303, $add->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$id}"));
        self::assertStringContainsString('no longer a member', $this->request($dora, 'GET', "/editions/{$this->casaBook['edition_id']}")->body());
        // Keeping it shared with Piso is not possible; with her only household it is.
        self::assertSame(422, $this->request($dora, 'POST', "/collections/{$id}", ['_csrf' => $token, 'name' => 'Piso shelf', 'visibility' => 'shared', 'household_id' => (string) $this->piso])->status());
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$id}", ['_csrf' => $token, 'name' => 'Casa shelf', 'visibility' => 'shared'])->status());
        self::assertSame((string) $this->casa, (string) $this->value("SELECT household_id FROM collections WHERE id = {$id}"));
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$id}/items", ['_csrf' => $token, 'edition_id' => (string) $this->casaBook['edition_id']])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM collection_items WHERE collection_id = {$id}"));
        // Making it private clears the household.
        self::assertSame(303, $this->request($dora, 'POST', "/collections/{$id}", ['_csrf' => $token, 'name' => 'Casa shelf', 'visibility' => 'private'])->status());
        self::assertNull($this->value("SELECT household_id FROM collections WHERE id = {$id}"));
    }

    // --- Wishlists ---------------------------------------------------------------------

    public function testWishlistLinksOnlyToRecordsTheUserMayReference(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $wish = fn (array $fields): Response => $this->request($ana, 'POST', '/wishlist', $fields + ['_csrf' => $token, 'title' => 'Wanted', 'author' => '', 'isbn' => '', 'priority' => 'normal', 'notes' => '']);
        $link = fn (int $id): ?string => ($v = $this->value("SELECT edition_id FROM wishlist_items WHERE id = {$id}")) === null ? null : (string) $v;

        // An ISBN that only Piso holds: the entry is kept, with the ISBN, but is not linked.
        self::assertSame(303, $wish(['title' => 'Piso by ISBN', 'isbn' => self::isbn(2)])->status());
        $byIsbn = (int) $this->value("SELECT id FROM wishlist_items WHERE title = 'Piso by ISBN'");
        self::assertNull($link($byIsbn));
        self::assertSame(self::isbn(2), $this->value("SELECT isbn_13 FROM wishlist_items WHERE id = {$byIsbn}"));
        // A visible ISBN is linked as before.
        self::assertSame(303, $wish(['title' => 'Casa by ISBN', 'isbn' => self::isbn(1)])->status());
        self::assertSame((string) $this->casaBook['edition_id'], $link((int) $this->value("SELECT id FROM wishlist_items WHERE title = 'Casa by ISBN'")));
        // An edition id of another household opens nothing.
        self::assertSame(404, $wish(['title' => 'By id', 'edition_id' => (string) $this->pisoBook['edition_id']])->status());
        self::assertSame(404, $this->request($ana, 'GET', "/wishlist/new?edition={$this->pisoBook['edition_id']}")->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM wishlist_items WHERE title = 'By id'"));
        $list = $this->request($ana, 'GET', '/wishlist');
        self::assertStringNotContainsString("/editions/{$this->pisoBook['edition_id']}", $list->body());
        $this->assertNoInventory($list, 'the wishlist');

        // Once she holds the book herself, saving the entry links it.
        $save = fn (int $id, array $fields): Response => $this->request($ana, 'POST', "/wishlist/{$id}", $fields + ['_csrf' => $token, 'title' => 'Piso by ISBN', 'author' => '', 'isbn' => self::isbn(2), 'priority' => 'normal', 'notes' => '']);
        $copy = $this->makeCopy($this->pisoBook['edition_id'], $this->anaId, $this->casa);
        self::assertSame(303, $save($byIsbn, [])->status());
        self::assertSame((string) $this->pisoBook['edition_id'], $link($byIsbn));

        // And when it leaves her view again, the next save drops the link (it is never shown meanwhile).
        $this->pdo()->exec("DELETE FROM copies WHERE id = {$copy}");
        self::assertStringNotContainsString("/editions/{$this->pisoBook['edition_id']}", $this->request($ana, 'GET', '/wishlist')->body());
        self::assertSame(303, $save($byIsbn, ['notes' => 'still wanted'])->status());
        self::assertNull($link($byIsbn));
        self::assertSame('still wanted', $this->value("SELECT notes FROM wishlist_items WHERE id = {$byIsbn}"));

        // Existing behaviour: status changes, duplicates and other users' entries.
        self::assertSame(303, $this->request($ana, 'POST', "/wishlist/{$byIsbn}/status", ['_csrf' => $token, 'status' => 'acquired'])->status());
        self::assertSame('acquired', $this->value("SELECT status FROM wishlist_items WHERE id = {$byIsbn}"));
        self::assertSame(409, $wish(['title' => 'Again', 'isbn' => self::isbn(1)])->status(), 'A wanted ISBN is not entered twice');
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        self::assertSame(404, $this->request($bea, 'POST', "/wishlist/{$byIsbn}", ['_csrf' => $beaToken, 'title' => 'Taken', 'priority' => 'normal'])->status());
        self::assertSame(404, $this->request($bea, 'POST', "/wishlist/{$byIsbn}/status", ['_csrf' => $beaToken, 'status' => 'wanted'])->status());
    }

    // --- Locations -----------------------------------------------------------------------

    public function testEveryHouseholdHasItsOwnLocationTree(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $post = fn (string $path, array $fields): Response => $this->request($admin, 'POST', $path, $fields + ['_csrf' => $token, 'kind' => 'room', 'description' => '']);
        $household = fn (int $id): ?string => ($v = $this->value("SELECT household_id FROM locations WHERE id = {$id}")) === null ? null : (string) $v;
        $id = fn (string $name, int $householdId): int => (int) $this->value("SELECT id FROM locations WHERE name = '{$name}' AND household_id = {$householdId}");

        // A root needs an explicit household; unknown ones are refused.
        self::assertSame(422, $post('/admin/locations', ['name' => 'Study'])->status());
        self::assertSame(422, $post('/admin/locations', ['name' => 'Study', 'household_id' => '999999'])->status());
        self::assertSame(303, $post('/admin/locations', ['name' => 'Study', 'household_id' => (string) $this->casa])->status());
        // The same root name in another household is a different location.
        self::assertSame(303, $post('/admin/locations', ['name' => 'Study', 'household_id' => (string) $this->piso])->status());
        self::assertSame(422, $post('/admin/locations', ['name' => 'Study', 'household_id' => (string) $this->casa])->status(), 'Unique among the roots of one household');
        $casaStudy = $id('Study', $this->casa);
        $pisoStudy = $id('Study', $this->piso);

        // A child inherits its parent's household; a different household for it is refused.
        self::assertSame(303, $post('/admin/locations', ['name' => 'Desk', 'parent_id' => (string) $casaStudy])->status());
        $desk = $id('Desk', $this->casa);
        self::assertSame((string) $this->casa, $household($desk));
        self::assertSame(422, $post('/admin/locations', ['name' => 'Drawer', 'parent_id' => (string) $casaStudy, 'household_id' => (string) $this->piso])->status());
        self::assertSame(303, $post('/admin/locations', ['name' => 'Drawer', 'parent_id' => (string) $casaStudy, 'household_id' => (string) $this->casa])->status());

        // Moving: within the household, never under another household's tree, never into a cycle.
        self::assertSame(303, $post("/admin/locations/{$desk}", ['name' => 'Desk', 'parent_id' => (string) $this->casaShelf])->status());
        $before = $this->snapshot();
        $crossed = $post("/admin/locations/{$desk}", ['name' => 'Desk', 'parent_id' => (string) $pisoStudy]);
        self::assertSame(422, $crossed->status());
        self::assertStringContainsString('stays in its household', $crossed->body());
        self::assertSame(422, $post("/admin/locations/{$this->casaShelf}", ['name' => 'Casa shelf', 'parent_id' => (string) $desk])->status(), 'A cycle');
        self::assertSame(422, $post("/admin/locations/{$desk}", ['name' => 'Desk', 'parent_id' => '999999'])->status());
        self::assertSame($before, $this->snapshot());
        // Becoming a root keeps the household; a submitted household is not a way to reassign it.
        self::assertSame(303, $post("/admin/locations/{$desk}", ['name' => 'Desk', 'parent_id' => '', 'household_id' => (string) $this->piso])->status());
        self::assertSame((string) $this->casa, $household($desk));
        self::assertEquals($this->casa, $this->auditDetails('location.updated')['household_id']);

        // The page shows one tree per household, and existing locations and copies are untouched.
        $page = $this->request($admin, 'GET', '/admin/locations')->body();
        self::assertStringContainsString('<h3>Casa</h3>', $page);
        self::assertStringContainsString('<h3>Zzinv Piso</h3>', $page);
        self::assertSame((string) $this->casaShelf, (string) $this->value("SELECT location_id FROM copies WHERE id = {$this->casaBook['copy_id']}"));
        $edit = $this->request($admin, 'GET', "/admin/locations/{$desk}/edit")->body();
        self::assertStringNotContainsString('value="' . $pisoStudy . '"', $edit, 'Only its own household\'s locations are offered as parents');

        // Deleting is refused while in use or while it has children.
        self::assertSame(303, $post("/admin/locations/{$this->casaShelf}/delete", [])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM locations WHERE id = {$this->casaShelf}"));
        self::assertSame(303, $post("/admin/locations/{$desk}/delete", [])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM locations WHERE id = {$desk}"));
        self::assertSame(404, $post('/admin/locations/999999', ['name' => 'X'])->status());

        // Location management stays with administrators.
        [$ana, $anaToken] = $this->signedIn('ana@example.org');
        foreach (['/admin/locations', "/admin/locations/{$this->casaShelf}", "/admin/locations/{$this->casaShelf}/delete"] as $path) {
            self::assertSame(403, $this->request($ana, 'POST', $path, ['_csrf' => $anaToken, 'name' => 'Mine', 'kind' => 'room', 'household_id' => (string) $this->casa])->status(), $path);
        }
        self::assertSame(403, $this->request($ana, 'GET', '/admin/locations')->status());
    }

    // --- Users and invitations ---------------------------------------------------------

    public function testNewAccountsGetOnlyTheHouseholdsAnAdministratorAssigns(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $create = fn (array $fields): Response => $this->request($admin, 'POST', '/admin/users', $fields + ['_csrf' => $token, 'display_name' => 'New', 'role' => 'member', 'locale' => 'en', 'password' => self::PASSWORD]);
        $households = fn (string $email): array => array_map('intval', $this->pdo()->query("SELECT m.household_id FROM household_members m JOIN users u ON u.id = m.user_id WHERE u.email = '{$email}' ORDER BY m.household_id")->fetchAll(PDO::FETCH_COLUMN));

        self::assertStringContainsString('name="household_ids[]"', $this->request($admin, 'GET', '/admin/users/new')->body());
        self::assertStringNotContainsString('checked', (string) preg_replace('/.*<fieldset>(.*?)<\/fieldset>.*/s', '$1', $this->request($admin, 'GET', '/admin/users/new')->body()), 'Nothing is ticked by default');

        // No household unless one is chosen.
        self::assertSame(303, $create(['email' => 'plain@example.org'])->status());
        self::assertSame([], $households('plain@example.org'));
        // One or several, created with the account and audited by id only.
        self::assertSame(303, $create(['email' => 'both@example.org', 'household_ids' => [(string) $this->casa, (string) $this->piso]])->status());
        self::assertSame([$this->casa, $this->piso], $households('both@example.org'));
        $newId = (int) $this->value("SELECT id FROM users WHERE email = 'both@example.org'");
        $audits = $this->pdo()->query("SELECT entity_id, details FROM audit_log WHERE action = 'household.member_added' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertSame([$this->casa, $this->piso], array_map(static fn (array $a): int => (int) $a['entity_id'], $audits));
        foreach ($audits as $audit) {
            self::assertEquals(['member_user_id' => $newId, 'via' => 'user_creation'], json_decode((string) $audit['details'], true));
            self::assertStringNotContainsString('both@example.org', (string) $audit['details']);
        }
        self::assertSame((string) $this->adminId, (string) $this->value("SELECT added_by FROM household_members WHERE user_id = {$newId} LIMIT 1"));

        // An unknown household refuses the whole creation: no account without the intended access.
        foreach ([['999999'], [(string) $this->casa, '999999'], ['x']] as $ids) {
            self::assertSame(422, $create(['email' => 'broken@example.org', 'household_ids' => $ids])->status());
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'broken@example.org'"));
        // The safeguards of user administration are unchanged.
        self::assertSame(422, $create(['email' => 'both@example.org'])->status(), 'Duplicate address');
        [$ana, $anaToken] = $this->signedIn('ana@example.org');
        self::assertSame(403, $this->request($ana, 'POST', '/admin/users', ['_csrf' => $anaToken, 'email' => 'x@example.org', 'display_name' => 'X', 'role' => 'member', 'locale' => 'en', 'password' => self::PASSWORD, 'household_ids' => [(string) $this->casa]])->status());
    }

    public function testInvitationsCarryHouseholdsThatAreGrantedOnlyOnRedemption(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $expires = gmdate('Y-m-d', strtotime('+5 days'));
        $invite = fn (string $email, array $ids): Response => $this->request($admin, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => $email, 'role' => 'member', 'expires_on' => $expires, 'household_ids' => $ids]);
        $assigned = fn (int $invitation): array => array_map('intval', $this->pdo()->query("SELECT household_id FROM invitation_households WHERE invitation_id = {$invitation} ORDER BY household_id")->fetchAll(PDO::FETCH_COLUMN));
        $memberships = fn (string $email): array => array_map('intval', $this->pdo()->query("SELECT m.household_id FROM household_members m JOIN users u ON u.id = m.user_id WHERE u.email = '{$email}' ORDER BY m.household_id")->fetchAll(PDO::FETCH_COLUMN));

        self::assertStringContainsString('name="household_ids[]"', $this->request($admin, 'GET', '/admin/invitations/new')->body());
        self::assertSame(422, $invite('bad@example.org', ['999999'])->status(), 'A household that does not exist cannot be assigned');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitations'));

        // Stored with the invitation; nobody is a member yet.
        $issued = $invite('guest@example.org', [(string) $this->casa, (string) $this->piso]);
        self::assertSame(200, $issued->status());
        $first = (int) $this->value("SELECT id FROM invitations WHERE email = 'guest@example.org'");
        self::assertSame([$this->casa, $this->piso], $assigned($first));
        self::assertEquals([$this->casa, $this->piso], $this->auditDetails('invitation.created')['household_ids']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members WHERE added_by IS NOT NULL'));
        self::assertStringContainsString('Households: Casa, Zzinv Piso', $this->request($admin, 'GET', '/admin/invitations')->body());

        // A revoked invitation grants nothing, even though its assignments are still stored.
        $revokedToken = self::tokenFrom($issued);
        self::assertSame(303, $this->request($admin, 'POST', "/admin/invitations/{$first}/revoke", ['_csrf' => $token])->status());
        self::assertSame(404, $this->redeem($revokedToken, 'guest@example.org')->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'guest@example.org'"));

        // A replaced invitation keeps the intended households; one deleted meanwhile is dropped.
        $second = $invite('guest@example.org', [(string) $this->casa, (string) $this->piso]);
        $secondId = (int) $this->value("SELECT id FROM invitations WHERE email = 'guest@example.org' AND revoked_at IS NULL");
        $empty = $this->createHousehold('Short-lived');
        $this->pdo()->exec("INSERT INTO invitation_households (invitation_id, household_id) VALUES ({$secondId}, {$empty})");
        self::assertSame(303, $this->request($admin, 'POST', "/admin/households/{$empty}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM households WHERE id = {$empty}"), 'Pending invitations do not keep a household alive');
        $replaced = $this->request($admin, 'POST', "/admin/invitations/{$secondId}/replace", ['_csrf' => $token, 'expires_on' => $expires]);
        self::assertSame(200, $replaced->status());
        $thirdId = (int) $this->value("SELECT id FROM invitations WHERE email = 'guest@example.org' AND revoked_at IS NULL");
        self::assertNotSame($secondId, $thirdId);
        self::assertSame([$this->casa, $this->piso], $assigned($thirdId));
        self::assertEquals([$this->casa, $this->piso], $this->auditDetails('invitation.replaced')['household_ids']);
        // The replaced link grants nothing.
        self::assertSame(404, $this->redeem(self::tokenFrom($second), 'guest@example.org')->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'guest@example.org'"));

        // A wrong address grants nothing either; the right one creates the account and its memberships together.
        $good = self::tokenFrom($replaced);
        self::assertSame(422, $this->redeem($good, 'someone.else@example.org')->status());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM household_members WHERE added_by IS NOT NULL'));
        self::assertSame(303, $this->redeem($good, 'guest@example.org')->status());
        self::assertSame([$this->casa, $this->piso], $memberships('guest@example.org'));
        $guestId = (int) $this->value("SELECT id FROM users WHERE email = 'guest@example.org'");
        $audits = $this->pdo()->query("SELECT user_id, entity_id, details FROM audit_log WHERE action = 'household.member_added' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $audits);
        foreach ($audits as $audit) {
            self::assertEquals(['member_user_id' => $guestId, 'via' => 'invitation', 'invitation_id' => $thirdId], json_decode((string) $audit['details'], true));
            self::assertSame((string) $this->adminId, (string) $audit['user_id']);
            self::assertStringNotContainsString('guest@', (string) $audit['details']);
        }
        // Single use: the same link cannot add anything again.
        self::assertSame(404, $this->redeem($good, 'guest@example.org')->status());

        // Without assignments (the default) the new account has no household, as before.
        $plain = $invite('plain@example.org', []);
        self::assertSame(303, $this->redeem(self::tokenFrom($plain), 'plain@example.org')->status());
        self::assertSame([], $memberships('plain@example.org'));
        // Invitations never grant the administrator role, and stay administrator-only.
        self::assertSame(422, $this->request($admin, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => 'boss@example.org', 'role' => 'admin', 'expires_on' => $expires])->status());
        [$ana, $anaToken] = $this->signedIn('ana@example.org');
        self::assertSame(403, $this->request($ana, 'POST', '/admin/invitations', ['_csrf' => $anaToken, 'email' => 'x@example.org', 'role' => 'member', 'expires_on' => $expires, 'household_ids' => [(string) $this->casa]])->status());
    }

    // --- Exports -------------------------------------------------------------------------

    public function testExportsFollowTheHouseholdBoundary(): void
    {
        // The administrator export carries the household of each inventory row, and no memberships.
        [$admin] = $this->signedIn('admin@example.org');
        $json = json_decode($this->body($this->request($admin, 'GET', '/admin/export/catalogue.json')), true);
        $copies = array_column($json['data']['copies'], null, 'id');
        self::assertNotEmpty($copies);
        self::assertSame([$this->casa, 'Casa'], [$copies[$this->casaBook['copy_id']]['household_id'], $copies[$this->casaBook['copy_id']]['household']]);
        self::assertSame($this->piso, $copies[$this->pisoBook['copy_id']]['household_id']);
        $csv = $this->body($this->request($admin, 'GET', '/admin/export/locations.csv'));
        self::assertStringContainsString('household_id,household', strtok($csv, "\n"));
        self::assertStringNotContainsString('household_members', json_encode($json));
        self::assertStringNotContainsString('ana@example.org', json_encode($json));

        // Members cannot use it, and their personal export holds none of Piso's inventory.
        [$ana] = $this->signedIn('ana@example.org');
        self::assertSame(403, $this->request($ana, 'GET', '/admin/export/catalogue.json')->status());
        self::assertSame(403, $this->request($ana, 'GET', '/admin/export/copies.csv')->status());
        $personal = $this->request($ana, 'GET', '/export/personal.json');
        self::assertSame(200, $personal->status());
        self::assertStringNotContainsString(self::INVENTORY, strtolower($this->body($personal)));
        self::assertStringNotContainsString('"household_id"', $this->body($personal), 'The personal export keeps its columns');
    }

    // --- No modification without authorization ---------------------------------------------

    /**
     * Every state-changing route, requested across the boundary by a
     * member of the other household, by a member of none and by a viewer:
     * never a success, never a server error, and not one row changed.
     */
    public function testUnauthorizedRequestsModifyNothing(): void
    {
        self::$trustUploads = true;
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, household_id, created_at, updated_at) VALUES ({$this->beaId}, 'Zzinv picks', 'shared', {$this->piso}, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $collection = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at) VALUES ({$collection}, {$this->pisoBook['edition_id']}, 1, UTC_TIMESTAMP())");
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, title, created_at, updated_at) VALUES ({$this->beaId}, 'Zzinv wish', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $wish = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$this->pisoBook['copy_id']}, 'Zzinv Borrower', '2026-01-02', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $loan = (int) $this->pdo()->lastInsertId();
        $this->pdo()->exec("UPDATE copies SET status = 'on_loan' WHERE id = {$this->pisoBook['copy_id']}");
        [$bea, $beaToken] = $this->signedIn('zzinv-bea@example.org');
        self::assertSame(303, $this->upload($bea, $beaToken, $this->pisoBook['edition_id'])->status());
        $cover = (int) $this->value("SELECT id FROM covers WHERE edition_id = {$this->pisoBook['edition_id']}");

        $b = $this->pisoBook;
        $attempts = fn (string $token, int $userId): array => [
            "/works/{$b['work_id']}" => $this->workBody($token, $b['work_id'], 'Hijacked'),
            "/works/{$b['work_id']}/delete" => [],
            "/works/{$b['work_id']}/editions" => ['edition_title' => 'Extra edition'],
            "/editions/{$b['edition_id']}" => $this->editionBody($token, $b['edition_id'], 'Hijacked'),
            "/editions/{$b['edition_id']}/delete" => [],
            "/editions/{$b['edition_id']}/copies" => ['owner_user_id' => (string) $userId, 'status' => 'available'],
            "/editions/{$b['edition_id']}/covers" => [],
            "/covers/{$cover}/delete" => [],
            "/copies/{$b['copy_id']}" => ['owner_user_id' => (string) $userId, 'status' => 'lost', 'row_version' => '1'],
            "/copies/{$b['copy_id']}/delete" => [],
            "/copies/{$this->sharedPisoCopy}/lend" => ['borrower_type' => 'name', 'borrower_name' => 'X', 'loaned_on' => '2026-01-05'],
            "/loans/{$loan}/return" => [],
            "/collections/{$collection}" => ['name' => 'Taken', 'visibility' => 'private'],
            "/collections/{$collection}/delete" => ['confirm' => '1'],
            "/collections/{$collection}/items" => ['edition_id' => (string) $this->casaBook['edition_id']],
            "/collections/{$collection}/items/{$b['edition_id']}/remove" => [],
            "/collections/{$collection}/items/{$b['edition_id']}/move" => ['direction' => 'up'],
            '/collections/add-item' => ['collection_id' => (string) $collection, 'edition_id' => (string) $this->casaBook['edition_id']],
            "/wishlist/{$wish}" => ['title' => 'Taken', 'priority' => 'normal'],
            "/wishlist/{$wish}/status" => ['status' => 'archived'],
            '/admin/locations' => ['name' => 'Mine', 'kind' => 'room', 'household_id' => (string) $this->piso],
            "/admin/locations/{$this->pisoShelf}" => ['name' => 'Renamed', 'kind' => 'room'],
            "/admin/locations/{$this->pisoShelf}/delete" => [],
            '/admin/users' => ['email' => 'new@example.org', 'display_name' => 'New', 'role' => 'admin', 'locale' => 'en', 'password' => self::PASSWORD, 'household_ids' => [(string) $this->piso]],
            '/admin/invitations' => ['email' => 'new@example.org', 'role' => 'member', 'expires_on' => gmdate('Y-m-d', strtotime('+2 days')), 'household_ids' => [(string) $this->piso]],
            "/admin/households/{$this->piso}/members" => ['user_id' => (string) $userId],
            "/admin/households/{$this->piso}/members/{$this->beaId}/remove" => [],
            "/admin/households/{$this->piso}" => ['name' => 'Mine', 'row_version' => '1'],
            "/admin/households/{$this->piso}/delete" => [],
        ];
        $before = $this->snapshot();
        $audit = $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action NOT LIKE 'auth.%'");
        $files = $this->coverFiles();
        foreach (['ana@example.org' => $this->anaId, 'carl@example.org' => $this->carlId, 'vera@example.org' => $this->veraId] as $email => $userId) {
            [$browser, $token] = $this->signedIn($email);
            foreach ($attempts($token, $userId) as $path => $body) {
                $response = $this->request($browser, 'POST', $path, $body + ['_csrf' => $token]);
                self::assertContains($response->status(), [403, 404], "{$email} POST {$path}");
                $this->assertNoInventory($response, "{$email} POST {$path}");
            }
        }
        self::assertSame($before, $this->snapshot(), 'No table changed');
        self::assertSame($audit, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action NOT LIKE 'auth.%'"), 'Nothing was recorded as done');
        self::assertSame($files, $this->coverFiles());

        // Without a CSRF token even the owner's own request is refused (unchanged).
        self::assertSame(419, $this->request($bea, 'POST', "/copies/{$b['copy_id']}", ['owner_user_id' => (string) $this->beaId, 'status' => 'lost', 'row_version' => '1'])->status());
        self::assertSame($before, $this->snapshot());
    }

    /**
     * The checks are repeated inside the services: calling them directly
     * with a forged or outdated actor is refused there, not only by the
     * controllers.
     */
    public function testServicesRefuseOnTheirOwn(): void
    {
        $services = $this->services();
        $ana = $services->users()->findById($this->anaId);
        $before = $this->snapshot();
        $copyInput = new \DigitalLibrary\Catalogue\Input\CopyInput((string) $this->anaId, '', '', '', 'available', false, '', '', '', '', '');
        $editionInput = new \DigitalLibrary\Catalogue\Input\EditionInput('Hijacked', '', '', '', '', '', '', '', '', '', '', []);
        $workInput = new \DigitalLibrary\Catalogue\Input\WorkInput('Hijacked', '', '', '', '', '', [], []);
        $denied = [
            'copy of an invisible edition' => fn () => $services->copyService()->create($this->anaId, $this->pisoBook['edition_id'], $copyInput),
            'update of another household\'s copy' => fn () => $services->copyService()->update($this->anaId, (int) $this->pisoBook['copy_id'], $copyInput, 1),
            'delete of another household\'s copy' => fn () => $services->copyService()->delete($this->anaId, (int) $this->pisoBook['copy_id']),
            'edit of an invisible edition' => fn () => $services->books()->updateEdition($this->anaId, $this->pisoBook['edition_id'], $editionInput, 1),
            'edit of a shared edition' => fn () => $services->books()->updateEdition($this->anaId, $this->sharedBook['edition_id'], $editionInput, 1),
            'edit of a shared work' => fn () => $services->books()->updateWork($this->anaId, $this->sharedBook['work_id'], $workInput, 1),
            'edition under an invisible work' => fn () => $services->books()->addEdition($this->anaId, $this->pisoBook['work_id'], $editionInput),
            'delete by a member' => fn () => $services->books()->deleteEdition($this->anaId, $this->casaBook['edition_id']),
            'a viewer creating a book' => fn () => $services->books()->createBook($this->veraId, $workInput, $editionInput, null),
            'location by a member' => fn () => $services->locationService()->create($this->anaId, null, (string) $this->casa, 'Mine', 'room', null),
        ];
        foreach ($denied as $what => $call) {
            try {
                $call();
                self::fail("Accepted: {$what}");
            } catch (\DigitalLibrary\Households\WriteDeniedException $e) {
                self::assertSame(str_contains($what, 'invisible') || str_contains($what, 'another household'), $e->notFound, $what);
            }
        }
        foreach ([
            fn () => $services->collectionService()->addItem($ana, $this->collection($this->anaId), $this->pisoBook['edition_id']),
            fn () => $services->wishlistService()->add($ana, new \DigitalLibrary\Wishlist\WishlistInput('X', '', '', 'normal', ''), $this->pisoBook['edition_id']),
        ] as $call) {
            try {
                $call();
                self::fail('Accepted an edition out of view');
            } catch (\DigitalLibrary\Collections\CollectionException | \DigitalLibrary\Wishlist\WishlistException $e) {
                self::assertStringEndsWith('edition_not_found', $e->getMessage());
            }
        }
        // A deactivated account is refused even with a still-valid object in hand.
        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->anaId}");
        try {
            $services->copyService()->update($this->anaId, (int) $this->casaBook['copy_id'], $copyInput, 1);
            self::fail('A deactivated account wrote');
        } catch (\DigitalLibrary\Households\WriteDeniedException $e) {
            self::assertFalse($e->notFound);
        }
        $this->pdo()->exec("UPDATE users SET is_active = 1 WHERE id = {$this->anaId}");
        $this->pdo()->exec("DELETE FROM collections WHERE owner_user_id = {$this->anaId}");
        self::assertSame($before, $this->snapshot());
        self::assertFalse($this->pdo()->inTransaction());
    }

    // --- Helpers ---------------------------------------------------------------------------

    /**
     * @param list<string> $allowed inventory values that legitimately belong to the viewer too
     */
    private function assertNoInventory(Response $response, string $what, array $allowed = []): void
    {
        $text = str_replace(array_map('strtolower', $allowed), '', strtolower($this->body($response)));
        // The signed-in user's own e-mail local part may carry the marker (Bea); nobody else's page may.
        $at = strpos($text, self::INVENTORY);
        self::assertFalse($at, "{$what} leaks \"" . self::INVENTORY . '": …' . ($at === false ? '' : substr($text, max(0, $at - 100), 220)) . '…');
    }

    private function body(Response $response): string
    {
        // For streamed responses (exports) this runs the writer.
        return $response->body();
    }

    /**
     * The <main> part of a page (without navigation, which names "Households" for administrators).
     */
    private static function mainOf(string $body): string
    {
        return preg_match('/<main\b.*?<\/main>/s', $body, $m) === 1 ? $m[0] : $body;
    }

    /**
     * Checksums of every table a write route could touch (not the audit log or login attempts).
     *
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $out = [];
        foreach (['works', 'editions', 'work_authors', 'edition_contributors', 'authors', 'publishers', 'covers', 'copies', 'locations', 'loans',
            'collections', 'collection_items', 'wishlist_items', 'households', 'household_members', 'invitations', 'invitation_households'] as $table) {
            $out[$table] = (string) $this->pdo()->query("CHECKSUM TABLE {$table}")->fetch(PDO::FETCH_NUM)[1];
        }
        $out['users'] = (string) $this->value('SELECT COUNT(*) FROM users');

        return $out;
    }

    private function value(string $sql): mixed
    {
        $value = $this->pdo()->query($sql)->fetchColumn();

        return $value === false ? null : $value;
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

    private function location(string $name, int $householdId): int
    {
        $this->pdo()->prepare('INSERT INTO locations (parent_id, household_id, name, created_at, updated_at) VALUES (NULL, :h, :n, UTC_TIMESTAMP(), UTC_TIMESTAMP())')->execute(['h' => $householdId, 'n' => $name]);

        return (int) $this->pdo()->lastInsertId();
    }

    private function collection(int $ownerId): int
    {
        $this->pdo()->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES ({$ownerId}, 'Service test', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @return array<string, string>
     */
    private function editionBody(string $token, int $editionId, string $title): array
    {
        return ['_csrf' => $token, 'edition_title' => $title, 'isbn' => (string) $this->value("SELECT COALESCE(isbn_13, '') FROM editions WHERE id = {$editionId}"), 'row_version' => $this->rowVersion('editions', $editionId)];
    }

    /**
     * @return array<string, string>
     */
    private function workBody(string $token, int $workId, string $title): array
    {
        return ['_csrf' => $token, 'title' => $title, 'row_version' => $this->rowVersion('works', $workId)];
    }

    private function saveEdition(Session $browser, string $token, int $editionId, string $title): Response
    {
        return $this->request($browser, 'POST', "/editions/{$editionId}", $this->editionBody($token, $editionId, $title));
    }

    private function saveWork(Session $browser, string $token, int $workId, string $title): Response
    {
        return $this->request($browser, 'POST', "/works/{$workId}", $this->workBody($token, $workId, $title));
    }

    private function upload(Session $browser, string $token, int $editionId): Response
    {
        $image = imagecreatetruecolor(60, 90);
        $file = (string) tempnam(sys_get_temp_dir(), 'cov');
        imagepng($image, $file);
        $this->temp[] = $file;

        return $this->request($browser, 'POST', "/editions/{$editionId}/covers", ['_csrf' => $token], [], [
            'cover' => ['name' => 'x.png', 'type' => 'image/png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)],
        ]);
    }

    /**
     * @return list<string>
     */
    private function coverFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::coverDirectory(), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
    }

    private static function tokenFrom(Response $response): string
    {
        if (preg_match('#/invite(?:/redeem)?[?\#](?:token=)?([A-Za-z0-9_-]{20,})#', html_entity_decode($response->body()), $m) !== 1) {
            self::fail('No invitation link in the response');
        }

        return $m[1];
    }

    /**
     * Opens an invitation link in a fresh browser and submits the registration form.
     */
    private function redeem(string $token, string $email): Response
    {
        $guest = $this->browser();
        $opened = $this->request($guest, 'GET', '/invite?token=' . $token);
        if ($opened->header('Location') !== '/invite/accept') {
            return $this->request($guest, 'GET', '/invite/invalid');
        }
        $csrf = self::csrfFrom($this->request($guest, 'GET', '/invite/accept'));

        return $this->request($guest, 'POST', '/invite/accept', ['_csrf' => $csrf, 'email' => $email, 'display_name' => 'Guest', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD]);
    }

    private function services(): \DigitalLibrary\Core\Services
    {
        $services = new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());

        return $services;
    }

    private function visibilityOf(int $userId): \DigitalLibrary\Security\Visibility
    {
        $services = $this->services();

        return $services->visibility($services->users()->findById($userId));
    }

    /**
     * A page without what legitimately differs between two requests: the
     * CSRF token, the requested path echoed in the language form, and numbers.
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
}
