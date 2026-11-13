<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Name and description of a household, as submitted by an administrator.
 */
final class HouseholdInput
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            trim((string) $request->input('name', '')),
            trim((string) $request->input('description', '')),
        );
    }

    /**
     * @param array<string, mixed> $household
     */
    public static function fromRecord(array $household): self
    {
        return new self((string) $household['name'], (string) ($household['description'] ?? ''));
    }

    public static function blank(): self
    {
        return new self('', '');
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make(
            ['name' => $this->name, 'description' => $this->description],
            ['name' => ['required', 'max:150', 'no_control'], 'description' => ['max:1000']],
        );
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
    }
}
