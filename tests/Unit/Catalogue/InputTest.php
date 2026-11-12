<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Catalogue;

use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Catalogue\Input\EditionInput;
use DigitalLibrary\Catalogue\Input\WorkInput;
use DigitalLibrary\Catalogue\Repository\AuthorRepository;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testWorkInputParsesPeopleAndCategories(): void
    {
        $input = WorkInput::fromRequest(new Request('POST', '/books', [], [
            'title' => '  Cien años de soledad ',
            'author_names' => ['Gabriel  García Márquez', '', ' Second   Person '],
            'author_roles' => ['author', 'author', ''],
            'categories' => ['3', 'x', '3', '7', ['nested']],
        ]));

        self::assertSame('Cien años de soledad', $input->title);
        self::assertSame([
            ['name' => 'Gabriel García Márquez', 'role' => 'author'],
            ['name' => 'Second Person', 'role' => 'author'],
        ], $input->authors);
        self::assertSame([3, 7], $input->categoryIds);
        $v = Validator::make([], []);
        $input->validate($v);
        self::assertFalse($v->fails());
    }

    public function testWorkInputValidation(): void
    {
        $input = new WorkInput('', str_repeat('x', 501), '', 'xx', '99999', '', [['name' => 'A', 'role' => 'overlord']], []);
        $v = Validator::make([], []);
        $input->validate($v);

        self::assertSame(['validation.required'], $v->errors()['title']);
        self::assertSame(['validation.max'], $v->errors()['subtitle']);
        self::assertSame(['validation.in'], $v->errors()['original_language']);
        self::assertSame(['validation.between'], $v->errors()['first_published_year']);
        self::assertSame(['validation.in'], $v->errors()['authors']);
    }

    public function testTooManyPeopleIsRejected(): void
    {
        $people = array_fill(0, WorkInput::MAX_PEOPLE + 1, ['name' => 'X', 'role' => 'author']);
        $v = Validator::make([], []);
        (new WorkInput('T', '', '', '', '', '', $people, []))->validate($v);
        self::assertSame(['validation.too_many'], $v->errors()['authors']);
    }

    public function testEditionInputFallsBackToWorkTitleAndNormalisesIsbn(): void
    {
        $input = EditionInput::fromRequest(new Request('POST', '/books', [], ['isbn' => '0-306-40615-2']), 'Work title', 'Work subtitle');
        self::assertSame('Work title', $input->title);
        self::assertSame('Work subtitle', $input->subtitle);

        $record = $input->toRecord(5);
        self::assertSame('9780306406157', $record['isbn_13']);
        self::assertSame('0306406152', $record['isbn_10']);
        self::assertSame(5, $record['publisher_id']);
        self::assertNull($record['publication_year']);
    }

    public function testEditionInputRejectsInvalidIsbnAndRanges(): void
    {
        $input = EditionInput::fromRequest(new Request('POST', '/x', [], [
            'edition_title' => 'T', 'isbn' => '978-0-306-40615-8', 'page_count' => '0', 'format' => 'scroll', 'publication_year' => '1999.5',
        ]));
        $v = Validator::make([], []);
        $input->validate($v);

        self::assertSame(['validation.isbn'], $v->errors()['isbn']);
        self::assertSame(['validation.between'], $v->errors()['page_count']);
        self::assertSame(['validation.in'], $v->errors()['format']);
        self::assertSame(['validation.between'], $v->errors()['publication_year']);
    }

    public function testCopyInputPriceParsingAndValidation(): void
    {
        $base = ['owner_user_id' => '4', 'status' => 'available'];
        $cases = ['12.50' => 1250, '12,5' => 1250, '0.05' => 5, '7' => 700];
        foreach ($cases as $price => $cents) {
            $input = CopyInput::fromRequest(new Request('POST', '/x', [], $base + ['price' => $price, 'currency' => 'EUR']));
            self::assertSame($cents, $input->toRecord()['purchase_price_cents'], (string) $price);
        }

        $v = Validator::make([], []);
        CopyInput::fromRequest(new Request('POST', '/x', [], $base + ['price' => '1.234', 'acquired_on' => '2024-02-30', 'copy_condition' => 'mint']))->validate($v);
        self::assertSame(['validation.money'], $v->errors()['price']);
        self::assertSame(['validation.date'], $v->errors()['acquired_on']);
        self::assertSame(['validation.in'], $v->errors()['copy_condition']);

        $v = Validator::make([], []);
        CopyInput::fromRequest(new Request('POST', '/x', [], $base + ['price' => '3']))->validate($v);
        self::assertSame(['validation.currency_required'], $v->errors()['currency']);
    }

    public function testCopyInputRoundTripsFromRecord(): void
    {
        $input = CopyInput::fromRecord(['owner_user_id' => 3, 'location_id' => null, 'inventory_code' => null, 'copy_condition' => 'good',
            'status' => 'on_loan', 'is_signed' => 1, 'acquired_on' => '2020-01-02', 'acquisition_source' => null,
            'purchase_price_cents' => 1999, 'currency' => 'GBP', 'notes' => null]);
        self::assertSame('19.99', $input->price);
        self::assertTrue($input->isSigned);
        self::assertSame(1999, $input->toRecord()['purchase_price_cents']);
        self::assertNull($input->toRecord()['location_id']);
    }

    public function testAuthorSortName(): void
    {
        self::assertSame('Márquez, Gabriel García', AuthorRepository::sortName('Gabriel García Márquez'));
        self::assertSame('Castro, Rosalía de', AuthorRepository::sortName('Castro, Rosalía de'));
        self::assertSame('Homer', AuthorRepository::sortName(' Homer '));
    }
}
