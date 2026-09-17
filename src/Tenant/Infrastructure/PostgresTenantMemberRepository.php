<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Shared\Database\JsonArray;
use App\Tenant\Domain\TenantMember;
use App\Tenant\Domain\TenantMemberRepository;
use Doctrine\DBAL\Connection;

final class PostgresTenantMemberRepository implements TenantMemberRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function listMembers(string $tenantId, string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id, u.email, u.display_name,
                       array_to_json(array_agg(DISTINCT r.code) FILTER (WHERE r.code IS NOT NULL)) AS roles
                FROM tenant_members tm
                JOIN users u ON u.id = tm.user_id
                LEFT JOIN tenant_member_roles tmr
                       ON tmr.tenant_id = tm.tenant_id
                      AND tmr.user_id = tm.user_id
                      AND tmr.product_id = tm.product_id
                LEFT JOIN roles r ON r.id = tmr.role_id
                WHERE tm.tenant_id = :tenantId
                  AND tm.product_id = :productId
                  AND tm.status = 'ACTIVE'
                GROUP BY u.id, u.email, u.display_name
                ORDER BY u.email NULLS LAST, u.id
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        $members = [];

        foreach ($rows as $row) {
            $member = $this->toMember($row);

            if ($member !== null) {
                $members[] = $member;
            }
        }

        return $members;
    }

    public function findMember(string $tenantId, string $productId, string $userId): ?TenantMember
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT u.id, u.email, u.display_name,
                       array_to_json(array_agg(DISTINCT r.code) FILTER (WHERE r.code IS NOT NULL)) AS roles
                FROM tenant_members tm
                JOIN users u ON u.id = tm.user_id
                LEFT JOIN tenant_member_roles tmr
                       ON tmr.tenant_id = tm.tenant_id
                      AND tmr.user_id = tm.user_id
                      AND tmr.product_id = tm.product_id
                LEFT JOIN roles r ON r.id = tmr.role_id
                WHERE tm.tenant_id = :tenantId
                  AND tm.product_id = :productId
                  AND tm.user_id = :userId
                GROUP BY u.id, u.email, u.display_name
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
        );

        return $row === false ? null : $this->toMember($row);
    }

    public function addMember(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        // One row per product the platform assigned to the tenant, not one
        // for the product this request arrived in (ADR-047): a colleague
        // invited into the organisation is a member of the organisation, and
        // the product the administrator happened to be using when they typed
        // the address is not a decision about which products that colleague
        // may see. `$productId` names the context the caller resolved and is
        // one of those rows by construction.
        $this->connection->transactional(function () use ($tenantId, $userId, $roleCodes): void {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_members (tenant_id, user_id, product_id)
                    SELECT tp.tenant_id, :userId, tp.product_id
                    FROM tenant_products tp
                    WHERE tp.tenant_id = :tenantId
                    ON CONFLICT (tenant_id, user_id, product_id) DO NOTHING
                    SQL,
                ['tenantId' => $tenantId, 'userId' => $userId],
            );

            $this->assignRoles($tenantId, $userId, $roleCodes);
        });
    }

    public function replaceRoles(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        // Delete-then-insert inside one transaction: a member must never be
        // observable with no roles part-way through a role change.
        //
        // Across every product the tenant holds: a role is held in the
        // organisation, and a change made from one product that left the
        // other products' rows behind would give one person two answers.
        $this->connection->transactional(function () use ($tenantId, $userId, $roleCodes): void {
            $this->connection->executeStatement(
                <<<'SQL'
                    DELETE FROM tenant_member_roles
                    WHERE tenant_id = :tenantId AND user_id = :userId
                    SQL,
                ['tenantId' => $tenantId, 'userId' => $userId],
            );

            $this->assignRoles($tenantId, $userId, $roleCodes);
        });
    }

    public function removeMember(string $tenantId, string $productId, string $userId): void
    {
        // Every product's row, and the role rows go with them through
        // ON DELETE CASCADE. Removing somebody from the organisation in one
        // product while leaving them a member in another is not a thing an
        // administrator asked for.
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM tenant_members
                WHERE tenant_id = :tenantId AND user_id = :userId
                SQL,
            ['tenantId' => $tenantId, 'userId' => $userId],
        );
    }

    public function knownRoleCodes(): array
    {
        $codes = [];

        foreach ($this->connection->fetchFirstColumn('SELECT code FROM roles ORDER BY code') as $code) {
            if (is_string($code)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public function countMembersWithRole(string $tenantId, string $productId, string $roleCode): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(DISTINCT tmr.user_id)
                FROM tenant_member_roles tmr
                JOIN roles r ON r.id = tmr.role_id
                WHERE tmr.tenant_id = :tenantId
                  AND tmr.product_id = :productId
                  AND r.code = :roleCode
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'roleCode' => $roleCode],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    /**
     * @param list<string> $roleCodes
     */
    private function assignRoles(string $tenantId, string $userId, array $roleCodes): void
    {
        foreach ($roleCodes as $roleCode) {
            // Selecting the id from roles means an unknown code inserts
            // nothing rather than inventing a role; callers validate first.
            // One row per product the tenant holds, mirroring the membership.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                    SELECT tp.tenant_id, :userId, tp.product_id, r.id
                    FROM tenant_products tp
                    CROSS JOIN roles r
                    WHERE tp.tenant_id = :tenantId
                      AND r.code = :roleCode
                    ON CONFLICT DO NOTHING
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'userId' => $userId,
                    'roleCode' => $roleCode,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toMember(array $row): ?TenantMember
    {
        $id = $row['id'] ?? null;

        if (!is_string($id)) {
            return null;
        }

        $email = $row['email'] ?? null;
        $displayName = $row['display_name'] ?? null;

        return new TenantMember(
            $id,
            is_string($email) ? $email : null,
            is_string($displayName) ? $displayName : null,
            JsonArray::ofStrings($row['roles'] ?? null),
        );
    }
}
