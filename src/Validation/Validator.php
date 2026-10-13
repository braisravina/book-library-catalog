<?php

declare(strict_types=1);

namespace DigitalLibrary\Validation;

/**
 * Small declarative validator. Rules are strings ("required", "email",
 * "max:255", "min:3", "in:a,b,c"); errors are translation keys per field.
 */
final class Validator
{
    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * @param array<string, string|null>  $data
     * @param array<string, list<string>> $rules
     */
    public function __construct(private readonly array $data, private readonly array $rules)
    {
    }

    /**
     * @param array<string, string|null>  $data
     * @param array<string, list<string>> $rules
     */
    public static function make(array $data, array $rules): self
    {
        $validator = new self($data, $rules);
        $validator->run();

        return $validator;
    }

    public function fails(): bool
    {
        return $this->errors !== [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function addError(string $field, string $key): void
    {
        $this->errors[$field][] = $key;
    }

    private static function between(string $value, string $range): bool
    {
        [$min, $max] = array_map('intval', explode(',', $range, 2) + [1 => PHP_INT_MAX]);

        return preg_match('/^-?\d{1,10}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max;
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function run(): void
    {
        foreach ($this->rules as $field => $rules) {
            $value = $this->data[$field] ?? null;
            $value = $value === null ? null : trim($value);
            $empty = $value === null || $value === '';
            if ($empty) {
                if (in_array('required', $rules, true)) {
                    $this->addError($field, 'validation.required');
                }
                continue;
            }
            foreach ($rules as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $ok = match ($name) {
                    'required' => true,
                    'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false && mb_strlen($value) <= 254,
                    'max' => mb_strlen($value) <= (int) $arg,
                    'min' => mb_strlen($value) >= (int) $arg,
                    'in' => in_array($value, explode(',', (string) $arg), true),
                    'integer' => preg_match('/^\d+$/', $value) === 1,
                    'no_control' => preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 0,
                    'between' => self::between($value, (string) $arg),
                    'date' => self::isDate($value),
                    'money' => preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/', $value) === 1,
                    default => throw new \InvalidArgumentException('Unknown validation rule ' . $name),
                };
                if (!$ok) {
                    $this->addError($field, 'validation.' . $name);
                }
            }
        }
    }
}
