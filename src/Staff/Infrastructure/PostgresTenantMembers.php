<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\JsonArray;
use App\Shared\Database\Uuid;
use App\Staff\Domain\TenantMemberAcrossProducts;
use App\Staff\Domain\TenantMembers;
use Doctrine\DBAL\Connection;

final class PostgresTenantMembers implements TenantMembers
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function of(string $tenantId, ?string $productId): array
    {
        if (!Uuid::isValid($tenantId) || ($productId !== null && !Uuid::isValid($productId))) {
            return [];
        }

        // One row per person. Roles are the same on every product the
        // membership is mirrored onto (ADR-047), so DISTINCT collapses them;
        // the products are what differ, and are listed. When a product is
        // asked for, only people on it are listed and only that product is
        // named — the console is looking at one product, not at the tenant.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT m.user_id,
                       u.email,
                       u.display_name,
                       array_to_json(array_agg(DISTINCT r.code) FILTER (WHERE r.code IS NOT NULL)) AS roles,
                       array_to_json(array_agg(DISTINCT p.code ORDER BY p.code)) AS products
                  FROM tenant_members m
                  JOIN users u ON u.id = m.user_id
                  JOIN products p ON p.id = m.product_id
                  LEFT JOIN tenant_member_roles mr
                         ON mr.tenant_id = m.tenant_id AND mr.product_id = m.product_id AND mr.user_id = m.user_id
                  LEFT JOIN roles r ON r.id = mr.role_id
                 WHERE m.tenant_id = :tenant
                   AND (CAST(:product AS uuid) IS NULL OR m.product_id = CAST(:product AS uuid))
                 GROUP BY m.user_id, u.email, u.display_name
                 ORDER BY u.display_name NULLS LAST, u.email NULLS LAST, m.user_id
                SQL,
            ['tenant' => $tenantId, 'product' => $productId],
        );

        $members = [];

        foreach ($rows as $row) {
            $members[] = new TenantMemberAcrossProducts(
                is_string($row['user_id']) ? $row['user_id'] : '',
                is_string($row['email'] ?? null) ? $row['email'] : null,
                is_string($row['display_name'] ?? null) ? $row['display_name'] : null,
                JsonArray::ofStrings($row['roles'] ?? null),
                JsonArray::ofStrings($row['products'] ?? null),
            );
        }

        return $members;
    }
}
