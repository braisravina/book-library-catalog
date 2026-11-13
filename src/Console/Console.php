<?php

declare(strict_types=1);

namespace DigitalLibrary\Console;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigValidator;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Database\Migrator;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Validation\Validator;

/**
 * Operational commands. Run inside the PHP container:
 *   php bin/console migrate
 *   php bin/console migrate:status
 *   php bin/console user:create --email=... --name=... --role=admin
 */
final class Console
{
    /**
     * Commands that change the database schema, data or files in bulk, or
     * create accounts: only the ops role may run them in production, and
     * never a container with APP_ROLE=web (in any environment).
     */
    public const OPS_COMMANDS = ['migrate', 'user:create', 'backup:create', 'backup:restore', 'maintenance:on', 'maintenance:off', 'cleanup', 'metadata:refresh'];
    /** Commands that must not overlap (OperationLock). */
    public const EXCLUSIVE_COMMANDS = ['migrate', 'backup:create', 'backup:restore', 'maintenance:off', 'cleanup'];

    /** @var resource */
    private $out;
    /** @var resource */
    private $err;

    /**
     * @param resource|null $stdin
     * @param resource|null $out
     * @param resource|null $err
     */
    public function __construct(private readonly Services $services, private $stdin = null, $out = null, $err = null)
    {
        $this->out = $out ?? STDOUT;
        $this->err = $err ?? STDERR;
        $this->stdin ??= STDIN;
    }

    /**
     * @param list<string> $argv
     */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';
        $options = self::parseOptions(array_slice($argv, 2));

        $refusal = $this->roleRefusal($command);
        if ($refusal !== null) {
            return $this->fail($refusal);
        }
        $lock = null;
        try {
            if (in_array($command, self::EXCLUSIVE_COMMANDS, true)) {
                $lock = OperationLock::acquire($this->services->tempDirectory(), $command);
            }

            return match ($command) {
                'migrate' => $this->migrate(),
                'config:check' => $this->configCheck($options),
                'migrate:status' => $this->migrateStatus(),
                'user:create' => $this->createUser($options),
                'metadata:refresh' => $this->metadataRefresh($options),
                'backup:create' => $this->operations()->backupCreate($options),
                'backup:verify' => $this->operations()->backupVerify($options),
                'backup:restore' => $this->operations()->backupRestore($options),
                'maintenance:on' => $this->operations()->maintenanceOn($options),
                'maintenance:off' => $this->operations()->maintenanceOff($options),
                'maintenance:status' => $this->operations()->maintenanceStatus(),
                'cleanup' => $this->operations()->cleanup($options),
                'help', '--help', '-h' => $this->help(),
                default => $this->fail("Unknown command: {$command}\n\n" . $this->usage()),
            };
        } catch (OperationLockedException $e) {
            return $this->fail('Refused: ' . $e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('Error: ' . $e->getMessage());
        } finally {
            $lock?->release();
        }
    }

    /**
     * Operational role separation (fail closed). In production (including an
     * unset or unknown APP_ENV) the commands in OPS_COMMANDS require
     * APP_ROLE=ops; a container with APP_ROLE=web may never run them, and an
     * unrecognised role is refused. Development and testing without a role
     * keep the single-container workflow.
     */
    private function roleRefusal(string $command): ?string
    {
        if (!in_array($command, self::OPS_COMMANDS, true)) {
            return null;
        }
        $config = $this->services->config;
        $role = $config->get('APP_ROLE');
        if ($role !== null && !in_array($role, ConfigValidator::ROLES, true)) {
            return sprintf('Refused: %s: APP_ROLE has an unrecognised value; it must be "web" or "ops".', $command);
        }
        if ($role === 'web') {
            return sprintf('Refused: %s is an operations command and cannot run in the web container (APP_ROLE=web). Use the ops container: docker compose -f docker-compose.prod.yml run --rm ops php bin/console %s', $command, $command);
        }
        if ($config->isProduction() && $role !== 'ops') {
            return sprintf('Refused: %s requires APP_ROLE=ops in production (APP_ENV=%s).', $command, $config->environment() ?? 'unset');
        }

        return null;
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string|true>
     */
    public static function parseOptions(array $args): array
    {
        $options = [];
        foreach ($args as $arg) {
            if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/s', $arg, $m)) {
                $options[$m[1]] = $m[2] ?? true;
            }
        }

        return $options;
    }

    private function operations(): Operations
    {
        return new Operations($this->services, fn (string $m) => $this->line($m), fn (string $m) => $this->error($m));
    }

