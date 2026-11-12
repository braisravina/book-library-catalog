<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigValidator;
use DigitalLibrary\Core\DotEnv;
use DigitalLibrary\Tests\Support\BlockYaml;
use PHPUnit\Framework\TestCase;

/**
 * Static guarantees of the production stack (docker-compose.prod.yml, the
 * production Dockerfiles and nginx main configuration). Docker is not
 * needed: images are never built or run here. Runtime behaviour that only
 * Docker can show is listed in ARCHITECTURE.md ("Production deployment").
 */
final class ProductionComposeTest extends TestCase
{
    private const PINNED = '/^[a-z0-9.\/-]+:\d+\.\d+\.\d+[a-z0-9.-]*@sha256:[0-9a-f]{64}$/';

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
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    public function testServicesAndNoPublishedPorts(): void
    {
        self::assertSame(['nginx', 'php', 'ops', 'db'], array_keys(self::services()));
        foreach (self::services() as $name => $service) {
            foreach (['ports', 'network_mode', 'privileged', 'pid', 'ipc', 'cap_add', 'devices', 'extra_hosts'] as $forbidden) {
                self::assertArrayNotHasKey($forbidden, $service, "{$name}: {$forbidden}");
            }
            self::assertContains('no-new-privileges:true', $service['security_opt'] ?? [], $name);
            self::assertSame('json-file', $service['logging']['driver'] ?? null, $name);
            self::assertNotEmpty($service['logging']['options']['max-size'] ?? null, $name . ': bounded logs');
            self::assertNotEmpty($service['logging']['options']['max-file'] ?? null, $name . ': bounded logs');
            self::assertSame($name === 'ops' ? 'no' : 'unless-stopped', $service['restart'] ?? null, $name);
            if ($name !== 'ops') {
                self::assertSame('CMD', $service['healthcheck']['test'][0] ?? null, $name . ': health check without a shell');
            }
        }
        self::assertSame(['ops'], self::services()['ops']['profiles'], 'ops only runs on demand');
    }

    public function testApplicationContainersAreUnprivilegedAndReadOnly(): void
    {
        foreach (['nginx', 'php', 'ops'] as $name) {
            $service = self::services()[$name];
            self::assertSame('true', $service['read_only'] ?? null, $name);
            self::assertSame(['ALL'], $service['cap_drop'] ?? null, $name);
            self::assertMatchesRegularExpression('#^/tmp:size=\d+m,mode=1777$#', $service['tmpfs'][0] ?? '', $name);
            self::assertArrayNotHasKey('user', $service, $name . ': the image sets its unprivileged user');
        }
        $php = self::file('docker/php/Dockerfile');
        $prod = substr($php, (int) strpos($php, 'FROM base AS prod'));
        self::assertMatchesRegularExpression('/^USER www-data$/m', $prod);
        self::assertStringContainsString('ENTRYPOINT ["entrypoint-prod.sh"]', $prod);
        $nginx = self::file('docker/nginx/Dockerfile');
        self::assertMatchesRegularExpression('/^USER nginx$/m', $nginx);
        self::assertStringContainsString('ENTRYPOINT ["/usr/local/bin/dla-nginx-entrypoint"]', $nginx);
    }

    public function testImagesArePinnedByVersionAndDigest(): void
    {
        self::assertMatchesRegularExpression(self::PINNED, self::services()['db']['image']);
        foreach (['nginx' => 'digital-library-nginx:', 'php' => 'digital-library-php:', 'ops' => 'digital-library-php:'] as $name => $local) {
            self::assertStringStartsWith($local, self::services()[$name]['image'], $name . ': built locally from the pinned Dockerfiles');
        }
        preg_match_all('/^ARG [A-Z_]+_IMAGE=(\S+)$/m', self::file('docker/php/Dockerfile') . self::file('docker/nginx/Dockerfile'), $m);
        self::assertCount(3, $m[1], 'php, composer and nginx base images');
        foreach ($m[1] as $image) {
            self::assertMatchesRegularExpression(self::PINNED, $image);
        }
        self::assertStringContainsString('ARG PHP_IMAGE=php:8.4.', self::file('docker/php/Dockerfile'), 'PHP 8.4');
        self::assertStringStartsWith('mysql:8.4.', self::services()['db']['image'], 'MySQL 8.4');
        foreach (['FROM ${COMPOSER_IMAGE} AS composer', 'FROM ${PHP_IMAGE} AS base'] as $from) {
            self::assertStringContainsString($from, self::file('docker/php/Dockerfile'));
        }

        // Development uses the same pinned nginx and MySQL images.
        $dev = self::file('docker-compose.yml');
        preg_match('/^ARG NGINX_IMAGE=(\S+)$/m', self::file('docker/nginx/Dockerfile'), $nginx);
        self::assertStringContainsString('image: ' . $nginx[1], $dev);
        self::assertStringContainsString('image: ' . self::services()['db']['image'], $dev);
    }

