<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Backoff;

/**
 * Spaces requests to one provider across all PHP workers.
 */
interface RequestPacer
{
    /**
     * Reserves the next request slot for $provider and waits for it.
     * Returns false, without waiting, when the slot is further away than the
     * caller may wait: the caller then skips an optional request. Never
     * retries and never throws.
     */
    public function acquire(string $provider, bool $optional): bool;
}
