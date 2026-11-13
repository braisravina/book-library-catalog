<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Backoff;

/**
 * One small JSON file per provider (storage/cache/metadata-backoff-<provider>.json),
 * updated under an exclusive lock. No schema change is needed and the state
 * survives across PHP-FPM workers. Any I/O problem degrades to "no backoff":
 * it must never prevent a lookup or manual entry.
 */
final class FileBackoffStore implements BackoffStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function read(string $provider): ?array
    {
        $file = $this->file($provider);
        if (!is_file($file)) {
            return null;
        }
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            flock($handle, LOCK_SH);

            return self::decode((string) stream_get_contents($handle));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function update(string $provider, callable $change): void
    {
        $file = $this->file($provider);
        if (!is_dir($this->directory)) {
            error_log('[digital-library] metadata backoff directory missing: backoff disabled');

            return;
        }
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            error_log('[digital-library] metadata backoff state not writable: backoff disabled');

            return;
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            @chmod($file, 0600);
            $state = $change(self::decode((string) stream_get_contents($handle)));
            ftruncate($handle, 0);
            rewind($handle);
            if ($state !== null) {
                fwrite($handle, (string) json_encode(['failures' => (int) $state['failures'], 'until' => (int) $state['until']]));
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Provider names are fixed identifiers from the configuration, never
     * user input; the check keeps the file name inside the directory anyway.
     */
    private function file(string $provider): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $provider)) {
            throw new \InvalidArgumentException('Invalid provider name');
        }

        return rtrim($this->directory, '/') . '/metadata-backoff-' . $provider . '.json';
    }

    /**
     * @return array{failures: int, until: int}|null
     */
    private static function decode(string $json): ?array
    {
        $data = $json === '' ? null : json_decode($json, true);
        if (!is_array($data) || !is_int($data['failures'] ?? null) || !is_int($data['until'] ?? null)) {
            return null;
        }

        return ['failures' => max(0, $data['failures']), 'until' => $data['until']];
    }
}
