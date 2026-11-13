<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use DigitalLibrary\Tests\Support\BlockYaml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 5C persistent storage, split layout:
 *   Git         /home/brais/git/digital-library.git (bare; objects only)
 *   system SSD  /opt/digital-library: the deployed working tree (Compose
 *               project directory, build context), code only
 *               /opt/digital-library-data: mysql/, secrets/, mysql-init-guard/
 *   storage HDD /mnt/bravina-shared/digital-library (covers/, backups/, storage/)
 *
 * Covered here: fail-closed bind mounts (create_host_path: false, no named
 * volumes); MySQL data on the SSD, mounted only in db, never tracked by Git
 * or sent to a build; the in-container database guard (no silent
 * initialisation of an empty database); restart policy "no" with the
 * dedicated digital-library.service as the only boot path (docker.service
 * untouched); the read-only preflight with explicit first-deployment and
 * existing-installation modes; and the owner's initialisation procedure,
 * which CI runs verbatim. The preflight runs against simulated
 * findmnt/stat/find/df; the guard against real temporary directories. No
 * root, disk or Docker is needed.
 */
final class PersistentStorageTest extends TestCase
{
    private const MOUNTPOINT = '/mnt/bravina-shared';
    private const UUID = 'ad62670f-861d-47a4-a68d-967272d27866';
    private const ROOT = '/mnt/bravina-shared/digital-library';
    private const APP = '/opt/digital-library';
    private const DATA = '/opt/digital-library-data';
    private const INSTALLED = '/usr/local/sbin/digital-library-preflight';
    private const SOURCE = '/^\$\{(?<var>DLA_STORAGE_ROOT|DLA_DATA_ROOT):\?[^}]+\}\/(?<dir>[a-z][a-z\/-]*)$/';
    private const PREFLIGHT = 'docker/storage/preflight.sh';
    private const GUARD = 'docker/mysql/db-guard.sh';
    private const UNIT = 'docker/storage/systemd/digital-library.service';

    /** Expected mounts per service: "VAR/dir" => [container path, read-only]. */
    private const MOUNTS = [
        'nginx' => [],
        'php' => [
            'DLA_STORAGE_ROOT/storage' => ['/var/www/html/storage', false],
            'DLA_STORAGE_ROOT/covers' => ['/var/www/html/storage/covers', false],
        ],
        'ops' => [
            'DLA_STORAGE_ROOT/storage' => ['/var/www/html/storage', false],
            'DLA_STORAGE_ROOT/covers' => ['/var/www/html/storage/covers', false],
            'DLA_STORAGE_ROOT/backups' => ['/var/www/html/storage/backups', false],
        ],
        'db' => [
            'DLA_DATA_ROOT/mysql' => ['/var/lib/mysql', false],
            'DLA_DATA_ROOT/mysql-init-guard' => ['/run/dla-db-init', true],
        ],
    ];

    /** @var array<string, mixed> */
    private static array $compose = [];
    private string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$compose = BlockYaml::parseFile(self::path('docker-compose.prod.yml'));
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    public function testDataMountsAreFailClosedBindsOnTheTwoRoots(): void
    {
        self::assertArrayNotHasKey('volumes', self::$compose, 'No named volumes: a missing directory must never become an empty volume');
        foreach (self::MOUNTS as $service => $expected) {
            $actual = [];
            foreach (self::$compose['services'][$service]['volumes'] ?? [] as $volume) {
                if (is_string($volume)) {
                    // Short syntax only for read-only repository files.
                    self::assertMatchesRegularExpression('#^\./docker/[a-z/.-]+:/[a-z/.-]+:ro$#', $volume, "{$service}: no named or writable short-syntax mount");
                    continue;
                }
                $keys = array_keys($volume);
                self::assertContains($keys, [['type', 'source', 'target', 'bind'], ['type', 'source', 'target', 'read_only', 'bind']], $service . ': long syntax, nothing else');
                self::assertSame('bind', $volume['type'], $service);
                self::assertSame(['create_host_path' => 'false'], $volume['bind'], $service . ': Compose and Docker never create a missing source');
                self::assertMatchesRegularExpression(self::SOURCE, $volume['source'], $service . ': under a root without a default');
                preg_match(self::SOURCE, $volume['source'], $m);
                $key = $m['var'] . '/' . $m['dir'];
                self::assertArrayNotHasKey($key, $actual, $service . ': mounted once');
                $actual[$key] = [$volume['target'], ($volume['read_only'] ?? 'false') === 'true'];
            }
            self::assertSame($expected, $actual, $service);
        }
        self::assertStringNotContainsString('books', self::code('docker-compose.prod.yml'), 'books/ is reserved: not mounted yet');
        self::assertSame(['./docker/nginx/production:/etc/nginx/dla:ro'], self::$compose['services']['nginx']['volumes'], 'nginx mounts no storage');
    }

    /**
     * The YAML itself, not Compose's normalised output (which may omit the
     * field): every persistent bind mount says create_host_path: false, and
     * there are exactly the expected ones.
     */
    public function testEveryPersistentBindExplicitlyRefusesHostPathCreation(): void
    {
        $binds = [];
        foreach (self::$compose['services'] as $service => $config) {
            foreach ($config['volumes'] ?? [] as $volume) {
                if (is_array($volume)) {
                    self::assertSame('bind', $volume['type'] ?? null, $service . ': persistent data only as binds');
                    self::assertArrayHasKey('bind', $volume, $service . ': ' . ($volume['source'] ?? '') . ' declares its bind options');
                    self::assertSame(['create_host_path' => 'false'], $volume['bind'], $service . ': ' . ($volume['source'] ?? '') . ' explicitly refuses host path creation');
                    $binds[] = $service . ' ' . $volume['target'];
                }
            }
        }
        $expected = [];
        foreach (self::MOUNTS as $service => $mounts) {
            foreach ($mounts as [$target]) {
                $expected[] = $service . ' ' . $target;
            }
        }
        sort($binds);
        sort($expected);
        self::assertSame($expected, $binds, 'Exactly the expected persistent binds, no more');
        self::assertCount(7, $binds);
        // Raw text as well: one explicit "false" per bind, never "true".
        $code = self::code('docker-compose.prod.yml');
        preg_match_all('/^\s*create_host_path:\s*(\S+)\s*$/m', $code, $m);
        self::assertSame(array_fill(0, 7, 'false'), $m[1]);
        self::assertDoesNotMatchRegularExpression('/create_host_path:\s*(true|yes|on|1)\b/i', $code);
    }

    public function testDatabaseStaysOnTheSsdAndOnlyInDb(): void
    {
        foreach (self::MOUNTS as $service => $mounts) {
            self::assertSame($service === 'db', isset($mounts['DLA_DATA_ROOT/mysql']), $service . ': MySQL data only in db');
            self::assertSame($service === 'ops', isset($mounts['DLA_STORAGE_ROOT/backups']), $service . ': backups only in ops');
            if ($service !== 'db') {
                self::assertSame([], array_filter(array_keys($mounts), static fn (string $k): bool => str_starts_with($k, 'DLA_DATA_ROOT/')), $service . ': nothing from the data root');
            }
        }
        self::assertSame([], array_filter(array_keys(self::MOUNTS['db']), static fn (string $k): bool => str_starts_with($k, 'DLA_STORAGE_ROOT/')), 'db never depends on the HDD layout');
        // Secrets in the data root too, outside the working tree.
        foreach (self::$compose['secrets'] as $name => $secret) {
            self::assertMatchesRegularExpression('/^\$\{DLA_DATA_ROOT:\?[^}]+\}\/secrets\/' . $name . '$/', $secret['file']);
        }
    }

