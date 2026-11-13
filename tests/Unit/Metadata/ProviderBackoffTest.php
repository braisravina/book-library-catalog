<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Metadata\Backoff\FileBackoffStore;
use DigitalLibrary\Metadata\Backoff\ProviderBackoff;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Tests\Support\InMemoryBackoffStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProviderBackoffTest extends TestCase
{
    private int $now = 1_800_000_000;
    private string $dir = '';

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }
    }

    private function backoff(?InMemoryBackoffStore $store = null): ProviderBackoff
    {
        return new ProviderBackoff($store ?? new InMemoryBackoffStore(), fn (): int => $this->now);
    }

    /**
     * @return iterable<string, array{?int, bool}>
     */
    public static function statuses(): iterable
    {
        foreach ([429, 500, 502, 503, 504] as $status) {
            yield 'HTTP ' . $status => [$status, true];
        }
        foreach ([400, 401, 403, 404, 410, 501, 505] as $status) {
            yield 'HTTP ' . $status => [$status, false];
        }
        yield 'transport error' => [null, false];
    }

    #[DataProvider('statuses')]
    public function testOnlyRateLimitsAndTemporaryServerErrorsAreRetryable(?int $status, bool $retryable): void
    {
        self::assertSame($retryable, ProviderBackoff::isRetryable(new HttpException('x', $status)));
    }

    public function testPauseDoublesAndIsBounded(): void
    {
        $backoff = $this->backoff();
        $pauses = [];
        for ($i = 0; $i < 8; $i++) {
            $pauses[] = $backoff->recordFailure('google_books', null);
        }
        self::assertSame([30, 60, 120, 240, 480, 900, 900, 900], $pauses);
        self::assertSame(900, $backoff->remaining('google_books'));
        self::assertSame(0, $backoff->remaining('open_library'), 'Backoff is per provider');
    }

    public function testRetryAfterIsHonouredWithinLimits(): void
    {
        $backoff = $this->backoff();
        self::assertSame(120, $backoff->recordFailure('p', '120'));
        self::assertSame(900, $backoff->recordFailure('p', '86400'), 'Capped at the maximum');
        self::assertSame(5, $backoff->recordFailure('p', '0'), 'Raised to the minimum');
        $date = gmdate('D, d M Y H:i:s', $this->now + 300) . ' GMT';
        self::assertSame(300, $backoff->recordFailure('p', $date), 'HTTP-date form');
        self::assertSame(480, $backoff->recordFailure('p', 'soon'), 'Unusable value: exponential pause (5th failure)');
    }

    public function testPauseExpiresAndSuccessResets(): void
    {
        $store = new InMemoryBackoffStore();
        $backoff = $this->backoff($store);
        $backoff->recordFailure('p', null);
        $backoff->recordFailure('p', null);
        self::assertSame(60, $backoff->remaining('p'));
        $this->now += 61;
        self::assertSame(0, $backoff->remaining('p'));
        self::assertSame(120, $backoff->recordFailure('p', null), 'Consecutive failures keep counting');
        $backoff->recordSuccess('p');
        self::assertSame([], $store->states);
        self::assertSame(30, $backoff->recordFailure('p', null), 'Starts again from the base');
    }

    public function testRetryAfterParsing(): void
    {
        self::assertNull(ProviderBackoff::retryAfterSeconds(null, $this->now));
        self::assertNull(ProviderBackoff::retryAfterSeconds('', $this->now));
        self::assertNull(ProviderBackoff::retryAfterSeconds('-5', $this->now));
        self::assertNull(ProviderBackoff::retryAfterSeconds('12345678901', $this->now));
        self::assertSame(7, ProviderBackoff::retryAfterSeconds(' 7 ', $this->now));
        self::assertSame(0, ProviderBackoff::retryAfterSeconds(gmdate('D, d M Y H:i:s', $this->now - 60) . ' GMT', $this->now));
    }

    public function testFileStoreSharesStateAndDegradesSafely(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-backoff-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
        $first = new ProviderBackoff(new FileBackoffStore($this->dir), fn (): int => $this->now);
        $second = new ProviderBackoff(new FileBackoffStore($this->dir), fn (): int => $this->now);

        $first->recordFailure('open_library', '90');
        self::assertSame(90, $second->remaining('open_library'), 'Another worker sees the pause');
        $file = $this->dir . '/metadata-backoff-open_library.json';
        self::assertSame(['failures' => 1, 'until' => $this->now + 90], json_decode((string) file_get_contents($file), true));
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));

        file_put_contents($file, 'not json');
        self::assertSame(0, $second->remaining('open_library'), 'Corrupt state means no backoff');
        $second->recordSuccess('open_library');
        $first->recordFailure('open_library', null);
        $second->recordSuccess('open_library');
        self::assertSame('', file_get_contents($file));
        self::assertSame(0, $first->remaining('open_library'));

        $missing = new ProviderBackoff(new FileBackoffStore($this->dir . '/missing'), fn (): int => $this->now);
        self::assertSame(0, $missing->recordFailure('open_library', null), 'No pause can be recorded');
        self::assertSame(0, $missing->remaining('open_library'), 'Without a usable directory there is simply no backoff');
        self::assertFileDoesNotExist($this->dir . '/missing');
    }

    public function testFileStoreRefusesUnsafeProviderNames(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FileBackoffStore(sys_get_temp_dir()))->read('../etc/passwd');
    }
}
