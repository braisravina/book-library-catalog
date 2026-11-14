<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Households\InventoryTransferService;
use DigitalLibrary\Households\TransferException;
use DigitalLibrary\Households\WriteBusyException;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Security\Role;
use PDO;
use PHPUnit\Framework\Attributes\TestWith;

final class HouseholdTransferTest extends IntegrationTestCase
{
    private int $admin;
    private int $owner;
    private int $viewer;
    private int $source;
    private int $destination;
    private int $root;
    private int $shelf;
    private int $target;
    private int $copy;
    private int $second;

    protected function setUp(): void
    {
        parent::setUp();
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $this->admin = $this->createUser('admin@example.org', Role::Admin);
        $this->owner = $this->createUser('owner@example.org', Role::Member);
        $this->viewer = $this->createUser('viewer@example.org', Role::Viewer);
        $this->source = $this->createHousehold('Source', [$this->owner, $this->viewer]);
        $this->destination = $this->createHousehold('Destination', [$this->owner]);
        $service = $this->services()->locationService();
        $this->root = $service->create($this->admin, null, (string) $this->source, 'Root', 'room', null)['id'];
        $this->shelf = $service->create($this->admin, $this->root, '', 'Shelf', 'shelf', null)['id'];
        $this->target = $service->create($this->admin, null, (string) $this->destination, 'Target', 'room', null)['id'];
        $book = $this->makeBook($this->owner, 'Preserved title', '9788437604947', [], [], [], $this->owner, $this->shelf, $this->source);
        $this->copy = $book['copy_id'];
        $this->second = $this->makeCopy($book['edition_id'], $this->owner, $this->source, $this->root, 'SECOND');
        $this->pdo()->exec("UPDATE copies SET inventory_code = 'Original-1', notes = 'Private note', acquisition_source = 'Gift', copy_condition = 'fair', acquired_on = '2020-01-02', purchase_price_cents = 1250, currency = 'EUR' WHERE id = {$this->copy}");
    }

    private function services(): Services
    {
        $s = new Services(self::config(), dirname(__DIR__, 2));
        $s->set(PDO::class, $this->pdo());
        return $s;
    }

    private function service(): InventoryTransferService
    {
        return $this->services()->inventoryTransfers();
    }

    private function input(array $changes = []): array
    {
        return $changes + ['kind' => 'copies', 'source' => $this->source, 'destination' => $this->destination,
            'selected' => [$this->copy . ':' . $this->rowVersion('copies', $this->copy)], 'location' => $this->target, 'reason' => 'Reviewed relocation'];
    }

    private function apply(int $id): array
    {
        return $this->service()->apply($this->admin, $id, $this->service()->show($this->admin, $id)['digest']);
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (['copies', 'locations', 'loans', 'works', 'editions', 'household_members', 'collections', 'invitations', 'audit_log'] as $table) {
            $out[$table] = $this->pdo()->query('SELECT * FROM ' . $table)->fetchAll();
        }
        return $out;
    }

    private function refused(callable $work, string $key): void
    {
        $before = $this->snapshot();
        try {
            $work();
            self::fail('Invalid operation succeeded');
        } catch (TransferException $e) {
            self::assertContains('transfers.error.' . $key, array_column($e->conflicts, 'key'));
        }
        self::assertSame($before, $this->snapshot());
    }

    public function testDryRunPlansApplicationPreservationAndIdempotency(): void
    {
        $before = $this->snapshot();
        $preview = $this->service()->preview($this->admin, $this->input());
        self::assertSame($before, $this->snapshot());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM inventory_transfers'));
        self::assertSame([$this->copy], array_column($preview['copies'], 'id'));
        $id = $this->service()->plan($this->admin, $this->input());
        self::assertSame($before, $this->snapshot(), 'Saving a plan changes no inventory or audit');
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM inventory_transfer_items'));
        self::assertSame('completed', $this->apply($id)['status']);
        $after = $this->pdo()->query("SELECT * FROM copies WHERE id = {$this->copy}")->fetch();
        $original = $before['copies'][0];
        foreach (['household_id', 'location_id', 'row_version', 'updated_by', 'updated_at'] as $field) {
            unset($original[$field], $after[$field]);
        }
        // Generated scope necessarily follows household; every other business field survives.
        unset($original['inventory_scope_id'], $after['inventory_scope_id']);
        self::assertSame($original, $after);
        $completed = $this->snapshot();
        self::assertSame('completed', $this->apply($id)['status']);
        self::assertSame($completed, $this->snapshot());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'inventory.%'"));
    }

