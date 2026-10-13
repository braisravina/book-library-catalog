<?php

declare(strict_types=1);

namespace DigitalLibrary\Audit;

use DigitalLibrary\Catalogue\Repository\Repository;
use DigitalLibrary\Database\Params;

/**
 * Read-only access to the audit log for the administrative viewer. There
 * are deliberately no write methods: events are only ever added through
 * AuditLogger::log().
 */
final class AuditLogRepository extends Repository
{
    public const PER_PAGE = 50;
    /** Upper bound on statement time (MySQL optimizer hint), in milliseconds. */
    public const MAX_EXECUTION_MS = 5000;

    /**
     * One page, newest first, using keyset pagination on the primary key
     * (no OFFSET scans, no full COUNT(*)). One extra row is fetched to tell
     * whether an older page exists.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?int}
     */
    public function page(AuditQuery $query): array
    {
        $params = new Params();
        $where = [
            'a.created_at >= ' . $params->add($query->from . ' 00:00:00'),
            'a.created_at < ' . $params->add(gmdate('Y-m-d', strtotime($query->to . ' +1 day')) . ' 00:00:00'),
        ];
        if ($query->action !== null) {
            $where[] = 'a.action = ' . $params->add($query->action);
        }
        if ($query->userId !== null) {
            $where[] = 'a.user_id = ' . $params->add($query->userId);
        }
        if ($query->entityType !== null) {
            $where[] = 'a.entity_type = ' . $params->add($query->entityType);
            if ($query->entityId !== null) {
                $where[] = 'a.entity_id = ' . $params->add($query->entityId);
            }
        }
        if ($query->search !== null) {
            $like = Params::likeContains($query->search);
            $where[] = '(CAST(a.details AS CHAR) LIKE ' . $params->add($like)
                . ' OR a.ip_address LIKE ' . $params->add($like)
                . ' OR a.user_agent LIKE ' . $params->add($like) . ')';
        }
        if ($query->before !== null) {
            $where[] = 'a.id < ' . $params->add($query->before);
        }
        $limit = $params->add(self::PER_PAGE + 1);

        $stmt = $this->pdo->prepare(
            'SELECT /*+ MAX_EXECUTION_TIME(' . self::MAX_EXECUTION_MS . ') */
                    a.id, a.user_id, a.action, a.entity_type, a.entity_id, a.ip_address, a.created_at,
                    u.display_name AS user_name
             FROM audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.id DESC
             LIMIT ' . $limit
        );
        $params->bind($stmt);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $next = null;
        if (count($rows) > self::PER_PAGE) {
            array_pop($rows);
            $next = (int) $rows[count($rows) - 1]['id'];
        }

        return ['rows' => $rows, 'next' => $next];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->one(
            'SELECT a.*, u.display_name AS user_name, u.email AS user_email
             FROM audit_log a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.id = :id',
            ['id' => $id],
        );
    }

    /**
     * Distinct action names for the filter (uses the (action, created_at) index).
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return array_map('strval', $this->run('SELECT DISTINCT action FROM audit_log ORDER BY action LIMIT 500')->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * Distinct entity types (uses the (entity_type, entity_id) index).
     *
     * @return list<string>
     */
    public function entityTypes(): array
    {
        return array_map('strval', $this->run('SELECT DISTINCT entity_type FROM audit_log WHERE entity_type IS NOT NULL ORDER BY entity_type LIMIT 100')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public static function isTimeout(\PDOException $e): bool
    {
        return in_array($e->errorInfo[1] ?? null, [3024, 1317], true); // max_execution_time exceeded / query interrupted
    }
}
