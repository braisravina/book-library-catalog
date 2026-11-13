<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Catalogue\Isbn;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Metadata\Backoff\FileRequestPacer;
use DigitalLibrary\Metadata\Http\CurlHttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\Provider\GoogleBooksProvider;
use DigitalLibrary\Tests\Support\FakeHttpClient;
use PHPUnit\Framework\TestCase;

final class RequestPacerAndTransportTest extends TestCase
{
    private string $dir = '';
    private float $now = 1000.0;
    /** @var list<float> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dla-pacer-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    private function pacer(?string $dir = null): FileRequestPacer
    {
        return new FileRequestPacer($dir ?? $this->dir, 1.0, 1.5, fn (): float => $this->now, function (float $s): void {
            $this->slept[] = round($s, 3);
        });
    }

    public function testRequestsAreSpacedOneSecondApartAcrossWorkers(): void
    {
        $a = $this->pacer();
        $b = $this->pacer(); // another PHP worker, same state file
        self::assertTrue($a->acquire('open_library', false));
        self::assertTrue($b->acquire('open_library', false), 'Waits for the next slot');
        self::assertSame([1.0], $this->slept);
        self::assertFalse($a->acquire('open_library', true), 'An optional request never waits');
        self::assertFalse($b->acquire('open_library', false), 'A required request waits at most 1.5 s, then gives up');
        $this->now += 5;
        self::assertTrue($b->acquire('open_library', true), 'Free slot');
        self::assertTrue($a->acquire('google_books', false), 'Per provider');
        self::assertSame([1.0], $this->slept);
        $file = $this->dir . '/metadata-pace-open_library.json';
        self::assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
    }

    public function testCorruptOrUnusableStateNeverBlocks(): void
    {
        file_put_contents($this->dir . '/metadata-pace-open_library.json', 'garbage');
        self::assertTrue($this->pacer()->acquire('open_library', true));
        file_put_contents($this->dir . '/metadata-pace-open_library.json', '{"next": 9999999999.5}');
        self::assertTrue($this->pacer()->acquire('open_library', true), 'A slot far in the future (clock change) is reset');
        self::assertTrue($this->pacer($this->dir . '/missing')->acquire('open_library', false), 'No directory: no pacing');
        self::assertSame([], $this->slept);
        $this->expectException(\InvalidArgumentException::class);
        $this->pacer()->acquire('../x', false);
    }

    public function testCurlErrorsAreClassified(): void
    {
        self::assertSame(HttpException::DNS, CurlHttpClient::kindOf(6));
        self::assertSame(HttpException::TIMEOUT, CurlHttpClient::kindOf(28));
        foreach ([35, 51, 60, 77] as $tls) {
            self::assertSame(HttpException::TLS, CurlHttpClient::kindOf($tls), (string) $tls);
        }
        self::assertSame(HttpException::NETWORK, CurlHttpClient::kindOf(7), 'connection refused');
        self::assertSame(HttpException::NETWORK, CurlHttpClient::kindOf(56), 'connection reset');
    }

    public function testTheUserAgentCarriesAContactOnlyWhenConfigured(): void
    {
        self::assertSame('DigitalLibraryArchive/1.0 (private catalogue)', Services::userAgent(null));
        self::assertSame('DigitalLibraryArchive/1.0 (private catalogue)', Services::userAgent(''));
        self::assertSame('DigitalLibraryArchive/1.0 (private catalogue; library@example.org)', Services::userAgent('library@example.org'));
        self::assertSame('DigitalLibraryArchive/1.0 (private catalogue; https://example.org/about)', Services::userAgent('https://example.org/about'));
        foreach (["x@example.org\r\nX-Injected: 1", 'not a contact', 'http://insecure.example', 'javascript:alert(1)'] as $bad) {
            self::assertSame('DigitalLibraryArchive/1.0 (private catalogue)', Services::userAgent($bad), $bad);
        }
    }

    public function testGoogleDailyQuotaAsksForTheLongestBoundedPause(): void
    {
        $isbn = Isbn::tryParse('9780132350884') ?? throw new \LogicException();
        $daily = '{"error":{"code":429,"message":"Quota exceeded for quota metric \'Queries\' and limit \'Queries per day\'"}}';
        foreach ([[$daily, [], '86400'], ['{"error":{"code":429}}', [], null], [$daily, ['retry-after' => '20'], '20']] as [$body, $headers, $expected]) {
            try {
                (new GoogleBooksProvider((new FakeHttpClient())->onPrefix('https://www.googleapis.com/', new HttpResponse(429, $body, 'application/json', $headers))))->fetch($isbn);
                self::fail('Expected a rate limit');
            } catch (HttpException $e) {
                self::assertSame([429, $expected], [$e->status, $e->retryAfter]);
            }
        }
        try {
            (new GoogleBooksProvider((new FakeHttpClient())->onPrefix('https://www.googleapis.com/', new HttpResponse(200, '{"items": "x"}'))))->fetch($isbn);
            self::fail('Expected an invalid response');
        } catch (HttpException $e) {
            self::assertSame(HttpException::INVALID, $e->kind);
        }
    }
}
