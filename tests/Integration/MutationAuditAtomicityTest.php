<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Catalogue\Input\CopyInput;
use DigitalLibrary\Collections\CollectionInput;
use DigitalLibrary\Core\Services;
use DigitalLibrary\Households\HouseholdInput;
use DigitalLibrary\Loans\LoanInput;
use DigitalLibrary\Security\Role;
use PDO;

final class MutationAuditAtomicityTest extends IntegrationTestCase
{
    public function testMissingAuditStorageRollsBackSecuritySensitiveMutations(): void
    {
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $adminId = $this->createUser('admin@example.org', Role::Admin);
        $memberId = $this->createUser('member@example.org', Role::Member);
        $household = $this->createHousehold('Atomic', [$memberId]);
        $s = new Services(self::config(), dirname(__DIR__, 2)); $s->set(PDO::class, $this->pdo());
        $admin = $s->users()->findById($adminId); $member = $s->users()->findById($memberId);
        $book = $this->makeBook($adminId, 'Audit boundary', null, [], [], [], $adminId, null, $household);
        $copy = $book['copy_id']; $edition = $book['edition_id'];
        $collection = $s->collectionService()->create($admin, new CollectionInput('Atomic list', '', 'private'));
        $location = $s->locationService()->create($adminId, null, (string) $household, 'Atomic room', 'room', null)['id'];
        $invitation = $s->invitationService()->issue($admin, 'invitee@example.org', Role::Member, gmdate('Y-m-d', time() + 86400), [$household]);
        $operations = [
            fn () => $s->householdService()->removeMember($admin, $household, $memberId),
            fn () => $s->householdService()->update($admin, $household, new HouseholdInput('Changed', ''), 1),
            fn () => $s->userAdministration()->update($admin, $member, $member->email, 'Changed', Role::Admin, 'en', true),
            fn () => $s->userAdministration()->resetPassword($admin, $member, 'changed'),
            fn () => $s->userAdministration()->revokeSessions($admin, $member),
            fn () => $s->invitationService()->replace($admin, $invitation['id'], gmdate('Y-m-d', time() + 86400)),
            fn () => $s->invitationService()->revoke($admin, $invitation['id']),
            fn () => $s->invitationService()->redeem(\DigitalLibrary\Invitations\InvitationToken::hash($invitation['token']), 'invitee@example.org', 'Invitee', 'hash', 'en'),
            fn () => $s->copyService()->create($adminId, $edition, new CopyInput((string) $memberId, '', '', '', 'available', false, '', '', '', '', '', (string) $household)),
            fn () => $s->copyService()->delete($adminId, $copy),
            fn () => $s->loanService()->lend($admin, $copy, new LoanInput('user', (string) $memberId, '', gmdate('Y-m-d'), '', '')),
            fn () => $s->collectionService()->create($admin, new CollectionInput('Changed list', '', 'shared', (string) $household)),
            fn () => $s->collectionService()->update($admin, $collection, new CollectionInput('Changed list', '', 'shared', (string) $household)),
            fn () => $s->collectionService()->delete($admin, $collection),
            fn () => $s->collectionService()->addItem($admin, $collection, $edition),
            fn () => $s->locationService()->create($adminId, null, (string) $household, 'Changed room', 'room', null),
            fn () => $s->locationService()->update($adminId, $location, null, 'Changed room', 'room', null, 1),
            fn () => $s->locationService()->delete($adminId, $location),
            fn () => $s->auth()->changePassword($admin, 'new password for atomic test'),
        ];
        $this->migrationPdo()->exec('RENAME TABLE audit_log TO atomic_audit_unavailable');
        try {
            $before = $this->snapshot();
            foreach ($operations as $i => $operation) {
                try { $operation(); self::fail('Mutation ' . $i . ' ignored audit failure'); }
                catch (\PDOException $e) { self::assertSame('42S02', $e->getCode(), 'Mutation ' . $i); }
                self::assertFalse($this->pdo()->inTransaction());
                self::assertSame($before, $this->snapshot(), 'Mutation ' . $i . ' partially committed');
            }
        } finally {
            $this->migrationPdo()->exec('RENAME TABLE atomic_audit_unavailable TO audit_log');
        }
    }

    public function testDeadlockRetriesRollBackEarlierEventsAndCommitExactlyOne(): void
    {
        $adminId = $this->createUser('admin@example.org', Role::Admin);
        $s = new Services(self::config(), dirname(__DIR__, 2)); $s->set(PDO::class, $this->pdo());
        foreach (['administratorGuard', 'writeGuard'] as $method) {
            $attempt = 0;
            $s->$method()->run($adminId, function () use ($s, $adminId, $method, &$attempt): void {
                $attempt++;
                $this->pdo()->exec("INSERT INTO publishers (name, created_at, updated_at) VALUES ('Retried {$method}', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                $s->audit()->log('test.retry.' . $method, $adminId, 'publisher', (int) $this->pdo()->lastInsertId());
                if ($attempt < 3) {
                    $e = new \PDOException('Injected deadlock victim'); $e->errorInfo = ['40001', 1213, 'Injected']; throw $e;
                }
            });
            self::assertSame(3, $attempt);
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'test.retry.{$method}'"));
        }
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM publishers WHERE name LIKE 'Retried %'"));
    }

    private function snapshot(): string
    {
        $out = [];
        foreach (['users', 'households', 'household_members', 'invitation_households', 'invitations', 'copies', 'loans', 'locations', 'collections', 'collection_items', 'atomic_audit_unavailable'] as $table) {
            $out[$table] = $this->pdo()->query('SELECT * FROM ' . $table . ' ORDER BY 1, 2')->fetchAll();
        }
        return serialize($out);
    }
}