    private function migrator(): Migrator
    {
        return new Migrator(
            ConnectionFactory::forMigrations($this->services->config),
            $this->services->basePath . '/database/migrations',
        );
    }

    private function migrate(): int
    {
        $ran = $this->migrator()->migrate(fn (string $line) => $this->line($line));
        $this->line($ran === [] ? 'Nothing to migrate.' : sprintf('Applied %d migration(s).', count($ran)));

        return 0;
    }

    private function migrateStatus(): int
    {
        $exit = 0;
        $migrator = $this->migrator();
        $this->line('Database engine: ' . ($migrator->engine() === Migrator::ENGINE_MARIADB ? 'MariaDB' : 'MySQL'));
        $otherEngine = false;
        foreach ($migrator->status() as $row) {
            $state = match (true) {
                !$row['applied'] => 'pending',
                $row['other_engine'] => 'OTHER-ENGINE',
                $row['modified'] => 'MODIFIED',
                default => 'applied',
            };
            if ($row['modified'] || $row['other_engine']) {
                $exit = 1;
            }
            $otherEngine = $otherEngine || $row['other_engine'];
            $variant = $row['variant'] === Migrator::ENGINE_MARIADB ? ' (MariaDB variant)' : '';
            $this->line(sprintf('%-12s %s%s', $state, $row['version'], $variant));
        }
        if ($otherEngine) {
            $this->line('OTHER-ENGINE: the history was written by the other database engine; migrate refuses to run (docs/OPERATIONS.md, "Database engine").');
        }

        return $exit;
    }

    /**
     * Selective cache invalidation (e.g. a wrong record that the provider
     * has since corrected). Nothing is deleted and nothing is fetched here.
     *
     * @param array<string, string|true> $options
     */
    private function metadataRefresh(array $options): int
    {
        $isbn = Isbn::tryParse(is_string($options['isbn'] ?? null) ? $options['isbn'] : null);
        if ($isbn === null) {
            return $this->fail('metadata:refresh needs a valid --isbn=ISBN.');
        }
        $provider = is_string($options['provider'] ?? null) ? $options['provider'] : null;
        try {
            $count = $this->services->metadata()->invalidate($isbn, $provider);
        } catch (\InvalidArgumentException $e) {
            return $this->fail($e->getMessage());
        }
        $this->line(sprintf('Marked %d cached lookup(s) for %s as stale.', $count, $isbn->isbn13));

        return 0;
    }

    /**
     * @param array<string, string|true> $options
     */
    private function createUser(array $options): int
    {
        $input = [
            'email' => is_string($options['email'] ?? null) ? trim($options['email']) : null,
            'display_name' => is_string($options['name'] ?? null) ? trim($options['name']) : null,
            'role' => is_string($options['role'] ?? null) ? $options['role'] : Role::Member->value,
            'locale' => is_string($options['locale'] ?? null) ? $options['locale'] : $this->services->defaultLocale(),
        ];
        $validator = Validator::make($input, [
            'email' => ['required', 'email'],
            'display_name' => ['required', 'max:100', 'no_control'],
            'role' => ['required', 'in:' . implode(',', array_column(Role::cases(), 'value'))],
            'locale' => ['required', 'in:' . implode(',', Translator::SUPPORTED)],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors() as $field => $errors) {
                $this->error(sprintf('%s: %s', $field, implode(', ', $errors)));
            }

            return $this->fail('Usage: user:create --email=EMAIL --name="NAME" [--role=admin|member|viewer] [--locale=en|es|gl]');
        }

        $users = $this->services->users();
        if ($users->emailExists((string) $input['email'])) {
            return $this->fail('A user with that e-mail already exists.');
        }

        // Password is read from STDIN (never from argv, which leaks into
        // process listings and shell history).
        $password = $this->readPassword();
        $violations = $this->services->passwordPolicy()->validate($password, (string) $input['email']);
        if ($violations !== []) {
            return $this->fail('Password rejected: ' . implode(', ', $violations));
        }

        $id = $users->create(
            (string) $input['email'],
            (string) $input['display_name'],
            Role::from((string) $input['role']),
            $this->services->hasher()->hash($password),
            (string) $input['locale'],
            null,
        );
        $this->services->audit()->log('user.created', null, 'user', $id, ['email' => $input['email'], 'role' => $input['role'], 'via' => 'cli']);
        $this->line(sprintf('Created %s user #%d (%s).', $input['role'], $id, $input['email']));

        return 0;
    }

