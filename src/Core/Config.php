<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

/**
 * Read-only application configuration sourced from environment variables.
 *
 * Any key may instead be supplied as KEY_FILE pointing at a file (Docker
 * secrets); the file contents take precedence over a plain KEY value.
 */
final class Config
{
    /**
     * @param array<string, string> $values
     */
    public function __construct(private readonly array $values)
    {
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $values = [];
        if ($envFile !== null && is_file($envFile) && is_readable($envFile)) {
            $values = DotEnv::parse((string) file_get_contents($envFile));
        }
        foreach (getenv() as $key => $value) {
            $values[$key] = (string) $value;
        }

        return new self($values);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $file = $this->values[$key . '_FILE'] ?? null;
        if ($file !== null && $file !== '') {
            if (!is_file($file) || !is_readable($file)) {
                throw new ConfigException(sprintf('Secret file for %s is not readable.', $key));
            }

            return rtrim((string) file_get_contents($file), "\r\n");
        }
        $value = $this->values[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null) {
            throw new ConfigException(sprintf('Missing required configuration value %s.', $key));
        }

        return $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return $default;
        }

        return (int) $value;
    }

    /**
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key);
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    public function with(string $key, string $value): self
    {
        $values = $this->values;
        $values[$key] = $value;
        unset($values[$key . '_FILE']);

        return new self($values);
    }

    public function isProduction(): bool
    {
        return $this->get('APP_ENV', 'production') === 'production';
    }

    public function debug(): bool
    {
        // Debug output is never allowed in production, whatever APP_DEBUG says.
        return !$this->isProduction() && $this->bool('APP_DEBUG');
    }
}
