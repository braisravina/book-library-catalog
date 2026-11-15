<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Households\HouseholdInput;
use DigitalLibrary\Households\WriteDeniedException;
use DigitalLibrary\Security\Role;
use PDO;

final class AdministratorRevocationTest extends IntegrationTestCase
{
    private function services(?PDO $pdo = null): Services
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $pdo ?? $this->pdo());
        return $services;
    }

    public function testCommittedRevocationRejectsEveryStaleAdministrativeServiceCallWithoutPartialWrites(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $actorId = $this->createUser('actor@example.org', Role::Admin);
        $targetId = $this->createUser('target@example.org', Role::Member);
        $household = $this->createHousehold('Protected', [$targetId]);
        $services = $this->services();
        $actor = $services->users()->findById($actorId);
        $target = $services->users()->findById($targetId);
        $invitation = $services->invitationService()->issue($actor, 'invited@example.org', Role::Viewer, gmdate('Y-m-d', time() + 86400));
        $operations = [
            fn () => $services->householdService()->create($actor, new HouseholdInput('Forbidden', '')),
            fn () => $services->householdService()->update($actor, $household, new HouseholdInput('Forbidden', ''), 1),
            fn () => $services->householdService()->delete($actor, $household),
            fn () => $services->householdService()->addMember($actor, $household, $actorId),
            fn () => $services->householdService()->removeMember($actor, $household, $targetId),
            fn () => $services->userAdministration()->create($actor, 'forbidden@example.org', 'Forbidden', Role::Admin, 'hash', 'en', [$household]),
            fn () => $services->userAdministration()->update($actor, $target, $target->email, 'Forbidden', Role::Admin, 'en', true),
            fn () => $services->userAdministration()->resetPassword($actor, $target, 'forbidden'),
            fn () => $services->userAdministration()->revokeSessions($actor, $target),
            fn () => $services->invitationService()->issue($actor, 'forbidden@example.org', Role::Viewer, gmdate('Y-m-d', time() + 86400)),
            fn () => $services->invitationService()->replace($actor, $invitation['id'], gmdate('Y-m-d', time() + 86400)),
            fn () => $services->invitationService()->revoke($actor, $invitation['id']),
        ];
        $other = ConnectionFactory::fromConfig(self::config());
        foreach (['role_id = 2', 'role_id = 1, is_active = 0'] as $revocation) {
            // Connection B commits revocation BEFORE connection A enters its mutation.
            $other->exec("UPDATE users SET {$revocation} WHERE id = {$actorId}");
            $before = $this->state();
            foreach ($operations as $operation) {
                try {
                    $operation();
                    self::fail('Stale administrator performed a mutation');
                } catch (WriteDeniedException $e) {
                    self::assertFalse($e->notFound);
                }
                self::assertSame($before, $this->state());
                self::assertFalse($this->pdo()->inTransaction());
            }
        }
    }

    public function testInFlightAuthorizationHoldsRevocationUntilTheMutationCommits(): void
    {
        $actorId = $this->createUser('actor@example.org', Role::Admin);
        $revokerId = $this->createUser('revoker@example.org', Role::Admin);
        $services = $this->services();
        $other = ConnectionFactory::fromConfig(self::config());
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $revoker = $this->services($other)->users()->findById($revokerId);
        $target = $services->users()->findById($actorId);
        // A has authorized under its shared account lock. B attempts real
        // account administration while A remains open; B must wait/roll back.
        $services->administratorGuard()->run($actorId, function () use ($actorId, $revoker, $target, $other): void {
            $this->pdo()->exec("INSERT INTO publishers (name, created_at, updated_at) VALUES ('In flight', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            try {
                $this->services($other)->userAdministration()->update($revoker, $target, $target->email, $target->displayName, Role::Member, 'en', true);
                self::fail('Revocation overtook the authorized mutation');
            } catch (\DigitalLibrary\Households\WriteBusyException $e) {
                self::assertSame(1205, $e->getPrevious()->errorInfo[1]);
            }
            self::assertFalse($other->inTransaction());
            self::assertSame('admin', $this->services()->users()->findById($actorId)->role->value);
            $this->services()->audit()->log('publisher.created', $actorId, 'publisher', (int) $this->pdo()->lastInsertId());
        });
        // A commits first, then B can commit. A later mutation is refused.
        $this->services($other)->userAdministration()->update($revoker, $target, $target->email, $target->displayName, Role::Member, 'en', true);
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM publishers WHERE name = 'In flight'"));
        $this->expectException(WriteDeniedException::class);
        $services->administratorGuard()->run($actorId, static fn () => null);
    }

    public function testAuditInsertFailureRollsBackMembershipAndAccountCreation(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $actorId = $this->createUser('actor@example.org', Role::Admin);
        $targetId = $this->createUser('target@example.org', Role::Member);
        $household = $this->createHousehold('Protected');
        $services = $this->services();
        $actor = $services->users()->findById($actorId);
        // The DML account cannot INSERT into the temporarily renamed audit
        // table: no mock logger and no successful domain commit without it.
        $this->migrationPdo()->exec('RENAME TABLE audit_log TO audit_log_unavailable');
        try {
            foreach ([
                fn () => $services->householdService()->addMember($actor, $household, $targetId),
                fn () => $services->userAdministration()->create($actor, 'atomic@example.org', 'Atomic', Role::Admin, 'hash', 'en', [$household]),
                fn () => $services->invitationService()->issue($actor, 'atomic@example.org', Role::Member, gmdate('Y-m-d', time() + 86400), [$household]),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('Missing audit storage was ignored');
                } catch (\PDOException $e) {
                    self::assertSame('42S02', $e->getCode());
                }
                self::assertFalse($this->pdo()->inTransaction());
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"));
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'atomic@example.org'"));
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM invitations WHERE email = 'atomic@example.org'"));
            }
        } finally {
            $this->migrationPdo()->exec('RENAME TABLE audit_log_unavailable TO audit_log');
        }
    }

    public function testSeparateProcessesRespectBothAdministratorCommitOrders(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $actor = $this->createUser('actor@example.org', Role::Admin);
        $target = $this->createUser('target@example.org', Role::Member);
        $household = $this->createHousehold('Concurrent');
        foreach (['membership', 'create'] as $operation) {
            foreach (['role_id = 2', 'is_active = 0'] as $revocation) {
                $this->pdo()->exec("UPDATE users SET role_id = 1, is_active = 1 WHERE id = {$actor}");
                [$process, $in, $out] = $this->worker(compact('actor', 'target', 'household', 'operation') + ['mode' => 'stale']);
                try {
                    self::assertSame("captured\n", fgets($out));
                    // Parent commits while worker holds only a cached User.
                    $this->pdo()->exec("UPDATE users SET {$revocation} WHERE id = {$actor}");
                    fwrite($in, "continue\n"); fflush($in);
                    self::assertSame("denied\n", fgets($out));
                } finally {
                    fclose($in); fclose($out); self::assertSame(0, proc_close($process));
                }
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"));
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'worker-created@example.org'"));
            }
            $this->pdo()->exec("UPDATE users SET role_id = 1, is_active = 1 WHERE id = {$actor}");
            [$process, $in, $out] = $this->worker(compact('actor', 'target', 'household', 'operation') + ['mode' => 'inflight']);
            try {
                self::assertSame("authorized\n", fgets($out));
                $this->assertAccountChangeWaits($actor);
                fwrite($in, "continue\n"); fflush($in);
                self::assertSame("committed\n", fgets($out));
            } finally {
                fclose($in); fclose($out); self::assertSame(0, proc_close($process));
            }
            // The mutation committed first; only now can revocation commit.
            $this->pdo()->exec("UPDATE users SET role_id = 2 WHERE id = {$actor}");
            self::assertSame(1, $this->countRows($operation === 'membership'
                ? "SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"
                : "SELECT COUNT(*) FROM users WHERE email = 'worker-created@example.org'"));
            $this->pdo()->exec("DELETE FROM household_members WHERE household_id = {$household}");
        }
    }

    public function testBorrowerAndChosenOwnerStayActiveThroughTheirInFlightWrites(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $actor = $this->createUser('actor@example.org', Role::Admin);
        $target = $this->createUser('target@example.org', Role::Member);
        $household = $this->createHousehold('Concurrent', [$target]);
        $book = $this->makeBook($actor, 'Concurrency', null, [], [], [], $actor, null, $household);
        $copy = $book['copy_id']; $edition = $book['edition_id'];
        foreach (['borrower', 'owner'] as $operation) {
            [$process, $in, $out] = $this->worker(compact('actor', 'target', 'household', 'operation', 'copy', 'edition') + ['mode' => 'inflight']);
            try {
                self::assertSame("authorized\n", fgets($out));
                $this->assertAccountChangeWaits($target);
                fwrite($in, "continue\n"); fflush($in);
                self::assertSame("committed\n", fgets($out));
            } finally {
                fclose($in); fclose($out); self::assertSame(0, proc_close($process));
            }
        }
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM loans WHERE borrower_user_id = {$target}"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE owner_user_id = {$target}"));
    }

    private function assertAccountChangeWaits(int $id): void
    {
        $other = ConnectionFactory::fromConfig(self::config());
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $other->exec("UPDATE users SET is_active = 0 WHERE id = {$id}");
            self::fail('Account revocation overtook the locked write');
        } catch (\PDOException $e) {
            self::assertSame(1205, $e->errorInfo[1]);
        }
    }

    private function worker(array $job): array
    {
        $job['config'] = ['APP_ENV' => 'testing', 'APP_URL' => 'http://library.test', 'HOUSEHOLDS_ENABLED' => 'true'];
        foreach (['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            $job['config'][$key] = (string) self::config()->get($key);
        }
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/revocation_worker.php'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null,
            ['DLA_WORKER' => json_encode($job, JSON_THROW_ON_ERROR)] + getenv());
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 10);
        return [$process, $pipes[0], $pipes[1]];
    }

    public function testCatalogueExportRefusesRevocationBetweenResponsePreparationAndStreaming(): void
    {
        $actorId = $this->createUser('actor@example.org', Role::Admin);
        $services = $this->services();
        $request = (new \DigitalLibrary\Http\Request('GET', '/admin/export/catalogue.json'))
            ->withAttribute('user', $services->users()->findById($actorId));
        $response = (new \DigitalLibrary\Http\Controllers\Library\ExportController($services))->catalogueJson($request);
        self::assertTrue($response->isStreamed());
        ConnectionFactory::fromConfig(self::config())->exec("UPDATE users SET role_id = 2 WHERE id = {$actorId}");
        try {
            $response->body();
            self::fail('A prepared export disclosed data after committed revocation');
        } catch (\DigitalLibrary\Http\HttpException $e) {
            self::assertSame(403, $e->status());
        }
        self::assertFalse($this->pdo()->inTransaction());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'export.catalogue'"), 'Durable request event does not claim completed delivery');
    }

    public function testHttpMembershipAuditFailureReturnsFailureWithoutGrantingAccess(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $this->createUser('actor@example.org', Role::Admin);
        $target = $this->createUser('target@example.org', Role::Member);
        $household = $this->createHousehold('HTTP atomicity');
        [$browser, $csrf] = $this->signedIn('actor@example.org');
        $this->migrationPdo()->exec('RENAME TABLE audit_log TO http_audit_unavailable');
        try {
            $response = $this->request($browser, 'POST', "/admin/households/{$household}/members", ['_csrf' => $csrf, 'user_id' => (string) $target]);
            self::assertSame(500, $response->status());
            self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM household_members WHERE household_id = {$household}"));
            self::assertFalse($this->pdo()->inTransaction());
        } finally {
            $this->migrationPdo()->exec('RENAME TABLE http_audit_unavailable TO audit_log');
        }
    }

    private function state(): string
    {
        $state = [];
        foreach (['households', 'household_members', 'users', 'invitations', 'invitation_households', 'audit_log'] as $table) {
            $state[$table] = $this->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1, 2')->fetchAll();
        }
        return serialize($state);
    }
}
