<?php

declare(strict_types=1);

namespace DigitalLibrary\I18n;

/**
 * Centralised translations. Catalogues live in lang/{locale}.php as nested
 * arrays and are addressed with dot keys: t('auth.login.title').
 * Missing keys fall back to the fallback locale, then to the key itself.
 */
final class Translator
{
    public const SUPPORTED = ['en', 'es', 'gl'];

    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    public function __construct(
        private readonly string $directory,
        private string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
        if (!self::isSupported($locale)) {
            $this->locale = $fallback;
        }
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && in_array($locale, self::SUPPORTED, true);
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if (self::isSupported($locale)) {
            $this->locale = $locale;
        }
    }

    /**
     * @param array<string, string|int> $params Replaces ":name" placeholders.
     */
    public function get(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $message = $this->catalogue($locale)[$key] ?? $this->catalogue($this->fallback)[$key] ?? $key;
        if ($params !== []) {
            $replace = [];
            foreach ($params as $name => $value) {
                $replace[':' . $name] = (string) $value;
            }
            // Longest placeholders first so ":count" does not clobber ":counter".
            uksort($replace, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
            $message = strtr($message, $replace);
        }

        return $message;
    }

    public function has(string $key, ?string $locale = null): bool
    {
        return isset($this->catalogue($locale ?? $this->locale)[$key]);
    }

    /**
     * @return array<string, string>
     */
    public function catalogue(string $locale): array
    {
        if (!self::isSupported($locale)) {
            return [];
        }
        if (!isset($this->catalogues[$locale])) {
            $file = $this->directory . '/' . $locale . '.php';
            $data = is_file($file) ? require $file : [];
            $this->catalogues[$locale] = self::flatten(is_array($data) ? $data : []);
        }

        return $this->catalogues[$locale];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out += self::flatten($value, $full);
            } else {
                $out[$full] = (string) $value;
            }
        }

        return $out;
    }
}
