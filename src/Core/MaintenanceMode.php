<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

/**
 * Maintenance mode: while the flag file exists, the web application
 * answers every request (except /health) with 503 and changes nothing.
 * Used for restores and, optionally, for fully quiescent backups.
 * Toggled only from the CLI (bin/console maintenance:on|off).
 *
 * A restore into the live database also writes a "restore incomplete"
 * marker before its first change and removes it only on success. While the
 * marker exists the application stays in maintenance mode even if the flag
 * is removed by hand, and maintenance:off refuses until an operator
 * acknowledges it, so a partially restored database is never served.
 */
final class MaintenanceMode
{
    public const MARKER_SUFFIX = '.restore-incomplete';

    public function __construct(private readonly string $flagPath)
    {
    }

    public function isActive(): bool
    {
        return is_file($this->flagPath) || is_file($this->markerPath());
    }

    /**
     * Seconds since maintenance mode was switched on (0 when it is off).
     */
    public function activeForSeconds(?int $now = null): int
    {
        $mtime = is_file($this->flagPath) ? @filemtime($this->flagPath) : false;

        return $mtime === false ? 0 : max(0, ($now ?? time()) - $mtime);
    }

    /**
     * @param array<string, scalar> $details file name, target database, start time (no secrets)
     */
    public function markRestoreIncomplete(array $details): void
    {
        $this->write($this->markerPath(), $details);
    }

    public function clearRestoreIncomplete(): void
    {
        if (is_file($this->markerPath()) && !unlink($this->markerPath())) {
            throw new \RuntimeException('Cannot remove the restore marker');
        }
    }

    /**
     * @return array<string, mixed>|null details of an unfinished restore
     */
    public function restoreIncomplete(): ?array
    {
        if (!is_file($this->markerPath())) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->markerPath(), false, null, 0, 8192), true);

        return is_array($data) ? $data : [];
    }

    private function markerPath(): string
    {
        return $this->flagPath . self::MARKER_SUFFIX;
    }

    public function enable(string $reason = ''): void
    {
        $this->write($this->flagPath, ['since' => gmdate('Y-m-d\TH:i:s\Z'), 'reason' => mb_substr($reason, 0, 200)]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $path, array $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($path, $payload . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write ' . basename($path));
        }
        @chmod($path, 0640);
    }

    public function disable(): void
    {
        if (is_file($this->flagPath) && !unlink($this->flagPath)) {
            throw new \RuntimeException('Cannot remove the maintenance flag');
        }
    }

    /**
     * @return array{since: ?string, reason: ?string}
     */
    public function details(): array
    {
        $data = $this->isActive() ? json_decode((string) @file_get_contents($this->flagPath), true) : null;

        return ['since' => is_array($data) ? ($data['since'] ?? null) : null, 'reason' => is_array($data) ? ($data['reason'] ?? null) : null];
    }
}
