<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\MetadataPrefill;
use DigitalLibrary\Metadata\Provider\GoogleBooksProvider;
use DigitalLibrary\Metadata\Provider\OpenLibraryProvider;
use DigitalLibrary\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class ProviderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/metadata/';

    private Isbn $isbn;

    protected function setUp(): void
    {
        $this->isbn = Isbn::tryParse('978-84-376-0494-7') ?? throw new \LogicException();
    }

    /**
     * Payloads the legacy Books API wrote before format 2 are still read
     * (existing cache rows, linked editions), and marked for refresh.
     */
    public function testLegacyCachedOpenLibraryPayloadIsReadableButNotCurrent(): void
    {
        $provider = new OpenLibraryProvider(new FakeHttpClient());
        $legacy = json_decode((string) file_get_contents(self::FIXTURES . 'openlibrary_9788437604947.json'), true)['ISBN:9788437604947'];
        self::assertFalse($provider->isCurrent($legacy));
        $record = $provider->describe($legacy, $this->isbn);
        $m = $record->metadata;
        self::assertSame('Cien años de soledad', $m->title);
        self::assertSame(['Gabriel García Márquez'], $m->authors);
        self::assertSame('Cátedra', $m->publisher);
        self::assertSame(2007, $m->publicationYear);
        self::assertSame(471, $m->pageCount);
        self::assertSame('https://covers.openlibrary.org/b/id/1-L.jpg', $m->coverUrl);
        self::assertSame(['ol_edition' => 'OL0000000M'], $record->identifiers);
        self::assertSame([], $record->warnings);
    }

    public function testGoogleBooksOnlyAcceptsVolumesListingTheIsbn(): void
    {
        $provider = new GoogleBooksProvider(new FakeHttpClient(), 'k3y&x');
        self::assertStringEndsWith('&key=k3y%26x', $provider->url($this->isbn), 'API key is URL-encoded');

        $http = (new FakeHttpClient())->onPrefix('https://www.googleapis.com/books/v1/volumes?q=isbn:9788437604947', new HttpResponse(200, (string) file_get_contents(self::FIXTURES . 'googlebooks_9788437604947.json')));
        $provider = new GoogleBooksProvider($http);
        $payload = $provider->fetch($this->isbn);
        self::assertNotNull($payload);
        $m = $provider->normalize($payload, $this->isbn);
        self::assertSame('Cien Años de Soledad', $m->title, 'The unrelated first item was skipped');
        self::assertSame('es', $m->language);
        self::assertSame('La historia de la familia Buendía.', $m->description, 'HTML is stripped');
        self::assertStringStartsWith('https://books.google.com/', $m->coverUrl, 'Upgraded to https');

        $other = Isbn::tryParse('9780394752846') ?? throw new \LogicException();
        $http = (new FakeHttpClient())->onPrefix('https://www.googleapis.com/', new HttpResponse(200, (string) file_get_contents(self::FIXTURES . 'googlebooks_9788437604947.json')));
        self::assertNull((new GoogleBooksProvider($http))->fetch($other), 'No volume lists the requested ISBN');
    }

    public function testCleaningOfUntrustedValues(): void
    {
        $m = BookMetadata::clean(
            "Title\x00 with\ncontrol\tchars",
            ['not', 'a string'],
            ['A', 'A', ' B ', 42, str_repeat('x', 300)],
            'P',
            'circa 1880?',
            '99999',
            'glg',
            "<script>alert(1)</script>Line one\nLine two",
            'https://covers.openlibrary.org/x.jpg',
        );
        self::assertSame('Title with control chars', $m->title);
        self::assertSame('', $m->subtitle);
        self::assertSame(['A', 'B', str_repeat('x', 255)], $m->authors);
        self::assertSame(1880, $m->publicationYear);
        self::assertNull($m->pageCount, 'Out of range page counts are dropped');
        self::assertSame('gl', $m->language);
        self::assertSame("alert(1)Line one\nLine two", $m->description, 'Tags removed, text kept as plain text');
    }

    public function testLanguageMappingAndMerge(): void
    {
        self::assertSame('es', BookMetadata::languageCode('spa'));
        self::assertSame('eu', BookMetadata::languageCode('baq'));
        self::assertSame('pt', BookMetadata::languageCode('pt-BR'));
        self::assertSame('', BookMetadata::languageCode('tlh'));

        $merged = (new BookMetadata(title: 'First', publisher: ''))->mergedWith(new BookMetadata(title: 'Second', publisher: 'Fallback', language: 'gl'));
        self::assertSame(['First', 'Fallback', 'gl'], [$merged->title, $merged->publisher, $merged->language]);
    }

    public function testPrefillMapping(): void
    {
        [$work, $edition] = MetadataPrefill::inputs(new BookMetadata('T', 'S', ['A1', 'A2'], 'P', 1999, 300, 'gl', 'D', 'u'), $this->isbn);
        self::assertSame([['name' => 'A1', 'role' => 'author'], ['name' => 'A2', 'role' => 'author']], $work->authors);
        self::assertSame('D', $work->description);
        self::assertSame(['T', 'P', '1999', '300', 'gl', '9788437604947'], [$edition->title, $edition->publisher, $edition->publicationYear, $edition->pageCount, $edition->language, $edition->isbn]);
    }

    public function testStatusFailuresCarryTheStatusAndRetryAfter(): void
    {
        $http = (new FakeHttpClient())
            ->on(OpenLibraryProvider::editionUrl($this->isbn), new HttpResponse(503, 'down', 'text/plain', ['retry-after' => '120']))
            ->onPrefix('https://www.googleapis.com/books/v1/volumes', new HttpResponse(429, '{"error":{"message":"secret detail"}}'));

        try {
            (new OpenLibraryProvider($http))->fetch($this->isbn);
            self::fail('Expected an exception');
        } catch (HttpException $e) {
            self::assertSame([503, '120', 'Open Library returned HTTP 503'], [$e->status, $e->retryAfter, $e->getMessage()]);
        }
        try {
            (new GoogleBooksProvider($http))->fetch($this->isbn);
            self::fail('Expected an exception');
        } catch (HttpException $e) {
            self::assertSame([429, null], [$e->status, $e->retryAfter]);
            self::assertStringNotContainsString('secret detail', $e->getMessage(), 'Response bodies are never part of the message');
        }
    }

    public function testResponseHeadersAreCaseInsensitive(): void
    {
        $response = new HttpResponse(429, '', '', ['retry-after' => '5']);
        self::assertSame('5', $response->header('Retry-After'));
        self::assertNull($response->header('X-Missing'));
    }
}
