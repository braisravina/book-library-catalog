<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Tests\Support\BlockYaml;
use PHPUnit\Framework\TestCase;

final class ProductionIdentityTest extends TestCase
{
    public function testProductionComposePinsIdentityAndClearsFileOverride(): void
    {
        $root = dirname(__DIR__, 3);
        $compose = BlockYaml::parseFile($root . '/docker-compose.prod.yml');
        foreach (['php', 'ops'] as $service) {
            $env = $compose['services'][$service]['environment'];
            self::assertSame('production', $env['APP_ENV']);
            self::assertSame('', $env['APP_ENV_FILE']);
            foreach (['development', 'testing', '', 'prod'] as $mistake) {
                // Compose's explicit environment overrides env_file values.
                $values = array_replace(['APP_ENV' => $mistake, 'APP_ENV_FILE' => '/missing', 'HOUSEHOLDS_ENABLED' => 'true'], $env);
                $config = new Config($values);
                self::assertTrue($config->isProduction());
                self::assertFalse((new Services($config, $root))->householdsEnabled());
            }
        }
    }

    public function testProductionImageMarkerOverridesEveryEnvironmentValue(): void
    {
        $root = sys_get_temp_dir() . '/dla-production-identity-' . bin2hex(random_bytes(8));
        mkdir($root . '/src/Core', 0700, true);
        mkdir($root . '/config', 0700);
        copy(dirname(__DIR__, 3) . '/src/Core/Config.php', $root . '/src/Core/Config.php');
        touch($root . '/config/production-image');
        $script = $root . '/check.php';
        file_put_contents($script, '<?php require __DIR__ . "/src/Core/Config.php"; foreach (["development", "testing", "production", "", "invalid"] as $env) { if (!(new DigitalLibrary\\Core\\Config(["APP_ENV" => $env, "APP_ENV_FILE" => "/unreadable", "APP_DEBUG" => "true"]))->isProduction()) { exit(1); } }');
        try {
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script), $output, $code);
            self::assertSame(0, $code);
            self::assertStringContainsString('touch /var/www/html/config/production-image', (string) file_get_contents(dirname(__DIR__, 3) . '/docker/php/Dockerfile'));
            self::assertFalse((new Config(['APP_ENV' => 'testing']))->isProduction(), 'Explicit local testing remains available outside the prod image');
        } finally {
            unlink($script);
            unlink($root . '/src/Core/Config.php');
            unlink($root . '/config/production-image');
            rmdir($root . '/src/Core'); rmdir($root . '/src'); rmdir($root . '/config'); rmdir($root);
        }
    }
}
