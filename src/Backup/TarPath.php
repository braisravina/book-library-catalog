<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Archive member names allowed in a backup: relative, slash-separated,
 * conservative characters only, no "." or ".." segments, no leading slash,
 * no backslashes, at most 100 bytes.
 */
final class TarPath
{
    public static function isSafe(string $name): bool
    {
        if ($name === '' || strlen($name) > 100 || preg_match('#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#', $name) !== 1) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '.' || $segment === '..' || str_contains($segment, '..')) {
                return false;
            }
        }

        return true;
    }

    public static function assertSafe(string $name): void
    {
        if (!self::isSafe($name)) {
            throw new BackupException('Unsafe archive member name: ' . self::printable($name));
        }
    }

    public static function printable(string $name): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', mb_strimwidth($name, 0, 120, '…'));
    }
}
