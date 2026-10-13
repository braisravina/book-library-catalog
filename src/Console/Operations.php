<?php

declare(strict_types=1);

namespace DigitalLibrary\Console;

use DigitalLibrary\Backup\BackupException;
use DigitalLibrary\Backup\BackupVerifier;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Maintenance\Cleanup;

/**
 * Operational CLI commands: backups, restore, maintenance mode, cleanup.
 * None of these is reachable from the web application.
 *
 * Exit codes: 0 success, 1 refused or failed before changing anything,
 * 2 failed after changes were made (e.g. a partial restore).
 */
final class Operations
{
    /**
     * @param \Closure(string): void $line
     * @param \Closure(string): void $error
     */
    public function __construct(
        private readonly Services $services,
        private readonly \Closure $line,
        private readonly \Closure $error,
    ) {
    }

    /**
     * @param array<string, string|true> $options
     */
    public function backupCreate(array $options): int
    {
        $dir = is_string($options['output-dir'] ?? null) ? $options['output-dir'] : $this->services->backupDirectory();
        try {
            $result = $this->services->backupCreator()->create($dir, ($options['allow-missing-covers'] ?? false) === true, $this->line);
        } catch (BackupException $e) {
            ($this->error)('BACKUP FAILED: ' . $e->getMessage());
            ($this->error)('No archive was written; temporary files were removed.');

            return 1;
        }
        foreach ($result['warnings'] as $warning) {
            ($this->error)('Warning: ' . $warning);
        }
        $check = (new BackupVerifier())->verify($result['path']);
        if (!$check['ok']) {
            ($this->error)('BACKUP FAILED VERIFICATION: ' . implode('; ', $check['errors']));

            return 1;
        }
        $this->services->audit()->log('backup.created', null, 'backup', null, [
            'file' => basename($result['path']),
            'sha256' => $result['sha256'],
            'size' => $result['size'],
            'covers' => $result['manifest']['covers']['files'],
        ]);
        ($this->line)(sprintf("OK  %s\n    %d bytes, SHA-256 %s\n    tables: %d, cover files: %d, schema: %s\n    verified: yes",
            $result['path'],
            $result['size'],
            $result['sha256'],
            count($result['manifest']['database']['tables']),
            $result['manifest']['covers']['files'],
            $result['manifest']['application']['schema_version'],
        ));

        return 0;
    }

    /**
     * @param array<string, string|true> $options
     */
    public function backupVerify(array $options): int
    {
        $file = $options['file'] ?? null;
        if (!is_string($file) || $file === '') {
            ($this->error)('Usage: backup:verify --file=PATH');

            return 1;
        }
        $result = (new BackupVerifier())->verify($file);
        foreach ($result['warnings'] as $warning) {
            ($this->error)('Warning: ' . $warning);
        }
        if (!$result['ok']) {
            ($this->error)('INVALID BACKUP: ' . basename($file));
            foreach ($result['errors'] as $error) {
                ($this->error)(' - ' . $error);
            }

            return 1;
        }
        $m = $result['manifest'];
        ($this->line)(sprintf("VALID  %s\n    created %s, format %d, schema %s\n    %d files, %d tables, %d cover files\n    archive SHA-256 %s",
            basename($file),
            $m['created_at'],
            $m['format_version'],
            $m['application']['schema_version'],
            count($m['files']),
            count($m['database']['tables']),
            $m['covers']['files'],
            $result['sha256'],
        ));

        return 0;
    }

    /**
     * @param array<string, string|true> $options
     */
    public function backupRestore(array $options): int
    {
        $config = $this->services->config;
        $file = $options['file'] ?? null;
        $database = is_string($options['database'] ?? null) ? $options['database'] : (string) $config->get('DB_DATABASE', '');
        $production = $config->get('APP_ENV') === 'production';
        $skipMaintenance = ($options['skip-maintenance-check'] ?? false) === true;

        if (!is_string($file) || $file === '' || !is_file($file)) {
            ($this->error)('Usage: backup:restore --file=PATH --confirm=DATABASE_NAME [--database=NAME] [--replace] [--covers-dir=DIR]');

            return 1;
        }
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            ($this->error)('Invalid target database name.');

            return 1;
        }
        if (($options['confirm'] ?? null) !== $database) {
            ($this->error)(sprintf('Refusing to restore: confirm the target by passing --confirm=%s (the exact database name). Restoring replaces data.', $database));

            return 1;
        }
        if ($production && ($options['allow-production'] ?? false) !== true) {
            ($this->error)('Refusing to restore in a production environment (APP_ENV=production) without --allow-production.');

            return 1;
        }
        if ($production && $skipMaintenance) {
            ($this->error)('--skip-maintenance-check is not allowed in production.');

            return 1;
        }
        if (!$skipMaintenance && !$this->services->maintenance()->isActive()) {
            ($this->error)('Refusing to restore while the application is live. Run maintenance:on first (or --skip-maintenance-check for a disposable environment).');

            return 1;
        }
        $covers = is_string($options['covers-dir'] ?? null) ? $options['covers-dir'] : $this->services->coverDirectory();
        $public = realpath($this->services->basePath . '/public');
        $coversReal = realpath($covers);
        if ($coversReal === false || !is_dir($coversReal)) {
            ($this->error)('The covers directory does not exist: ' . $covers);

            return 1;
        }
        if ($public !== false && str_starts_with($coversReal . '/', $public . '/')) {
            ($this->error)('Refusing to restore cover files into the public web root.');

            return 1;
        }

