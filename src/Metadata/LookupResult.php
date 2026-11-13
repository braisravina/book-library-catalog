<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

final class LookupResult
{
    /** Outcome statuses that mean the provider was not read (or not trusted) this time. */
    public const UNAVAILABLE = ['cooling_down', 'rate_limited', 'server_error', 'unavailable', 'network', 'dns', 'timeout', 'tls', 'invalid', 'blocked', 'paced'];

    /**
     * @param list<string>                  $sources    usable providers that pre-filled the form, in priority order
     * @param list<string>                  $failures   providers that could not be read (includes $throttled)
     * @param array<string, BookMetadata>   $byProvider usable providers' own data, in priority order
     * @param list<string>                  $throttled  providers rate-limited or cooling down after server errors
     * @param array<string, ProviderRecord> $records    every provider that answered with data, including questionable ones
     * @param list<array{provider: string, status: string, cached: bool, retry_in: ?int, incomplete: bool}> $outcomes
     *        one per provider, in priority order. status: found, questionable, ambiguous,
     *        not_found, or one of UNAVAILABLE
     */
    public function __construct(
        public readonly BookMetadata $metadata,
        public readonly array $sources,
        public readonly array $failures,
        public readonly ?int $primaryRecordId,
        public readonly array $byProvider = [],
        public readonly array $throttled = [],
        public readonly array $records = [],
        public readonly array $outcomes = [],
    ) {
    }

    public function found(): bool
    {
        return !$this->metadata->isEmpty();
    }

    /**
     * Whether any provider's record was set aside as questionable.
     */
    public function hasQuestionable(): bool
    {
        foreach ($this->records as $record) {
            if ($record->questionable()) {
                return true;
            }
        }

        return false;
    }
}
