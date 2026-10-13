<?php

declare(strict_types=1);

namespace DigitalLibrary\Console;

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

        try {
            return match ($command) {
                'migrate' => $this->migrate(),
                'migrate:status' => $this->migrateStatus(),
                'user:create' => $this->createUser($options),
                'backup:create' => $this->operations()->backupCreate($options),
                'backup:verify' => $this->operations()->backupVerify($options),
                'backup:restore' => $this->operations()->backupRestore($options),
                'maintenance:on' => $this->operations()->maintenanceOn($options),
                'maintenance:off' => $this->operations()->maintenanceOff(),
                'maintenance:status' => $this->operations()->maintenanceStatus(),
                'cleanup' => $this->operations()->cleanup($options),
                'help', '--help', '-h' => $this->help(),
                default => $this->fail("Unknown command: {$command}\n\n" . $this->usage()),
            };
        } catch (\Throwable $e) {
            return $this->fail('Error: ' . $e->getMessage());
        }
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
        foreach ($this->migrator()->status() as $row) {
            $state = $row['applied'] ? ($row['modified'] ? 'MODIFIED' : 'applied') : 'pending';
            if ($row['modified']) {
                $exit = 1;
            }
            $this->line(sprintf('%-9s %s', $state, $row['version']));
        }

        return $exit;
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

            Maintenance:
              maintenance:on    Put the web application in maintenance mode (503) [--reason=TEXT]
              maintenance:off   Leave maintenance mode
              maintenance:status
              cleanup           Retention and housekeeping; a dry run unless --apply
                                [--apply] [--tasks=invitations,login-attempts,temp-files,orphan-covers,backups]
                                [--delete-orphan-covers]
            TXT;
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
