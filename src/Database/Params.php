<?php

declare(strict_types=1);

namespace DigitalLibrary\Database;

/**
 * Collects bound values for dynamically built queries. Native MySQL prepared
 * statements cannot reuse a named placeholder, so every use gets its own name.
 */
final class Params
{
    /** @var array<string, int|string|null> */
    private array $values = [];

    public function add(int|string|null $value): string
    {
        $name = 'p' . count($this->values);
        $this->values[$name] = $value;

        return ':' . $name;
    }

    /**
     * @param list<int|string> $values
     */
    public function in(array $values): string
    {
        if ($values === []) {
            return 'NULL';
        }

        return implode(', ', array_map(fn ($v): string => $this->add($v), $values));
    }

    /**
     * @return array<string, int|string|null>
     */
    public function all(): array
    {
        return $this->values;
    }

    public function bind(\PDOStatement $stmt): void
    {
        foreach ($this->values as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? \PDO::PARAM_INT : ($value === null ? \PDO::PARAM_NULL : \PDO::PARAM_STR));
        }
    }

    /**
     * Escapes LIKE wildcards so user input is matched literally.
     */
    public static function likeContains(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
