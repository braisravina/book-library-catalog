<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Catalogue;

use DigitalLibrary\Catalogue\Isbn;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IsbnTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, ?string}>
     */
    public static function validIsbns(): iterable
    {
        yield 'isbn-13 plain' => ['9780306406157', '9780306406157', '0306406152'];
        yield 'isbn-13 hyphenated' => ['978-0-306-40615-7', '9780306406157', '0306406152'];
        yield 'isbn-10 plain' => ['0306406152', '9780306406157', '0306406152'];
        yield 'isbn-10 with X' => ['0-8044-2957-X', '9780804429573', '080442957X'];
        yield 'isbn-10 lowercase x' => ['080442957x', '9780804429573', '080442957X'];
        yield 'prefix and spaces' => ['ISBN 978 84 376 0494 7', '9788437604947', '843760494X'];
        yield 'isbn-13 prefix' => ['ISBN-13: 978-84-376-0494-7', '9788437604947', '843760494X'];
        yield '979 has no isbn-10' => ['979-10-90636-07-1', '9791090636071', null];
        yield 'unicode hyphen' => ["978\u{2010}0306406157", '9780306406157', '0306406152'];
    }

    #[DataProvider('validIsbns')]
    public function testParsesValidIsbns(string $input, string $isbn13, ?string $isbn10): void
    {
        $isbn = Isbn::tryParse($input);
        self::assertNotNull($isbn);
        self::assertSame($isbn13, $isbn->isbn13);
        self::assertSame($isbn10, $isbn->isbn10);
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalidIsbns(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'bad isbn-13 checksum' => ['9780306406158'];
        yield 'bad isbn-10 checksum' => ['0306406153'];
        yield 'wrong prefix' => ['9770306406157'];
        yield 'X in isbn-13' => ['978030640615X'];
        yield 'X not last' => ['03064X6152'];
        yield 'too short' => ['12345'];
        yield 'letters' => ['abcdefghij'];
        yield 'sql' => ["' OR 1=1 --"];
    }

    #[DataProvider('invalidIsbns')]
    public function testRejectsInvalidIsbns(?string $input): void
    {
        self::assertNull(Isbn::tryParse($input));
    }

    public function testBothFormsResolveToSameKeyForDuplicateDetection(): void
    {
        self::assertSame(Isbn::tryParse('0-306-40615-2')?->isbn13, Isbn::tryParse('978-0-306-40615-7')?->isbn13);
    }

    public function testConversionRoundTrip(): void
    {
        foreach (['0306406152', '080442957X', '843760494X'] as $isbn10) {
            self::assertSame($isbn10, Isbn::isbn13To10(Isbn::isbn10To13($isbn10)));
        }
    }
}