    #[TestWith(['source', 999999, 'household'])]
    #[TestWith(['destination', null, 'household'])]
    #[TestWith(['selected', ['1:1', '1:1'], 'input'])]
    #[TestWith(['selected', [['id' => 1]], 'input'])]
    #[TestWith(['selected', [], 'limit'])]
    #[TestWith(['selected', ['999999:1'], 'missing'])]
    #[TestWith(['reason', '', 'input'])]
    #[TestWith(['reason', [], 'input'])]
    #[TestWith(['kind', 'arbitrary', 'input'])]
    public function testMalformedPlansAreRejectedWithoutWrites(string $field, mixed $value, string $error): void
    {
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input([$field => $value])), $error);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM inventory_transfers'));
    }

    public function testWrongVersionsSourcesLocationsAndLimits(): void
    {
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['selected' => [$this->copy . ':999']])), 'stale');
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['source' => null])), 'source');
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['location' => $this->shelf])), 'location');
        $tokens = [];
        for ($i = 1; $i <= 101; $i++) { $tokens[] = $i . ':1'; }
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['selected' => $tokens])), 'limit');
        self::$configOverrides['HOUSEHOLD_TRANSFER_LIMIT'] = '1';
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['selected' => [$this->copy . ':1', $this->second . ':1']])), 'limit');
    }

    public function testMembershipAndAccountChangesInvalidateConfirmation(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$this->destination} AND user_id = {$this->owner}");
        $this->refused(fn () => $this->apply($id), 'owner');
        $this->addHouseholdMember($this->destination, $this->owner);
        $this->pdo()->exec("UPDATE users SET is_active = 0 WHERE id = {$this->owner}");
        $this->refused(fn () => $this->apply($id), 'owner');
    }

    public function testLoanCreationAfterPreviewAndHistoricalLoans(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$this->copy}, 'Historical borrower', '2020-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->refused(fn () => $this->apply($id), 'loan');
        $this->pdo()->exec("UPDATE loans SET returned_on = '2020-01-03'");
        $history = $this->pdo()->query('SELECT * FROM loans')->fetchAll();
        $this->apply($id);
        self::assertSame($history, $this->pdo()->query('SELECT * FROM loans')->fetchAll());
        [$viewer] = $this->signedIn('viewer@example.org');
        self::assertSame(404, $this->request($viewer, 'GET', '/copies/' . $this->copy)->status());
        [$owner] = $this->signedIn('owner@example.org');
        self::assertStringContainsString('Historical borrower', $this->request($owner, 'GET', '/copies/' . $this->copy)->body());
    }

    #[TestWith(['original-1'])]
    #[TestWith(['Óriginal-1'])]
    public function testDestinationCodesUseDatabaseCollation(string $code): void
    {
        $edition = (int) $this->pdo()->query("SELECT edition_id FROM copies WHERE id = {$this->copy}")->fetchColumn();
        $this->makeCopy($edition, $this->owner, $this->destination, null, $code);
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['selected' => [$this->copy . ':1', $this->second . ':1']])), 'code');
    }

    public function testBatchAtomicityOnConflictAfterPreview(): void
    {
        $id = $this->service()->plan($this->admin, $this->input(['selected' => [$this->copy . ':1', $this->second . ':1']]));
        $edition = (int) $this->pdo()->query("SELECT edition_id FROM copies WHERE id = {$this->copy}")->fetchColumn();
        $this->makeCopy($edition, $this->owner, $this->destination, null, 'SECOND');
        $this->refused(fn () => $this->apply($id), 'code');
        self::assertSame('planned', $this->service()->show($this->admin, $id)['status']);
    }

    public function testStaleCopyAndDestinationInvalidateTheSavedPlan(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $this->pdo()->exec("UPDATE copies SET row_version = row_version + 1 WHERE id = {$this->copy}");
        $this->refused(fn () => $this->apply($id), 'stale');
        $id = $this->service()->plan($this->admin, $this->input());
        $this->pdo()->exec("UPDATE locations SET row_version = row_version + 1 WHERE id = {$this->target}");
        $this->refused(fn () => $this->apply($id), 'stale');
    }

    private function subtree(): array
    {
        return $this->input(['kind' => 'subtree', 'selected' => [], 'root' => $this->root, 'root_version' => 1, 'parent' => $this->target, 'location' => null]);
    }

    public function testSubtreeMovesAllIdsAndHierarchyAtomically(): void
    {
        $id = $this->service()->plan($this->admin, $this->subtree());
        $preview = $this->service()->show($this->admin, $id)['plan'];
        self::assertSame([$this->copy, $this->second], array_column($preview['copies'], 'id'));
        self::assertSame([$this->root, $this->shelf], array_column($preview['locations'], 'id'));
        $this->apply($id);
        self::assertSame($this->target, $this->pdo()->query("SELECT parent_id FROM locations WHERE id = {$this->root}")->fetchColumn());
        self::assertSame($this->root, $this->pdo()->query("SELECT parent_id FROM locations WHERE id = {$this->shelf}")->fetchColumn());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM copies WHERE household_id = {$this->destination}"));
        self::assertSame($this->shelf, $this->pdo()->query("SELECT location_id FROM copies WHERE id = {$this->copy}")->fetchColumn());
    }

    public function testNewDescendantsAndCopiesCannotBeAbsorbedByAnOldPreview(): void
    {
        $id = $this->service()->plan($this->admin, $this->subtree());
        $this->services()->locationService()->create($this->admin, $this->shelf, '', 'New child', 'box', null);
        $this->refused(fn () => $this->apply($id), 'stale');
        $id = $this->service()->plan($this->admin, $this->subtree());
        $edition = (int) $this->pdo()->query("SELECT edition_id FROM copies WHERE id = {$this->copy}")->fetchColumn();
        $this->makeCopy($edition, $this->owner, $this->source, $this->shelf);
        $this->refused(fn () => $this->apply($id), 'stale');
    }

    public function testMixedTreesCyclesAndNamesAreRefused(): void
    {
        $input = $this->subtree();
        $input['parent'] = $this->shelf;
        $this->refused(fn () => $this->service()->plan($this->admin, $input), 'tree');
        $this->pdo()->exec("UPDATE locations SET household_id = {$this->destination} WHERE id = {$this->shelf}");
        $this->refused(fn () => $this->service()->plan($this->admin, $this->subtree()), 'tree');
        $this->pdo()->exec("UPDATE locations SET household_id = {$this->source} WHERE id = {$this->shelf}");
        $this->services()->locationService()->create($this->admin, $this->target, '', 'Root', 'room', null);
        $this->refused(fn () => $this->service()->plan($this->admin, $this->subtree()), 'name');
    }

    public function testActiveSubtreeLoanAndMissingDescendantRejectTheWholeOperation(): void
    {
        $id = $this->service()->plan($this->admin, $this->subtree());
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, created_at, updated_at) VALUES ({$this->second}, 'Borrower', '2020-01-01', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->refused(fn () => $this->apply($id), 'loan');
        $this->pdo()->exec("UPDATE loans SET returned_on = '2020-01-02'");
        $this->services()->locationService()->update($this->admin, $this->shelf, null, 'Shelf', 'shelf', null, 1);
        $this->refused(fn () => $this->apply($id), 'stale');
    }

    public function testDeletingDestinationInvalidatesPlanWithoutDeletingEvidence(): void
    {
        $id = $this->service()->plan($this->admin, $this->input(['location' => null]));
        $this->pdo()->exec("DELETE FROM locations WHERE household_id = {$this->destination}");
        $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$this->destination}");
        $this->pdo()->exec("DELETE FROM households WHERE id = {$this->destination}");
        $this->refused(fn () => $this->apply($id), 'household');
        self::assertSame('planned', $this->service()->show($this->admin, $id)['status']);
    }

    public function testNullSourceIsNotTheDefaultHousehold(): void
    {
        $this->pdo()->exec("UPDATE copies SET household_id = NULL, location_id = NULL WHERE id = {$this->copy}");
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['source' => $this->defaultHouseholdId()])), 'source');
        $id = $this->service()->plan($this->admin, $this->input(['source' => null]));
        $this->apply($id);
        self::assertSame($this->destination, $this->pdo()->query("SELECT household_id FROM copies WHERE id = {$this->copy}")->fetchColumn());
        self::assertTrue($this->services()->households()->exists($this->defaultHouseholdId()));
    }

    public function testOwnerReassignmentIsSeparateAndHistoryIsNeverDisclosed(): void
    {
        $input = $this->input(['kind' => 'owner', 'destination' => $this->source, 'location' => $this->shelf, 'owner' => $this->admin]);
        $id = $this->service()->plan($this->admin, $input);
        $this->apply($id);
        self::assertSame($this->admin, $this->pdo()->query("SELECT owner_user_id FROM copies WHERE id = {$this->copy}")->fetchColumn());
        $this->pdo()->exec("INSERT INTO loans (copy_id, borrower_name, loaned_on, returned_on, created_at, updated_at) VALUES ({$this->copy}, 'Private borrower', '2020-01-01', '2020-01-02', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->refused(fn () => $this->service()->plan($this->admin, $this->input(['kind' => 'owner', 'destination' => $this->source, 'location' => $this->shelf, 'owner' => $this->viewer])), 'owner_history');
    }

    public function testUnassignedOwnershipCanOnlyBeReassignedToAnAdministrator(): void
    {
        $this->pdo()->exec("UPDATE copies SET household_id = NULL, location_id = NULL WHERE id = {$this->copy}");
        $input = $this->input(['kind' => 'owner', 'source' => null, 'destination' => null, 'location' => null, 'owner' => $this->viewer]);
        $this->refused(fn () => $this->service()->plan($this->admin, $input), 'owner');
        $input['owner'] = $this->admin;
        $id = $this->service()->plan($this->admin, $input);
        $this->apply($id);
        self::assertNull($this->pdo()->query("SELECT household_id FROM copies WHERE id = {$this->copy}")->fetchColumn());
        self::assertSame($this->admin, $this->pdo()->query("SELECT owner_user_id FROM copies WHERE id = {$this->copy}")->fetchColumn());
    }

    public function testInvalidConfirmationCancelledPlanAndRevokedAdministrator(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $this->refused(fn () => $this->service()->apply($this->admin, $id, 'wrong'), 'confirmation');
        $this->service()->cancel($this->admin, $id);
        $this->refused(fn () => $this->apply($id), 'cancelled');
        $this->pdo()->exec("UPDATE users SET role_id = 2 WHERE id = {$this->admin}");
        $this->expectException(WriteDeniedException::class);
        $this->apply($id);
    }

    public function testAuditFailureRollsBackEveryInventoryChange(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $before = $this->pdo()->query('SELECT * FROM copies ORDER BY id')->fetchAll();
        $this->migrationPdo()->exec('RENAME TABLE audit_log TO h4_audit_unavailable');
        try {
            try { $this->apply($id); self::fail('Audit failure was ignored'); } catch (\PDOException) {}
        } finally {
            $this->migrationPdo()->exec('RENAME TABLE h4_audit_unavailable TO audit_log');
        }
        self::assertSame($before, $this->pdo()->query('SELECT * FROM copies ORDER BY id')->fetchAll());
        self::assertSame('planned', $this->service()->show($this->admin, $id)['status']);
        $this->apply($id);
    }

    public function testConcurrentMembershipChangeBlocksThenRevalidatesTransfer(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $other = ConnectionFactory::fromConfig(self::config());
        $other->beginTransaction();
        $other->query("SELECT id FROM households WHERE id = {$this->destination} FOR UPDATE")->fetchAll();
        $other->exec("DELETE FROM household_members WHERE household_id = {$this->destination} AND user_id = {$this->owner}");
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            try { $this->apply($id); self::fail('Concurrent transfer passed membership lock'); } catch (WriteBusyException) {}
            self::assertFalse($this->pdo()->inTransaction());
            $other->commit();
            $this->refused(fn () => $this->apply($id), 'owner');
        } finally {
            if ($other->inTransaction()) { $other->rollBack(); }
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
    }

    public function testParallelConfirmationCommitsOnce(): void
    {
        $id = $this->service()->plan($this->admin, $this->input());
        $operation = $this->service()->show($this->admin, $id);
        $config = self::config();
        $values = ['APP_ENV' => 'testing', 'HOUSEHOLDS_ENABLED' => 'true'];
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $values[$key] = (string) $config->get($key);
        }
        $job = ['mode' => 'transfer', 'start' => microtime(true) + 0.5, 'config' => $values,
            'actor' => $this->admin, 'operation' => $id, 'digest' => $operation['digest']];
        $workers = [];
        try {
            for ($i = 0; $i < 2; $i++) {
                $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/concurrency_worker.php'],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
                    ['DLA_WORKER' => json_encode($job, JSON_THROW_ON_ERROR)] + getenv());
                self::assertIsResource($process);
                stream_set_timeout($pipes[1], 15);
                $workers[] = [$process, $pipes];
            }
            foreach ($workers as [$process, $pipes]) {
                self::assertSame('completed', trim((string) fgets($pipes[1])));
                self::assertSame(0, proc_close($process));
            }
            self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'inventory.%'"));
            self::assertSame('2', $this->rowVersion('copies', $this->copy));
        } finally {
            foreach ($workers as [$process, $pipes]) {
                foreach ($pipes as $pipe) { if (is_resource($pipe)) { fclose($pipe); } }
                if (is_resource($process)) { proc_terminate($process); proc_close($process); }
            }
        }
    }

    public function testLocationVersionsRejectStaleAndMissingForms(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $body = ['_csrf' => $token, 'name' => 'Renamed', 'kind' => 'shelf', 'parent_id' => (string) $this->root];
        self::assertSame(409, $this->request($admin, 'POST', '/admin/locations/' . $this->shelf, $body)->status());
        self::assertSame(303, $this->request($admin, 'POST', '/admin/locations/' . $this->shelf, $body + ['row_version' => '1'])->status());
        self::assertSame(409, $this->request($admin, 'POST', '/admin/locations/' . $this->shelf, $body + ['row_version' => '1'])->status());
    }

    public function testOldCopyEndpointCannotMoveOrReassignEvenForAdmin(): void
    {
        foreach (['admin@example.org', 'owner@example.org'] as $email) {
            [$browser, $token] = $this->signedIn($email);
            $body = ['_csrf' => $token, 'owner_user_id' => (string) $this->owner, 'status' => 'available', 'row_version' => '1'];
            self::assertSame(422, $this->request($browser, 'POST', '/copies/' . $this->copy, $body + ['household_id' => (string) $this->destination])->status());
            self::assertSame(303, $this->request($browser, 'POST', '/copies/' . $this->copy, $body + ['household_id' => (string) $this->source])->status());
            // Restore the fixture version for the next actor, as no transfer occurred.
            $this->pdo()->exec("UPDATE copies SET row_version = 1 WHERE id = {$this->copy}");
        }
    }

    public function testOneHundredCopyBoundaryAndNullCodesArePreserved(): void
    {
        $edition = (int) $this->pdo()->query("SELECT edition_id FROM copies WHERE id = {$this->copy}")->fetchColumn();
        $ids = [$this->copy, $this->second];
        while (count($ids) < 100) { $ids[] = $this->makeCopy($edition, $this->owner, $this->source); }
        $id = $this->service()->plan($this->admin, $this->input(['selected' => array_map(static fn (int $id): string => $id . ':1', $ids)]));
        $this->apply($id);
        self::assertSame(100, $this->countRows("SELECT COUNT(*) FROM copies WHERE household_id = {$this->destination}"));
        self::assertSame(98, $this->countRows('SELECT COUNT(*) FROM copies WHERE inventory_code IS NULL'));
        self::assertSame(101, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action LIKE 'inventory.%'"));
    }

    public function testLocalPreparationCommandIsReadOnlyAndExclusivelyLocked(): void
    {
        $dir = sys_get_temp_dir() . '/dla-h4-rehearsal-' . bin2hex(random_bytes(5));
        mkdir($dir, 0700);
        self::$configOverrides['TEMP_PATH'] = $dir;
        self::$configOverrides['MAINTENANCE_FLAG_PATH'] = $dir . '/maintenance';
        self::$configOverrides['APP_ROLE'] = 'ops';
        $services = $this->services();
        $services->maintenance()->enable('Local rehearsal');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        $console = new \DigitalLibrary\Console\Console($services, out: $out, err: $err);
        $args = ['console', 'inventory:check', '--actor=' . $this->admin, '--writers-stopped', '--confirm=LOCAL-INVENTORY-CHECK'];
        $before = $this->snapshot();
        try {
            $lock = \DigitalLibrary\Console\OperationLock::acquire($dir, 'other-rehearsal');
            try { self::assertNotSame(0, $console->run($args)); } finally { $lock->release(); }
            self::assertSame(0, $console->run($args));
            rewind($out);
            $report = json_decode(stream_get_contents($out), true, 16, JSON_THROW_ON_ERROR);
            self::assertArrayHasKey('dependencies', $report);
            self::assertSame($before, $this->snapshot());
        } finally {
            fclose($out); fclose($err);
            foreach (glob($dir . '/*') ?: [] as $file) { unlink($file); }
            rmdir($dir);
        }
    }

    #[TestWith(['en'])]
    #[TestWith(['es'])]
    #[TestWith(['gl'])]
    public function testRoutesFormsRolesCsrfAndTranslations(string $locale): void
    {
        [$admin, $csrf] = $this->signedIn('admin@example.org');
        $this->request($admin, 'POST', '/locale', ['_csrf' => $csrf, 'locale' => $locale, 'return_to' => '/admin/inventory']);
        $page = $this->request($admin, 'GET', '/admin/inventory?source=' . $this->source);
        self::assertSame(200, $page->status());
        self::assertStringContainsString('name="selected[]"', $page->body());
        self::assertStringNotContainsString('transfers.', $page->body());
        self::assertSame(419, $this->request($admin, 'POST', '/admin/inventory/preview', [])->status());
        $body = ['_csrf' => $csrf, 'source' => (string) $this->source, 'destination' => (string) $this->destination,
            'location' => (string) $this->target, 'selected' => [$this->copy . ':1'], 'reason' => 'Reviewed'];
        self::assertSame(422, $this->request($admin, 'POST', '/admin/inventory/preview', array_replace($body, ['location' => []]))->status(), 'Malformed location identifiers must not become an unlocated assignment');
        self::assertSame(200, $this->request($admin, 'POST', '/admin/inventory/preview', $body + ['intent' => 'dry-run'])->status());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM inventory_transfers'));
        self::assertSame(303, $this->request($admin, 'POST', '/admin/inventory/preview', $body)->status());
        $id = (int) $this->pdo()->query('SELECT id FROM inventory_transfers')->fetchColumn();
        self::assertSame(409, $this->request($admin, 'POST', "/admin/inventory/transfers/{$id}/apply", ['_csrf' => $csrf])->status());
        foreach (['owner@example.org', 'viewer@example.org'] as $email) {
            [$browser, $token] = $this->signedIn($email);
            foreach (['/admin/inventory', "/admin/inventory/transfers/{$id}", '/admin/inventory/transfers/999999'] as $url) {
                $response = $this->request($browser, 'GET', $url);
                self::assertSame(403, $response->status());
                self::assertStringNotContainsString('Original-1', $response->body());
            }
            self::assertSame(403, $this->request($browser, 'POST', "/admin/inventory/transfers/{$id}/apply", ['_csrf' => $token, 'confirmed' => 'yes'])->status());
        }
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'false';
        self::assertSame(404, $this->request($admin, 'GET', '/admin/inventory')->status());
        self::assertSame(404, $this->request($admin, 'POST', '/admin/inventory/preview', $body)->status());
    }
}
