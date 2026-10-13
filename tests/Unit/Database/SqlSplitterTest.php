<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Database;

use DigitalLibrary\Database\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsOnTopLevelSemicolonsOnly(): void
    {
        $sql = <<<'SQL'
            -- leading comment; with semicolon
            CREATE TABLE a (id INT); # hash comment;
            INSERT INTO a VALUES ('x;y'), ("q\";r"), ('it''s;');
            /* block; comment */ SELECT `we;ird` FROM a;

            SQL;

        self::assertSame([
            'CREATE TABLE a (id INT)',
            "INSERT INTO a VALUES ('x;y'), (\"q\\\";r\"), ('it''s;')",
            'SELECT `we;ird` FROM a',
        ], SqlSplitter::split($sql));
    }

    public function testDoubleDashWithoutSpaceIsNotAComment(): void
    {
        self::assertSame(['SELECT 1--1'], SqlSplitter::split('SELECT 1--1;'));
    }
}
