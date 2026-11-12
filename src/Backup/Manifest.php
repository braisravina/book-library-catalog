<?php

declare(strict_types=1);

namespace DigitalLibrary\Backup;

/**
 * Backup manifest (manifest.json, the last archive member).
 *
 *   format          "dla-backup"
 *   format_version  1
 *   created_at      ISO 8601 UTC
 *   application     name, schema_version (latest applied migration), migrations
 *   database        file, server_version, tables {name: rows}, consistency
 *   covers          referenced, files, missing (storage keys)
 *   files           [{path, size, sha256}] for every other archive member
 *   excluded        what is deliberately not in the backup
 */
final class Manifest
{
    public const FORMAT = 'dla-backup';
    public const VERSION = 1;
    public const NAME = 'manifest.json';
    public const DATABASE = 'database.sql';
    public const COVER_PATTERN = '#^covers/([0-9a-f]{2})/\1[0-9a-f]{30}(\.thumb)?\.jpg$#';

    /**
     * Validates structure and compatibility; returns a list of problems.
     *
     * @param array<string, mixed> $m
     *
     * @return list<string>
     */
    public static function problems(array $m): array
    {
        $problems = [];
        if (($m['format'] ?? null) !== self::FORMAT) {
            return ['Not a Digital Library Archive backup (unknown manifest format)'];
        }
        if (!is_int($m['format_version'] ?? null)) {
            return ['Manifest has no format version'];
        }
        if ($m['format_version'] > self::VERSION) {
            return [sprintf('Backup format version %d is newer than this application supports (%d)', $m['format_version'], self::VERSION)];
        }
        if (!is_string($m['created_at'] ?? null) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $m['created_at']) !== 1) {
            $problems[] = 'Manifest has an invalid creation time';
        }
        if (!is_string($m['application']['schema_version'] ?? null)) {
            $problems[] = 'Manifest has no schema version';
        }
        if (!is_array($m['database']['tables'] ?? null) || ($m['database']['file'] ?? null) !== self::DATABASE) {
            $problems[] = 'Manifest has no database section';
        }
        if (!is_array($m['covers'] ?? null) || !is_array($m['covers']['missing'] ?? null)) {
            $problems[] = 'Manifest has no covers section';
        }
        if (!is_array($m['files'] ?? null) || !array_is_list($m['files'])) {
            return array_merge($problems, ['Manifest has no file list']);
        }
        $seen = [];
        foreach ($m['files'] as $file) {
            $path = is_array($file) ? ($file['path'] ?? null) : null;
            if (!is_string($path) || !TarPath::isSafe($path) || $path === self::NAME) {
                $problems[] = 'Manifest lists an unsafe or invalid path';
                continue;
            }
            if (isset($seen[$path])) {
                $problems[] = 'Manifest lists a file twice: ' . $path;
            }
            $seen[$path] = true;
            if ($path !== self::DATABASE && preg_match(self::COVER_PATTERN, $path) !== 1) {
                $problems[] = 'Manifest lists an unexpected file: ' . $path;
            }
            if (!is_int($file['size'] ?? null) || $file['size'] < 0 || !is_string($file['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $file['sha256']) !== 1) {
                $problems[] = 'Manifest entry has an invalid size or checksum: ' . $path;
            }
        }
        if (!isset($seen[self::DATABASE])) {
            $problems[] = 'Backup has no database dump';
        }

        return $problems;
    }
}
