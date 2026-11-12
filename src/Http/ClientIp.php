<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

/**
 * The verified client address and the rate-limit bucket derived from it.
 *
 * Trust model: the application never reads forwarding headers
 * (X-Forwarded-For, CF-Connecting-IP, Forwarded, X-Real-IP, ...). Its only
 * input is REMOTE_ADDR, which Nginx sets to the TCP peer, or, for peers
 * explicitly listed in docker/nginx/client-ip.conf, to the address that
 * trusted proxy reported (real_ip module). Login throttling, invitation
 * throttling and the audit log all use the same normalised value.
 */
final class ClientIp
{
    /** Stand-in for a missing or malformed REMOTE_ADDR: one shared (fail-closed) bucket. */
    public const UNKNOWN = '0.0.0.0';

    /**
     * Canonical text form of a single IPv4/IPv6 address, or null when the
     * value is not exactly one valid address (no ports, brackets, zone ids,
     * lists, whitespace or leading zeros). IPv4-mapped IPv6 addresses
     * (::ffff:192.0.2.1) become plain IPv4, so one client has one identity.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 45 || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($value);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $text = inet_ntop($packed);

        return $text === false ? null : $text;
    }

    /**
     * Rate-limit bucket for an address: the address itself for IPv4, its
     * /64 network for IPv6 (a single subscriber usually controls a whole
     * /64, so per-address IPv6 buckets could be rotated through freely).
     */
    public static function throttleKey(string $ip): string
    {
        $canonical = self::normalize($ip);
        if ($canonical === null) {
            return self::UNKNOWN;
        }
        $packed = (string) inet_pton($canonical);
        if (strlen($packed) === 4) {
            return $canonical;
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }
}
