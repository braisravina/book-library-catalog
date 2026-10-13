<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Repository;

use DigitalLibrary\Upload\StoredImage;

final class CoverRepository extends Repository
{
    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one('SELECT * FROM covers WHERE id = :id', ['id' => $id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forEdition(int $editionId): array
    {
        return $this->many('SELECT * FROM covers WHERE edition_id = :id ORDER BY is_primary DESC, id DESC', ['id' => $editionId]);
    }

    /**
     * Stores metadata for a new cover and makes it the primary one.
     * Must run inside a transaction (the caller owns it).
     */
    public function addPrimary(int $editionId, StoredImage $image, int $userId): int
    {
        $this->run('UPDATE covers SET is_primary = 0 WHERE edition_id = :id AND is_primary = 1', ['id' => $editionId]);

        return $this->insert(
            "INSERT INTO covers (edition_id, storage_key, mime_type, width, height, byte_size, sha256, source, is_primary, uploaded_by, created_at)
             VALUES (:e, :k, :m, :w, :h, :b, :s, 'upload', 1, :u, UTC_TIMESTAMP())",
            ['e' => $editionId, 'k' => $image->storageKey, 'm' => $image->mimeType, 'w' => $image->width, 'h' => $image->height,
                'b' => $image->byteSize, 's' => $image->sha256, 'u' => $userId],
        );
    }

    /**
     * Deletes the row; if it was primary, promotes the newest remaining cover.
     * Must run inside a transaction.
     */
    public function delete(int $id, int $editionId): void
    {
        $this->run('DELETE FROM covers WHERE id = :id', ['id' => $id]);
        $next = $this->scalar('SELECT id FROM covers WHERE edition_id = :e ORDER BY id DESC LIMIT 1', ['e' => $editionId]);
        $hasPrimary = $this->scalar('SELECT 1 FROM covers WHERE edition_id = :e AND is_primary = 1', ['e' => $editionId]) !== null;
        if ($next !== null && !$hasPrimary) {
            $this->run('UPDATE covers SET is_primary = 1 WHERE id = :id', ['id' => (int) $next]);
        }
    }
}
