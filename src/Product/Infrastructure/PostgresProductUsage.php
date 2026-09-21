<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Entitlement\Domain\ReportedUsage;
use App\Product\Domain\ProductUsageLedger;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

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
        try {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO product_usage (tenant_id, product_id, credential_id, feature, quantity, period_start, period_end, idempotency_key)
                    VALUES (:tenant, :product, :credential, :feature, :quantity, :periodStart, :periodEnd, :key)
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
        } catch (UniqueConstraintViolationException) {
            // The same fact, again: a retry after a timeout. The first row
            // stands and this one is not a second count.
            return false;
        }

        return true;
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
