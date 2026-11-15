<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Database\Engine;
use DigitalLibrary\Households\WriteActor;
use DigitalLibrary\Households\WriteBusyException;
use DigitalLibrary\Households\WriteGuard;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Security\Role;
use PDO;

/**
 * Membership revocation and concurrency (phase H3): the guarantees of
 * Households\WriteGuard, exercised with a second database connection and,
 * where a connection has to wait while the test goes on, a second process.
 *
 *  - a membership removal waits for the writes in flight, and a write
 *    that starts after it sees it;
 *  - a first copy added by another household and an edit of the record
 *    are never interleaved;
 *  - lock timeouts and deadlocks end in a clean "try again" with nothing
 *    written, and a deadlock victim is run again;
 *  - racing writes produce exactly the outcome the unique indexes allow.
 *
 * Runs on whichever engine the suite is pointed at (MariaDB 11.4 and
 * MySQL 8.4 in CI).
 */
final class HouseholdWriteConcurrencyTest extends IntegrationTestCase
{
    private int $adminId;
    private int $anaId;
    private int $veraId;
    private int $beaId;
    private int $casa;
    private int $piso;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $casaBook;
    /** @var array{work_id: int, edition_id: int, copy_id: ?int} */
    private array $pisoBook;
    private ?PDO $other = null;
    /** @var list<resource> */
    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->anaId = $this->createUser('ana@example.org', Role::Member);
        $this->veraId = $this->createUser('vera@example.org', Role::Viewer);
        $this->beaId = $this->createUser('bea@example.org', Role::Member);
        $this->casa = $this->createHousehold('Casa', [$this->anaId, $this->veraId]);
        $this->piso = $this->createHousehold('Piso', [$this->beaId]);
        $this->casaBook = $this->makeBook($this->anaId, 'Casa Book', '9788437604947', [], [], [], $this->anaId, null, $this->casa);
        $this->pisoBook = $this->makeBook($this->beaId, 'Piso Book', '9780132350884', [], [], [], $this->beaId, null, $this->piso);
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
    }

    protected function tearDown(): void
    {
        if ($this->other !== null && $this->other->inTransaction()) {
            $this->other->rollBack();
        }
        $this->other = null;
        foreach ($this->workers as $worker) {
            if (is_resource($worker)) {
                proc_terminate($worker);
                proc_close($worker);
            }
        }
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
    }

    // --- The guard itself ----------------------------------------------------------

    public function testGuardedTransactionsReadCommittedDataAndLeaveTheSessionLevelAlone(): void
    {
        $other = $this->other();
        $title = fn (): string => (string) $this->pdo()->query("SELECT title FROM works WHERE id = {$this->casaBook['work_id']}")->fetchColumn();

        $seen = $this->guard()->run($this->anaId, function (WriteActor $actor) use ($other, $title): array {
            self::assertTrue($this->pdo()->inTransaction());
            self::assertSame($this->anaId, $actor->user->id);
            self::assertSame([$this->casa], $actor->scope->ids());
            $before = $title();
            $other->exec("UPDATE works SET title = 'Changed meanwhile' WHERE id = {$this->casaBook['work_id']}");

            return [$before, $title()];
        });
        self::assertSame(['Casa Book', 'Changed meanwhile'], $seen, 'A check made after a lock sees what was committed before it');
        self::assertFalse($this->pdo()->inTransaction());

        // The session keeps the server default: an ordinary transaction still reads one snapshot.
        $this->pdo()->beginTransaction();
        $before = $title();
        $other->exec("UPDATE works SET title = 'Changed again' WHERE id = {$this->casaBook['work_id']}");
        $after = $title();
        $this->pdo()->rollBack();
        self::assertSame(['Changed meanwhile', 'Changed meanwhile'], [$before, $after]);

        // The actor comes from the database, not from the caller: role, state and memberships as they are now.
        $this->pdo()->exec("DELETE FROM household_members WHERE user_id = {$this->anaId}");
        self::assertSame([], $this->guard()->run($this->anaId, static fn (WriteActor $actor): array => $actor->scope->ids()));
        self::assertTrue($this->guard()->run($this->adminId, static fn (WriteActor $actor): bool => $actor->scope->unrestricted && $actor->reaches(null)));
        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->anaId}");
        try {
            $this->guard()->run($this->anaId, static fn (): bool => true);
            self::fail('A deactivated account entered a guarded write');
        } catch (\DigitalLibrary\Households\WriteDeniedException $e) {
            self::assertFalse($e->notFound);
        }
        try {
            $this->guard()->run(999999, static fn (): bool => true);
            self::fail('An unknown account entered a guarded write');
        } catch (\DigitalLibrary\Households\WriteDeniedException) {
        }
        self::assertFalse($this->pdo()->inTransaction());

        // It never joins a transaction that is already open (its snapshot could be older than the locks).
        $this->pdo()->beginTransaction();
        try {
            $this->guard()->run($this->adminId, static fn (): bool => true);
            self::fail('A guarded write joined an open transaction');
        } catch (\LogicException) {
        } finally {
            $this->pdo()->rollBack();
        }
    }

    public function testDeadlockVictimsAreRunAgainAndFailuresWriteNothing(): void
    {
        $failure = static function (int $code): \PDOException {
            $e = new \PDOException('SQLSTATE[40001]: simulated');
            $e->errorInfo = ['40001', $code, 'simulated'];

            return $e;
        };
        $rows = fn (): int => $this->countRows("SELECT COUNT(*) FROM publishers WHERE name LIKE 'Guard %'");
        $work = function (int $failures, int $code) use ($failure, &$attempts): \Closure {
            $attempts = 0;

            return function () use ($failures, $code, $failure, &$attempts): int {
                $attempts++;
                $this->pdo()->exec("INSERT INTO publishers (name, created_at, updated_at) VALUES ('Guard {$attempts}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                if ($attempts <= $failures) {
                    throw $failure($code);
                }

                return $attempts;
            };
        };

        // Twice a deadlock victim, then through: only the last attempt's write exists.
        self::assertSame(3, $this->guard()->run($this->adminId, $work(2, Engine::DEADLOCK)));
        self::assertSame(1, $rows());
        self::assertSame('Guard 3', $this->pdo()->query("SELECT name FROM publishers WHERE name LIKE 'Guard %'")->fetchColumn());
        $this->pdo()->exec("DELETE FROM publishers WHERE name LIKE 'Guard %'");

        // A deadlock on every attempt: a clean refusal, nothing written, no transaction left open.
        try {
            $this->guard()->run($this->adminId, $work(99, Engine::DEADLOCK));
            self::fail('Expected WriteBusyException');
        } catch (WriteBusyException $e) {
            self::assertSame('errors.busy.message', $e->getMessage());
        }
        self::assertSame(WriteGuard::MAX_ATTEMPTS, $attempts);
        self::assertSame(0, $rows());
        self::assertFalse($this->pdo()->inTransaction());

        // A lock wait timeout is not retried (the other transaction is still there).
        try {
            $this->guard()->run($this->adminId, $work(99, Engine::LOCK_WAIT_TIMEOUT));
            self::fail('Expected WriteBusyException');
        } catch (WriteBusyException) {
        }
        self::assertSame(1, $attempts);
        self::assertSame(0, $rows());

        // Anything else is not swallowed, and is rolled back just the same.
        try {
            $this->guard()->run($this->adminId, $work(99, 1062));
            self::fail('Expected the original exception');
        } catch (\PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        self::assertSame(0, $rows());
        self::assertFalse($this->pdo()->inTransaction());
    }

    /**
     * Two transactions that each hold what the other wants. The server
     * rolls one back; when that is the guarded write it is run again and
     * succeeds once the other has finished.
     */
    public function testARealDeadlockIsResolvedByRunningTheWriteAgain(): void
    {
        // Enough changed rows to make the other transaction the heavier one (the server sacrifices the lighter).
        for ($i = 0; $i < 300; $i++) {
            $this->pdo()->exec("INSERT INTO publishers (name, created_at, updated_at) VALUES ('Filler {$i}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        }
        $mine = $this->casaBook['work_id'];
        $theirs = $this->pisoBook['work_id'];
        [$worker, $stdin, $stdout] = $this->spawn([
            'mode' => 'script',
            'first' => ["UPDATE publishers SET name = CONCAT(name, ' x') WHERE name LIKE 'Filler %'", "SELECT id FROM works WHERE id = {$theirs} FOR UPDATE"],
            'second' => ["SELECT id FROM works WHERE id = {$mine} FOR UPDATE"],
        ]);
        self::assertSame("ready\n", fgets($stdout), 'The other transaction holds its first lock');

        $attempts = 0;
        $services = $this->services();
        $result = $this->guard()->run($this->adminId, function () use (&$attempts, $services, $mine, $theirs, $stdin): string {
            $attempts++;
            $services->works()->lock($mine, true);
            if ($attempts === 1) {
                // Let the other transaction ask for the row held here, then ask for the row it holds.
                fwrite($stdin, "go\n");
                fflush($stdin);
                usleep(600_000);
            }
            $services->works()->lock($theirs, true);
            $this->pdo()->exec("UPDATE works SET subtitle = 'after the deadlock' WHERE id IN ({$mine}, {$theirs})");

            return 'written';
        });

        self::assertSame('written', $result);
        self::assertSame(2, $attempts, 'The first attempt lost the deadlock and was run again');
        self::assertSame("done\n", fgets($stdout), 'The other transaction went through');
        self::assertSame(0, proc_close($worker));
        array_pop($this->workers);
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM works WHERE subtitle = 'after the deadlock'"));
        self::assertSame(300, $this->countRows("SELECT COUNT(*) FROM publishers WHERE name LIKE 'Filler % x'"));
        self::assertFalse($this->pdo()->inTransaction());
    }

    // --- Membership removal -------------------------------------------------------------

    public function testMembershipChangesWaitForTheWritesInFlight(): void
    {
        $other = $this->other();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $admin = $this->services()->users()->findById($this->adminId);
        $elsewhere = $this->services($other)->householdService();

        $this->guard()->run($this->anaId, function (WriteActor $actor) use ($elsewhere, $admin): void {
            self::assertTrue($actor->reaches($this->casa));
            // A write of Ana's is in flight: her membership cannot be removed, nor the household changed, until it ends.
            foreach ([
                fn () => $elsewhere->removeMember($admin, $this->casa, $this->anaId),
                fn () => $elsewhere->addMember($admin, $this->casa, $this->beaId),
                fn () => $elsewhere->delete($admin, $this->piso),
            ] as $change) {
                try {
                    $change();
                    self::fail('A membership change overtook a write in flight');
                } catch (WriteBusyException $e) {
                    self::assertSame(Engine::LOCK_WAIT_TIMEOUT, $e->getPrevious()->errorInfo[1]);
                    self::assertFalse($this->other()->inTransaction());
                }
            }
            self::assertTrue($this->guard()->isMember($this->casa, $this->anaId), 'Still a member while the write runs');
        });

        // Once the write has ended the removal goes through, and the next write sees it.
        self::assertSame(1, $elsewhere->removeMember($admin, $this->casa, $this->anaId));
        self::assertFalse($this->guard()->run($this->anaId, fn (WriteActor $actor): bool => $actor->reaches($this->casa)));
    }

    /**
     * A removal that is in flight: the member's writes wait for it and,
     * when they cannot, are refused cleanly. After it is committed they
     * find the copy gone from their view; if it is rolled back they go
     * through.
     */
    public function testWritesWaitForAMembershipRemovalInFlightAndThenSeeIt(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $copy = (int) $this->casaBook['copy_id'];
        $edition = $this->casaBook['edition_id'];
        $requests = [
            'copy update' => fn (): Response => $this->request($ana, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'lost', 'row_version' => $this->rowVersion('copies', $copy)]),
            'copy delete' => fn (): Response => $this->request($ana, 'POST', "/copies/{$copy}/delete", ['_csrf' => $token]),
            'lend' => fn (): Response => $this->request($ana, 'POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'name', 'borrower_name' => 'Friend', 'loaned_on' => '2026-01-05']),
            'edition edit' => fn (): Response => $this->request($ana, 'POST', "/editions/{$edition}", ['_csrf' => $token, 'edition_title' => 'Edited', 'isbn' => '9788437604947', 'row_version' => $this->rowVersion('editions', $edition)]),
            'new copy' => fn (): Response => $this->request($ana, 'POST', "/editions/{$edition}/copies", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'available']),
            'collection' => fn (): Response => $this->request($ana, 'POST', '/collections', ['_csrf' => $token, 'name' => 'Racing', 'visibility' => 'shared']),
        ];
        /** @param list<string> $names */
        $attempts = static fn (array $names): array => array_map(static fn (string $name): Response => $requests[$name](), array_combine($names, $names));
        $all = array_keys($requests);
        $state = fn (): array => [
            $this->pdo()->query("SELECT status, row_version FROM copies WHERE id = {$copy}")->fetch(PDO::FETCH_NUM),
            $this->countRows('SELECT COUNT(*) FROM copies'), $this->countRows('SELECT COUNT(*) FROM loans'), $this->countRows('SELECT COUNT(*) FROM collections'),
            $this->pdo()->query("SELECT title FROM editions WHERE id = {$edition}")->fetchColumn(),
        ];
        $before = $state();

        // The administrator's removal has started but is not committed.
        $other = $this->other();
        $remove = function () use ($other): void {
            $other->beginTransaction();
            $other->query("SELECT id FROM households WHERE id = {$this->casa} FOR UPDATE")->fetchAll();
            $other->exec("DELETE FROM household_members WHERE household_id = {$this->casa} AND user_id = {$this->anaId}");
        };
        $remove();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        foreach ($attempts($all) as $what => $response) {
            self::assertSame(409, $response->status(), "{$what}: waits for the removal, then gives up cleanly");
            self::assertStringContainsString('try again', strtolower($response->body()), $what);
        }
        self::assertSame($before, $state(), 'Nothing was written while waiting');
        self::assertFalse($this->pdo()->inTransaction());

        // Rolled back: she is still a member and the same requests succeed (all but deleting the copy).
        $other->rollBack();
        foreach ($attempts(['copy update', 'edition edit', 'new copy', 'collection']) as $what => $response) {
            self::assertSame(303, $response->status(), $what);
        }
        self::assertSame('lost', $this->pdo()->query("SELECT status FROM copies WHERE id = {$copy}")->fetchColumn());

        // Committed: every one of them now finds nothing to act on, and writes nothing.
        $remove();
        $other->commit();
        $before = $state();
        $after = $attempts($all);
        foreach (['copy update', 'copy delete', 'lend'] as $what) {
            self::assertSame(404, $after[$what]->status(), "{$what}: the copy is out of her view");
        }
        self::assertSame(403, $after['edition edit']->status(), 'Her edition is now held outside her households');
        self::assertSame(422, $after['new copy']->status(), 'No household to put a copy in');
        self::assertSame(422, $after['collection']->status(), 'No household to share with');
        self::assertSame($before, $state());
    }

    public function testConcurrentMembershipRemovalAndLoanCreation(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $copy = (int) $this->casaBook['copy_id'];
        $lend = fn (): Response => $this->request($ana, 'POST', "/copies/{$copy}/lend", ['_csrf' => $token, 'borrower_type' => 'user', 'borrower_user_id' => (string) $this->veraId, 'loaned_on' => '2026-01-05']);

        // The borrower's membership is being removed: the loan waits, and is refused once the removal is committed.
        $other = $this->other();
        $other->beginTransaction();
        $other->query("SELECT id FROM households WHERE id = {$this->casa} FOR UPDATE")->fetchAll();
        $other->exec("DELETE FROM household_members WHERE household_id = {$this->casa} AND user_id = {$this->veraId}");
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $waiting = $lend();
        self::assertSame(409, $waiting->status());
        self::assertStringContainsString('being updated by someone else', $waiting->body());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));
        $other->commit();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        self::assertSame(422, $lend()->status(), 'No longer a member of the copy\'s household');
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM loans'));
        self::assertSame('available', $this->pdo()->query("SELECT status FROM copies WHERE id = {$copy}")->fetchColumn());

        // In parallel, for real: a loan to Vera races her removal. Whichever is first, the result
        // is consistent: a loan exists only if it was written while she was a member, and the
        // loser gets a clean answer.
        $admin = $this->services()->users()->findById($this->adminId);
        $outcomes = [];
        foreach ([0.00, 0.02, 0.05, 0.10, 0.20, 0.00] as $round => $delay) {
            $this->migrationPdo()->exec('DELETE FROM loans'); // Reset history between isolated race rounds.
            $this->pdo()->exec("UPDATE copies SET status = 'available' WHERE id = {$copy}");
            $this->pdo()->exec("INSERT INTO household_members (household_id, user_id, created_at) VALUES ({$this->casa}, {$this->veraId}, UTC_TIMESTAMP())");
            $start = microtime(true) + 0.5;
            [$worker, , $stdout] = $this->spawn(['mode' => 'lend', 'start' => $start, 'actor' => $this->anaId, 'copy' => $copy, 'borrower' => $this->veraId]);
            usleep((int) (max(0.0, $start - microtime(true) - 0.05 + $delay) * 1_000_000));
            $removedAt = null;
            $this->services()->householdService()->removeMember($admin, $this->casa, $this->veraId);
            $outcome = trim((string) fgets($stdout));
            self::assertSame(0, proc_close($worker), "round {$round}: {$outcome}");
            array_pop($this->workers);
            $loans = $this->countRows("SELECT COUNT(*) FROM loans WHERE copy_id = {$copy} AND borrower_user_id = {$this->veraId}");
            self::assertContains($outcome, ['ok', 'loans.error.borrower'], "round {$round}");
            self::assertSame($outcome === 'ok' ? 1 : 0, $loans, "round {$round}: the loan exists exactly when it was accepted");
            self::assertSame($outcome === 'ok' ? 'on_loan' : 'available', $this->pdo()->query("SELECT status FROM copies WHERE id = {$copy}")->fetchColumn());
            self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$this->casa} AND user_id = {$this->veraId}"));
            $outcomes[] = $outcome;
        }
        self::assertNotEmpty($outcomes);
    }

    // --- Shared records and inventory ---------------------------------------------------

    /**
     * "Nobody else holds it" cannot be overtaken: a copy that another
     * household is adding and an edit of the edition wait for each other.
     */
    public function testAFirstCopyFromAnotherHouseholdAndAnEditOfTheRecordAreSerialised(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $edition = $this->casaBook['edition_id'];
        $work = $this->casaBook['work_id'];
        $saveEdition = fn (): Response => $this->request($ana, 'POST', "/editions/{$edition}", ['_csrf' => $token, 'edition_title' => 'Edited by Ana', 'isbn' => '9788437604947', 'row_version' => $this->rowVersion('editions', $edition)]);
        $saveWork = fn (): Response => $this->request($ana, 'POST', "/works/{$work}", ['_csrf' => $token, 'title' => 'Edited by Ana', 'row_version' => $this->rowVersion('works', $work)]);

        // Piso's copy is being added (the edition and its work are locked shared, as CopyService does).
        $other = $this->other();
        $other->beginTransaction();
        $other->query("SELECT id FROM editions WHERE id = {$edition} LOCK IN SHARE MODE")->fetchAll();
        $other->query("SELECT id FROM works WHERE id = {$work} LOCK IN SHARE MODE")->fetchAll();
        $other->exec("INSERT INTO copies (edition_id, owner_user_id, household_id, status, created_at, updated_at) VALUES ({$edition}, {$this->beaId}, {$this->piso}, 'available', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame(409, $saveEdition()->status(), 'The edit waits for the copy');
        self::assertSame(409, $saveWork()->status());
        self::assertSame('Casa Book', $this->pdo()->query("SELECT title FROM editions WHERE id = {$edition}")->fetchColumn());
        // Committed: the record is shared now, and the same form is refused.
        $other->commit();
        self::assertSame(403, $saveEdition()->status());
        self::assertSame(403, $saveWork()->status());
        self::assertSame('Casa Book', $this->pdo()->query("SELECT title FROM works WHERE id = {$work}")->fetchColumn());

        // The other way round: an edit of the edition (or of its work) is in flight, and a copy
        // by ISBN from another household waits for it.
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $copies = $this->services($other)->copyService();
        $isbn = \DigitalLibrary\Catalogue\Isbn::tryParse('9780132350884');
        $input = new \DigitalLibrary\Catalogue\Input\CopyInput((string) $this->anaId, '', '', '', 'available', false, '', '', '', '', '');
        foreach (['editions' => $this->pisoBook['edition_id'], 'works' => $this->pisoBook['work_id']] as $table => $id) {
            $this->pdo()->beginTransaction();
            $this->pdo()->query("SELECT id FROM {$table} WHERE id = {$id} FOR UPDATE")->fetchAll();
            try {
                $copies->createByIsbn($this->anaId, $isbn, $input);
                self::fail("A copy overtook an edit of the {$table} row");
            } catch (WriteBusyException) {
            }
            $this->pdo()->rollBack();
            self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM copies WHERE edition_id = {$this->pisoBook['edition_id']} AND owner_user_id = {$this->anaId}"));
        }
        self::assertFalse($other->inTransaction());
        $created = $copies->createByIsbn($this->anaId, $isbn, $input);
        self::assertSame([$this->pisoBook['edition_id'], $this->casa], [$created['edition_id'], $created['household_id']]);
    }

    public function testParallelCopiesWithTheSameInventoryCodeYieldExactlyOnePerHousehold(): void
    {
        $run = function (array $jobs): array {
            $start = microtime(true) + 0.8;
            $handles = [];
            foreach ($jobs as $job) {
                $handles[] = $this->spawn($job + ['mode' => 'copy', 'start' => $start]);
            }
            $out = [];
            foreach ($handles as [$worker, , $stdout]) {
                $out[] = trim((string) fgets($stdout));
                self::assertSame(0, proc_close($worker), end($out));
                array_pop($this->workers);
            }
            sort($out);

            return $out;
        };
        $ana = ['actor' => $this->anaId, 'edition' => $this->casaBook['edition_id'], 'household' => $this->casa];
        $bea = ['actor' => $this->beaId, 'edition' => $this->pisoBook['edition_id'], 'household' => $this->piso];

        // Six requests for the same code in one household: one copy, five "already in use", no server error.
        $outcomes = $run(array_fill(0, 6, $ana + ['code' => 'RACE-1']));
        self::assertSame(['ok', 'taken', 'taken', 'taken', 'taken', 'taken'], $outcomes);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE inventory_code = 'RACE-1'"));

        // The same code in two households at once: both succeed, and each household still allows only one.
        $outcomes = $run([$ana + ['code' => 'RACE-2'], $bea + ['code' => 'RACE-2'], $ana + ['code' => 'RACE-2'], $bea + ['code' => 'RACE-2']]);
        self::assertSame(['ok', 'ok', 'taken', 'taken'], $outcomes);
        self::assertSame([1, 1], [
            $this->countRows("SELECT COUNT(*) FROM copies WHERE inventory_code = 'RACE-2' AND household_id = {$this->casa}"),
            $this->countRows("SELECT COUNT(*) FROM copies WHERE inventory_code = 'RACE-2' AND household_id = {$this->piso}"),
        ]);
    }

    public function testACopyBeingLentCannotBeMovedOrDeletedMeanwhile(): void
    {
        [$ana, $token] = $this->signedIn('ana@example.org');
        $copy = (int) $this->casaBook['copy_id'];
        // A loan of the copy is in flight on another connection (the copy row is locked, as LoanService does).
        $other = $this->other();
        $other->beginTransaction();
        $other->query("SELECT id FROM copies WHERE id = {$copy} FOR UPDATE")->fetchAll();
        $other->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$copy}, 'Racer', UTC_DATE(), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $other->exec("UPDATE copies SET status = 'on_loan', row_version = row_version + 1 WHERE id = {$copy}");

        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        self::assertSame(409, $this->request($ana, 'POST', "/copies/{$copy}/delete", ['_csrf' => $token])->status());
        self::assertSame(409, $this->request($ana, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'withdrawn', 'row_version' => '1'])->status());
        $other->commit();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');

        // After the loan: the copy has history (kept, not deleted) and "on loan" cannot be edited away.
        self::assertSame(303, $this->request($ana, 'POST', "/copies/{$copy}/delete", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE id = {$copy}"));
        self::assertSame(409, $this->request($ana, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'on_loan', 'row_version' => '1'])->status(), 'The stale form is a conflict');
        self::assertSame(422, $this->request($ana, 'POST', "/copies/{$copy}", ['_csrf' => $token, 'owner_user_id' => (string) $this->anaId, 'status' => 'withdrawn', 'row_version' => $this->rowVersion('copies', $copy)])->status());
        self::assertSame('on_loan', $this->pdo()->query("SELECT status FROM copies WHERE id = {$copy}")->fetchColumn());
    }

    #[\PHPUnit\Framework\Attributes\TestWith([true])]
    #[\PHPUnit\Framework\Attributes\TestWith([false])]
    public function testConcurrentRootLocationsCannotDuplicateNames(bool $enabled): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = $enabled ? 'true' : 'false';
        $other = $this->other();
        $other->beginTransaction();
        try {
            // A location creation paused before commit, with the H3 household locks.
            $other->query('SELECT id FROM households ORDER BY id LOCK IN SHARE MODE')->fetchAll();
            $household = $enabled ? $this->casa : $this->defaultHouseholdId();
            $other->exec("INSERT INTO locations (household_id, name, kind, created_at, updated_at) VALUES ({$household}, 'Concurrent root', 'room', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $this->services()->locationService()->create($this->adminId, null, (string) $this->casa, 'Concurrent root', 'room', null);
                self::fail('A concurrent root creation passed the uncommitted duplicate');
            } catch (WriteBusyException) {
                self::assertFalse($this->pdo()->inTransaction());
            }
            $other->commit();
            try {
                $this->services()->locationService()->create($this->adminId, null, (string) $this->casa, 'Concurrent root', 'room', null);
                self::fail('The committed duplicate root was accepted');
            } catch (\DigitalLibrary\Households\LocationException $e) {
                self::assertSame('validation.name_taken', $e->getMessage());
            }
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM locations WHERE name = 'Concurrent root'"));
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    public function testDisjointLocationMovesCannotCreateACycle(): void
    {
        $locations = $this->services()->locationService();
        $create = fn (?int $parent, string $name): int => $locations->create($this->adminId, $parent, (string) $this->casa, $name, 'room', null)['id'];
        $a = $create(null, 'A');
        $b = $create($a, 'B');
        $c = $create(null, 'C');
        $d = $create($c, 'D');
        $other = $this->other();
        $other->beginTransaction();
        try {
            $other->query('SELECT id FROM households ORDER BY id LOCK IN SHARE MODE')->fetchAll();
            $other->exec("UPDATE locations SET parent_id = {$d} WHERE id = {$a}");
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
            try {
                $locations->update($this->adminId, $c, $b, 'C', 'room', null);
                self::fail('Disjoint moves were allowed to pass each other');
            } catch (WriteBusyException) {
                self::assertFalse($this->pdo()->inTransaction());
            }
            $other->commit();
            try {
                $locations->update($this->adminId, $c, $b, 'C', 'room', null);
                self::fail('The cycle was accepted');
            } catch (\DigitalLibrary\Households\LocationException $e) {
                self::assertSame('validation.parent_cycle', $e->getMessage());
            }
            self::assertNull($this->pdo()->query("SELECT parent_id FROM locations WHERE id = {$c}")->fetchColumn());
        } finally {
            if ($other->inTransaction()) {
                $other->rollBack();
            }
        }
    }

    // --- Helpers -----------------------------------------------------------------------------

    private function other(): PDO
    {
        return $this->other ??= ConnectionFactory::fromConfig(self::config());
    }

    private function services(?PDO $pdo = null): Services
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $pdo ?? $this->pdo());

        return $services;
    }

    private function guard(): WriteGuard
    {
        return $this->services()->writeGuard();
    }

    /**
     * Starts tests/Support/concurrency_worker.php with its own connection
     * to the test database.
     *
     * @param array<string, mixed> $job
     *
     * @return array{resource, resource, resource} process, its stdin, its stdout
     */
    private function spawn(array $job): array
    {
        $config = self::config();
        $job['config'] = ['APP_ENV' => 'testing', 'APP_URL' => 'http://library.test', 'HOUSEHOLDS_ENABLED' => 'true'];
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $job['config'][$key] = (string) $config->get($key);
        }
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/concurrency_worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['DLA_WORKER' => json_encode($job, JSON_THROW_ON_ERROR)] + getenv(),
        );
        self::assertIsResource($process);
        $this->workers[] = $process;

        return [$process, $pipes[0], $pipes[1]];
    }
}
