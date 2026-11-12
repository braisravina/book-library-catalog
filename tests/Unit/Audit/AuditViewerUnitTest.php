<?php

declare(strict_types=1);

namespace DigitalLibrary\Tests\Unit\Audit;

use DigitalLibrary\Audit\AuditDetails;
use DigitalLibrary\Audit\AuditQuery;
use DigitalLibrary\Http\Request;
use PHPUnit\Framework\TestCase;

final class AuditViewerUnitTest extends TestCase
{
    private const TODAY = '2026-10-13';

    public function testDefaultsToTheLastThirtyUtcDays(): void
    {
        $q = $this->query([]);
        self::assertSame(['2026-09-14', '2026-10-13', []], [$q->from, $q->to, $q->errors]);
        self::assertNull($q->action);
        self::assertNull($q->search);
    }

    public function testRangesAreValidatedOrderedAndBounded(): void
    {
        $q = $this->query(['from' => '2026-10-10', 'to' => '2026-10-01']);
        self::assertSame(['2026-10-01', '2026-10-10', ['audit.filter.range_order']], [$q->from, $q->to, $q->errors]);

        $q = $this->query(['from' => '2020-01-01', 'to' => '2026-10-13']);
        self::assertSame('2025-10-13', $q->from, 'At most 366 days, inclusive');
        self::assertSame(['audit.filter.range_too_long'], $q->errors);

        $q = $this->query(['from' => '2026-02-30', 'to' => 'yesterday']);
        self::assertSame(['audit.filter.invalid_date'], $q->errors);
        self::assertSame(self::TODAY, $q->to);

        $q = $this->query(['from' => '2026-10-13', 'to' => '2026-10-13']);
        self::assertSame(['2026-10-13', '2026-10-13', []], [$q->from, $q->to, $q->errors], 'A single day is allowed');
    }

    public function testMalformedFiltersAreDropped(): void
    {
        $q = $this->query(['action' => "x' OR 1=1", 'user' => '-1', 'entity_type' => 'Work!', 'entity_id' => '5', 'before' => '0', 'q' => 'ab']);
        self::assertSame([null, null, null, null, null, null], [$q->action, $q->userId, $q->entityType, $q->entityId, $q->before, $q->search]);
        self::assertSame(['audit.filter.search_length'], $q->errors);

        $q = $this->query(['action' => 'loan.created', 'user' => '7', 'entity_type' => 'loan', 'entity_id' => '12', 'before' => '900', 'q' => ' 50% off ']);
        self::assertSame(['loan.created', 7, 'loan', 12, 900, '50% off'], [$q->action, $q->userId, $q->entityType, $q->entityId, $q->before, $q->search]);
        self::assertArrayNotHasKey('before', $q->query(), 'Pagination links set their own cursor');
    }

    public function testDetailsAreRedactedAndPrettyPrinted(): void
    {
        $json = json_encode(['user_password' => 'x', 'nested' => ['API-Key' => 'y', 'csrf_token' => 'z', 'list' => [['client_secret' => 's']]], 'title' => '<b>"T"</b>', 'n' => 1]);
        $out = (string) AuditDetails::format($json);
        foreach (['"x"', '"y"', '"z"', '"s"'] as $secret) {
            self::assertStringNotContainsString($secret, $out);
        }
        self::assertStringContainsString('"title": "<b>\"T\"</b>"', $out, 'Formatting does not escape HTML; templates do');
        self::assertStringContainsString("\n", $out);
        self::assertNull(AuditDetails::format(null));
        self::assertSame('[unreadable]', AuditDetails::format('{broken'));
        self::assertSame('"plain"', AuditDetails::format('"plain"'));
    }

    /**
     * @param array<string, string> $query
     */
    private function query(array $query): AuditQuery
    {
        return AuditQuery::fromRequest(new Request('GET', '/admin/audit', $query), self::TODAY);
    }
}
