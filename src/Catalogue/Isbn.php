<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * Validated ISBN value object. Every valid ISBN is held in its ISBN-13 form,
 * which is what duplicate detection keys on; the ISBN-10 form exists only for
 * 978-prefixed numbers.
 */
final class Isbn
{
    private function __construct(
        public readonly string $isbn13,
        public readonly ?string $isbn10,
    ) {
    }

    public static function tryParse(?string $input): ?self
    {
        if ($input === null) {
            return null;
        }
        $digits = self::normalize($input);
        if (strlen($digits) === 10 && self::isValidIsbn10($digits)) {
            return new self(self::isbn10To13($digits), $digits);
        }
        if (strlen($digits) === 13 && self::isValidIsbn13($digits)) {
            return new self($digits, self::isbn13To10($digits));
        }

        return null;
    }

    /**
     * Removes hyphens, spaces and an "ISBN" prefix; upper-cases the X check digit.
     */
    public static function normalize(string $input): string
    {
        $value = strtoupper(trim($input));
        $value = (string) preg_replace('/^ISBN(?:-1[03])?:?/', '', $value);

        return (string) preg_replace('/[\s\-\x{2010}-\x{2015}]/u', '', $value);
    }

    public static function isValidIsbn10(string $isbn): bool
    {
        if (!preg_match('/^\d{9}[\dX]$/', $isbn)) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = $isbn[$i] === 'X' ? 10 : (int) $isbn[$i];
            $sum += $digit * (10 - $i);
        }

        return $sum % 11 === 0;
    }

    public static function isValidIsbn13(string $isbn): bool
    {
        if (!preg_match('/^97[89]\d{10}$/', $isbn)) {
            return false;
        }

        return self::isbn13CheckDigit(substr($isbn, 0, 12)) === $isbn[12];
    }

    public static function isbn10To13(string $isbn10): string
    {
        $core = '978' . substr($isbn10, 0, 9);

        return $core . self::isbn13CheckDigit($core);
    }

    public static function isbn13To10(string $isbn13): ?string
    {
        if (!str_starts_with($isbn13, '978')) {
            return null;
        }
        $core = substr($isbn13, 3, 9);
        $sum = 0;
        for ($i = 0; $i < 9; $i++) {
            $sum += (int) $core[$i] * (10 - $i);
        }
        $check = (11 - ($sum % 11)) % 11;

        return $core . ($check === 10 ? 'X' : (string) $check);
    }

    private static function isbn13CheckDigit(string $first12): string
    {
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += (int) $first12[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (string) ((10 - ($sum % 10)) % 10);
    }
}
