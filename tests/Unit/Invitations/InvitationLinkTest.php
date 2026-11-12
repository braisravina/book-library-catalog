<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Invitations;

use DigitalLibrary\Core\Config;
use DigitalLibrary\Core\ConfigException;
use DigitalLibrary\Invitations\InvitationLink;
use DigitalLibrary\Invitations\InvitationToken;
use PHPUnit\Framework\TestCase;

final class InvitationLinkTest extends TestCase
{
    public function testQueryFormatIsTheDefault(): void
    {
        $token = InvitationToken::generate();
        $link = InvitationLink::fromConfig(new Config(['APP_URL' => 'https://library.example/']));

        self::assertSame(InvitationLink::QUERY, $link->format());
        self::assertSame('https://library.example/invite?token=' . $token, $link->build($token));
    }

    public function testFragmentFormatKeepsTheTokenAfterTheHash(): void
    {
        $token = InvitationToken::generate();
        $url = (new InvitationLink('https://library.example', InvitationLink::FRAGMENT))->build($token);

        self::assertSame('https://library.example/invite/redeem#' . $token, $url);
        $parts = parse_url($url);
        self::assertArrayNotHasKey('query', $parts);
        self::assertSame('/invite/redeem', $parts['path'], 'The path, which is sent to servers, holds no token');
        self::assertSame($token, $parts['fragment']);
    }

    public function testUnknownFormatsAreRejected(): void
    {
        $this->expectException(ConfigException::class);

        InvitationLink::fromConfig(new Config(['APP_URL' => 'https://library.example', 'INVITATION_LINK_FORMAT' => 'path']));
    }
}
