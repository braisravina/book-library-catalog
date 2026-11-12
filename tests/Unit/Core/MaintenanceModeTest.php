<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Core\MaintenanceMode;
use PHPUnit\Framework\TestCase;

final class MaintenanceModeTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-maintenance-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testIncompleteRestoreKeepsMaintenanceActive(): void
    {
        $mode = new MaintenanceMode($this->dir . '/maintenance.flag');
        self::assertFalse($mode->isActive());
        self::assertSame(0, $mode->activeForSeconds());

        $mode->enable('restore');
        self::assertTrue($mode->isActive());
        self::assertSame(0, fileperms($this->dir . '/maintenance.flag') & 0137, 'Not world-readable or executable');
        self::assertSame(60, $mode->activeForSeconds((int) filemtime($this->dir . '/maintenance.flag') + 60));

        $mode->markRestoreIncomplete(['file' => 'a.tar.gz', 'database' => 'library', 'started_at' => '2026-11-11T10:00:00Z']);
        $mode->disable();
        self::assertTrue($mode->isActive(), 'Removing the flag does not reopen a partially restored site');
        self::assertSame('a.tar.gz', $mode->restoreIncomplete()['file'] ?? null);
        self::assertSame(0, $mode->activeForSeconds(), 'The drain clock follows the flag only');

        $mode->clearRestoreIncomplete();
        self::assertNull($mode->restoreIncomplete());
        self::assertFalse($mode->isActive());
    }
}
