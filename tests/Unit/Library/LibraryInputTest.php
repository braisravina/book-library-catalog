<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Library;

use DigitalLibrary\Collections\CollectionInput;
use DigitalLibrary\Http\Request;
use DigitalLibrary\Loans\LoanInput;
use DigitalLibrary\Validation\Validator;
use DigitalLibrary\Wishlist\WishlistInput;
use PHPUnit\Framework\TestCase;

final class LibraryInputTest extends TestCase
{
    private const TODAY = '2026-10-13';

    public function testLoanInputParsesBorrowerChoice(): void
    {
        $input = LoanInput::fromRequest(new Request('POST', '/x', [], ['borrower_type' => 'user', 'borrower_user_id' => '5', 'borrower_name' => 'ignored', 'loaned_on' => self::TODAY]));
        self::assertSame([5, null], [$input->borrowerUserId(), $input->borrowerName()]);

        $input = LoanInput::fromRequest(new Request('POST', '/x', [], ['borrower_type' => 'anything', 'borrower_user_id' => '5', 'borrower_name' => "  Ana \t  María ", 'loaned_on' => self::TODAY]));
        self::assertSame('name', $input->borrowerType, 'Unknown types fall back to a free-text name');
        self::assertSame([null, 'Ana María'], [$input->borrowerUserId(), $input->borrowerName()]);
    }

    public function testLoanDateRules(): void
    {
        $errors = fn (string $loaned, string $due, string $type = 'name', string $user = ''): array => $this->loanErrors(new LoanInput($type, $user, 'Ana', $loaned, $due, ''), 9);

        self::assertSame([], $errors(self::TODAY, ''));
        self::assertSame([], $errors('2026-01-01', '2031-01-01'), 'Up to five years after the loan date');
        self::assertSame(['due_on' => ['loans.validation.due_too_far']], $errors('2026-01-01', '2031-01-02'));
        self::assertSame(['due_on' => ['loans.validation.due_before_loan']], $errors(self::TODAY, '2026-10-12'));
        self::assertSame(['loaned_on' => ['loans.validation.loaned_in_future']], $errors('2026-10-14', ''));
        self::assertSame(['loaned_on' => ['validation.date']], $errors('13/10/2026', ''));
        self::assertSame(['borrower_user_id' => ['loans.validation.self']], $errors(self::TODAY, '', 'user', '9'));
        self::assertSame(['borrower_user_id' => ['validation.integer']], $errors(self::TODAY, '', 'user', '1e3'));
    }

    public function testWishlistInputNormalisesIsbnAndValidates(): void
    {
        $input = WishlistInput::fromRequest(new Request('POST', '/x', [], ['title' => " Rayuela\n ", 'author' => 'Julio  Cortázar', 'isbn' => '84-376-0494-X', 'priority' => 'high']));
        self::assertSame(['Rayuela', 'Julio Cortázar', '9788437604947'], [$input->title, $input->author, $input->isbn13()]);
        $v = Validator::make([], []);
        $input->validate($v);
        self::assertSame([], $v->errors());

        $bad = new WishlistInput('', str_repeat('a', 256), '9788437604940', 'urgent', '');
        $v = Validator::make([], []);
        $bad->validate($v);
        self::assertSame(['title', 'author', 'priority', 'isbn'], array_keys($v->errors()));
        self::assertNull($bad->isbn13());
        self::assertNull(WishlistInput::blank()->isbn13());
    }

    public function testCollectionsDefaultToPrivateAndRejectPublicVisibility(): void
    {
        self::assertSame('private', CollectionInput::blank()->visibility);
        self::assertSame('private', CollectionInput::fromRequest(new Request('POST', '/x', [], ['name' => 'X']))->visibility);
        $v = Validator::make([], []);
        (new CollectionInput('Name', '', 'public'))->validate($v);
        self::assertSame(['visibility' => ['validation.in']], $v->errors());
        $v = Validator::make([], []);
        (new CollectionInput(str_repeat('n', 151), str_repeat('d', 2001), 'shared'))->validate($v);
        self::assertSame(['name', 'description'], array_keys($v->errors()));
    }

    /**
     * @return array<string, list<string>>
     */
    private function loanErrors(LoanInput $input, int $ownerId): array
    {
        $v = Validator::make([], []);
        $input->validate($v, self::TODAY, $ownerId);

        return $v->errors();
    }
}
