<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Export;

use DigitalLibrary\Export\CsvWriter;
use DigitalLibrary\Export\Dataset;
use DigitalLibrary\Export\JsonWriter;
use PHPUnit\Framework\TestCase;

final class CsvWriterTest extends TestCase
{
    public function testFormulaInjectionIsNeutralised(): void
    {
        foreach (['=1+1', '+SUM(A1)', '-2', '@cmd', "\t=x", "\r=x", '   =x', '＝1', '＋1', '－1', '＠x', "\u{3000}=x"] as $value) {
            self::assertSame("'" . $value, CsvWriter::protect($value), json_encode($value));
        }
        foreach (['', 'Rayuela', '1-2', 'a=b', 'email@example.org', "'quoted", '12.50'] as $value) {
            self::assertSame($value, CsvWriter::protect($value), $value);
        }
    }

    public function testCellTypes(): void
    {
        self::assertSame('', CsvWriter::cell(null));
        self::assertSame('1', CsvWriter::cell(true));
        self::assertSame('0', CsvWriter::cell(false));
        self::assertSame('-5', CsvWriter::cell(-5), 'Numbers are not prefixed');
        self::assertSame('Ana; =Bob', CsvWriter::cell(['Ana', '=Bob']), 'Lists are joined; only the cell start matters');
        self::assertSame("'=A; B", CsvWriter::cell(['=A', 'B']));
    }

    public function testOutputIsRfc4180WithBomAndNoTruncation(): void
    {
        $out = fopen('php://memory', 'w+');
        $long = str_repeat('x', 100_000);
        $count = CsvWriter::write($out, ['a', 'b'], [['a' => 'He said "hi", then left', 'b' => "line1\nline2"], ['a' => $long, 'b' => null]]);
        rewind($out);
        $body = stream_get_contents($out);
        self::assertSame(2, $count);
        self::assertStringStartsWith("\xEF\xBB\xBFa,b\r\n\"He said \"\"hi\"\", then left\",\"line1\nline2\"\r\n", $body);
        self::assertStringContainsString($long . ",\r\n", $body);
    }

    public function testJsonDocumentIsVersionedAndComplete(): void
    {
        $out = fopen('php://memory', 'w+');
        $dataset = new Dataset('things', ['id', 'name', 'tags'], static fn () => [['id' => 1, 'name' => 'Ñ "x"', 'tags' => ['a', 'b']], ['id' => 2]]);
        $counts = JsonWriter::write($out, 'test', ['scope' => 'unit'], [$dataset, new Dataset('empty', ['id'], static fn () => [])]);
        rewind($out);
        $json = json_decode(stream_get_contents($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['things' => 2, 'empty' => 0], $counts);
        self::assertSame(['format' => 'digital-library-archive', 'type' => 'test', 'schema_version' => 1], array_intersect_key($json['export'], array_flip(['format', 'type', 'schema_version'])));
        self::assertSame(['things', 'empty'], $json['export']['datasets']);
        self::assertSame([['id' => 1, 'name' => 'Ñ "x"', 'tags' => ['a', 'b']], ['id' => 2, 'name' => null, 'tags' => null]], $json['data']['things'], 'Every column is present; missing values are null');
        self::assertSame([], $json['data']['empty']);
    }
}
