<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Deployment;

use DigitalLibrary\Tests\Support\BlockYaml;
use PHPUnit\Framework\TestCase;

/**
 * Static guarantees of .github/workflows/ci.yml (the workflow itself can
 * only run on GitHub): least privilege, pinned actions and images, no
 * script injection, shell blocks that parse, and coverage of every check
 * the project relies on.
 */
final class CiWorkflowTest extends TestCase
{
    private const FILE = '.github/workflows/ci.yml';

    /** @var array<string, mixed> */
    private static array $workflow = [];
    private static string $source = '';

    public static function setUpBeforeClass(): void
    {
        self::$source = (string) file_get_contents(self::path(self::FILE));
        self::$workflow = BlockYaml::parseFile(self::path(self::FILE));
    }

    public function testLeastPrivilegeAndSafeTriggers(): void
    {
        self::assertSame(['contents' => 'read'], self::$workflow['permissions']);
        self::assertSame(['push', 'pull_request', 'schedule', 'workflow_dispatch'], array_keys(self::$workflow['on']));
        $code = implode("\n", array_filter(explode("\n", self::$source), static fn (string $l): bool => !str_starts_with(ltrim($l), '#')));
        self::assertStringNotContainsString('pull_request_target', $code);
        self::assertStringNotContainsString('workflow_run', $code);
        self::assertDoesNotMatchRegularExpression('/\$\{\{\s*secrets\./', self::$source, 'No repository secrets are needed');
        self::assertSame(['test', 'unit-php83', 'static', 'production-stack'], array_keys(self::jobs()));
        foreach (self::jobs() as $name => $job) {
            self::assertArrayNotHasKey('permissions', $job, $name . ': no job widens the token');
            self::assertSame('ubuntu-24.04', $job['runs-on'], $name . ': pinned runner image');
            self::assertMatchesRegularExpression('/^\d+$/', $job['timeout-minutes'] ?? '', $name . ': bounded run time');
        }
    }

    public function testActionsAndImagesArePinned(): void
    {
        preg_match_all('/^\s*(?:-\s+)?uses:\s*(\S+)(.*)$/m', self::$source, $uses, PREG_SET_ORDER);
        self::assertNotEmpty($uses);
        foreach ($uses as [, $action, $comment]) {
            self::assertMatchesRegularExpression('#^[a-z0-9-]+/[a-z0-9-]+@[0-9a-f]{40}$#', $action, 'Full commit SHA: ' . $action);
            self::assertMatchesRegularExpression('/^\s*#\s*v?\d+\.\d+\.\d+$/', $comment, 'Version noted for ' . $action);
        }
        foreach (self::steps() as [$job, $step]) {
            if (str_starts_with($step['uses'] ?? '', 'actions/checkout@')) {
                self::assertSame('false', $step['with']['persist-credentials'] ?? null, $job . ': the token is not left in .git/config');
            }
        }
        $compose = BlockYaml::parseFile(self::path('docker-compose.prod.yml'));
        self::assertSame($compose['services']['db']['image'], self::jobs()['test']['services']['mysql']['image'], 'CI tests against the production MySQL image');
    }

    public function testShellBlocksParseAndNeverRelyOnNegatedCommands(): void
    {
        foreach (self::steps() as [$job, $step]) {
            if (!isset($step['run'])) {
                continue;
            }
            $script = $step['run'];
            $name = $job . ' / ' . ($step['name'] ?? '?');
            self::assertDoesNotMatchRegularExpression('/\$\{\{\s*(github\.(event|head_ref)|inputs\.)/', $script, $name . ': no untrusted expression in a shell script');
            self::assertDoesNotMatchRegularExpression('/^\s*!\s/m', $script, $name . ': "! cmd" never fails a set -e script; use refuse/if');
            $file = tempnam(sys_get_temp_dir(), 'dla-ci-');
            file_put_contents($file, "set -eo pipefail\n" . $script);
            exec('bash -n ' . escapeshellarg($file) . ' 2>&1', $output, $status);
            unlink($file);
            self::assertSame(0, $status, $name . ': ' . implode("\n", $output));
        }
    }

