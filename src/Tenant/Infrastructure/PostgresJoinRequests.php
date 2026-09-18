<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Tenant\Domain\JoinRequests;
use App\Tenant\Domain\TenantMember;
use Doctrine\DBAL\Connection;

final class PostgresJoinRequests implements JoinRequests
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function pendingFor(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT t.id, t.slug, t.name
                  FROM tenant_members tm
                  JOIN tenants t ON t.id = tm.tenant_id
                 WHERE tm.user_id = :user AND tm.status = 'PENDING'
                 ORDER BY t.name
                SQL,
            ['user' => $userId],
        );

        return array_map(static fn (array $row): array => [
            'tenant_id' => Row::string($row, 'id'),
            'slug' => Row::string($row, 'slug'),
            'name' => Row::string($row, 'name'),
        ], $rows);
    }

    public function memberOf(string $userId): array
    {
        if (!Uuid::isValid($userId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT t.id, t.slug, t.name
                  FROM tenant_members tm
                  JOIN tenants t ON t.id = tm.tenant_id
                 WHERE tm.user_id = :user AND tm.status = 'ACTIVE'
                 ORDER BY t.name
                SQL,
            ['user' => $userId],
        );

        return array_map(static fn (array $row): array => [
            'tenant_id' => Row::string($row, 'id'),
            'slug' => Row::string($row, 'slug'),
            'name' => Row::string($row, 'name'),
        ], $rows);
    }

    public function pendingIn(string $tenantId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id, u.email, u.display_name, min(tm.requested_at) AS requested_at
                  FROM tenant_members tm
                  JOIN users u ON u.id = tm.user_id
                 WHERE tm.tenant_id = :tenant AND tm.status = 'PENDING'
                 GROUP BY u.id, u.email, u.display_name
                 ORDER BY min(tm.requested_at), u.id
                SQL,
            ['tenant' => $tenantId],
        );

        $people = [];

        foreach ($rows as $row) {
            $email = $row['email'] ?? null;
            $name = $row['display_name'] ?? null;
            $people[] = new TenantMember(
                Row::string($row, 'id'),
                is_string($email) ? $email : null,
                is_string($name) ? $name : null,
                ['USER'],
                'PENDING',
            );
        }

        return $people;
    }

    public function accept(string $tenantId, string $userId): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        return $this->connection->executeStatement(
            "UPDATE tenant_members SET status = 'ACTIVE' WHERE tenant_id = :tenant AND user_id = :user AND status = 'PENDING'",
            ['tenant' => $tenantId, 'user' => $userId],
        ) > 0;
    }

    public function decline(string $tenantId, string $userId): bool
    {
        if (!Uuid::isValid($userId)) {
            return false;
        }

        // Roles cascade from the membership rows.
        return $this->connection->executeStatement(
            "DELETE FROM tenant_members WHERE tenant_id = :tenant AND user_id = :user AND status = 'PENDING'",
            ['tenant' => $tenantId, 'user' => $userId],
        ) > 0;
    }

    public function policyOf(string $tenantId): array
    {
        $policy = $this->connection->fetchOne('SELECT join_policy FROM tenants WHERE id = :id', ['id' => $tenantId]);
        $domains = $this->connection->fetchFirstColumn(
            'SELECT domain FROM tenant_join_domains WHERE tenant_id = :id ORDER BY domain',
            ['id' => $tenantId],
        );

        return [
            'policy' => is_string($policy) ? $policy : 'APPROVAL',
            'domains' => array_values(array_filter($domains, 'is_string')),
        ];
    }

    public function setPolicy(string $tenantId, string $policy, array $domains): void
    {
        $this->connection->transactional(function () use ($tenantId, $policy, $domains): void {
            $this->connection->executeStatement(
                'UPDATE tenants SET join_policy = :policy, updated_at = now() WHERE id = :id',
                ['policy' => $policy, 'id' => $tenantId],
            );
            $this->connection->executeStatement('DELETE FROM tenant_join_domains WHERE tenant_id = :id', ['id' => $tenantId]);

            foreach (array_unique($domains) as $domain) {
                $this->connection->executeStatement(
                    'INSERT INTO tenant_join_domains (tenant_id, domain) VALUES (:id, :domain)',
                    ['id' => $tenantId, 'domain' => $domain],
                );
            }
        });
    }

    public function administratorsOf(string $tenantId): array
    {
        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT DISTINCT tmr.user_id
                  FROM tenant_member_roles tmr
                  JOIN roles r ON r.id = tmr.role_id
                  JOIN tenant_members tm
                    ON tm.tenant_id = tmr.tenant_id AND tm.user_id = tmr.user_id AND tm.product_id = tmr.product_id
                 WHERE tmr.tenant_id = :tenant AND r.code = 'TENANT_ADMIN' AND tm.status = 'ACTIVE'
                SQL,
            ['tenant' => $tenantId],
        );

        return array_values(array_filter($ids, 'is_string'));
    }
}
