<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffAccessLog;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use stdClass;

/**
 * Writes the trail non-negotiable #21 requires.
 *
 * Deliberately **not** transactional on its own. When a staff act is a write,
 * the caller already holds a transaction and this row joins it, so the act
 * and the record of who performed it commit together or not at all — the same
 * participating pattern as `applyIssue`, `applyActivate` and
 * `applyCompleteOrder`.
 *
 * For a plain read there is no surrounding transaction and the insert stands
 * alone, which is correct: nothing to be atomic with.
 *
 * A failure here is deliberately allowed to propagate. Swallowing it would
 * produce exactly the silent access #21 forbids, and answering an error is
 * the safer failure: an operator who cannot be logged is an operator who does
 * not read.
 */
final class PostgresStaffAccessLog implements StaffAccessLog
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(StaffAccess $access): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO staff_access_log
                    (staff_user_id, tenant_id, product_id, action,
                     resource_type, resource_id, permission, detail, purpose, reason)
                VALUES (:staff, :tenant, :product, :action,
                        :resourceType, :resourceId, :permission, CAST(:detail AS jsonb),
                        :purpose, :reason)
                SQL,
            [
                'staff' => $access->staffUserId,
                'tenant' => $access->tenantId,
                'product' => $access->productId,
                'action' => $access->action,
                'resourceType' => $access->resourceType,
                'resourceId' => $access->resourceId,
                'permission' => $access->permission,
                'detail' => self::encode($access->detail),
                // Both or neither — the table's own CHECK says so, and a row
                // with a category and nothing in it would be worse than a row
                // with no category (R14).
                'purpose' => $access->motive?->purpose,
                'reason' => $access->motive?->reference,
            ],
        );
    }

    public function recent(?string $tenantId, int $limit, int $offset): array
    {
        // An unparseable tenant filter returns nothing rather than everything.
        // Falling back to the unfiltered trail because an id was malformed
        // would answer a broader question than the one asked.
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT id, staff_user_id, tenant_id, product_id, action,
                       resource_type, resource_id, permission, detail, occurred_at,
                       purpose, reason
                  FROM staff_access_log
                 WHERE (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                 ORDER BY occurred_at DESC, id
                 LIMIT :limit OFFSET :offset
                SQL,
            ['tenant' => $tenantId, 'limit' => $limit, 'offset' => $offset],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toEntry(...), $rows);
    }

    public function count(?string $tenantId): int
    {
        if ($tenantId !== null && !Uuid::isValid($tenantId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM staff_access_log
                 WHERE (CAST(:tenant AS UUID) IS NULL OR tenant_id = CAST(:tenant AS UUID))
                SQL,
            ['tenant' => $tenantId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toEntry(array $row): StaffAccessEntry
    {
        return new StaffAccessEntry(
            Row::string($row, 'id'),
            Row::string($row, 'staff_user_id'),
            Row::nullableString($row, 'tenant_id'),
            Row::nullableString($row, 'product_id'),
            Row::string($row, 'action'),
            Row::string($row, 'resource_type'),
            Row::nullableString($row, 'resource_id'),
            Row::string($row, 'permission'),
            self::decode($row, 'detail'),
            Row::timestamp($row, 'occurred_at'),
            Row::nullableString($row, 'purpose'),
            Row::nullableString($row, 'reason'),
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function decode(array $row, string $column): array
    {
        $decoded = json_decode(Row::string($row, $column), true);

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function encode(array $value): string
    {
        $encoded = json_encode($value === [] ? new stdClass() : $value);

        return $encoded === false ? '{}' : $encoded;
    }
}
