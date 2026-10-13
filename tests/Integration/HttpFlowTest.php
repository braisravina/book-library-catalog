<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Security\Role;
use PDO;

final class HttpFlowTest extends IntegrationTestCase
{
    public function testHealthCheck(): void
    {
        $response = $this->request($this->browser(), 'GET', '/health');
        self::assertSame(200, $response->status());
        self::assertSame('{"status":"ok"}', $response->body());
    }

    public function testGuestSeesLandingPageWithoutCatalogueData(): void
    {
        $response = $this->request($this->browser(), 'GET', '/');
        self::assertSame(200, $response->status());
        self::assertStringContainsString('href="/login"', $response->body());
        self::assertStringNotContainsString('stat-grid', $response->body());
        self::assertSame('noindex, nofollow', $response->header('X-Robots-Tag'));
        self::assertNotNull($response->header('Content-Security-Policy'));
    }

    public function testProtectedPagesRedirectGuestsToLogin(): void
    {
        foreach (['/admin/users', '/account/password'] as $path) {
            $response = $this->request($this->browser(), 'GET', $path);
            self::assertSame(303, $response->status(), $path);
            self::assertSame('/login', $response->header('Location'));
        }
    }

    public function testUnknownRouteIs404AndDirectPhpPathIsNotRouted(): void
    {
        self::assertSame(404, $this->request($this->browser(), 'GET', '/does-not-exist')->status());
        self::assertSame(404, $this->request($this->browser(), 'GET', '/index.php')->status());
    }

    public function testLoginRequiresCsrfToken(): void
    {
        $this->createUser('admin@example.org', Role::Admin);
        $response = $this->request($this->browser(), 'POST', '/login', ['email' => 'admin@example.org', 'password' => self::PASSWORD]);

        self::assertSame(419, $response->status());
        self::assertStringNotContainsString('Welcome', $response->body());
    }

    public function testSuccessfulLoginRotatesSessionAndCsrfAndRedirectsToIntendedPage(): void
    {
        $this->createUser('admin@example.org', Role::Admin);
        $browser = $this->browser();
        $this->request($browser, 'GET', '/admin/users');
        $tokenBefore = self::csrfFrom($this->request($browser, 'GET', '/login'));
        $sessionBefore = $browser->id();

        $response = $this->request($browser, 'POST', '/login', ['_csrf' => $tokenBefore, 'email' => 'ADMIN@example.org ', 'password' => self::PASSWORD]);

        self::assertSame(303, $response->status());
        self::assertSame('/admin/users', $response->header('Location'));
        self::assertNotSame($sessionBefore, $browser->id(), 'Session id must change at login');
        self::assertNotSame($tokenBefore, $browser->get('_csrf_token'), 'CSRF token must rotate at login');

        $dashboard = $this->request($browser, 'GET', '/');
        self::assertStringContainsString('Welcome, Admin', $dashboard->body());
        self::assertSame(1, (int) $this->pdo()->query("SELECT COUNT(*) FROM audit_log WHERE action = 'auth.login'")->fetchColumn());
    }

    public function testFailedLoginIsGenericAndAudited(): void
    {
        $this->createUser('member@example.org', Role::Member);
        $wrongPassword = $this->login($this->browser(), 'member@example.org', 'wrong password!!');
        $unknownUser = $this->login($this->browser(), 'nobody@example.org', 'wrong password!!');

        self::assertSame(422, $wrongPassword->status());
        self::assertSame(422, $unknownUser->status());
        self::assertStringContainsString('The e-mail address or password is incorrect.', $wrongPassword->body());
        self::assertStringContainsString('The e-mail address or password is incorrect.', $unknownUser->body());

        $details = $this->pdo()->query("SELECT details FROM audit_log WHERE action = 'auth.login_failed'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $details);
        foreach ($details as $json) {
            self::assertStringNotContainsString('wrong password', (string) $json, 'Passwords are never logged');
        }
    }

    public function testInactiveUsersCannotLogIn(): void
    {
        $this->createUser('gone@example.org', Role::Admin, active: false);
        self::assertSame(422, $this->login($this->browser(), 'gone@example.org')->status());
    }

    public function testLoginIsThrottledAfterRepeatedFailures(): void
    {
        $this->createUser('target@example.org', Role::Member);
        $browser = $this->browser();
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(422, $this->login($browser, 'target@example.org', 'not the password')->status());
        }

        $locked = $this->login($browser, 'target@example.org');
        self::assertSame(429, $locked->status(), 'Even the correct password is refused while locked');
        self::assertNotNull($locked->header('Retry-After'));
        self::assertNull($browser->get('auth_user_id'));
    }

    public function testRoleBasedAccessToAdministration(): void
    {
        $this->createUser('admin@example.org', Role::Admin);
        $this->createUser('member@example.org', Role::Member);
        $this->createUser('viewer@example.org', Role::Viewer);

        foreach (['member@example.org' => 403, 'viewer@example.org' => 403, 'admin@example.org' => 200] as $email => $status) {
            $browser = $this->browser();
            $this->login($browser, $email);
            self::assertSame($status, $this->request($browser, 'GET', '/admin/users')->status(), $email);
        }
    }

