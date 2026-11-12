<?php

declare(strict_types=1);

namespace DigitalLibrary\Export;

use DigitalLibrary\Catalogue\Tree;
use PDO;

/**
 * Shared helpers for export data sources: bounded keyset batches (no
 * OFFSET scans, no full result sets in memory), consistent date formats and
 * lookups for multi-valued fields.
 *
 * Formats: DATETIME columns become ISO 8601 UTC ("2026-10-13T16:23:40Z"),
 * DATE columns stay "YYYY-MM-DD"; missing values are null.
 */
abstract class ExportSource
{
    public const BATCH = 500;

    public function __construct(protected readonly PDO $pdo)
    {
    }

    /**
     * Yields rows of "$select WHERE $where" ordered by the given key columns,
     * BATCH rows per query, continuing after the last key seen.
     *
     * @param array<string, mixed>                    $params bound values (names must not start with "k")
     * @param list<array{0: string, 1: string, 2: int|string}> $keys  [qualified column, result alias, initial value]
     * @param (\Closure(list<array<string, mixed>>): list<array<string, mixed>>)|null $decorate per-batch enrichment
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function keyset(string $select, string $where, array $params, array $keys, ?\Closure $decorate = null): \Generator
    {
        $after = array_map(static fn (array $k): int|string => $k[2], $keys);
        $columns = '(' . implode(', ', array_column($keys, 0)) . ')';
        $placeholders = '(' . implode(', ', array_map(static fn (int $i): string => ':k' . $i, array_keys($keys))) . ')';
        $sql = $select . ' WHERE (' . $where . ') AND ' . $columns . ' > ' . $placeholders
            . ' ORDER BY ' . implode(', ', array_column($keys, 0)) . ' LIMIT ' . self::BATCH;
        $stmt = $this->pdo->prepare($sql);
        do {
            foreach ($params as $name => $value) {
                $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
            }
            foreach ($after as $i => $value) {
                $stmt->bindValue(':k' . $i, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            if ($rows === []) {
                return;
            }
            $last = $rows[count($rows) - 1];
            foreach ($keys as $i => $key) {
                $after[$i] = is_int($key[2]) ? (int) $last[$key[1]] : (string) $last[$key[1]];
            }
            yield from $decorate !== null ? $decorate($rows) : $rows;
        } while (count($rows) === self::BATCH);
    }

    public static function dateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return str_replace(' ', 'T', (string) $value) . 'Z';
    }

    /**
     * Cents to a decimal string ("1250" → "12.50"); null stays null.
     */
    public static function money(mixed $cents): ?string
    {
        if ($cents === null) {
            return null;
        }
        $cents = (int) $cents;

        return sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);
    }

    /**
     * @return array<int, string> id => full path ("Fiction › Classics")
     */
    protected function treePaths(string $table): array
    {
        return Tree::paths($this->pdo->query('SELECT * FROM ' . $table . ' ORDER BY name, id')->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Author credits for a batch of works or editions, in credit order.
     *
     * @param list<int> $ids
     *
     * @return array<int, list<array{id: int, name: string, role: string}>>
     */
    protected function credits(string $table, string $column, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $names[] = ':id' . $i;
            $params['id' . $i] = $id;
        }
        $stmt = $this->pdo->prepare(
            "SELECT x.{$column} AS owner_id, a.id, a.name, x.role FROM {$table} x JOIN authors a ON a.id = x.author_id
             WHERE x.{$column} IN (" . implode(', ', $names) . ') ORDER BY x.position, a.sort_name, a.id'
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['owner_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name'], 'role' => (string) $row['role']];
        }

        return $out;
    }

    /**
     * Copy rows (shared by the catalogue and personal exports).
     *
     * @param array<string, mixed> $params
     * @param array<int, string>   $locations
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function copyRows(string $where, array $params, array $locations): \Generator
    {
        foreach ($this->keyset(
            'SELECT c.id, c.edition_id, e.title AS edition_title, c.owner_user_id, u.display_name AS owner, c.location_id,
                    c.status, c.copy_condition, c.inventory_code, c.is_signed, c.acquired_on, c.acquisition_source,
                    c.purchase_price_cents, c.currency, c.notes, c.created_at, c.updated_at
             FROM copies c JOIN editions e ON e.id = c.edition_id JOIN users u ON u.id = c.owner_user_id',
            $where,
            $params,
            [['c.id', 'id', 0]],
        ) as $row) {
            yield [
                'id' => (int) $row['id'],
                'edition_id' => (int) $row['edition_id'],
                'edition_title' => $row['edition_title'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'owner' => $row['owner'],
                'location_id' => $row['location_id'] === null ? null : (int) $row['location_id'],
                'location' => $row['location_id'] === null ? null : ($locations[(int) $row['location_id']] ?? null),
                'status' => $row['status'],
                'condition' => $row['copy_condition'],
                'inventory_code' => $row['inventory_code'],
                'is_signed' => (bool) $row['is_signed'],
                'acquired_on' => $row['acquired_on'],
                'acquisition_source' => $row['acquisition_source'],
                'price' => self::money($row['purchase_price_cents']),
                'currency' => $row['currency'],
                'notes' => $row['notes'],
                'created_at' => self::dateTime($row['created_at']),
                'updated_at' => self::dateTime($row['updated_at']),
            ];
        }
    }

    public const COPY_COLUMNS = ['id', 'edition_id', 'edition_title', 'owner_user_id', 'owner', 'location_id', 'location', 'status', 'condition',
        'inventory_code', 'is_signed', 'acquired_on', 'acquisition_source', 'price', 'currency', 'notes', 'created_at', 'updated_at'];

    public const COLLECTION_COLUMNS = ['id', 'owner_user_id', 'owner', 'name', 'description', 'visibility', 'created_at', 'updated_at'];

    public const COLLECTION_ITEM_COLUMNS = ['collection_id', 'edition_id', 'edition_title', 'position', 'added_at'];

    /**
     * @param array<string, mixed> $params
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function collectionRows(string $where, array $params): \Generator
    {
        foreach ($this->keyset(
            'SELECT c.id, c.owner_user_id, u.display_name AS owner, c.name, c.description, c.visibility, c.created_at, c.updated_at
             FROM collections c JOIN users u ON u.id = c.owner_user_id',
            $where,
            $params,
            [['c.id', 'id', 0]],
        ) as $row) {
            yield [
                'id' => (int) $row['id'],
                'owner_user_id' => (int) $row['owner_user_id'],
                'owner' => $row['owner'],
                'name' => $row['name'],
                'description' => $row['description'],
                'visibility' => $row['visibility'],
                'created_at' => self::dateTime($row['created_at']),
                'updated_at' => self::dateTime($row['updated_at']),
            ];
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function collectionItemRows(string $where, array $params): \Generator
    {
        foreach ($this->keyset(
            'SELECT ci.collection_id, ci.edition_id, e.title AS edition_title, ci.position, ci.added_at
             FROM collection_items ci JOIN collections c ON c.id = ci.collection_id JOIN editions e ON e.id = ci.edition_id',
            $where,
            $params,
            [['ci.collection_id', 'collection_id', 0], ['ci.edition_id', 'edition_id', 0]],
        ) as $row) {
            yield [
                'collection_id' => (int) $row['collection_id'],
                'edition_id' => (int) $row['edition_id'],
                'edition_title' => $row['edition_title'],
                'position' => (int) $row['position'],
                'added_at' => self::dateTime($row['added_at']),
            ];
        }
    }
}
