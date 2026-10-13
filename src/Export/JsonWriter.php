<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

/**
 * Streams one versioned JSON document:
 *
 *   {"export": {"format": "digital-library-archive", "type": "...",
 *               "schema_version": 1, "generated_at": "...Z", ...},
 *    "data": {"<dataset>": [ {row}, ... ], ...}}
 *
 * Rows are encoded one at a time, so memory use does not grow with the
 * export size. Every column is always present; absent values are null.
 */
final class JsonWriter
{
    public const FORMAT = 'digital-library-archive';
    public const SCHEMA_VERSION = 1;
    private const FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * @param resource             $out
     * @param array<string, mixed> $meta     extra metadata (scope, user id, ...)
     * @param list<Dataset>        $datasets
     *
     * @return array<string, int> row count per dataset
     */
    public static function write($out, string $type, array $meta, array $datasets): array
    {
        $header = ['format' => self::FORMAT, 'type' => $type, 'schema_version' => self::SCHEMA_VERSION, 'generated_at' => gmdate('Y-m-d\TH:i:s\Z')] + $meta
            + ['datasets' => array_map(static fn (Dataset $d): string => $d->name, $datasets)];
        fwrite($out, '{"export":' . json_encode($header, self::FLAGS) . ',"data":{');
        $counts = [];
        foreach (array_values($datasets) as $i => $dataset) {
            fwrite($out, ($i > 0 ? ',' : '') . json_encode($dataset->name, self::FLAGS) . ':[');
            $n = 0;
            foreach ($dataset->rows() as $row) {
                fwrite($out, ($n > 0 ? ',' : '') . "\n" . json_encode($row, self::FLAGS));
                $n++;
            }
            fwrite($out, ($n > 0 ? "\n" : '') . ']');
            $counts[$dataset->name] = $n;
        }
        fwrite($out, "}}\n");

        return $counts;
    }
}
