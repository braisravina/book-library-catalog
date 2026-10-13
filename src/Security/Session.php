<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

/**
 * Session wrapper with hardened cookie settings, idle and absolute timeouts.
 *
 * In "native" mode it drives PHP's session extension; otherwise it keeps data
 * in memory, which lets the full HTTP stack be exercised in tests.
 */
final class Session
{
    private const META = '_meta';
    private const FLASH = '_flash';

    /** @var array<string, mixed> */
    private array $memory = [];
    private bool $started = false;
    private string $memoryId = '';

    public function __construct(
        private readonly bool $native = true,
        private readonly bool $secureCookie = true,
        private readonly int $idleTimeout = 1800,
        private readonly int $absoluteTimeout = 43200,
        private readonly string $cookieName = 'dla_session',
    ) {
    }

    public static function inMemory(int $idleTimeout = 1800, int $absoluteTimeout = 43200): self
    {
        return new self(false, false, $idleTimeout, $absoluteTimeout);
    }

    public function cookieName(): string
    {
        // The __Host- prefix forces Secure, Path=/ and no Domain attribute in browsers.
        return $this->secureCookie ? '__Host-' . $this->cookieName : $this->cookieName;
    }

    public function start(?int $now = null): void
    {
        if ($this->started) {
            return;
        }
        $now ??= time();

        if ($this->native) {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                session_name($this->cookieName());
                session_set_cookie_params([
                    'lifetime' => 0,
                    'path' => '/',
                    'domain' => '',
                    'secure' => $this->secureCookie,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]);
                session_start([
                    'use_strict_mode' => true,
                    'use_only_cookies' => true,
                    'use_trans_sid' => false,
                    'cookie_httponly' => true,
                    'gc_maxlifetime' => max($this->idleTimeout, 60),
                ]);
            }
        } else {
            $this->memoryId = bin2hex(random_bytes(16));
        }
        $this->started = true;

        $meta = $this->get(self::META);
        if (!is_array($meta)) {
            $this->set(self::META, ['created_at' => $now, 'last_activity' => $now]);

            return;
        }
        $expiredIdle = $now - (int) ($meta['last_activity'] ?? 0) > $this->idleTimeout;
        $expiredAbsolute = $now - (int) ($meta['created_at'] ?? 0) > $this->absoluteTimeout;
        if ($expiredIdle || $expiredAbsolute) {
            $this->invalidate($now);
            $this->set('_expired', true);

            return;
        }
        $meta['last_activity'] = $now;
        $this->set(self::META, $meta);
    }

    public function id(): string
    {
        return $this->native ? (string) session_id() : $this->memoryId;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $bag = &$this->bag();

        return $bag[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $bag = &$this->bag();
        $bag[$key] = $value;
    }

    public function has(string $key): bool
    {
        $bag = &$this->bag();

        return array_key_exists($key, $bag);
    }

    public function remove(string $key): void
    {
        $bag = &$this->bag();
        unset($bag[$key]);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);

        return $value;
    }

    public function flash(string $type, string $messageKey): void
    {
        $flashes = $this->get(self::FLASH, []);
        $flashes[] = ['type' => $type, 'message' => $messageKey];
        $this->set(self::FLASH, $flashes);
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    public function takeFlashes(): array
    {
        $flashes = $this->pull(self::FLASH, []);

        return is_array($flashes) ? $flashes : [];
    }

    /**
     * New session identifier, same data. Call on every privilege change.
     */
    public function regenerate(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        } else {
            $this->memoryId = bin2hex(random_bytes(16));
        }
    }

    /**
     * Drops all data and issues a fresh identifier.
     */
    public function invalidate(?int $now = null): void
    {
        $now ??= time();
        $bag = &$this->bag();
        $bag = [];
        $this->regenerate();
        $this->set(self::META, ['created_at' => $now, 'last_activity' => $now]);
    }

    /**
     * @return array<string, mixed>
     */
    private function &bag(): array
    {
        if (!$this->started) {
            throw new \LogicException('Session has not been started.');
        }
        if ($this->native) {
            return $_SESSION;
        }

        return $this->memory;
    }
}
