<?php

declare(strict_types=1);

namespace DigitalLibrary\Wishlist;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Wishlist entry form data: a title (required), an optional author and ISBN,
 * a priority and private notes. The catalogue link is set by the controller,
 * never taken from the form.
 */
final class WishlistInput
{
    public const PRIORITIES = ['high', 'normal', 'low'];

    public function __construct(
        public readonly string $title,
        public readonly string $author,
        public readonly string $isbn,
        public readonly string $priority,
        public readonly string $notes,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $line = static fn (string $k): string => trim((string) preg_replace('/\s+/u', ' ', (string) $request->input($k, '')));

        return new self(
            $line('title'),
            $line('author'),
            $line('isbn'),
            trim((string) $request->input('priority', 'normal')),
            trim((string) $request->input('notes', '')),
        );
    }

    public static function blank(): self
    {
        return new self('', '', '', 'normal', '');
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRecord(array $row): self
    {
        return new self((string) $row['title'], (string) ($row['author'] ?? ''), (string) ($row['isbn_13'] ?? ''), (string) $row['priority'], (string) ($row['notes'] ?? ''));
    }

    /**
     * @param array<string, mixed> $edition catalogue edition (EditionRepository::find)
     * @param list<string>         $authors
     */
    public static function fromEdition(array $edition, array $authors): self
    {
        return new self(
            mb_substr((string) $edition['title'], 0, 500),
            mb_substr(implode(', ', $authors), 0, 255),
            (string) ($edition['isbn_13'] ?? ''),
            'normal',
            '',
        );
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make([
            'title' => $this->title,
            'author' => $this->author,
            'priority' => $this->priority,
            'notes' => $this->notes,
        ], [
            'title' => ['required', 'max:500', 'no_control'],
            'author' => ['max:255', 'no_control'],
            'priority' => ['required', 'in:' . implode(',', self::PRIORITIES)],
            'notes' => ['max:1000'],
        ]);
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
        if ($this->isbn !== '' && Isbn::tryParse($this->isbn) === null) {
            $validator->addError('isbn', 'validation.isbn');
        }
    }

    /**
     * Normalised ISBN-13 (the duplicate key), or null when none was given.
     */
    public function isbn13(): ?string
    {
        return $this->isbn === '' ? null : Isbn::tryParse($this->isbn)?->isbn13;
    }
}
