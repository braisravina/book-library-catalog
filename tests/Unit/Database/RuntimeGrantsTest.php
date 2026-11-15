<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Database;

use DigitalLibrary\Database\RuntimeGrants;
use PHPUnit\Framework\TestCase;

final class RuntimeGrantsTest extends TestCase
{
    public function testEveryReleasedTableHasAnExplicitWriteOrReadOnlyDecision(): void
    {
        $tables = ['schema_migrations', 'schema_migration_attempts'];
        foreach (glob(dirname(__DIR__, 3) . '/database/migrations/*.sql') as $file) {
            preg_match_all('/CREATE TABLE\s+([a-z_]+)/', (string) file_get_contents($file), $matches);
            $tables = [...$tables, ...$matches[1]];
        }
        $reviewed = [...array_keys(RuntimeGrants::WRITES), 'roles', 'settings', 'schema_migrations', 'schema_migration_attempts'];
        sort($tables); sort($reviewed);
        self::assertSame($tables, $reviewed, 'New tables require a reviewed privilege decision.');
        $sql = RuntimeGrants::sql('library', 'library_app');
        self::assertStringContainsString("GRANT INSERT ON `library`.`audit_log` TO 'library_app'@'%';", $sql);
        self::assertStringContainsString("GRANT INSERT, UPDATE ON `library`.`users` TO 'library_app'@'%';", $sql);
        self::assertDoesNotMatchRegularExpression('/GRANT (?!SELECT ON)[^\n]+\.\*/', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(CREATE|ALTER|DROP|TRIGGER|REFERENCES|REVOKE|ALL PRIVILEGES|GRANT OPTION)\b/', $sql);
        self::assertStringContainsString('GRANT SELECT ON `library\\_test`.*', RuntimeGrants::sql('library_test', 'test_app'));
    }

    public function testSqlGenerationRejectsUntrustedIdentifiers(): void
    {
        foreach ([["library`; DROP", 'app', '%'], ['library', "app'", '%'], ['library', 'app', "%' OR 1=1"]] as $args) {
            try {
                RuntimeGrants::sql(...$args);
                self::fail('Unsafe SQL identifier accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