    public function testProductionDataNeverEntersGitOrABuild(): void
    {
        // The repository root is the deployed working tree, the Compose
        // project directory and the build context: the data directories sit
        // in it and are kept out by the ignore files.
        foreach (['nginx', 'php'] as $service) {
            self::assertSame('.', self::$compose['services'][$service]['build']['context'], $service);
        }
        $gitignore = self::file('.gitignore');
        foreach (['/mysql/', '/secrets/', '/mysql-init-guard/', '/mysql-data/', '/data/', '*.ibd', 'ibdata*', 'ib_logfile*', 'binlog.[0-9]*', 'allow-empty-init', '/.env.*'] as $pattern) {
            self::assertMatchesRegularExpression('/^' . preg_quote($pattern, '/') . '$/m', $gitignore, '.gitignore: ' . $pattern);
        }
        $dockerignore = self::file('.dockerignore');
        foreach (['mysql', 'secrets', 'mysql-init-guard', 'mysql-data', 'data', '**/*.ibd', '**/ibdata*', '**/ib_logfile*', '**/binlog.*', '**/allow-empty-init', '.git', '.env.*'] as $pattern) {
            self::assertMatchesRegularExpression('/^' . preg_quote($pattern, '/') . '$/m', $dockerignore, '.dockerignore: ' . $pattern);
        }
        self::assertDoesNotMatchRegularExpression('/^\s*!/m', $dockerignore, 'No exception patterns: the builder never looks inside an excluded directory');
        // Nothing is tracked where production data lives, so "git checkout -f"
        // never writes there.
        $tracked = [];
        exec('git -C ' . escapeshellarg(self::path('')) . ' ls-files -- mysql secrets mysql-init-guard data mysql-data .git 2>/dev/null', $tracked);
        self::assertSame([], $tracked);
        // The images get explicit paths only, never the whole context.
        foreach (['docker/php/Dockerfile', 'docker/nginx/Dockerfile'] as $dockerfile) {
            $code = self::code($dockerfile);
            self::assertDoesNotMatchRegularExpression('/^\s*ADD\s/mi', $code, $dockerfile);
            self::assertDoesNotMatchRegularExpression('/^\s*COPY\s+(--\S+\s+)*(\.|\.\/|\*)\s/mi', $code, $dockerfile . ': no COPY of the whole context');
            self::assertDoesNotMatchRegularExpression('/^\s*COPY\s[^\n]*\b(mysql|secrets|mysql-init-guard)\b(?!\/)/mi', preg_replace('#docker/mysql/#', '', $code) ?? '', $dockerfile);
        }
        // The data root is a sibling of the working tree, never inside it,
        // and the preflight refuses data or .git inside the tree.
        self::assertFalse(str_starts_with(self::DATA . '/', self::APP . '/'));
        self::assertFalse(str_starts_with(self::APP . '/', self::DATA . '/'));
        $preflight = self::file(self::PREFLIGHT);
        self::assertStringContainsString("readonly TREE_FORBIDDEN=('.git' 'mysql' 'secrets' 'mysql-init-guard' 'mysql-data' 'data')\n", $preflight);
        self::assertStringContainsString("readonly MYSQL_DIR=\$DATA_ROOT/mysql\n", $preflight);
        self::assertStringNotContainsString('./mysql', self::code('docker-compose.prod.yml'));
    }

    public function testTheWorkingTreeIsDeployedFromTheBareRepository(): void
    {
        $doc = self::file('docs/DEPLOYMENT.md');
        foreach ([
            'repo=/home/brais/git/digital-library.git',
            'sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 0755 "$app"',
            'test -z "$(git --git-dir="$repo" ls-tree --name-only main -- mysql secrets mysql-init-guard)"',
            'git --git-dir="$repo" --work-tree="$app" checkout -f main',
            'test ! -e "$app/.git"',
            'for d in mysql secrets mysql-init-guard; do test ! -e "$app/$d"; done',
            'g() { git --git-dir="$repo" --work-tree=/opt/digital-library "$@"; }',
            'g diff --exit-code --stat',
            'test -z "$(g status --porcelain --untracked-files=no)" && test ! -e .git',
            'g checkout -f main && test "$(g rev-parse HEAD)" = "$rev"',
            'A push deploys nothing',
            'Never run `git clean -x`/`-X`, `rsync --delete` or a fresh `git clone`',
        ] as $line) {
            self::assertStringContainsString($line, $doc);
        }
        // The checkout runs before the data directories exist and as the
        // deploying user, never root; nothing clones into the tree or cleans
        // ignored files.
        self::assertLessThan(strpos($doc, 'sudo install -d -o 999 -g 999 -m 0700 "$data/mysql"'), strpos($doc, 'git --git-dir="$repo" --work-tree="$app" checkout -f main'));
        foreach (['docs/DEPLOYMENT.md', 'docs/OPERATIONS.md', 'ARCHITECTURE.md', 'SECURITY.md', 'README.md', 'docker-compose.prod.yml', self::UNIT, '.env.production.example'] as $file) {
            $text = self::file($file);
            self::assertDoesNotMatchRegularExpression('#sudo\s+git\b|git\s+clone\s[^\n]*/opt/digital-library|/opt/digital-library/app\b|digital-library/docker/mysql-init-guard#', $text, $file);
        }
    }

    public function testTheGuardWrapsTheDatabaseEntrypoint(): void
    {
        $db = self::$compose['services']['db'];
        self::assertSame(['/usr/local/bin/dla-db-guard'], $db['entrypoint']);
        self::assertSame(['mariadbd'], $db['command'], 'The image\'s own command, passed through');
        self::assertContains('./docker/mysql/db-guard.sh:/usr/local/bin/dla-db-guard:ro', $db['volumes']);
        self::assertSame('100755', self::mode(self::GUARD));
        $guard = self::code(self::GUARD);
        self::assertSame(2, substr_count($guard, 'exec docker-entrypoint.sh "$@"'), 'Only two ways in: an existing database, or an authorised first deployment');
        self::assertStringContainsString('authorisation=${DLA_DB_INIT_DIR:-/run/dla-db-init}/allow-empty-init', $guard);
        self::assertStringContainsString('marker=.dla-init-complete', $guard, 'Written last by docker/mysql/init/01-create-users.sh');
        self::assertStringContainsString(': > "$DATADIR/.dla-init-complete"', self::code('docker/mysql/init/01-create-users.sh'));
        self::assertArrayNotHasKey('DLA_DB_DATADIR', $db['environment'], 'Test hooks are never set in production');
        self::assertArrayNotHasKey('DLA_DB_INIT_DIR', $db['environment']);
    }

