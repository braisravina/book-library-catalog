<?php

declare(strict_types=1);

namespace DigitalLibrary\Core;

use DigitalLibrary\Http\HostPolicy;
use DigitalLibrary\Invitations\InvitationLink;

/**
 * Validates the effective configuration. Read-only: it never connects to
 * the database, writes files or changes state.
 *
 * Errors make production refuse to serve (Application) and make
 * `bin/console config:check` exit non-zero; warnings are advisory.
 * Messages name keys and requirements only, never configured values.
 *
 * Scope: settings the application itself enforces. TLS termination,
 * trusted proxies / client IP (nginx real_ip), egress filtering and CDN
 * logging are enforced by nginx, Cloudflare and the Docker host, and are
 * listed as notes only.
 */
final class ConfigValidator
{
    private const BOOLEANS = ['1', '0', 'true', 'false', 'yes', 'no', 'on', 'off'];
    private const SECRETS = ['DB_PASSWORD', 'DB_MIGRATION_PASSWORD', 'DB_ROOT_PASSWORD', 'GOOGLE_BOOKS_API_KEY'];
    /** Positive integer settings: key => [min, max]. */
    private const INTEGERS = [
        'DB_PORT' => [1, 65535],
        'SESSION_IDLE_TIMEOUT' => [60, 86400],
        'SESSION_ABSOLUTE_TIMEOUT' => [300, 2592000],
        'LOGIN_MAX_ATTEMPTS' => [1, 1000],
        'LOGIN_MAX_ATTEMPTS_PER_IP' => [1, 100000],
        'LOGIN_LOCKOUT_SECONDS' => [60, 86400],
        'ARGON2_MEMORY_KIB' => [8192, 4194304],
        'ARGON2_TIME_COST' => [1, 20],
        'METADATA_TIMEOUT_SECONDS' => [1, 60],
        'METADATA_CACHE_DAYS' => [1, 3650],
        'UPLOAD_MAX_BYTES' => [1024, 52428800],
        'INVITATION_MAX_DAYS' => [1, 90],
        'INVITATION_MAX_FAILURES' => [1, 10000],
        'INVITATION_RETENTION_DAYS' => [1, 36500],
        'LOGIN_ATTEMPT_RETENTION_DAYS' => [1, 36500],
        'TEMP_FILE_RETENTION_HOURS' => [1, 87600],
        'ORPHAN_COVER_MIN_AGE_HOURS' => [1, 87600],
        'BACKUP_KEEP_COUNT' => [1, 10000],
        'BACKUP_KEEP_DAYS' => [1, 36500],
    ];
    private const PATHS = ['COVER_STORAGE_PATH', 'BACKUP_PATH', 'TEMP_PATH', 'MAINTENANCE_FLAG_PATH'];
    private const PROVIDERS = ['open_library', 'google_books', 'none'];
    /**
     * APP_ROLE: which credentials a container may hold. "web" (serves HTTP;
     * runtime account only), "ops" (one-off CLI work: migrations, backups,
     * restores; runtime and migration accounts). Unset: one container does
     * both (development, simple installs).
     */
    public const ROLES = ['web', 'ops'];

