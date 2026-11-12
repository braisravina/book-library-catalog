<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class PasswordTest extends TestCase
{
    public function testHashesWithArgon2id(): void
    {
        $hasher = new PasswordHasher(8192, 1);
        $hash = $hasher->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password here', $hash));
        self::assertFalse($hasher->needsRehash($hash));
        self::assertTrue((new PasswordHasher(16384, 1))->needsRehash($hash), 'Stronger parameters require rehash');
    }

    public function testBcryptHashesAreFlaggedForUpgrade(): void
    {
        $hasher = new PasswordHasher(8192, 1);
        self::assertTrue($hasher->needsRehash(password_hash('x', PASSWORD_BCRYPT)));
    }

    public function testPolicy(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame([], $policy->validate('a long memorable passphrase'));
        self::assertContains('validation.password_too_short', $policy->validate('short'));
        self::assertContains('validation.password_common', $policy->validate('password1234'));
        self::assertContains('validation.password_common', $policy->validate('aaaaaaaaaaaaaaa'));
        self::assertContains('validation.password_blank', $policy->validate(str_repeat(' ', 14)));
        self::assertContains('validation.password_too_long', $policy->validate(str_repeat('ab1-', 200)));
        self::assertContains('validation.password_common', $policy->validate('Someone@Example.org', 'someone@example.org'));
        self::assertSame([], $policy->validate('ñandú pingüino 🐧 biblioteca'), 'Unicode passphrases are allowed');
    }
}
