<?php

declare(strict_types=1);

namespace DigitalLibrary\Households;

final class TransferException extends \RuntimeException
{
    /** @param list<array{key: string, id: ?int}> $conflicts */
    public function __construct(public readonly array $conflicts)
    {
        parent::__construct('transfers.conflicts');
    }

    public static function because(string $key, ?int $id = null): self
    {
        return new self([['key' => 'transfers.error.' . $key, 'id' => $id]]);
    }
}
