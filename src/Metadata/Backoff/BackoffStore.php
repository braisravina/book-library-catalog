<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Backoff;

/**
 * Per-provider backoff state shared by all PHP workers.
 * A state is ['failures' => int, 'until' => unix timestamp].
 */
interface BackoffStore
{
    /**
     * @return array{failures: int, until: int}|null
     */
    public function read(string $provider): ?array;

    /**
     * Atomically replaces the state with $change(current state); a null
     * result clears it.
     *
     * @param callable(array{failures: int, until: int}|null): (array{failures: int, until: int}|null) $change
     */
    public function update(string $provider, callable $change): void;
}
