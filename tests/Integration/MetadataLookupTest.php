<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Security\Role;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * ISBN metadata lookup through the full stack, with an offline HTTP fake.
 */
final class MetadataLookupTest extends IntegrationTestCase
{
    private const ISBN = '9788437604947';
    private const OL = 'https://openlibrary.org/api/books?bibkeys=ISBN:9788437604947&format=json&jscmd=data';
    private const GB = 'https://www.googleapis.com/books/v1/volumes?q=isbn:9788437604947';
    private const FIXTURES = __DIR__ . '/../Fixtures/metadata/';

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    private function bothProvidersRespond(): void
    {
        self::$http->on(self::OL, new HttpResponse(200, (string) file_get_contents(self::FIXTURES . 'openlibrary_9788437604947.json')));
        self::$http->onPrefix(self::GB, new HttpResponse(200, (string) file_get_contents(self::FIXTURES . 'googlebooks_9788437604947.json')));
    }

    public function testLookupPrefillsTheFormForReviewAndCachesPayloads(): void
    {
        $this->bothProvidersRespond();
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=978-84-376-0494-7')->body();
        self::assertStringContainsString('Details found online via Open Library, Google Books.', $html);
        self::assertStringContainsString('Please check every field before saving', $html);
        self::assertStringContainsString('value="Cien años de soledad"', $html, 'Open Library has priority for the title');
        self::assertStringContainsString('value="Gabriel García Márquez"', $html);
        self::assertStringContainsString('value="Cátedra"', $html);
        self::assertStringContainsString('value="2007"', $html);
        self::assertStringContainsString('<option value="es" selected>', $html, 'Language filled in from Google Books');
        self::assertStringContainsString('name="import_cover" value="1" checked', $html);
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM metadata_records WHERE isbn_13 = '" . self::ISBN . "'"));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM editions'), 'Nothing is saved before review');

        $requestsBefore = count(self::$http->requested);
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        self::assertSame($requestsBefore, count(self::$http->requested), 'Second lookup is served from the cache');
    }

    #[RequiresPhpExtension('gd')]
    public function testSavingLinksTheRecordAndImportsTheCoverThroughTheImagePipeline(): void
    {
        $this->bothProvidersRespond();
        self::$http->on('https://covers.openlibrary.org/b/id/1-L.jpg', new HttpResponse(200, $this->pngBytes(300, 450), 'image/png'));
        [$browser, $token] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'open_library'");

        $response = $this->request($browser, 'POST', '/books', [
            '_csrf' => $token, 'title' => 'Cien años de soledad', 'isbn' => self::ISBN,
            'metadata_record_id' => (string) $recordId, 'import_cover' => '1',
        ]);
        self::assertSame(303, $response->status());
        $edition = $this->pdo()->query('SELECT id, metadata_record_id FROM editions')->fetch();
        self::assertSame($recordId, (int) $edition['metadata_record_id']);

        $cover = $this->pdo()->query('SELECT * FROM covers')->fetch();
        self::assertSame((int) $edition['id'], (int) $cover['edition_id']);
        self::assertSame('image/jpeg', $cover['mime_type'], 'Re-encoded like any upload');
        self::assertFileExists(self::coverDirectory() . '/' . $cover['storage_key']);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'cover.imported'"));
    }

    public function testRecordIdForAnotherIsbnIsIgnored(): void
    {
        $this->bothProvidersRespond();
        [$browser, $token] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'open_library'");

        $response = $this->request($browser, 'POST', '/books', [
            '_csrf' => $token, 'title' => 'Other book', 'isbn' => '9780306406157',
            'metadata_record_id' => (string) $recordId, 'import_cover' => '1',
        ]);
        self::assertSame(303, $response->status());
        self::assertNull($this->pdo()->query('SELECT metadata_record_id FROM editions')->fetchColumn());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM covers'));
        self::assertNotContains('https://covers.openlibrary.org/b/id/1-L.jpg', self::$http->requested, 'No download was attempted');
    }

