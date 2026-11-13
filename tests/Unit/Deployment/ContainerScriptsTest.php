<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * Behaviour of the production container entrypoints and the MySQL init
 * script, run with sh/bash and stub binaries (no Docker, no MySQL).
 */
final class ContainerScriptsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/dla-scripts-' . bin2hex(random_bytes(6));
        mkdir($this->tmp . '/bin', 0700, true);
    }

    protected function tearDown(): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmp);
    }

    public function testNginxRefusesToStartWithoutAnExplicitValidPolicy(): void
    {
        $policy = $this->tmp . '/client-ip.conf';
        $script = $this->patched('docker/nginx/entrypoint-prod.sh', [
            'policy=/etc/nginx/dla/client-ip.conf' => 'policy=' . $policy,
            'exec /docker-entrypoint.sh "$@"' => 'exec ' . $this->tmp . '/bin/next "$@"',
        ]);
        $this->stub('nginx', 'exit "${FAKE_NGINX_RC:-0}"');
        $this->stub('next', 'echo "started $*"');
        $template = static fn (string $name, string $address): string => str_replace(['<CLOUDFLARED_ADDRESS>', '<PROXY_ADDRESS>'], $address, (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/' . $name));
        $shipped = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/client-ip.conf');
        $placeholder = (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/client-ip.cloudflared.conf.example');

        $cases = [
            'no policy file' => [null, 'required', [], 'no readable client IP policy'],
            'template placeholder' => [$placeholder, 'required', [], 'placeholder'],
            'trust-nothing, role unset' => [$shipped, null, [], 'set DLA_TRUSTED_PROXY'],
            'trust-nothing, unknown role' => [$shipped, 'yes', [], 'set DLA_TRUSTED_PROXY'],
            'trust-nothing but required' => [$shipped, 'required', [], 'trusts no proxy'],
            'trust-nothing, explicitly none' => [$shipped, 'none', [], null],
            'cloudflared policy, required' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', [], null],
            'reverse-proxy policy, required' => [$template('client-ip.reverse-proxy.conf.example', '192.0.2.10'), 'required', [], null],
            'a range, required' => [$template('client-ip.reverse-proxy.conf.example', '192.0.2.0/29'), 'required', [], 'exactly DLA_EDGE_GATEWAY'],
            'proxy policy but none' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'none', [], 'trusts a proxy'],
            'trust everything (IPv4)' => [$template('client-ip.reverse-proxy.conf.example', '0.0.0.0/0'), 'required', [], 'trusts every address'],
            'trust everything (IPv6)' => [$template('client-ip.reverse-proxy.conf.example', '::/0'), 'required', [], 'trusts every address'],
            'placeholder only in a comment' => ["# was <PROXY_ADDRESS>\n" . $template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', [], null],
            'trusted address is not the gateway' => [$template('client-ip.cloudflared.conf.example', '192.0.2.11'), 'required', [], 'exactly DLA_EDGE_GATEWAY'],
            'gateway plus another address' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10') . "set_real_ip_from 192.0.2.99;\n", 'required', [], 'exactly DLA_EDGE_GATEWAY'],
            'required without a gateway' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', ['DLA_EDGE_GATEWAY' => ''], 'needs DLA_EDGE_GATEWAY'],
            'nginx -t fails' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', ['FAKE_NGINX_RC' => '1'], 'nginx -t'],
        ];
        foreach ($cases as $name => [$content, $role, $env, $refusal]) {
            @unlink($policy);
            if ($content !== null) {
                file_put_contents($policy, $content);
            }
            [$status, $out, $err] = $this->exec(['sh', $script, 'nginx', '-g', 'daemon off;'], $env + ['DLA_HTTP_BIND' => '127.0.0.1', 'DLA_EDGE_GATEWAY' => '192.0.2.10'] + ($role === null ? [] : ['DLA_TRUSTED_PROXY' => $role]));
            if ($refusal === null) {
                self::assertSame(0, $status, "{$name}: {$err}");
                self::assertSame("started nginx -g daemon off;\n", $out, $name);
            } else {
                self::assertNotSame(0, $status, $name);
                self::assertStringNotContainsString('started', $out, $name);
                self::assertStringContainsString('refusing to start', $err, $name);
                self::assertStringContainsString($refusal, $err, $name);
            }
        }
    }

    public function testNginxRefusesAnyBindAddressButLoopback(): void
    {
        $policy = $this->tmp . '/client-ip.conf';
        file_put_contents($policy, (string) file_get_contents(dirname(__DIR__, 3) . '/docker/nginx/client-ip.conf'));
        $script = $this->patched('docker/nginx/entrypoint-prod.sh', [
            'policy=/etc/nginx/dla/client-ip.conf' => 'policy=' . $policy,
            'exec /docker-entrypoint.sh "$@"' => 'exec ' . $this->tmp . '/bin/next "$@"',
        ]);
        $this->stub('nginx', 'exit 0');
        $this->stub('next', 'echo "started $*"');

        $cases = [
            'unset' => [null, 'DLA_HTTP_BIND is not set'],
            'empty' => ['', 'DLA_HTTP_BIND is not set'],
            'IPv4 wildcard' => ['0.0.0.0', 'not a loopback address'],
            'IPv6 wildcard' => ['::', 'not a loopback address'],
            'IPv6 wildcard, bracketed' => ['[::]', 'not a loopback address'],
            'asterisk' => ['*', 'not a loopback address'],
            'LAN address' => ['192.168.1.50', 'not a loopback address'],
            'host name' => ['localhost', 'not a loopback address'],
            'two lines' => ["127.0.0.1\n0.0.0.0", 'not a loopback address'],
            'trailing space' => ['127.0.0.1 ', 'not a loopback address'],
            'lookalike' => ['127.0.0.1.5', 'not a loopback address'],
            'loopback' => ['127.0.0.1', null],
            'IPv6 loopback' => ['::1', null],
        ];
        foreach ($cases as $name => [$bind, $refusal]) {
            $env = ['DLA_TRUSTED_PROXY' => 'none'] + ($bind === null ? [] : ['DLA_HTTP_BIND' => $bind]);
            [$status, $out, $err] = $this->exec(['sh', $script, 'nginx'], $env);
            if ($refusal === null) {
                self::assertSame(0, $status, "{$name}: {$err}");
                self::assertSame("started nginx\n", $out, $name);
            } else {
                self::assertNotSame(0, $status, $name);
                self::assertStringNotContainsString('started', $out, $name);
                self::assertStringContainsString($refusal, $err, $name);
            }
        }
    }

    public function testPhpValidatesConfigurationBeforeStartingFpmOnly(): void
    {
        $this->stub('php', 'echo "php $*"; exit "${FAKE_PHP_RC:-0}"');
        $this->stub('docker-php-entrypoint', 'echo "started $*"');
        $script = dirname(__DIR__, 3) . '/docker/php/entrypoint-prod.sh';

        [$status, $out] = $this->exec(['sh', $script, 'php-fpm'], []);
        self::assertSame(0, $status);
        self::assertSame("php /var/www/html/bin/console config:check\nstarted php-fpm\n", $out);

        [$status, $out, $err] = $this->exec(['sh', $script, 'php-fpm'], ['FAKE_PHP_RC' => '1']);
        self::assertSame(1, $status);
        self::assertStringNotContainsString('started', $out);
        self::assertStringContainsString('refusing to start PHP-FPM', $err);

        [$status, $out] = $this->exec(['sh', $script, 'php', 'bin/console', 'migrate'], ['FAKE_PHP_RC' => '1']);
        self::assertSame(0, $status, 'One-off commands are not gated');
        self::assertSame("started php bin/console migrate\n", $out);
    }

    public function testDatabaseInitReadsPasswordsFromSecretFilesAndMarksCompletion(): void
    {
        // MariaDB images ship only the "mariadb" client; it is preferred over "mysql".
        $this->stub('mariadb', 'cat > "$FAKE_SQL"; [ -n "${MYSQL_PWD:-}" ] && echo "root password passed: $MYSQL_PWD" >> "$FAKE_SQL"; exit "${FAKE_CLIENT_RC:-0}"');
        $this->stub('mysql', 'echo "mysql client used" >> "$FAKE_SQL"; exit 1');
        $sql = $this->tmp . '/out.sql';
        $datadir = $this->tmp . '/datadir';
        mkdir($datadir, 0700);
        $marker = $datadir . '/.dla-init-complete';
        file_put_contents($this->tmp . '/app', "app'secret\\pw\r\n");
        file_put_contents($this->tmp . '/mig', "migration-secret\n");
        $base = ['MYSQL_DATABASE' => 'library', 'DB_USERNAME' => 'library_app', 'DB_MIGRATION_USERNAME' => 'library_migrator', 'MARIADB_ROOT_PASSWORD' => 'root-pw', 'FAKE_SQL' => $sql, 'DLA_DB_DATADIR' => $datadir];
        $script = dirname(__DIR__, 3) . '/docker/mysql/init/01-create-users.sh';

        [$status, , $err] = $this->exec(['bash', $script], $base + ['DB_PASSWORD_FILE' => $this->tmp . '/app', 'DB_MIGRATION_PASSWORD_FILE' => $this->tmp . '/mig']);
        self::assertSame(0, $status, $err);
        $out = (string) file_get_contents($sql);
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'library_app'@'%' IDENTIFIED BY 'app''secret\\\\pw';", $out, 'File contents, trailing CR/LF removed, SQL-escaped');
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'library_migrator'@'%' IDENTIFIED BY 'migration-secret';", $out);
        self::assertStringContainsString("GRANT SELECT, INSERT, UPDATE, DELETE ON `library`.* TO 'library_app'@'%';", $out, 'Application account: DML only');
        self::assertStringContainsString('root password passed: root-pw', $out);
        self::assertStringNotContainsString('mysql client used', $out);
        self::assertStringNotContainsString($this->tmp, $out, 'Paths are not passwords');
        self::assertFileExists($marker, 'Completion marker written after every grant');
        self::assertSame('0600', substr(sprintf('%o', fileperms($marker)), -4));

        // Development: plain variables still work; MYSQL_ROOT_PASSWORD is the fallback name.
        unlink($sql);
        unlink($marker);
        $dev = array_diff_key($base, ['MARIADB_ROOT_PASSWORD' => true]) + ['MYSQL_ROOT_PASSWORD' => 'mysql-root', 'DB_PASSWORD' => 'dev-app', 'DB_MIGRATION_PASSWORD' => 'dev-mig'];
        [$status] = $this->exec(['bash', $script], $dev);
        self::assertSame(0, $status);
        self::assertStringContainsString("IDENTIFIED BY 'dev-app';", (string) file_get_contents($sql));
        self::assertStringContainsString('root password passed: mysql-root', (string) file_get_contents($sql));
        self::assertFileExists($marker);

        foreach ([
            'both forms' => ['DB_PASSWORD' => 'x', 'DB_PASSWORD_FILE' => $this->tmp . '/app', 'DB_MIGRATION_PASSWORD' => 'y'],
            'unreadable file' => ['DB_PASSWORD_FILE' => $this->tmp . '/missing', 'DB_MIGRATION_PASSWORD' => 'y'],
            'empty file' => ['DB_PASSWORD_FILE' => $this->emptyFile(), 'DB_MIGRATION_PASSWORD' => 'y'],
            'no root password' => ['DB_PASSWORD' => 'x', 'DB_MIGRATION_PASSWORD' => 'y', 'MARIADB_ROOT_PASSWORD' => ''],
        ] as $name => $env) {
            @unlink($sql);
            @unlink($marker);
            [$status, , $err] = $this->exec(['bash', $script], $env + $base);
            self::assertNotSame(0, $status, $name);
            self::assertFileDoesNotExist($sql, $name . ': nothing reached the database');
            self::assertFileDoesNotExist($marker, $name . ': no completion marker');
            self::assertStringNotContainsString("app'secret", $err, $name);
        }

        // The client fails part-way: no completion marker, so db-guard.sh refuses the directory.
        @unlink($marker);
        [$status] = $this->exec(['bash', $script], $base + ['DB_PASSWORD' => 'x', 'DB_MIGRATION_PASSWORD' => 'y', 'FAKE_CLIENT_RC' => '1']);
        self::assertNotSame(0, $status);
        self::assertFileDoesNotExist($marker, 'client failure: no completion marker');
    }

    private function emptyFile(): string
    {
        file_put_contents($this->tmp . '/empty', "\n");

        return $this->tmp . '/empty';
    }

    /**
     * @param array<string, string> $replacements exact, single occurrences
     */
    private function patched(string $relative, array $replacements): string
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
        foreach ($replacements as $from => $to) {
            self::assertSame(1, substr_count($source, $from), "{$relative}: expected one '{$from}'");
            $source = str_replace($from, $to, $source);
        }
        $path = $this->tmp . '/' . basename($relative);
        file_put_contents($path, $source);

        return $path;
    }

    private function stub(string $name, string $body): void
    {
        file_put_contents($this->tmp . '/bin/' . $name, "#!/bin/sh\n{$body}\n");
        chmod($this->tmp . '/bin/' . $name, 0700);
    }

    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{int, string, string}
     */
    private function exec(array $command, array $env): array
    {
        $env += ['PATH' => $this->tmp . '/bin:/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C'];
        $process = proc_open($command, [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, $this->tmp, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
