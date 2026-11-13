<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\MetadataChecks;
use DigitalLibrary\Metadata\ProviderRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataChecksTest extends TestCase
{
    private const NOW = 1_794_600_000; // 2026-11-13

    /**
     * @param array<string, mixed> $signals
     *
     * @return list<string>
     */
    private static function inspect(BookMetadata $m, string $isbn = '9780132350884', array $signals = []): array
    {
        return MetadataChecks::inspect($m, Isbn::tryParse($isbn) ?? throw new \LogicException(), $signals, 2026, self::NOW);
    }

    /**
     * @return iterable<string, array{BookMetadata, list<string>, bool}>
     */
    public static function records(): iterable
    {
        yield 'ordinary book' => [new BookMetadata('Clean Code', '', ['Robert C. Martin'], 'Prentice Hall', 2008), [], false];
        yield 'promotional title' => [new BookMetadata('The Earn2Trade Discount Code', '', ['X'], 'Y', 2025), [MetadataChecks::PROMOTIONAL], true];
        yield 'promo code in the publisher' => [new BookMetadata('Book', '', ['X'], 'Promo codes inc', 2020), [MetadataChecks::PROMOTIONAL], true];
        yield 'percentage off' => [new BookMetadata('Get 20% off today', '', ['X'], 'Y', 2020), [MetadataChecks::PROMOTIONAL], true];
        yield 'link in the title' => [new BookMetadata('Visit https://example.com now', '', ['X'], 'Y', 2020), [MetadataChecks::PROMOTIONAL], true];
        yield 'title is publisher, no author' => [new BookMetadata('Acme Annual', '', [], 'ACME ANNUAL.', 2020), [MetadataChecks::NO_AUTHORS, MetadataChecks::TITLE_IS_PUBLISHER_NO_AUTHOR], true];
        yield 'future year' => [new BookMetadata('Book', '', ['X'], 'Y', 2031), [MetadataChecks::DATE_FUTURE], true];
        // Legitimate, unusual records: warnings at most, never set aside.
        yield 'anonymous work' => [new BookMetadata('Lazarillo de Tormes', '', [], 'Cátedra', 1987), [MetadataChecks::NO_AUTHORS], false];
        yield 'self-published: author is publisher' => [new BookMetadata('Poemas', '', ['Ana Pérez'], 'Ana Pérez', 2019), [], false];
        yield 'title also the publisher, with an author' => [new BookMetadata('Galaxia', '', ['X'], 'Galaxia', 1990), [], false];
        yield 'odd title' => [new BookMetadata('!!!', '', ['Z'], 'Q', 2001), [], false];
        yield 'book about coupons (no code)' => [new BookMetadata('The History of the Coupon', '', ['X'], 'Y', 2010), [], false];
        yield 'next year (announced)' => [new BookMetadata('Book', '', ['X'], 'Y', 2027), [], false];
        yield 'year before ISBNs' => [new BookMetadata('Book', '', ['X'], 'Y', 1950), [MetadataChecks::DATE_BEFORE_ISBN], false];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('records')]
    public function testRulesAreExplainableAndDoNotRejectUnusualBooks(BookMetadata $m, array $expected, bool $questionable): void
    {
        $warnings = self::inspect($m);
        self::assertSame($expected, $warnings);
        self::assertSame($questionable, (new ProviderRecord('open_library', $m, [], [], $warnings))->questionable());
    }

    public function testProviderSignals(): void
    {
        $m = new BookMetadata('Book', '', ['X'], 'Y', 2020);
        self::assertSame([MetadataChecks::RECENT_UNSOURCED], self::inspect($m, '9780132350884', ['created' => '2026-11-10T03:47:52.625258', 'sources' => 0]));
        self::assertSame([], self::inspect($m, '9780132350884', ['created' => '2026-11-10T03:47:52', 'sources' => 2]), 'Recent but sourced');
        self::assertSame([], self::inspect($m, '9780132350884', ['created' => '2015-01-01T00:00:00', 'sources' => 0]), 'Old');
        self::assertSame([MetadataChecks::ISBN_SHARED, MetadataChecks::INCOMPLETE], self::inspect($m, '9780132350884', ['shared' => true, 'incomplete' => true]));
        self::assertSame([MetadataChecks::DATE_BEFORE_ISBN], self::inspect(new BookMetadata('Book', '', ['X'], 'Y', 2003), '9791032305690'), '979 prefix before 2007');
        self::assertSame([], self::inspect(new BookMetadata()), 'Nothing to check');
        self::assertSame([MetadataChecks::PROMOTIONAL], self::inspect(new BookMetadata('Book', '', ['X'], 'Y', 2020, null, '', 'Use our discount code at checkout.')), 'Explicit phrase in the description');
        self::assertSame([], self::inspect(new BookMetadata('Book', '', ['X'], 'Y', 2020, null, '', 'See www.example.org for the companion site.')), 'Links in a description are fine');
    }
}
