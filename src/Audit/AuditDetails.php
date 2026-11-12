<?php

declare(strict_types=1);

namespace DigitalLibrary\Audit;

/**
 * Prepares stored audit details for display. AuditLogger already redacts
 * known secret keys on write; this applies a broader pattern again on read
 * (covering rows written before a key was added) and pretty-prints the JSON.
 * The result is plain text; templates must still escape it.
 */
final class AuditDetails
{
    private const SECRET_KEY = '/pass(word)?|token|secret|csrf|api[_-]?key|private[_-]?key|session|cookie|authorization|hash/i';

    public static function format(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return null;
        }
        try {
            $data = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '[unreadable]';
        }
        if (is_array($data)) {
            $data = self::redact($data);
        }

        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY, $key) === 1) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }
}
