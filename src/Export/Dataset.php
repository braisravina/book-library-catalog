<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

/**
 * One exportable table: a stable column list and a lazy row source.
 * Every row has exactly these keys, in this order; values are scalars,
 * null, or (JSON only) lists. Lists are flattened for CSV.
 */
final class Dataset
{
    /**
     * @param list<string>                                 $columns
     * @param \Closure(): iterable<array<string, mixed>>   $rows
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        private readonly \Closure $rows,
    ) {
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function rows(): iterable
    {
        foreach (($this->rows)() as $row) {
            $out = [];
            foreach ($this->columns as $column) {
                $out[$column] = $row[$column] ?? null;
            }
            yield $out;
        }
    }
}
