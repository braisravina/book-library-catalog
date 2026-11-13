<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Provider;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;
use DigitalLibrary\Metadata\ProviderRecord;

interface MetadataProvider
{
    /** Matches the metadata_records.provider ENUM. */
    public function name(): string;

    /**
     * Fetches the provider's raw payload for an ISBN (all of its endpoints).
     *
     * @return array<string, mixed>|null null when the provider has no record
     *
     * @throws \DigitalLibrary\Metadata\Http\HttpException when the provider cannot be read
     */
    public function fetch(Isbn $isbn): ?array;

    /**
     * Interprets a raw payload (fresh or cached) for the given ISBN,
     * including its plausibility warnings.
     *
     * @param array<string, mixed> $payload
     */
    public function describe(array $payload, Isbn $isbn): ProviderRecord;

    /**
     * Normalised data of a raw payload (describe()->metadata).
     *
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload, Isbn $isbn): BookMetadata;

    /**
     * Whether a cached payload is in the format this provider writes now
     * (older formats are refreshed on the next lookup).
     *
     * @param array<string, mixed> $payload
     */
    public function isCurrent(array $payload): bool;
}
