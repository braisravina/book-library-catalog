<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

/**
 * RFC 4180 CSV in UTF-8 (with a byte-order mark so spreadsheet software
 * detects the encoding). Values are never truncated.
 *
 * Spreadsheet formula injection: any text cell beginning with =, +, -, @,
 * a tab or a carriage return (also after leading spaces, or the full-width
 * equivalents) is prefixed with a single quote so it is shown as text.
 * Numbers (int/float) are written unchanged. List values are joined with
 * "; " (JSON is the lossless format for multi-valued fields).
 */
final class CsvWriter
{
    public const BOM = "\xEF\xBB\xBF";
    public const LIST_SEPARATOR = '; ';

    /**
     * @param resource                       $out
     * @param iterable<array<string, mixed>> $rows
     * @param list<string>                   $columns
     */
    public static function write($out, array $columns, iterable $rows): int
    {
        fwrite($out, self::BOM);
        self::line($out, $columns);
        $count = 0;
        foreach ($rows as $row) {
            self::line($out, array_map(static fn (mixed $v): string => self::cell($v), array_values($row)));
            $count++;
        }

        return $count;
    }

    public static function cell(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            is_array($value) => self::protect(implode(self::LIST_SEPARATOR, array_map(static fn (mixed $v): string => is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $value))),
            default => self::protect((string) $value),
        };
    }

    /**
     * Neutralises cells a spreadsheet would evaluate as a formula.
     */
    public static function protect(string $value): string
    {
        if ($value !== '' && preg_match('/^[\s\x{3000}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}\x{FF20}\t\r]/u', $value) === 1) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param resource     $out
     * @param list<string> $fields
     */
    private static function line($out, array $fields): void
    {
        // Explicit escape "" (RFC 4180 doubling only); PHP 8.4 deprecates the default.
        fputcsv($out, $fields, ',', '"', '', "\r\n");
    }
}
