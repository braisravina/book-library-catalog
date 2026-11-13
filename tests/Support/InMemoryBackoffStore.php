<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

use DigitalLibrary\Metadata\Backoff\BackoffStore;

/**
 * Process-local backoff state for tests (no files, no shared state).
 */
final class InMemoryBackoffStore implements BackoffStore
{
    /** @var array<string, array{failures: int, until: int}> */
    public array $states = [];

    public function read(string $provider): ?array
    {
        return $this->states[$provider] ?? null;
    }

    public function update(string $provider, callable $change): void
    {
        $state = $change($this->states[$provider] ?? null);
        if ($state === null) {
            unset($this->states[$provider]);
        } else {
            $this->states[$provider] = $state;
        }
    }
}
