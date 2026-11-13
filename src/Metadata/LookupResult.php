<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

final class LookupResult
{
    /**
     * @param list<string>                $sources    providers that contributed, in priority order
     * @param list<string>                $failures   providers that could not be reached (includes $throttled)
     * @param array<string, BookMetadata> $byProvider each contributing provider's own data, in priority order
     * @param list<string>                $throttled  providers rate-limited or cooling down after server errors
     */
    public function __construct(
        public readonly BookMetadata $metadata,
        public readonly array $sources,
        public readonly array $failures,
        public readonly ?int $primaryRecordId,
        public readonly array $byProvider = [],
        public readonly array $throttled = [],
    ) {
    }

    public function found(): bool
    {
        return !$this->metadata->isEmpty();
    }
}
