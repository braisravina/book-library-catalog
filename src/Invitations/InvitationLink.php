<?php

declare(strict_types=1);

namespace DigitalLibrary\Invitations;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigException;

/**
 * Builds the invitation link shown once to the administrator.
 *
 *  - "query" (default, Phase 4B format): APP_URL/invite?token=TOKEN.
 *    The token is in the request line of one GET request, so it can reach
 *    proxy/CDN request logs and web-server error logs.
 *  - "fragment": APP_URL/invite/redeem#TOKEN. Browsers never send the
 *    fragment to any server (nor in Referer headers); a same-origin script
 *    posts it with the CSRF token, and a paste-in form works without
 *    JavaScript. The token never appears in any request line.
 *
 * Both formats redeem the same tokens: the setting only changes how new
 * links are written, so it can be switched without invalidating links.
 */
final class InvitationLink
{
    public const QUERY = 'query';
    public const FRAGMENT = 'fragment';

    public function __construct(
        private readonly string $appUrl,
        private readonly string $format = self::QUERY,
    ) {
        if (!in_array($format, [self::QUERY, self::FRAGMENT], true)) {
            throw new ConfigException('INVITATION_LINK_FORMAT must be "query" or "fragment".');
        }
    }

    public static function fromConfig(Config $config): self
    {
        return new self((string) $config->get('APP_URL', ''), (string) $config->get('INVITATION_LINK_FORMAT', self::QUERY));
    }

    public function format(): string
    {
        return $this->format;
    }

    public function build(#[\SensitiveParameter] string $token): string
    {
        $base = rtrim($this->appUrl, '/');

        return $this->format === self::FRAGMENT
            ? $base . '/invite/redeem#' . $token
            : $base . '/invite?token=' . $token;
    }
}
