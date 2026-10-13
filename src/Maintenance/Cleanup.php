<?php

declare(strict_types=1);

namespace DigitalLibrary\Maintenance;

use DigitalLibrary\Backup\BackupCreator;
use DigitalLibrary\Backup\BackupVerifier;
use PDO;

/**
 * Retention and housekeeping. Every task is a dry run (report only) unless
 * $apply is true; orphaned cover files are only ever reported unless
 * deletion is requested separately.
 *
 * Never touched: catalogue records, loans (history), wishlists,
 * collections, users, the audit log, accepted invitations, and the newest
 * backup that passes verification.
 */
final class Cleanup
{
    public const TASKS = ['invitations', 'login-attempts', 'temp-files', 'orphan-covers', 'backups'];
    public const BACKUP_PATTERN = '/^dla-backup-\d{8}T\d{6}Z(-\d+)?\.tar\.gz$/';

    /**
     * @param array{invitation_days: int, login_attempt_days: int, temp_hours: int, orphan_min_hours: int, backup_keep_count: int, backup_keep_days: int} $policy
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $coversDirectory,
        private readonly string $tempDirectory,
        private readonly string $backupDirectory,
        private readonly array $policy,
    ) {
    }

    /**
     * @param array<string, int|string> $config raw values (missing or invalid ones fall back to safe defaults)
     *
     * @return array{invitation_days: int, login_attempt_days: int, temp_hours: int, orphan_min_hours: int, backup_keep_count: int, backup_keep_days: int}
     */
    public static function policy(array $config): array
    {
        $int = static fn (string $key, int $default, int $min, int $max): int => max($min, min($max, is_numeric($config[$key] ?? null) ? (int) $config[$key] : $default));

        return [
            'invitation_days' => $int('INVITATION_RETENTION_DAYS', 90, 1, 3650),
            // Never shorter than a day, so active lockout windows are never cleared.
            'login_attempt_days' => $int('LOGIN_ATTEMPT_RETENTION_DAYS', 30, 1, 3650),
            'temp_hours' => $int('TEMP_FILE_RETENTION_HOURS', 24, 1, 8760),
            'orphan_min_hours' => $int('ORPHAN_COVER_MIN_AGE_HOURS', 24, 1, 8760),
            'backup_keep_count' => $int('BACKUP_KEEP_COUNT', 14, 1, 1000),
            'backup_keep_days' => $int('BACKUP_KEEP_DAYS', 30, 1, 3650),
        ];
    }

    /**
     * @param list<string>                  $tasks
     * @param (\Closure(string): void)|null $log
     *
     * @return array<string, array{found: int, removed: int, details: list<string>}>
     */
    public function run(array $tasks, bool $apply, bool $deleteOrphanCovers = false, ?\Closure $log = null, ?int $now = null): array
    {
        $log ??= static function (string $line): void {
        };
        $now ??= time();
        $report = [];
        foreach ($tasks as $task) {
            $report[$task] = match ($task) {
                'invitations' => $this->invitations($apply, $now),
                'login-attempts' => $this->loginAttempts($apply, $now),
                'temp-files' => $this->tempFiles($apply, $now),
                'orphan-covers' => $this->orphanCovers($apply && $deleteOrphanCovers, $now),
                'backups' => $this->backups($apply, $now),
                default => throw new \InvalidArgumentException('Unknown cleanup task: ' . $task),
            };
            $r = $report[$task];
            $log(sprintf('%-15s found %d, %s %d', $task, $r['found'], $apply ? 'removed' : 'would remove', $apply ? $r['removed'] : $r['found']));
            foreach ($r['details'] as $detail) {
                $log('                ' . $detail);
            }
        }

        return $report;
    }

    /**
     * @return array{found: int, removed: int, details: list<string>}
     */
    private function invitations(bool $apply, int $now): array
    {
        $before = gmdate('Y-m-d H:i:s', $now - $this->policy['invitation_days'] * 86400);
        $where = 'accepted_at IS NULL AND ((revoked_at IS NOT NULL AND revoked_at < :b1) OR (revoked_at IS NULL AND expires_at < :b2))';
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM invitations WHERE ' . $where);
        $count->execute(['b1' => $before, 'b2' => $before]);
        $found = (int) $count->fetchColumn();
        $removed = 0;
        if ($apply && $found > 0) {
            $delete = $this->pdo->prepare('DELETE FROM invitations WHERE ' . $where);
            $delete->execute(['b1' => $before, 'b2' => $before]);
            $removed = $delete->rowCount();
        }

        return ['found' => $found, 'removed' => $removed, 'details' => [sprintf('unused invitations revoked or expired before %s UTC (accepted ones are kept)', $before)]];
    }

    /**
     * @return array{found: int, removed: int, details: list<string>}
     */
    private function loginAttempts(bool $apply, int $now): array
    {
        $before = gmdate('Y-m-d H:i:s', $now - $this->policy['login_attempt_days'] * 86400);
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE attempted_at < :b');
        $count->execute(['b' => $before]);
        $found = (int) $count->fetchColumn();
        $removed = 0;
        if ($apply && $found > 0) {
            $delete = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :b');
            $delete->execute(['b' => $before]);
            $removed = $delete->rowCount();
        }

        return ['found' => $found, 'removed' => $removed, 'details' => [sprintf('sign-in and invitation attempt records before %s UTC', $before)]];
    }

