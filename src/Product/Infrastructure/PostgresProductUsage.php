<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Entitlement\Domain\ReportedUsage;
use App\Product\Domain\ProductUsageLedger;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;

/**
 * The ledger of what products reported (ADR-051 §4), and the meter's view
 * of it: the sum of the deltas is the level.
 */
final class PostgresProductUsage implements ProductUsageLedger, ReportedUsage
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        string $tenantId,
        string $productId,
        ?string $credentialId,
        string $feature,
        int $quantity,
        string $idempotencyKey,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
    ): bool {
        // ON CONFLICT rather than catching the violation: this runs inside
        // `exclusively()`'s transaction, and a statement that fails aborts
        // the transaction it is in.
        $written = $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_usage (tenant_id, product_id, credential_id, feature, quantity, period_start, period_end, idempotency_key)
                VALUES (:tenant, :product, :credential, :feature, :quantity, :periodStart, :periodEnd, :key)
                ON CONFLICT ON CONSTRAINT product_usage_once DO NOTHING
                SQL,
            [
                'tenant' => $tenantId,
                'product' => $productId,
                'credential' => $credentialId,
                'feature' => $feature,
                'quantity' => $quantity,
                'periodStart' => $periodStart?->format('Y-m-d'),
                'periodEnd' => $periodEnd?->format('Y-m-d'),
                'key' => $idempotencyKey,
            ],
        );

        // Nothing written: the same fact, again — a retry after a timeout.
        // The first row stands and this one is not a second count.
        return $written > 0;
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function exclusively(string $tenantId, string $productId, string $feature, callable $work): mixed
    {
        return $this->connection->transactional(function () use ($tenantId, $productId, $feature, $work) {
            // Per (tenant, product, feature), released with the transaction.
            // Two reports with different keys used to both read the level
            // below the limit and both write, crossing it together.
            $this->connection->executeStatement(
                "SELECT pg_advisory_xact_lock(hashtext('product_usage:' || CAST(:tenant AS text) || ':' || CAST(:product AS text) || ':' || CAST(:feature AS text)))",
                ['tenant' => $tenantId, 'product' => $productId, 'feature' => $feature],
            );

            return $work();
        });
    }

    public function isRecorded(string $tenantId, string $productId, string $feature, string $idempotencyKey): bool
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return false;
        }

        return (bool) $this->connection->fetchOne(
            <<<'SQL'
                SELECT EXISTS (
                    SELECT 1 FROM product_usage
                     WHERE tenant_id = :tenant AND product_id = :product
                       AND feature = :feature AND idempotency_key = :key
                )
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'feature' => $feature, 'key' => $idempotencyKey],
        );
    }

    public function measures(string $featureCode): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM product_usage WHERE feature = :feature)',
            ['feature' => $featureCode],
        );
    }

    public function usage(string $featureCode, string $tenantId, string $productId): ?int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return null;
        }

        $level = $this->connection->fetchOne(
            <<<'SQL'
                SELECT SUM(quantity)
                  FROM product_usage
                 WHERE tenant_id = :tenant AND product_id = :product AND feature = :feature
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'feature' => $featureCode],
        );

        // SUM over no rows is NULL: nothing was reported, which is not zero.
        return is_numeric($level) ? max(0, (int) $level) : null;
    }
}
