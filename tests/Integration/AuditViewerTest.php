<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Audit\AuditLogRepository;
use DigitalLibrary\Security\Role;

/**
 * Administrative audit-log viewer: admin-only, read-only, bounded filters,
 * keyset pagination, redaction of secrets and escaping of stored values.
 */
final class AuditViewerTest extends IntegrationTestCase
{
    private int $adminId;
    private int $aliceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->aliceId = $this->createUser('alice@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);
    }

    public function testOnlyAdministratorsCanOpenTheViewer(): void
    {
        $id = $this->event('work.created', $this->aliceId, ['title' => 'X']);
        foreach (['/admin/audit', "/admin/audit/{$id}"] as $path) {
            self::assertSame('/login', $this->request($this->browser(), 'GET', $path)->header('Location'), $path);
            foreach (['alice@example.org', 'viewer@example.org'] as $email) {
                [$browser] = $this->signedIn($email);
                self::assertSame(403, $this->request($browser, 'GET', $path)->status(), "{$email} {$path}");
            }
        }
        [$admin] = $this->signedIn('admin@example.org');
        self::assertSame(200, $this->request($admin, 'GET', '/admin/audit')->status());
        self::assertSame(200, $this->request($admin, 'GET', "/admin/audit/{$id}")->status());
        self::assertSame(404, $this->request($admin, 'GET', '/admin/audit/999999')->status());
        self::assertStringContainsString('href="/admin/audit"', $this->request($admin, 'GET', '/')->body());
        [$alice] = $this->signedIn('alice@example.org');
        self::assertStringNotContainsString('href="/admin/audit"', $this->request($alice, 'GET', '/')->body());
    }

