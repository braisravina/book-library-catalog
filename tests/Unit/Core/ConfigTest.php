<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigException;
use DigitalLibrary\Core\DotEnv;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testDotEnvParsesQuotesCommentsAndExport(): void
    {
        $values = DotEnv::parse(<<<'ENV'
            # comment
            APP_ENV=development
            export DB_HOST=db
            QUOTED="hello world"
            SINGLE='raw $value'
            INLINE=value # trailing comment
            EMPTY=
            not a pair
            1INVALID=x
            ENV);

        self::assertSame('development', $values['APP_ENV']);
        self::assertSame('db', $values['DB_HOST']);
        self::assertSame('hello world', $values['QUOTED']);
        self::assertSame('raw $value', $values['SINGLE']);
        self::assertSame('value', $values['INLINE']);
        self::assertSame('', $values['EMPTY']);
        self::assertArrayNotHasKey('1INVALID', $values);
    }

    public function testTypedAccessors(): void
    {
        $config = new Config(['A' => 'true', 'B' => '0', 'N' => '42', 'BAD' => '4x', 'L' => 'a, b,,c']);

        self::assertTrue($config->bool('A'));
        self::assertFalse($config->bool('B', true));
        self::assertTrue($config->bool('MISSING', true));
        self::assertSame(42, $config->int('N', 1));
        self::assertSame(7, $config->int('BAD', 7));
        self::assertSame(['a', 'b', 'c'], $config->list('L'));
        self::assertSame('fallback', $config->get('MISSING', 'fallback'));
    }

    public function testEmptyValueIsTreatedAsMissing(): void
    {
        $this->expectException(ConfigException::class);
        (new Config(['SECRET' => '']))->require('SECRET');
    }

    public function testFileSecretTakesPrecedence(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'secret');
        file_put_contents($file, "s3cret-from-file\n");
        try {
            $config = new Config(['DB_PASSWORD' => 'plain', 'DB_PASSWORD_FILE' => $file]);
            self::assertSame('s3cret-from-file', $config->get('DB_PASSWORD'));
        } finally {
            unlink($file);
        }
    }

    public function testUnreadableSecretFileFailsWithoutLeakingPath(): void
    {
        $config = new Config(['DB_PASSWORD_FILE' => '/nonexistent/secret/path']);
        try {
            $config->get('DB_PASSWORD');
            self::fail('Expected exception');
        } catch (ConfigException $e) {
            self::assertStringNotContainsString('/nonexistent', $e->getMessage());
        }
    }

    public function testDebugIsNeverEnabledInProduction(): void
    {
        self::assertFalse((new Config(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']))->debug());
        self::assertFalse((new Config(['APP_DEBUG' => 'true']))->debug(), 'Missing APP_ENV must default to production');
        self::assertTrue((new Config(['APP_ENV' => 'development', 'APP_DEBUG' => 'true']))->debug());
    }
}