    /**
     * @param list<string>|null $files files to create in the data directory (null: no directory)
     */
    #[DataProvider('guardCases')]
    public function testTheGuard(?array $files, bool $authorised, bool $starts, string $expect): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-guard-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0700, true);
        mkdir($this->dir . '/init', 0700);
        if ($files !== null) {
            mkdir($this->dir . '/data', 0700);
            foreach ($files as $file) {
                str_ends_with($file, '/') ? mkdir($this->dir . '/data/' . $file, 0700) : file_put_contents($this->dir . '/data/' . $file, '');
            }
        }
        if ($authorised) {
            touch($this->dir . '/init/allow-empty-init');
        }
        file_put_contents($this->dir . '/bin/docker-entrypoint.sh', "#!/bin/sh\necho \"STARTED \$*\"\n");
        chmod($this->dir . '/bin/docker-entrypoint.sh', 0700);
        $env = ['PATH' => $this->dir . '/bin:' . (string) getenv('PATH'), 'DLA_DB_DATADIR' => $this->dir . '/data', 'DLA_DB_INIT_DIR' => $this->dir . '/init'];
        [$code, $output] = self::execute(['sh', self::path(self::GUARD), 'mariadbd'], $env);

        self::assertSame($starts ? 0 : 1, $code, $output);
        self::assertSame($starts, str_contains($output, 'STARTED mariadbd'), $output);
        self::assertStringContainsString($expect, $output);
    }

    /**
     * Entries ending in "/" are directories. A MariaDB data directory has the
     * system schema mysql/; the init script writes .dla-init-complete last.
     *
     * @return iterable<string, array{list<string>|null, bool, bool, string}>
     */
    public static function guardCases(): iterable
    {
        yield 'existing database' => [['mysql/', '.dla-init-complete', 'ibdata1', 'library/'], false, true, 'STARTED mariadbd'];
        yield 'existing database, authorisation left over' => [['mysql/', '.dla-init-complete'], true, true, 'STARTED mariadbd'];
        yield 'initialisation did not finish' => [['mysql/', 'ibdata1', 'library/'], false, false, 'initialisation did not finish'];
        yield 'initialisation did not finish, authorised' => [['mysql/', 'ibdata1'], true, false, 'initialisation did not finish'];
        yield 'MySQL 8 data directory' => [['mysql.ibd', 'mysql/', 'ibdata1', '.dla-init-complete'], false, false, 'MySQL 8 data directory'];
        yield 'MySQL 8 data directory, authorised' => [['mysql.ibd'], true, false, 'MySQL 8 data directory'];
        yield 'marker without a database' => [['.dla-init-complete'], true, false, 'has files but no database'];
        yield 'mysql is a file, not the system schema' => [['mysql', '.dla-init-complete'], false, false, 'has files but no database'];
        yield 'first deployment, authorised' => [[], true, true, 'initialising a NEW database'];
        yield 'empty, not authorised' => [[], false, false, 'refusing to initialise a new, empty database'];
        yield 'files but no database, authorised' => [['ibdata1'], true, false, 'has files but no database'];
        yield 'files but no database' => [['stray'], false, false, 'has files but no database'];
        yield 'hidden file only, authorised' => [['.keep'], true, false, 'has files but no database'];
        yield 'no data directory' => [null, true, false, 'is not a directory'];
    }

    public function testTheConfirmedLocationsAreUsedAndNeverDockerdata(): void
    {
        $template = self::file('.env.production.example');
        preg_match_all('/^DLA_STORAGE_ROOT=(.*)$/m', $template, $hdd);
        preg_match_all('/^DLA_DATA_ROOT=(.*)$/m', $template, $data);
        self::assertSame([self::ROOT], $hdd[1], 'Owner-confirmed HDD root, set exactly once');
        self::assertSame([self::DATA], $data[1], 'Owner-confirmed data root, set exactly once');
        foreach (['docker-compose.prod.yml', '.env.production.example', '.github/workflows/ci.yml', self::UNIT, self::GUARD] as $file) {
            self::assertStringNotContainsString('/mnt/dockerdata', self::code($file), $file);
        }
        // The preflight names it only to reject it.
        $mentions = array_values(preg_grep('#/mnt/dockerdata#', explode("\n", self::code(self::PREFLIGHT))) ?: []);
        self::assertCount(1, $mentions);
        self::assertStringContainsString('it must be on the system SSD', $mentions[0]);
        $preflight = self::file(self::PREFLIGHT);
        foreach ([
            'readonly MOUNTPOINT=' . self::MOUNTPOINT,
            'readonly FS_UUID=' . self::UUID,
            'readonly FS_TYPE=ext4',
            'readonly ROOT=$MOUNTPOINT/digital-library',
            'readonly APP_DIR=' . self::APP,
            'readonly DATA_ROOT=' . self::DATA,
            'readonly INIT_AUTHORISATION=$DATA_ROOT/mysql-init-guard/allow-empty-init',
            'readonly INSTALLED=' . self::INSTALLED,
            'readonly TREE_USED=(',
        ] as $line) {
            self::assertStringContainsString($line . "\n", $preflight);
        }
    }

    public function testPreflightOnlyReads(): void
    {
        $code = self::code(self::PREFLIGHT);
        // Message texts are not commands (strings with a command substitution stay).
        $commands = (string) preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|\'[^\']*\'/', static fn (array $m): string => str_contains($m[0], '$(') ? $m[0] : '""', $code);
        self::assertStringStartsWith("#!/usr/bin/env bash\n", self::file(self::PREFLIGHT));
        self::assertStringContainsString('set -euo pipefail', $code);
        self::assertDoesNotMatchRegularExpression('/(^|[\s;|&(`$])(mkdir|chmod|chown|chattr|install|rm|rmdir|mv|cp|ln|touch|truncate|dd|tee|mount|umount|sed|docker|ssh|scp|curl|wget|sudo|systemctl|cat|openssl)(\s|$)/m', $commands, 'Read-only commands only, and secret values are never read');
        self::assertDoesNotMatchRegularExpression('/find\s[^\n]*-(delete|exec|execdir|ok|fprint)/', $commands);
        // The only redirections: to stderr and discarding errors.
        self::assertStringNotContainsString('>', str_replace(['>&2', '2>/dev/null'], '', $commands), 'No output redirection to files');
        self::assertSame('100755', self::mode(self::PREFLIGHT));
    }

    public function testInitialisationProcedureIsTheSameInTheRunbookAndCi(): void
    {
        $doc = self::procedure(self::file('docs/DEPLOYMENT.md'));
        $prepare = array_values(array_filter(BlockYaml::parseFile(self::path('.github/workflows/ci.yml'))['jobs']['production-stack']['steps'], static fn (array $s): bool => ($s['name'] ?? '') === 'Prepare throwaway configuration and secrets'));
        self::assertCount(1, $prepare);
        $ci = self::procedure($prepare[0]['run']);
        self::assertCount(12, $doc['install']);
        self::assertCount(5, $doc['secrets']);
        self::assertSame($doc, $ci, 'CI creates the directories and secrets with exactly the documented commands');
        foreach ($doc['install'] as $line) {
            self::assertDoesNotMatchRegularExpression('/\s-R\b|\s0?777\b|--recursive|books/', $line);
            self::assertMatchesRegularExpression('/"\$(root|data|app)/', $line, 'Only paths below the two roots; the HDD mountpoint is never changed');
        }
        $deployment = self::file('docs/DEPLOYMENT.md');
        self::assertDoesNotMatchRegularExpression('/^\s*(sudo\s+)?(chmod|chown)\s+(-\S+\s+)*(-R|--recursive|0?777)\b/m', $deployment, 'No recursive or world-writable changes');
        self::assertStringContainsString('test ! -e "$root"', $deployment, 'Never re-initialise an existing HDD directory');
        self::assertStringContainsString('test ! -e "$data/mysql"', $deployment, 'Never re-initialise an existing database directory');
        self::assertStringContainsString('test "$(findmnt --mountpoint /mnt/bravina-shared --noheadings --output UUID)" = ' . self::UUID, $deployment, 'Only on the right disk');

        // Every path the preflight checks is created with the same owner and mode.
        $created = [];
        $id = static fn (string $v): string => $v === 'root' ? '0' : $v;
        foreach ($doc['install'] as $line) {
            self::assertMatchesRegularExpression('/^sudo install -d -o ("[^"]*"|\S+) -g ("[^"]*"|\S+) -m (0[0-7]{3}) ("\$(root|data|app)[^"]*")( "\$(root|data|app)[^"]*")*$/', $line);
            preg_match('/^sudo install -d -o ("[^"]*"|\S+) -g ("[^"]*"|\S+) -m (0[0-7]{3}) (.*)$/', $line, $m);
            foreach (explode(' ', $m[4]) as $path) {
                $created[strtr(trim($path, '"'), ['$root' => self::ROOT, '$data' => self::DATA, '$app' => self::APP])] = 'directory|' . $id($m[1]) . '|' . $id($m[2]) . '|' . decoct((int) octdec($m[3]));
            }
        }
        foreach ([
            self::DATA . '/secrets/db_root_password' => 'regular file|999|999|400',
            self::DATA . '/secrets/db_password' => 'regular file|82|999|440',
            self::DATA . '/secrets/db_migration_password' => 'regular file|82|999|440',
        ] as $path => $expected) {
            $created[$path] = $expected;
        }
        $secrets = implode("\n", $doc['secrets']);
        self::assertStringContainsString('sudo chown 999:999 "$data/secrets/db_root_password"', $secrets);
        self::assertStringContainsString('sudo chmod 0400 "$data/secrets/db_root_password"', $secrets);
        self::assertStringContainsString('sudo chown 82:999 "$data/secrets/db_password" "$data/secrets/db_migration_password"', $secrets);
        self::assertStringContainsString('sudo chmod 0440 "$data/secrets/db_password" "$data/secrets/db_migration_password"', $secrets);

        preg_match_all('/"\$(ROOT|DATA_ROOT|MYSQL_DIR)([^|]*)\|(directory|regular file)\|(\d+)\|(\d+)\|([0-7]+)"/', self::file(self::PREFLIGHT), $entries, PREG_SET_ORDER);
        self::assertCount(13, $entries);
        foreach ($entries as [, $base, $suffix, $type, $uid, $gid, $mode]) {
            $path = ['ROOT' => self::ROOT, 'DATA_ROOT' => self::DATA, 'MYSQL_DIR' => self::DATA . '/mysql'][$base] . $suffix;
            self::assertArrayHasKey($path, $created, $path . ' is created by the procedure');
            self::assertSame("{$type}|{$uid}|{$gid}|{$mode}", $created[$path], $path);
        }
        // The working tree belongs to the deploying user, not root or a container.
        self::assertSame('directory|"$(id -un)"|"$(id -gn)"|755', $created[self::APP]);
        self::assertSame('directory|0|0|755', $created[self::DATA], 'The data root is root-owned');
        // The privileged preflight is installed root-owned, identically in both.
        $install = 'sudo install -o root -g root -m 0755 "$app/docker/storage/preflight.sh" ' . self::INSTALLED;
        self::assertStringContainsString($install, $deployment);
        self::assertStringContainsString($install, $prepare[0]['run']);
        // The owners match the images: mysql (999) and www-data (82).
        self::assertSame('directory|999|999|700', $created[self::DATA . '/mysql']);
        foreach (['/storage', '/covers', '/backups'] as $dir) {
            self::assertStringStartsWith('directory|82|82|', $created[self::ROOT . $dir]);
        }
        self::assertSame('directory|0|0|0', $created[self::ROOT . '/storage/backups'], 'The web container cannot use the backups stub');
        $php = self::file('docker/php/Dockerfile');
        self::assertMatchesRegularExpression('/^USER www-data$/m', substr($php, (int) strpos($php, 'FROM base AS prod')));
    }

    public function testFirstDeploymentIsExplicit(): void
    {
        $doc = self::file('docs/DEPLOYMENT.md');
        foreach ([
            'sudo touch /opt/digital-library-data/mysql-init-guard/allow-empty-init',
            'sudo ' . self::INSTALLED . ' --env-file /opt/digital-library/.env.production --first-deployment',
            'sudo rm /opt/digital-library-data/mysql-init-guard/allow-empty-init',
            'sudo ' . self::INSTALLED . ' --env-file /opt/digital-library/.env.production --existing-installation',
        ] as $step) {
            self::assertStringContainsString($step, $doc);
        }
        self::assertLessThan(strpos($doc, 'sudo rm /opt/digital-library-data/mysql-init-guard/allow-empty-init'), strpos($doc, 'sudo touch /opt/digital-library-data/mysql-init-guard/allow-empty-init'));
        $ci = self::file('.github/workflows/ci.yml');
        self::assertStringContainsString('sudo touch "$data/mysql-init-guard/allow-empty-init"', $ci);
        self::assertStringContainsString('sudo rm /srv/dla-ci-data/mysql-init-guard/allow-empty-init', $ci);
        self::assertStringContainsString("grep -F 'first deployment is authorised: initialising a NEW database'", $ci);
    }

    public function testTheDedicatedUnitIsTheOnlyBootPath(): void
    {
        $mountUnit = str_replace('/', '-', str_replace('-', '\x2d', ltrim(self::MOUNTPOINT, '/'))) . '.mount';
        self::assertSame('mnt-bravina\x2dshared.mount', $mountUnit);
        $unit = self::unit();
        self::assertSame(['Unit', 'Service', 'Install'], array_keys($unit));
        self::assertSame([
            'Description' => ['Digital Library Archive (storage-guarded production stack)'],
            'Documentation' => ['file:///opt/digital-library/docs/DEPLOYMENT.md'],
            'Requires' => ['docker.service'],
            'After' => ['docker.service network-online.target', $mountUnit],
            'Wants' => ['network-online.target'],
            'BindsTo' => [$mountUnit],
            'RequiresMountsFor' => [self::APP . ' ' . self::DATA],
            'AssertPathIsMountPoint' => [self::MOUNTPOINT],
        ], $unit['Unit'], 'Depends on Docker and both disks; adds nothing to docker.service');
        $compose = '/usr/bin/docker compose -f docker-compose.prod.yml --env-file .env.production ';
        self::assertSame([
            'Type' => ['oneshot'],
            'RemainAfterExit' => ['yes'],
            'WorkingDirectory' => [self::APP],
            // The root-owned installed copy, never the user-writable tree's.
            'ExecStartPre' => [self::INSTALLED . ' --env-file ' . self::APP . '/.env.production --existing-installation'],
            'ExecStart' => [$compose . 'up -d --no-recreate --no-build --pull never --wait --wait-timeout 300'],
            'ExecStopPost' => [$compose . 'stop'],
            'TimeoutStartSec' => ['600'],
            'TimeoutStopSec' => ['180'],
        ], $unit['Service'], 'Preflight first; start only what was deployed; any failure stops the stack');
        self::assertSame(['WantedBy' => ['multi-user.target']], $unit['Install']);

        foreach (self::$compose['services'] as $name => $service) {
            self::assertSame('no', $service['restart'] ?? null, $name . ': the Docker daemon never starts it by itself');
        }
        // docker.service is never modified, and nothing in the repository
        // makes Docker (and so the other workloads) wait for these disks.
        foreach (['docs/DEPLOYMENT.md', self::UNIT, 'docker-compose.prod.yml', '.github/workflows/ci.yml', 'ARCHITECTURE.md', 'SECURITY.md'] as $file) {
            self::assertDoesNotMatchRegularExpression('#docker\.service\.d|systemctl\s+edit\s+docker|Before=docker\.service|RequiredBy=docker|WantedBy=docker#', self::file($file), $file);
        }
        self::assertSame([], glob(self::path('docker/storage/systemd/*.conf')) ?: [], 'No docker.service drop-in');
        $doc = self::file('docs/DEPLOYMENT.md');
        self::assertStringContainsString('sudo install -o root -g root -m 0644 docker/storage/systemd/digital-library.service /etc/systemd/system/digital-library.service', $doc);
        self::assertStringContainsString('sudo systemctl enable digital-library.service', $doc);
        self::assertStringContainsString('### 3.5 If the HDD disappears while the stack is running', $doc);
    }

    public function testCiChecksTheStorageAndTheFailClosedStart(): void
    {
        $ci = self::file('.github/workflows/ci.yml');
        foreach (['DLA_STORAGE_ROOT', 'DLA_DATA_ROOT'] as $key) {
            self::assertStringContainsString("refuse env {$key}= docker compose -f docker-compose.prod.yml --env-file .env.production config --quiet", $ci);
        }
        // CI: the checkout as the working tree, the data root outside it.
        self::assertSame(1, substr_count($ci, 'app=$PWD'));
        self::assertSame(3, substr_count($ci, 'data=/srv/dla-ci-data'));
        self::assertStringNotContainsString('$PWD/', $ci);
        self::assertStringContainsString("sed -i 's#^DLA_DATA_ROOT=.*#DLA_DATA_ROOT=/srv/dla-ci-data#' .env.production", $ci);
        self::assertStringContainsString('{"read_only":false,"source":"/srv/dla-ci-data/mysql","target":"/var/lib/mysql","type":"bind"}', $ci);
        // The normalised-JSON comparison never relies on create_host_path,
        // which Compose may omit (null is not proof of false).
        $static = BlockYaml::parseFile(self::path('.github/workflows/ci.yml'))['jobs']['static']['steps'];
        $validate = implode("\n", array_column(array_filter($static, static fn (array $s): bool => ($s['name'] ?? '') === 'Validate the Compose files'), 'run'));
        $code = implode("\n", array_filter(explode("\n", $validate), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
        self::assertStringContainsString("expect 'storage mounts'", $code);
        self::assertStringNotContainsString('create_host_path', $code);
        self::assertStringContainsString('{"create_mountpoint":[false],"mounts":[{"rw":false,"source":"/srv/dla-ci-data/mysql-init-guard","target":"/run/dla-db-init","type":"bind"},{"rw":true,"source":"/srv/dla-ci-data/mysql","target":"/var/lib/mysql","type":"bind"}],"service":"db"}', $ci);
        self::assertStringContainsString('test -z "$(git ls-files -- mysql secrets mysql-init-guard)"', $ci);
        self::assertStringContainsString('for path in mysql secrets mysql-init-guard; do test ! -e "$path"; done', $ci);
        self::assertStringContainsString("test \"\$(stat -c '%u %a' " . self::INSTALLED . ")\" = '0 755'", $ci);
        self::assertStringContainsString("jq -c '[.services[] | .restart] | unique')\" '[\"no\"]'", $ci, 'Compose resolves restart "no" everywhere');
        self::assertStringContainsString('test "$policies" = \'[{"restart":"no","service":"db"},{"restart":"no","service":"nginx"},{"restart":"no","service":"php"}]\'', $ci, 'Docker holds restart "no"');
        self::assertStringContainsString('refuse dc exec -T php touch /var/www/html/storage/backups/ci-write-test', $ci);
        self::assertStringContainsString('refuse dc exec -T php test -e /var/lib/mysql/.dla-init-complete', $ci);
        self::assertStringContainsString('refuse dc exec -T nginx test -e /var/lib/mysql/.dla-init-complete', $ci);
        $steps = BlockYaml::parseFile(self::path('.github/workflows/ci.yml'))['jobs']['production-stack']['steps'];
        $drill = array_values(array_filter($steps, static fn (array $s): bool => ($s['name'] ?? '') === 'Missing or unexpected storage fails closed (no Docker daemon restart)'));
        self::assertCount(1, $drill);
        $run = $drill[0]['run'];
        self::assertDoesNotMatchRegularExpression('/systemctl|dockerd|service docker/', $run, 'The global Docker daemon is never restarted');
        $up = substr(self::unit()['Service']['ExecStart'][0], strlen('/usr/bin/docker compose -f docker-compose.prod.yml --env-file .env.production '));
        self::assertSame('up -d --no-recreate --no-build --pull never --wait --wait-timeout 300', $up);
        foreach ([
            'sudo mv "$root" /srv/dla-ci-hdd-detached',
            'if out=$(docker start "$php" 2>&1); then',
            'refuse dc up -d --no-recreate --no-build --pull never --wait --wait-timeout 60',
            'test ! -e "$root"',
            'sudo install -d -o 999 -g 999 -m 0700 "$data/mysql"',
            'refuse dc up -d --no-recreate --no-build --pull never --wait --wait-timeout 60 db',
            "dc logs db | grep -F 'refusing to initialise a new, empty database'",
            'test -z "$(sudo find "$data/mysql" -mindepth 1 -print -quit)"',
            'if out=$(docker start "$db" 2>&1); then',
            'test ! -e "$data/mysql"',
            'dc ' . $up,
            'printf \'%s\n\' "$status" | grep -q applied',
        ] as $check) {
            self::assertStringContainsString($check, $run);
        }
        self::assertSame(2, substr_count($run, "grep -F 'source path does not exist'"));
        self::assertMatchesRegularExpression('#shellcheck [^\n]*docker/storage/preflight\.sh#', $ci);
        self::assertMatchesRegularExpression('#shellcheck --severity=warning --shell=sh [^\n]*docker/mysql/db-guard\.sh#', $ci);
    }

    /**
     * @param array<string, string|null> $change simulated host changes (null deletes)
     * @param list<string> $args
     */
    #[DataProvider('preflightCases')]
    public function testPreflight(array $change, array $args, int $status, string $expect): void
    {
        $this->fakeHost($change);
        $args = $args === [] ? ['--env-file', 'ENV', '--existing-installation'] : $args;
        [$code, $output] = self::execute(
            array_merge(['bash', self::path(self::PREFLIGHT)], str_replace('ENV', $this->dir . '/env', $args)),
            ['PATH' => $this->dir . '/bin:' . (string) getenv('PATH'), 'FAKE' => $this->dir . '/state', 'LC_ALL' => 'C'],
        );
        self::assertSame($status, $code, $output);
        self::assertStringContainsString($expect, $output);
        if ($status === 0) {
            self::assertStringNotContainsString('FAIL', $output);
        }
    }

    /**
     * @return iterable<string, array{array<string, string|null>, list<string>, int, string}>
     */
    public static function preflightCases(): iterable
    {
        $root = self::ROOT;
        $app = self::APP;
        $data = self::DATA;
        $installed = self::INSTALLED;
        $first = ['--env-file', 'ENV', '--first-deployment'];
        $fresh = ["stat:{$data}/mysql/mysql" => null, "stat:{$data}/mysql/.dla-init-complete" => null, "stat:{$data}/mysql/library" => null, "stat:{$data}/mysql-init-guard/allow-empty-init" => 'regular empty file|0|0|644'];
        $env = static fn (string $lines): array => ['env' => $lines];

        yield 'existing installation' => [[], [], 0, 'preflight: all storage checks passed.'];
        yield 'existing: database present' => [[], [], 0, 'ok    existing MariaDB database (system schema mysql/ present)'];
        yield 'existing: initialisation completed' => [[], [], 0, 'ok    database initialisation completed (.dla-init-complete present)'];
        yield 'existing: marker with content' => [["stat:{$data}/mysql/.dla-init-complete" => 'regular file|999|999|600'], [], 0, 'ok    database initialisation completed'];
        yield 'existing: schema present' => [[], [], 0, "ok    application schema 'library' present"];
        yield 'existing: installed copy' => [[], [], 0, "ok    {$installed} is root-owned, mode 755"];
        yield 'existing: checkout copy identical' => [[], [], 0, "ok    the checkout's preflight is identical to the one running"];
        yield 'existing: checkout copy changed (note only)' => [['cmp-same' => null], [], 0, "note  the checkout's preflight differs from the one running: review the change, then install it again"];
        yield 'existing: this env file' => [[], [], 0, 'ok    the env file is /opt/digital-library/.env.production'];
        yield 'existing: no .git' => [[], [], 0, 'ok    no .git in /opt/digital-library'];
        yield 'existing: no data in the tree' => [[], [], 0, 'ok    no mysql in /opt/digital-library'];
        yield 'existing: working tree' => [[], [], 0, 'ok    /opt/digital-library is the working tree (owner uid 1000, 755)'];
        yield 'existing: data root' => [[], [], 0, 'ok    /opt/digital-library-data 0:0 755'];
        yield 'existing: root-owned env file' => [["stat:{$app}/.env.production" => 'regular file|0|1000|640|2049:20'], [], 0, 'ok    /opt/digital-library/.env.production uid 0, mode 640'];
        yield 'existing: empty database directory' => [["stat:{$data}/mysql/mysql" => null, "stat:{$data}/mysql/.dla-init-complete" => null], [], 1, 'FAIL  no MariaDB database'];
        yield 'existing: initialisation did not finish' => [["stat:{$data}/mysql/.dla-init-complete" => null], [], 1, "FAIL  {$data}/mysql/.dla-init-complete missing: the first initialisation did not finish"];
        yield 'existing: marker is a directory' => [["stat:{$data}/mysql/.dla-init-complete" => 'directory|999|999|700'], [], 1, '.dla-init-complete missing'];
        yield 'existing: MySQL 8 files' => [["stat:{$data}/mysql/mysql.ibd" => 'regular file|999|999|640'], [], 1, "FAIL  {$data}/mysql holds MySQL 8 files (mysql.ibd): MariaDB cannot use them"];
        yield 'existing: another database' => [["stat:{$data}/mysql/library" => null], [], 1, "FAIL  application schema 'library' missing"];
        yield 'existing: authorisation left behind' => [["stat:{$data}/mysql-init-guard/allow-empty-init" => 'regular empty file|0|0|644'], [], 1, 'is still present: remove it'];
        yield 'first deployment' => [$fresh, $first, 0, 'ok    /opt/digital-library-data/mysql is empty: a new database will be initialised'];
        yield 'first deployment: mode shown' => [$fresh, $first, 0, 'note  mode: FIRST DEPLOYMENT'];
        yield 'first deployment: directory not empty' => [$fresh + ['find:' . $data . '/mysql' => $data . '/mysql/ibdata1'], $first, 1, 'is not empty (/opt/digital-library-data/mysql/ibdata1): this is not a first deployment'];
        yield 'first deployment: existing database' => [['find:' . $data . '/mysql' => $data . '/mysql/mysql.ibd'], $first, 1, 'this is not a first deployment'];
        yield 'first deployment: not authorised' => [["stat:{$data}/mysql/mysql" => null, "stat:{$data}/mysql/.dla-init-complete" => null, "stat:{$data}/mysql/library" => null], $first, 1, 'FAIL  initialisation not authorised'];
        yield 'first deployment: authorisation not root-owned' => [array_merge($fresh, ["stat:{$data}/mysql-init-guard/allow-empty-init" => 'regular empty file|1000|1000|644']), $first, 1, 'FAIL  initialisation not authorised'];
        yield 'usage: no mode' => [[], ['--env-file', 'ENV'], 2, 'usage:'];
        yield 'usage: both modes' => [[], ['--env-file', 'ENV', '--first-deployment', '--existing-installation'], 2, 'usage:'];
        yield 'usage: no env file' => [[], ['--existing-installation'], 2, 'usage:'];
        yield 'usage: bad minimum' => [[], ['--env-file', 'ENV', '--existing-installation', '--min-free-gb', 'x'], 2, 'usage:'];
        yield 'usage: unknown option' => [[], ['--env-file', 'ENV', '--existing-installation', '--fix'], 2, 'usage:'];

        yield 'HDD not mounted' => [['mount' => null], [], 1, 'FAIL  nothing is mounted at /mnt/bravina-shared'];
        yield 'another HDD' => [['mount' => "11111111-2222-3333-4444-555555555555 ext4 rw,relatime\n"], [], 1, "FAIL  UUID at /mnt/bravina-shared is '11111111"];
        yield 'two filesystems stacked' => [['mount' => self::UUID . " ext4 rw,relatime\n" . self::UUID . " ext4 rw,relatime\n"], [], 1, 'FAIL  more than one filesystem'];
        yield 'not ext4' => [['mount' => self::UUID . " xfs rw,relatime\n"], [], 1, "FAIL  filesystem type is 'xfs'"];
        yield 'read-only' => [['mount' => self::UUID . " ext4 ro,relatime\n"], [], 1, 'FAIL  not mounted read-write'];
        yield 'HDD mountpoint owner changed' => [['stat:/mnt/bravina-shared' => 'directory|1000|1000|755'], [], 1, 'FAIL  /mnt/bravina-shared is'];
        yield 'HDD mountpoint opened up' => [['stat:/mnt/bravina-shared' => 'directory|0|0|777'], [], 1, 'FAIL  /mnt/bravina-shared is'];
        yield 'HDD root on dockerdata' => [$env("DLA_STORAGE_ROOT=/mnt/dockerdata/digital-library\nDLA_DATA_ROOT={$data}\nDB_DATABASE=library\n"), [], 1, "DLA_STORAGE_ROOT is '/mnt/dockerdata/digital-library'"];
        yield 'data root in the working tree' => [$env("DLA_STORAGE_ROOT={$root}\nDLA_DATA_ROOT={$app}\nDB_DATABASE=library\n"), [], 1, "DLA_DATA_ROOT is '/opt/digital-library', expected exactly /opt/digital-library-data"];
        yield 'data root unset' => [$env("DLA_STORAGE_ROOT={$root}\nDB_DATABASE=library\n"), [], 1, 'must set DLA_DATA_ROOT exactly once (found 0)'];
        yield 'HDD root set twice' => [$env("DLA_STORAGE_ROOT={$root}\nDLA_STORAGE_ROOT={$root}\nDLA_DATA_ROOT={$data}\nDB_DATABASE=library\n"), [], 1, 'must set DLA_STORAGE_ROOT exactly once (found 2)'];
        yield 'HDD root quoted' => [$env("DLA_STORAGE_ROOT=\"{$root}\"\nDLA_DATA_ROOT={$data}\nDB_DATABASE=library\n"), [], 1, "DLA_STORAGE_ROOT is '\"/mnt"];
        yield 'schema name unsafe' => [$env("DLA_STORAGE_ROOT={$root}\nDLA_DATA_ROOT={$data}\nDB_DATABASE=../x\n"), [], 1, "DB_DATABASE '../x' is not a plain schema name"];
        yield 'env file missing' => [['env' => null], [], 1, 'FAIL  cannot read'];
        yield 'HDD storage is a symlink' => [["stat:{$root}/storage" => 'symbolic link|82|82|777'], [], 1, "FAIL  {$root}/storage is 'symbolic link"];
        yield 'HDD backups stub usable' => [["stat:{$root}/storage/backups" => 'directory|82|82|750'], [], 1, "FAIL  {$root}/storage/backups is"];
        yield 'HDD covers missing' => [["stat:{$root}/covers" => null], [], 1, "FAIL  {$root}/covers is missing"];
        yield 'HDD backups on another filesystem' => [["target:{$root}/backups" => '/'], [], 1, "FAIL  {$root}/backups is on the filesystem mounted at '/'"];
        yield 'HDD root missing (SSD mountpoint)' => [["stat:{$root}" => null], [], 1, "FAIL  {$root} is missing"];

        yield 'installed copy missing' => [["stat:{$installed}" => null], [], 1, "FAIL  {$installed} is 'missing': install the reviewed preflight"];
        yield 'installed copy owned by the deploying user' => [["stat:{$installed}" => 'regular file|1000|1000|755|2049:30'], [], 1, "FAIL  {$installed} is owned by uid 1000"];
        yield 'installed copy group-writable' => [["stat:{$installed}" => 'regular file|0|0|775|2049:30'], [], 1, 'with mode 775: it must be root-owned'];
        yield 'installed copy is a symlink into the tree' => [["stat:{$installed}" => 'symbolic link|0|0|777|2049:30'], [], 1, "FAIL  {$installed} is 'symbolic link'"];
        yield 'working tree opened up' => [["stat:{$app}" => 'directory|1000|1000|777|2049:2'], [], 1, "FAIL  {$app} (the working tree) has mode 777"];
        yield 'working tree owned by mysql' => [["stat:{$app}" => 'directory|999|999|755|2049:2'], [], 1, 'is owned by container user 999'];
        yield 'working tree missing' => [["stat:{$app}" => null], [], 1, "FAIL  {$app} (the working tree) is missing"];
        yield 'another env file' => [['stat:ENV' => 'regular file|1000|1000|640|2049:98'], [], 1, 'is not /opt/digital-library/.env.production'];
        yield 'clone with .git in the tree' => [["stat:{$app}/.git" => 'directory|1000|1000|755|2049:97'], [], 1, "FAIL  {$app}/.git exists"];
        yield 'database copied into the tree' => [["stat:{$app}/mysql" => 'directory|999|999|700|2049:96'], [], 1, "FAIL  {$app}/mysql exists"];
        yield 'secrets copied into the tree' => [["stat:{$app}/secrets" => 'directory|0|0|700|2049:95'], [], 1, "FAIL  {$app}/secrets exists"];
        yield 'guard directory in the tree' => [["stat:{$app}/mysql-init-guard" => 'directory|0|0|755|2049:94'], [], 1, "FAIL  {$app}/mysql-init-guard exists"];
        yield 'compose file world-writable' => [["stat:{$app}/docker-compose.prod.yml" => 'regular file|1000|1000|666|2049:3'], [], 1, 'docker-compose.prod.yml has mode 666: group- or world-writable'];
        yield 'my.cnf group-writable' => [["stat:{$app}/docker/mysql/my.cnf" => 'regular file|1000|1000|664|2049:9'], [], 1, 'my.cnf has mode 664'];
        yield 'guard owned by someone else' => [["stat:{$app}/docker/mysql/db-guard.sh" => 'regular file|1001|1001|755|2049:5'], [], 1, 'db-guard.sh is owned by uid 1001'];
        yield 'guard replaced by a symlink' => [["stat:{$app}/docker/mysql/db-guard.sh" => 'symbolic link|1000|1000|777|2049:5'], [], 1, "db-guard.sh is 'symbolic link'"];
        yield 'docker directory missing' => [["stat:{$app}/docker" => null], [], 1, "{$app}/docker is 'missing'"];

        yield 'data root owned by the deploying user' => [["stat:{$data}" => 'directory|1000|1000|755'], [], 1, "FAIL  {$data} is 'directory|1000|1000|755'"];
        yield 'data root missing' => [["stat:{$data}" => null], [], 1, "FAIL  {$data} is missing"];
        yield 'MySQL directory on the HDD' => [["target:{$data}/mysql" => '/mnt/bravina-shared'], [], 1, 'it must be on the system SSD'];
        yield 'MySQL directory on dockerdata' => [["target:{$data}/mysql" => '/mnt/dockerdata'], [], 1, 'it must be on the system SSD'];
        yield 'MySQL directory owned by www-data' => [["stat:{$data}/mysql" => 'directory|82|82|700'], [], 1, "FAIL  {$data}/mysql is 'directory|82|82|700'"];
        yield 'MySQL directory readable by others' => [["stat:{$data}/mysql" => 'directory|999|999|755'], [], 1, "FAIL  {$data}/mysql is"];
        yield 'MySQL directory missing' => [["stat:{$data}/mysql" => null], [], 1, "FAIL  {$data}/mysql is missing"];
        yield 'secrets directory readable' => [["stat:{$data}/secrets" => 'directory|0|0|755'], [], 1, "FAIL  {$data}/secrets is"];
        yield 'root password world-readable' => [["stat:{$data}/secrets/db_root_password" => 'regular file|999|999|444'], [], 1, "FAIL  {$data}/secrets/db_root_password is"];
        yield 'empty secret' => [["stat:{$data}/secrets/db_password" => 'regular empty file|82|999|440'], [], 1, "FAIL  {$data}/secrets/db_password is 'regular empty file"];
        yield 'secret missing' => [["stat:{$data}/secrets/db_migration_password" => null], [], 1, "FAIL  {$data}/secrets/db_migration_password is missing"];
        yield 'guard directory missing' => [["stat:{$data}/mysql-init-guard" => null], [], 1, "FAIL  {$data}/mysql-init-guard is missing"];
        yield 'low space' => [['df' => "Avail\n   5\n"], [], 1, "FAIL  free space on /mnt/bravina-shared is '5' GB"];
        yield 'custom minimum' => [['df' => "Avail\n   5\n"], ['--env-file', 'ENV', '--existing-installation', '--min-free-gb', '4'], 0, 'ok    5 GB free'];
    }

    /**
     * A simulated host (existing installation), then the case's changes.
     *
     * @param array<string, string|null> $change
     */
    private function fakeHost(array $change): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-preflight-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/bin', 0700, true);
        mkdir($this->dir . '/state', 0700);
        $root = self::ROOT;
        $app = self::APP;
        $data = self::DATA;
        // type|uid|gid|mode[|device:inode]; ENV is the env file this test
        // passes, the same file as the host's .env.production.
        $stat = [
            self::MOUNTPOINT => 'directory|0|0|755',
            $root => 'directory|0|0|700',
            "{$root}/storage" => 'directory|82|82|750',
            "{$root}/storage/covers" => 'directory|0|0|755',
            "{$root}/storage/backups" => 'directory|0|0|0',
            "{$root}/covers" => 'directory|82|82|750',
            "{$root}/backups" => 'directory|82|82|700',
            self::INSTALLED => 'regular file|0|0|755|2049:30',
            $app => 'directory|1000|1000|755|2049:2',
            "{$app}/.env.production" => 'regular file|1000|1000|640|2049:20',
            'ENV' => 'regular file|1000|1000|640|2049:20',
            "{$app}/docker-compose.prod.yml" => 'regular file|1000|1000|644|2049:3',
            "{$app}/docker" => 'directory|1000|1000|755|2049:6',
            "{$app}/docker/mysql" => 'directory|1000|1000|755|2049:8',
            "{$app}/docker/mysql/db-guard.sh" => 'regular file|1000|1000|755|2049:5',
            "{$app}/docker/mysql/init" => 'directory|1000|1000|755|2049:10',
            "{$app}/docker/mysql/my.cnf" => 'regular file|1000|1000|644|2049:9',
            $data => 'directory|0|0|755',
            "{$data}/mysql" => 'directory|999|999|700',
            "{$data}/mysql/mysql" => 'directory|999|999|700',
            "{$data}/mysql/.dla-init-complete" => 'regular empty file|999|999|600',
            "{$data}/mysql/library" => 'directory|999|999|750',
            "{$data}/secrets" => 'directory|0|0|700',
            "{$data}/secrets/db_root_password" => 'regular file|999|999|400',
            "{$data}/secrets/db_password" => 'regular file|82|999|440',
            "{$data}/secrets/db_migration_password" => 'regular file|82|999|440',
            "{$data}/mysql-init-guard" => 'directory|0|0|755',
        ];
        $targets = array_fill_keys([$root, "{$root}/storage", "{$root}/storage/covers", "{$root}/storage/backups", "{$root}/covers", "{$root}/backups"], self::MOUNTPOINT);
        $targets["{$data}/mysql"] = '/';
        $finds = [];
        $files = ['mount' => self::UUID . " ext4 rw,relatime\n", 'df' => " Avail\n  907\n", 'cmp-same' => ''];
        $env = "APP_ENV=production\nDB_DATABASE=library\nDLA_DATA_ROOT={$data}\nDLA_STORAGE_ROOT={$root}\n";
        foreach ($change as $key => $value) {
            [$kind, $path] = array_pad(explode(':', $key, 2), 2, '');
            if ($kind === 'stat') {
                if ($value === null) {
                    unset($stat[$path]);
                } else {
                    $stat[$path] = $value;
                }
            } elseif ($kind === 'target') {
                $targets[$path] = (string) $value;
            } elseif ($kind === 'find') {
                $finds[$path] = (string) $value;
            } elseif ($kind === 'env') {
                $env = $value;
            } else {
                $files[$key] = $value;
            }
        }
        $stat = array_combine(array_map(fn (string $k): string => $k === 'ENV' ? $this->dir . '/env' : $k, array_keys($stat)), $stat);
        $lines = static fn (array $map): string => implode('', array_map(static fn (string $k, string $v): string => $k . '|' . $v . "\n", array_keys($map), $map));
        $files['stat'] = $lines($stat);
        $files['targets'] = $lines($targets);
        $files['finds'] = $lines($finds);
        foreach ($files as $name => $content) {
            if ($content !== null) {
                file_put_contents($this->dir . '/state/' . $name, $content);
            }
        }
        if ($env !== null) {
            file_put_contents($this->dir . '/env', $env);
        }

        $lookup = 'awk -F"|" -v p="$path" \'{k=$1; sub(/^[^|]*\|/, ""); if (k == p) {print; f=1}} END {exit !f}\'';
        $fakes = [
            'findmnt' => "mode=''; path=''\nwhile [ \$# -gt 0 ]; do case \"\$1\" in --mountpoint) mode=mp; shift 2 ;; --target) path=\$2; shift 2 ;; *) shift ;; esac; done\n"
                . "if [ \"\$mode\" = mp ]; then [ -f \"\$FAKE/mount\" ] || exit 1; cat \"\$FAKE/mount\"; exit 0; fi\n"
                . "{$lookup} \"\$FAKE/targets\"\n",
            // stat -c FORMAT -- PATH: as many |-separated fields as FORMAT has.
            'stat' => "fmt=\$2; for path; do :; done\nline=\$({$lookup} \"\$FAKE/stat\") || exit 1\nn=\$(printf '%s' \"\$fmt\" | tr -cd '%' | wc -c)\nprintf '%s\\n' \"\$line\" | cut -d'|' -f1-\$n\n",
            // find DIR -mindepth 1 -maxdepth 1 -print -quit: the first entry, if any.
            'find' => "path=\$1\n{$lookup} \"\$FAKE/finds\" || true\n",
            'df' => "cat \"\$FAKE/df\"\n",
            // cmp -s A B: identical while the state file cmp-same exists.
            'cmp' => "[ -f \"\$FAKE/cmp-same\" ]\n",
        ];
        foreach ($fakes as $name => $body) {
            file_put_contents($this->dir . '/bin/' . $name, "#!/bin/sh\n" . $body);
            chmod($this->dir . '/bin/' . $name, 0700);
        }
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     * @return array{int, string}
     */
    private static function execute(array $command, array $env): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, $env);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        return [proc_close($process), $output];
    }

    /**
     * The unit as section => key => list of values (comments and blank lines
     * dropped; repeated keys keep their order).
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function unit(): array
    {
        $unit = [];
        $section = '';
        foreach (explode("\n", self::file(self::UNIT)) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^\[(\w+)\]$/', $line, $m) === 1) {
                $section = $m[1];
                $unit[$section] = [];
                continue;
            }
            self::assertNotSame('', $section, 'Key outside a section: ' . $line);
            [$key, $value] = explode('=', $line, 2);
            $unit[$section][$key][] = $value;
        }

        return $unit;
    }

    /**
     * The commands that create the directories and the secrets.
     *
     * @return array{install: list<string>, secrets: list<string>}
     */
    private static function procedure(string $source): array
    {
        preg_match_all('/^\s*(sudo install -d -o (?:"[^"]*"|\S+) -g (?:"[^"]*"|\S+) -m \S+ "\$(?:root|data|app).*)$/m', $source, $install);
        preg_match_all('/^\s*(sudo (?:sh -c "umask 077 && openssl rand -hex 32 > \'\$data\/secrets\/\$name\'"|chown \S+ "\$data\/secrets\/.*|chmod \S+ "\$data\/secrets\/.*))$/m', $source, $secrets);

        return ['install' => array_map('trim', $install[1]), 'secrets' => array_map('trim', $secrets[1])];
    }

    private static function mode(string $relative): string
    {
        $out = [];
        exec('git -C ' . escapeshellarg(self::path('')) . ' ls-files -s -- ' . escapeshellarg($relative) . ' 2>/dev/null', $out);
        if ($out !== []) {
            return explode(' ', $out[0])[0];
        }

        return (fileperms(self::path($relative)) & 0111) === 0111 ? '100755' : '100644';
    }

    private static function code(string $relative): string
    {
        return implode("\n", array_filter(explode("\n", self::file($relative)), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
    }

    private static function file(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
