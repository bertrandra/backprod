<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Shared\Database\JsonArray;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantMembershipRepository implements TenantMembershipRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findForUserAndProduct(string $userId, string $productId): array
    {
        // Roles and the permissions they grant are resolved together: the
        // context chain needs both, and asking twice would let them disagree
        // within one request.
        //
        // LEFT JOINs throughout so a member with no roles still appears —
        // they are a member with no privileges, not a non-member.
        //
        // Aggregates are converted to JSON in the database: DBAL returns an
        // array column as the raw `{a,b}` literal, which is ambiguous as soon
        // as a value contains a comma.
        //
        // Ordered by tenant_id so the list in a TENANT_SELECTION_REQUIRED
        // response is stable between requests.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT tm.tenant_id,
                       tm.user_id,
                       tm.product_id,
                       array_to_json(array_agg(DISTINCT r.code) FILTER (WHERE r.code IS NOT NULL)) AS roles,
                       array_to_json(array_agg(DISTINCT p.code) FILTER (WHERE p.code IS NOT NULL)) AS permissions
                FROM tenant_members tm
                LEFT JOIN tenant_member_roles tmr
                       ON tmr.tenant_id = tm.tenant_id
                      AND tmr.user_id = tm.user_id
                      AND tmr.product_id = tm.product_id
                LEFT JOIN roles r ON r.id = tmr.role_id
                LEFT JOIN role_permissions rp ON rp.role_id = r.id
                LEFT JOIN permissions p ON p.id = rp.permission_id
                WHERE tm.user_id = :userId
                  AND tm.product_id = :productId
                GROUP BY tm.tenant_id, tm.user_id, tm.product_id
                ORDER BY tm.tenant_id
                SQL,
            [
                'userId' => $userId,
                'productId' => $productId,
            ],
        );

        $memberships = [];

        foreach ($rows as $row) {
            $tenantId = $row['tenant_id'] ?? null;
            $member = $row['user_id'] ?? null;
            $product = $row['product_id'] ?? null;

            if (!is_string($tenantId) || !is_string($member) || !is_string($product)) {
                continue;
            }

            $memberships[] = new TenantMembership(
                $tenantId,
                $member,
                $product,
                JsonArray::ofStrings($row['roles'] ?? null),
                JsonArray::ofStrings($row['permissions'] ?? null),
            );
        }

        return $memberships;
    }
}
