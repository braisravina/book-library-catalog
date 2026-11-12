<?php

declare(strict_types=1);

namespace DigitalLibrary\Collections;

use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

final class CollectionInput
{
    public const VISIBILITIES = ['private', 'shared'];

    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly string $visibility,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            trim((string) preg_replace('/\s+/u', ' ', (string) $request->input('name', ''))),
            trim((string) $request->input('description', '')),
            trim((string) $request->input('visibility', 'private')),
        );
    }

    /**
     * New collections are private unless the owner chooses to share them.
     */
    public static function blank(): self
    {
        return new self('', '', 'private');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRecord(array $row): self
    {
        return new self((string) $row['name'], (string) ($row['description'] ?? ''), (string) $row['visibility']);
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make(
            ['name' => $this->name, 'description' => $this->description, 'visibility' => $this->visibility],
            [
                'name' => ['required', 'max:150', 'no_control'],
                'description' => ['max:2000'],
                'visibility' => ['required', 'in:' . implode(',', self::VISIBILITIES)],
            ],
        );
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
    }
}
