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
        $this->connection->transactional(function () use ($tenantId, $productId, $userId, $roleCodes): void {
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_members (tenant_id, user_id, product_id)
                    VALUES (:tenantId, :userId, :productId)
                    ON CONFLICT (tenant_id, user_id, product_id) DO NOTHING
                    SQL,
                ['tenantId' => $tenantId, 'userId' => $userId, 'productId' => $productId],
            );

            $this->assignRoles($tenantId, $productId, $userId, $roleCodes);
        });
    }

    public function replaceRoles(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        // Delete-then-insert inside one transaction: a member must never be
        // observable with no roles part-way through a role change.
        $this->connection->transactional(function () use ($tenantId, $productId, $userId, $roleCodes): void {
            $this->connection->executeStatement(
                <<<'SQL'
                    DELETE FROM tenant_member_roles
                    WHERE tenant_id = :tenantId AND product_id = :productId AND user_id = :userId
                    SQL,
                ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
            );

            $this->assignRoles($tenantId, $productId, $userId, $roleCodes);
        });
    }

    public function removeMember(string $tenantId, string $productId, string $userId): void
    {
        // Role rows go with it through ON DELETE CASCADE.
        $this->connection->executeStatement(
            <<<'SQL'
                DELETE FROM tenant_members
                WHERE tenant_id = :tenantId AND product_id = :productId AND user_id = :userId
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'userId' => $userId],
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
    private function assignRoles(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        foreach ($roleCodes as $roleCode) {
            // Selecting the id from roles means an unknown code inserts
            // nothing rather than inventing a role; callers validate first.
            $this->connection->executeStatement(
                <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, user_id, product_id, role_id)
                    SELECT :tenantId, :userId, :productId, r.id
                    FROM roles r
                    WHERE r.code = :roleCode
                    ON CONFLICT DO NOTHING
                    SQL,
                [
                    'tenantId' => $tenantId,
                    'userId' => $userId,
                    'productId' => $productId,
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
