<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Support;

use DigitalLibrary\Metadata\Http\HttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\HttpResponse;
use DigitalLibrary\Metadata\Http\OutboundPolicy;

/**
 * Offline stand-in for the outbound HTTP client. Unknown URLs fail like a
 * network error. The real OutboundPolicy is enforced, so tests also prove
 * that only allowlisted URLs are ever requested.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var array<string, HttpResponse|\Throwable> */
    private array $routes = [];

    /** @var list<string> */
    public array $requested = [];

    public function __construct(private readonly OutboundPolicy $policy = new OutboundPolicy(['openlibrary.org', 'archive.org', 'googleapis.com', 'books.google.com', 'googleusercontent.com']))
    {
    }

    public function on(string $url, HttpResponse|\Throwable $response): self
    {
        $this->routes[$url] = $response;

        return $this;
    }

    public function onPrefix(string $prefix, HttpResponse|\Throwable $response): self
    {
        $this->routes['prefix:' . $prefix] = $response;

        return $this;
    }

    public function get(string $url, int $maxBytes): HttpResponse
    {
        if (!$this->policy->allows($url)) {
            throw new HttpException('URL refused by outbound policy');
        }
        $this->requested[] = $url;
        $response = $this->routes[$url] ?? null;
        if ($response === null) {
            foreach ($this->routes as $key => $candidate) {
                if (str_starts_with($key, 'prefix:') && str_starts_with($url, substr($key, 7))) {
                    $response = $candidate;
                    break;
                }
            }
        }
        if ($response === null) {
            throw new HttpException('offline: ' . $url);
        }
        if ($response instanceof \Throwable) {
            throw $response;
        }
        if (strlen($response->body) > $maxBytes) {
            throw new HttpException('Response exceeds size limit');
        }

        return $response;
    }
}
