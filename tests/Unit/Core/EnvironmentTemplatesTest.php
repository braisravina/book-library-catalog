<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Core;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigValidator;
use DigitalLibrary\Core\DotEnv;
use PHPUnit\Framework\TestCase;

/**
 * The shipped environment templates stay consistent and safe.
 */
final class EnvironmentTemplatesTest extends TestCase
{
    /** Development/test-only keys that the production template must not set. */
    private const DEVELOPMENT_ONLY = [
        'APP_PORT', 'DB_ROOT_PASSWORD', 'DB_TEST_DATABASE', 'DB_TEST_USERNAME', 'DB_TEST_PASSWORD',
        'DB_TEST_MIGRATION_USERNAME', 'DB_TEST_MIGRATION_PASSWORD', 'DB_TEST_REQUIRE_DEDICATED_ACCOUNTS',
    ];
    /** Secrets: plain values in development, *_FILE in production. */
    private const SECRETS = ['DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'GOOGLE_BOOKS_API_KEY'];

    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    public function testProductionTemplateValidatesCleanlyOnceSecretsExist(): void
    {
        $values = $this->template('.env.production.example');
        $this->dir = sys_get_temp_dir() . '/dla-secrets-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        foreach ($values as $key => $path) {
            if (str_ends_with($key, '_FILE')) {
                $file = $this->dir . '/' . basename($path);
                file_put_contents($file, bin2hex(random_bytes(32)) . "\n");
                $values[$key] = $file;
            }
        }
        $report = (new ConfigValidator(dirname(__DIR__, 3)))->validate(new Config($values));

        self::assertSame([], $report['errors']);
        self::assertSame([], $report['warnings'], 'The production template should not need any warning');
    }

    public function testProductionTemplateHoldsNoSecretsAndSecureSettings(): void
    {
        $values = $this->template('.env.production.example');

        self::assertSame('production', $values['APP_ENV']);
        self::assertStringStartsWith('https://', $values['APP_URL']);
        self::assertSame('true', $values['SESSION_SECURE_COOKIE']);
        self::assertSame('true', $values['SECURITY_HSTS']);
        self::assertSame('false', $values['APP_DEBUG']);
        self::assertSame('fragment', $values['INVITATION_LINK_FORMAT']);
        foreach (self::SECRETS as $key) {
            self::assertArrayNotHasKey($key, $values, $key . ' must come from a secret file');
        }
        foreach (['DB_PASSWORD_FILE', 'DB_MIGRATION_PASSWORD_FILE'] as $key) {
            self::assertStringStartsWith('/run/secrets/', $values[$key]);
        }
        foreach (self::DEVELOPMENT_ONLY as $key) {
            self::assertArrayNotHasKey($key, $values, $key . ' is development-only');
        }
        foreach ($values as $key => $value) {
            self::assertDoesNotMatchRegularExpression('/change[_-]?me/i', $value, $key . ': no placeholders');
            if (str_ends_with($key, '_FILE')) {
                self::assertStringStartsWith('/run/secrets/', $value, $key);
            }
        }
    }

    public function testEveryDevelopmentSettingIsCoveredByTheProductionTemplate(): void
    {
        $development = array_keys($this->template('.env.example'));
        $production = array_keys($this->template('.env.production.example'));
        $covered = array_merge($production, self::DEVELOPMENT_ONLY, self::SECRETS);
        // Settings shown only as comments in .env.example are optional either way.
        self::assertSame([], array_values(array_diff($development, $covered)));
    }

    public function testDevelopmentTemplateWorksOverPlainHttp(): void
    {
        $values = $this->template('.env.example');
        $report = (new ConfigValidator(dirname(__DIR__, 3)))->validate(new Config($values));

        self::assertSame('development', $values['APP_ENV']);
        self::assertSame([], $report['errors'], json_encode($report['errors']));
    }

    /**
     * @return array<string, string>
     */
    private function template(string $name): array
    {
        return DotEnv::parse((string) file_get_contents(dirname(__DIR__, 3) . '/' . $name));
    }
}
