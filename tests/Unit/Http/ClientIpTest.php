<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Http;

use DigitalLibrary\Http\ClientIp;
use DigitalLibrary\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientIpTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function valid(): iterable
    {
        yield 'IPv4' => ['192.0.2.10', '192.0.2.10'];
        yield 'IPv6 loopback' => ['::1', '::1'];
        yield 'IPv6 upper case, expanded' => ['2001:DB8:0:0:0:0:0:1', '2001:db8::1'];
        yield 'IPv6 partly compressed' => ['2001:db8:0:0::1', '2001:db8::1'];
        yield 'IPv4-mapped IPv6' => ['::ffff:192.0.2.5', '192.0.2.5'];
        yield 'IPv4-mapped IPv6, hex' => ['::FFFF:C000:0205', '192.0.2.5'];
        yield 'IPv4-compatible (not mapped)' => ['::192.0.2.1', '::192.0.2.1'];
    }

    #[DataProvider('valid')]
    public function testNormalizesToOneCanonicalForm(string $input, string $expected): void
    {
        self::assertSame($expected, ClientIp::normalize($input));
    }

    /**
     * @return iterable<string, array{?string}>
     */
    public static function invalid(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'leading space' => [' 192.0.2.1'];
        yield 'trailing newline' => ["192.0.2.1\n"];
        yield 'list' => ['192.0.2.1, 198.51.100.1'];
        yield 'with port' => ['192.0.2.1:443'];
        yield 'bracketed IPv6' => ['[::1]'];
        yield 'zone id' => ['fe80::1%eth0'];
        yield 'octet out of range' => ['256.1.1.1'];
        yield 'too few octets' => ['192.0.2'];
        yield 'leading zeros (octal ambiguity)' => ['010.0.0.1'];
        yield 'hex IPv4' => ['0x7f.0.0.1'];
        yield 'CIDR' => ['192.0.2.1/32'];
        yield 'host name' => ['localhost'];
        yield 'unix socket' => ['unix:'];
        yield 'NUL' => ["192.0.2.1\0"];
        yield 'overlong' => [str_repeat('1', 46)];
    }

    #[DataProvider('invalid')]
    public function testRejectsAnythingButOneAddress(?string $input): void
    {
        self::assertNull(ClientIp::normalize($input));
    }

    public function testThrottleKeys(): void
    {
        self::assertSame('192.0.2.10', ClientIp::throttleKey('192.0.2.10'));
        self::assertSame('192.0.2.5', ClientIp::throttleKey('::ffff:192.0.2.5'), 'Mapped and plain IPv4 share a bucket');
        self::assertSame('2001:db8:1:2::/64', ClientIp::throttleKey('2001:db8:1:2:aaaa:bbbb:cccc:dddd'));
        self::assertSame(ClientIp::throttleKey('2001:db8:1:2::1'), ClientIp::throttleKey('2001:DB8:1:2:ffff::9'), 'One /64, one bucket');
        self::assertNotSame(ClientIp::throttleKey('2001:db8:1:2::1'), ClientIp::throttleKey('2001:db8:1:3::1'), 'Neighbouring /64s are separate');
        self::assertNotSame(ClientIp::throttleKey('192.0.2.10'), ClientIp::throttleKey('192.0.2.11'));
        self::assertSame(ClientIp::UNKNOWN, ClientIp::throttleKey('not an address'));
        self::assertLessThanOrEqual(45, strlen(ClientIp::throttleKey('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff')), 'Fits login_attempts.ip_address');
    }

    public function testRequestUsesOnlyTheNormalizedRemoteAddress(): void
    {
        $forged = [
            'HTTP_X_FORWARDED_FOR' => '203.0.113.66', 'HTTP_CF_CONNECTING_IP' => '203.0.113.66',
            'HTTP_FORWARDED' => 'for=203.0.113.66', 'HTTP_X_REAL_IP' => '203.0.113.66', 'HTTP_TRUE_CLIENT_IP' => '203.0.113.66',
        ];
        $request = static fn (array $server): Request => new Request('GET', '/', [], [], $server);

        self::assertSame('192.0.2.10', $request(['REMOTE_ADDR' => '192.0.2.10'] + $forged)->remoteAddress());
        self::assertSame('2001:db8::1', $request(['REMOTE_ADDR' => '2001:DB8::0:1'])->remoteAddress());
        self::assertSame(ClientIp::UNKNOWN, $request($forged)->remoteAddress(), 'Missing REMOTE_ADDR: forwarding headers are not a fallback');
        self::assertSame(ClientIp::UNKNOWN, $request(['REMOTE_ADDR' => '203.0.113.66, 192.0.2.10'])->remoteAddress());
        self::assertSame(ClientIp::UNKNOWN, $request(['REMOTE_ADDR' => ['192.0.2.10']])->remoteAddress());
    }
}
