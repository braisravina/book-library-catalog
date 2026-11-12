<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

/**
 * Minimal parser for KEY=VALUE environment files.
 *
 * Only used as a development convenience; in containers the values come from
 * the process environment (Docker Compose `env_file`, secrets, orchestrator).
 */
final class DotEnv
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $contents): array
    {
        $values = [];
        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }
            $key = trim(substr($line, 0, $pos));
            if (!preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }
            $values[$key] = self::parseValue(trim(substr($line, $pos + 1)));
        }

        return $values;
    }

    private static function parseValue(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && strlen($value) >= 2 && str_ends_with($value, $quote)) {
            $inner = substr($value, 1, -1);

            return $quote === '"' ? stripcslashes($inner) : $inner;
        }
        // Strip trailing inline comments ("value # comment").
        $hash = strpos($value, ' #');

        return $hash === false ? $value : rtrim(substr($value, 0, $hash));
    }
}
