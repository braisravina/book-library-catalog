<?php

declare(strict_types=1);

namespace DigitalLibrary\Auth;

use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\LoginThrottle;
use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\Session;

final class AuthService
{
    private const SESSION_USER = 'auth_user_id';
    private const SESSION_VERSION = 'auth_version';

    private ?User $resolved = null;
    private bool $resolvedOnce = false;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly AuditLogger $audit,
    ) {
    }

    public function attempt(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent = ''): AuthResult
    {
        $retryAfter = $this->throttle->retryAfter($email, $ip);
        if ($retryAfter > 0) {
            $this->audit->log('auth.login_throttled', null, 'user', null, [], $ip, $userAgent);

            return AuthResult::throttled($retryAfter);
        }

        $credentials = $this->users->findCredentialsByEmail($email);
        if ($credentials === null) {
            $this->hasher->dummyVerify($password);
            $this->throttle->recordFailure($email, $ip);
            $this->audit->log('auth.login_failed', null, 'user', null, ['reason' => 'unknown_account'], $ip, $userAgent);

            return AuthResult::failure();
        }

        $user = $credentials['user'];
        $valid = $this->hasher->verify($password, $credentials['password_hash']);
        if (!$valid || !$user->isActive) {
            $this->throttle->recordFailure($email, $ip);
            $this->audit->log('auth.login_failed', $user->id, 'user', $user->id, ['reason' => $valid ? 'inactive' : 'bad_password'], $ip, $userAgent);

            return AuthResult::failure();
        }

        if ($this->hasher->needsRehash($credentials['password_hash'])) {
            $this->users->updatePassword($user->id, $this->hasher->hash($password), false);
        }
        $this->throttle->recordSuccess($email, $ip);
        $this->users->recordLogin($user->id);
        $this->login($user);
        $this->audit->log('auth.login', $user->id, 'user', $user->id, [], $ip, $userAgent);

        return AuthResult::success($user);
    }

    public function login(User $user): void
    {
        // Fresh identifier and CSRF token on privilege change (session fixation defence).
        $this->session->regenerate();
        $this->session->set(self::SESSION_USER, $user->id);
        $this->session->set(self::SESSION_VERSION, $user->authVersion);
        $this->session->set('locale', $user->locale);
        $this->csrf->rotate();
        $this->resolved = $user;
        $this->resolvedOnce = true;
    }

    public function logout(): void
    {
        $this->session->invalidate();
        $this->csrf->rotate();
        $this->resolved = null;
        $this->resolvedOnce = true;
    }

    /**
     * The authenticated user, re-validated against the database on each
     * request: deactivated accounts and stale auth versions are logged out.
     */
    public function user(): ?User
    {
        if ($this->resolvedOnce) {
            return $this->resolved;
        }
        $this->resolvedOnce = true;
        $id = $this->session->get(self::SESSION_USER);
        if (!is_int($id)) {
            return null;
        }
        $user = $this->users->findById($id);
        if ($user === null || !$user->isActive || $user->authVersion !== $this->session->get(self::SESSION_VERSION)) {
            $this->logout();

            return null;
        }

        return $this->resolved = $user;
    }

    public function changePassword(User $user, #[\SensitiveParameter] string $newPassword): void
    {
        $this->users->updatePassword($user->id, $this->hasher->hash($newPassword));
        $fresh = $this->users->findById($user->id);
        if ($fresh !== null) {
            $this->login($fresh);
        }
    }

    public function verifyPassword(User $user, #[\SensitiveParameter] string $password): bool
    {
        $hash = $this->users->passwordHash($user->id);

        return $hash !== null && $this->hasher->verify($password, $hash);
    }
}
