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
            'reverse-proxy policy, required' => [$template('client-ip.reverse-proxy.conf.example', '192.0.2.0/29'), 'required', [], null],
            'proxy policy but none' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'none', [], 'trusts a proxy'],
            'trust everything (IPv4)' => [$template('client-ip.reverse-proxy.conf.example', '0.0.0.0/0'), 'required', [], 'trusts every address'],
            'trust everything (IPv6)' => [$template('client-ip.reverse-proxy.conf.example', '::/0'), 'required', [], 'trusts every address'],
            'placeholder only in a comment' => ["# was <PROXY_ADDRESS>\n" . $template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', [], null],
            'nginx -t fails' => [$template('client-ip.cloudflared.conf.example', '192.0.2.10'), 'required', ['FAKE_NGINX_RC' => '1'], 'nginx -t'],
        ];
        foreach ($cases as $name => [$content, $role, $env, $refusal]) {
            @unlink($policy);
            if ($content !== null) {
                file_put_contents($policy, $content);
            }
            [$status, $out, $err] = $this->exec(['sh', $script, 'nginx', '-g', 'daemon off;'], $env + ($role === null ? [] : ['DLA_TRUSTED_PROXY' => $role]));
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

    public function testMysqlInitReadsPasswordsFromSecretFiles(): void
    {
        $this->stub('mysql', 'cat > "$FAKE_SQL"; [ -n "${MYSQL_PWD:-}" ] && echo "root password passed" >> "$FAKE_SQL"; exit 0');
        $sql = $this->tmp . '/out.sql';
        file_put_contents($this->tmp . '/app', "app'secret\\pw\r\n");
        file_put_contents($this->tmp . '/mig', "migration-secret\n");
        $base = ['MYSQL_DATABASE' => 'library', 'DB_USERNAME' => 'library_app', 'DB_MIGRATION_USERNAME' => 'library_migrator', 'MYSQL_ROOT_PASSWORD' => 'root-pw', 'FAKE_SQL' => $sql];
        $script = dirname(__DIR__, 3) . '/docker/mysql/init/01-create-users.sh';

        [$status, , $err] = $this->exec(['bash', $script], $base + ['DB_PASSWORD_FILE' => $this->tmp . '/app', 'DB_MIGRATION_PASSWORD_FILE' => $this->tmp . '/mig']);
        self::assertSame(0, $status, $err);
        $out = (string) file_get_contents($sql);
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'library_app'@'%' IDENTIFIED BY 'app''secret\\\\pw';", $out, 'File contents, trailing CR/LF removed, SQL-escaped');
        self::assertStringContainsString("CREATE USER IF NOT EXISTS 'library_migrator'@'%' IDENTIFIED BY 'migration-secret';", $out);
        self::assertStringContainsString('root password passed', $out);
        self::assertStringNotContainsString($this->tmp, $out, 'Paths are not passwords');

        // Development: plain variables still work.
        unlink($sql);
        [$status] = $this->exec(['bash', $script], $base + ['DB_PASSWORD' => 'dev-app', 'DB_MIGRATION_PASSWORD' => 'dev-mig']);
        self::assertSame(0, $status);
        self::assertStringContainsString("IDENTIFIED BY 'dev-app';", (string) file_get_contents($sql));

        foreach ([
            'both forms' => ['DB_PASSWORD' => 'x', 'DB_PASSWORD_FILE' => $this->tmp . '/app', 'DB_MIGRATION_PASSWORD' => 'y'],
            'unreadable file' => ['DB_PASSWORD_FILE' => $this->tmp . '/missing', 'DB_MIGRATION_PASSWORD' => 'y'],
            'empty file' => ['DB_PASSWORD_FILE' => $this->emptyFile(), 'DB_MIGRATION_PASSWORD' => 'y'],
        ] as $name => $env) {
            @unlink($sql);
            [$status, , $err] = $this->exec(['bash', $script], $base + $env);
            self::assertNotSame(0, $status, $name);
            self::assertFileDoesNotExist($sql, $name . ': nothing reached MySQL');
            self::assertStringNotContainsString("app'secret", $err, $name);
        }
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