    public function testEveryProjectCheckRuns(): void
    {
        $runs = implode("\n", array_map(static fn (array $s): string => $s[1]['run'] ?? '', self::steps()));
        foreach ([
            'composer validate --strict', 'composer audit', 'composer lint',
            'vendor/bin/phpunit --fail-on-skipped --fail-on-warning',
            'vendor/bin/phpunit --testsuite unit --fail-on-skipped --fail-on-warning',
            'php tests/nginx/run.php',
            'docker build --check -f docker/php/Dockerfile .', 'docker build --check -f docker/nginx/Dockerfile .',
            'docker compose config --quiet', 'docker compose -f docker-compose.prod.yml --env-file .env.production config --quiet',
            'bin/console backup:restore', 'bin/console backup:verify', 'up -d --wait',
        ] as $command) {
            self::assertStringContainsString($command, $runs);
        }
        $versions = [];
        foreach (self::steps() as [$job, $step]) {
            if (isset($step['with']['php-version'])) {
                $versions[$job] = $step['with']['php-version'];
            }
        }
        self::assertSame(['test' => '8.4', 'unit-php83' => '8.3'], $versions, 'PHP 8.4 runtime and 8.3 tooling compatibility (CLAUDE.md)');

        // Every shell script in the repository is checked by shellcheck.
        $scripts = array_merge(glob(self::path('docker/*/*.sh')) ?: [], glob(self::path('docker/*/*/*.sh')) ?: []);
        self::assertNotEmpty($scripts);
        foreach ($scripts as $script) {
            $relative = substr($script, strlen(self::path('')));
            self::assertMatchesRegularExpression('#shellcheck [^\n]*' . preg_quote($relative, '#') . '#', $runs, $relative . ' is not shellchecked');
        }
        // Every Dockerfile is linted.
        foreach (glob(self::path('docker/*/Dockerfile')) ?: [] as $dockerfile) {
            self::assertStringContainsString('docker build --check -f ' . substr($dockerfile, strlen(self::path(''))) . ' .', $runs);
        }
    }

    public function testProductionStackJobKeepsTheIngressDecisionOpen(): void
    {
        $runs = implode("\n", array_map(static fn (array $s): string => $s[1]['run'] ?? '', array_filter(self::steps(), static fn (array $s): bool => $s[0] === 'production-stack')));
        self::assertStringContainsString("echo 'DLA_TRUSTED_PROXY=none' >> .env.production", $runs, 'CI-only, explicit: no trusted proxy');
        self::assertStringContainsString('cp docker/nginx/client-ip.conf docker/nginx/production/client-ip.conf', $runs, 'The trust-nothing policy, never a guessed address');
        self::assertStringNotContainsString('set_real_ip_from', self::$source);
        self::assertStringContainsString("echo 'DLA_EDGE_GATEWAY=172.30.89.1' >> .env.production", $runs, 'CI-only edge subnet');
        self::assertStringNotContainsString("DLA_HTTP_BIND=", implode("\n", array_filter(explode("\n", $runs), static fn (string $l): bool => !str_starts_with(ltrim($l), '#'))), 'Loopback comes from the template, never overridden');
        self::assertStringContainsString("sed 's/<CLOUDFLARED_ADDRESS>/172.30.89.1/' docker/nginx/client-ip.cloudflared.conf.example > docker/nginx/production/client-ip.conf", $runs, 'The trusted peer is the edge gateway');
        self::assertStringContainsString("peer=172\\.30\\.89\\.1$", $runs, 'The predicted peer is checked in the access log');
        self::assertStringNotContainsString('DLA_HTTP_BIND=0.0.0.0', self::$source);
        self::assertStringContainsString('refuse docker compose -f docker-compose.prod.yml --env-file .env.production config --quiet', self::$source, 'Compose refuses to run without a bind address and port');
        self::assertStringContainsString('test "$published" = "nginx 127.0.0.1:8089->8080/tcp"', $runs, 'Exactly one published port');
        self::assertStringContainsString('-H \'Host: library.bravina.co.uk\' "$origin/health"', $runs, 'End to end through the published port');
        self::assertMatchesRegularExpression('/grep -Eqi \'\^set-cookie: __Host-.*secure/', $runs, 'Secure cookie over the plain-HTTP origin leg');
        self::assertDoesNotMatchRegularExpression('/^\s*(sudo\s+)?cloudflared\s|cloudflare\/cloudflared|api\.cloudflare\.com/m', self::$source, 'CI never runs or contacts a tunnel');
        self::assertStringContainsString('down -v --remove-orphans', $runs);
        self::assertSame('always()', array_values(array_filter(self::steps(), static fn (array $s): bool => $s[0] === 'production-stack' && ($s[1]['name'] ?? '') === 'Remove the stack'))[0][1]['if'] ?? null);
        self::assertSame('static', self::jobs()['production-stack']['needs']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function jobs(): array
    {
        return self::$workflow['jobs'];
    }

    /**
     * @return list<array{string, array<string, mixed>}>
     */
    private static function steps(): array
    {
        $steps = [];
        foreach (self::jobs() as $name => $job) {
            foreach ($job['steps'] as $step) {
                $steps[] = [$name, $step];
            }
        }

        return $steps;
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__, 3) . '/' . $relative;
    }
}
