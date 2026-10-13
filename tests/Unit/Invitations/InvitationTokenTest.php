<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Invitations;

use DigitalLibrary\Invitations\InvitationToken;
use PHPUnit\Framework\TestCase;

final class InvitationTokenTest extends TestCase
{
    public function testTokensAreRandomUrlSafeAndHashed(): void
    {
        $tokens = [];
        for ($i = 0; $i < 200; $i++) {
            $token = InvitationToken::generate();
            self::assertTrue(InvitationToken::isWellFormed($token), $token);
            self::assertSame(32, strlen((string) base64_decode(strtr($token, '-_', '+/') . '=', true)), '256 bits of randomness');
            $tokens[$token] = true;
        }
        self::assertCount(200, $tokens);
        self::assertSame(hash('sha256', 'abc'), InvitationToken::hash('abc'));
        foreach (['', 'short', str_repeat('a', 44), str_repeat('a', 42) . '=', str_repeat('a', 42) . '/', str_repeat('a', 42) . "\n"] as $bad) {
            self::assertFalse(InvitationToken::isWellFormed($bad), json_encode($bad));
        }
    }
}