    public function testNetworksArePrivateAndMinimal(): void
    {
        $networks = self::$compose['networks'];
        self::assertSame(['frontend', 'backend', 'egress'], array_keys($networks));
        self::assertSame('true', $networks['frontend']['internal']);
        self::assertSame('true', $networks['backend']['internal']);
        foreach ($networks as $name => $network) {
            self::assertArrayNotHasKey('external', $network ?? [], $name . ': owned by this project');
            self::assertArrayNotHasKey('attachable', $network ?? [], $name);
        }
        $membership = array_map(static fn (array $s): array => $s['networks'] ?? [], self::services());
        self::assertSame(['frontend'], $membership['nginx'], 'nginx: no ingress network until the topology is approved');
        self::assertSame(['frontend', 'backend', 'egress'], $membership['php']);
        self::assertSame(['backend'], $membership['ops']);
        self::assertSame(['backend'], $membership['db']);
    }

    public function testCredentialsAreSeparatedAndFileBased(): void
    {
        $services = self::services();
        self::assertSame(['db_password'], $services['php']['secrets']);
        self::assertSame(['db_password', 'db_migration_password'], $services['ops']['secrets']);
        self::assertSame(['db_root_password', 'db_password', 'db_migration_password'], $services['db']['secrets']);
        self::assertArrayNotHasKey('secrets', $services['nginx']);
        foreach (self::$compose['secrets'] as $name => $secret) {
            self::assertSame('./secrets/' . $name, $secret['file']);
        }

        self::assertSame('web', $services['php']['environment']['APP_ROLE']);
        self::assertSame('ops', $services['ops']['environment']['APP_ROLE']);
        foreach (['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD', 'DB_MIGRATION_PASSWORD_FILE', 'DB_ROOT_PASSWORD', 'DB_ROOT_PASSWORD_FILE'] as $key) {
            self::assertSame('', $services['php']['environment'][$key], "web container: {$key} explicitly cleared");
        }
        self::assertSame('localhost', $services['db']['environment']['MYSQL_ROOT_HOST'], 'root only inside the db container');
        foreach ($services as $name => $service) {
            self::assertNotContains('.env', (array) ($service['env_file'] ?? []), $name . ': never the development .env');
            foreach ($service['environment'] ?? [] as $key => $value) {
                if (preg_match('/PASSWORD|SECRET|_KEY$/', $key) === 1) {
                    self::assertTrue(str_ends_with($key, '_FILE') ? str_starts_with((string) $value, '/run/secrets/') || $value === '' : $value === '', "{$name}: {$key} must be a secret file");
                }
            }
        }
        self::assertStringContainsString("\n/secrets/\n", self::file('.gitignore'));
        self::assertMatchesRegularExpression('/^secrets$/m', self::file('.dockerignore'));
        self::assertMatchesRegularExpression('/^\.env\.\*$/m', self::file('.dockerignore'));
    }

