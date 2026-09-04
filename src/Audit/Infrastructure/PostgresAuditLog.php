<?php

declare(strict_types=1);

namespace App\Audit\Infrastructure;

use App\Audit\Domain\AuditEntry;
use App\Audit\Domain\AuditLog;
use App\Audit\Domain\AuditReader;
use App\Audit\Domain\AuditRecord;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

/**
 * The trail, in the one table that refuses to forget what happened.
 *
 * Writing opens no transaction of its own. Where a caller holds one the row
 * joins it, so the act and the record commit together; where there is none —
 * a plain read being recorded — the insert stands alone, which is correct
 * because there is nothing to be atomic with. Same shape as
 * `PostgresStaffAccessLog`.
 *
 * A failure here propagates rather than being swallowed. An act nobody can
 * be held to account for is the thing §30 exists to prevent, so refusing the
 * act is the safer of the two failures.
 */
final class PostgresAuditLog implements AuditLog, AuditReader
{
    private const COLUMNS = 'id, occurred_at, action, subject_type, subject_id,'
        . ' tenant_id, product_id, user_id, project_id, request_id, detail, actor_forgotten_at';

    /**
     * Both filters are optional and are written the same way: a NULL
     * parameter matches everything rather than nothing. Casting inside the
     * comparison keeps one statement instead of four built by string
     * concatenation, which is where an injection would eventually arrive.
     */
    private const NARROWED = <<<'SQL'
        (CAST(:tenantId AS UUID) IS NULL OR tenant_id = CAST(:tenantId AS UUID))
          AND (CAST(:requestId AS TEXT) IS NULL OR request_id = CAST(:requestId AS TEXT))
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function applyRecord(AuditRecord $record): void
    {
        $detail = json_encode($record->detail);

        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO audit_log
                    (action, subject_type, subject_id, tenant_id, product_id,
                     user_id, project_id, request_id, detail)
                VALUES (:action, :subjectType, :subjectId, :tenantId, :productId,
                        :userId, :projectId, :requestId, CAST(:detail AS jsonb))
                SQL,
            [
                'action' => $record->action,
                'subjectType' => $record->subjectType,
                'subjectId' => $record->subjectId,
                'tenantId' => $record->tenantId,
                'productId' => $record->productId,
                'userId' => $record->userId,
                'projectId' => $record->projectId,
                'requestId' => $record->requestId,
                // An unencodable detail becomes an empty object rather than
                // failing the act it describes. The event is the thing worth
                // keeping; its annotation is not worth losing it over.
                'detail' => $detail === false ? '{}' : $detail,
            ],
        );
    }

    public function recent(?string $tenantId, ?string $requestId, int $limit, int $offset): array
    {
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            // Not an error: an id of the wrong shape matches nothing, and
            // answering "nothing" is the same answer the database would give
            // without the round trip — or the cast error it would raise.
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM audit_log WHERE ' . self::NARROWED
            . ' ORDER BY occurred_at DESC, id DESC LIMIT :limit OFFSET :offset',
            [
                'tenantId' => $tenantId,
                'requestId' => $requestId,
                'limit' => $limit,
                'offset' => $offset,
            ],
        );

        return array_map(self::toEntry(...), $rows);
    }

    public function count(?string $tenantId, ?string $requestId): int
    {
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            return 0;
        }

        $total = $this->connection->fetchOne(
            'SELECT count(*) FROM audit_log WHERE ' . self::NARROWED,
            ['tenantId' => $tenantId, 'requestId' => $requestId],
        );

        // count() comes back an int and sum() a string; Row::integer takes
        // either, which is the lesson PR #17 paid for.
        return Row::integer(['total' => $total], 'total');
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toEntry(array $row): AuditEntry
    {
        $raw = $row['detail'] ?? null;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        // json_decode gives array<mixed> and is_array() does not narrow the
        // key type, so the annotation is what satisfies the constructor.
        /** @var array<string, mixed> $detail */
        $detail = is_array($decoded) ? $decoded : [];

        return new AuditEntry(
            Row::string($row, 'id'),
            Row::timestamp($row, 'occurred_at'),
            Row::string($row, 'action'),
            Row::string($row, 'subject_type'),
            Row::nullableString($row, 'subject_id'),
            Row::nullableString($row, 'tenant_id'),
            Row::nullableString($row, 'product_id'),
            Row::nullableString($row, 'user_id'),
            Row::nullableString($row, 'project_id'),
            Row::nullableString($row, 'request_id'),
            $detail,
            Row::nullableTimestamp($row, 'actor_forgotten_at'),
        );
    }
}
