<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Vocabulary;

/**
 * Normalised bibliographic data from a provider. Everything here is
 * untrusted external data: it is only ever used to PRE-FILL a form that the
 * user reviews, and it passes the same validation as manual input.
 */
final class BookMetadata
{
    /**
     * @param list<string> $authors
     */
    public function __construct(
        public readonly string $title = '',
        public readonly string $subtitle = '',
        public readonly array $authors = [],
        public readonly string $publisher = '',
        public readonly ?int $publicationYear = null,
        public readonly ?int $pageCount = null,
        public readonly string $language = '',
        public readonly string $description = '',
        public readonly string $coverUrl = '',
    ) {
    }

    /**
     * Normalises raw strings: control characters removed, whitespace
     * collapsed, lengths capped to the catalogue's column sizes.
     *
     * @param list<mixed> $authors
     */
    public static function clean(
        mixed $title,
        mixed $subtitle,
        array $authors,
        mixed $publisher,
        mixed $date,
        mixed $pages,
        mixed $language,
        mixed $description,
        mixed $coverUrl,
    ): self {
        $text = static function (mixed $v, int $max, bool $multiline = false): string {
            if (!is_string($v)) {
                return '';
            }
            $v = (string) preg_replace($multiline ? '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u' : '/[\x00-\x1F\x7F]/u', ' ', $v);
            $v = $multiline ? trim((string) preg_replace('/[ \t]+/u', ' ', strip_tags($v))) : trim((string) preg_replace('/\s+/u', ' ', $v));

            return mb_substr($v, 0, $max);
        };
        $year = null;
        if (is_string($date) && preg_match('/\b(1[0-9]{3}|20[0-9]{2})\b/', $date, $m)) {
            $year = (int) $m[1];
        }
        $pageCount = is_int($pages) || (is_string($pages) && ctype_digit($pages)) ? (int) $pages : null;
        $language = is_string($language) ? self::languageCode($language) : '';
        $names = [];
        foreach (array_slice($authors, 0, 20) as $name) {
            $name = $text($name, 255);
            if ($name !== '' && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return new self(
            $text($title, 500),
            $text($subtitle, 500),
            $names,
            $text($publisher, 255),
            $year,
            $pageCount !== null && $pageCount >= 1 && $pageCount <= 65535 ? $pageCount : null,
            $language,
            $text($description, 10000, true),
            is_string($coverUrl) ? $coverUrl : '',
        );
    }

    /**
     * Maps ISO 639-1/639-2 (bibliographic and terminology) codes onto the
     * catalogue's language vocabulary; unknown languages map to ''.
     */
    public static function languageCode(string $code): string
    {
        $code = strtolower(trim($code));
        $map = [
            'eng' => 'en', 'spa' => 'es', 'glg' => 'gl', 'por' => 'pt', 'cat' => 'ca', 'baq' => 'eu', 'eus' => 'eu',
            'fre' => 'fr', 'fra' => 'fr', 'ger' => 'de', 'deu' => 'de', 'ita' => 'it', 'lat' => 'la', 'dut' => 'nl',
            'nld' => 'nl', 'rus' => 'ru', 'jpn' => 'ja', 'chi' => 'zh', 'zho' => 'zh', 'ara' => 'ar',
        ];
        $code = $map[$code] ?? explode('-', $code)[0];

        return in_array($code, Vocabulary::LANGUAGES, true) ? $code : '';
    }

    public function isEmpty(): bool
    {
        return $this->title === '';
    }

    /**
     * Field-by-field fallback: values from $this win, gaps are filled from $other.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            $this->title !== '' ? $this->title : $other->title,
            $this->subtitle !== '' ? $this->subtitle : $other->subtitle,
            $this->authors !== [] ? $this->authors : $other->authors,
            $this->publisher !== '' ? $this->publisher : $other->publisher,
            $this->publicationYear ?? $other->publicationYear,
            $this->pageCount ?? $other->pageCount,
            $this->language !== '' ? $this->language : $other->language,
            $this->description !== '' ? $this->description : $other->description,
            $this->coverUrl !== '' ? $this->coverUrl : $other->coverUrl,
        );
    }
}
