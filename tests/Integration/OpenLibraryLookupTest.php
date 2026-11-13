<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\Provider\OpenLibraryProvider;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Tests\Support\OpenLibraryFake;
use PDO;

/**
 * The expanded Open Library integration, the review of untrusted metadata,
 * precise provider outcomes, cache safety and the manual contribution
 * workflow, through the full stack with offline HTTP fakes only.
 */
final class OpenLibraryLookupTest extends IntegrationTestCase
{
    private const CLEAN_CODE = '9780132350884';
    private const MISSING = '9788409779550';
    private const FIXTURES = __DIR__ . '/../Fixtures/metadata/';

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    private static function isbn(string $value): Isbn
    {
        return Isbn::tryParse($value) ?? throw new \LogicException($value);
    }

    private static function gb(string $isbn): string
    {
        return 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
    }

    private static function fixture(string $name): HttpResponse
    {
        return new HttpResponse(200, (string) file_get_contents(self::FIXTURES . $name), 'application/json');
    }

    /**
     * Open Library's /isbn endpoint returns the promotional edition; search
     * lists it and the genuine work under the same ISBN.
     */
    private static function spamOnOpenLibrary(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        self::$http->on(OpenLibraryProvider::editionUrl($isbn), self::fixture('openlibrary_edition_9780132350884_spam.json'));
        self::$http->on(OpenLibraryProvider::searchUrl($isbn), self::fixture('openlibrary_search_9780132350884.json'));
        self::$http->onPrefix('https://openlibrary.org/works/', new HttpResponse(404, ''));
    }

    private function openLibraryRequests(): int
    {
        return count(array_filter(self::$http->requested, static fn (string $u): bool => str_starts_with($u, 'https://openlibrary.org/')));
    }

    // Untrusted metadata ----------------------------------------------------------

    public function testThePromotionalRecordIsSetAsideAndTheIndependentProviderFillsTheForm(): void
    {
        self::spamOnOpenLibrary();
        self::$http->onPrefix(self::gb(self::CLEAN_CODE), self::fixture('googlebooks_9780132350884.json'));
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::CLEAN_CODE)->body();

