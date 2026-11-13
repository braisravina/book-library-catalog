<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\Provider\OpenLibraryProvider;
use DigitalLibrary\Tests\Support\FakeHttpClient;
use DigitalLibrary\Tests\Support\OpenLibraryFake;
use DigitalLibrary\Tests\Support\RecordingPacer;
use PHPUnit\Framework\TestCase;

/**
 * Open Library: exact edition, search fallback, work enrichment and the
 * plausibility signals, against deterministic responses only.
 */
final class OpenLibraryProviderTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../Fixtures/metadata/';
    private const CLEAN_CODE = '9780132350884';

    private static function isbn(string $value): Isbn
    {
        return Isbn::tryParse($value) ?? throw new \LogicException($value);
    }

    private static function fixture(string $name): HttpResponse
    {
        return new HttpResponse(200, (string) file_get_contents(self::FIXTURES . $name), 'application/json');
    }

    public function testExactEditionWithAuthorsFromSearchAndAPacedBoundedRequestCount(): void
    {
        $http = new FakeHttpClient();
        OpenLibraryFake::book($http, '9788437604947', [
            'title' => 'Cien años de soledad', 'publishers' => ['Cátedra'], 'publish_date' => 'March 2007',
            'number_of_pages' => 471, 'covers' => [-1, 12], 'languages' => ['spa'], 'key' => 'OL5M', 'work' => 'OL5W',
        ], ['Gabriel García Márquez'], 'A work-level description.');
        $pacer = new RecordingPacer();
        $provider = new OpenLibraryProvider($http, $pacer);
        $isbn = self::isbn('9788437604947');

        $payload = $provider->fetch($isbn);
        self::assertNotNull($payload);
        self::assertTrue($provider->isCurrent($payload));
        self::assertSame([OpenLibraryProvider::editionUrl($isbn), OpenLibraryProvider::searchUrl($isbn), OpenLibraryProvider::workUrl('OL5W')], $http->requested, 'At most three requests, edition first');
        self::assertSame(['open_library:required', 'open_library:optional', 'open_library:optional'], $pacer->acquired, 'Every request is paced; enrichment is optional');
        $record = $provider->describe($payload, $isbn);
        $m = $record->metadata;
        self::assertSame(['Cien años de soledad', ['Gabriel García Márquez'], 'Cátedra', 2007, 471, 'es'], [$m->title, $m->authors, $m->publisher, $m->publicationYear, $m->pageCount, $m->language]);
        self::assertSame('https://covers.openlibrary.org/b/id/12-L.jpg?default=false', $m->coverUrl, 'By cover ID; negative IDs skipped');
        self::assertSame('A work-level description.', $m->description);
        self::assertSame(['ol_edition' => 'OL5M', 'ol_work' => 'OL5W'], $record->identifiers);
        self::assertSame('work', $record->fieldOrigins['description'], 'Work-level data is labelled');
        self::assertSame('edition', $record->fieldOrigins['publisher']);
        self::assertSame('search', $record->fieldOrigins['authors']);
        self::assertSame([], $record->warnings);
        self::assertFalse($record->questionable());
    }

    public function testWorkDataNeverReplacesEditionData(): void
    {
        $http = new FakeHttpClient();
        OpenLibraryFake::book($http, self::CLEAN_CODE, ['title' => 'Edition title', 'description' => 'Edition description.', 'key' => 'OL9M', 'work' => 'OL9W'], ['A. Author']);
        $http->on(OpenLibraryProvider::workUrl('OL9W'), new HttpResponse(200, '{"key":"/works/OL9W","title":"Work title","description":"Work description."}'));
        $provider = new OpenLibraryProvider($http);
        $record = $provider->describe($provider->fetch(self::isbn(self::CLEAN_CODE)) ?? [], self::isbn(self::CLEAN_CODE));
        self::assertSame(['Edition title', 'Edition description.'], [$record->metadata->title, $record->metadata->description]);
        self::assertNotContains(OpenLibraryProvider::workUrl('OL9W'), $http->requested, 'No work request when the edition has a description');
    }

    public function testAnEditionThatDoesNotListTheIsbnIsNeverUsed(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        $http = (new FakeHttpClient())
            ->on(OpenLibraryProvider::editionUrl($isbn), new HttpResponse(200, '{"key":"/books/OL3M","title":"Another book","isbn_13":["9780306406157"],"works":[{"key":"/works/OL3W"}]}'));
        OpenLibraryFake::search($http, $isbn, []);
        $http->on(OpenLibraryProvider::workUrl('OL3W'), new HttpResponse(200, '{"key":"/works/OL3W"}'));
        $provider = new OpenLibraryProvider($http);
        $record = $provider->describe($provider->fetch($isbn) ?? [], $isbn);
        self::assertTrue($record->metadata->isEmpty());
        self::assertSame([], $record->identifiers);
    }

    public function testSearchFallbackUsesOnlyTheEditionThatListsTheIsbnAndNeverJustTheFirstResult(): void
    {
        $isbn = self::isbn('9788437604947');
        $http = new FakeHttpClient();
        OpenLibraryFake::missing($http, $isbn->isbn13, [
            // First result: an unrelated work, with an edition carrying a DIFFERENT ISBN.
            OpenLibraryFake::doc('OL1W', 'Unrelated first result', ['Someone'], [['key' => '/books/OL1M', 'title' => 'Unrelated first result', 'isbn' => ['9780306406157']]]),
            // The real work, with two editions; only one lists the ISBN.
            OpenLibraryFake::doc('OL2W', 'Cien años de soledad', ['Gabriel García Márquez'], [
                ['key' => '/books/OL20M', 'title' => 'Cien años de soledad (otra edición)', 'isbn' => ['9780060883287'], 'publisher' => ['Harper']],
                ['key' => '/books/OL21M', 'title' => 'Cien años de soledad', 'isbn' => ['843760494X'], 'publisher' => ['Cátedra'], 'publish_date' => ['2007'], 'language' => ['spa']],
            ]),
        ]);
        $provider = new OpenLibraryProvider($http);
        $payload = $provider->fetch($isbn);
        self::assertNotNull($payload);
        self::assertCount(2, $http->requested, 'No work request without an edition record');
        $record = $provider->describe($payload, $isbn);
        $m = $record->metadata;
        self::assertSame(['Cien años de soledad', ['Gabriel García Márquez'], 'Cátedra', 2007, 'es'], [$m->title, $m->authors, $m->publisher, $m->publicationYear, $m->language]);
        self::assertSame(['ol_edition' => 'OL21M', 'ol_work' => 'OL2W'], $record->identifiers);
        self::assertSame('search', $record->fieldOrigins['title']);
        self::assertSame([], $record->candidates);
    }

    public function testSeveralWorksClaimingTheIsbnWithoutAnEditionAreAmbiguous(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        $http = new FakeHttpClient();
        OpenLibraryFake::missing($http, $isbn->isbn13, [
            OpenLibraryFake::doc('OL1W', 'Book one', ['A'], [['key' => '/books/OL1M', 'title' => 'Book one', 'isbn' => [$isbn->isbn13]]]),
            OpenLibraryFake::doc('OL2W', 'Book two', [], [['key' => '/books/OL2M', 'title' => 'Book two', 'isbn' => ['0132350882']]]),
        ]);
        $provider = new OpenLibraryProvider($http);
        $record = $provider->describe($provider->fetch($isbn) ?? [], $isbn);
        self::assertTrue($record->metadata->isEmpty(), 'None is chosen');
        self::assertSame(['OL1M', 'OL2M'], array_column($record->candidates, 'edition'));
        self::assertSame([MetadataChecks::ISBN_SHARED], $record->warnings);
    }

    public function testNoEditionAndNoSearchResultIsNotFound(): void
    {
        $http = new FakeHttpClient();
        OpenLibraryFake::missing($http, '9788409779550', []);
        self::assertNull((new OpenLibraryProvider($http))->fetch(self::isbn('9788409779550')));
    }

    /**
     * Regression: the same ISBN is attached to the genuine edition and to an
     * unrelated promotional edition (seen live for 9780132350884).
     */
    public function testThePromotionalEditionIsQuestionableAndTheGenuineOneIsNot(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        foreach (['spam' => true, 'real' => false] as $which => $questionable) {
            $http = (new FakeHttpClient())
                ->on(OpenLibraryProvider::editionUrl($isbn), self::fixture('openlibrary_edition_9780132350884_' . $which . '.json'))
                ->on(OpenLibraryProvider::searchUrl($isbn), self::fixture('openlibrary_search_9780132350884.json'))
                ->onPrefix('https://openlibrary.org/works/', new HttpResponse(404, ''));
            $provider = new OpenLibraryProvider($http);
            $record = $provider->describe($provider->fetch($isbn) ?? [], $isbn);
            self::assertSame($questionable, $record->questionable(), $which);
            self::assertContains(MetadataChecks::ISBN_SHARED, $record->warnings, $which . ': the other claimant is reported');
            self::assertCount(1, $record->candidates, $which);
            if ($questionable) {
                self::assertSame('Clean Code', $record->candidates[0]['title']);
                self::assertSame(['Robert C. Martin'], $record->candidates[0]['authors']);
                foreach ([MetadataChecks::PROMOTIONAL, MetadataChecks::NO_AUTHORS, MetadataChecks::TITLE_IS_PUBLISHER_NO_AUTHOR] as $code) {
                    self::assertContains($code, $record->warnings, $code);
                }
            } else {
                self::assertSame('The Earn2Trade Discount Code', $record->candidates[0]['title']);
                self::assertSame(['Clean Code', ['Robert C. Martin'], 'Prentice Hall', 2008, 431, 'en'], [$record->metadata->title, $record->metadata->authors, $record->metadata->publisher, $record->metadata->publicationYear, $record->metadata->pageCount, $record->metadata->language]);
            }
        }
    }

    public function testFailuresAreClassifiedAndRateLimitsCarryRetryAfter(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        $cases = [
            '429' => [new HttpResponse(429, '', '', ['retry-after' => '30']), HttpException::STATUS, 429, '30'],
            '503' => [new HttpResponse(503, ''), HttpException::STATUS, 503, null],
            'malformed JSON' => [new HttpResponse(200, '{"key": '), HttpException::INVALID, null, null],
            'edition without a key' => [new HttpResponse(200, '{"title":"x"}'), HttpException::INVALID, null, null],
            'TLS' => [new HttpException('cert', null, null, HttpException::TLS), HttpException::TLS, null, null],
            'DNS' => [new HttpException('dns', null, null, HttpException::DNS), HttpException::DNS, null, null],
        ];
        foreach ($cases as $label => [$failure, $kind, $status, $retry]) {
            try {
                (new OpenLibraryProvider((new FakeHttpClient())->on(OpenLibraryProvider::editionUrl($isbn), $failure)))->fetch($isbn);
                self::fail('Expected a failure: ' . (string) $label);
            } catch (HttpException $e) {
                self::assertSame([$kind, $status, $retry], [$e->kind, $e->status, $e->retryAfter], (string) $label);
            }
        }
        // Without an edition, a failing search is the provider's failure (not "not found").
        $http = new FakeHttpClient();
        $http->on(OpenLibraryProvider::editionUrl($isbn), new HttpResponse(404, ''));
        $http->on(OpenLibraryProvider::searchUrl($isbn), new HttpResponse(200, '{"numFound":1}'));
        try {
            (new OpenLibraryProvider($http))->fetch($isbn);
            self::fail('A search response without docs was accepted');
        } catch (HttpException $e) {
            self::assertSame(HttpException::INVALID, $e->kind);
        }
    }

    public function testSecondaryFailuresKeepTheEditionAndShareTheBackoff(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        $http = (new FakeHttpClient())
            ->on(OpenLibraryProvider::editionUrl($isbn), self::fixture('openlibrary_edition_9780132350884_real.json'))
            ->on(OpenLibraryProvider::searchUrl($isbn), new HttpResponse(429, '', '', ['retry-after' => '45']));
        $provider = new OpenLibraryProvider($http);
        $payload = $provider->fetch($isbn) ?? [];
        self::assertSame(['search:429'], $payload['incomplete']);
        self::assertSame('45', $payload['_retry_after'], 'MetadataService records the shared Open Library pause');
        $record = $provider->describe($payload, $isbn);
        self::assertSame('Clean Code', $record->metadata->title);
        self::assertSame([], $record->metadata->authors, 'Author names come from search, which failed');
        self::assertContains(MetadataChecks::INCOMPLETE, $record->warnings);
    }

    public function testPacingSkipsOptionalRequestsAndRefusesARequiredOneWithoutWaitingLonger(): void
    {
        $isbn = self::isbn('9788437604947');
        $http = new FakeHttpClient();
        OpenLibraryFake::cienAnos($http);
        $pacer = new RecordingPacer();
        $pacer->refuseOptional = true;
        $payload = (new OpenLibraryProvider($http, $pacer))->fetch($isbn) ?? [];
        self::assertSame(['search:paced', 'work:paced'], $payload['incomplete']);
        self::assertSame([OpenLibraryProvider::editionUrl($isbn)], $http->requested);

        $http = new FakeHttpClient();
        OpenLibraryFake::missing($http, $isbn->isbn13, []);
        $refusing = new class () implements \DigitalLibrary\Metadata\Backoff\RequestPacer {
            private int $calls = 0;

            public function acquire(string $provider, bool $optional): bool
            {
                return $this->calls++ === 0;
            }
        };
        try {
            (new OpenLibraryProvider($http, $refusing))->fetch($isbn);
            self::fail('Expected a paced failure');
        } catch (HttpException $e) {
            self::assertSame(HttpException::PACED, $e->kind);
        }
    }

    public function testResponsesAreBoundedAndValidated(): void
    {
        $isbn = self::isbn(self::CLEAN_CODE);
        $edition = [
            'key' => '/books/OL1M', 'title' => str_repeat('T', 900), 'isbn_13' => array_merge(array_fill(0, 40, '9780306406157'), [$isbn->isbn13]),
            'publishers' => array_fill(0, 30, 'P'), 'works' => [['key' => '/works/OL1W'], ['key' => '../../etc']], 'covers' => ['x', 7],
            'languages' => [['key' => '/languages/<script>']],
        ];
        $http = (new FakeHttpClient())->on(OpenLibraryProvider::editionUrl($isbn), new HttpResponse(200, (string) json_encode($edition)));
        OpenLibraryFake::search($http, $isbn, []);
        $http->on(OpenLibraryProvider::workUrl('OL1W'), new HttpResponse(200, '{"key":"/works/OL1W"}'));
        $payload = (new OpenLibraryProvider($http))->fetch($isbn) ?? [];
        self::assertSame(500, mb_strlen($payload['edition']['title']));
        self::assertCount(20, $payload['edition']['isbn_13'], 'At most 20 identifiers kept');
        self::assertCount(5, $payload['edition']['publishers']);
        self::assertSame(['/works/OL1W'], $payload['edition']['works'], 'Invalid keys dropped');
        self::assertSame([7], $payload['edition']['covers']);
        self::assertSame([], $payload['edition']['languages']);
        // The ISBN was beyond the 20 kept: the edition is not trusted to list it.
        self::assertTrue((new OpenLibraryProvider(new FakeHttpClient()))->describe($payload, $isbn)->metadata->isEmpty());
    }
}
