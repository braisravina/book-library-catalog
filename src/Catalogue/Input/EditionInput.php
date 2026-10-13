<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue\Input;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Catalogue\Repository\EditionRepository;
use DigitalLibrary\Catalogue\Vocabulary;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;

/**
 * Edition-level fields: the published manifestation an ISBN identifies.
 */
final class EditionInput
{
    /**
     * @param list<array{name: string, role: string}> $contributors
     */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $publisher,
        public readonly string $publicationYear,
        public readonly string $editionStatement,
        public readonly string $language,
        public readonly string $pageCount,
        public readonly string $format,
        public readonly string $isbn,
        public readonly string $legalDeposit,
        public readonly string $description,
        public readonly array $contributors,
    ) {
    }

    /**
     * @param string $fallbackTitle used when the edition title is left blank
     *                              (the combined "new book" form)
     */
    public static function fromRequest(Request $request, string $fallbackTitle = '', string $fallbackSubtitle = ''): self
    {
        $title = trim((string) $request->input('edition_title', ''));
        $subtitle = trim((string) $request->input('edition_subtitle', ''));

        return new self(
            $title !== '' ? $title : $fallbackTitle,
            $title !== '' ? $subtitle : ($subtitle !== '' ? $subtitle : $fallbackSubtitle),
            trim((string) $request->input('publisher', '')),
            trim((string) $request->input('publication_year', '')),
            trim((string) $request->input('edition_statement', '')),
            trim((string) $request->input('language', '')),
            trim((string) $request->input('page_count', '')),
            trim((string) $request->input('format', '')),
            trim((string) $request->input('isbn', '')),
            trim((string) $request->input('legal_deposit', '')),
            trim((string) $request->input('edition_description', '')),
            People::fromRequest($request, 'contributor_names', 'contributor_roles', 'translator'),
        );
    }

    /**
     * @param array<string, mixed>                    $edition
     * @param list<array{name: string, role: string}> $contributors
     */
    public static function fromRecord(array $edition, array $contributors): self
    {
        $str = static fn (string $k): string => $edition[$k] === null ? '' : (string) $edition[$k];

        return new self(
            $str('title'), $str('subtitle'), $str('publisher_name'), $str('publication_year'), $str('edition_statement'),
            $str('language'), $str('page_count'), $str('format'), $str('isbn_13'), $str('legal_deposit'), $str('description'),
            $contributors,
        );
    }

    public static function blank(string $isbn = '', string $title = ''): self
    {
        return new self($title, '', '', '', '', '', '', '', $isbn, '', '', []);
    }

    public function parsedIsbn(): ?Isbn
    {
        return Isbn::tryParse($this->isbn);
    }

    public function validate(Validator $validator): void
    {
        $checks = Validator::make([
            'edition_title' => $this->title,
            'edition_subtitle' => $this->subtitle,
            'publisher' => $this->publisher,
            'publication_year' => $this->publicationYear,
            'edition_statement' => $this->editionStatement,
            'language' => $this->language,
            'page_count' => $this->pageCount,
            'format' => $this->format,
            'legal_deposit' => $this->legalDeposit,
            'edition_description' => $this->description,
        ], [
            'edition_title' => ['required', 'max:500', 'no_control'],
            'edition_subtitle' => ['max:500', 'no_control'],
            'publisher' => ['max:255', 'no_control'],
            'publication_year' => ['between:-3000,' . ((int) gmdate('Y') + 1)],
            'edition_statement' => ['max:255', 'no_control'],
            'language' => ['in:' . implode(',', Vocabulary::LANGUAGES)],
            'page_count' => ['between:1,65535'],
            'format' => ['in:' . implode(',', Vocabulary::FORMATS)],
            'legal_deposit' => ['max:64', 'no_control'],
            'edition_description' => ['max:10000'],
        ]);
        foreach ($checks->errors() as $field => $errors) {
            foreach ($errors as $error) {
                $validator->addError($field, $error);
            }
        }
        if ($this->isbn !== '' && $this->parsedIsbn() === null) {
            $validator->addError('isbn', 'validation.isbn');
        }
        People::validate($this->contributors, EditionRepository::CONTRIBUTOR_ROLES, 'contributors', $validator);
    }

    /**
     * @return array<string, mixed> column values for EditionRepository (publisher_id set by caller)
     */
    public function toRecord(?int $publisherId): array
    {
        $isbn = $this->parsedIsbn();

        return [
            'title' => $this->title,
            'subtitle' => WorkInput::nullable($this->subtitle),
            'publisher_id' => $publisherId,
            'publication_year' => $this->publicationYear === '' ? null : (int) $this->publicationYear,
            'edition_statement' => WorkInput::nullable($this->editionStatement),
            'language' => WorkInput::nullable($this->language),
            'page_count' => $this->pageCount === '' ? null : (int) $this->pageCount,
            'format' => WorkInput::nullable($this->format),
            'isbn_13' => $isbn?->isbn13,
            'isbn_10' => $isbn?->isbn10,
            'legal_deposit' => WorkInput::nullable($this->legalDeposit),
            'description' => WorkInput::nullable($this->description),
        ];
    }
}