    public function testViewerIsReadOnly(): void
    {
        $router = (new \DigitalLibrary\Core\Application(new \DigitalLibrary\Core\Services(self::config(), dirname(__DIR__, 2))))->router();
        $routes = (new \ReflectionProperty($router, 'routes'))->getValue($router);
        $auditRoutes = array_filter($routes, static fn ($r): bool => str_starts_with($r->pattern, '/admin/audit'));
        self::assertCount(2, $auditRoutes);
        foreach ($auditRoutes as $route) {
            self::assertSame('GET', $route->method, $route->pattern);
        }
        $id = $this->event('work.created', $this->aliceId);
        [$admin, $token] = $this->signedIn('admin@example.org');
        self::assertSame(405, $this->request($admin, 'POST', "/admin/audit/{$id}", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE id = {$id}"));
    }

    public function testFiltersNarrowTheResults(): void
    {
        $this->event('work.created', $this->aliceId, ['title' => 'Rayuela'], 'work', 7);
        $this->event('work.deleted', $this->aliceId, ['title' => 'Follas novas'], 'work', 8);
        $this->event('user.updated', $this->adminId, ['changes' => ['role' => ['from' => 'viewer', 'to' => 'member']]], 'user', $this->aliceId);
        $this->event('auth.login_failed', null, ['reason' => 'unknown_account'], 'user', null, '198.51.100.7');
        $this->event('work.created', $this->aliceId, ['title' => 'Old event'], 'work', 9, null, '-40 days');
        $this->event('work.created', $this->aliceId, ['title' => 'Ancient event'], 'work', 10, null, '-400 days');
        [$admin] = $this->signedIn('admin@example.org');
        $this->migrationPdo()->exec("DELETE FROM audit_log WHERE action = 'auth.login'"); // the sign-in above
        $get = fn (string $query): string => $this->request($admin, 'GET', '/admin/audit?' . $query)->body();

        $default = $get('');
        foreach (['work.created', 'work.deleted', 'user.updated', 'auth.login_failed', '198.51.100.7'] as $expected) {
            self::assertStringContainsString($expected, $default, $expected);
        }
        self::assertSame(4, substr_count($default, 'href="/admin/audit/'), 'Default range is the last 30 days');

        self::assertSame(2, substr_count($get('action=work.created&from=' . gmdate('Y-m-d', strtotime('-60 days'))), 'href="/admin/audit/'));
        self::assertSame(1, substr_count($get('action=work.created'), 'href="/admin/audit/'));
        self::assertSame(1, substr_count($get('user=' . $this->adminId), 'href="/admin/audit/'));
        self::assertSame(1, substr_count($get('entity_type=work&entity_id=8'), 'href="/admin/audit/'));
        self::assertSame(2, substr_count($get('entity_type=work'), 'href="/admin/audit/'));
        self::assertSame(1, substr_count($get('q=Follas'), 'href="/admin/audit/'), 'Search in details');
        self::assertSame(1, substr_count($get('q=198.51.100'), 'href="/admin/audit/'), 'Search in IP address');
        self::assertSame(1, substr_count($get('q=50%25'), 'Showing events'), 'LIKE wildcards are literal');
        self::assertSame(0, substr_count($get('q=50%25'), 'href="/admin/audit/'));

        // Bounded ranges: more than 366 days is shortened, so the ancient event stays out.
        $long = $get('from=2000-01-01');
        self::assertStringContainsString('Searches cover at most 366 days', $long);
        self::assertSame(5, substr_count($long, 'href="/admin/audit/'));
        self::assertStringContainsString('An invalid date was ignored.', $get('from=2026-02-30'));
        self::assertStringContainsString('Search terms must be between 3 and 100 characters', $get('q=ab'));
        self::assertStringContainsString('were swapped', $get('from=' . gmdate('Y-m-d') . '&to=' . gmdate('Y-m-d', strtotime('-5 days'))));

        // Malformed filters are ignored rather than passed to SQL.
        self::assertSame(4, substr_count($get('action=' . rawurlencode("x' OR 1=1 --") . '&user=abc&entity_type=' . rawurlencode('work;')), 'href="/admin/audit/'));
        self::assertStringContainsString('All times are in UTC', $default);
    }

    public function testKeysetPaginationWalksTheWholeRange(): void
    {
        $total = AuditLogRepository::PER_PAGE * 2 + 5;
        $this->pdo()->exec('INSERT INTO audit_log (user_id, action, entity_type, entity_id, created_at)
            WITH RECURSIVE n (i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM n WHERE i < ' . $total . ")
            SELECT {$this->aliceId}, 'copy.updated', 'copy', i, UTC_TIMESTAMP() FROM n");
        [$admin] = $this->signedIn('admin@example.org');

        $seen = [];
        $url = '/admin/audit?action=copy.updated';
        for ($pages = 0; $url !== null && $pages < 10; $pages++) {
            $body = $this->request($admin, 'GET', $url)->body();
            preg_match_all('#href="/admin/audit/(\d+)"#', $body, $m);
            $seen = array_merge($seen, array_map('intval', $m[1]));
            $url = preg_match('#href="([^"]+)" rel="next"#', $body, $n) === 1 ? html_entity_decode($n[1]) : null;
        }
        self::assertSame(3, $pages);
        self::assertCount($total, $seen);
        self::assertSame($seen, array_values(array_unique($seen)), 'No event appears twice');
        $sorted = $seen;
        rsort($sorted);
        self::assertSame($sorted, $seen, 'Newest first');
        self::assertStringContainsString('action=copy.updated', $this->request($admin, 'GET', '/admin/audit?action=copy.updated')->body());
    }

    public function testSecretsAreMaskedAndStoredValuesEscaped(): void
    {
        $id = $this->event('user.updated', $this->adminId, [
            'password' => 'hunter2',
            'nested' => ['api_key' => 'sk-live-123', 'Session_Token' => 'abc', 'note' => '<script>alert(1)</script>'],
            'title' => '"><img src=x onerror=alert(2)>',
            'reason' => 'ok',
        ], 'user', $this->aliceId, '203.0.113.9', 'now', '<b>Evil UA</b>');
        // A row written before redaction existed (inserted directly).
        $raw = $this->event('legacy.event', null, ['client_secret' => 'plain-secret', 'value' => 1]);

        [$admin] = $this->signedIn('admin@example.org');
        $page = $this->request($admin, 'GET', "/admin/audit/{$id}")->body();
        foreach (['hunter2', 'sk-live-123', '<script>alert(1)', '<img src=x', '<b>Evil UA</b>'] as $leak) {
            self::assertStringNotContainsString($leak, $page, $leak);
        }
        self::assertStringContainsString('&quot;password&quot;: &quot;[redacted]&quot;', $page);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $page);
        self::assertStringContainsString('&lt;b&gt;Evil UA&lt;/b&gt;', $page);
        self::assertStringContainsString('&quot;reason&quot;: &quot;ok&quot;', $page);
        self::assertStringContainsString('203.0.113.9', $page);

        $legacy = $this->request($admin, 'GET', "/admin/audit/{$raw}")->body();
        self::assertStringNotContainsString('plain-secret', $legacy);
        self::assertStringContainsString('[redacted]', $legacy);

        // The list never shows details at all, and searching for a secret does not reveal it.
        $list = $this->request($admin, 'GET', '/admin/audit?q=hunter2')->body();
        self::assertStringNotContainsString('hunter2', str_replace('value="hunter2"', '', $list));
    }

    /**
     * Inserts an event directly (bypassing AuditLogger redaction on purpose
     * for the "legacy row" case; other callers pass already-safe data).
     *
     * @param array<string, mixed> $details
     */
    private function event(string $action, ?int $userId, array $details = [], ?string $entityType = null, ?int $entityId = null, ?string $ip = null, string $when = 'now', ?string $ua = null): int
    {
        $stmt = $this->pdo()->prepare('INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
            VALUES (:u, :a, :t, :e, :d, :ip, :ua, :c)');
        $stmt->execute([
            'u' => $userId, 'a' => $action, 't' => $entityType, 'e' => $entityId,
            'd' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR),
            'ip' => $ip, 'ua' => $ua, 'c' => gmdate('Y-m-d H:i:s', strtotime($when)),
        ]);

        return (int) $this->pdo()->lastInsertId();
    }
}
