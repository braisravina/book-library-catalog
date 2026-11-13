<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

/**
 * One provider's answer for one ISBN, as the review needs it: the
 * normalised data, where each field came from within the provider, its
 * identifiers, and the plausibility warnings (MetadataChecks). All of it is
 * untrusted external data; it only ever pre-fills a form the user reviews.
 */
final class ProviderRecord
{
    /**
     * @param array<string, string> $fieldOrigins  field => origin within the provider
     *                                              (Open Library: edition, work, search)
     * @param array<string, string> $identifiers   e.g. ol_edition => OL26222911M, ol_work => OL17618370W
     * @param list<string>          $warnings      MetadataChecks codes
     * @param list<array{edition: string, work: string, title: string, authors: list<string>}> $candidates
     *        other records of the same provider that claim this ISBN
     * @param list<string>          $incomplete    endpoints that could not be read (data may be partial)
     */
    public function __construct(
        public readonly string $provider,
        public readonly BookMetadata $metadata,
        public readonly array $fieldOrigins = [],
        public readonly array $identifiers = [],
        public readonly array $warnings = [],
        public readonly array $candidates = [],
        public readonly array $incomplete = [],
    ) {
    }

    /**
     * Strong signals that this record does not describe the book: it is
     * shown for review but never used to pre-fill the form.
     */
    public function questionable(): bool
    {
        return array_intersect($this->warnings, MetadataChecks::QUESTIONABLE) !== [];
    }

    public function usable(): bool
    {
        return !$this->metadata->isEmpty() && !$this->questionable();
    }
}
