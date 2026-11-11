<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use PHPUnit\Framework\TestCase;

/**
 * Static guarantees of the shipped Nginx/Compose files (behaviour is tested
 * against a real Nginx by tests/nginx/run.php).
 */
final class ClientIpPolicyFilesTest extends TestCase
{
    private const FORWARDING_PARAMS = [
        'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_PORT',
        'HTTP_X_FORWARDED_SERVER', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP', 'HTTP_X_CLIENT_IP', 'HTTP_X_ORIGINAL_HOST',
        'HTTP_X_ORIGINAL_URL', 'HTTP_X_REWRITE_URL', 'HTTP_CF_CONNECTING_IP', 'HTTP_CF_CONNECTING_IPV6', 'HTTP_TRUE_CLIENT_IP',
    ];

    public function testShippedPolicyTrustsNoProxy(): void
    {
        $policy = self::directives('docker/nginx/client-ip.conf');

        self::assertDoesNotMatchRegularExpression('/^\s*(set_real_ip_from|real_ip_header|real_ip_recursive)\b/m', $policy);
        self::assertMatchesRegularExpression('/map \$uri \$dla_require_trusted_proxy \{\s*default 0;\s*\}/', $policy, 'Direct requests cannot be required to come through a proxy that is not trusted');
    }

    public function testTemplatesNeedOneExplicitAddress(): void
    {
        foreach (['cloudflared' => ['CLOUDFLARED_ADDRESS', 'CF-Connecting-IP', 'off'], 'reverse-proxy' => ['PROXY_ADDRESS', 'X-Forwarded-For', 'on']] as $name => [$placeholder, $header, $recursive]) {
            $policy = self::directives("docker/nginx/client-ip.{$name}.conf.example");

            self::assertSame(1, preg_match_all('/^\s*set_real_ip_from\s+(\S+);/m', $policy, $m), $name);
            self::assertSame("<{$placeholder}>", $m[1][0], $name . ': no address is assumed');
            self::assertStringContainsString("real_ip_header {$header};", $policy);
            self::assertStringContainsString("real_ip_recursive {$recursive};", $policy);
            self::assertDoesNotMatchRegularExpression('#0\.0\.0\.0/0|::/0#', $policy);
            self::assertMatchesRegularExpression('/\$dla_require_trusted_proxy \{\s*default 1;/', $policy, $name . ': direct-origin protection on');
        }
    }

    public function testForwardingHeadersNeverReachPhp(): void
    {
        $site = self::directives('docker/nginx/default.conf');

        self::assertStringContainsString('include /etc/nginx/dla/client-ip.conf;', $site);
        foreach (self::FORWARDING_PARAMS as $param) {
            self::assertMatchesRegularExpression('/^\s*fastcgi_param ' . $param . ' "";$/m', $site, $param);
        }
        self::assertMatchesRegularExpression('/^\s*fastcgi_param HTTP_HOST \$http_host;$/m', $site, 'Raw Host for the application check');
        self::assertMatchesRegularExpression('/^\s*fastcgi_param HTTP_PROXY "";$/m', $site);
        self::assertStringContainsString('peer=$realip_remote_addr', $site);
        self::assertDoesNotMatchRegularExpression('/^\s*underscores_in_headers\s+on/m', $site);
        self::assertDoesNotMatchRegularExpression('/^\s*(set_real_ip_from|real_ip_header)\b/m', $site, 'Trust is configured only in client-ip.conf');
    }

    public function testOnlyNginxIsPublishedAndOnlyOnLoopback(): void
    {
        $compose = (string) file_get_contents(dirname(__DIR__, 3) . '/docker-compose.yml');
        $published = [];
        $service = null;
        $inPorts = false;
        foreach (explode("\n", $compose) as $line) {
            if (preg_match('/^  ([a-z0-9_-]+):\s*$/', $line, $m)) {
                $service = $m[1];
                $inPorts = false;
            } elseif (preg_match('/^    ports:\s*$/', $line)) {
                $inPorts = true;
            } elseif ($inPorts && preg_match('/^      - "?([^"#]+)"?/', $line, $m)) {
                $published[$service][] = trim($m[1]);
            } elseif ($inPorts && preg_match('/^    \S/', $line)) {
                $inPorts = false;
            }
        }

        self::assertSame(['nginx'], array_keys($published), 'PHP-FPM and MySQL are never published');
        foreach ($published['nginx'] as $mapping) {
            self::assertStringStartsWith('127.0.0.1:', $mapping, 'Loopback only');
        }
        self::assertStringContainsString('./docker/nginx/client-ip.conf:/etc/nginx/dla/client-ip.conf:ro', $compose);
    }

    /**
     * File contents without comment lines.
     */
    private static function directives(string $path): string
    {
        $lines = explode("\n", (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path));

        return implode("\n", array_filter($lines, static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
    }
}
