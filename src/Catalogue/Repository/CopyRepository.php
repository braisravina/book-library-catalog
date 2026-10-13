<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

final class CopyRepository extends Repository
{
    public const FIELDS = ['owner_user_id', 'location_id', 'inventory_code', 'copy_condition', 'status', 'is_signed',
        'acquired_on', 'acquisition_source', 'purchase_price_cents', 'currency', 'notes'];

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT c.*, u.display_name AS owner_name, e.title AS edition_title
             FROM copies c JOIN users u ON u.id = c.owner_user_id JOIN editions e ON e.id = c.edition_id
             WHERE c.id = :id',
            ['id' => $id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEdition(int $editionId): array
    {
        return $this->many(
            'SELECT c.*, u.display_name AS owner_name FROM copies c JOIN users u ON u.id = c.owner_user_id
             WHERE c.edition_id = :id ORDER BY u.display_name, c.id',
            ['id' => $editionId],
        );
    }

    /**
     * @param array<string, mixed> $data keys from FIELDS
     */
    public function create(int $editionId, array $data, int $userId): int
    {
        return $this->insert(
            'INSERT INTO copies (edition_id, owner_user_id, location_id, inventory_code, copy_condition, status, is_signed,
                                 acquired_on, acquisition_source, purchase_price_cents, currency, notes,
                                 created_by, updated_by, created_at, updated_at)
             VALUES (:edition_id, :owner_user_id, :location_id, :inventory_code, :copy_condition, :status, :is_signed,
                     :acquired_on, :acquisition_source, :purchase_price_cents, :currency, :notes,
                     :created_by, :updated_by, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            self::only($data) + ['edition_id' => $editionId, 'created_by' => $userId, 'updated_by' => $userId],
        );
    }

    /**
     * Conditional on the version the edit was based on.
     *
     * @param array<string, mixed> $data keys from FIELDS
     *
     * @return bool false when the record changed in the meantime
     */
    public function update(int $id, array $data, int $userId, int $expectedVersion): bool
    {
        return $this->run(
            'UPDATE copies SET owner_user_id = :owner_user_id, location_id = :location_id, inventory_code = :inventory_code,
                    copy_condition = :copy_condition, status = :status, is_signed = :is_signed, acquired_on = :acquired_on,
                    acquisition_source = :acquisition_source, purchase_price_cents = :purchase_price_cents,
                    currency = :currency, notes = :notes, updated_by = :updated_by, updated_at = UTC_TIMESTAMP(),
                    row_version = row_version + 1
             WHERE id = :id AND row_version = :expected',
            self::only($data) + ['updated_by' => $userId, 'id' => $id, 'expected' => $expectedVersion],
        )->rowCount() === 1;
    }

    public function delete(int $id): void
    {
        $this->run('DELETE FROM copies WHERE id = :id', ['id' => $id]);
    }

    public function inventoryCodeTaken(string $code, ?int $excludeId = null): bool
    {
        return $this->scalar('SELECT 1 FROM copies WHERE inventory_code = :c AND id <> :x', ['c' => $code, 'x' => $excludeId ?? 0]) !== null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function only(array $data): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $data[$field] ?? null;
        }
        $out['is_signed'] = !empty($out['is_signed']) ? 1 : 0;
        $out['status'] ??= 'available';

        return $out;
    }
}
