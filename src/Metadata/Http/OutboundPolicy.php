<?php

declare(strict_types=1);

namespace DigitalLibrary\Metadata\Http;

/**
 * Which outbound URLs the application may fetch. Only HTTPS, default port,
 * no credentials, and hosts that are (subdomains of) an allowlisted domain.
 * Applied to the initial URL and to every redirect hop.
 */
final class OutboundPolicy
{
    /**
     * @param list<string> $allowedDomains e.g. "openlibrary.org" also allows "covers.openlibrary.org"
     */
    public function __construct(private readonly array $allowedDomains)
    {
    }

    public static function defaults(): self
    {
        return new self([
            'openlibrary.org',      // Books API and covers.openlibrary.org
            'archive.org',          // Open Library cover redirects (*.us.archive.org)
            'googleapis.com',       // www.googleapis.com/books/v1
            'books.google.com',     // Google Books cover images
            'googleusercontent.com',
        ]);
    }

    public function allows(string $url): bool
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url)) {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            return false;
        }
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return false; // never literal IP addresses
        }
        foreach ($this->allowedDomains as $domain) {
            if ($host === $domain || str_ends_with($host, '.' . $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves a Location header against the current URL (absolute or
     * path-absolute only).
     */
    public static function resolveRedirect(string $currentUrl, string $location): ?string
    {
        $location = trim($location);
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $p = parse_url($currentUrl);

            return is_array($p) ? 'https://' . ($p['host'] ?? '') . $location : null;
        }

        return null;
    }
}
