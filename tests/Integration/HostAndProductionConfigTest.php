<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Application;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Phase 5A: Host header validation against APP_URL, forwarded-header
 * spoofing, production fail-closed configuration and `config:check`.
 * The test environment's APP_URL is http://library.test.
 */
final class HostAndProductionConfigTest extends IntegrationTestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function hosts(): iterable
    {
        yield 'configured host' => ['library.test', 200];
        yield 'upper case' => ['LIBRARY.Test', 200];
        yield 'explicit default port' => ['library.test:80', 200];
        yield 'foreign host' => ['evil.example', 400];
        yield 'look-alike suffix' => ['library.test.evil.example', 400];
        yield 'other port' => ['library.test:8080', 400];
        yield 'https port' => ['library.test:443', 400];
        yield 'trailing dot' => ['library.test.', 400];
        yield 'empty' => ['', 400];
        yield 'user info' => ['evil.example@library.test', 400];
        yield 'two hosts' => ['library.test,evil.example', 400];
        yield 'header injection' => ["library.test\r\nSet-Cookie: x=1", 400];
        yield 'IP address' => ['192.0.2.1', 400];
    }

    #[DataProvider('hosts')]
    public function testOnlyTheConfiguredHostIsServed(string $host, int $status): void
    {
        $browser = $this->browser();
        $response = $this->request($browser, 'GET', '/login', [], ['HTTP_HOST' => $host]);

        self::assertSame($status, $response->status(), var_export($host, true));
        if ($status === 400) {
            self::assertNotNull($response->header('Content-Security-Policy'), 'Security headers on the refusal');
            self::assertNull($response->header('Location'));
            self::assertStringNotContainsString('evil.example', $response->body(), 'The Host value is not reflected');
            self::assertStringNotContainsString('name="_csrf"', $response->body(), 'Refused before the session/CSRF stack');
        }
    }

    public function testForwardedHostHeadersAreIgnored(): void
    {
        $spoof = ['HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '8443', 'HTTP_FORWARDED' => 'host=evil.example;proto=https', 'HTTP_X_ORIGINAL_HOST' => 'evil.example'];

        // A forged forwarding header cannot make a foreign Host acceptable...
        self::assertSame(400, $this->request($this->browser(), 'GET', '/login', [], ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'library.test'])->status());

        // ...nor leak into redirects, pages or absolute links when the Host is valid.
        $redirect = $this->request($this->browser(), 'GET', '/books', [], $spoof);
        self::assertSame(303, $redirect->status());
        self::assertSame('/login', $redirect->header('Location'), 'Redirects are relative, never built from headers');
        $page = $this->request($this->browser(), 'GET', '/login', [], $spoof);
        self::assertStringNotContainsString('evil.example', $page->body() . json_encode($page->headers()));

        $this->createUser('admin@example.org', Role::Admin);
        [$admin, $token] = $this->signedIn('admin@example.org');
        $issued = $this->request($admin, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => 'reader@example.org', 'role' => 'viewer', 'expires_on' => gmdate('Y-m-d', strtotime('+7 days'))], $spoof);
        self::assertSame(200, $issued->status());
        self::assertMatchesRegularExpression('#value="http://library\.test/invite\?token=[A-Za-z0-9_-]{43}"#', $issued->body(), 'Invitation links come from APP_URL only');
        self::assertStringNotContainsString('evil.example', $issued->body());
    }

    public function testHealthCheckIsReachableByContainerProbes(): void
    {
        $health = $this->request($this->browser(), 'GET', '/health', [], ['HTTP_HOST' => 'localhost']);

        self::assertSame(200, $health->status());
        self::assertSame(['status' => 'ok'], json_decode($health->body(), true));
    }

    public function testMisconfiguredProductionRefusesToServe(): void
    {
        $config = $this->productionConfig()->with('SESSION_SECURE_COOKIE', 'false');
        (new \ReflectionProperty(Application::class, 'productionErrors'))->setValue(null, []);
        $log = tempnam(sys_get_temp_dir(), 'dla-log-');
        $previous = ini_set('error_log', $log);
        try {
            $page = $this->productionRequest($config, 'GET', '/login');
            $health = $this->productionRequest($config, 'GET', '/health');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);

        self::assertSame(503, $page->status());
        self::assertStringNotContainsString('name="password"', $page->body(), 'No login form');
        self::assertSame(503, $health->status());
        self::assertSame(['status' => 'unavailable'], json_decode($health->body(), true), 'No configuration details on the probe');
        // Validated and logged once per process and configuration, with keys only.
        self::assertSame(1, substr_count($logged, 'invalid production configuration'));
        self::assertStringContainsString('SESSION_SECURE_COOKIE', $logged);
        self::assertStringNotContainsString((string) $config->get('DB_PASSWORD'), $logged);

        // The cached result belongs to that configuration only: a corrected
        // configuration is validated afresh and served.
        self::assertSame(200, $this->productionRequest($this->productionConfig(), 'GET', '/login')->status());
        self::assertSame(503, $this->productionRequest($config, 'GET', '/login')->status(), 'Still refused: the cache never turns an error into success');
    }

    public function testValidProductionConfigurationServesWithHstsAndSecureCookies(): void
    {
        $config = $this->productionConfig();
        $login = $this->productionRequest($config, 'GET', '/login');

        self::assertSame(200, $login->status());
        self::assertSame('max-age=31536000; includeSubDomains', $login->header('Strict-Transport-Security'));
        self::assertSame(200, $this->productionRequest($config, 'GET', '/health')->status());
        self::assertSame(400, $this->productionRequest($config, 'GET', '/login', ['HTTP_HOST' => 'library.test:80'])->status(), 'https site: port 80 is a different origin');
    }

    public function testConfigCheckCommandReportsWithoutSecrets(): void
    {
        [$code, $out] = $this->console($this->productionConfig(), ['console', 'config:check']);
        self::assertSame(0, $code, $out);
        self::assertStringContainsString('production rules', $out);
        self::assertStringContainsString('Result: 0 error(s)', $out);

        $bad = $this->productionConfig()
            ->with('APP_URL', 'http://library.test')
            ->with('DB_PASSWORD', 'CHANGE_ME_' . bin2hex(random_bytes(6)))
            ->with('INVITATION_LINK_FORMAT', 'path');
        [$code, $out] = $this->console($bad, ['console', 'config:check']);
        self::assertSame(1, $code);
        foreach (['ERROR    APP_URL', 'ERROR    DB_PASSWORD', 'ERROR    INVITATION_LINK_FORMAT', 'INVALID'] as $expected) {
            self::assertStringContainsString($expected, $out);
        }
        self::assertStringNotContainsString((string) $bad->get('DB_PASSWORD'), $out);
        self::assertStringNotContainsString((string) $bad->get('DB_MIGRATION_PASSWORD'), $out);

        // Warnings alone pass, unless --strict.
        $warn = $this->productionConfig()->with('INVITATION_LINK_FORMAT', 'query');
        self::assertSame(0, $this->console($warn, ['console', 'config:check'])[0]);
        self::assertSame(1, $this->console($warn, ['console', 'config:check', '--strict'])[0]);

        // Development rules: plain HTTP is fine.
        [$code, $out] = $this->console(self::config()->with('APP_ENV', 'development')->with('SESSION_SECURE_COOKIE', 'false')->with('SECURITY_HSTS', 'false'), ['console', 'config:check']);
        self::assertStringContainsString('development rules', $out);
        self::assertStringNotContainsString('ERROR    APP_URL', $out);
    }

    /**
     * The test configuration turned into a valid production configuration.
     */
    private function productionConfig(): Config
    {
        return self::config()
            ->with('APP_ENV', 'production')
            ->with('APP_URL', 'https://library.test')
            ->with('SESSION_SECURE_COOKIE', 'true')
            ->with('SECURITY_HSTS', 'true')
            ->with('INVITATION_LINK_FORMAT', 'fragment')
            ->with('ARGON2_MEMORY_KIB', '19456')
            ->with('ARGON2_TIME_COST', '2')
            ->with('DB_ROOT_PASSWORD', bin2hex(random_bytes(16)));
    }

    /**
     * @param array<string, string> $server
     */
    private function productionRequest(Config $config, string $method, string $path, array $server = []): Response
    {
        $services = new Services($config, dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $services->set(Session::class, Session::inMemory());
        $services->set(HttpClient::class, self::$http);

        return (new Application($services))->handle(new Request($method, $path, [], [], $server + ['REMOTE_ADDR' => '192.0.2.10', 'HTTP_HOST' => 'library.test']));
    }

    /**
     * @param list<string> $argv
     *
     * @return array{int, string}
     */
    private function console(Config $config, array $argv): array
    {
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $code = (new Console(new Services($config, dirname(__DIR__, 2)), null, $out, $err))->run($argv);
        rewind($out);
        rewind($err);

        return [$code, stream_get_contents($out) . stream_get_contents($err)];
    }
}
