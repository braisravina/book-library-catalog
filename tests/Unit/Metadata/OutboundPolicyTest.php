<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Metadata;

use DigitalLibrary\Metadata\Http\CurlHttpClient;
use DigitalLibrary\Metadata\Http\HttpException;
use DigitalLibrary\Metadata\Http\OutboundPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OutboundPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'open library api' => ['https://openlibrary.org/api/books?bibkeys=ISBN:9780306406157&format=json&jscmd=data', true];
        yield 'open library covers' => ['https://covers.openlibrary.org/b/id/1-L.jpg', true];
        yield 'archive.org redirect' => ['https://ia800100.us.archive.org/view_archive.php?archive=x', true];
        yield 'google books api' => ['https://www.googleapis.com/books/v1/volumes?q=isbn:9780306406157', true];
        yield 'google cover' => ['https://books.google.com/books/content?id=abc&img=1', true];
        yield 'plain http' => ['http://openlibrary.org/api/books', false];
        yield 'lookalike domain' => ['https://evilopenlibrary.org/x', false];
        yield 'suffix trick' => ['https://openlibrary.org.evil.example/x', false];
        yield 'credentials' => ['https://user:pass@openlibrary.org/x', false];
        yield 'credential host confusion' => ['https://openlibrary.org@evil.example/x', false];
        yield 'other port' => ['https://openlibrary.org:8443/x', false];
        yield 'metadata service ip' => ['https://169.254.169.254/latest/meta-data', false];
        yield 'loopback' => ['https://127.0.0.1/', false];
        yield 'ipv6 literal' => ['https://[::1]/', false];
        yield 'file scheme' => ['file:///etc/passwd', false];
        yield 'gopher' => ['gopher://openlibrary.org/', false];
        yield 'backslash' => ['https://openlibrary.org\\@evil.example/', false];
        yield 'whitespace' => ["https://openlibrary.org/ x", false];
        yield 'empty' => ['', false];
    }

    #[DataProvider('urls')]
    public function testAllowlist(string $url, bool $allowed): void
    {
        self::assertSame($allowed, OutboundPolicy::defaults()->allows($url));
    }

    public function testRedirectResolution(): void
    {
        self::assertSame('https://openlibrary.org/books/OL1M.json', OutboundPolicy::resolveRedirect('https://openlibrary.org/isbn/1.json', '/books/OL1M.json'));
        self::assertSame('http://evil.example/', OutboundPolicy::resolveRedirect('https://openlibrary.org/', 'http://evil.example/'), 'Absolute targets are returned as-is and then re-checked by the policy');
        self::assertNull(OutboundPolicy::resolveRedirect('https://openlibrary.org/', '//evil.example/'));
        self::assertNull(OutboundPolicy::resolveRedirect('https://openlibrary.org/', 'relative/path'));
    }

    public function testClientRefusesDisallowedUrlsBeforeAnyNetworkAccess(): void
    {
        $client = new CurlHttpClient(OutboundPolicy::defaults(), 2);
        foreach (['http://openlibrary.org/', 'https://169.254.169.254/', 'https://example.com/'] as $url) {
            try {
                $client->get($url, 1024);
                self::fail('Expected refusal for ' . $url);
            } catch (HttpException $e) {
                self::assertSame('URL refused by outbound policy', $e->getMessage());
            }
        }
    }
}
