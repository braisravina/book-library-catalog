<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

/**
 * Maintenance mode: while the flag file exists, the web application
 * answers every request (except /health) with 503 and changes nothing.
 * Used for restores and, optionally, for fully quiescent backups.
 * Toggled only from the CLI (bin/console maintenance:on|off).
 */
final class MaintenanceMode
{
    public function __construct(private readonly string $flagPath)
    {
    }

    public function isActive(): bool
    {
        return is_file($this->flagPath);
    }

    public function enable(string $reason = ''): void
    {
        $dir = dirname($this->flagPath);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create ' . $dir);
        }
        $payload = json_encode(['since' => gmdate('Y-m-d\TH:i:s\Z'), 'reason' => mb_substr($reason, 0, 200)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($this->flagPath, $payload . "\n", LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the maintenance flag');
        }
        @chmod($this->flagPath, 0640);
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
