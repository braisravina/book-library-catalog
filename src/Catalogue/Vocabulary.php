<?php

declare(strict_types=1);

namespace DigitalLibrary\Catalogue;

/**
 * Controlled vocabularies. Values are stored in the database; labels are
 * translation keys ("<group>.<value>"), never stored text.
 */
final class Vocabulary
{
    /** Edition languages offered in forms and filters (ISO 639-1 codes). */
    public const LANGUAGES = ['en', 'es', 'gl', 'pt', 'ca', 'eu', 'fr', 'de', 'it', 'la', 'nl', 'ru', 'ja', 'zh', 'ar'];

    /** Mirrors the editions.format ENUM. */
    public const FORMATS = ['hardcover', 'paperback', 'mass_market', 'spiral', 'leather', 'boxed_set', 'other'];

    /** Mirrors the copies.copy_condition ENUM. */
    public const CONDITIONS = ['new', 'fine', 'very_good', 'good', 'fair', 'poor'];

    /** Mirrors the copies.status ENUM. */
    public const STATUSES = ['available', 'on_loan', 'lost', 'withdrawn'];

    /** Mirrors the work_authors.role ENUM. */
    public const AUTHOR_ROLES = ['author', 'editor', 'compiler'];

    /** Mirrors the locations.kind ENUM. */
    public const LOCATION_KINDS = ['site', 'room', 'bookcase', 'shelf', 'box', 'other'];

    public const CURRENCIES = ['EUR', 'GBP', 'USD'];

    public static function in(string $value, array $allowed): bool
    {
        return in_array($value, $allowed, true);
    }
}
