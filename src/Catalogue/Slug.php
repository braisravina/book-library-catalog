<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

final class Slug
{
    private const MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n', 'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ý' => 'y', 'ÿ' => 'y',
    ];

    /**
     * ASCII slug; deliberately independent of iconv/intl, whose
     * transliteration differs between C libraries (musl vs glibc).
     */
    public static function from(string $text, int $maxLength = 150): string
    {
        $slug = strtr(mb_strtolower(trim($text)), self::MAP);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim(substr($slug, 0, $maxLength), '-');

        return $slug === '' ? 'item' : $slug;
    }
}
