<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Product\Domain\Product;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use App\Staff\Domain\TenantProducts;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class PostgresTenantProducts implements TenantProducts
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(string $tenantId): array
    {
        if (!Uuid::isValid($tenantId)) {
            return [];
        }

        return $this->ofMany([$tenantId])[$tenantId] ?? [];
    }

    public function ofMany(array $tenantIds): array
    {
        $valid = array_values(array_filter($tenantIds, Uuid::isValid(...)));

        if ($valid === []) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tp.tenant_id, p.id, p.code, p.name, p.active
                  FROM tenant_products tp
                  JOIN products p ON p.id = tp.product_id
                 WHERE tp.tenant_id IN (:tenants)
                 ORDER BY tp.tenant_id, p.code
                SQL,
            ['tenants' => $valid],
            ['tenants' => ArrayParameterType::STRING],
        );

        $byTenant = [];

        foreach ($rows as $row) {
            $byTenant[Row::string($row, 'tenant_id')][] = self::toProduct($row);
        }

        return $byTenant;
    }

    public function assign(string $tenantId, string $productId, string $staffUserId): ?Product
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return null;
        }

        return $this->connection->transactional(function () use ($tenantId, $productId, $staffUserId): ?Product {
            $product = $this->productOf($tenantId, $productId);

            if ($product === null) {
                return null;
            }

            if (!$product->active) {
                throw new ConflictException(
                    'PRODUCT_INACTIVE',
                    'A retired product cannot be assigned.',
                    ['product' => $product->code],
                );
            }

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_products (tenant_id, product_id, assigned_by)
                    VALUES (:tenant, :product, :staff)
                    ON CONFLICT (tenant_id, product_id) DO NOTHING
                    SQL,
                ['tenant' => $tenantId, 'product' => $productId, 'staff' => $staffUserId],
            );

            // Every current member becomes a member of the new product, with
            // the roles they hold in the organisation — DISTINCT across the
            // products they already have, because a role is held in the
            // tenant and the rows per product are its mirror (ADR-047).
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_members (tenant_id, user_id, product_id)
                    SELECT DISTINCT tenant_id, user_id, CAST(:product AS uuid)
                      FROM tenant_members
                     WHERE tenant_id = :tenant
                    ON CONFLICT DO NOTHING
                    SQL,
                ['tenant' => $tenantId, 'product' => $productId],
            );

            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                    SELECT DISTINCT tenant_id, user_id, CAST(:product AS uuid), role_id
                      FROM tenant_member_roles
                     WHERE tenant_id = :tenant
                    ON CONFLICT DO NOTHING
                    SQL,
                ['tenant' => $tenantId, 'product' => $productId],
            );

            return $product;
        });
    }

    public function unassign(string $tenantId, string $productId): ?Product
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return null;
        }

        return $this->connection->transactional(function () use ($tenantId, $productId): ?Product {
            $product = $this->productOf($tenantId, $productId);

            if ($product === null) {
                return null;
            }

            // Owed service, not merely "active": a cancelled subscription is
            // still paid for until its period ends (§13.1), and a product
            // withdrawn before then is a customer cut off from what they
            // settled. CUSTOM periods carry no end date and count as owed
            // until they are no longer active.
            $owed = (bool) $this->connection->fetchOne(
                <<<'SQL'
                    SELECT EXISTS (
                        SELECT 1
                          FROM subscriptions
                         WHERE tenant_id = :tenant
                           AND product_id = :product
                           AND (status = 'ACTIVE' OR current_period_end > now())
                    )
                    SQL,
                ['tenant' => $tenantId, 'product' => $productId],
            );

            if ($owed) {
                throw new ConflictException(
                    'PRODUCT_IN_USE',
                    'A subscription on this product is still owed service.',
                    ['product' => $product->code],
                );
            }

            // Memberships in the product, and their roles, go by cascade.
            $this->connection->executeStatement(
                'DELETE FROM tenant_products WHERE tenant_id = :tenant AND product_id = :product',
                ['tenant' => $tenantId, 'product' => $productId],
            );

            return $product;
        });
    }

    /**
     * The product, if both it and the tenant exist. One query answers both
     * absences the same way, which is the only way a 404 should.
     */
    private function productOf(string $tenantId, string $productId): ?Product
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.id, p.code, p.name, p.active
                  FROM products p
                 WHERE p.id = :product
                   AND EXISTS (SELECT 1 FROM tenants t WHERE t.id = :tenant)
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : self::toProduct($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toProduct(array $row): Product
    {
        return new Product(
            Row::string($row, 'id'),
            Row::string($row, 'code'),
            Row::string($row, 'name'),
            Row::boolean($row, 'active'),
        );
    }
}
