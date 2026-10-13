<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;

final class AdminTreeTest extends IntegrationTestCase
{
    private \DigitalLibrary\Security\Session $browser;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('admin@example.org', Role::Admin);
        [$this->browser, $this->token] = $this->signedIn('admin@example.org');
    }

    private function post(string $path, array $body): \DigitalLibrary\Http\Response
    {
        return $this->request($this->browser, 'POST', $path, ['_csrf' => $this->token] + $body);
    }

    private function id(string $table, string $name): int
    {
        $stmt = $this->pdo()->prepare("SELECT id FROM {$table} WHERE name = ?");
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    public function testCategoryHierarchyWithUniqueSlugsAndSiblingNames(): void
    {
        self::assertSame(303, $this->post('/admin/categories', ['name' => 'Ficción'])->status());
        $fiction = $this->id('categories', 'Ficción');
        self::assertSame(303, $this->post('/admin/categories', ['name' => 'Ciencia ficción', 'parent_id' => (string) $fiction])->status());
        self::assertSame(422, $this->post('/admin/categories', ['name' => 'Ficcion'])->status(), 'Accent-insensitive duplicate of a sibling');
        self::assertSame(303, $this->post('/admin/categories', ['name' => 'Ficción!'])->status(), 'Different name, same slug base');

        $slugs = $this->pdo()->query('SELECT slug FROM categories ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['ficcion', 'ciencia-ficcion', 'ficcion-2'], $slugs);

        $dup = $this->post('/admin/categories', ['name' => 'Ciencia ficción', 'parent_id' => (string) $fiction]);
        self::assertSame(422, $dup->status());
        self::assertStringContainsString('That name is already in use here.', $dup->body());

        $html = $this->request($this->browser, 'GET', '/admin/categories')->body();
        self::assertStringContainsString('Ciencia ficción', $html);
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'category.created'"));
    }

    public function testMovingANodeUnderItselfOrADescendantIsRejected(): void
    {
        $this->post('/admin/locations', ['name' => 'House', 'kind' => 'site']);
        $house = $this->id('locations', 'House');
        $this->post('/admin/locations', ['name' => 'Study', 'kind' => 'room', 'parent_id' => (string) $house]);
        $study = $this->id('locations', 'Study');

        foreach ([(string) $house, (string) $study] as $parent) {
            $response = $this->post("/admin/locations/{$house}", ['name' => 'House', 'kind' => 'site', 'parent_id' => $parent]);
            self::assertSame(422, $response->status(), 'parent ' . $parent);
            self::assertStringContainsString('An item cannot be placed inside itself.', $response->body());
        }
        self::assertNull($this->pdo()->query("SELECT parent_id FROM locations WHERE id = {$house}")->fetchColumn());

        $edit = $this->request($this->browser, 'GET', "/admin/locations/{$house}/edit")->body();
        self::assertStringNotContainsString('<option value="' . $study . '"', $edit, 'Descendants are not offered as parents');
    }

    public function testValidationOfKindParentAndName(): void
    {
        self::assertSame(422, $this->post('/admin/locations', ['name' => 'X', 'kind' => 'castle'])->status());
        self::assertSame(422, $this->post('/admin/locations', ['name' => 'X', 'kind' => 'room', 'parent_id' => '999999'])->status());
        self::assertSame(422, $this->post('/admin/categories', ['name' => ''])->status());
        self::assertSame(422, $this->post('/admin/categories', ['name' => str_repeat('n', 151)])->status());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM locations') + $this->countRows('SELECT COUNT(*) FROM categories'));
    }

    public function testDeletionIsRefusedForParentsAndItemsInUse(): void
    {
        $this->post('/admin/categories', ['name' => 'Parent']);
        $parent = $this->id('categories', 'Parent');
        $this->post('/admin/categories', ['name' => 'Child', 'parent_id' => (string) $parent]);
        $child = $this->id('categories', 'Child');
        $this->post('/admin/locations', ['name' => 'Shelf', 'kind' => 'shelf']);
        $shelf = $this->id('locations', 'Shelf');

        $owner = $this->countRows("SELECT id FROM users WHERE email = 'admin@example.org'");
        $this->makeBook($owner, 'Categorised', null, [], [], [$child], $owner, $shelf);

        $this->post("/admin/categories/{$parent}/delete", []);
        $this->post("/admin/categories/{$child}/delete", []);
        $this->post("/admin/locations/{$shelf}/delete", []);
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM categories'));
        self::assertSame(1, $this->countRows('SELECT COUNT(*) FROM locations'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM copies WHERE location_id = {$shelf}"), 'Copies keep their location');

        $this->pdo()->exec('DELETE FROM work_categories');
        $this->post("/admin/categories/{$child}/delete", []);
        $this->post("/admin/categories/{$parent}/delete", []);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM categories'));
    }

    public function testAuthorAndPublisherEditing(): void
    {
        $owner = $this->countRows("SELECT id FROM users WHERE email = 'admin@example.org'");
        $this->makeBook($owner, 'Book A', null, ['Ramón Otero Pedrayo'], ['publisher' => 'Galaxia']);
        $this->makeBook($owner, 'Book B', null, [], ['publisher' => 'Xerais']);
        $author = $this->id('authors', 'Ramón Otero Pedrayo');
        $galaxia = $this->id('publishers', 'Galaxia');

        self::assertSame('Pedrayo, Ramón Otero', $this->pdo()->query("SELECT sort_name FROM authors WHERE id = {$author}")->fetchColumn());
        self::assertSame(303, $this->post("/authors/{$author}", ['name' => 'Ramón Otero Pedrayo', 'sort_name' => 'Otero Pedrayo, Ramón', 'birth_year' => '1888', 'death_year' => '1976'])->status());
        self::assertSame(422, $this->post("/authors/{$author}", ['name' => 'R', 'sort_name' => 'R', 'birth_year' => 'eighteen'])->status());
        self::assertSame('Otero Pedrayo, Ramón', $this->pdo()->query("SELECT sort_name FROM authors WHERE id = {$author}")->fetchColumn());

        self::assertSame(422, $this->post("/publishers/{$galaxia}", ['name' => 'Xerais'])->status(), 'Renaming onto an existing publisher is refused');
        self::assertSame(303, $this->post("/publishers/{$galaxia}", ['name' => 'Editorial Galaxia'])->status());
        self::assertStringContainsString('Editorial Galaxia', $this->request($this->browser, 'GET', '/publishers')->body());
        self::assertStringContainsString('Otero Pedrayo, Ramón', $this->request($this->browser, 'GET', '/authors?q=otero')->body());
    }
}
