<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Backup;

use DigitalLibrary\Backup\BackupException;
use DigitalLibrary\Backup\DatabaseDumper;
use DigitalLibrary\Backup\DumpReader;
use DigitalLibrary\Backup\Manifest;
use DigitalLibrary\Backup\TarPath;
use DigitalLibrary\Backup\TarReader;
use DigitalLibrary\Backup\TarWriter;
use PHPUnit\Framework\TestCase;

final class TarTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-tar-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    public function testRoundTripPreservesNamesAndBytes(): void
    {
        $source = $this->dir . '/source.bin';
        file_put_contents($source, $bytes = random_bytes(70_000));
        $archive = $this->dir . '/a.tar.gz';
        $tar = new TarWriter($archive);
        self::assertSame(hash('sha256', $bytes), $tar->addFile('covers/ab/x.jpg', $source));
        $tar->addString('manifest.json', '{}');
        $tar->addString('empty', '');
        $tar->close();

        $seen = [];
        $names = (new TarReader($archive))->each(function (string $name, int $size, \Closure $read) use (&$seen): void {
            $data = '';
            while (($chunk = $read()) !== null) {
                $data .= $chunk;
            }
            $seen[$name] = [$size, $data];
        });
        self::assertSame(['covers/ab/x.jpg', 'manifest.json', 'empty'], $names);
        self::assertSame([70_000, $bytes], $seen['covers/ab/x.jpg']);
        self::assertSame([0, ''], $seen['empty']);

        // Readable by standard tools too.
        exec('tar -tzf ' . escapeshellarg($archive) . ' 2>&1', $listing, $code);
        self::assertSame(0, $code);
        self::assertSame(['covers/ab/x.jpg', 'manifest.json', 'empty'], $listing);
    }

    public function testUnsafeNamesAreRejected(): void
    {
        foreach (['../x', 'a/../b', '/etc/passwd', 'a//b', 'a/./b', '.hidden', 'a\\b', "a\0b", 'a b', str_repeat('a', 101), '', 'covers/..', 'a/'] as $name) {
            self::assertFalse(TarPath::isSafe($name), json_encode($name));
        }
        foreach (['database.sql', 'manifest.json', 'covers/ab/ab00.thumb.jpg', 'a-b_c.d'] as $name) {
            self::assertTrue(TarPath::isSafe($name), $name);
        }
        $this->expectException(BackupException::class);
        (new TarWriter($this->dir . '/b.tar.gz'))->addString('../escape', 'x');
    }

    public function testManifestValidation(): void
    {
        $valid = [
            'format' => 'dla-backup', 'format_version' => 1, 'created_at' => '2026-10-13T00:00:00Z',
            'application' => ['schema_version' => 'v'], 'database' => ['file' => 'database.sql', 'tables' => []],
            'covers' => ['missing' => []],
            'files' => [['path' => 'database.sql', 'size' => 1, 'sha256' => str_repeat('a', 64)]],
        ];
        self::assertSame([], Manifest::problems($valid));
        self::assertSame(['Not a Digital Library Archive backup (unknown manifest format)'], Manifest::problems(array_merge($valid, ['format' => 'zip'])));
        self::assertStringContainsString('newer', Manifest::problems(array_merge($valid, ['format_version' => 2]))[0]);
        $bad = $valid;
        $bad['files'][] = ['path' => 'scripts/run.sh', 'size' => 1, 'sha256' => str_repeat('a', 64)];
        self::assertContains('Manifest lists an unexpected file: scripts/run.sh', Manifest::problems($bad));
        $noDb = $valid;
        $noDb['files'] = [];
        self::assertContains('Backup has no database dump', Manifest::problems($noDb));
    }

    public function testOnlyDumperStatementShapesAreAllowed(): void
    {
        foreach (['SET NAMES utf8mb4;', "SET time_zone = '+00:00';", 'SET FOREIGN_KEY_CHECKS = 0;', 'SET FOREIGN_KEY_CHECKS = 1;',
            "CREATE TABLE `works` (\n  `id` int unsigned NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;",
            "INSERT INTO `works` (`id`, `title`) VALUES (1, 'A; B'),\n(2, 'C');"] as $ok) {
            self::assertTrue(DumpReader::isAllowed($ok), $ok);
        }
        foreach (['DROP TABLE `works`;', 'SET GLOBAL x = 1;', "SELECT 1 INTO OUTFILE '/tmp/x';", 'CREATE TABLE `x` (`id` int);',
            "CREATE TABLE `x` (\n  `id` int\n) ENGINE=MyISAM;", "CREATE TABLE `x` (\n  `id` int\n) ENGINE=InnoDB INDEX DIRECTORY='/x';",
            "CREATE TABLE `x` (\n  `id` int\n) ENGINE=InnoDB;\nDROP TABLE y;", 'INSERT INTO `x` VALUES (1);', 'INSERT INTO works (`id`) VALUES (1);',
            'UPDATE `users` SET role_id = 1;', 'DELETE FROM `audit_log`;', ''] as $bad) {
            self::assertFalse(DumpReader::isAllowed($bad), $bad);
        }
    }

    public function testDumpFramingIsStrict(): void
    {
        $file = $this->dir . '/d.sql';
        $write = static fn (string $body) => file_put_contents($file, DatabaseDumper::HEADER . "\n" . $body);
        $write("-- comment\n-- dla:statement 9\nSELECT 1;\n-- dla:statement 9\nSELECT 2;\n-- dla:end 2\n");
        self::assertSame(['SELECT 1;', 'SELECT 2;'], iterator_to_array(DumpReader::statements($file), false));

        foreach ([
            'wrong count' => "-- dla:statement 9\nSELECT 1;\n-- dla:end 5\n",
            'no end' => "-- dla:statement 9\nSELECT 1;\n",
            'truncated' => "-- dla:statement 90\nSELECT 1;\n-- dla:end 1\n",
            'raw sql' => "DROP DATABASE x;\n-- dla:end 0\n",
            'data after end' => "-- dla:end 0\nSELECT 1;\n",
        ] as $label => $body) {
            $write($body);
            try {
                iterator_to_array(DumpReader::statements($file));
                self::fail('Accepted: ' . $label);
            } catch (BackupException) {
                self::assertTrue(true);
            }
        }
        file_put_contents($file, "-- something else\n");
        $this->expectException(BackupException::class);
        iterator_to_array(DumpReader::statements($file));
    }
}
