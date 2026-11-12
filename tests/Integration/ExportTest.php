<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Export\CatalogueExport;
use DigitalLibrary\Export\CsvWriter;
use DigitalLibrary\Export\PersonalExport;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;

/**
 * CSV and JSON exports: format, escaping and formula-injection protection,
 * authorization and per-user isolation, privacy exclusions, headers and
 * bounded memory on large catalogues.
 */
final class ExportTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
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
        $this->createUser('viewer@example.org', Role::Viewer);
        $this->aliceBook = $this->makeBook($this->aliceId, 'Rayuela', '9788437604947', ['Julio Cortázar', 'Second Author'], ['year' => 1963], [], $this->aliceId);
        $this->bobBook = $this->makeBook($this->bobId, 'Follas novas', null, ['Rosalía de Castro'], [], [], $this->bobId);
        $pdo = $this->pdo();
        $pdo->exec("UPDATE copies SET purchase_price_cents = 1250, currency = 'EUR', notes = 'First edition' WHERE id = {$this->aliceBook['copy_id']}");
        $pdo->exec("INSERT INTO wishlist_items (user_id, title, status, created_at, updated_at) VALUES
            ({$this->aliceId}, 'Alice wish', 'wanted', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ({$this->bobId}, 'Bob secret wish', 'wanted', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collections (owner_user_id, name, visibility, created_at, updated_at) VALUES
            ({$this->aliceId}, 'Alice private shelf', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ({$this->aliceId}, 'Alice shared shelf', 'shared', UTC_TIMESTAMP(), UTC_TIMESTAMP()),
            ({$this->bobId}, 'Bob private shelf', 'private', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO collection_items (collection_id, edition_id, position, added_at)
            SELECT id, {$this->aliceBook['edition_id']}, 1, UTC_TIMESTAMP() FROM collections");
        $pdo->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, notes, created_at, updated_at)
            VALUES ({$this->aliceBook['copy_id']}, 'Secret Borrower', '2026-01-02', '2026-01-20', 'private note', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $pdo->exec("INSERT INTO loans (copy_id, borrower_user_id, loaned_on, created_at, updated_at)
            VALUES ({$this->aliceBook['copy_id']}, {$this->bobId}, '2026-02-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
    }

    public function testPersonalJsonContainsOnlyTheUsersOwnData(): void
    {
        [$alice] = $this->signedIn('alice@example.org');
        // A user parameter is ignored: the export is always the signed-in user's.
        $response = $this->request($alice, 'GET', '/export/personal.json?user=' . $this->bobId);
        self::assertSame(200, $response->status());
        self::assertSame('application/json; charset=UTF-8', $response->header('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="library-personal-\d{8}\.json"$/', (string) $response->header('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        $json = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['format' => 'digital-library-archive', 'type' => 'personal', 'schema_version' => 1], array_intersect_key($json['export'], array_flip(['format', 'type', 'schema_version'])));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $json['export']['generated_at']);
        self::assertSame($this->aliceId, $json['export']['user_id']);
        self::assertSame(PersonalExport::DATASETS, array_keys($json['data']));

        $data = $json['data'];
        self::assertSame([['id' => $this->aliceId, 'display_name' => 'Alice', 'email' => 'alice@example.org', 'role' => 'member', 'locale' => 'en']],
            array_map(static fn (array $a): array => array_diff_key($a, ['created_at' => 1]), $data['account']));
        self::assertSame(['Alice wish'], array_column($data['wishlist'], 'title'));
        self::assertSame([$this->aliceBook['copy_id']], array_column($data['copies'], 'id'));
        self::assertSame('12.50', $data['copies'][0]['price']);
        self::assertSame(['Alice private shelf', 'Alice shared shelf'], array_column($data['collections'], 'name'));
        self::assertCount(2, $data['collection_items']);
        self::assertSame(['lender', 'lender'], array_column($data['loans'], 'my_role'));
        self::assertSame(['Secret Borrower', 'Bob'], array_column($data['loans'], 'borrower'));

        $body = $response->body();
        foreach (['Bob secret wish', 'Bob private shelf', 'Follas novas', 'password', 'argon2', 'auth_version', 'bob@example.org'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'export.personal' AND user_id = {$this->aliceId}"));
    }

    public function testBorrowersSeeTheirLoansButNotOtherBorrowers(): void
    {
        [$bob] = $this->signedIn('bob@example.org');
        $loans = json_decode($this->request($bob, 'GET', '/export/personal.json')->body(), true)['data']['loans'];
        self::assertCount(1, $loans);
        self::assertSame(['borrower', 'Alice', 'Bob'], [$loans[0]['my_role'], $loans[0]['owner'], $loans[0]['borrower']]);
        $csv = $this->request($bob, 'GET', '/export/personal/loans.csv')->body();
        self::assertStringNotContainsString('Secret Borrower', $csv);
        self::assertStringNotContainsString('private note', $csv);

        // Viewers export (empty) personal data but nothing else.
        [$viewer] = $this->signedIn('viewer@example.org');
        $json = json_decode($this->request($viewer, 'GET', '/export/personal.json')->body(), true);
        self::assertSame([], $json['data']['wishlist']);
        self::assertSame([], $json['data']['copies']);
        self::assertSame(403, $this->request($viewer, 'GET', '/admin/export/catalogue.json')->status());
    }

    public function testCatalogueExportIsAdministratorOnlyAndExcludesPrivateData(): void
    {
        foreach (['/export', '/export/personal.json', '/export/personal/wishlist.csv', '/admin/export/catalogue.json', '/admin/export/works.csv'] as $path) {
            self::assertSame('/login', $this->request($this->browser(), 'GET', $path)->header('Location'), $path);
        }
        [$alice] = $this->signedIn('alice@example.org');
        self::assertSame(403, $this->request($alice, 'GET', '/admin/export/catalogue.json')->status());
        self::assertSame(403, $this->request($alice, 'GET', '/admin/export/copies.csv')->status());
        self::assertStringNotContainsString('/admin/export/', $this->request($alice, 'GET', '/export')->body());

        [$admin] = $this->signedIn('admin@example.org');
        self::assertStringContainsString('/admin/export/catalogue.json', $this->request($admin, 'GET', '/export')->body());
        $response = $this->request($admin, 'GET', '/admin/export/catalogue.json');
        self::assertSame(200, $response->status());
        $json = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('catalogue', $json['export']['type']);
        self::assertSame(CatalogueExport::DATASETS, array_keys($json['data']));

        $works = array_column($json['data']['works'], null, 'title');
        self::assertSame(['Julio Cortázar', 'Second Author'], $works['Rayuela']['authors']);
        self::assertCount(2, $works['Rayuela']['author_ids']);
        self::assertSame($this->aliceBook['work_id'], $works['Rayuela']['id']);
        $editions = array_column($json['data']['editions'], null, 'id');
        self::assertSame([$this->aliceBook['work_id'], '9788437604947', 1963], [$editions[$this->aliceBook['edition_id']]['work_id'], $editions[$this->aliceBook['edition_id']]['isbn_13'], $editions[$this->aliceBook['edition_id']]['publication_year']]);
        self::assertCount(3, $json['data']['work_authors']);
        $copies = array_column($json['data']['copies'], 'owner_user_id', 'id');
        self::assertSame([$this->aliceBook['copy_id'] => $this->aliceId, $this->bobBook['copy_id'] => $this->bobId], $copies);
        self::assertSame(['Alice shared shelf'], array_column($json['data']['collections'], 'name'), 'Private collections are not exported');
        self::assertCount(1, $json['data']['collection_items']);

        $body = $response->body();
        foreach (['wish', 'private shelf', 'Secret Borrower', '@example.org', 'password', 'argon2', 'token'] as $leak) {
            self::assertStringNotContainsString($leak, $body, $leak);
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'export.catalogue' AND user_id = {$this->adminId}"));

        foreach (['wishlist', 'loans', 'users', 'account', 'audit_log', 'invitations'] as $dataset) {
            self::assertSame(404, $this->request($admin, 'GET', "/admin/export/{$dataset}.csv")->status(), $dataset);
        }
        foreach (['works', 'users', 'nope'] as $dataset) {
            self::assertSame(404, $this->request($alice, 'GET', "/export/personal/{$dataset}.csv")->status(), $dataset);
        }
    }

    public function testCsvIsWellFormedAndNeutralisesFormulas(): void
    {
        $hostile = [
            '=HYPERLINK("http://evil.example","x")', '+1+2', '-3', '@SUM(A1)', "\tTabbed", "\rReturn", '  =padded', '＝FULLWIDTH',
        ];
        foreach ($hostile as $i => $title) {
            $this->makeBook($this->aliceId, $title, null, ['=Evil Author ' . $i]);
        }
        $long = str_repeat('Long text with "quotes", commas and a newline' . "\n", 400);
        $this->pdo()->prepare('UPDATE works SET description = :d WHERE id = :id')->execute(['d' => $long, 'id' => $this->aliceBook['work_id']]);

        [$admin] = $this->signedIn('admin@example.org');
        $response = $this->request($admin, 'GET', '/admin/export/works.csv');
        self::assertSame('text/csv; charset=UTF-8', $response->header('Content-Type'));
        self::assertMatchesRegularExpression('/^attachment; filename="library-catalogue-works-\d{8}\.csv"$/', (string) $response->header('Content-Disposition'));
        $body = $response->body();
        self::assertStringStartsWith(CsvWriter::BOM . "id,title,subtitle,original_title,original_language,first_published_year,description,authors,author_ids,categories,category_ids,created_by,created_at,updated_at\r\n", $body);

        $rows = $this->parseCsv($body);
        $header = array_shift($rows);
        $byId = [];
        foreach ($rows as $row) {
            self::assertCount(count($header), $row);
            $byId[(int) $row[0]] = array_combine($header, $row);
        }
        $rayuela = $byId[$this->aliceBook['work_id']];
        self::assertSame($long, $rayuela['description'], 'Long values with quotes and newlines survive intact');
        self::assertSame('Julio Cortázar; Second Author', $rayuela['authors']);
        self::assertSame('', $rayuela['subtitle'], 'Null is an empty cell');
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rayuela['created_at']);

        $titles = array_column(array_values($byId), 'title');
        foreach ($hostile as $title) {
            self::assertContains("'" . $title, $titles, json_encode($title));
        }
        self::assertContains('Rayuela', $titles, 'Ordinary values are unchanged');
        foreach (array_values($byId) as $row) {
            if (str_starts_with($row['authors'], "'=Evil")) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/^[=+\-@]/', $row['authors']);
        }

        // Numbers are left as numbers.
        $editions = $this->parseCsv($this->request($admin, 'GET', '/admin/export/editions.csv')->body());
        $header = array_shift($editions);
        $first = array_combine($header, $editions[0]);
        self::assertSame('1963', $first['publication_year']);

        // Personal CSV, e.g. wishlist, uses the same writer.
        $this->pdo()->exec("INSERT INTO wishlist_items (user_id, title, notes, created_at, updated_at) VALUES ({$this->aliceId}, '=cmd|calc', '-2+3', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        [$alice] = $this->signedIn('alice@example.org');
        $wish = $this->parseCsv($this->request($alice, 'GET', '/export/personal/wishlist.csv')->body());
        self::assertSame(['id', 'title', 'author', 'isbn_13', 'edition_id', 'work_id', 'priority', 'notes', 'status', 'status_changed_at', 'created_at', 'updated_at'], $wish[0]);
        $flat = array_merge(...array_slice($wish, 1));
        self::assertContains("'=cmd|calc", $flat);
        self::assertContains("'-2+3", $flat);
    }

    public function testLargeCataloguesStreamInBatchesWithBoundedMemory(): void
    {
        $count = CatalogueExport::BATCH * 5 + 37;
        $this->pdo()->exec('SET SESSION cte_max_recursion_depth = 10000');
        $this->pdo()->exec("INSERT INTO works (title, description, created_by, created_at, updated_at)
            WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < {$count})
            SELECT CONCAT('Bulk work ', i), REPEAT('x', 2000), {$this->aliceId}, UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM n");
        $total = $count + 2;

        $export = new CatalogueExport($this->pdo());
        $out = fopen('php://temp', 'w+b');
        $before = memory_get_usage();
        $peakBefore = memory_get_peak_usage();
        $written = CsvWriter::write($out, $export->datasets()['works']->columns, $export->datasets()['works']->rows());
        $grown = memory_get_peak_usage() - max($before, $peakBefore);
        self::assertSame($total, $written);
        self::assertLessThan(8 * 1024 * 1024, $grown, 'Peak memory grows by less than 8 MiB for ~6 MB of output');
        rewind($out);
        $ids = [];
        fgets($out);
        while (($row = fgetcsv($out, null, ',', '"', '')) !== false) {
            $ids[] = (int) $row[0];
        }
        self::assertCount($total, $ids);
        self::assertSame($ids, array_values(array_unique($ids)), 'Keyset batches neither repeat nor skip rows');
        $sorted = $ids;
        sort($sorted);
        self::assertSame($sorted, $ids);

        [$admin] = $this->signedIn('admin@example.org');
        $json = json_decode($this->request($admin, 'GET', '/admin/export/catalogue.json')->body(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount($total, $json['data']['works']);
    }

    /**
     * @return list<list<string>>
     */
    private function parseCsv(string $body): array
    {
        self::assertStringStartsWith(CsvWriter::BOM, $body);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, substr($body, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }
}
