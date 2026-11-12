<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Integration;

use DigitalLibrary\Http\ClientIp;
use DigitalLibrary\Security\Role;

/**
 * Phase 5A Batch 4: the application derives the client address from
 * REMOTE_ADDR only (Nginx sets it; see docker/nginx/client-ip.conf), and
 * login throttling, invitation throttling and the audit log all use that
 * same verified value. Forwarding headers are inert.
 */
final class ClientIpTest extends IntegrationTestCase
{
    private const VICTIM = '192.0.2.10';
    private const ATTACKER = '198.51.100.66';

    /**
     * Forwarding headers naming the victim, as an attacker would send them.
     *
     * @return array<string, string>
     */
    private static function spoofing(string $ip): array
    {
        return [
            'HTTP_X_FORWARDED_FOR' => $ip, 'HTTP_CF_CONNECTING_IP' => $ip, 'HTTP_X_REAL_IP' => $ip,
            'HTTP_FORWARDED' => 'for=' . $ip, 'HTTP_TRUE_CLIENT_IP' => $ip, 'HTTP_X_CLIENT_IP' => $ip,
        ];
    }

    public function testSpoofedHeadersCannotSelectAnotherClientsLoginBucket(): void
    {
        $this->createUser('reader@example.org', Role::Viewer);
        $limit = self::config()->int('LOGIN_MAX_ATTEMPTS_PER_IP', 20);
        $attacker = $this->browser();
        $server = ['REMOTE_ADDR' => self::ATTACKER] + self::spoofing(self::VICTIM);
        $token = self::csrfFrom($this->request($attacker, 'GET', '/login', [], $server));
        for ($i = 0; $i < $limit; $i++) {
            $response = $this->request($attacker, 'POST', '/login', ['_csrf' => $token, 'email' => "nobody{$i}@example.org", 'password' => 'wrong-password'], $server);
            self::assertSame(422, $response->status(), 'Generic failure, not yet throttled');
        }

        // The attacker exhausted their own bucket, whatever the headers claimed...
        $blocked = $this->request($attacker, 'POST', '/login', ['_csrf' => $token, 'email' => 'reader@example.org', 'password' => self::PASSWORD], $server);
        self::assertSame(429, $blocked->status());
        // ...and changing the forged headers does not open a fresh one.
        $rotated = ['REMOTE_ADDR' => self::ATTACKER] + self::spoofing('203.0.113.200');
        self::assertSame(429, $this->request($attacker, 'POST', '/login', ['_csrf' => $token, 'email' => 'reader@example.org', 'password' => self::PASSWORD], $rotated)->status());

        // The victim, whose address the headers named, is unaffected.
        self::assertSame(303, $this->login($this->browser(), 'reader@example.org')->status());

        self::assertSame([self::ATTACKER], $this->column("SELECT DISTINCT ip_address FROM login_attempts WHERE succeeded = 0"));
        self::assertSame([self::ATTACKER], $this->column("SELECT DISTINCT ip_address FROM audit_log WHERE action IN ('auth.login_failed', 'auth.login_throttled')"));
        self::assertSame([self::VICTIM], $this->column("SELECT DISTINCT ip_address FROM audit_log WHERE action = 'auth.login'"));
    }

