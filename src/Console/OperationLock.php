<?php

declare(strict_types=1);

namespace DigitalLibrary\Console;

/**
 * Mutual exclusion for operational commands (backup, restore, migrate,
 * cleanup, maintenance:off): at most one runs at a time, across processes
 * and across containers sharing the storage volume.
 *
 * Uses flock() on a lock file in the temporary directory. The kernel
 * releases the lock when the holding process exits for any reason
 * (including SIGKILL or a container stop), so a stale lock can never block
 * later operations and needs no manual clean-up. The file itself is never
 * deleted (deleting lock files races); its contents only describe the
 * current holder (operation name, PID, start time: no secrets).
 */
final class OperationLock
{
    public const FILE = 'operations.lock';

    /**
     * @param resource $handle
     */
    private function __construct(private $handle)
    {
    }

    /**
     * @throws OperationLockedException when another operation holds the lock
     */
    public static function acquire(string $directory, string $operation): self
    {
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create the directory for the operations lock');
        }
        $path = rtrim($directory, '/') . '/' . self::FILE;
        if (is_link($path)) {
            throw new \RuntimeException('Refusing to use a symbolic link as the operations lock');
        }
        $previous = umask(0077);
        try {
            $handle = @fopen($path, 'c+');
        } finally {
            umask($previous);
        }
        if ($handle === false) {
            throw new \RuntimeException('Cannot open the operations lock');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            $holder = json_decode((string) stream_get_contents($handle, 4096, 0), true);
            fclose($handle);
            throw new OperationLockedException(is_array($holder) ? $holder : []);
        }
        ftruncate($handle, 0);
        fwrite($handle, (string) json_encode(['operation' => $operation, 'pid' => getmypid(), 'since' => gmdate('Y-m-d\TH:i:s\Z')]) . "\n");
        fflush($handle);

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            ftruncate($this->handle, 0);
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
