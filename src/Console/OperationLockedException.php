<?php

declare(strict_types=1);

namespace DigitalLibrary\Console;

final class OperationLockedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $holder contents of the lock file (operation, pid, since)
     */
    public function __construct(public readonly array $holder)
    {
        $operation = is_string($holder['operation'] ?? null) && preg_match('/^[a-z:-]{1,40}$/', $holder['operation']) === 1 ? $holder['operation'] : 'unknown';
        $since = is_string($holder['since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $holder['since']) === 1 ? $holder['since'] : '?';
        parent::__construct(sprintf('Another operation is running (%s, since %s). Wait for it to finish; the lock is released automatically when that process exits.', $operation, $since));
    }
}
