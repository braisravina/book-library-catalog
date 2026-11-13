<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Security\Role;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * ISBN-assisted entry through the full stack, offline (FakeHttpClient):
 * ISBN step feedback, the lookup review, existing-work suggestions,
 * provider backoff and translations.
 */
final class IsbnAssistedEntryTest extends IntegrationTestCase
{
    private const A = '9788437604947';
    private const B = '9780306406157';

    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    private static function ol(string $isbn): string
    {
        return 'https://openlibrary.org/api/books?bibkeys=ISBN:' . $isbn . '&format=json&jscmd=data';
    }

    private static function gb(string $isbn): string
    {
        return 'https://www.googleapis.com/books/v1/volumes?q=isbn:' . $isbn;
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function olSays(string $isbn, array $record, int $status = 200): void
    {
        self::$http->on(self::ol($isbn), new HttpResponse($status, (string) json_encode(['ISBN:' . $isbn => $record])));
    }

    /**
     * @param array<string, mixed> $info
     */
    private static function gbSays(string $isbn, array $info): void
    {
        $info['industryIdentifiers'] = [['type' => 'ISBN_13', 'identifier' => $isbn]];
        self::$http->onPrefix(self::gb($isbn), new HttpResponse(200, (string) json_encode(['items' => [['volumeInfo' => $info]]])));
    }

    private static function gbStatus(string $isbn, int $status, ?string $retryAfter = null): void
    {
        self::$http->onPrefix(self::gb($isbn), new HttpResponse($status, '{}', 'application/json', $retryAfter === null ? [] : ['retry-after' => $retryAfter]));
    }

    /** A valid ISBN-13 from a 12-digit stem. */
    private static function isbn(string $stem): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $stem[$i] * ($i % 2 === 0 ? 1 : 3);
        }
        $isbn = $stem . ((10 - $sum % 10) % 10);
        self::assertNotNull(Isbn::tryParse($isbn));

