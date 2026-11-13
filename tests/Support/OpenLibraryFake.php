<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\Provider\OpenLibraryProvider;

/**
 * Deterministic Open Library responses (edition by ISBN, search, work) in
 * the documented JSON shapes, for FakeHttpClient. Bibliographic values are
 * written for the tests; no real record text is copied.
 */
final class OpenLibraryFake
{
    /**
     * An edition that lists the ISBN, its work (with authors) in search, and
     * optionally other records claiming the same ISBN.
     *
     * @param array<string, mixed> $edition overrides: title, subtitle, publishers, publish_date,
     *        number_of_pages, covers, languages (codes), description, key, work, created, source_records
     * @param list<string>         $authors
     * @param list<array{key: string, work: string, title: string, authors: list<string>, publisher?: string, date?: string}> $others
     */
    public static function book(FakeHttpClient $http, string $isbn13, array $edition, array $authors = [], ?string $workDescription = null, array $others = []): void
    {
        $isbn = Isbn::tryParse($isbn13) ?? throw new \InvalidArgumentException($isbn13);
        $key = (string) ($edition['key'] ?? 'OL1M');
        $work = (string) ($edition['work'] ?? 'OL1W');
        $json = [
            'key' => '/books/' . $key,
            'type' => ['key' => '/type/edition'],
            'title' => $edition['title'] ?? 'A title',
            'publishers' => $edition['publishers'] ?? [],
            'publish_date' => $edition['publish_date'] ?? '',
            'isbn_13' => [$isbn->isbn13],
            'works' => [['key' => '/works/' . $work]],
            'languages' => array_map(static fn (string $c): array => ['key' => '/languages/' . $c], $edition['languages'] ?? []),
            'created' => ['type' => '/type/datetime', 'value' => $edition['created'] ?? '2015-01-01T00:00:00.000000'],
            'source_records' => array_fill(0, (int) ($edition['source_records'] ?? 1), 'marc:test'),
        ];
        if ($isbn->isbn10 !== null) {
            $json['isbn_10'] = [$isbn->isbn10];
        }
        foreach (['subtitle', 'number_of_pages', 'covers'] as $field) {
            if (isset($edition[$field])) {
                $json[$field] = $edition[$field];
            }
        }
        if (isset($edition['description'])) {
            $json['description'] = ['type' => '/type/text', 'value' => $edition['description']];
        }
        $http->on(OpenLibraryProvider::editionUrl($isbn), new HttpResponse(200, (string) json_encode($json), 'application/json'));
        $docs = [self::doc($work, (string) $json['title'], $authors, [[
            'key' => '/books/' . $key, 'title' => $json['title'], 'isbn' => array_values(array_filter([$isbn->isbn13, $isbn->isbn10])),
            'publisher' => $json['publishers'], 'publish_date' => [$json['publish_date']],
        ]])];
        foreach ($others as $other) {
            $docs[] = self::doc($other['work'], $other['title'], $other['authors'], [[
                'key' => '/books/' . $other['key'], 'title' => $other['title'], 'isbn' => [$isbn->isbn13],
                'publisher' => isset($other['publisher']) ? [$other['publisher']] : [], 'publish_date' => isset($other['date']) ? [$other['date']] : [],
            ]]);
        }
        self::search($http, $isbn, $docs);
        if (!isset($edition['description'])) {
            $workJson = ['key' => '/works/' . $work, 'title' => $json['title'], 'subjects' => ['Testing']];
            if ($workDescription !== null) {
                $workJson['description'] = ['type' => '/type/text', 'value' => $workDescription];
            }
            $http->on(OpenLibraryProvider::workUrl($work), new HttpResponse(200, (string) json_encode($workJson), 'application/json'));
        }
    }

    /**
     * No edition record (404, as Open Library answers), and these search docs.
     *
     * @param list<array<string, mixed>> $docs
     */
    public static function missing(FakeHttpClient $http, string $isbn13, array $docs = []): void
    {
        $isbn = Isbn::tryParse($isbn13) ?? throw new \InvalidArgumentException($isbn13);
        $http->on(OpenLibraryProvider::editionUrl($isbn), new HttpResponse(404, '<html>not found</html>', 'text/html'));
        self::search($http, $isbn, $docs);
    }

    /**
     * The edition endpoint fails (status, or a transport error).
     */
    public static function failing(FakeHttpClient $http, string $isbn13, HttpResponse|HttpException $failure): void
    {
        $isbn = Isbn::tryParse($isbn13) ?? throw new \InvalidArgumentException($isbn13);
        $http->on(OpenLibraryProvider::editionUrl($isbn), $failure);
        $http->on(OpenLibraryProvider::searchUrl($isbn), $failure);
    }

    /**
     * @param list<string>               $authors
     * @param list<array<string, mixed>> $editions
     *
     * @return array<string, mixed>
     */
    public static function doc(string $work, string $title, array $authors, array $editions): array
    {
        return ['key' => '/works/' . $work, 'title' => $title, 'author_name' => $authors, 'editions' => ['numFound' => count($editions), 'start' => 0, 'docs' => $editions]];
    }

    /**
     * @param list<array<string, mixed>> $docs
     */
    public static function search(FakeHttpClient $http, Isbn $isbn, array $docs): void
    {
        $http->on(OpenLibraryProvider::searchUrl($isbn), new HttpResponse(200, (string) json_encode(['numFound' => count($docs), 'start' => 0, 'docs' => $docs]), 'application/json'));
    }

    /**
     * Cien años de soledad, as the existing lookup tests expect it.
     */
    public static function cienAnos(FakeHttpClient $http, string $isbn13 = '9788437604947'): void
    {
        self::book($http, $isbn13, [
            'title' => 'Cien años de soledad', 'publishers' => ['Cátedra'], 'publish_date' => '2007',
            'number_of_pages' => 471, 'covers' => [1], 'key' => 'OL100M', 'work' => 'OL100W',
        ], ['Gabriel García Márquez']);
    }
}
