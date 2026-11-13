<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\LookupResult;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\OpenLibraryContribution;
use DigitalLibrary\Metadata\ProviderRecord;
use PHPUnit\Framework\TestCase;

final class OpenLibraryContributionTest extends TestCase
{
    /**
     * An edition row as EditionRepository::find() returns it, including
     * columns that must never reach a contribution.
     *
     * @return array<string, mixed>
     */
    private static function edition(array $overrides = []): array
    {
        return $overrides + [
            'id' => 424242, 'work_id' => 535353, 'title' => 'El mundo submarino', 'subtitle' => null, 'isbn_13' => '9788409779550', 'isbn_10' => '8409779552',
            'publisher_name' => 'Editorial de prueba', 'publisher_id' => 3, 'publication_year' => 2025, 'language' => 'es', 'page_count' => 120,
            'format' => 'hardcover', 'edition_statement' => '1st ed.', 'legal_deposit' => 'M-1-2025', 'description' => null,
            'work_description' => 'A description someone typed.', 'created_by' => 9, 'created_by_name' => 'Private Person',
            'updated_by' => 9, 'row_version' => 3, 'metadata_record_id' => 919191, 'notes' => 'private note', 'price' => '29.90',
        ];
    }

    /**
     * @param array<string, ProviderRecord> $records
     * @param list<array{provider: string, status: string}> $outcomes
     */
    private static function cached(array $records, array $outcomes): LookupResult
    {
        return new LookupResult(new BookMetadata(), [], [], null, [], [], $records, array_map(static fn (array $o): array => $o + ['cached' => true, 'retry_in' => null, 'incomplete' => false], $outcomes));
    }

    public function testMissingOnOpenLibraryLeadsToAddingWithDuplicateGuidance(): void
    {
        $plan = OpenLibraryContribution::plan(self::edition(), [['name' => 'Ana Autora', 'role' => 'author']], [['name' => 'Tra Ductor', 'role' => 'translator']], self::cached([], [['provider' => 'open_library', 'status' => 'not_found']]));
        self::assertNotNull($plan);
        self::assertSame('add', $plan->situation);
        self::assertTrue($plan->relevant());
        $fields = $plan->selection(null);
        self::assertNotContains('description', $fields, 'Off by default');
        self::assertNotContains('legal_deposit', $fields, 'Off by default');
        $text = $plan->text($fields);
        foreach (['ISBN-13: 9788409779550', 'ISBN-10: 8409779552', 'Title: El mundo submarino', 'Authors: Ana Autora', 'Contributors: Tra Ductor (translator)', 'Publisher: Editorial de prueba', 'Publish date: 2025', 'Language: Spanish', 'Number of pages: 120'] as $line) {
            self::assertStringContainsString($line, $text);
        }
        foreach (['Private Person', 'private note', '29.90', '424242', '535353', '919191', 'M-1-2025', 'someone typed', 'created'] as $private) {
            self::assertStringNotContainsString($private, $text, $private);
        }
        $links = $plan->links($text);
        self::assertSame('https://openlibrary.org/books/add', $links['add']);
        self::assertStringStartsWith('https://openlibrary.org/search?q=El%20mundo%20submarino%20Ana%20Autora', $links['search'], 'Search for an existing work first');
        self::assertArrayNotHasKey('email', $links);
        self::assertSame([], $plan->support, 'No online source: everything is from the catalogue only');
    }

    public function testSelectionIsExplicitAndLimitedToAllowlistedFields(): void
    {
        $plan = OpenLibraryContribution::plan(self::edition(), [], [], null);
        self::assertSame('check', $plan?->situation, 'No saved lookup');
        self::assertSame(['title', 'description'], $plan->selection(['title', 'description', 'notes', 'price', 'created_by_name', 'id']));
        self::assertStringContainsString("Description:\nA description someone typed.", $plan->text(['description']));
        self::assertNull(OpenLibraryContribution::plan(self::edition(['isbn_13' => null]), [], [], null), 'Only ISBN editions');
    }