    private function readPassword(): string
    {
        $interactive = function_exists('posix_isatty') && posix_isatty($this->stdin);
        if ($interactive) {
            fwrite($this->out, 'Password: ');
            @shell_exec('stty -echo 2>/dev/null');
        }
        $password = rtrim((string) fgets($this->stdin), "\r\n");
        if ($interactive) {
            @shell_exec('stty echo 2>/dev/null');
            fwrite($this->out, PHP_EOL);
        }

        return $password;
    }

    private function help(): int
    {
        $this->line($this->usage());

        return 0;
    }

    private function usage(): string
    {
        return <<<TXT
            Digital Library Archive console

            Commands:
              config:check      Validate the configuration (read-only; secrets are never shown)
                                [--strict]  also fail on warnings
              migrate           Apply pending database migrations
              migrate:status    List migrations and whether they are applied
              user:create       Create a user; the password is read from STDIN
                                --email=EMAIL --name="NAME" [--role=admin|member|viewer] [--locale=en|es|gl]

            Backups (see README "Backup and restore"):
              backup:create     Write a verified archive of the database and cover files
                                [--output-dir=DIR] [--allow-missing-covers]
              backup:verify     Check an archive's structure, manifest and checksums
                                --file=PATH
              backup:restore    Restore an archive (requires maintenance mode)
                                --file=PATH --confirm=DATABASE_NAME [--database=NAME] [--replace]
                                [--covers-dir=DIR] [--skip-maintenance-check] [--allow-production]
                                [--no-safety-backup]  skip the automatic backup of a non-empty target

            Metadata:
              metadata:refresh  Mark the cached online lookup of one ISBN as stale, so the next
                                lookup asks the providers again (rows are kept)
                                --isbn=ISBN [--provider=open_library|google_books]

            Production: migrate, user:create, backup:create, backup:restore, maintenance:on|off,
            cleanup and metadata:refresh run only with APP_ROLE=ops (the ops container), never with APP_ROLE=web.
            Operations that must not overlap take a lock and refuse to start while another runs.

            Maintenance:
              maintenance:on    Put the web application in maintenance mode (503) [--reason=TEXT]
              maintenance:off   Leave maintenance mode
                                [--acknowledge-incomplete-restore=DATABASE]  only to serve a database
                                whose restore did not complete, after checking it (audited; see docs/OPERATIONS.md)
              maintenance:status
              cleanup           Retention and housekeeping; a dry run unless --apply
                                [--apply] [--tasks=invitations,login-attempts,temp-files,orphan-covers,backups]
                                [--delete-orphan-covers]
            TXT;
    }

    /**
     * Read-only validation of the effective configuration (environment and
     * .env). Exit 0 when valid, 1 on errors (or on warnings with --strict).
     * Production refuses to serve on the same errors.
     *
     * @param array<string, string|true> $options
     */
    private function configCheck(array $options): int
    {
        $config = $this->services->config;
        $report = (new ConfigValidator($this->services->basePath))->validate($config);
        $environment = $config->environment();
        $this->line(sprintf(
            'Configuration check: APP_ENV=%s (%s)',
            $environment !== null && in_array($environment, Config::ENVIRONMENTS, true) ? $environment : ($environment === null ? '(unset)' : '(unrecognised)'),
            $config->isProduction() ? 'production rules' : 'development rules',
        ));
        foreach ($report['errors'] as [$key, $message]) {
            $this->line(sprintf('  ERROR    %s %s', $key, $message));
        }
        foreach ($report['warnings'] as [$key, $message]) {
            $this->line(sprintf('  WARNING  %s %s', $key, $message));
        }
        foreach ($report['notes'] as $note) {
            $this->line('  NOTE     ' . $note);
        }
        $errors = count($report['errors']);
        $warnings = count($report['warnings']);
        $strict = ($options['strict'] ?? false) === true;
        $this->line(sprintf('Result: %d error(s), %d warning(s) - %s', $errors, $warnings, $errors > 0 || ($strict && $warnings > 0) ? 'INVALID' : 'OK'));

        return $errors > 0 || ($strict && $warnings > 0) ? 1 : 0;
    }

    private function line(string $message): void
    {
        fwrite($this->out, $message . PHP_EOL);
    }

    private function error(string $message): void
    {
        fwrite($this->err, $message . PHP_EOL);
    }

    private function fail(string $message): int
    {
        $this->error($message);

        return 1;
    }
}
