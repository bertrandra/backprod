<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\GrantedEntitlement;
use App\Staff\Domain\GrantedFeature;
use App\Staff\Domain\TenantGrants;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\DBAL\Connection;

/**
 * Grants in PostgreSQL: `entitlements` rows with `source = 'GRANT'`.
 *
 * The same table the resolver reads on every request, which is the point —
 * a grant is an entitlement, not a note about one. Writing here and reading
 * in `PostgresEntitlementRepository` is the arrangement the subscription
 * rows already have.
 */
final class PostgresTenantGrants implements TenantGrants
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(string $tenantId, string $productId): ?GrantedEntitlement
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT f.code, f.name, f.kind, e.limit_value, e.valid_until, e.granted_by, e.created_at
                  FROM entitlements e
                  JOIN features f ON f.id = e.feature_id
                 WHERE e.tenant_id = :tenant AND e.product_id = :product AND e.source = 'GRANT'
                 ORDER BY f.code
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );

        if ($rows === []) {
            return null;
        }

        $first = $rows[0];

        return new GrantedEntitlement(
            $tenantId,
            $productId,
            array_map(static fn (array $row): GrantedFeature => new GrantedFeature(
                Row::string($row, 'code'),
                Row::string($row, 'name'),
                Row::string($row, 'kind'),
                Row::nullableInteger($row, 'limit_value'),
            ), $rows),
            Row::nullableTimestamp($first, 'valid_until'),
            Row::nullableString($first, 'granted_by'),
            Row::timestamp($first, 'created_at'),
        );
    }

    public function grant(
        string $tenantId,
        string $productId,
        array $limits,
        ?DateTimeImmutable $validUntil,
        string $staffUserId,
    ): GrantedEntitlement {
        // The platform's one list (2026-09-24), retired rows included. A
        // negotiated override is the exception path by construction — it is
        // how support restores something outside any offer — and refusing a
        // retired code here would leave a customer who already held one
        // unable to be given it back. What retirement stops is a *new offer*
        // granting it, which is enforced where an offer version is written.
        $features = $this->connection->fetchAllKeyValue('SELECT code, id FROM features');

        foreach (array_keys($limits) as $code) {
            if (!isset($features[$code])) {
                throw new NotFoundException(
                    'No feature has that code.',
                    ['feature' => $code],
                    'FEATURE_NOT_FOUND',
                );
            }
        }

        $this->connection->beginTransaction();

        try {
            $this->connection->executeStatement(
                "DELETE FROM entitlements WHERE tenant_id = :tenant AND product_id = :product AND source = 'GRANT'",
                ['tenant' => $tenantId, 'product' => $productId],
            );

            foreach ($limits as $code => $limit) {
                $this->connection->executeStatement(
                    <<<'SQL'
                        INSERT INTO entitlements
                            (tenant_id, product_id, feature_id, limit_value, source, subscription_id, valid_until, granted_by)
                        VALUES (:tenant, :product, :feature, :limit, 'GRANT', NULL, :until, :by)
                        SQL,
                    [
                        'tenant' => $tenantId,
                        'product' => $productId,
                        'feature' => $features[$code],
                        'limit' => $limit,
                        'until' => $validUntil?->format(DateTimeInterface::ATOM),
                        'by' => $staffUserId,
                    ],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $failure) {
            $this->connection->rollBack();

            throw $failure;
        }

        $granted = $this->of($tenantId, $productId);

        if ($granted === null) {
            // An empty feature list withdraws; the caller refused that
            // earlier, so this is the transaction failing to write, which
            // would have thrown above.
            throw new \LogicException('A grant was written and cannot be read back.');
        }

        return $granted;
    }

    public function withdraw(string $tenantId, string $productId): bool
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return false;
        }

        return $this->connection->executeStatement(
            "DELETE FROM entitlements WHERE tenant_id = :tenant AND product_id = :product AND source = 'GRANT'",
            ['tenant' => $tenantId, 'product' => $productId],
        ) > 0;
    }

    public function limitsOfPlan(string $productId, string $planCode): ?array
    {
        $version = $this->connection->fetchOne(
            <<<'SQL'
                SELECT v.id
                  FROM offer_versions v
                  JOIN offers o ON o.id = v.offer_id
                  JOIN plans p ON p.id = o.plan_id
                 WHERE p.product_id = :product AND p.code = :plan AND v.status = 'ACTIVE'
                 ORDER BY v.valid_from DESC, v.version DESC
                 LIMIT 1
                SQL,
            ['product' => $productId, 'plan' => $planCode],
        );

        if (!is_string($version)) {
            $known = $this->connection->fetchOne(
                'SELECT EXISTS (SELECT 1 FROM plans WHERE product_id = :product AND code = :plan)',
                ['product' => $productId, 'plan' => $planCode],
            );

            // A plan with no published version grants nothing yet: an empty
            // shorthand, not an unknown one.
            return (bool) $known ? [] : null;
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT f.code, g.limit_value
                  FROM offer_version_features g
                  JOIN features f ON f.id = g.feature_id
                 WHERE g.offer_version_id = :version
                SQL,
            ['version' => $version],
        );

        $limits = [];

        foreach ($rows as $row) {
            $limits[Row::string($row, 'code')] = Row::nullableInteger($row, 'limit_value');
        }

        return $limits;
    }
}