    /** @var list<array{string, string}> */
    private array $errors = [];
    /** @var list<array{string, string}> */
    private array $warnings = [];

    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @return array{errors: list<array{string, string}>, warnings: list<array{string, string}>, notes: list<string>}
     */
    public function validate(Config $config): array
    {
        $this->errors = [];
        $this->warnings = [];
        $production = $config->isProduction();

        $env = $this->read($config, 'APP_ENV');
        if ($env !== null && !in_array($env, Config::ENVIRONMENTS, true)) {
            $this->error('APP_ENV', 'must be production, development or testing; any other value is treated as production.');
        } elseif ($env === null) {
            $this->warning('APP_ENV', 'is not set; the application runs as production.');
        }

        $this->booleans($config);
        $https = $this->appUrl($config, $production);
        $this->sessions($config, $production, $https);
        $this->secrets($config, $production);
        $this->database($config, $production);
        $this->integers($config, $production);
        $this->invitations($config, $production);
        $this->metadata($config);
        $this->paths($config);
        if ($production && $config->bool('APP_DEBUG')) {
            $this->warning('APP_DEBUG', 'is ignored in production (debug output is never shown); set it to false.');
        }
        if ($this->read($config, 'DB_ROOT_PASSWORD') !== null) {
            if ($this->role($config) !== null) {
                $this->error('DB_ROOT_PASSWORD', 'must not be available to application containers (APP_ROLE is set); only the database container needs it.');
            } elseif ($production) {
                $this->warning('DB_ROOT_PASSWORD', 'is visible to the application; only the database container needs it. Do not pass it to the PHP container.');
            }
        }
        if ($production) {
            foreach (['DB_TEST_DATABASE', 'DB_TEST_USERNAME', 'DB_TEST_MIGRATION_USERNAME'] as $key) {
                if ($this->read($config, $key) !== null) {
                    $this->warning($key, 'is a development/test setting and should not be present in production.');
                }
            }
        }

        return [
            'errors' => $this->errors,
            'warnings' => $this->warnings,
            'notes' => [
                'Client IP: the application uses REMOTE_ADDR only and never reads X-Forwarded-For or CF-Connecting-IP; trusted proxies are configured in nginx (docker/nginx/client-ip.conf, which trusts none by default), not here.',
                'Host: only the host and port of APP_URL are answered (X-Forwarded-Host is ignored).',
                'TLS, HTTP-to-HTTPS redirects, CDN/proxy logging and outbound egress are enforced outside the application (Cloudflare, nginx, Docker host).',
            ],
        ];
    }

    /**
     * True when production must refuse to serve with this configuration.
     */
    public function blocksProduction(Config $config): bool
    {
        return $config->isProduction() && $this->validate($config)['errors'] !== [];
    }

    private function booleans(Config $config): void
    {
        foreach (['APP_DEBUG', 'SESSION_SECURE_COOKIE', 'SECURITY_HSTS'] as $key) {
            $value = $this->read($config, $key);
            if ($value !== null && !in_array(strtolower($value), self::BOOLEANS, true)) {
                $this->error($key, 'must be true or false (unrecognised values count as false).');
            }
        }
    }

    /**
     * @return bool|null true for https, false for http, null when unusable
     */
    private function appUrl(Config $config, bool $production): ?bool
    {
        $url = $this->read($config, 'APP_URL');
        if ($url === null) {
            $production
                ? $this->error('APP_URL', 'is required in production (origin checks, Host validation, invitation links).')
                : $this->warning('APP_URL', 'is not set: Origin and Host checks are disabled and invitation links are relative.');

            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            $this->error('APP_URL', 'must be an absolute http:// or https:// URL, e.g. https://library.example.org');

            return null;
        }
        $ok = true;
        if (isset($parts['user']) || isset($parts['pass'])) {
            $this->error('APP_URL', 'must not contain credentials.');
            $ok = false;
        }
        if (isset($parts['query']) || isset($parts['fragment']) || str_contains($url, '?') || str_contains($url, '#')) {
            $this->error('APP_URL', 'must not contain a query string or fragment.');
            $ok = false;
        }
        if (($parts['path'] ?? '') !== '' && $parts['path'] !== '/') {
            $this->error('APP_URL', 'must be the site root (the application is served at "/"; no path).');
            $ok = false;
        }
        if (HostPolicy::fromAppUrl($url) === null) {
            $this->error('APP_URL', 'must contain a valid host name (letters, digits, dots and hyphens; no IPv6 literal).');
            $ok = false;
        }
        $https = strtolower($parts['scheme']) === 'https';
        if ($production && !$https) {
            $this->error('APP_URL', 'must use https:// in production.');
        }
        if ($production && $ok && (strtolower($parts['host']) === 'localhost' || filter_var($parts['host'], FILTER_VALIDATE_IP) !== false)) {
            $this->warning('APP_URL', 'points to localhost or an IP address; production should use the public host name.');
        }

        return $ok ? $https : null;
    }

