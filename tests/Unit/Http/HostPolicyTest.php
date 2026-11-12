<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Http;

use DigitalLibrary\Http\HostPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HostPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function cases(): iterable
    {
        // Production host behind HTTPS.
        $prod = 'https://library.bravina.co.uk';
        yield 'exact production host' => [$prod, 'library.bravina.co.uk', true];
        yield 'upper case' => [$prod, 'LIBRARY.Bravina.CO.UK', true];
        yield 'explicit default port' => [$prod, 'library.bravina.co.uk:443', true];
        yield 'other port' => [$prod, 'library.bravina.co.uk:8443', false];
        yield 'http port on https site' => [$prod, 'library.bravina.co.uk:80', false];
        yield 'parent domain' => [$prod, 'bravina.co.uk', false];
        yield 'subdomain' => [$prod, 'evil.library.bravina.co.uk', false];
        yield 'suffix look-alike' => [$prod, 'library.bravina.co.uk.evil.example', false];
        yield 'prefix look-alike' => [$prod, 'evillibrary.bravina.co.uk', false];
        yield 'trailing dot' => [$prod, 'library.bravina.co.uk.', false];
        yield 'empty' => [$prod, '', false];
        yield 'port only' => [$prod, ':443', false];
        yield 'empty port' => [$prod, 'library.bravina.co.uk:', false];
        yield 'leading-zero port' => [$prod, 'library.bravina.co.uk:0443', false];
        yield 'port zero' => [$prod, 'library.bravina.co.uk:0', false];
        yield 'port out of range' => [$prod, 'library.bravina.co.uk:99999', false];
        yield 'two ports' => [$prod, 'library.bravina.co.uk:443:443', false];
        yield 'two hosts' => [$prod, 'library.bravina.co.uk, evil.example', false];
        yield 'user info' => [$prod, 'user@library.bravina.co.uk', false];
        yield 'user info hiding a host' => [$prod, 'library.bravina.co.uk@evil.example', false];
        yield 'path' => [$prod, 'library.bravina.co.uk/evil', false];
        yield 'backslash' => [$prod, 'library.bravina.co.uk\\evil', false];
        yield 'space' => [$prod, 'library.bravina.co.uk ', false];
        yield 'CRLF injection' => [$prod, "library.bravina.co.uk\r\nX-Injected: 1", false];
        yield 'NUL byte' => [$prod, "library.bravina.co.uk\0.evil.example", false];
        yield 'percent-encoded' => [$prod, 'library%2ebravina.co.uk', false];
        yield 'IPv6 literal' => [$prod, '[::1]', false];
        yield 'IPv4 address' => [$prod, '127.0.0.1', false];
        yield 'underscore' => [$prod, 'library_bravina.co.uk', false];
        yield 'label with leading hyphen' => [$prod, '-library.bravina.co.uk', false];
        yield 'over-long' => [$prod, str_repeat('a', 300), false];

        // Local development over plain HTTP.
        $dev = 'http://books.test';
        yield 'dev host' => [$dev, 'books.test', true];
        yield 'dev host mixed case' => [$dev, 'Books.Test', true];
        yield 'dev explicit port 80' => [$dev, 'books.test:80', true];
        yield 'dev https port' => [$dev, 'books.test:443', false];
        yield 'dev localhost' => [$dev, 'localhost', false];

        // Explicit non-default port in APP_URL.
        $port = 'http://localhost:8080';
        yield 'matching explicit port' => [$port, 'localhost:8080', true];
        yield 'missing explicit port' => [$port, 'localhost', false];
        yield 'different explicit port' => [$port, 'localhost:8081', false];
    }

    #[DataProvider('cases')]
    public function testAllows(string $appUrl, string $host, bool $allowed): void
    {
        $policy = HostPolicy::fromAppUrl($appUrl);
        self::assertNotNull($policy);
        self::assertSame($allowed, $policy->allows($host));
    }

    public function testMissingHostIsRefused(): void
    {
        self::assertFalse(HostPolicy::fromAppUrl('https://library.bravina.co.uk')?->allows(null));
    }

    public function testHostNameIsNormalised(): void
    {
        self::assertSame('library.bravina.co.uk', HostPolicy::fromAppUrl('HTTPS://Library.Bravina.CO.UK/')?->host());
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function unusableAppUrls(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
        yield 'no scheme' => ['books.test'];
        yield 'ftp' => ['ftp://books.test'];
        yield 'no host' => ['https://'];
        yield 'IPv6 host' => ['https://[::1]'];
        yield 'underscore host' => ['https://bad_host.example'];
    }

    #[DataProvider('unusableAppUrls')]
    public function testUnusableAppUrlGivesNoPolicy(?string $appUrl): void
    {
        self::assertNull(HostPolicy::fromAppUrl($appUrl));
    }
}
