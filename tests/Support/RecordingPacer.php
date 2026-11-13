<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

use DigitalLibrary\Metadata\Backoff\RequestPacer;

/**
 * Test pacer: never waits; records each acquisition and can refuse optional slots.
 */
final class RecordingPacer implements RequestPacer
{
    /** @var list<string> "provider:required" or "provider:optional" */
    public array $acquired = [];

    public bool $refuseOptional = false;

    public function acquire(string $provider, bool $optional): bool
    {
        if ($optional && $this->refuseOptional) {
            return false;
        }
        $this->acquired[] = $provider . ($optional ? ':optional' : ':required');

        return true;
    }
}
