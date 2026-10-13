<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Provider;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Metadata\BookMetadata;

interface MetadataProvider
{
    /** Matches the metadata_records.provider ENUM. */
    public function name(): string;

    /**
     * Fetches the provider's raw payload for an ISBN.
     *
     * @return array<string, mixed>|null null when the provider has no record
     *
     * @throws \DigitalLibrary\Metadata\Http\HttpException on transport errors
     */
    public function fetch(Isbn $isbn): ?array;

    /**
     * Normalises a raw payload (fresh or cached) for the given ISBN.
     *
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload, Isbn $isbn): BookMetadata;
}
