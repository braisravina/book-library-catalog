<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata;

final class LookupResult
{
    /**
     * @param list<string> $sources   providers that contributed, in priority order
     * @param list<string> $failures  providers that could not be reached
     */
    public function __construct(
        public readonly BookMetadata $metadata,
        public readonly array $sources,
        public readonly array $failures,
        public readonly ?int $primaryRecordId,
    ) {
    }

    public function found(): bool
    {
        return !$this->metadata->isEmpty();
    }
}