        try {
            $pdo = ConnectionFactory::forMigrations($config->with('DB_DATABASE', $database));
            $result = $this->services->backupRestorer($pdo, $coversReal)->restore($file, ($options['replace'] ?? false) === true, $this->line);
        } catch (BackupException $e) {
            ($this->error)('RESTORE FAILED: ' . $e->getMessage());
            $partial = str_contains($e->getMessage(), 'PARTIAL') || str_contains($e->getMessage(), 'INCOMPLETE');

            return $partial ? 2 : 1;
        } catch (\PDOException $e) {
            ($this->error)('RESTORE FAILED: cannot use the target database (' . ($e->errorInfo[2] ?? 'connection error') . '). Nothing was changed.');

            return 1;
        }
        $pdo->prepare('INSERT INTO audit_log (user_id, action, entity_type, details, created_at) VALUES (NULL, :a, :t, :d, UTC_TIMESTAMP())')
            ->execute(['a' => 'backup.restored', 't' => 'backup', 'd' => json_encode(['file' => basename($file), 'covers_written' => $result['covers_written']], JSON_THROW_ON_ERROR)]);
        ($this->line)(sprintf("RESTORED  %s into database %s\n    %d tables, %d rows; %d cover files written, %d already present\n    Remember: run migrate (if the code is newer), check the site, then maintenance:off.",
            basename($file),
            $database,
            count($result['tables']),
            array_sum($result['tables']),
            $result['covers_written'],
            $result['covers_unchanged'],
        ));

        return 0;
    }

    /**
     * @param array<string, string|true> $options
     */
    public function maintenanceOn(array $options): int
    {
        $this->services->maintenance()->enable(is_string($options['reason'] ?? null) ? $options['reason'] : '');
        ($this->line)('Maintenance mode is ON: the web application answers 503 until maintenance:off.');

        return 0;
    }

    public function maintenanceOff(): int
    {
        $this->services->maintenance()->disable();
        ($this->line)('Maintenance mode is OFF.');

        return 0;
    }

    public function maintenanceStatus(): int
    {
        $mode = $this->services->maintenance();
        $details = $mode->details();
        ($this->line)($mode->isActive() ? sprintf('ON since %s%s', $details['since'] ?? '?', $details['reason'] ? ' (' . $details['reason'] . ')' : '') : 'OFF');

        return 0;
    }

    /**
     * @param array<string, string|true> $options
     */
    public function cleanup(array $options): int
    {
        $tasks = is_string($options['tasks'] ?? null) ? array_values(array_filter(array_map('trim', explode(',', $options['tasks'])))) : Cleanup::TASKS;
        $unknown = array_diff($tasks, Cleanup::TASKS);
        if ($unknown !== [] || $tasks === []) {
            ($this->error)('Unknown task(s): ' . implode(', ', $unknown) . '. Valid: ' . implode(', ', Cleanup::TASKS));

            return 1;
        }
        $apply = ($options['apply'] ?? false) === true;
        $config = $this->services->config;
        $raw = [];
        foreach (['INVITATION_RETENTION_DAYS', 'LOGIN_ATTEMPT_RETENTION_DAYS', 'TEMP_FILE_RETENTION_HOURS', 'ORPHAN_COVER_MIN_AGE_HOURS', 'BACKUP_KEEP_COUNT', 'BACKUP_KEEP_DAYS'] as $key) {
            if ($config->get($key) !== null) {
                $raw[$key] = (string) $config->get($key);
            }
        }
        $cleanup = new Cleanup(
            $this->services->pdo(),
            $this->services->coverDirectory(),
            $this->services->tempDirectory(),
            $this->services->backupDirectory(),
            Cleanup::policy($raw),
        );
        ($this->line)($apply ? 'Cleanup (APPLY):' : 'Cleanup (dry run, nothing is deleted; pass --apply to delete):');
        $report = $cleanup->run($tasks, $apply, ($options['delete-orphan-covers'] ?? false) === true, $this->line);
        if ($apply) {
            $this->services->audit()->log('maintenance.cleanup', null, 'maintenance', null, array_map(static fn (array $r): array => ['found' => $r['found'], 'removed' => $r['removed']], $report));
        }

        return 0;
    }
}
