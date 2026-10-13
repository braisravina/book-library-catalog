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

    public function testOpenLibraryParsesBooksApiPayload(): void
    {
        $http = (new FakeHttpClient())->on(OpenLibraryProvider::url($this->isbn), new HttpResponse(200, (string) file_get_contents(self::FIXTURES . 'openlibrary_9788437604947.json')));
        $provider = new OpenLibraryProvider($http);

        $payload = $provider->fetch($this->isbn);
        self::assertNotNull($payload);
        $m = $provider->normalize($payload, $this->isbn);
        self::assertSame('Cien años de soledad', $m->title);
        self::assertSame(['Gabriel García Márquez'], $m->authors);
        self::assertSame('Cátedra', $m->publisher);
        self::assertSame(2007, $m->publicationYear);
        self::assertSame(471, $m->pageCount);
        self::assertSame('https://covers.openlibrary.org/b/id/1-L.jpg', $m->coverUrl);
        self::assertSame(['https://openlibrary.org/api/books?bibkeys=ISBN:9788437604947&format=json&jscmd=data'], $http->requested);
    }

    public function testOpenLibraryNotFoundAndErrors(): void
    {
        $url = OpenLibraryProvider::url($this->isbn);
        self::assertNull((new OpenLibraryProvider((new FakeHttpClient())->on($url, new HttpResponse(200, '{}'))))->fetch($this->isbn));

        foreach ([new HttpResponse(503, ''), new HttpResponse(200, 'not json')] as $response) {
            try {
                (new OpenLibraryProvider((new FakeHttpClient())->on($url, $response)))->fetch($this->isbn);
                self::fail('Expected HttpException');
            } catch (HttpException) {
                $this->addToAssertionCount(1);
            }
        }
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
}