        return $isbn;
    }

    private function cienAnos(): void
    {
        self::olSays(self::A, [
            'title' => 'Cien años de soledad',
            'authors' => [['name' => 'Gabriel García Márquez']],
            'publishers' => [['name' => 'Cátedra']],
            'publish_date' => '2007',
            'number_of_pages' => 471,
            'cover' => ['large' => 'https://covers.openlibrary.org/b/id/1-L.jpg'],
        ]);
        self::gbSays(self::A, [
            'title' => 'Cien Años de Soledad',
            'authors' => ['Gabriel García Márquez'],
            'publisher' => 'Ediciones Cátedra',
            'publishedDate' => '2008',
            'language' => 'es',
            'description' => 'La historia de la familia Buendía.',
        ]);
    }

    private function requestsTo(string $prefix): int
    {
        return count(array_filter(self::$http->requested, static fn (string $url): bool => str_starts_with($url, $prefix)));
    }

    // ISBN step ---------------------------------------------------------------

    public function testIsbnStepOffersLookupManualEntryAndAccessibleFeedback(): void
    {
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new')->body();

        self::assertStringContainsString('data-isbn-lookup', $html);
        self::assertStringContainsString('>Look up book</button>', $html);
        self::assertStringContainsString('name="manual" value="1" formnovalidate data-isbn-manual', $html);
        self::assertStringContainsString('href="/books/new?manual=1"', $html);
        self::assertStringContainsString('<label for="isbn">ISBN</label>', $html);
        self::assertStringContainsString('aria-describedby="isbn-hint isbn-check"', $html);
        self::assertStringContainsString('<p class="isbn-check" id="isbn-check" aria-live="polite" data-isbn-check></p>', $html);
        self::assertStringContainsString('role="status" data-lookup-status data-message="Looking up the book online. This can take a few seconds."', $html);
        // Phones: a text keyboard, so an ISBN-10 ending in X can be typed.
        self::assertStringContainsString('inputmode="text" autocapitalize="characters" autocorrect="off" spellcheck="false"', $html);
        self::assertStringContainsString('data-msg-length="An ISBN has 10 or 13 digits; this one has :count."', $html);
        self::assertSame([], self::$http->requested, 'Nothing is looked up until the user asks');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidIsbns(): iterable
    {
        yield 'characters' => ['97B-84-376', 'An ISBN contains only digits'];
        yield 'length' => ['12345', 'An ISBN has 10 or 13 digits; this one has 5.'];
        yield 'prefix' => ['977-0-306-40615-7', 'An ISBN-13 starts with 978 or 979.'];
        yield 'isbn-13 checksum' => ['978-0-306-40615-8', 'The check digit does not match.'];
        yield 'isbn-10 checksum' => ['0-306-40615-3', 'The check digit does not match.'];
    }

    #[DataProvider('invalidIsbns')]
    public function testInvalidIsbnGetsASpecificMessage(string $isbn, string $message): void
    {
        [$browser] = $this->signedIn('member@example.org');
        $response = $this->request($browser, 'GET', '/books/new?isbn=' . rawurlencode($isbn));

        self::assertSame(422, $response->status());
        self::assertStringContainsString($message, $response->body());
        self::assertStringContainsString('aria-describedby="isbn-hint isbn-errors isbn-check" aria-invalid="true"', $response->body());
        self::assertStringContainsString('value="' . $isbn . '"', $response->body(), 'What was typed is kept for correction');
        self::assertSame([], self::$http->requested);
    }

    public function testHostileIsbnInputIsEscaped(): void
    {
        [$browser] = $this->signedIn('member@example.org');
        $response = $this->request($browser, 'GET', '/books/new?isbn=' . rawurlencode('"><script>alert(1)</script>'));
        self::assertSame(422, $response->status());
        self::assertStringNotContainsString('<script>alert(1)', $response->body());
        self::assertStringContainsString('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"', $response->body());
    }

    public function testManualEntryKeepsTheIsbnAndSkipsTheLookup(): void
    {
        $this->cienAnos();
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=84-376-0494-X&manual=1')->body();
        self::assertStringContainsString('name="isbn" type="text" value="' . self::A . '"', $html);
        self::assertStringNotContainsString('Online lookup results', $html);
        self::assertSame([], self::$http->requested, 'Manual entry makes no outbound request');

        // An ISBN that does not validate is kept for the user to fix in the form.
        $html = $this->request($browser, 'GET', '/books/new?isbn=12345&manual=1')->body();
        self::assertStringContainsString('name="isbn" type="text" value="12345"', $html);
        self::assertSame([], self::$http->requested);
    }

    public function testManualEntryStillDetectsDuplicates(): void
    {
        $book = $this->makeBook($this->memberId, 'Existing', self::B);
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=0306406152&manual=1')->body();
        self::assertStringContainsString('href="/editions/' . $book['edition_id'] . '"', $html);
        self::assertStringContainsString('Already in the catalogue', $html);
    }

    public function testWithoutProvidersTheStepJustContinues(): void
    {
        self::$configOverrides['METADATA_PROVIDERS'] = 'none';
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new')->body();
        self::assertStringContainsString('>Continue</button>', $html);
        self::assertStringNotContainsString('data-isbn-manual', $html);
    }

    // Review --------------------------------------------------------------------

    public function testReviewShowsSourcesConflictsAndMissingFieldsWithEditableForm(): void
    {
        $this->cienAnos();
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();

        self::assertStringContainsString('<h2 id="lookup-review-title">Online lookup results</h2>', $html);
        self::assertStringContainsString('<dt><a href="#title">Title</a></dt>', $html);
        self::assertMatchesRegularExpression('#<a href="\#title">Title</a></dt>\s*<dd>\s*<span class="lookup-value">Cien años de soledad</span>\s*<span class="lookup-source">from Open Library</span>\s*</dd>#', $html, 'Case differences are not a conflict');
        self::assertMatchesRegularExpression('#<a href="\#language">Language</a></dt>\s*<dd>\s*<span class="lookup-value">Spanish</span>\s*<span class="lookup-source">from Google Books</span>#', $html);
        self::assertStringContainsString('The sources disagree on some details.', $html);
        self::assertStringContainsString('<span class="lookup-alt-label">Other value found (Google Books):</span>', $html);
        self::assertStringContainsString('<button type="button" class="button button-small" hidden data-fill-target="publisher" data-fill-value="Ediciones Cátedra">Use this value', $html);
        self::assertStringContainsString('data-fill-target="publication_year" data-fill-value="2008"', $html);
        self::assertStringContainsString('<span class="lookup-value">Cover image available</span>', $html);
        self::assertStringNotContainsString('covers.openlibrary.org', $html, 'Image URLs are not shown');
        self::assertMatchesRegularExpression('#<h3>Not found online</h3>.*<li><a href="\#subtitle">Subtitle</a></li>#s', $html);

        // Every value stays in an editable field; the priority source fills it.
        self::assertStringContainsString('id="publisher" name="publisher" type="text" value="Cátedra"', $html);
        self::assertStringContainsString('id="publication_year" name="publication_year" type="text" value="2007"', $html);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM editions'), 'Nothing is saved before review');
    }

    public function testHostileAlternativeValuesAreEscaped(): void
    {
        self::olSays(self::A, ['title' => 'Safe', 'publishers' => [['name' => 'Plain']]]);
        self::gbSays(self::A, ['title' => 'Safe', 'publisher' => '"><img src=x onerror=alert(1)>', 'description' => '<script>alert(2)</script>' . str_repeat('x', 300)]);
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();

        self::assertStringNotContainsString('<img src=x', $html);
        self::assertStringNotContainsString('<script>alert(2)', $html);
        self::assertStringContainsString('data-fill-value="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"', $html);
    }

    public function testReviewIsShownAgainFromTheCacheAfterValidationErrors(): void
    {
        $this->cienAnos();
        [$browser, $token] = $this->signedIn('member@example.org');
        $this->request($browser, 'GET', '/books/new?isbn=' . self::A);
        $recordId = $this->countRows("SELECT id FROM metadata_records WHERE provider = 'open_library'");
        $requests = count(self::$http->requested);

        $response = $this->request($browser, 'POST', '/books', ['_csrf' => $token, 'title' => '', 'isbn' => self::A, 'publisher' => 'Mine', 'metadata_record_id' => (string) $recordId]);
        self::assertSame(422, $response->status());
        self::assertStringContainsString('Online lookup results', $response->body());
        self::assertStringContainsString('value="Mine"', $response->body(), 'What the user typed wins over provider data');
        self::assertStringContainsString('name="metadata_record_id" value="' . $recordId . '"', $response->body());
        self::assertSame($requests, count(self::$http->requested), 'No outbound request on POST');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM works'));
    }

    // Existing works ------------------------------------------------------------

    public function testSimilarWorksAreSuggestedButNeverMerged(): void
    {
        $exact = $this->makeBook($this->memberId, 'Cien años de soledad', null, ['Gabriel García Márquez'])['work_id'];
        $otherAuthor = $this->makeBook($this->memberId, 'CIEN ANOS DE SOLEDAD', null, ['Otro Autor'])['work_id'];
        $longer = $this->makeBook($this->memberId, 'Cien años de soledad (edición ilustrada)', null, ['Gabriel García Márquez'])['work_id'];
        $sameAuthor = $this->makeBook($this->memberId, 'El otoño del patriarca', null, ['Gabriel García Márquez'])['work_id'];
        $sameWords = $this->makeBook($this->memberId, 'Cien años de soledad ilustrada', null, ['Someone Else'])['work_id'];
        $this->cienAnos();
        [$browser, $token] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('<h2 id="similar-works-title">Possibly already in the catalogue</h2>', $html);
        $link = static fn (int $id): string => 'href="/works/' . $id . '/editions/new?isbn=' . self::A . '"';
        $positions = array_map(static fn (int $id): int|false => strpos($html, $link($id)), [$exact, $otherAuthor, $longer]);
        self::assertNotContains(false, $positions);
        self::assertSame($positions, array_values(array_unique($positions)));
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions, 'Same title and author first, then same title, then same author');
        self::assertStringNotContainsString($link($sameAuthor), $html, 'Another book by the same author is not suggested');
        self::assertStringNotContainsString($link($sameWords), $html, 'Similar words without a shared author are not suggested');
        self::assertSame(2, substr_count($html, '<span class="badge">Same author</span>'));
        self::assertStringContainsString('If none of these is the same book, continue with the form below.', $html);
        self::assertSame(5, $this->countRows('SELECT COUNT(*) FROM works'), 'Nothing merged or created');

        // "Add as a new edition": pre-filled from the cached lookup, no request.
        $requests = count(self::$http->requested);
        $form = $this->request($browser, 'GET', '/works/' . $exact . '/editions/new?isbn=' . self::A)->body();
        self::assertSame($requests, count(self::$http->requested));
        self::assertStringContainsString('Edition details were filled in from the earlier online lookup.', $form);
        self::assertStringContainsString('name="isbn" type="text" value="' . self::A . '"', $form);
        self::assertStringContainsString('name="publisher" type="text" value="Cátedra"', $form);

        $response = $this->request($browser, 'POST', '/works/' . $exact . '/editions', [
            '_csrf' => $token, 'edition_title' => 'Cien años de soledad', 'isbn' => self::A, 'publisher' => 'Cátedra', 'publication_year' => '2007',
        ]);
        self::assertSame(303, $response->status());
        self::assertSame($exact, $this->countRows("SELECT work_id FROM editions WHERE isbn_13 = '" . self::A . "'"));
        self::assertSame(5, $this->countRows('SELECT COUNT(*) FROM works'), 'Still one work per book');
    }

    public function testTitleWildcardsMatchLiterally(): void
    {
        $this->makeBook($this->memberId, 'Anything at all', null, ['Gabriel García Márquez']);
        self::olSays(self::A, ['title' => '%_', 'authors' => [['name' => 'Gabriel García Márquez']]]);
        self::gbSays(self::A, []);
        [$browser] = $this->signedIn('member@example.org');
        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('Online lookup results', $html);
        self::assertStringNotContainsString('Possibly already in the catalogue', $html);
    }

    public function testNewEditionIsbnParameterIsValidatedAndNeedsPermission(): void
    {
        $work = $this->makeBook($this->memberId, 'A work')['work_id'];
        $this->createUser('viewer@example.org', Role::Viewer);
        [$member] = $this->signedIn('member@example.org');

        $html = $this->request($member, 'GET', '/works/' . $work . '/editions/new?isbn=' . rawurlencode('"><b>x'))->body();
        self::assertStringContainsString('name="isbn" type="text" value=""', $html, 'An invalid ISBN parameter is ignored');
        $html = $this->request($member, 'GET', '/works/' . $work . '/editions/new?isbn=0306406152')->body();
        self::assertStringContainsString('name="isbn" type="text" value="' . self::B . '"', $html, 'Normalised to ISBN-13');
        self::assertStringNotContainsString('earlier online lookup', $html, 'Nothing cached: nothing pre-filled');
        self::assertSame([], self::$http->requested, 'The new-edition form never contacts providers');

        [$viewer] = $this->signedIn('viewer@example.org');
        self::assertSame(403, $this->request($viewer, 'GET', '/works/' . $work . '/editions/new?isbn=' . self::B)->status());
        self::assertSame(403, $this->request($viewer, 'GET', '/books/new?isbn=' . self::B)->status());
    }

    // Provider reliability --------------------------------------------------------

    public function testRateLimitedProviderIsPausedWhileOthersAreStillUsed(): void
    {
        $c = self::isbn('978000000001');
        $this->cienAnos();
        self::gbStatus(self::A, 429, '120');
        foreach ([self::B, $c] as $isbn) {
            self::olSays($isbn, ['title' => 'Book ' . $isbn]);
            self::gbSays($isbn, ['title' => 'Book ' . $isbn, 'language' => 'en']);
        }
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('Details found online via Open Library.', $html);
        self::assertStringContainsString('Some online sources are busy and were skipped for now.', $html);
        self::assertSame(['failures' => 1, 'until' => self::$backoff->states['google_books']['until']], self::$backoff->states['google_books']);

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::B)->body();
        self::assertSame(0, $this->requestsTo(self::gb(self::B)), 'Google Books is not contacted during its pause');
        self::assertSame(1, $this->requestsTo(self::ol(self::B)), 'Open Library still answers');
        self::assertStringContainsString('value="Book ' . self::B . '"', $html);
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM metadata_records WHERE provider = 'google_books'"), 'A pause is not cached as "not found"');

        self::$clockOffset = 121;
        $this->request($browser, 'GET', '/books/new?isbn=' . $c);
        self::assertSame(1, $this->requestsTo(self::gb($c)), 'Contacted again once the pause is over');
        self::assertSame([], self::$backoff->states, 'Success clears the backoff');
    }

    public function testServerErrorsBackOffExponentially(): void
    {
        $isbns = [self::B, self::isbn('978000000001'), self::isbn('978000000002')];
        foreach ($isbns as $isbn) {
            self::olSays($isbn, ['title' => 'Book']);
        }
        self::gbStatus($isbns[0], 503);
        self::gbStatus($isbns[1], 502);
        self::gbStatus($isbns[2], 500);
        [$browser] = $this->signedIn('member@example.org');

        $start = time();
        $this->request($browser, 'GET', '/books/new?isbn=' . $isbns[0]);
        self::assertSame(1, self::$backoff->states['google_books']['failures']);
        self::assertEqualsWithDelta($start + 30, self::$backoff->states['google_books']['until'], 2);

        self::$clockOffset = 31;
        $this->request($browser, 'GET', '/books/new?isbn=' . $isbns[1]);
        self::assertSame(2, self::$backoff->states['google_books']['failures']);
        self::assertEqualsWithDelta($start + 31 + 60, self::$backoff->states['google_books']['until'], 2);

        self::$clockOffset = 31 + 61;
        $this->request($browser, 'GET', '/books/new?isbn=' . $isbns[2]);
        self::assertEqualsWithDelta($start + 92 + 120, self::$backoff->states['google_books']['until'], 2);
        self::assertSame(3, count(array_filter(self::$http->requested, static fn (string $u): bool => str_contains($u, 'googleapis'))), 'One request per lookup, never a retry loop');
    }

    public function testNotFoundAndOtherClientErrorsDoNotBackOff(): void
    {
        $c = self::isbn('978000000001');
        self::olSays(self::B, ['title' => 'Book']);
        self::gbStatus(self::B, 404);
        self::olSays($c, ['title' => 'Book']);
        self::$http->onPrefix(self::gb($c), new HttpResponse(200, '{"totalItems":0}'));
        [$browser] = $this->signedIn('member@example.org');

        $this->request($browser, 'GET', '/books/new?isbn=' . self::B);
        $this->request($browser, 'GET', '/books/new?isbn=' . $c);
        self::assertSame([], self::$backoff->states);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM metadata_records WHERE provider = 'google_books' AND isbn_13 = '" . $c . "'"), '"Not found" is cached instead');
    }

    public function testFallsBackToGoogleBooksWhenOpenLibraryFails(): void
    {
        self::$http->on(self::ol(self::A), new HttpResponse(500, 'oops'));
        self::gbSays(self::A, ['title' => 'Cien años de soledad', 'publisher' => 'Cátedra']);
        [$browser] = $this->signedIn('member@example.org');

        $html = $this->request($browser, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('Details found online via Google Books.', $html);
        self::assertStringContainsString('Some online sources are busy', $html);
        self::assertMatchesRegularExpression('#Cien años de soledad</span>\s*<span class="lookup-source">from Google Books</span>#', $html);
        self::assertStringContainsString('value="Cátedra"', $html);
        self::assertSame(1, self::$backoff->states['open_library']['failures']);
    }

    public function testWhenEveryProviderIsBusyManualEntryRemains(): void
    {
        self::$http->on(self::ol(self::A), new HttpResponse(429, '', '', ['retry-after' => '60']));
        self::gbStatus(self::A, 503);
        [$browser] = $this->signedIn('member@example.org');

        $response = $this->request($browser, 'GET', '/books/new?isbn=' . self::A);
        self::assertSame(200, $response->status());
        self::assertStringContainsString('The online sources are busy right now. Please enter the details manually', $response->body());
        self::assertStringContainsString('name="isbn" type="text" value="' . self::A . '"', $response->body());
        self::assertStringContainsString('>Add to catalogue</button>', $response->body());

        $requests = count(self::$http->requested);
        $this->request($browser, 'GET', '/books/new?isbn=' . self::A);
        self::assertSame($requests, count(self::$http->requested), 'Both providers are paused');
    }

    // Translations ----------------------------------------------------------------

    public function testIsbnStepAndReviewAreTranslated(): void
    {
        $this->makeBook($this->memberId, 'Cien años de soledad', null, ['Gabriel García Márquez']);
        $this->cienAnos();
        $this->pdo()->exec("UPDATE users SET locale = 'es'");
        [$es] = $this->signedIn('member@example.org');
        $html = $this->request($es, 'GET', '/books/new?isbn=978-0-306-40615-8')->body();
        self::assertStringContainsString('>Buscar libro</button>', $html);
        self::assertStringContainsString('Introducir los datos a mano', $html);
        self::assertStringContainsString('El dígito de control no coincide.', $html);
        self::assertStringContainsString('data-msg-length="Un ISBN tiene 10 o 13 dígitos; este tiene :count."', $html);
        $html = $this->request($es, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('Resultados de la búsqueda en línea', $html);
        self::assertStringContainsString('Puede que ya esté en el catálogo', $html);
        self::assertStringContainsString('Otro valor encontrado (Google Books):', $html);

        $this->pdo()->exec("UPDATE users SET locale = 'gl'");
        [$gl] = $this->signedIn('member@example.org');
        $html = $this->request($gl, 'GET', '/books/new?isbn=12345')->body();
        self::assertStringContainsString('>Buscar libro</button>', $html);
        self::assertStringContainsString('Un ISBN ten 10 ou 13 díxitos; este ten 5.', $html);
        $html = $this->request($gl, 'GET', '/books/new?isbn=' . self::A)->body();
        self::assertStringContainsString('Resultados da busca en liña', $html);
        self::assertStringContainsString('Pode que xa estea no catálogo', $html);
        self::assertStringContainsString('Engadir como nova edición', $html);
        self::assertStringContainsString('Non atopado en liña', $html);
    }
}
