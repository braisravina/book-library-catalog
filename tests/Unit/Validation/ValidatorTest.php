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

    public function testRangeDateAndMoneyRules(): void
    {
        $validator = Validator::make(
            ['y1' => '-500', 'y2' => '2100', 'y3' => '12a', 'd1' => '2024-02-29', 'd2' => '2023-02-29', 'd3' => '2024-1-5', 'm1' => '12,50', 'm2' => '1.999', 'm3' => '-4'],
            ['y1' => ['between:-3000,2027'], 'y2' => ['between:-3000,2027'], 'y3' => ['between:1,9'], 'd1' => ['date'], 'd2' => ['date'], 'd3' => ['date'], 'm1' => ['money'], 'm2' => ['money'], 'm3' => ['money']],
        );

        self::assertSame(['y2', 'y3', 'd2', 'd3', 'm2', 'm3'], array_keys($validator->errors()));
    }

    public function testUnknownRuleIsAProgrammingError(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['a' => 'x'], ['a' => ['nope']]);
    }
}
