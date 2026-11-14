<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Database;

use PHPUnit\Framework\TestCase;

final class HouseholdMigrationFilesTest extends TestCase
{
    public function testReleasedHouseholdMigrationsStayFrozen(): void
    {
        $pinned = [
            '2026_11_13_180000_create_households.sql' => '449979cc2e4887b2072eaf0a3fa85c934248c7e2e6f9019a387b48242b1450fc',
            '2026_11_13_180001_add_household_to_inventory.sql' => '98950bd54bf51586855009ab57ecd066005dcf2a28f257993998e2d4205434c2',
            '2026_11_13_180002_backfill_default_household.sql' => 'bc2cd103900bc65ee2b4428336a77e8b52be22efc99d2438c29ab2945ba34dc6',
            '2026_11_14_100000_scope_inventory_codes_to_households.sql' => 'e132f94ad8772f6d6ded2c97057188981645f9b65abdb8221cebac00847f85f5',
            '2026_11_14_100001_add_household_to_collections.sql' => 'de75e44d3e0b65d88cd3bda09a9e61c17278db7738d7f465f0c51db4d63e2908',
            '2026_11_14_100002_backfill_shared_collection_households.sql' => 'f73bd34a0b391d12a60412d63aa134581498c39115b98f09f9fe958913966f55',
            '2026_11_14_100003_create_invitation_households.sql' => '0b21b775c3e3bfb2bac933be73641b18e661dbeaaac158fa06a73683173f2dae',
        ];
        foreach ($pinned as $name => $hash) {
            self::assertSame($hash, hash_file('sha256', dirname(__DIR__, 3) . '/database/migrations/' . $name), $name);
        }
    }
}
