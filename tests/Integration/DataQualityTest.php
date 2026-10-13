<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;

/**
 * Optimistic concurrency control and merging of duplicate shared records.
 */
final class DataQualityTest extends IntegrationTestCase
{
    private int $adminId;
    private int $memberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->memberId = $this->createUser('member@example.org', Role::Member);
    }

    private function authorId(string $name): int
    {
        $stmt = $this->pdo()->prepare('SELECT id FROM authors WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    public function testStaleEditionEditIsRejectedAndNothingIsOverwritten(): void
    {
        $book = $this->makeBook($this->memberId, 'Original', null, ['First Author']);
        $e = $book['edition_id'];
        [$browser, $token] = $this->signedIn('member@example.org');

        $form = $this->request($browser, 'GET', "/editions/{$e}/edit")->body();
        self::assertStringContainsString('name="row_version" value="1"', $form);

        self::assertSame(303, $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'row_version' => '1', 'edition_title' => 'First save'])->status());
        self::assertSame('2', $this->rowVersion('editions', $e));

        $stale = $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'row_version' => '1', 'edition_title' => 'Stale save', 'contributor_names' => ['Should Not Appear'], 'contributor_roles' => ['translator']]);
        self::assertSame(409, $stale->status());
        self::assertStringContainsString('Someone else changed this record', $stale->body());
        self::assertStringContainsString('value="Stale save"', $stale->body(), 'The user keeps their input');
        self::assertStringContainsString('name="row_version" value="1"', $stale->body(), 'Saving again stays stale until the form is reloaded');
        self::assertSame('First save', $this->pdo()->query("SELECT title FROM editions WHERE id = {$e}")->fetchColumn());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM edition_contributors'), 'Rolled back as a whole');

        self::assertSame(409, $this->request($browser, 'POST', "/editions/{$e}", ['_csrf' => $token, 'edition_title' => 'No version'])->status(), 'A missing version is a conflict');
    }

    public function testStaleWorkAndCopyEditsAreRejected(): void
    {
        $book = $this->makeBook($this->memberId, 'Work', null, ['A. Author'], [], [], $this->memberId);
        [$browser, $token] = $this->signedIn('member@example.org');

        $this->request($browser, 'POST', "/works/{$book['work_id']}", ['_csrf' => $token, 'row_version' => '1', 'title' => 'Work v2', 'author_names' => ['A. Author'], 'author_roles' => ['author']]);
        $stale = $this->request($browser, 'POST', "/works/{$book['work_id']}", ['_csrf' => $token, 'row_version' => '1', 'title' => 'Stale', 'author_names' => ['Intruder'], 'author_roles' => ['author']]);
        self::assertSame(409, $stale->status());
        self::assertSame('Work v2', $this->pdo()->query("SELECT title FROM works WHERE id = {$book['work_id']}")->fetchColumn());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM authors WHERE name = 'Intruder'"), 'No side effects from the rejected edit');

        $copy = $book['copy_id'];
        $base = ['_csrf' => $token, 'owner_user_id' => (string) $this->memberId, 'status' => 'available'];
        self::assertSame(303, $this->request($browser, 'POST', "/copies/{$copy}", $base + ['row_version' => '1', 'copy_condition' => 'good'])->status());
        self::assertSame(409, $this->request($browser, 'POST', "/copies/{$copy}", $base + ['row_version' => '1', 'copy_condition' => 'poor'])->status());
        self::assertSame('good', $this->pdo()->query("SELECT copy_condition FROM copies WHERE id = {$copy}")->fetchColumn());
    }

    public function testMergingAuthorsMovesCreditsWithoutDuplicates(): void
    {
        $one = $this->makeBook($this->adminId, 'Book one', null, ['G. García Márquez']);
        $both = $this->makeBook($this->adminId, 'Book two', null, ['Gabriel García Márquez', 'G. García Márquez']);
        $this->makeBook($this->adminId, 'Book three', null, ['Gabriel García Márquez']);
        $this->pdo()->prepare("INSERT INTO edition_contributors (edition_id, author_id, role, position) VALUES (?, ?, 'translator', 0)")
            ->execute([$one['edition_id'], $this->authorId('G. García Márquez')]);
        $source = $this->authorId('G. García Márquez');
        $target = $this->authorId('Gabriel García Márquez');
        $versionBefore = $this->rowVersion('works', $both['work_id']);

        [$browser, $token] = $this->signedIn('admin@example.org');
        $response = $this->request($browser, 'POST', "/authors/{$source}/merge", ['_csrf' => $token, 'target_id' => (string) $target]);
        self::assertSame(303, $response->status());
        self::assertSame("/authors/{$target}", $response->header('Location'));

        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM authors WHERE id = {$source}"));
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM work_authors WHERE author_id = {$target}"), 'One credit per work');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM work_authors WHERE work_id = {$both['work_id']}"), 'Overlapping credit collapsed');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM edition_contributors WHERE author_id = {$target}"));
        self::assertNotSame($versionBefore, $this->rowVersion('works', $both['work_id']), 'Stale forms on affected works now conflict');
        $details = json_decode((string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'author.merged'")->fetchColumn(), true);
        self::assertSame('G. García Márquez', $details['merged_name']);
    }

    public function testMergingPublishersAndDeletingUnusedRecords(): void
    {
        $a = $this->makeBook($this->adminId, 'A', null, [], ['publisher' => 'Edicións Xerais']);
        $this->makeBook($this->adminId, 'B', null, [], ['publisher' => 'Xerais']);
        $source = (int) $this->pdo()->query("SELECT id FROM publishers WHERE name = 'Edicións Xerais'")->fetchColumn();
        $target = (int) $this->pdo()->query("SELECT id FROM publishers WHERE name = 'Xerais'")->fetchColumn();
        [$browser, $token] = $this->signedIn('admin@example.org');

        self::assertSame(303, $this->request($browser, 'POST', "/publishers/{$target}/delete", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM publishers WHERE id = {$target}"), 'Publishers in use are not deleted');

        self::assertSame(303, $this->request($browser, 'POST', "/publishers/{$source}/merge", ['_csrf' => $token, 'target_id' => (string) $target])->status());
        self::assertSame(2, $this->countRows("SELECT COUNT(*) FROM editions WHERE publisher_id = {$target}"));
        self::assertSame('2', $this->rowVersion('editions', $a['edition_id']));

        $this->pdo()->exec("INSERT INTO authors (name, sort_name, created_at, updated_at) VALUES ('Orphan', 'Orphan', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $orphan = $this->authorId('Orphan');
        self::assertSame(303, $this->request($browser, 'POST', "/authors/{$orphan}/delete", ['_csrf' => $token])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM authors WHERE id = {$orphan}"));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'author.deleted'"));
    }

    public function testInvalidMergeTargetsAndPermissions(): void
    {
        $this->makeBook($this->adminId, 'A', null, ['Solo Author'], ['publisher' => 'Solo Press']);
        $author = $this->authorId('Solo Author');
        [$admin, $token] = $this->signedIn('admin@example.org');
        foreach ([(string) $author, '999999', 'abc', ''] as $target) {
            self::assertSame(303, $this->request($admin, 'POST', "/authors/{$author}/merge", ['_csrf' => $token, 'target_id' => $target])->status());
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM authors WHERE id = {$author}"), "target '{$target}'");
        }

        [$member, $memberToken] = $this->signedIn('member@example.org');
        self::assertSame(403, $this->request($member, 'POST', "/authors/{$author}/merge", ['_csrf' => $memberToken, 'target_id' => '1'])->status());
        self::assertSame(403, $this->request($member, 'POST', "/authors/{$author}/delete", ['_csrf' => $memberToken])->status());
        self::assertSame(403, $this->request($member, 'POST', '/publishers/1/merge', ['_csrf' => $memberToken, 'target_id' => '2'])->status());
    }
}