    private function sessions(Config $config, bool $production, ?bool $https): void
    {
        $secure = $config->bool('SESSION_SECURE_COOKIE', true);
        if ($production && !$secure) {
            $this->error('SESSION_SECURE_COOKIE', 'must be true in production.');
        }
        if ($https === false && $secure) {
            $this->error('SESSION_SECURE_COOKIE', 'is true but APP_URL uses http://: browsers would drop the (__Host-) session cookie and nobody could sign in. Use false for local HTTP development only.');
        }
        if (!$production && $https === true && !$secure) {
            $this->warning('SESSION_SECURE_COOKIE', 'is false although APP_URL uses https://.');
        }
        $hsts = $config->bool('SECURITY_HSTS', false);
        if ($production && !$hsts) {
            $this->error('SECURITY_HSTS', 'must be true in production (the application sends Strict-Transport-Security).');
        }
        if ($hsts && $https === false) {
            $this->warning('SECURITY_HSTS', 'has no effect over http:// (browsers ignore HSTS on insecure responses).');
        }
        if ($config->int('SESSION_ABSOLUTE_TIMEOUT', 43200) < $config->int('SESSION_IDLE_TIMEOUT', 1800)) {
            $this->warning('SESSION_ABSOLUTE_TIMEOUT', 'is shorter than SESSION_IDLE_TIMEOUT.');
        }
    }

    private function secrets(Config $config, bool $production): void
    {
        foreach (self::SECRETS as $key) {
            $value = $this->read($config, $key);
            if ($value === null) {
                continue;
            }
            if (preg_match('/change[_-]?me/i', $value) === 1) {
                $production
                    ? $this->error($key, 'still contains a placeholder (CHANGE_ME); generate a real secret.')
                    : $this->warning($key, 'still contains a placeholder (CHANGE_ME).');
            } elseif ($production && $key !== 'GOOGLE_BOOKS_API_KEY' && strlen($value) < 16) {
                $this->warning($key, 'is shorter than 16 characters; use a long random value.');
            }
        }
        $app = $this->read($config, 'DB_PASSWORD');
        if ($production && $app !== null && $app === $this->read($config, 'DB_MIGRATION_PASSWORD')) {
            $this->warning('DB_MIGRATION_PASSWORD', 'is the same as DB_PASSWORD; use distinct secrets.');
        }
    }