    public function testProviderFailureFallsBackToManualEntry(): void
    {
        self::$http->on(self::OL, new HttpException('timeout'));
        self::$http->onPrefix(self::GB, new HttpResponse(429, '{"error":"rate limited"}'));
        [$browser] = $this->signedIn('member@example.org');

        $response = $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('The online lookup is unavailable right now.', $response->body());
        self::assertStringContainsString('value="' . self::ISBN . '"', $response->body());
        self::assertStringNotContainsString('name="metadata_record_id"', $response->body());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM metadata_records'), 'Failures are not cached');
    }

    public function testNotFoundIsCachedBriefly(): void
    {
        self::$http->on(self::OL, new HttpResponse(200, '{}'));
        self::$http->onPrefix(self::GB, new HttpResponse(200, '{"kind":"books#volumes","totalItems":0}'));
        [$browser] = $this->signedIn('member@example.org');

        self::assertStringContainsString('No details were found online for this ISBN.', $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN)->body());
        $requests = count(self::$http->requested);
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        self::assertSame($requests, count(self::$http->requested), 'Negative result cached');

        $this->pdo()->exec("UPDATE metadata_records SET fetched_at = UTC_TIMESTAMP() - INTERVAL 2 DAY");
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        self::assertGreaterThan($requests, count(self::$http->requested), 'Negative cache expires after a day');
    }

    public function testHostileProviderDataIsEscapedAndCannotSteerTheServer(): void
    {
        $payload = ['ISBN:' . self::ISBN => [
            'title' => '<img src=x onerror=alert(1)>"Title',
            'authors' => [['name' => '<script>alert(2)</script>']],
            'publishers' => [['name' => 'Evil "Press"']],
            // A provider (or an attacker controlling its response) points the cover at an internal address.
            'cover' => ['large' => 'http://169.254.169.254/latest/meta-data/'],
        ]];
        self::$http->on(self::OL, new HttpResponse(200, (string) json_encode($payload)));
        self::$http->onPrefix(self::GB, new HttpResponse(200, '{"totalItems":0}'));
        [$browser, $token] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN)->body();
        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>alert(2)', $html);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;&quot;Title', $html);

        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'open_library'");
        $response = $this->request($browser, 'POST', '/books', ['_csrf' => $token, 'title' => 'Reviewed title', 'isbn' => self::ISBN, 'metadata_record_id' => (string) $recordId, 'import_cover' => '1']);
        self::assertSame(303, $response->status(), 'The book is still saved');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM covers'));
        self::assertNotContains('http://169.254.169.254/latest/meta-data/', self::$http->requested, 'The internal URL was never requested');
        self::assertStringContainsString('the cover image could not be imported', $this->request($browser, 'GET', (string) $response->header('Location'))->body());
    }

    #[RequiresPhpExtension('gd')]
    public function testNonImageCoverBytesAreRejected(): void
    {
        $this->bothProvidersRespond();
        self::$http->on('https://covers.openlibrary.org/b/id/1-L.jpg', new HttpResponse(200, '<?php system($_GET["c"]); ?>', 'image/jpeg'));
        [$browser, $token] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN);
        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'open_library'");
        $before = glob(self::coverDirectory() . '/*/*') ?: [];

        $this->request($browser, 'POST', '/books', ['_csrf' => $token, 'title' => 'T', 'isbn' => self::ISBN, 'metadata_record_id' => (string) $recordId, 'import_cover' => '1']);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM covers'));
        self::assertSame($before, glob(self::coverDirectory() . '/*/*') ?: [], 'No file left behind');
    }

    public function testLookupCanBeDisabled(): void
    {
        self::$configOverrides['METADATA_PROVIDERS'] = 'none';
        $this->bothProvidersRespond();
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::ISBN)->body();
        self::assertSame([], self::$http->requested);
        self::assertStringNotContainsString('online', $html);
    }

    private function pngBytes(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, (int) imagecolorallocate($img, 90, 40, 30));
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }
}