    /**
     * Stale work files: storage/tmp/restore-* and backup-directory
     * .tmp-dla-* items older than the retention period. Symbolic links are
     * removed as links, never followed.
     *
     * @return array{found: int, removed: int, details: list<string>}
     */
    private function tempFiles(bool $apply, int $now): array
    {
        $cutoff = $now - $this->policy['temp_hours'] * 3600;
        $candidates = [];
        foreach ([[$this->tempDirectory, '/^restore-[0-9a-f]{16}$/'], [$this->backupDirectory, '/^\.tmp-dla-[0-9a-f]{16}\.(sql|tar\.gz|sha256)$/']] as [$dir, $pattern]) {
            if (!is_dir($dir) || is_link($dir)) {
                continue;
            }
            foreach (scandir($dir) ?: [] as $name) {
                $path = $dir . '/' . $name;
                $stat = @lstat($path);
                if (preg_match($pattern, $name) === 1 && $stat !== false && $stat['mtime'] < $cutoff) {
                    $candidates[] = $path;
                }
            }
        }
        $removed = 0;
        if ($apply) {
            foreach ($candidates as $path) {
                $removed += $this->remove($path) ? 1 : 0;
            }
        }

        return ['found' => count($candidates), 'removed' => $removed, 'details' => array_map('basename', array_slice($candidates, 0, 20))];
    }

    /**
     * Cover files no cover record refers to. Only files older than the
     * minimum age are candidates (an upload writes the file just before
     * its database row).
     *
     * @return array{found: int, removed: int, details: list<string>}
     */
    private function orphanCovers(bool $delete, int $now): array
    {
        $referenced = [];
        foreach ($this->pdo->query('SELECT storage_key FROM covers')->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $referenced[(string) $key] = true;
            $referenced[substr((string) $key, 0, -4) . '.thumb.jpg'] = true;
        }
        [$files] = (new BackupCreator($this->pdo, $this->coversDirectory, '/nonexistent'))->coverFiles();
        $cutoff = $now - $this->policy['orphan_min_hours'] * 3600;
        $orphans = [];
        foreach ($files as $relative) {
            $mtime = @filemtime($this->coversDirectory . '/' . $relative);
            if (!isset($referenced[$relative]) && $mtime !== false && $mtime < $cutoff) {
                $orphans[] = $relative;
            }
        }
        $removed = 0;
        if ($delete) {
            foreach ($orphans as $relative) {
                $removed += @unlink($this->coversDirectory . '/' . $relative) ? 1 : 0;
            }
        }
        $details = array_slice($orphans, 0, 20);
        if (!$delete && $orphans !== []) {
            $details[] = 'report only: pass --apply --delete-orphan-covers to delete';
        }

        return ['found' => count($orphans), 'removed' => $removed, 'details' => $details];
    }

    /**
     * Keeps the newest BACKUP_KEEP_COUNT archives and every archive younger
     * than BACKUP_KEEP_DAYS; older ones are removed with their .sha256 file.
     * The newest archive that passes full verification is always kept; if
     * none verifies, nothing is deleted.
     *
     * @return array{found: int, removed: int, details: list<string>}
     */
    private function backups(bool $apply, int $now): array
    {
        if (!is_dir($this->backupDirectory)) {
            return ['found' => 0, 'removed' => 0, 'details' => ['no backup directory']];
        }
        $archives = [];
        foreach (scandir($this->backupDirectory) ?: [] as $name) {
            $path = $this->backupDirectory . '/' . $name;
            if (preg_match(self::BACKUP_PATTERN, $name) === 1 && is_file($path) && !is_link($path)) {
                $archives[] = $name;
            }
        }
        rsort($archives, SORT_STRING); // timestamped names: newest first

        $protected = null;
        $verifier = new BackupVerifier();
        foreach ($archives as $name) {
            if ($verifier->verify($this->backupDirectory . '/' . $name)['ok']) {
                $protected = $name;
                break;
            }
        }
        if ($protected === null) {
            return ['found' => 0, 'removed' => 0, 'details' => $archives === [] ? ['no backups found'] : ['WARNING: no backup passes verification; nothing will be deleted']];
        }

        $cutoff = $now - $this->policy['backup_keep_days'] * 86400;
        $expired = [];
        foreach ($archives as $i => $name) {
            $mtime = (int) @filemtime($this->backupDirectory . '/' . $name);
            if ($name === $protected || $i < $this->policy['backup_keep_count'] || $mtime >= $cutoff) {
                continue;
            }
            $expired[] = $name;
        }
        $removed = 0;
        if ($apply) {
            foreach ($expired as $name) {
                if (@unlink($this->backupDirectory . '/' . $name)) {
                    $removed++;
                    @unlink($this->backupDirectory . '/' . $name . '.sha256');
                }
            }
        }
        $details = array_merge(['newest verified backup (always kept): ' . $protected], array_slice($expired, 0, 20));

        return ['found' => count($expired), 'removed' => $removed, 'details' => $details];
    }

    private function remove(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() && !$item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return @rmdir($path);
    }
}