    public function testNonAdminCannotCreateUsersEvenWithValidCsrf(): void
    {
        $this->createUser('member@example.org', Role::Member);
        $browser = $this->browser();
        $this->login($browser, 'member@example.org');
        $token = self::csrfFrom($this->request($browser, 'GET', '/'));

        $response = $this->request($browser, 'POST', '/admin/users', [
            '_csrf' => $token, 'email' => 'x@example.org', 'display_name' => 'X', 'role' => 'admin', 'locale' => 'en', 'password' => 'a long enough passphrase',
        ]);
        self::assertSame(403, $response->status());
        self::assertSame(0, (int) $this->pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'x@example.org'")->fetchColumn());
    }

    public function testAdminCreatesUserWithValidation(): void
    {
        $adminId = $this->createUser('admin@example.org', Role::Admin);
        $browser = $this->browser();
        $this->login($browser, 'admin@example.org');
        $token = self::csrfFrom($this->request($browser, 'GET', '/admin/users/new'));

        $invalid = $this->request($browser, 'POST', '/admin/users', [
            '_csrf' => $token, 'email' => 'bad', 'display_name' => '<script>alert(1)</script>', 'role' => 'superuser', 'locale' => 'fr', 'password' => 'short',
        ]);
        self::assertSame(422, $invalid->status());
        self::assertStringNotContainsString('<script>alert(1)</script>', $invalid->body());
        self::assertStringContainsString('&lt;script&gt;', $invalid->body());
        self::assertStringNotContainsString('value="short"', $invalid->body(), 'Passwords are never echoed back');

        $created = $this->request($browser, 'POST', '/admin/users', [
            '_csrf' => $token, 'email' => 'New.Reader@Example.org', 'display_name' => 'New Reader', 'role' => 'viewer', 'locale' => 'gl', 'password' => 'a long enough passphrase',
        ]);
        self::assertSame(303, $created->status());

        $row = $this->pdo()->query("SELECT u.email, u.locale, u.created_by, u.password_hash, r.code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.email = 'new.reader@example.org'")->fetch();
        self::assertSame('viewer', $row['code']);
        self::assertSame('gl', $row['locale']);
        self::assertSame($adminId, (int) $row['created_by']);
        self::assertStringStartsWith('$argon2id$', $row['password_hash']);

        $duplicate = $this->request($browser, 'POST', '/admin/users', [
            '_csrf' => $token, 'email' => 'new.reader@example.org', 'display_name' => 'Dup', 'role' => 'viewer', 'locale' => 'en', 'password' => 'a long enough passphrase',
        ]);
        self::assertSame(422, $duplicate->status());
        self::assertStringContainsString('already exists', $duplicate->body());
    }

    public function testPasswordChangeSignsOutOtherSessions(): void
    {
        $this->createUser('member@example.org', Role::Member);
        $laptop = $this->browser();
        $phone = $this->browser();
        $this->login($laptop, 'member@example.org');
        $this->login($phone, 'member@example.org');
        self::assertSame(200, $this->request($phone, 'GET', '/account/password')->status());

        $token = self::csrfFrom($this->request($laptop, 'GET', '/account/password'));
        $response = $this->request($laptop, 'POST', '/account/password', [
            '_csrf' => $token,
            'current_password' => self::PASSWORD,
            'new_password' => 'an entirely new passphrase',
            'new_password_confirmation' => 'an entirely new passphrase',
        ]);
        self::assertSame(303, $response->status());

        self::assertSame(200, $this->request($laptop, 'GET', '/account/password')->status(), 'Current session stays signed in');
        self::assertSame(303, $this->request($phone, 'GET', '/account/password')->status(), 'Other sessions are signed out');
        self::assertSame(303, $this->login($this->browser(), 'member@example.org', 'an entirely new passphrase')->status());
    }

    public function testLogoutRequiresPostAndInvalidatesSession(): void
    {
        $this->createUser('member@example.org', Role::Member);
        $browser = $this->browser();
        $this->login($browser, 'member@example.org');

        self::assertSame(405, $this->request($browser, 'GET', '/logout')->status());
        $token = self::csrfFrom($this->request($browser, 'GET', '/'));
        self::assertSame(303, $this->request($browser, 'POST', '/logout', ['_csrf' => $token])->status());
        self::assertSame(303, $this->request($browser, 'GET', '/account/password')->status());
    }

    public function testLocaleSwitchingAndNegotiation(): void
    {
        $browser = $this->browser();
        $galician = $this->request($browser, 'GET', '/login', [], ['HTTP_ACCEPT_LANGUAGE' => 'gl-ES,gl;q=0.9']);
        self::assertStringContainsString('<html lang="gl">', $galician->body());
        self::assertSame('gl', $galician->header('Content-Language'));

        $token = self::csrfFrom($galician);
        $switch = $this->request($browser, 'POST', '/locale', ['_csrf' => $token, 'locale' => 'es', 'return_to' => 'https://evil.example/']);
        self::assertSame('/', $switch->header('Location'), 'Open redirect via return_to is blocked');
        self::assertStringContainsString('Iniciar sesión', $this->request($browser, 'GET', '/login')->body());
    }
}
