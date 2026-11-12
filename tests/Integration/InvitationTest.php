<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Core\Services;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\Http\Response;
use DigitalLibrary\Invitations\InvitationException;
use DigitalLibrary\Invitations\InvitationToken;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;
use PDO;
use PDOException;

/**
 * Administrator-issued invitations and invitation-only registration:
 * token handling, single use, expiry, revocation, replacement, role
 * restrictions, concurrency, rate limiting and leak prevention.
 */
final class InvitationTest extends IntegrationTestCase
{
    private const NEW_PASSWORD = 'violet harbour lantern 2026';

    private int $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->createUser('admin@example.org', Role::Admin);
        $this->createUser('member@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);
    }

    public function testAdministratorIssuesASingleUseLinkThatIsNeverStored(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        self::assertSame(200, $this->request($admin, 'GET', '/admin/invitations/new')->status());
        $response = $this->issue($admin, $token, 'New.Reader@Example.org', 'viewer');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('no-store', (string) $response->header('Cache-Control'));
        // The page has forms (sign-out, language): "no-referrer" would make browsers send Origin: null.
        self::assertNotSame('no-referrer', $response->header('Referrer-Policy'));
        $raw = $this->tokenFrom($response);
        self::assertStringContainsString('http://library.test/invite?token=' . $raw, $response->body());

        $row = $this->pdo()->query('SELECT * FROM invitations')->fetch();
        self::assertSame(['new.reader@example.org', 3, $this->adminId, hash('sha256', $raw)], [$row['email'], (int) $row['role_id'], (int) $row['invited_by'], $row['token_hash']]);
        self::assertSame(gmdate('Y-m-d', strtotime('+7 days')) . ' 23:59:59', $row['expires_at']);

        // The raw token appears nowhere in the database, the audit trail or the session.
        foreach ($this->pdo()->query('SELECT * FROM invitations')->fetchAll(PDO::FETCH_NUM) as $values) {
            self::assertNotContains($raw, $values);
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%" . $raw . "%'"));
        self::assertStringNotContainsString($raw, serialize($admin->get('_flash') ?? null));
        $audit = json_decode((string) $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'invitation.created'")->fetchColumn(), true);
        self::assertEqualsCanonicalizing(['email', 'role', 'expires_at'], array_keys($audit));

        // The list page never shows the link again.
        $list = $this->request($admin, 'GET', '/admin/invitations')->body();
        self::assertStringContainsString('new.reader@example.org', $list);
        self::assertStringNotContainsString($raw, $list);
    }

    public function testInviteeRegistersAndSignsIn(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $raw = $this->tokenFrom($this->issue($admin, $token, 'reader@example.org', 'member'));

        $invitee = $this->browser();
        $open = $this->request($invitee, 'GET', '/invite?token=' . $raw);
        self::assertSame(303, $open->status());
        self::assertSame('/invite/accept', $open->header('Location'), 'The token never appears in a page URL');
        self::assertSame('no-referrer', $open->header('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $open->header('Cache-Control'));
        self::assertSame(['hash'], array_keys(array_diff_key($invitee->get('invitation'), ['until' => 1])), 'Only the hash is kept in the session');
        self::assertStringNotContainsString($raw, serialize($invitee->get('invitation')));

        $form = $this->request($invitee, 'GET', '/invite/accept');
        self::assertSame(200, $form->status());
        self::assertStringNotContainsString('reader@example.org', $form->body(), 'The invited address is not disclosed');
        self::assertNotSame('no-referrer', $form->header('Referrer-Policy'), 'Forms must keep a real Origin header');
        self::assertStringContainsString('no-store', (string) $form->header('Cache-Control'));
        $csrf = self::csrfFrom($form);

        // Validation and e-mail verification.
        self::assertSame(422, $this->accept($invitee, $csrf, ['password_confirmation' => 'something else entirely'])->status());
        self::assertSame(422, $this->accept($invitee, $csrf, ['password' => 'short', 'password_confirmation' => 'short'])->status());
        $mismatch = $this->accept($invitee, $csrf, ['email' => 'someone.else@example.org']);
        self::assertSame(422, $mismatch->status());
        self::assertStringContainsString('This is not the e-mail address the invitation was sent to.', $mismatch->body());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'reader@example.org'"), 'No account before a successful redemption');

        // As a browser sends it: with the site's own Origin header.
        $done = $this->accept($invitee, $csrf, ['email' => ' Reader@Example.org '], ['HTTP_ORIGIN' => 'http://library.test']);
        self::assertSame(303, $done->status());
        self::assertSame('/login', $done->header('Location'));
        $user = $this->pdo()->query("SELECT u.*, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE email = 'reader@example.org'")->fetch();
        self::assertSame(['member', 1, $this->adminId, 'Rosa Reader'], [$user['code'], (int) $user['is_active'], (int) $user['created_by'], $user['display_name']]);
        self::assertTrue(password_verify(self::NEW_PASSWORD, $user['password_hash']));
        self::assertStringStartsWith('$argon2id$', $user['password_hash']);
        $invitation = $this->pdo()->query('SELECT accepted_at, accepted_user_id, open_email FROM invitations')->fetch();
        self::assertNotNull($invitation['accepted_at']);
        self::assertSame((int) $user['id'], (int) $invitation['accepted_user_id']);
        self::assertNull($invitation['open_email']);
        self::assertNull($invitee->get('invitation'));
        foreach (['invitation.accepted', 'user.created'] as $action) {
            self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = '{$action}' AND user_id = {$user['id']}"), $action);
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE details LIKE '%" . $raw . "%' OR details LIKE '%" . hash('sha256', $raw) . "%'"));

        // Existing authentication.
        self::assertSame(303, $this->login($this->browser(), 'reader@example.org', self::NEW_PASSWORD)->status());

        // Single use: the same link is now generic-invalid.
        [$again, $page] = $this->open($this->browser(), $raw);
        self::assertSame('/invite/invalid', $again->header('Location'));
        self::assertSame(404, $page->status());
        self::assertStringContainsString('This invitation link is not valid.', $page->body());
    }

    public function testInvalidExpiredAndRevokedLinksShareOneGenericAnswer(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $expired = $this->tokenFrom($this->issue($admin, $token, 'late@example.org', 'member'));
        $this->pdo()->exec("UPDATE invitations SET created_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE email = 'late@example.org'");
        $revoked = $this->tokenFrom($this->issue($admin, $token, 'gone@example.org', 'member'));
        $id = $this->countRows("SELECT id FROM invitations WHERE email = 'gone@example.org'");
        self::assertSame(303, $this->request($admin, 'POST', "/admin/invitations/{$id}/revoke", ['_csrf' => $token])->status());
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM invitations WHERE id = {$id} AND revoked_at IS NOT NULL AND revoked_by = {$this->adminId}"));

        $bodies = [];
        foreach (['expired' => $expired, 'revoked' => $revoked, 'unknown' => InvitationToken::generate(), 'malformed' => 'abc', 'empty' => ''] as $label => $raw) {
            [$response, $page] = $this->open($this->browser(), rawurlencode($raw));
            self::assertSame(303, $response->status(), $label);
            self::assertSame('/invite/invalid', $response->header('Location'), $label . ': no page is ever rendered at the token URL');
            self::assertSame('no-referrer', $response->header('Referrer-Policy'), $label);
            self::assertSame(404, $page->status(), $label);
            $bodies[$label] = preg_replace('/name="_csrf" value="[0-9a-f]+"/', '', $page->body());
        }
        self::assertCount(1, array_unique($bodies), 'Every failure looks the same');
        self::assertSame(5, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.rejected'"));

        // A link that expires after it was opened is refused on submit.
        $raw = $this->tokenFrom($this->issue($admin, $token, 'slow@example.org', 'viewer'));
        $invitee = $this->browser();
        $this->request($invitee, 'GET', '/invite?token=' . $raw);
        $csrf = self::csrfFrom($this->request($invitee, 'GET', '/invite/accept'));
        $this->pdo()->exec("UPDATE invitations SET created_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE email = 'slow@example.org'");
        self::assertSame(404, $this->accept($invitee, $csrf, ['email' => 'slow@example.org'])->status());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'slow@example.org'"));

        // Without a pending invitation in the session, the form is not available.
        self::assertSame(404, $this->request($this->browser(), 'GET', '/invite/accept')->status());
    }

    public function testReplacementInvalidatesTheOldLink(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $old = $this->tokenFrom($this->issue($admin, $token, 'lost@example.org', 'viewer'));
        $oldId = $this->countRows('SELECT id FROM invitations');
        $response = $this->request($admin, 'POST', "/admin/invitations/{$oldId}/replace", ['_csrf' => $token, 'expires_on' => gmdate('Y-m-d', strtotime('+3 days'))]);
        self::assertSame(200, $response->status());
        $new = $this->tokenFrom($response);
        self::assertNotSame($old, $new);
        $newId = $this->countRows('SELECT MAX(id) FROM invitations');
        self::assertSame([$newId, 3], array_map('intval', $this->pdo()->query("SELECT replaced_by_id, (SELECT role_id FROM invitations WHERE id = {$newId}) FROM invitations WHERE id = {$oldId}")->fetch(PDO::FETCH_NUM)));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.replaced' AND entity_id = {$newId}"));

        self::assertSame('/invite/invalid', $this->request($this->browser(), 'GET', '/invite?token=' . $old)->header('Location'));
        self::assertSame('/invite/accept', $this->request($this->browser(), 'GET', '/invite?token=' . $new)->header('Location'));

        // Expired invitations can be replaced too; accepted ones cannot.
        $this->pdo()->exec("UPDATE invitations SET created_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = {$newId}");
        self::assertStringContainsString('Expired', $this->request($admin, 'GET', '/admin/invitations?status=expired')->body());
        self::assertSame(200, $this->request($admin, 'POST', "/admin/invitations/{$newId}/replace", ['_csrf' => $token])->status());
        $this->pdo()->exec('UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE id = (SELECT id FROM (SELECT MAX(id) AS id FROM invitations) t)');
        $accepted = $this->countRows('SELECT MAX(id) FROM invitations');
        $refused = $this->request($admin, 'POST', "/admin/invitations/{$accepted}/replace", ['_csrf' => $token]);
        self::assertSame(303, $refused->status());
        self::assertSame(404, $this->request($admin, 'POST', '/admin/invitations/999999/replace', ['_csrf' => $token])->status());
        self::assertSame(404, $this->request($admin, 'POST', '/admin/invitations/999999/revoke', ['_csrf' => $token])->status());
    }

    public function testIssuingRules(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $cases = [
            'administrator role' => ['email' => 'boss@example.org', 'role' => 'admin'],
            'unknown role' => ['email' => 'x@example.org', 'role' => 'owner'],
            'bad e-mail' => ['email' => 'not-an-address', 'role' => 'member'],
            'expiry in the past' => ['email' => 'x@example.org', 'role' => 'member', 'expires_on' => gmdate('Y-m-d', strtotime('-1 day'))],
            'expiry too far' => ['email' => 'x@example.org', 'role' => 'member', 'expires_on' => gmdate('Y-m-d', strtotime('+31 days'))],
            'malformed expiry' => ['email' => 'x@example.org', 'role' => 'member', 'expires_on' => '2026-02-30'],
            'existing account' => ['email' => 'MEMBER@example.org', 'role' => 'member'],
        ];
        foreach ($cases as $label => $fields) {
            self::assertSame(422, $this->request($admin, 'POST', '/admin/invitations', $fields + ['_csrf' => $token, 'expires_on' => gmdate('Y-m-d', strtotime('+7 days'))])->status(), $label);
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitations'));

        // One open invitation per address; an expired one is closed automatically.
        self::assertSame(200, $this->issue($admin, $token, 'twice@example.org', 'member')->status());
        $second = $this->issue($admin, $token, 'Twice@example.org', 'viewer');
        self::assertSame(422, $second->status());
        self::assertStringContainsString('already has a pending invitation', $second->body());
        $this->pdo()->exec("UPDATE invitations SET created_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE email = 'twice@example.org'");
        self::assertSame(200, $this->issue($admin, $token, 'twice@example.org', 'viewer')->status());
        self::assertSame([1, 1], array_map('intval', $this->pdo()->query("SELECT SUM(revoked_at IS NOT NULL), SUM(open_email IS NOT NULL) FROM invitations WHERE email = 'twice@example.org'")->fetch(PDO::FETCH_NUM)));
    }

    public function testDatabaseForbidsAdministratorInvitationsAndDuplicateOpenOnes(): void
    {
        $insert = fn (int $role, string $email, string $hash) => $this->pdo()->exec(
            "INSERT INTO invitations (email, role_id, token_hash, invited_by, expires_at, created_at)
             VALUES ('{$email}', {$role}, '{$hash}', {$this->adminId}, UTC_TIMESTAMP() + INTERVAL 1 DAY, UTC_TIMESTAMP())"
        );
        try {
            $insert(1, 'a@example.org', str_repeat('a', 64));
            self::fail('Administrator invitation accepted by the database');
        } catch (PDOException $e) {
            self::assertSame(3819, $e->errorInfo[1]);
        }
        $insert(2, 'b@example.org', str_repeat('b', 64));
        try {
            $insert(3, 'b@example.org', str_repeat('c', 64));
            self::fail('Second open invitation accepted');
        } catch (PDOException $e) {
            self::assertSame(1062, $e->errorInfo[1]);
        }
        $this->pdo()->exec("UPDATE invitations SET revoked_at = UTC_TIMESTAMP() WHERE email = 'b@example.org'");
        $insert(3, 'b@example.org', str_repeat('c', 64));
        self::assertSame(2, $this->countRows('SELECT COUNT(*) FROM invitations'));
    }

    public function testConcurrentRedemptionCannotCreateTwoAccounts(): void
    {
        $services = $this->services();
        $admin = $services->users()->findById($this->adminId);
        $issued = $services->invitationService()->issue($admin, 'race@example.org', Role::Member, gmdate('Y-m-d', strtotime('+2 days')));
        $hash = InvitationToken::hash($issued['token']);
        $passwordHash = $services->hasher()->hash(self::NEW_PASSWORD);

        // A competing redemption holds the invitation row: this one waits, then gives up generically.
        $other = ConnectionFactory::fromConfig(self::config());
        $other->beginTransaction();
        $other->query("SELECT id FROM invitations WHERE token_hash = '{$hash}' FOR UPDATE")->fetchAll();
        $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $services->invitationService()->redeem($hash, 'race@example.org', 'Racer', $passwordHash, 'en');
            self::fail('Redemption did not wait for the competing transaction');
        } catch (InvitationException $e) {
            self::assertSame('invitations.error.invalid', $e->getMessage());
        } finally {
            $this->pdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
            $other->rollBack();
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'race@example.org'"));

        // The competing transaction consumes the invitation first; the waiting one is then refused.
        $other->beginTransaction();
        $other->query("SELECT id FROM invitations WHERE token_hash = '{$hash}' FOR UPDATE")->fetchAll();
        $other->exec("UPDATE invitations SET accepted_at = UTC_TIMESTAMP() WHERE token_hash = '{$hash}'");
        $other->commit();
        try {
            $services->invitationService()->redeem($hash, 'race@example.org', 'Racer', $passwordHash, 'en');
            self::fail('A consumed invitation was redeemed again');
        } catch (InvitationException $e) {
            self::assertSame(['invitations.error.invalid', 'used'], [$e->getMessage(), $e->reason]);
        }
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'race@example.org'"));
        self::assertFalse($this->pdo()->inTransaction());
    }

    public function testFailedAttemptsAreRateLimited(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $raw = $this->tokenFrom($this->issue($admin, $token, 'patient@example.org', 'member'));
        for ($i = 0; $i < 10; $i++) {
            self::assertSame('/invite/invalid', $this->request($this->browser(), 'GET', '/invite?token=' . InvitationToken::generate())->header('Location'));
        }
        [$open, $blocked] = $this->open($this->browser(), $raw);
        self::assertSame('/invite/invalid', $open->header('Location'), 'Even a valid link is refused while the address is throttled');
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
        self::assertStringContainsString('Too many attempts', $blocked->body());

        // Another client address is unaffected.
        self::assertSame('/invite/accept', $this->request($this->browser(), 'GET', '/invite?token=' . $raw, [], ['REMOTE_ADDR' => '198.51.100.20'])->header('Location'));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM login_attempts WHERE identifier_hash LIKE '%" . $raw . "%'"));
    }

    public function testOnlyAdministratorsManageInvitations(): void
    {
        $paths = ['/admin/invitations', '/admin/invitations/new'];
        foreach ($paths as $path) {
            self::assertSame('/login', $this->request($this->browser(), 'GET', $path)->header('Location'), $path);
        }
        foreach (['member@example.org', 'viewer@example.org'] as $email) {
            [$browser, $token] = $this->signedIn($email);
            foreach ($paths as $path) {
                self::assertSame(403, $this->request($browser, 'GET', $path)->status(), "{$email} {$path}");
            }
            self::assertSame(403, $this->issue($browser, $token, 'sneaky@example.org', 'member')->status(), $email);
            self::assertSame(403, $this->request($browser, 'POST', '/admin/invitations/1/revoke', ['_csrf' => $token])->status(), $email);
            self::assertSame(403, $this->request($browser, 'POST', '/admin/invitations/1/replace', ['_csrf' => $token])->status(), $email);
            self::assertStringNotContainsString('/admin/invitations', $this->request($browser, 'GET', '/')->body());
        }
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM invitations'));

        // Signed-in users are sent home instead of registering again.
        [$member] = $this->signedIn('member@example.org');
        self::assertSame('/', $this->request($member, 'GET', '/invite?token=' . InvitationToken::generate())->header('Location'));
        self::assertSame('/', $this->request($member, 'GET', '/invite/accept')->header('Location'));
    }

    private function issue(Session $browser, string $token, string $email, string $role): Response
    {
        return $this->request($browser, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => $email, 'role' => $role, 'expires_on' => gmdate('Y-m-d', strtotime('+7 days'))]);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, string> $server
     */
    private function accept(Session $browser, string $csrf, array $fields = [], array $server = []): Response
    {
        return $this->request($browser, 'POST', '/invite/accept', $fields + [
            '_csrf' => $csrf, 'email' => 'reader@example.org', 'display_name' => 'Rosa Reader',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], $server);
    }

    /**
     * Opens a link and follows its redirect, like a browser.
     *
     * @return array{Response, Response} [the /invite response, the page it redirects to]
     */
    private function open(Session $browser, string $token): array
    {
        $first = $this->request($browser, 'GET', '/invite?token=' . $token);

        return [$first, $this->request($browser, 'GET', (string) $first->header('Location'))];
    }

    private function tokenFrom(Response $response): string
    {
        if (!preg_match('#/invite\?token=([A-Za-z0-9_-]{43})"#', $response->body(), $m)) {
            self::fail('No invitation link in response (status ' . $response->status() . ')');
        }

        return $m[1];
    }

    private function services(): Services
    {
        $services = new Services(self::config(), dirname(__DIR__, 2));
        $services->set(PDO::class, $this->pdo());

        return $services;
    }
}
