<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\MetadataReview;
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
        self::assertSame([['provider' => 'google_books', 'value' => 'Ediciones Cátedra']], $fields['publisher']['alternatives']);
        self::assertSame([['provider' => 'google_books', 'value' => '2008']], $fields['publication_year']['alternatives']);
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
}
