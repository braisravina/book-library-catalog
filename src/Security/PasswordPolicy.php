<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

/**
 * Length-based password policy in line with NIST SP 800-63B: no composition
 * rules, a generous maximum, and a rejection list for obvious choices.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;
    public const MAX_LENGTH = 512;

    private const COMMON = [
        'password1234', '123456789012', 'qwertyuiopas', 'passwordpassword',
        'administrator', 'letmein12345', 'iloveyou1234', 'library12345',
    ];

    /**
     * @return list<string> Translation keys describing each violation.
     */
    public function validate(#[\SensitiveParameter] string $password, string $email = ''): array
    {
        $errors = [];
        $length = mb_strlen($password);
        if ($length < self::MIN_LENGTH) {
            $errors[] = 'validation.password_too_short';
        }
        if ($length > self::MAX_LENGTH) {
            $errors[] = 'validation.password_too_long';
        }
        if (trim($password) === '') {
            $errors[] = 'validation.password_blank';
        }
        $lower = mb_strtolower($password);
        if (in_array($lower, self::COMMON, true) || ($email !== '' && $lower === mb_strtolower($email))) {
            $errors[] = 'validation.password_common';
        }
        if ($length >= self::MIN_LENGTH && count(array_unique(mb_str_split($password))) < 4) {
            $errors[] = 'validation.password_common';
        }

        return array_values(array_unique($errors));
    }
}
