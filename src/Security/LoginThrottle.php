<?php

declare(strict_types=1);

namespace DigitalLibrary\Security;

use PDO;

/**
 * Database-backed login rate limiter. Failures are counted per account
 * identifier (a hash of the e-mail, so failed addresses are not stored in
 * clear) and per client IP within a sliding window.
 */
final class LoginThrottle
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxPerAccount = 5,
        private readonly int $maxPerIp = 20,
        private readonly int $windowSeconds = 900,
    ) {
    }

    public static function identifier(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    public function isLocked(string $email, string $ip, ?int $now = null): bool
    {
        return $this->retryAfter($email, $ip, $now) > 0;
    }

    /**
     * Seconds until another attempt is allowed; 0 when not throttled.
     */
    public function retryAfter(string $email, string $ip, ?int $now = null): int
    {
        $now ??= time();
        $since = gmdate('Y-m-d H:i:s', $now - $this->windowSeconds);

        $retry = 0;
        foreach ([['identifier_hash', self::identifier($email), $this->maxPerAccount], ['ip_address', $ip, $this->maxPerIp]] as [$column, $value, $limit]) {
            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS c, MIN(attempted_at) AS oldest FROM login_attempts
                 WHERE {$column} = :value AND succeeded = 0 AND attempted_at > :since"
            );
            $stmt->execute(['value' => $value, 'since' => $since]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ((int) $row['c'] >= $limit) {
                $oldest = strtotime($row['oldest'] . ' UTC');
                $retry = max($retry, $oldest + $this->windowSeconds - $now, 1);
            }
        }

        return $retry;
    }

    public function recordFailure(string $email, string $ip, ?int $now = null): void
    {
        $this->record($email, $ip, false, $now);
    }

    /**
     * A successful login clears the account's failure history.
     */
    public function recordSuccess(string $email, string $ip, ?int $now = null): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = :id AND succeeded = 0');
        $stmt->execute(['id' => self::identifier($email)]);
        $this->record($email, $ip, true, $now);
    }

    public function prune(int $olderThanSeconds = 86400 * 30, ?int $now = null): int
    {
        $now ??= time();
        $stmt = $this->pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < :before');
        $stmt->execute(['before' => gmdate('Y-m-d H:i:s', $now - $olderThanSeconds)]);

        return $stmt->rowCount();
    }

    private function record(string $email, string $ip, bool $succeeded, ?int $now): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier_hash, ip_address, succeeded, attempted_at) VALUES (:id, :ip, :ok, :at)'
        );
        $stmt->execute([
            'id' => self::identifier($email),
            'ip' => mb_substr($ip, 0, 45),
            'ok' => $succeeded ? 1 : 0,
            'at' => gmdate('Y-m-d H:i:s', $now ?? time()),
        ]);
    }
}
