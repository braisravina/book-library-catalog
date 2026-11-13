<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Backoff;

/**
 * Open Library allows 1 request per second from an application that does
 * not identify itself with a contact (https://openlibrary.org/developers/api,
 * "Rate Limits"). The next free slot is kept in
 * storage/cache/metadata-pace-<provider>.json under an exclusive lock, so
 * concurrent lookups share it. A required request waits at most
 * $maxWaitSeconds; an optional one (enrichment) only takes a free slot.
 * Any I/O problem means no pacing: it must never block a lookup.
 */
final class FileRequestPacer implements RequestPacer
{
    /** @var \Closure(): float */
    private readonly \Closure $clock;
    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    public function __construct(
        private readonly string $directory,
        private readonly float $intervalSeconds = 1.0,
        private readonly float $maxWaitSeconds = 1.5,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1_000_000));
        };
    }

    public function acquire(string $provider, bool $optional): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $provider)) {
            throw new \InvalidArgumentException('Invalid provider name');
        }
        if (!is_dir($this->directory)) {
            return true;
        }
        $file = rtrim($this->directory, '/') . '/metadata-pace-' . $provider . '.json';
        $handle = @fopen($file, 'c+b');
        if ($handle === false) {
            return true;
        }
        $wait = 0.0;
        try {
            if (!flock($handle, LOCK_EX)) {
                return true;
            }
            @chmod($file, 0600);
            $data = json_decode((string) stream_get_contents($handle), true);
            $now = ($this->clock)();
            $stored = is_array($data) ? ($data['next'] ?? null) : null;
            $next = is_int($stored) || is_float($stored) ? (float) $stored : $now;
            // A slot more than one interval in the past or far in the future (clock change, corruption) is reset.
            if ($next < $now - $this->intervalSeconds || $next > $now + 60) {
                $next = $now;
            }
            $slot = max($now, $next);
            $wait = $slot - $now;
            if ($wait > ($optional ? 0.0 : $this->maxWaitSeconds)) {
                return false;
            }
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) json_encode(['next' => $slot + $this->intervalSeconds], JSON_PRESERVE_ZERO_FRACTION));
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        if ($wait > 0) {
            ($this->sleep)($wait);
        }

        return true;
    }
}
