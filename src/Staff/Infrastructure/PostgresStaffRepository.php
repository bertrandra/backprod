<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\JsonArray;
use App\Shared\Database\Uuid;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffRepository;
use Doctrine\DBAL\Connection;

/**
 * Platform roles in PostgreSQL.
 *
 * The query touches `platform_roles`, `platform_role_permissions` and
 * `platform_permissions` and nothing else. It cannot reach `roles`,
 * `tenant_members` or `permissions`, which is the separation of §12.2 showing
 * up as the shape of a single SQL statement: there is no join here that could
 * turn a tenant membership into platform authority, because the tables are
 * not in the query.
 */
final class PostgresStaffRepository implements StaffRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(string $userId): ?StaffIdentity
    {
        if (!Uuid::isValid($userId)) {
            return null;
        }

        // INNER JOIN on platform_roles, LEFT from there: a staff member whose
        // role grants nothing is still staff, and should reach a staff route
        // to be refused on the permission rather than be told they are not
        // staff at all. The two are different facts and an operator debugging
        // an access problem needs to tell them apart.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT ps.user_id,
                       array_to_json(array_agg(DISTINCT r.code)) AS roles,
                       array_to_json(
                           array_agg(DISTINCT p.code) FILTER (WHERE p.code IS NOT NULL)
                       ) AS permissions
                  FROM platform_staff ps
                  JOIN platform_roles r ON r.id = ps.platform_role_id
                  LEFT JOIN platform_role_permissions rp ON rp.platform_role_id = r.id
                  LEFT JOIN platform_permissions p ON p.id = rp.platform_permission_id
                 WHERE ps.user_id = :userId
                 GROUP BY ps.user_id
                SQL,
            ['userId' => $userId],
        );

        if ($row === false) {
            return null;
        }

        return new StaffIdentity(
            $userId,
            JsonArray::ofStrings($row['roles'] ?? null),
            JsonArray::ofStrings($row['permissions'] ?? null),
        );
    }
}
