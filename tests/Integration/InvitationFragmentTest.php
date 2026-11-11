<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\Response;
use DigitalLibrary\Invitations\InvitationToken;
use DigitalLibrary\Security\Role;
use DigitalLibrary\Security\Session;

/**
 * Phase 5A: fragment invitation links (/invite/redeem#TOKEN) and the
 * POST /invite/redeem entry point, alongside the unchanged query format.
 */
final class InvitationFragmentTest extends IntegrationTestCase
{
    private const NEW_PASSWORD = 'amber orchard compass 2031';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('admin@example.org', Role::Admin);
    }

    public function testFragmentLinksKeepTheTokenOutOfEveryRequestUrl(): void
    {
        self::$configOverrides = ['INVITATION_LINK_FORMAT' => 'fragment'];
        [$admin, $token] = $this->signedIn('admin@example.org');
        $issued = $this->issue($admin, $token, 'reader@example.org');
        $raw = $this->fragmentToken($issued);
        self::assertStringContainsString('http://library.test/invite/redeem#' . $raw, $issued->body());
        self::assertStringNotContainsString('?token=', $issued->body());

        // The landing page: a CSRF-protected form, no token, never cached.
        $invitee = $this->browser();
        $landing = $this->request($invitee, 'GET', '/invite/redeem');
        self::assertSame(200, $landing->status());
        self::assertStringContainsString('data-invitation-fragment', $landing->body());
        self::assertStringContainsString('name="invitation_code"', $landing->body());
        self::assertStringNotContainsString($raw, $landing->body());
        self::assertStringContainsString('no-store', (string) $landing->header('Cache-Control'));
        self::assertNotSame('no-referrer', $landing->header('Referrer-Policy'), 'The form must keep a real Origin header');
        self::assertStringContainsString("script-src 'self'", (string) $landing->header('Content-Security-Policy'));
        self::assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)#i', $landing->body(), 'No inline script (CSP)');

        // The token travels in the POST body only.
        $open = $this->redeem($invitee, self::csrfFrom($landing), $raw);
        self::assertSame(303, $open->status());
        self::assertSame('/invite/accept', $open->header('Location'));
        self::assertSame('no-referrer', $open->header('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $open->header('Cache-Control'));
        self::assertStringNotContainsString($raw, serialize($invitee->get('invitation')), 'Only the hash is kept in the session');

        $form = $this->request($invitee, 'GET', '/invite/accept');
        $done = $this->request($invitee, 'POST', '/invite/accept', [
            '_csrf' => self::csrfFrom($form), 'email' => 'reader@example.org', 'display_name' => 'Rosa Reader',
            'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD,
        ], ['HTTP_ORIGIN' => 'http://library.test']);
        self::assertSame('/login', $done->header('Location'));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM users WHERE email = 'reader@example.org'"));
        self::assertSame(303, $this->login($this->browser(), 'reader@example.org', self::NEW_PASSWORD)->status());

        // Single use, through either entry point.
        $again = $this->browser();
        $page = $this->request($again, 'GET', '/invite/redeem');
        self::assertSame('/invite/invalid', $this->redeem($again, self::csrfFrom($page), $raw)->header('Location'));
        self::assertSame('/invite/invalid', $this->request($this->browser(), 'GET', '/invite?token=' . $raw)->header('Location'));
    }

    public function testEitherEntryPointRedeemsTokensFromEitherLinkFormat(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $queryToken = $this->queryToken($this->issue($admin, $token, 'query@example.org'));
        self::$configOverrides = ['INVITATION_LINK_FORMAT' => 'fragment'];
        $fragmentToken = $this->fragmentToken($this->issue($admin, $token, 'fragment@example.org'));

        $viaPost = $this->browser();
        $landing = $this->request($viaPost, 'GET', '/invite/redeem');
        self::assertSame('/invite/accept', $this->redeem($viaPost, self::csrfFrom($landing), $queryToken)->header('Location'));

        $viaGet = $this->browser();
        self::assertSame('/invite/accept', $this->request($viaGet, 'GET', '/invite?token=' . $fragmentToken)->header('Location'));
    }

    public function testPostedCodesRequireAValidCsrfTokenAndTheSiteOrigin(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $raw = $this->queryToken($this->issue($admin, $token, 'reader@example.org'));
        $before = $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.rejected'");

        $cases = [
            'no CSRF token' => [419, ['invitation_code' => $raw], []],
            'wrong CSRF token' => [419, ['invitation_code' => $raw, '_csrf' => str_repeat('0', 64)], []],
            'foreign origin' => [403, ['invitation_code' => $raw], ['HTTP_ORIGIN' => 'https://evil.example'], true],
            'opaque origin' => [403, ['invitation_code' => $raw], ['HTTP_ORIGIN' => 'null'], true],
            'cross-site fetch' => [403, ['invitation_code' => $raw], ['HTTP_SEC_FETCH_SITE' => 'cross-site'], true],
        ];
        foreach ($cases as $label => $case) {
            [$status, $body, $server] = $case;
            $browser = $this->browser();
            $landing = $this->request($browser, 'GET', '/invite/redeem');
            if (isset($case[3])) {
                $body['_csrf'] = self::csrfFrom($landing); // valid token, refused on origin alone
            }
            $response = $this->request($browser, 'POST', '/invite/redeem', $body, $server);
            self::assertSame($status, $response->status(), $label);
            self::assertNull($browser->get('invitation'), $label . ': no invitation state');
            self::assertStringNotContainsString($raw, $response->body(), $label);
        }
        self::assertSame($before, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.rejected'"), 'Refused before the handler runs');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM invitations WHERE accepted_at IS NULL AND revoked_at IS NULL"), 'Still redeemable');
    }

    public function testExpiredRevokedUnknownAndMalformedCodesShareTheGenericAnswer(): void
    {
        [$admin, $token] = $this->signedIn('admin@example.org');
        $expired = $this->queryToken($this->issue($admin, $token, 'late@example.org'));
        $this->pdo()->exec("UPDATE invitations SET created_at = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_at = UTC_TIMESTAMP() - INTERVAL 1 SECOND WHERE email = 'late@example.org'");
        $revoked = $this->queryToken($this->issue($admin, $token, 'gone@example.org'));
        $id = $this->countRows("SELECT id FROM invitations WHERE email = 'gone@example.org'");
        $this->request($admin, 'POST', "/admin/invitations/{$id}/revoke", ['_csrf' => $token]);

        $codes = ['expired' => $expired, 'revoked' => $revoked, 'unknown' => InvitationToken::generate(), 'malformed' => 'abc', 'empty' => '', 'padded' => ' ' . InvitationToken::generate() . ' '];
        foreach ($codes as $label => $raw) {
            $browser = $this->browser();
            $landing = $this->request($browser, 'GET', '/invite/redeem');
            $response = $this->redeem($browser, self::csrfFrom($landing), $raw);
            self::assertSame(303, $response->status(), $label);
            self::assertSame('/invite/invalid', $response->header('Location'), $label);
            self::assertSame('no-referrer', $response->header('Referrer-Policy'), $label);
            self::assertNull($browser->get('invitation'), $label);
        }
        self::assertSame(count($codes), $this->countRows("SELECT COUNT(*) FROM audit_log WHERE action = 'invitation.rejected' AND JSON_UNQUOTE(JSON_EXTRACT(details, '$.reason')) = 'redeem'"));
    }

    public function testPostedCodesShareTheInvitationRateLimit(): void
    {
        self::$configOverrides = ['INVITATION_MAX_FAILURES' => '3'];
        [$admin, $token] = $this->signedIn('admin@example.org');
        $valid = $this->queryToken($this->issue($admin, $token, 'reader@example.org'));

        $browser = $this->browser();
        for ($i = 0; $i < 3; $i++) {
            $landing = $this->request($browser, 'GET', '/invite/redeem');
            $this->redeem($browser, self::csrfFrom($landing), InvitationToken::generate());
        }
        $blocked = $this->request($browser, 'GET', '/invite/redeem');
        self::assertSame(429, $blocked->status());
        self::assertNotNull($blocked->header('Retry-After'));
        self::assertSame('/invite/invalid', $this->redeem($browser, self::csrfFrom($blocked), $valid)->header('Location'), 'Even a valid code is refused while throttled');
        self::assertSame('/invite/invalid', $this->request($this->browser(), 'GET', '/invite?token=' . $valid)->header('Location'), 'One limit for both entry points');
        self::assertNull($browser->get('invitation'));

        // Another client address is unaffected.
        $other = $this->request($this->browser(), 'GET', '/invite?token=' . $valid, [], ['REMOTE_ADDR' => '198.51.100.7']);
        self::assertSame('/invite/accept', $other->header('Location'));
    }

    public function testInvitationTokensNeverReachTheApplicationLog(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'dla-log-');
        $previous = ini_set('error_log', $log);
        try {
            [$admin, $token] = $this->signedIn('admin@example.org');
            $query = $this->queryToken($this->issue($admin, $token, 'one@example.org'));
            self::$configOverrides = ['INVITATION_LINK_FORMAT' => 'fragment', 'INVITATION_MAX_FAILURES' => '4'];
            $fragment = $this->fragmentToken($this->issue($admin, $token, 'two@example.org'));
            $unknown = InvitationToken::generate();
            $responses = [];

            $a = $this->browser();
            $responses[] = $this->request($a, 'GET', '/invite?token=' . $query);
            $responses[] = $form = $this->request($a, 'GET', '/invite/accept');
            $responses[] = $this->request($a, 'POST', '/invite/accept', ['_csrf' => self::csrfFrom($form), 'email' => 'wrong@example.org', 'display_name' => 'X', 'password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
            $b = $this->browser();
            $responses[] = $landing = $this->request($b, 'GET', '/invite/redeem');
            $responses[] = $this->redeem($b, self::csrfFrom($landing), $fragment);
            $responses[] = $this->request($this->browser(), 'POST', '/invite/redeem', ['invitation_code' => $fragment]);
            $c = $this->browser();
            foreach ([$unknown, $unknown, $unknown, $unknown, $query] as $code) {
                $responses[] = $landing = $this->request($c, 'GET', '/invite/redeem');
                $responses[] = $this->redeem($c, self::csrfFrom($landing), $code);
            }
            $responses[] = $this->request($c, 'GET', '/invite?token=' . $fragment);

            error_log('[digital-library] log-capture canary');
            $logged = (string) file_get_contents($log);
            self::assertStringContainsString('log-capture canary', $logged, 'The application log is really captured');
            foreach ([$query, $fragment, $unknown] as $raw) {
                self::assertStringNotContainsString($raw, $logged, 'error log');
                self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM audit_log WHERE CONCAT_WS(' ', details, user_agent, ip_address) LIKE '%{$raw}%'"), 'audit log');
                foreach ([$a, $b, $c] as $session) {
                    self::assertStringNotContainsString($raw, serialize([$session->get('invitation'), $session->get('_flash')]), 'session');
                }
                foreach ($responses as $response) {
                    self::assertStringNotContainsString($raw, $response->body() . json_encode($response->headers()), 'response');
                }
            }
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    private function issue(Session $browser, string $token, string $email): Response
    {
        return $this->request($browser, 'POST', '/admin/invitations', ['_csrf' => $token, 'email' => $email, 'role' => 'member', 'expires_on' => gmdate('Y-m-d', strtotime('+7 days'))]);
    }

    private function redeem(Session $browser, string $csrf, string $code): Response
    {
        return $this->request($browser, 'POST', '/invite/redeem', ['_csrf' => $csrf, 'invitation_code' => $code], ['HTTP_ORIGIN' => 'http://library.test']);
    }

    private function queryToken(Response $response): string
    {
        self::assertSame(1, preg_match('#/invite\?token=([A-Za-z0-9_-]{43})"#', $response->body(), $m), 'query-format link shown');

        return $m[1];
    }

    private function fragmentToken(Response $response): string
    {
        self::assertSame(1, preg_match('#/invite/redeem\#([A-Za-z0-9_-]{43})"#', $response->body(), $m), 'fragment-format link shown');

        return $m[1];
    }
}
