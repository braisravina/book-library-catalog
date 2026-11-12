<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Repository\CoverRepository;
use DigitalLibrary\Database\Transaction;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Upload\CoverImageProcessor;
use DigitalLibrary\Upload\UploadException;
use PDO;

/**
 * Downloads a provider's cover image and stores it as a local cover.
 *
 * The URL comes from a cached provider record (never from the browser), is
 * fetched through the hardened client (allowlisted HTTPS hosts, size cap),
 * and the bytes go through CoverImageProcessor's full validation and
 * re-encoding. Once stored, the catalogue no longer depends on the provider.
 */
final class CoverImporter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly HttpClient $http,
        private readonly CoverImageProcessor $processor,
        private readonly CoverRepository $covers,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @return int|null the new cover id, or null if nothing could be imported
     */
    public function import(int $editionId, BookMetadata $metadata, int $userId): ?int
    {
        if ($metadata->coverUrl === '') {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dla-cover-');
        if ($tmp === false) {
            return null;
        }
        try {
            $response = $this->http->get($metadata->coverUrl, $this->maxBytes);
            if ($response->status !== 200 || $response->body === '') {
                return null;
            }
            file_put_contents($tmp, $response->body);
            $image = $this->processor->storeDownloaded($tmp);
        } catch (HttpException | UploadException $e) {
            error_log('[digital-library] cover import failed: ' . $e->getMessage());

            return null;
        } finally {
            @unlink($tmp);
        }

        try {
            return Transaction::run($this->pdo, fn (): int => $this->covers->addPrimary($editionId, $image, $userId));
        } catch (\Throwable $e) {
            $this->processor->delete($image->storageKey);
            throw $e;
        }
    }
}
