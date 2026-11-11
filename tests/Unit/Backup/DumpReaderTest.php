<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Backup;

use DigitalLibrary\Backup\DumpReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Restores accept only the statement shapes DatabaseDumper writes; INSERT
 * values must be plain literals (Phase 5A Batch 6).
 */
final class DumpReaderTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function accepted(): iterable
    {
        yield 'integers and NULL' => ["INSERT INTO `t` (`a`, `b`, `c`) VALUES (1, -20, NULL);"];
        yield 'several tuples' => ["INSERT INTO `t` (`a`, `b`) VALUES (1, 'x'),\n(2, 'y'),\n(3, NULL);"];
        yield 'backslash escapes (PDO::quote)' => ["INSERT INTO `t` (`a`) VALUES ('O\\'Brien \\\\ \\n \\0 \\\" \\Z');"];
        yield 'doubled quotes' => ["INSERT INTO `t` (`a`) VALUES ('it''s');"];
        yield 'separators inside strings' => ["INSERT INTO `t` (`a`, `b`) VALUES ('), (SELECT 1); --', ',\n(');"];
        yield 'binary' => ["INSERT INTO `t` (`a`) VALUES (X'00ff10');"];
        yield 'empty binary and string' => ["INSERT INTO `t` (`a`, `b`) VALUES (X'', '');"];
        yield 'unicode' => ["INSERT INTO `t` (`a`) VALUES ('Ñandú “quoted” 😀');"];
        yield 'max bigint' => ["INSERT INTO `t` (`a`) VALUES (18446744073709551615);"];
    }

    #[DataProvider('accepted')]
    public function testDumperShapesAreAccepted(string $sql): void
    {
        self::assertTrue(DumpReader::isAllowed($sql));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refused(): iterable
    {
        yield 'subquery' => ["INSERT INTO `t` (`a`) VALUES ((SELECT password_hash FROM users LIMIT 1));"];
        yield 'function' => ["INSERT INTO `t` (`a`) VALUES (SLEEP(10));"];
        yield 'function in second column' => ["INSERT INTO `t` (`a`, `b`) VALUES (1, LOAD_FILE('/etc/passwd'));"];
        yield 'user variable' => ["INSERT INTO `t` (`a`) VALUES (@x);"];
        yield 'system variable' => ["INSERT INTO `t` (`a`) VALUES (@@version);"];
        yield 'arithmetic' => ["INSERT INTO `t` (`a`) VALUES (1+1);"];
        yield 'string concatenation' => ["INSERT INTO `t` (`a`) VALUES ('a' 'b');"];
        yield 'charset introducer' => ["INSERT INTO `t` (`a`) VALUES (_binary'x');"];
        yield 'hex number' => ["INSERT INTO `t` (`a`) VALUES (0x41);"];
        yield 'float' => ["INSERT INTO `t` (`a`) VALUES (1.5);"];
        yield 'leading zero' => ["INSERT INTO `t` (`a`) VALUES (01);"];
        yield 'comment' => ["INSERT INTO `t` (`a`) VALUES (1) /* x */;"];
        yield 'line comment' => ["INSERT INTO `t` (`a`) VALUES (1); -- x"];
        yield 'second statement' => ["INSERT INTO `t` (`a`) VALUES (1); DROP TABLE `users`;"];
        yield 'too few values' => ["INSERT INTO `t` (`a`, `b`) VALUES (1);"];
        yield 'too many values' => ["INSERT INTO `t` (`a`) VALUES (1, 2);"];
        yield 'unterminated string' => ["INSERT INTO `t` (`a`) VALUES ('abc);"];
        yield 'escaped closing quote' => ["INSERT INTO `t` (`a`) VALUES ('abc\\');"];
        yield 'missing semicolon' => ["INSERT INTO `t` (`a`) VALUES (1)"];
        yield 'other tuple separator' => ["INSERT INTO `t` (`a`) VALUES (1), (2);"];
        yield 'ON DUPLICATE KEY' => ["INSERT INTO `t` (`a`) VALUES (1) ON DUPLICATE KEY UPDATE `a` = 2;"];
        yield 'SELECT form' => ["INSERT INTO `t` (`a`) SELECT 1;"];
        yield 'odd hex' => ["INSERT INTO `t` (`a`) VALUES (X'0');"];
        yield 'unsafe table name' => ["INSERT INTO `t;x` (`a`) VALUES (1);"];
    }

    #[DataProvider('refused')]
    public function testAnythingButLiteralsIsRefused(string $sql): void
    {
        self::assertFalse(DumpReader::isAllowed($sql));
    }
}
