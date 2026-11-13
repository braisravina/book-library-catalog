<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\MetadataReview;
use DigitalLibrary\Metadata\ProviderRecord;
use PHPUnit\Framework\TestCase;

final class MetadataReviewTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private static function byField(MetadataReview $review): array
    {
        $out = [];
        foreach ($review->found as $entry) {
            $out[$entry['field']] = $entry;
        }

        return $out;
    }

    public function testReportsSourceConflictsAndMissingFields(): void
    {
        $review = MetadataReview::fromProviders([
            'open_library' => new BookMetadata(title: 'Cien años de soledad', authors: ['Gabriel García Márquez'], publisher: 'Cátedra', publicationYear: 2007, pageCount: 471, coverUrl: 'https://covers.openlibrary.org/b/id/1-L.jpg'),
            'google_books' => new BookMetadata(title: 'CIEN AÑOS DE SOLEDAD.', authors: ['Gabriel García Márquez'], publisher: 'Ediciones Cátedra', publicationYear: 2008, language: 'es', description: 'La historia de los Buendía.'),
        ]);
        $fields = self::byField($review);

        self::assertSame(['title', 'authors', 'publisher', 'publication_year', 'page_count', 'language', 'description', 'cover'], array_keys($fields));
        self::assertSame(['open_library', []], [$fields['title']['source'], $fields['title']['alternatives']], 'Case and trailing punctuation are not conflicts');
        self::assertSame('Cátedra', $fields['publisher']['value']);
        self::assertSame([['provider' => 'google_books', 'value' => 'Ediciones Cátedra', 'origin' => null, 'questionable' => false]], $fields['publisher']['alternatives']);
        self::assertSame([['provider' => 'google_books', 'value' => '2008', 'origin' => null, 'questionable' => false]], $fields['publication_year']['alternatives']);
        self::assertSame('google_books', $fields['language']['source'], 'A gap in the first source is filled by the next one');
        self::assertSame(['subtitle'], $review->missing);
        self::assertTrue($review->hasConflicts());
    }

    public function testCoverIsReportedWithoutItsUrlAndNeverAsAConflict(): void
    {
        $review = MetadataReview::fromProviders([
            'open_library' => new BookMetadata(title: 'T', coverUrl: 'https://a.example/1.jpg'),
            'google_books' => new BookMetadata(title: 'T', coverUrl: 'https://b.example/2.jpg'),
        ]);
        $cover = self::byField($review)['cover'];
        self::assertSame(['', 'open_library', []], [$cover['value'], $cover['source'], $cover['alternatives']]);
        self::assertFalse($review->hasConflicts());
    }

    public function testAuthorOrderAndSpacingAreNotConflicts(): void
    {
        $review = MetadataReview::fromProviders([
            'open_library' => new BookMetadata(title: 'T', authors: ['Ana Pérez', 'Luis  Díaz']),
            'google_books' => new BookMetadata(title: 'T', authors: ['luis díaz', 'Ana Pérez']),
        ]);
        self::assertSame([], self::byField($review)['authors']['alternatives']);
        self::assertSame('Ana Pérez; Luis  Díaz', self::byField($review)['authors']['value']);
    }

    public function testSingleSourceAndEverythingMissing(): void
    {
        $review = MetadataReview::fromProviders(['open_library' => new BookMetadata(title: 'Only a title')]);
        self::assertSame(['title'], array_keys(self::byField($review)));
        self::assertSame(['subtitle', 'authors', 'publisher', 'publication_year', 'page_count', 'language', 'description', 'cover'], $review->missing);
        self::assertFalse($review->hasConflicts());

        self::assertSame(array_keys(MetadataReview::FIELDS), MetadataReview::fromProviders([])->missing);
    }

    public function testFieldLabelsExistInEveryLanguage(): void
    {
        foreach (['en', 'es', 'gl'] as $locale) {
            $strings = require dirname(__DIR__, 3) . '/lang/' . $locale . '.php';
            foreach (MetadataReview::FIELDS as [$label]) {
                self::assertIsString($strings['fields'][substr($label, 7)] ?? null, $locale . ': ' . $label);
            }
        }
    }

    public function testStatusesDistinguishIndependentCorroborationFromASingleProvider(): void
    {
        $ol = new ProviderRecord('open_library', new BookMetadata(title: 'Clean Code', authors: ['Robert C. Martin'], publisher: 'Prentice Hall', publicationYear: 2008), ['title' => 'edition', 'authors' => 'search']);
        $gb = new ProviderRecord('google_books', new BookMetadata(title: 'Clean code.', authors: ['Robert C. Martin'], publisher: 'Pearson Education', publicationYear: 2008, language: 'en'));
        $fields = self::byField(MetadataReview::fromRecords(['open_library' => $ol, 'google_books' => $gb]));
        self::assertSame('corroborated', $fields['title']['status'], 'Two independent providers agree');
        self::assertSame('corroborated', $fields['authors']['status']);
        self::assertSame('conflict', $fields['publisher']['status']);
        self::assertSame('single', $fields['language']['status']);
        self::assertSame('edition', $fields['title']['origin']);
        self::assertSame('search', $fields['authors']['origin']);

        // Open Library alone (its endpoints are one provider): never corroborated.
        $alone = self::byField(MetadataReview::fromRecords(['open_library' => $ol]));
        self::assertSame('single', $alone['title']['status']);
    }

    public function testQuestionableRecordsAreShownButNeverChosen(): void
    {
        $spam = new ProviderRecord('open_library', new BookMetadata(title: 'The Earn2Trade Discount Code', publisher: 'The Earn2Trade Discount Code', publicationYear: 2026), [], ['ol_edition' => 'OL62721526M'], [MetadataChecks::PROMOTIONAL, MetadataChecks::NO_AUTHORS]);
        $gb = new ProviderRecord('google_books', new BookMetadata(title: 'Clean Code', authors: ['Robert C. Martin']));
        $review = MetadataReview::fromRecords(['open_library' => $spam, 'google_books' => $gb]);
        $fields = self::byField($review);
        self::assertSame(['Clean Code', 'google_books', 'single'], [$fields['title']['value'], $fields['title']['source'], $fields['title']['status']]);
        self::assertSame([['provider' => 'open_library', 'value' => 'The Earn2Trade Discount Code', 'origin' => null, 'questionable' => true]], $fields['title']['alternatives']);
        self::assertSame('questionable', $fields['publisher']['status'], 'Only the set-aside record has a publisher');
        self::assertSame('', $fields['publisher']['value'], 'Not filled in');
        self::assertSame(['open_library'], $review->questionable);
        self::assertSame(['open_library' => [MetadataChecks::PROMOTIONAL, MetadataChecks::NO_AUTHORS]], $review->warnings);
        self::assertFalse($review->hasConflicts(), 'A questionable value is not a conflict between trusted sources');
    }

    public function testValuesTheUserEnteredAreMarkedEdited(): void
    {
        $gb = new ProviderRecord('google_books', new BookMetadata(title: 'Clean Code', publisher: 'Pearson'));
        $fields = self::byField(MetadataReview::fromRecords(['google_books' => $gb], ['title' => 'clean code', 'publisher' => 'Prentice Hall', 'page_count' => '431']));
        self::assertSame('single', $fields['title']['status'], 'Same value, different case');
        self::assertSame('edited', $fields['publisher']['status']);
        self::assertSame(['431', 'edited', null], [$fields['page_count']['value'], $fields['page_count']['status'], $fields['page_count']['source']]);
    }
}