    public function testComposeAndTemplateValidateCleanlyPerRole(): void
    {
        $template = DotEnv::parse(self::file('.env.production.example'));
        $this->dir = sys_get_temp_dir() . '/dla-prod-secrets-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        foreach (['php', 'ops'] as $name) {
            $service = self::services()[$name];
            // Compose: environment overrides env_file. Only mounted secrets exist.
            $values = $service['environment'] + $template;
            foreach ($values as $key => $value) {
                if (str_ends_with($key, '_FILE') && $value !== '') {
                    $secret = basename((string) $value);
                    $mounted = in_array($secret, $service['secrets'], true);
                    $values[$key] = $mounted ? $this->dir . '/' . $secret : $value;
                    if ($mounted) {
                        file_put_contents($this->dir . '/' . $secret, bin2hex(random_bytes(24)) . "\n");
                    }
                }
            }
            $report = (new ConfigValidator(dirname(__DIR__, 3)))->validate(new Config(array_map('strval', $values)));

            self::assertSame([], $report['errors'], $name . ': ' . json_encode($report['errors']));
            self::assertSame([], $report['warnings'], $name . ': ' . json_encode($report['warnings']));
        }
    }

    public function testIngressIsExplicitlyPendingAndFailsClosed(): void
    {
        $nginx = self::services()['nginx'];
        self::assertSame(['./docker/nginx/production:/etc/nginx/dla:ro'], $nginx['volumes']);
        self::assertSame('${DLA_TRUSTED_PROXY:-}', $nginx['environment']['DLA_TRUSTED_PROXY'], 'No default: unset means refuse to start');
        self::assertSame(['README.md'], array_values(array_diff(scandir(self::path('docker/nginx/production')) ?: [], ['.', '..'])), 'The production policy is added only after the topology is approved');
        $copies = implode("\n", preg_grep('/^COPY /', explode("\n", self::file('docker/nginx/Dockerfile'))) ?: []);
        self::assertStringNotContainsString('client-ip', $copies, 'No policy baked into the image');
        self::assertStringContainsString('# DLA_TRUSTED_PROXY=required', self::file('.env.production.example'), 'Documented, not set');
        self::assertDoesNotMatchRegularExpression('/^DLA_TRUSTED_PROXY=/m', self::file('.env.production.example'));
    }

    public function testNginxImageServesAssetsUnprivileged(): void
    {
        $dockerfile = self::file('docker/nginx/Dockerfile');
        self::assertStringContainsString('COPY public/assets /var/www/html/public/assets', $dockerfile);
        self::assertStringNotContainsString('COPY public ', $dockerfile, 'No PHP code in the nginx image');
        self::assertStringContainsString('COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf', $dockerfile);
        self::assertStringContainsString('EXPOSE 8080', $dockerfile);

        $main = self::directives('docker/nginx/nginx.prod.conf');
        self::assertDoesNotMatchRegularExpression('/^\s*user\s/m', $main, 'No user switch: the whole server runs as nginx');
        self::assertMatchesRegularExpression('#^\s*pid /tmp/\S+;$#m', $main);
        foreach (['client_body', 'proxy', 'fastcgi', 'uwsgi', 'scgi'] as $kind) {
            self::assertMatchesRegularExpression("#^\\s*{$kind}_temp_path /tmp/\\S+;$#m", $main, $kind);
        }
        self::assertStringContainsString('include /etc/nginx/conf.d/*.conf;', $main);
        self::assertMatchesRegularExpression('/^\s*listen 8080 default_server;$/m', self::file('docker/nginx/default.conf'), 'Unprivileged port');
        self::assertStringContainsString(':${APP_PORT:-8080}:8080"', self::file('docker-compose.yml'));
        self::assertStringContainsString('http://127.0.0.1:8080/health', implode(' ', self::services()['nginx']['healthcheck']['test']));
    }

    public function testDatabaseHealthCheckNeedsNoCredentials(): void
    {
        $test = self::services()['db']['healthcheck']['test'];
        self::assertSame(['CMD', 'mysqladmin', 'ping', '-h', '127.0.0.1', '--silent'], $test);
        self::assertSame(['db-data:/var/lib/mysql', './docker/mysql/init:/docker-entrypoint-initdb.d:ro', './docker/mysql/my.cnf:/etc/mysql/conf.d/app.cnf:ro'], self::services()['db']['volumes']);
        self::assertSame(['app-storage:/var/www/html/storage'], self::services()['php']['volumes'], 'The web container cannot read backups');
        self::assertSame(['app-storage:/var/www/html/storage', 'app-backups:/var/www/html/storage/backups'], self::services()['ops']['volumes']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function services(): array
    {
        return self::$compose['services'];
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }

    private static function file(string $relative): string
    {
        return (string) file_get_contents(self::path($relative));
    }

    private static function directives(string $relative): string
    {
        return implode("\n", array_filter(explode("\n", self::file($relative)), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
    }
}
