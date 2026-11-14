<?php

declare(strict_types=1);

// Disposable local browser fixture. The integration guard verifies dedicated test
// accounts before any truncation. Never loads a production database or remote host.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use DigitalLibrary\Core\Services;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Tests\Integration\IntegrationTestCase;

final class H4BrowserFixture extends IntegrationTestCase
{
    public function seed(): void
    {
        $base = self::baseConfig();
        if ($base->isProduction() || $base->get('DB_HOST') !== '127.0.0.1' || $base->int('DB_PORT', 3306) === 3306) {
            throw new RuntimeException('Browser fixtures require a dedicated loopback test instance.');
        }
        parent::setUp();
        self::$configOverrides['HOUSEHOLDS_ENABLED'] = 'true';
        $admin = $this->createUser('browser-admin@example.org', Role::Admin);
        $member = $this->createUser('browser-member@example.org', Role::Member);
        $viewer = $this->createUser('browser-viewer@example.org', Role::Viewer);
        $other = $this->createUser('browser-other@example.org', Role::Member);
        $owner = $this->createUser('browser-owner@example.org', Role::Member);
        $source = $this->createHousehold('Browser source', [$member, $viewer, $owner]);
        $destination = $this->createHousehold('Browser destination', [$owner, $other]);
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());
        $target = $services->locationService()->create($admin, null, (string) $destination, 'Browser target', 'room', null)['id'];
        $root = $services->locationService()->create($admin, null, (string) $source, 'Browser root', 'room', null)['id'];
        $copy = $this->makeBook($member, 'Browser visible book', '9788437604947', [], [], [], $owner, null, $source)['copy_id'];
        $this->pdo()->exec("UPDATE copies SET inventory_code = 'BROWSER-CODE' WHERE id = {$copy}");
        $edition = (int) $this->pdo()->query("SELECT edition_id FROM copies WHERE id = {$copy}")->fetchColumn();
        $hidden = $this->makeBook($other, 'BROWSER-PRIVATE-CANARY', '9780132350884', [], [], [], $other, $target, $destination)['copy_id'];
        $this->makeBook($member, 'Browser subtree book', null, [], [], [], $owner, $root, $source);
        echo json_encode(compact('edition', 'admin', 'member', 'viewer', 'source', 'destination', 'target', 'root', 'copy', 'hidden'), JSON_THROW_ON_ERROR) . "\n";
    }
}

(new H4BrowserFixture('seed'))->seed();
