<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Loans\LoanException;
use DigitalLibrary\Loans\LoanInput;
use DigitalLibrary\Loans\LoanService;
use DigitalLibrary\Security\Role;
use PDO;
use PDOException;

/**
 * Lending and returning physical copies: workflows, the one-active-loan
 * guarantee (service, row locks and unique index), authorization, borrower
 * privacy, derived overdue state and date validation.
 */
final class LoanTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;
    private int $bobId;
    private int $viewerId;
    private int $copyId;
    private int $editionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->bobId = $this->createUser('bob@example.org', Role::Member);
        $this->viewerId = $this->createUser('viewer@example.org', Role::Viewer);
        $book = $this->makeBook($this->aliceId, 'Los pazos de Ulloa', '9788437604947', ['Emilia Pardo Bazán'], [], [], $this->aliceId);
        $this->copyId = (int) $book['copy_id'];
        $this->editionId = $book['edition_id'];
    }

    public function testMemberLendsOwnCopyToExternalBorrowerAndRecordsReturn(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $today = LoanService::today();
        $due = gmdate('Y-m-d', strtotime('+14 days'));

        self::assertSame(200, $this->request($browser, 'GET', "/copies/{$this->copyId}/lend")->status());
        $response = $this->lend($browser, $token, ['borrower_name' => '  Carmen   R. ', 'due_on' => $due, 'notes' => 'Handle with care']);
        self::assertSame(303, $response->status());
        self::assertSame("/copies/{$this->copyId}", $response->header('Location'));

        $loan = $this->pdo()->query('SELECT * FROM loans')->fetch();
        self::assertSame('Carmen R.', $loan['borrower_name'], 'Whitespace is normalised');
        self::assertNull($loan['borrower_user_id']);
        self::assertSame([$today, $due, null, $this->aliceId], [$loan['loaned_on'], $loan['due_on'], $loan['returned_on'], (int) $loan['created_by']]);
        self::assertSame('on_loan', $this->copyStatus());

        $page = $this->request($browser, 'GET', "/copies/{$this->copyId}")->body();
        self::assertStringContainsString('Borrowed by Carmen R.', $page);
        self::assertStringContainsString('Handle with care', $page);
        self::assertStringContainsString("/loans/{$loan['id']}/return", $page);
        self::assertStringContainsString('Carmen R.', $this->request($browser, 'GET', '/loans')->body());
        self::assertStringContainsString('On loan', $this->request($browser, 'GET', "/editions/{$this->editionId}")->body());

        // Audit trail: the event is recorded, the borrower's name is not.
        $audit = $this->pdo()->query("SELECT entity_id, details FROM audit_log WHERE action = 'loan.created'")->fetch();
        self::assertSame((int) $loan['id'], (int) $audit['entity_id']);
        self::assertEquals(['copy_id' => $this->copyId, 'borrower_user_id' => null, 'due_on' => $due], json_decode((string) $audit['details'], true));
        self::assertStringNotContainsString('Carmen', (string) $audit['details']);

        // Return through the confirmation page.
        $confirm = $this->request($browser, 'GET', "/loans/{$loan['id']}/return");
        self::assertSame(200, $confirm->status());
        self::assertStringContainsString('name="_csrf"', $confirm->body());
        self::assertSame(303, $this->request($browser, 'POST', "/loans/{$loan['id']}/return", ['_csrf' => $token])->status());
        $returned = $this->pdo()->query("SELECT returned_on, returned_by FROM loans WHERE id = {$loan['id']}")->fetch();
        self::assertSame([$today, $this->aliceId], [$returned['returned_on'], (int) $returned['returned_by']]);
        self::assertSame('available', $this->copyStatus());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'loan.returned' AND entity_id = {$loan['id']}"));

        // A second return is refused without changing anything.
        $again = $this->request($browser, 'POST', "/loans/{$loan['id']}/return", ['_csrf' => $token]);
        self::assertSame(303, $again->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'loan.returned'"));
        self::assertSame(303, $this->request($browser, 'GET', "/loans/{$loan['id']}/return")->status(), 'Confirmation page redirects once returned');

        // History is preserved across loans.
        self::assertSame(303, $this->lend($browser, $token, ['borrower_name' => 'Xoán'])->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$this->copyId}"));
        $page = $this->request($browser, 'GET', "/copies/{$this->copyId}")->body();
        self::assertStringContainsString('Lending history', $page);
        self::assertStringContainsString('Carmen R.', $page);
        self::assertStringContainsString('Xoán', $page);
        self::assertStringContainsString('Returned', $this->request($browser, 'GET', '/loans?status=returned')->body());
    }

    public function testLendingToALibraryUserIsVisibleToTheBorrower(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        self::assertSame(303, $this->lend($browser, $token, ['borrower_type' => 'user', 'borrower_user_id' => (string) $this->viewerId])->status());
        self::assertSame($this->viewerId, $this->countRows('SELECT borrower_user_id FROM loans'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'loan.created' AND JSON_EXTRACT(details, '$.borrower_user_id') = {$this->viewerId}"));

        // The viewer (read-only role) sees their own borrowing, but cannot manage it.
        [$viewer, $viewerToken] = $this->signedIn('viewer@example.org');
        $list = $this->request($viewer, 'GET', '/loans');
        self::assertSame(200, $list->status());
        self::assertStringContainsString('Los pazos de Ulloa', $list->body());
        self::assertStringContainsString('Borrowed by Viewer', $this->request($viewer, 'GET', "/copies/{$this->copyId}")->body());
        $loanId = $this->countRows('SELECT id FROM loans');
        self::assertSame(403, $this->request($viewer, 'POST', "/loans/{$loanId}/return", ['_csrf' => $viewerToken])->status());
        self::assertSame(403, $this->request($viewer, 'GET', "/copies/{$this->copyId}/lend")->status());
    }

    public function testBorrowerMustBeAnotherActiveUser(): void
    {
        $inactive = $this->createUser('gone@example.org', Role::Member, active: false);
        [$browser, $token] = $this->signedIn('alice@example.org');
        foreach ([(string) $this->aliceId, (string) $inactive, '999999', 'abc', ''] as $candidate) {
            self::assertSame(422, $this->lend($browser, $token, ['borrower_type' => 'user', 'borrower_user_id' => $candidate])->status(), $candidate);
        }
        $form = $this->request($browser, 'GET', "/copies/{$this->copyId}/lend")->body();
        self::assertStringContainsString('value="' . $this->bobId . '"', $form);
        self::assertStringNotContainsString('value="' . $inactive . '"', $form, 'Inactive users are not offered');
        self::assertStringNotContainsString('<option value="' . $this->aliceId . '"', $form, 'The owner is not offered');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));
        self::assertSame('available', $this->copyStatus());
    }

    public function testInvalidDatesAndBorrowersAreRejected(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $today = LoanService::today();
        $cases = [
            'future loan date' => ['loaned_on' => gmdate('Y-m-d', strtotime('+1 day'))],
            'malformed loan date' => ['loaned_on' => '2026-02-30'],
            'missing loan date' => ['loaned_on' => ''],
            'ancient loan date' => ['loaned_on' => '1850-01-01'],
            'due before loan' => ['due_on' => gmdate('Y-m-d', strtotime('-1 day'))],
            'due too far' => ['due_on' => gmdate('Y-m-d', strtotime('+6 years'))],
            'malformed due date' => ['due_on' => 'next week'],
            'missing name' => ['borrower_name' => '   '],
            'name too long' => ['borrower_name' => str_repeat('a', 151)],
            'control characters' => ['borrower_name' => "Bad\x07name"],
            'notes too long' => ['notes' => str_repeat('n', 1001)],
        ];
        foreach ($cases as $label => $override) {
            self::assertSame(422, $this->lend($browser, $token, $override)->status(), $label);
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));

        // A past loan date (recording an earlier loan) and a same-day due date are fine.
        $past = gmdate('Y-m-d', strtotime('-20 days'));
        self::assertSame(303, $this->lend($browser, $token, ['loaned_on' => $past, 'due_on' => $past])->status());
        self::assertSame($past, (string) $this->pdo()->query('SELECT loaned_on FROM loans')->fetchColumn());
        self::assertNotSame($today, $past);
    }

    public function testACopyCannotHaveTwoActiveLoans(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        self::assertSame(303, $this->lend($browser, $token, ['borrower_name' => 'First'])->status());
        $second = $this->lend($browser, $token, ['borrower_name' => 'Second']);
        self::assertSame(409, $second->status());
        self::assertStringContainsString('This copy is already on loan.', $second->body());

        // Even if the copy status were edited out of step, the service checks the loans table.
        $this->pdo()->exec("UPDATE copies SET status = 'available' WHERE id = {$this->copyId}");
        self::assertSame(409, $this->lend($browser, $token, ['borrower_name' => 'Third'])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$this->copyId}"));
    }

    public function testDatabaseEnforcesOneActiveLoanPerCopy(): void
    {
        $insert = fn (?string $returned) => $this->pdo()->exec(
            "INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, created_at, updated_at)
             VALUES ({$this->copyId}, 'X', '2026-01-01', " . ($returned === null ? 'NULL' : "'{$returned}'") . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())'
        );
        $insert('2026-01-05');
        $insert('2026-01-09');
        $insert(null);
        try {
            $insert(null);
            self::fail('A second active loan was accepted');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
            self::assertStringContainsString('uq_loans_active_copy', $e->getMessage());
        }
        self::assertSame(3, $this->countRows('SELECT COUNT(*) FROM loans'));
    }

    public function testConcurrentLendingWaitsForTheCopyLock(): void
    {
        $services = $this->services();
        $alice = $services->users()->findById($this->aliceId);
        $other = ConnectionFactory::fromConfig(self::config());
        $other->beginTransaction();
        $other->query("SELECT id FROM copies WHERE id = {$this->copyId} FOR UPDATE")->fetchAll();

        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $services->loanService()->lend($alice, $this->copyId, $this->input());
            self::fail('Lending did not wait for the competing transaction');
        } catch (LoanException $e) {
            self::assertSame('loans.error.busy', $e->getMessage());
        } finally {
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $other->rollBack();
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));
        self::assertFalse($this->pdo()->inTransaction());

        // Once the competing transaction lent the copy, the waiting one is refused.
        $other->beginTransaction();
        $other->query("SELECT id FROM copies WHERE id = {$this->copyId} FOR UPDATE")->fetchAll();
        $other->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$this->copyId}, 'Racer', UTC_DATE(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $other->exec("UPDATE copies SET status = 'on_loan' WHERE id = {$this->copyId}");
        $other->commit();
        $this->expectExceptionObject(new LoanException('loans.error.already_on_loan'));
        $services->loanService()->lend($alice, $this->copyId, $this->input());
    }

    public function testOtherMembersCannotLendOrReturnSomeoneElsesCopy(): void
    {
        [$alice, $aliceToken] = $this->signedIn('alice@example.org');
        $this->lend($alice, $aliceToken, ['borrower_name' => 'Carmen']);
        $loanId = $this->countRows('SELECT id FROM loans');
        $bobBook = $this->makeBook($this->bobId, 'Bob book', null, [], [], [], $this->bobId);

        [$bob, $bobToken] = $this->signedIn('bob@example.org');
        self::assertSame(403, $this->request($bob, 'GET', "/copies/{$this->copyId}/lend")->status());
        self::assertSame(403, $this->request($bob, 'POST', "/copies/{$this->copyId}/lend", $this->body($bobToken))->status());
        self::assertSame(403, $this->request($bob, 'GET', "/loans/{$loanId}/return")->status());
        self::assertSame(403, $this->request($bob, 'POST', "/loans/{$loanId}/return", ['_csrf' => $bobToken])->status());
        self::assertNull($this->pdo()->query("SELECT returned_on FROM loans WHERE id = {$loanId}")->fetchColumn());

        // Nonexistent copies and loans are 404, also for the owner of other copies.
        foreach (['/copies/999999', '/copies/999999/lend', '/loans/999999/return'] as $path) {
            self::assertSame(404, $this->request($bob, 'GET', $path)->status(), $path);
        }
        self::assertSame(404, $this->request($bob, 'POST', '/copies/999999/lend', $this->body($bobToken))->status());
        self::assertSame(404, $this->request($bob, 'POST', '/loans/999999/return', ['_csrf' => $bobToken])->status());

        // Bob's own copy is fine; the service also refuses a forged actor.
        self::assertSame(303, $this->lend($bob, $bobToken, ['borrower_name' => 'Friend'], (int) $bobBook['copy_id'])->status());
        $services = $this->services();
        try {
            $services->loanService()->returnLoan($services->users()->findById($this->bobId), $loanId);
            self::fail('Service accepted a cross-user return');
        } catch (LoanException $e) {
            self::assertSame('loans.error.forbidden', $e->getMessage());
        }
        try {
            $services->loanService()->lend($services->users()->findById($this->bobId), 999999, $this->input());
            self::fail('Service accepted a missing copy');
        } catch (LoanException $e) {
            self::assertSame('loans.error.copy_not_found', $e->getMessage());
        }

        // Administrators may manage any copy's loans.
        [$admin, $adminToken] = $this->signedIn('admin@example.org');
        self::assertSame(303, $this->request($admin, 'POST', "/loans/{$loanId}/return", ['_csrf' => $adminToken])->status());
        self::assertSame($this->adminId, $this->countRows("SELECT returned_by FROM loans WHERE id = {$loanId}"));
        self::assertStringContainsString('Friend', $this->request($admin, 'GET', '/loans?scope=all')->body());
    }

    public function testBorrowerIdentityIsHiddenFromOtherUsers(): void
    {
        [$alice, $token] = $this->signedIn('alice@example.org');
        $this->lend($alice, $token, ['borrower_name' => 'Secret Borrower', 'notes' => 'private note']);

        foreach (['bob@example.org', 'viewer@example.org'] as $email) {
            [$browser] = $this->signedIn($email);
            $copy = $this->request($browser, 'GET', "/copies/{$this->copyId}");
            self::assertSame(200, $copy->status(), $email);
            self::assertStringContainsString('On loan', $copy->body(), $email);
            foreach (['Secret Borrower', 'private note', 'Lending history'] as $hidden) {
                self::assertStringNotContainsString($hidden, $copy->body(), "{$email}: {$hidden}");
            }
            self::assertStringNotContainsString('Secret Borrower', $this->request($browser, 'GET', "/editions/{$this->editionId}")->body(), $email);
            // Asking for every loan falls back to the user's own borrowing.
            self::assertStringNotContainsString('Secret Borrower', $this->request($browser, 'GET', '/loans?scope=all&status=all')->body(), $email);
            self::assertStringNotContainsString('Secret Borrower', $this->request($browser, 'GET', '/loans?scope=lent&status=all')->body(), $email);
        }

        [$admin] = $this->signedIn('admin@example.org');
        self::assertStringContainsString('Secret Borrower', $this->request($admin, 'GET', "/copies/{$this->copyId}")->body());
    }

    public function testOverdueIsDerivedFromTheDueDate(): void
    {
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, due_on, created_at, updated_at)
            VALUES ({$this->copyId}, 'Late reader', UTC_DATE() - INTERVAL 30 DAY, UTC_DATE() - INTERVAL 1 DAY, UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec("UPDATE copies SET status = 'on_loan' WHERE id = {$this->copyId}");
        $other = $this->makeBook($this->aliceId, 'Due today', null, [], [], [], $this->aliceId);
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, due_on, created_at, updated_at)
            VALUES ({$other['copy_id']}, 'Punctual reader', UTC_DATE() - INTERVAL 3 DAY, UTC_DATE(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");

        [$browser, $token] = $this->signedIn('alice@example.org');
        $overdue = $this->request($browser, 'GET', '/loans?status=overdue')->body();
        self::assertStringContainsString('Late reader', $overdue);
        self::assertStringNotContainsString('Punctual reader', $overdue, 'A loan due today is not overdue yet');
        $active = $this->request($browser, 'GET', '/loans?status=active')->body();
        self::assertStringContainsString('Late reader', $active);
        self::assertStringContainsString('Punctual reader', $active);
        self::assertStringContainsString('Overdue', $this->request($browser, 'GET', "/copies/{$this->copyId}")->body());
        self::assertSame(1, $this->services()->loans()->countOverdueForOwner($this->aliceId));
        self::assertStringContainsString('You have 1 overdue loans.', $this->request($browser, 'GET', '/')->body());
        [$bob] = $this->signedIn('bob@example.org');
        self::assertStringNotContainsString('overdue loans', $this->request($bob, 'GET', '/')->body());

        // Returning a loan clears its overdue state.
        $loanId = $this->countRows("SELECT id FROM loans WHERE borrower_name = 'Late reader'");
        $this->request($browser, 'POST', "/loans/{$loanId}/return", ['_csrf' => $token]);
        self::assertStringNotContainsString('Late reader', $this->request($browser, 'GET', '/loans?status=overdue')->body());
        self::assertSame(0, $this->services()->loans()->countOverdueForOwner($this->aliceId));
        self::assertStringNotContainsString('overdue loans', $this->request($browser, 'GET', '/')->body());
    }

    public function testUnavailableCopiesCannotBeLent(): void
    {
        $this->pdo()->exec("UPDATE copies SET status = 'lost' WHERE id = {$this->copyId}");
        [$browser, $token] = $this->signedIn('alice@example.org');
        $response = $this->lend($browser, $token);
        self::assertSame(409, $response->status());
        self::assertStringContainsString('This copy is not available for lending.', $response->body());
        self::assertStringNotContainsString("/copies/{$this->copyId}/lend\"", $this->request($browser, 'GET', "/copies/{$this->copyId}")->body());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));
    }

    public function testCopyStatusAndDeletionRespectLoans(): void
    {
        [$browser, $token] = $this->signedIn('alice@example.org');
        $edit = fn (string $status) => $this->request($browser, 'POST', "/copies/{$this->copyId}", [
            '_csrf' => $token, 'row_version' => $this->rowVersion('copies', $this->copyId), 'owner_user_id' => (string) $this->aliceId, 'status' => $status,
        ]);
        self::assertSame(422, $edit('on_loan')->status(), 'Lending goes through the loan workflow');

        $this->lend($browser, $token, ['borrower_name' => 'Carmen']);
        self::assertSame(422, $edit('available')->status(), 'An active loan keeps the copy on loan');
        self::assertSame(303, $edit('on_loan')->status(), 'Other edits keep working during a loan');

        $loanId = $this->countRows('SELECT id FROM loans');
        $this->request($browser, 'POST', "/loans/{$loanId}/return", ['_csrf' => $token]);
        self::assertSame(303, $edit('withdrawn')->status());

        // A copy with lending history is kept (the loans reference it).
        $response = $this->request($browser, 'POST', "/copies/{$this->copyId}/delete", ['_csrf' => $token]);
        self::assertSame(303, $response->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE id = {$this->copyId}"));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM loans'));
    }

    public function testGuestsAndViewersCannotUseLendingRoutes(): void
    {
        foreach (['/loans', "/copies/{$this->copyId}", "/copies/{$this->copyId}/lend", '/loans/1/return'] as $path) {
            $response = $this->request($this->browser(), 'GET', $path);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'), $path);
        }
        [$viewer, $token] = $this->signedIn('viewer@example.org');
        self::assertSame(403, $this->request($viewer, 'POST', "/copies/{$this->copyId}/lend", $this->body($token))->status());
        self::assertStringNotContainsString('/lend"', $this->request($viewer, 'GET', "/editions/{$this->editionId}")->body());
    }

    /**
     * @param array<string, string> $override
     */
    private function lend(\DigitalLibrary\Security\Session $browser, string $token, array $override = [], ?int $copyId = null): \DigitalLibrary\Http\Response
    {
        return $this->request($browser, 'POST', '/copies/' . ($copyId ?? $this->copyId) . '/lend', $override + $this->body($token));
    }

    /**
     * @return array<string, string>
     */
    private function body(string $token): array
    {
        return ['_csrf' => $token, 'borrower_type' => 'name', 'borrower_name' => 'Carmen', 'loaned_on' => LoanService::today(), 'due_on' => '', 'notes' => ''];
    }

    private function input(): LoanInput
    {
        return new LoanInput('name', '', 'Carmen', LoanService::today(), '', '');
    }

    private function services(): Services
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());

        return $services;
    }

    private function copyStatus(): string
    {
        return (string) $this->pdo()->query("SELECT status FROM copies WHERE id = {$this->copyId}")->fetchColumn();
    }
}