        self::assertStringContainsString('id="title" name="title" type="text" value="Clean Code"', $html, 'Filled from Google Books');
        self::assertDoesNotMatchRegularExpression('#name="(?:title|publisher|edition_title)" type="text" value="The Earn2Trade#', $html, 'Never filled into a form field');
        self::assertStringContainsString('Details found online via Google Books.', $html);
        self::assertStringContainsString('A record that looks unreliable was set aside and not used to fill in the form.', $html);
        self::assertStringContainsString('<strong>Open Library:</strong> a record was found, but it looks unreliable and was not used', $html);
        self::assertStringContainsString('Open Library: record set aside.', $html);
        self::assertStringContainsString('it contains advertising wording (for example a discount code)', $html);
        self::assertStringContainsString('the title is also given as the publisher, and there is no author', $html);
        self::assertStringContainsString('Unreliable record (Open Library):', $html);
        self::assertStringContainsString('data-fill-target="title" data-fill-value="The Earn2Trade Discount Code"', $html, 'The user may still choose it deliberately');
        self::assertStringContainsString('href="https://openlibrary.org/books/OL26222911M"', $html, 'The genuine record that also claims the ISBN is listed');
        self::assertStringNotContainsString('name="import_cover"', $html, 'No cover from the set-aside record');
        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'google_books'");
        self::assertStringContainsString('name="metadata_record_id" value="' . $recordId . '"', $html, 'Linked to the trusted record');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM editions'), 'Nothing is saved without the user');
    }

    public function testWithOnlyTheQuestionableRecordNothingIsFilledAndManualEntryWorks(): void
    {
        self::spamOnOpenLibrary();
        self::$http->onPrefix(self::gb(self::CLEAN_CODE), new HttpResponse(429, '{"error":{"message":"Queries per day"}}', 'application/json'));
        [$browser, $token] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::CLEAN_CODE)->body();

        self::assertStringContainsString('id="title" name="title" type="text" value=""', $html);
        self::assertStringContainsString('No usable details were found, and some sources could not be checked (see below).', $html);
        self::assertStringContainsString('<strong>Google Books:</strong> temporarily limiting requests (HTTP 429); try again in about 15 min', $html, 'Daily quota: the longest bounded pause');
        self::assertStringContainsString('<span class="lookup-badge">Unreliable source only</span>', $html);
        self::assertStringNotContainsString('name="metadata_record_id"', $html);

        $response = $this->request($browser, 'POST', '/books', ['_csrf' => $token, 'title' => 'Clean Code', 'isbn' => self::CLEAN_CODE, 'author_names' => ['Robert C. Martin'], 'author_roles' => ['author']]);
        self::assertSame(303, $response->status(), 'Manual entry is never blocked');
        self::assertSame('Clean Code', $this->pdo()->query('SELECT title FROM editions')->fetchColumn());
    }

    // Provider outcomes -------------------------------------------------------------

    public function testNoRecordAndARateLimitAreExplainedSeparately(): void
    {
        OpenLibraryFake::missing(self::$http, self::MISSING);
        self::$http->onPrefix(self::gb(self::MISSING), new HttpResponse(429, '{}', 'application/json', ['retry-after' => '120']));
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::MISSING)->body();

        self::assertStringNotContainsString('The online sources are busy right now', $html, 'Open Library was not busy: it has no record');
        self::assertStringContainsString('No usable details were found, and some sources could not be checked (see below).', $html);
        self::assertStringContainsString('<strong>Open Library:</strong> no record for this ISBN', $html);
        self::assertStringContainsString('<strong>Google Books:</strong> temporarily limiting requests (HTTP 429); try again in about 2 min', $html);
        self::assertStringContainsString('name="isbn" type="text" value="' . self::MISSING . '"', $html, 'Manual entry with the ISBN');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM metadata_records WHERE provider = 'open_library'"), '"Not found" is cached briefly');
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM metadata_records WHERE provider = 'google_books'"), 'A rate limit is never cached');
    }

    public function testTransportFailuresAreNamed(): void
    {
        $isbns = ['9780306406157', '9780000000019', '9780000000026'];
        $failures = [
            [new HttpException('cert', null, null, HttpException::TLS), 'the secure connection could not be verified, so it was not used'],
            [new HttpException('dns', null, null, HttpException::DNS), 'could not be reached (the address could not be resolved)'],
            [new HttpResponse(200, '{"broken": ', 'application/json'), 'sent an answer this application could not read'],
        ];
        [$browser] = $this->signedIn('member@example.org');
        foreach ($failures as $i => [$failure, $message]) {
            OpenLibraryFake::failing(self::$http, $isbns[$i], $failure);
            self::$http->onPrefix(self::gb($isbns[$i]), new HttpResponse(200, '{"totalItems":0}', 'application/json'));
            $html = $this->request($browser, 'GET', '/books/new?isbn=' . $isbns[$i])->body();
            self::assertStringContainsString('<strong>Open Library:</strong> ' . $message, $html, $message);
            self::assertStringContainsString('<strong>Google Books:</strong> no record for this ISBN', $html);
        }
        self::assertSame([], self::$backoff->states, 'TLS, DNS and malformed answers do not start a backoff');
    }

    public function testAllOpenLibraryEndpointsShareOneCooldown(): void
    {
        $first = self::CLEAN_CODE;
        $second = '9780306406157';
        $isbn = self::isbn($first);
        self::$http->on(OpenLibraryProvider::editionUrl($isbn), self::fixture('openlibrary_edition_9780132350884_real.json'));
        self::$http->on(OpenLibraryProvider::searchUrl($isbn), new HttpResponse(429, '', 'application/json', ['retry-after' => '90']));
        self::$http->onPrefix('https://www.googleapis.com/', new HttpResponse(200, '{"totalItems":0}', 'application/json'));
        OpenLibraryFake::cienAnos(self::$http, $second);
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . $first)->body();
        self::assertStringContainsString('value="Clean Code"', $html, 'The edition is still used');
        self::assertStringContainsString('(some details could not be read)', $html);
        self::assertSame(1, self::$backoff->states['open_library']['failures'], 'The search 429 paused all of Open Library');

        $before = $this->openLibraryRequests();
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . $second)->body();
        self::assertSame($before, $this->openLibraryRequests(), 'No Open Library endpoint is contacted during the pause');
        self::assertStringContainsString('<strong>Open Library:</strong> paused after recent errors and not contacted; try again in about 2 min', $html);

        self::$clockOffset = 91;
        $this->request($browser, 'GET', '/books/new?isbn=' . $second);
        self::assertGreaterThan($before, $this->openLibraryRequests(), 'Contacted again after the pause');
        self::assertArrayNotHasKey('open_library', self::$backoff->states, 'A complete answer ends the pause');
        self::assertContains('open_library:required', self::$pacer->acquired, 'Requests go through the pacer');
    }

    // Cache safety --------------------------------------------------------------------

    public function testCorruptAndLegacyCacheEntriesAreRefreshedNeverTrusted(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        // A legacy Books API payload holding the promotional record, and a structurally corrupt Google entry.
        $legacy = json_encode(['key' => '/books/OL62721526M', 'title' => 'The Earn2Trade Discount Code', 'publishers' => [['name' => 'X']]]);
        $this->pdo()->prepare("INSERT INTO metadata_records (provider, isbn_13, payload, fetched_at) VALUES ('open_library', :i, :p, UTC_TIMESTAMP()), ('google_books', :i2, '\"just a string\"', UTC_TIMESTAMP())")
            ->execute(['i' => $isbn->isbn13, 'p' => $legacy, 'i2' => $isbn->isbn13]);
        OpenLibraryFake::failing(self::$http, $isbn->isbn13, new HttpException('offline', null, null, HttpException::NETWORK));
        self::$http->onPrefix(self::gb($isbn->isbn13), self::fixture('googlebooks_9780132350884.json'));
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . $isbn->isbn13)->body();
        self::assertStringNotContainsString('Earn2Trade', $html, 'The legacy entry is not used, even when Open Library cannot be reached');
        self::assertStringContainsString('<strong>Open Library:</strong> could not be reached (network error)', $html);
        self::assertStringContainsString('value="Clean Code"', $html, 'The corrupt Google entry was refetched and replaced');
        self::assertSame('Clean Code', json_decode((string) $this->pdo()->query("SELECT payload FROM metadata_records WHERE provider = 'google_books'")->fetchColumn(), true)['title']);
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM metadata_records'), 'Still one row per provider');
    }

    public function testCachedResultsCanBeInvalidatedSelectively(): void
    {
        OpenLibraryFake::cienAnos(self::$http);
        OpenLibraryFake::cienAnos(self::$http, '9788437604947');
        OpenLibraryFake::book(self::$http, self::CLEAN_CODE, ['title' => 'Clean Code', 'key' => 'OL26222911M', 'work' => 'OL17618370W'], ['Robert C. Martin']);
        self::$http->onPrefix('https://www.googleapis.com/', new HttpResponse(200, '{"totalItems":0}', 'application/json'));
        [$browser] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=9788437604947');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::CLEAN_CODE);
        $requests = count(self::$http->requested);

        [$code, $out] = $this->console(['metadata:refresh', '--isbn=0132350882', '--provider=open_library']);
        self::assertSame(0, $code);
        self::assertStringContainsString('Marked 1 cached lookup(s) for 9780132350884 as stale.', $out);
        self::assertSame(1, $this->console(['metadata:refresh', '--isbn=12345'])[0]);
        self::assertSame(1, $this->console(['metadata:refresh', '--isbn=' . self::CLEAN_CODE, '--provider=elsewhere'])[0]);

        $this->request($browser, 'GET', '/books/new?isbn=9788437604947');
        self::assertSame($requests, count(self::$http->requested), 'Other ISBNs stay cached');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::CLEAN_CODE);
        $new = array_slice(self::$http->requested, $requests);
        self::assertNotEmpty($new);
        foreach ($new as $url) {
            self::assertStringStartsWith('https://openlibrary.org/', $url, 'Only the invalidated provider is asked again');
        }
        self::assertSame(4, $this->countRows('SELECT COUNT(*) FROM metadata_records'), 'Rows are refreshed, never duplicated or deleted');
    }

    public function testConcurrentCacheWritesLeaveOneCompleteRow(): void
    {
        $other = ConnectionFactory::fromConfig(self::config());
        $sql = 'INSERT INTO metadata_records (provider, isbn_13, payload, fetched_by, fetched_at) VALUES (:p, :i, :payload, NULL, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE payload = :payload_update, fetched_at = UTC_TIMESTAMP()';
        foreach ([[$this->pdo(), '{"v":2,"edition":null,"search":[],"work":null,"incomplete":[],"n":1}'], [$other, '{"v":2,"edition":null,"search":[],"work":null,"incomplete":[],"n":2}']] as [$pdo, $payload]) {
            $pdo->beginTransaction();
            $pdo->prepare($sql)->execute(['p' => 'open_library', 'i' => self::CLEAN_CODE, 'payload' => $payload, 'payload_update' => $payload]);
            $pdo->commit();
        }
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM metadata_records'));
        self::assertSame(2, json_decode((string) $this->pdo()->query('SELECT payload FROM metadata_records')->fetchColumn(), true)['n'], 'Last complete write wins');
    }

    // Contribution workflow -------------------------------------------------------------

    public function testAMissingRecordLeadsToAReviewedManualContributionWithoutPrivateData(): void
    {
        OpenLibraryFake::missing(self::$http, self::MISSING);
        self::$http->onPrefix(self::gb(self::MISSING), new HttpResponse(200, '{"totalItems":0}', 'application/json'));
        [$browser] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::MISSING);
        $locationId = (int) $this->pdo()->query("SELECT id FROM locations LIMIT 1")->fetchColumn() ?: null;
        $book = $this->makeBook($this->memberId, 'El mundo <submarino>', self::MISSING, ['Autora de Prueba'], ['publisher' => 'Editorial', 'year' => 2025, 'language' => 'es'], [], $this->memberId, $locationId);
        $this->pdo()->prepare("UPDATE copies SET notes = 'SECRET-NOTE', purchase_price_cents = 9950, currency = 'EUR', acquisition_source = 'SECRET-SHOP' WHERE id = :c")->execute(['c' => $book['copy_id']]);
        $requests = count(self::$http->requested);

        $page = $this->request($browser, 'GET', '/editions/' . $book['edition_id'])->body();
        self::assertStringContainsString('Open Library has no record for this ISBN. You can add it there.', $page);
        self::assertStringContainsString('href="/editions/' . $book['edition_id'] . '/open-library"', $page);
        self::assertStringContainsString('Optional. Your catalogue works the same whether or not you contribute.', $page);

        $review = $this->request($browser, 'GET', '/editions/' . $book['edition_id'] . '/open-library');
        self::assertSame(200, $review->status());
        $html = $review->body();
        self::assertStringContainsString('Add this book or edition', $html);
        self::assertStringContainsString('name="fields[]" value="title" checked', $html);
        self::assertStringNotContainsString('id="ol-contribution-text"', $html, 'Nothing is prepared until the user asks');
        self::assertStringContainsString('El mundo &lt;submarino&gt;', $html);

        $prepared = $this->request($browser, 'GET', '/editions/' . $book['edition_id'] . '/open-library?prepared=1&fields%5B%5D=title&fields%5B%5D=isbn_13&fields%5B%5D=authors&fields%5B%5D=notes')->body();
        self::assertMatchesRegularExpression('#<textarea id="ol-contribution-text" rows="\d+" readonly>ISBN-13: ' . self::MISSING . '\nTitle: El mundo &lt;submarino&gt;\nAuthors: Autora de Prueba\n</textarea>#', $prepared, 'Only the selected, allowlisted fields; escaped');
        self::assertStringContainsString('data-copy="ol-contribution-text"', $prepared);
        self::assertStringContainsString('data-copy-status="ol-copy-status" hidden', $prepared, 'The copy button needs JavaScript; the text can be copied by hand');
        self::assertStringContainsString('<p class="visually-hidden" role="status" id="ol-copy-status"></p>', $prepared);
        self::assertStringContainsString('href="https://openlibrary.org/books/add" rel="noopener noreferrer external"', $prepared);
        self::assertStringContainsString('href="https://openlibrary.org/search?q=', $prepared);
        self::assertStringContainsString('it never reports a contribution as done', $prepared);
        foreach (['SECRET-NOTE', '99.50', '9950', 'SECRET-SHOP', 'member@example.org'] as $private) {
            self::assertStringNotContainsString($private, (string) preg_replace('#<header.*?</header>#s', '', $prepared), $private);
        }
        self::assertSame($requests, count(self::$http->requested), 'Preparing a contribution makes no outbound request');
    }

    public function testTheSpamRecordLeadsToAReportAndAccessIsControlled(): void
    {
        self::spamOnOpenLibrary();
        self::$http->onPrefix(self::gb(self::CLEAN_CODE), self::fixture('googlebooks_9780132350884.json'));
        [$browser] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::CLEAN_CODE);
        $book = $this->makeBook($this->memberId, 'Clean Code', self::CLEAN_CODE, ['Robert C. Martin']);

        self::assertStringContainsString('Report a problem on Open Library', $this->request($browser, 'GET', '/editions/' . $book['edition_id'])->body());
        $html = $this->request($browser, 'GET', '/editions/' . $book['edition_id'] . '/open-library?prepared=1&fields%5B%5D=title&fields%5B%5D=isbn_13')->body();
        self::assertStringContainsString('Report an incorrect record', $html);
        self::assertStringContainsString('href="https://openlibrary.org/books/OL62721526M"', $html);
        self::assertStringContainsString('href="mailto:openlibrary@archive.org?subject=Incorrect%20record%20for%20ISBN%209780132350884', $html);
        self::assertStringNotContainsString('https://openlibrary.org/books/add', $html, 'No duplicate is suggested');

        // Same permission as the edition page; guests are sent to sign in.
        $this->createUser('viewer@example.org', Role::Viewer);
        [$viewer] = $this->signedIn('viewer@example.org');
        self::assertSame(200, $this->request($viewer, 'GET', '/editions/' . $book['edition_id'] . '/open-library')->status());
        self::assertSame(303, $this->request($this->browser(), 'GET', '/editions/' . $book['edition_id'] . '/open-library')->status(), 'Guests are sent to sign in');
        self::assertSame(404, $this->request($browser, 'GET', '/editions/999999/open-library')->status());
        $noIsbn = $this->makeBook($this->memberId, 'No ISBN');
        self::assertStringNotContainsString('/open-library"', $this->request($browser, 'GET', '/editions/' . $noIsbn['edition_id'])->body());
        self::assertSame(303, $this->request($browser, 'GET', '/editions/' . $noIsbn['edition_id'] . '/open-library')->status());
    }

    public function testTheWorkflowIsTranslated(): void
    {
        $lookup = '9780306406157'; // not catalogued, so the ISBN step looks it up
        OpenLibraryFake::missing(self::$http, $lookup);
        self::$http->onPrefix(self::gb($lookup), new HttpResponse(429, '{}', 'application/json'));
        $book = $this->makeBook($this->memberId, 'Libro', self::MISSING, ['Autora']);
        foreach (['es' => ['Contribuir a Open Library', 'Elige qué incluir', 'no hay ningún registro para este ISBN'], 'gl' => ['Contribuír a Open Library', 'Escolle que incluír', 'non hai ningún rexistro para este ISBN']] as $locale => [$title, $choose, $outcome]) {
            $this->pdo()->exec("UPDATE users SET locale = '{$locale}'");
            [$browser] = $this->signedIn('member@example.org');
            self::assertStringContainsString($outcome, $this->request($browser, 'GET', '/books/new?isbn=' . $lookup)->body());
            $html = $this->request($browser, 'GET', '/editions/' . $book['edition_id'] . '/open-library')->body();
            self::assertStringContainsString($title, $html);
            self::assertStringContainsString($choose, $html);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function console(array $args): array
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new Console($services, fopen('php://memory', 'r'), $out, $err))->run(array_merge(['console'], $args));
        rewind($out);
        rewind($err);

        return [$code, (string) stream_get_contents($out), (string) stream_get_contents($err)];
    }
}
