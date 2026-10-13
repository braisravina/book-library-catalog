<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Validation;

use DigitalLibrary\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testCollectsErrorKeysPerField(): void
    {
        $validator = Validator::make(
            ['email' => 'not-an-email', 'name' => '', 'role' => 'root', 'bio' => str_repeat('x', 11), 'n' => '1a', 'c' => "bad\x07"],
            [
                'email' => ['required', 'email'],
                'name' => ['required', 'max:10'],
                'role' => ['in:admin,member'],
                'bio' => ['max:10'],
                'n' => ['integer'],
                'c' => ['no_control'],
                'missing_optional' => ['email'],
            ],
        );

        self::assertTrue($validator->fails());
        self::assertSame([
            'email' => ['validation.email'],
            'name' => ['validation.required'],
            'role' => ['validation.in'],
            'bio' => ['validation.max'],
            'n' => ['validation.integer'],
            'c' => ['validation.no_control'],
        ], $validator->errors());
    }

    public function testPassesValidInput(): void
    {
        $validator = Validator::make(
            ['email' => ' reader@example.org ', 'name' => 'Ánxela', 'note' => "line\nbreak"],
            ['email' => ['required', 'email'], 'name' => ['required', 'max:6', 'min:2'], 'note' => ['no_control']],
        );
        self::assertFalse($validator->fails());
    }

    public function testMultibyteLengthIsCountedInCharacters(): void
    {
        self::assertFalse(Validator::make(['n' => 'ñññ'], ['n' => ['max:3']])->fails());
    }

    public function testUnknownRuleIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 'x'], ['a' => ['nope']]);
    }
}
