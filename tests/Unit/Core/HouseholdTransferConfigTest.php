<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Console\Console;
use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigValidator;
use DigitalLibrary\Core\Services;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

final class HouseholdTransferConfigTest extends TestCase
{
    #[TestWith(['0'])]
    #[TestWith(['101'])]
    #[TestWith(['-1'])]
    #[TestWith(['wrong'])]
    public function testInvalidLimitsAreConfigurationErrors(string $limit): void
    {
        $report = (new ConfigValidator(dirname(__DIR__, 3)))->validate(new Config(['APP_ENV' => 'testing', 'HOUSEHOLD_TRANSFER_LIMIT' => $limit]));
        self::assertContains('HOUSEHOLD_TRANSFER_LIMIT', array_column($report['errors'], 0));
    }

    public function testDiagnosticCliCannotConnectToProductionOrNormalLocalDatabase(): void
    {
        $dir = sys_get_temp_dir() . '/dla-h4-cli-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        try {
            foreach (['production', 'testing'] as $environment) {
                $out = fopen('php://memory', 'w+');
                $err = fopen('php://memory', 'w+');
                $services = new Services(new Config(['APP_ENV' => $environment, 'APP_ROLE' => 'ops', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_DATABASE' => 'library', 'TEMP_PATH' => $dir]), dirname(__DIR__, 3));
                $console = new Console($services, out: $out, err: $err);
                self::assertNotSame(0, $console->run(['console', 'inventory:check', '--actor=1', '--writers-stopped', '--confirm=LOCAL-INVENTORY-CHECK']));
                rewind($err);
                self::assertStringContainsString('Refused:', stream_get_contents($err));
                fclose($out); fclose($err);
            }
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($dir);
        }
    }
}
