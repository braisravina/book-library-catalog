<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

use DigitalLibrary\Catalogue\Isbn;

/**
 * Plausibility checks on one provider record. They inform the review; they
 * do not decide what a valid book is. A record is "questionable" (shown,
 * not used to pre-fill) only on strong, explainable signals; single unusual
 * traits (no author, a rare title, one provider only) are just warnings.
 * No scoring and no statistical or AI classification: every code below is
 * a plain rule with a message the user can read.
 */
final class MetadataChecks
{
    /** Title, subtitle or publisher reads like an advertisement (e.g. "Discount Code"). */
    public const PROMOTIONAL = 'promotional';
    /** The title is also the publisher and there is no author. */
    public const TITLE_IS_PUBLISHER_NO_AUTHOR = 'title_is_publisher_no_author';
    /** Publication year after next year. */
    public const DATE_FUTURE = 'date_future';
    /** Publication year before ISBNs existed (or before the 979 prefix was in use). */
    public const DATE_BEFORE_ISBN = 'date_before_isbn';
    /** No author or contributor at all. */
    public const NO_AUTHORS = 'no_authors';
    /** Open Library: created in the last 30 days with no source record (import provenance). */
    public const RECENT_UNSOURCED = 'recent_unsourced';
    /** Open Library: other records (other works) claim the same ISBN. */
    public const ISBN_SHARED = 'isbn_shared';
    /** Some of the provider's endpoints could not be read: data may be partial. */
    public const INCOMPLETE = 'incomplete';

    /** Codes that make a record questionable on their own. */
    public const QUESTIONABLE = [self::PROMOTIONAL, self::TITLE_IS_PUBLISHER_NO_AUTHOR, self::DATE_FUTURE];

    /** Phrases that do not belong in a book title or imprint (case-insensitive). */
    private const PROMOTIONAL_PATTERN = '/\b(?:discount|promo(?:tion(?:al)?)?|coupon|voucher|referral|bonus)\s+codes?\b|\b\d{1,3}\s?%\s?off\b|\bfree\s+(?:spins|bets?)\b|https?:\/\/|\bwww\.[a-z0-9-]+\./iu';

    /**
     * @param array{created?: ?string, sources?: ?int, shared?: bool, incomplete?: bool} $signals
     *        provider facts: creation date (ISO 8601), number of source records,
     *        whether other records claim the ISBN, whether endpoints failed
     *
     * @return list<string>
     */
    public static function inspect(BookMetadata $m, Isbn $isbn, array $signals = [], ?int $currentYear = null, ?int $now = null): array
    {
        if ($m->isEmpty()) {
            return ($signals['shared'] ?? false) ? [self::ISBN_SHARED] : [];
        }
        $currentYear ??= (int) gmdate('Y');
        $now ??= time();
        $warnings = [];
        $title = self::plain($m->title);
        $publisher = self::plain($m->publisher);
        foreach ([$m->title, $m->subtitle, $m->publisher, mb_substr($m->description, 0, 300)] as $i => $text) {
            // The description is only checked for the explicit phrases, never for links.
            $pattern = $i === 3 ? '/\b(?:discount|promo(?:tion(?:al)?)?|coupon|voucher|referral)\s+codes?\b/iu' : self::PROMOTIONAL_PATTERN;
            if ($text !== '' && preg_match($pattern, $text) === 1) {
                $warnings[] = self::PROMOTIONAL;
                break;
            }
        }
        if ($m->authors === []) {
            $warnings[] = self::NO_AUTHORS;
            if ($title !== '' && $title === $publisher) {
                $warnings[] = self::TITLE_IS_PUBLISHER_NO_AUTHOR;
            }
        }
        if ($m->publicationYear !== null) {
            if ($m->publicationYear > $currentYear + 1) {
                $warnings[] = self::DATE_FUTURE;
            } elseif ($m->publicationYear < 1966 || (str_starts_with($isbn->isbn13, '979') && $m->publicationYear < 2007)) {
                $warnings[] = self::DATE_BEFORE_ISBN;
            }
        }
        $created = $signals['created'] ?? null;
        if (is_string($created) && ($signals['sources'] ?? 1) === 0) {
            $time = strtotime($created . (preg_match('/[zZ]|[+-]\d\d:?\d\d$/', $created) ? '' : ' UTC'));
            if ($time !== false && $time > $now - 30 * 86400) {
                $warnings[] = self::RECENT_UNSOURCED;
            }
        }
        if ($signals['shared'] ?? false) {
            $warnings[] = self::ISBN_SHARED;
        }
        if ($signals['incomplete'] ?? false) {
            $warnings[] = self::INCOMPLETE;
        }

        return array_values(array_unique($warnings));
    }

    private static function plain(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/[\s\p{P}]+/u', ' ', $value)));
    }
}
