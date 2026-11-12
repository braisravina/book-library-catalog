<?php

declare(strict_types=1);

namespace DigitalLibrary\Http;

/**
 * Which Host header values the application answers to: exactly the host
 * and port of APP_URL.
 *
 *  - Host names compare case-insensitively (RFC 9110); the port must match
 *    the effective APP_URL port (an explicit default port such as ":443"
 *    for https is equivalent to none).
 *  - Malformed or ambiguous values are refused: empty, several hosts,
 *    user-info, paths, trailing dots, leading-zero or out-of-range ports,
 *    whitespace or control characters, IPv6 literals.
 *  - X-Forwarded-Host and similar headers are never consulted; the
 *    application builds every absolute URL from APP_URL, never from
 *    request headers.
 */
final class HostPolicy
{
    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $defaultPort,
    ) {
    }

    /**
     * Policy for a valid APP_URL, or null when APP_URL is missing or unusable
     * (ConfigValidator reports that; production refuses to start).
     */
    public static function fromAppUrl(?string $appUrl): ?self
    {
        if ($appUrl === null || $appUrl === '') {
            return null;
        }
        $parts = parse_url($appUrl);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (!self::isHostName($host)) {
            return null;
        }
        $default = $scheme === 'https' ? 443 : 80;

        return new self($host, (int) ($parts['port'] ?? $default), $default);
    }

    public function host(): string
    {
        return $this->host;
    }

    public function allows(?string $hostHeader): bool
    {
        if ($hostHeader === null || strlen($hostHeader) > 260) {
            return false;
        }
        if (preg_match('/^(?<host>[^:]+)(?::(?<port>[1-9][0-9]{0,4}))?$/D', $hostHeader, $m) !== 1) {
            return false;
        }
        $host = strtolower($m['host']);
        if (!self::isHostName($host)) {
            return false;
        }
        $port = isset($m['port']) && $m['port'] !== '' ? (int) $m['port'] : $this->defaultPort;
        if ($port > 65535) {
            return false;
        }

        return hash_equals($this->host, $host) && $port === $this->port;
    }

    private static function isHostName(string $host): bool
    {
        return strlen($host) <= 253
            && preg_match('/^' . self::LABEL . '(?:\.' . self::LABEL . ')*$/D', $host) === 1;
    }
}
