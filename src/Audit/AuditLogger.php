<?php

declare(strict_types=1);

namespace DigitalLibrary\Audit;

use PDO;

/**
 * Append-only audit trail of security-relevant and catalogue-changing events.
 * Callers must never pass secrets (passwords, tokens) in $details.
 */
final class AuditLogger
{
    private const REDACT = ['password', 'password_hash', 'token', 'secret', '_csrf'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(
        string $action,
        ?int $userId,
        ?string $entityType = null,
        ?int $entityId = null,
        array $details = [],
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_log (user_id, action, entity_type, entity_id, details, ip_address, user_agent, created_at)
             VALUES (:user_id, :action, :entity_type, :entity_id, :details, :ip, :ua, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action' => mb_substr($action, 0, 64),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details === [] ? null : json_encode(self::redact($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $ip !== null ? mb_substr($ip, 0, 45) : null,
            'ua' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
        ]);
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array<string, mixed>
     */
    public static function redact(array $details): array
    {
        foreach ($details as $key => $value) {
            if (in_array(strtolower((string) $key), self::REDACT, true)) {
                $details[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $details[$key] = self::redact($value);
            }
        }

        return $details;
    }
}
