<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Input;

use DigitalLibrary\Catalogue\Vocabulary;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Work-level fields (title, original data, authors, categories).
 */
final class WorkInput
{
    public const MAX_PEOPLE = 20;

    /**
     * @param list<array{name: string, role: string}> $authors
     * @param list<int>                               $categoryIds
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $originalTitle,
        public readonly string $originalLanguage,
        public readonly string $firstPublishedYear,
        public readonly string $description,
        public readonly array $authors,
        public readonly array $categoryIds,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            trim((string) $request->input('title', '')),
            trim((string) $request->input('subtitle', '')),
            trim((string) $request->input('original_title', '')),
            trim((string) $request->input('original_language', '')),
            trim((string) $request->input('first_published_year', '')),
            trim((string) $request->input('work_description', '')),
            People::fromRequest($request, 'author_names', 'author_roles', 'author'),
            array_values(array_unique(array_map('intval', array_filter($request->inputList('categories'), 'ctype_digit')))),
        );
    }

    /**
     * @param array<string, mixed>                    $work
     * @param list<array{name: string, role: string}> $authors
     * @param list<int>                               $categoryIds
     */
    public static function fromRecord(array $work, array $authors, array $categoryIds): self
    {
        return new self(
            (string) $work['title'],
            (string) ($work['subtitle'] ?? ''),
            (string) ($work['original_title'] ?? ''),
            (string) ($work['original_language'] ?? ''),
            $work['first_published_year'] === null ? '' : (string) $work['first_published_year'],
            (string) ($work['description'] ?? ''),
            $authors,
            $categoryIds,
        );
    }

    public static function blank(string $title = ''): self
    {
        return new self($title, '', '', '', '', '', [], []);
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make([
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'original_title' => $this->originalTitle,
            'original_language' => $this->originalLanguage,
            'first_published_year' => $this->firstPublishedYear,
            'work_description' => $this->description,
        ], [
            'title' => ['required', 'max:500', 'no_control'],
            'subtitle' => ['max:500', 'no_control'],
            'original_title' => ['max:500', 'no_control'],
            'original_language' => ['in:' . implode(',', Vocabulary::LANGUAGES)],
            'first_published_year' => ['between:-3000,' . ((int) gmdate('Y') + 1)],
            'work_description' => ['max:10000'],
        ]);
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
        People::validate($this->authors, Vocabulary::AUTHOR_ROLES, 'authors', $validator);
        if (count($this->categoryIds) > 50) {
            $validator->addError('categories', 'validation.too_many');
        }
    }

    /**
     * @return array<string, mixed> column values for WorkRepository
     */
    public function toRecord(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => self::nullable($this->subtitle),
            'original_title' => self::nullable($this->originalTitle),
            'original_language' => self::nullable($this->originalLanguage),
            'first_published_year' => $this->firstPublishedYear === '' ? null : (int) $this->firstPublishedYear,
            'description' => self::nullable($this->description),
        ];
    }

    public static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
