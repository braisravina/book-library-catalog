<?php

declare(strict_types=1);

namespace DigitalLibrary\Audit;

use DigitalLibrary\Http\Request;

/**
 * Audit-log viewer filters. All dates are UTC calendar days (the log stores
 * UTC_TIMESTAMP()); "to" is inclusive. Every search is bounded to a date
 * range of at most MAX_DAYS, defaulting to the last DEFAULT_DAYS, so each
 * query can use the created_at / (action, created_at) / (user_id,
 * created_at) indexes instead of scanning the whole table.
 */
final class AuditQuery
{
    public const DEFAULT_DAYS = 30;
    public const MAX_DAYS = 366;
    public const MIN_SEARCH = 3;
    public const MAX_SEARCH = 100;

    /**
     * @param list<string> $errors translation keys of rejected filters (ignored filters)
     */
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly ?string $action,
        public readonly ?int $userId,
        public readonly ?string $entityType,
        public readonly ?int $entityId,
        public readonly ?string $search,
        public readonly ?int $before,
        public readonly array $errors = [],
    ) {
    }

    public static function fromRequest(Request $request, string $today): self
    {
        $errors = [];
        $q = static fn (string $k): string => trim((string) $request->query($k, ''));
        $date = static function (string $value): ?string {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));

            return $d !== false && $d->format('Y-m-d') === $value ? $value : null;
        };
        $defaultFrom = gmdate('Y-m-d', strtotime($today . ' -' . (self::DEFAULT_DAYS - 1) . ' days'));

        $to = $q('to') === '' ? $today : $date($q('to'));
        if ($to === null) {
            $errors[] = 'audit.filter.invalid_date';
            $to = $today;
        }
        $from = $q('from') === '' ? null : $date($q('from'));
        if ($q('from') !== '' && $from === null) {
            $errors[] = 'audit.filter.invalid_date';
        }
        $from ??= min($defaultFrom, $to);
        if ($from > $to) {
            $errors[] = 'audit.filter.range_order';
            [$from, $to] = [$to, $from];
        }
        $span = (int) ((strtotime($to) - strtotime($from)) / 86400) + 1;
        if ($span > self::MAX_DAYS) {
            $errors[] = 'audit.filter.range_too_long';
            $from = gmdate('Y-m-d', strtotime($to . ' -' . (self::MAX_DAYS - 1) . ' days'));
        }

        $action = $q('action');
        $action = preg_match('/^[a-z][a-z0-9_.]{0,63}$/', $action) === 1 ? $action : null;
        $entityType = $q('entity_type');
        $entityType = preg_match('/^[a-z][a-z0-9_]{0,63}$/', $entityType) === 1 ? $entityType : null;
        $id = static fn (string $v): ?int => preg_match('/^[1-9]\d{0,17}$/', $v) === 1 ? (int) $v : null;

        $search = $q('q');
        if ($search !== '' && (mb_strlen($search) < self::MIN_SEARCH || mb_strlen($search) > self::MAX_SEARCH)) {
            $errors[] = 'audit.filter.search_length';
            $search = '';
        }

        return new self(
            $from,
            $to,
            $action,
            $id($q('user')),
            $entityType,
            $entityType === null ? null : $id($q('entity_id')),
            $search === '' ? null : $search,
            $id($q('before')),
            array_values(array_unique($errors)),
        );
    }

    /**
     * Filter values for building links (pagination keeps the filters).
     *
     * @return array<string, string|int|null>
     */
    public function query(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'action' => $this->action,
            'user' => $this->userId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'q' => $this->search,
        ];
    }
}