    public function testThrottleAndAuditUseTheSameVerifiedAddress(): void
    {
        $this->createUser('reader@example.org', Role::Viewer);
        $cases = [
            '2001:DB8:0:0:1:0:0:7' => ['2001:db8::1:0:0:7', '2001:db8::/64'],
            '::ffff:192.0.2.77' => ['192.0.2.77', '192.0.2.77'],
            '192.0.2.78' => ['192.0.2.78', '192.0.2.78'],
        ];
        foreach ($cases as $remote => [$audited, $bucket]) {
            $this->pdo()->exec('DELETE FROM login_attempts');
            $before = $this->countRows('SELECT COALESCE(MAX(id), 0) FROM audit_log');
            $browser = $this->browser();
            $server = ['REMOTE_ADDR' => $remote] + self::spoofing(self::VICTIM);
            $token = self::csrfFrom($this->request($browser, 'GET', '/login', [], $server));
            $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => 'reader@example.org', 'password' => 'wrong-password'], $server);
            self::assertSame(303, $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => 'reader@example.org', 'password' => self::PASSWORD], $server)->status());

            self::assertSame([$audited], $this->column("SELECT DISTINCT ip_address FROM audit_log WHERE id > {$before}"), $remote);
            self::assertSame([$bucket], $this->column('SELECT DISTINCT ip_address FROM login_attempts'), $remote);
            self::assertSame(ClientIp::throttleKey($audited), $bucket, 'Bucket derived from the audited address');
        }
    }

    public function testIpv6ClientsCannotRotateAddressesWithinTheirSlash64(): void
    {
        $limit = self::config()->int('LOGIN_MAX_ATTEMPTS_PER_IP', 20);
        $browser = $this->browser();
        $token = self::csrfFrom($this->request($browser, 'GET', '/login', [], ['REMOTE_ADDR' => '2001:db8:5:6::1']));
        for ($i = 1; $i <= $limit; $i++) {
            $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => "nobody{$i}@example.org", 'password' => 'wrong-password'], ['REMOTE_ADDR' => sprintf('2001:db8:5:6:%x::1', $i)]);
        }
        $this->createUser('reader@example.org', Role::Viewer);
        $post = fn (string $ip): int => $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => 'reader@example.org', 'password' => self::PASSWORD], ['REMOTE_ADDR' => $ip])->status();

        self::assertSame(429, $post('2001:db8:5:6:ffff:ffff:ffff:ffff'), 'Same /64');
        self::assertSame(303, $post('2001:db8:5:7::1'), 'Another /64 is another client');
    }

    public function testInvitationThrottleIsPerVerifiedClient(): void
    {
        $limit = self::config()->int('INVITATION_MAX_FAILURES', 10);
        $attacker = $this->browser();
        $server = ['REMOTE_ADDR' => self::ATTACKER] + self::spoofing(self::VICTIM);
        $token = self::csrfFrom($this->request($attacker, 'GET', '/invite/redeem', [], $server));
        for ($i = 0; $i < $limit; $i++) {
            $this->request($attacker, 'POST', '/invite/redeem', ['_csrf' => $token, 'invitation_code' => str_repeat('A', 42) . chr(65 + $i)], $server);
        }

        self::assertSame(429, $this->request($attacker, 'GET', '/invite/redeem', [], $server)->status());
        self::assertSame(200, $this->request($this->browser(), 'GET', '/invite/redeem')->status(), 'The named victim keeps access');
        self::assertSame([self::ATTACKER], $this->column("SELECT DISTINCT ip_address FROM audit_log WHERE action = 'invitation.rejected'"));
    }

    public function testMalformedRemoteAddressFailsClosedToOneSharedBucket(): void
    {
        foreach (['', 'garbage', '192.0.2.1, 198.51.100.1', '[::1]'] as $remote) {
            $browser = $this->browser();
            $server = ['REMOTE_ADDR' => $remote] + self::spoofing(self::VICTIM);
            $token = self::csrfFrom($this->request($browser, 'GET', '/login', [], $server));
            $this->request($browser, 'POST', '/login', ['_csrf' => $token, 'email' => 'nobody@example.org', 'password' => 'wrong-password'], $server);
        }

        self::assertSame([ClientIp::UNKNOWN], $this->column('SELECT DISTINCT ip_address FROM login_attempts'));
        self::assertSame([ClientIp::UNKNOWN], $this->column("SELECT DISTINCT ip_address FROM audit_log WHERE action = 'auth.login_failed'"));
    }

    public function testHealthCheckIgnoresClientAddressAndForwardingHeaders(): void
    {
        foreach (['', 'garbage', '::1', '127.0.0.1'] as $remote) {
            $health = $this->request($this->browser(), 'GET', '/health', [], ['REMOTE_ADDR' => $remote, 'HTTP_HOST' => 'localhost'] + self::spoofing(self::VICTIM));
            self::assertSame(200, $health->status(), var_export($remote, true));
            self::assertSame(['status' => 'ok'], json_decode($health->body(), true));
        }
    }

    /**
     * @return list<string>
     */
    private function column(string $sql): array
    {
        return array_map('strval', $this->pdo()->query($sql)->fetchAll(\PDO::FETCH_COLUMN));
    }
}
