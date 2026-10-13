<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Security;

use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\Session;
use PHPUnit\Framework\TestCase;

final class SessionAndCsrfTest extends TestCase
{
    public function testIdleTimeoutClearsSession(): void
    {
        $session = Session::inMemory(idleTimeout: 100, absoluteTimeout: 10_000);
        $session->start(1_000);
        $session->set('auth_user_id', 5);

        $reloaded = $this->reload($session, 1_050);
        self::assertSame(5, $reloaded->get('auth_user_id'), 'Activity inside the idle window keeps the session');

        $expired = $this->reload($reloaded, 1_200);
        self::assertNull($expired->get('auth_user_id'));
        self::assertTrue($expired->get('_expired'));
    }

    public function testAbsoluteTimeoutClearsActiveSession(): void
    {
        $session = Session::inMemory(idleTimeout: 100, absoluteTimeout: 250);
        $session->start(1_000);
        $session->set('auth_user_id', 5);
        $session = $this->reload($session, 1_090);
        $session = $this->reload($session, 1_180);
        self::assertSame(5, $session->get('auth_user_id'));
        $session = $this->reload($session, 1_260);
        self::assertNull($session->get('auth_user_id'));
    }

    public function testRegenerateChangesIdentifierAndKeepsData(): void
    {
        $session = Session::inMemory();
        $session->start();
        $session->set('k', 'v');
        $before = $session->id();
        $session->regenerate();
        self::assertNotSame($before, $session->id());
        self::assertSame('v', $session->get('k'));
    }

    public function testFlashMessagesAreReadOnce(): void
    {
        $session = Session::inMemory();
        $session->start();
        $session->flash('success', 'saved');
        self::assertSame([['type' => 'success', 'message' => 'saved']], $session->takeFlashes());
        self::assertSame([], $session->takeFlashes());
    }

    public function testSecureCookieUsesHostPrefix(): void
    {
        self::assertSame('__Host-dla_session', (new Session(true, true))->cookieName());
        self::assertSame('dla_session', (new Session(true, false))->cookieName());
    }

    public function testCsrfTokenIsStableUntilRotated(): void
    {
        $session = Session::inMemory();
        $session->start();
        $csrf = new Csrf($session);
        $token = $csrf->token();

        self::assertSame(64, strlen($token));
        self::assertSame($token, $csrf->token());
        self::assertTrue($csrf->isValid($token));
        self::assertFalse($csrf->isValid(null));
        self::assertFalse($csrf->isValid(''));
        self::assertFalse($csrf->isValid(strrev($token)));

        $csrf->rotate();
        self::assertFalse($csrf->isValid($token));
    }

    public function testUnstartedSessionCannotBeUsed(): void
    {
        $this->expectException(\LogicException::class);
        Session::inMemory()->get('x');
    }

    /**
     * Simulates the next request by copying state into a fresh session object.
     */
    private function reload(Session $previous, int $now): Session
    {
        $reflection = new \ReflectionProperty(Session::class, 'memory');
        $data = $reflection->getValue($previous);
        $idle = (new \ReflectionProperty(Session::class, 'idleTimeout'))->getValue($previous);
        $absolute = (new \ReflectionProperty(Session::class, 'absoluteTimeout'))->getValue($previous);

        $next = Session::inMemory($idle, $absolute);
        $reflection->setValue($next, $data);
        $next->start($now);

        return $next;
    }
}