    private function database(Config $config, bool $production): void
    {
        foreach (['DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
            if ($this->read($config, $key) === null) {
                $this->error($key, 'is required.');
            }
        }
        $database = $this->read($config, 'DB_DATABASE');
        if ($database !== null && preg_match('/^[A-Za-z0-9_]{1,64}$/', $database) !== 1) {
            $this->error('DB_DATABASE', 'must be a plain database name (letters, digits, underscore).');
        }
        $user = $this->read($config, 'DB_USERNAME');
        $migrator = $this->read($config, 'DB_MIGRATION_USERNAME');
        $role = $this->role($config);
        if ($production && $user !== null && strtolower($user) === 'root') {
            $this->error('DB_USERNAME', 'must not be root; use the least-privilege application account.');
        }
        if ($role === 'web') {
            // Least privilege: the web container never holds schema rights.
            foreach (['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD'] as $key) {
                if ($this->read($config, $key) !== null) {
                    $this->error($key, 'must not be set in the web container (APP_ROLE=web); migrations run in the ops container.');
                }
            }

            return;
        }
        if ($role === 'ops') {
            foreach (['DB_MIGRATION_USERNAME', 'DB_MIGRATION_PASSWORD'] as $key) {
                if ($this->read($config, $key) === null) {
                    $this->error($key, 'is required in the ops container (APP_ROLE=ops).');
                }
            }
        } elseif ($migrator !== null && $this->read($config, 'DB_MIGRATION_PASSWORD') === null) {
            $this->error('DB_MIGRATION_PASSWORD', 'is required when DB_MIGRATION_USERNAME is set.');
        }
        if ($migrator === null) {
            if ($role === 'ops') {
                return;
            }
            $this->warning('DB_MIGRATION_USERNAME', 'is not set: migrations would run with the application account, which lacks DDL rights in the intended setup.');
        } elseif ($user !== null && strcasecmp($user, $migrator) === 0) {
            $this->warning('DB_MIGRATION_USERNAME', 'is the same account as DB_USERNAME; the runtime account should not hold schema privileges.');
        }
    }

    private function integers(Config $config, bool $production): void
    {
        foreach (self::INTEGERS as $key => [$min, $max]) {
            $value = $this->read($config, $key);
            if ($value === null) {
                continue;
            }
            if (preg_match('/^\d+$/', $value) !== 1) {
                $this->warning($key, 'is not a whole number; the built-in default is used instead.');
            } elseif ((int) $value < $min || (int) $value > $max) {
                $this->warning($key, sprintf('is outside the supported range %d-%d.', $min, $max));
            }
        }
        if ($production) {
            if ($config->int('ARGON2_MEMORY_KIB', 65536) < 19456 || $config->int('ARGON2_TIME_COST', 3) < 2) {
                $this->warning('ARGON2_MEMORY_KIB', 'Argon2id parameters are below the OWASP minimum (19456 KiB, time cost 2).');
            }
        }
    }

    private function invitations(Config $config, bool $production): void
    {
        $format = $this->read($config, 'INVITATION_LINK_FORMAT') ?? InvitationLink::QUERY;
        if (!in_array($format, [InvitationLink::QUERY, InvitationLink::FRAGMENT], true)) {
            $this->error('INVITATION_LINK_FORMAT', 'must be "query" or "fragment".');
        } elseif ($production && $format === InvitationLink::QUERY) {
            $this->warning('INVITATION_LINK_FORMAT', 'is "query": invitation tokens appear in request URLs (CDN/proxy logs). "fragment" is recommended.');
        }
    }

    private function metadata(Config $config): void
    {
        $contact = $this->read($config, 'METADATA_CONTACT');
        if ($contact !== null && \DigitalLibrary\Core\Services::userAgent($contact) === \DigitalLibrary\Core\Services::userAgent(null)) {
            $this->warning('METADATA_CONTACT', 'is not an e-mail address or https URL, so it is not sent (the User-Agent has no contact).');
        }
        $providers = $this->read($config, 'METADATA_PROVIDERS');
        if ($providers === null) {
            return;
        }
        $unknown = array_diff($config->list('METADATA_PROVIDERS'), self::PROVIDERS);
        if ($unknown !== []) {
            $this->error('METADATA_PROVIDERS', 'contains an unknown provider; allowed: ' . implode(', ', self::PROVIDERS) . '.');
        }
    }

    private function paths(Config $config): void
    {
        $public = realpath($this->basePath . '/public');
        foreach (self::PATHS as $key) {
            $path = $this->read($config, $key);
            if ($path === null) {
                continue;
            }
            if (!str_starts_with($path, '/')) {
                $this->error($key, 'must be an absolute path.');
                continue;
            }
            $resolved = self::resolve($path);
            if ($public !== false && ($resolved === $public || str_starts_with($resolved . '/', $public . '/'))) {
                $this->error($key, 'must be outside the public web root.');
            }
        }
    }

    /**
     * realpath() of the path or of its nearest existing parent, plus the rest.
     */
    private static function resolve(string $path): string
    {
        $suffix = '';
        $current = rtrim($path, '/');
        while ($current !== '' && ($real = realpath($current)) === false) {
            $suffix = '/' . basename($current) . $suffix;
            $current = dirname($current) === $current ? '' : dirname($current);
        }

        return isset($real) && $real !== false ? rtrim($real, '/') . $suffix : $path;
    }

    /**
     * The configured APP_ROLE, or null when unset or invalid (reported).
     */
    private function role(Config $config): ?string
    {
        $role = $this->read($config, 'APP_ROLE');
        if ($role === null) {
            return null;
        }
        if (!in_array($role, self::ROLES, true)) {
            $this->error('APP_ROLE', 'must be "web" or "ops", or unset for a single combined container.');

            return null;
        }

        return $role;
    }

    private function read(Config $config, string $key): ?string
    {
        try {
            return $config->get($key);
        } catch (ConfigException) {
            $this->error($key, 'is configured through ' . $key . '_FILE, but that file is not readable.');

            return null;
        }
    }

    private function error(string $key, string $message): void
    {
        if (!in_array([$key, $message], $this->errors, true)) {
            $this->errors[] = [$key, $message];
        }
    }

    private function warning(string $key, string $message): void
    {
        if (!in_array([$key, $message], $this->warnings, true)) {
            $this->warnings[] = [$key, $message];
        }
    }
}
