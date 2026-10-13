<?php

declare(strict_types=1);

namespace DigitalLibrary\Upload;

final class StoredImage
{
    public function __construct(
        public readonly string $storageKey,
        public readonly string $mimeType,
        public readonly int $width,
        public readonly int $height,
        public readonly int $byteSize,
        public readonly string $sha256,
    ) {
    }
}
