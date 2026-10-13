<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

use DigitalLibrary\Audit\AuditLogger;
use DigitalLibrary\Auth\AuthService;
use DigitalLibrary\Auth\UserRepository;
use DigitalLibrary\Database\ConnectionFactory;
use DigitalLibrary\I18n\Translator;
use DigitalLibrary\Security\Authorizer;
use DigitalLibrary\Security\Csrf;
use DigitalLibrary\Security\LoginThrottle;
use DigitalLibrary\Security\PasswordHasher;
use DigitalLibrary\Security\PasswordPolicy;
use DigitalLibrary\Security\Session;
use DigitalLibrary\Upload\CoverImageProcessor;
use DigitalLibrary\View\View;
use PDO;

/**
 * Hand-wired, lazily-constructed service container. Deliberately explicit:
 * every dependency is visible here, with no reflection or magic.
 */
final class Services
{
    /** @var array<string, object> */
    private array $instances = [];

    public function __construct(
        public readonly Config $config,
        public readonly string $basePath,
    ) {
    }

    /**
     * Replace a service (tests, CLI).
     */
    public function set(string $id, object $service): void
    {
        $this->instances[$id] = $service;
    }

    public function pdo(): PDO
    {
        return $this->once(PDO::class, fn (): PDO => ConnectionFactory::fromConfig($this->config));
    }

    public function session(): Session
    {
        return $this->once(Session::class, fn (): Session => new Session(
            true,
            $this->config->bool('SESSION_SECURE_COOKIE', true),
            $this->config->int('SESSION_IDLE_TIMEOUT', 1800),
            $this->config->int('SESSION_ABSOLUTE_TIMEOUT', 43200),
        ));
    }

    public function csrf(): Csrf
    {
        return $this->once(Csrf::class, fn (): Csrf => new Csrf($this->session()));
    }

    public function translator(): Translator
    {
        return $this->once(Translator::class, fn (): Translator => new Translator(
            $this->basePath . '/lang',
            $this->defaultLocale(),
        ));
    }

    public function defaultLocale(): string
    {
        $locale = $this->config->get('APP_LOCALE', 'en');

        return Translator::isSupported($locale) ? (string) $locale : 'en';
    }

    public function view(): View
    {
        return $this->once(View::class, fn (): View => new View($this->basePath . '/templates', $this->translator()));
    }

    public function hasher(): PasswordHasher
    {
        return $this->once(PasswordHasher::class, fn (): PasswordHasher => new PasswordHasher(
            $this->config->int('ARGON2_MEMORY_KIB', 65536),
            $this->config->int('ARGON2_TIME_COST', 3),
        ));
    }

    public function passwordPolicy(): PasswordPolicy
    {
        return $this->once(PasswordPolicy::class, fn (): PasswordPolicy => new PasswordPolicy());
    }

    public function users(): UserRepository
    {
        return $this->once(UserRepository::class, fn (): UserRepository => new UserRepository($this->pdo()));
    }

    public function throttle(): LoginThrottle
    {
        return $this->once(LoginThrottle::class, fn (): LoginThrottle => new LoginThrottle(
            $this->pdo(),
            $this->config->int('LOGIN_MAX_ATTEMPTS', 5),
            $this->config->int('LOGIN_MAX_ATTEMPTS_PER_IP', 20),
            $this->config->int('LOGIN_LOCKOUT_SECONDS', 900),
        ));
    }

    public function audit(): AuditLogger
    {
        return $this->once(AuditLogger::class, fn (): AuditLogger => new AuditLogger($this->pdo()));
    }

    public function auth(): AuthService
    {
        return $this->once(AuthService::class, fn (): AuthService => new AuthService(
            $this->users(),
            $this->hasher(),
            $this->throttle(),
            $this->session(),
            $this->csrf(),
            $this->audit(),
        ));
    }

    public function authorizer(): Authorizer
    {
        return $this->once(Authorizer::class, fn (): Authorizer => new Authorizer());
    }

    public function covers(): CoverImageProcessor
    {
        return $this->once(CoverImageProcessor::class, fn (): CoverImageProcessor => new CoverImageProcessor(
            $this->config->get('COVER_STORAGE_PATH', $this->basePath . '/storage/covers'),
            $this->config->int('UPLOAD_MAX_BYTES', 5 * 1024 * 1024),
        ));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     * @param callable(): T   $factory
     *
     * @return T
     */
    private function once(string $id, callable $factory): object
    {
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $factory();
        }
        /** @var T */
        return $this->instances[$id];
    }
}