    public function testASpamRecordOrOtherClaimantsLeadToAReportNotADuplicate(): void
    {
        $spam = new ProviderRecord('open_library', new BookMetadata('The Earn2Trade Discount Code', '', [], 'The Earn2Trade Discount Code', 2026), [], ['ol_edition' => 'OL62721526M', 'ol_work' => 'OL46188220W'], [MetadataChecks::PROMOTIONAL], [
            ['edition' => 'OL26222911M', 'work' => 'OL17618370W', 'title' => 'Clean Code', 'authors' => ['Robert C. Martin']],
        ]);
        $plan = OpenLibraryContribution::plan(self::edition(['isbn_13' => '9780132350884', 'isbn_10' => '0132350882', 'title' => 'Clean Code', 'language' => 'en']), [['name' => 'Robert C. Martin', 'role' => 'author']], [], self::cached(['open_library' => $spam], [['provider' => 'open_library', 'status' => 'questionable']]));
        self::assertSame('report', $plan?->situation);
        self::assertSame(['OL62721526M', 'OL26222911M'], array_column($plan->problems, 'edition'));
        self::assertSame([], $plan->identifiers, 'A questionable record is never offered as the edition to edit');
        self::assertSame([], $plan->support, 'A questionable record confirms nothing, not even the ISBN');
        $text = $plan->text($plan->selection(null));
        self::assertStringStartsWith("Possible incorrect record for ISBN 9780132350884\n- https://openlibrary.org/books/OL62721526M \"The Earn2Trade Discount Code\" (no author)", $text);
        $links = $plan->links($text);
        self::assertStringStartsWith('mailto:openlibrary@archive.org?subject=Incorrect%20record%20for%20ISBN%209780132350884&body=', $links['email']);
        self::assertSame(OpenLibraryContribution::LIBRARIANS_TRACKER, $links['librarians']);
        self::assertArrayNotHasKey('add', $links, 'No new record is suggested');
    }

    public function testAnExistingEditionMissingDetailsIsImprovedAndACompleteOneNeedsNothing(): void
    {
        $partial = new ProviderRecord('open_library', new BookMetadata(title: 'El mundo submarino', publicationYear: 2025), [], ['ol_edition' => 'OL1M', 'ol_work' => 'OL1W']);
        $gb = new ProviderRecord('google_books', new BookMetadata(title: 'El mundo submarino', publisher: 'Editorial de prueba'));
        $plan = OpenLibraryContribution::plan(self::edition(), [['name' => 'Ana Autora', 'role' => 'author']], [], self::cached(['open_library' => $partial, 'google_books' => $gb], [['provider' => 'open_library', 'status' => 'found']]));
        self::assertSame('improve', $plan?->situation);
        self::assertSame(['authors', 'publisher', 'page_count', 'language'], $plan->missingThere);
        self::assertSame(['edition' => 'OL1M', 'work' => 'OL1W'], $plan->identifiers);
        self::assertSame(['open_library', 'google_books'], $plan->support['title']);
        self::assertSame(['google_books'], $plan->support['publisher']);
        self::assertSame(['open_library', 'google_books'], $plan->support['isbn_13'], 'A usable record found by this ISBN confirms it');
        self::assertSame(['open_library', 'google_books'], $plan->support['isbn_10']);
        self::assertStringContainsString('Open Library edition: https://openlibrary.org/books/OL1M', $plan->text(['title']));

        $complete = new ProviderRecord('open_library', new BookMetadata('El mundo submarino', '', ['Ana Autora'], 'Editorial de prueba', 2025, 120, 'es'), [], ['ol_edition' => 'OL1M']);
        $none = OpenLibraryContribution::plan(self::edition(), [['name' => 'Ana Autora', 'role' => 'author']], [], self::cached(['open_library' => $complete], [['provider' => 'open_library', 'status' => 'found']]));
        self::assertSame('none', $none?->situation);
        self::assertFalse($none->relevant());
    }
}
