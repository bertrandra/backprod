<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure;

use App\Shared\Database\JsonArray;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\StaffRoster;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * The roster in PostgreSQL.
 *
 * Like {@see PostgresStaffRepository}, the queries here touch the platform
 * tables and `users` and nothing else: there is no join available that could
 * turn a tenant membership into a platform grant, because `tenant_members` is
 * not in any statement in this file.
 */
final class PostgresStaffRoster implements StaffRoster
{
    /**
     * The trigger Version20260911170000 installs. Matched by name rather than
     * by SQLSTATE because a `RAISE EXCEPTION` in plpgsql raises P0001, which
     * every other trigger in this schema raises too — the name is the only
     * part that says *which* invariant refused.
     */
    private const LAST_ADMIN_TRIGGER = 'platform_staff_keeps_an_admin';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function members(): array
    {
        // One row per person, not per grant: `platform_staff` is keyed on
        // (user, role), and a roster that listed somebody twice for holding
        // two roles would invite revoking the wrong one.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT u.id,
                       u.email,
                       u.display_name,
                       min(ps.granted_at) AS granted_at,
                       array_to_json(array_agg(DISTINCT r.code)) AS roles
                  FROM platform_staff ps
                  JOIN platform_roles r ON r.id = ps.platform_role_id
                  JOIN users u ON u.id = ps.user_id
                 GROUP BY u.id, u.email, u.display_name
                 ORDER BY min(ps.granted_at), u.id
                SQL,
        );

        return array_map(
            static fn (array $row): StaffMember => new StaffMember(
                Row::string($row, 'id'),
                Row::nullableString($row, 'email'),
                Row::nullableString($row, 'display_name'),
                JsonArray::ofStrings($row['roles'] ?? null),
                Row::timestamp($row, 'granted_at'),
            ),
            $rows,
        );
    }

    public function grant(string $userId, string $roleCode, string $grantedBy): void
    {
        if (!Uuid::isValid($userId)) {
            throw new NotFoundException('No such user.', [], 'USER_NOT_FOUND');
        }

        $this->requireUser($userId);
        $roleId = $this->roleId($roleCode);

        // ON CONFLICT DO NOTHING rather than a prior existence check: two
        // administrators granting the same role at the same moment is a race
        // whose losing half should be a no-op, not an error about a state
        // that is exactly what was asked for.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO platform_staff (user_id, platform_role_id, granted_by)
                VALUES (:userId, :roleId, :grantedBy)
                ON CONFLICT (user_id, platform_role_id) DO NOTHING
                SQL,
            ['userId' => $userId, 'roleId' => $roleId, 'grantedBy' => $grantedBy],
        );
    }

    public function revoke(string $userId, string $roleCode): void
    {
        if (!Uuid::isValid($userId)) {
            throw new NotFoundException('No such staff member.', [], 'STAFF_NOT_FOUND');
        }

        $roleId = $this->roleId($roleCode);

        try {
            $removed = $this->connection->executeStatement(
                <<<'SQL'
                    DELETE FROM platform_staff
                     WHERE user_id = :userId AND platform_role_id = :roleId
                    SQL,
                ['userId' => $userId, 'roleId' => $roleId],
            );
        } catch (Throwable $e) {
            // The database refused because this was the last administrator.
            // It decided that, and re-deriving it here — counting admins and
            // guessing — would be a second opinion that could disagree with
            // the one that actually governs the table.
            if (!str_contains($e->getMessage(), self::LAST_ADMIN_TRIGGER)) {
                throw $e;
            }

            throw new ConflictException(
                'LAST_PLATFORM_ADMIN',
                'This is the last platform administrator. Grant the role to somebody else first.',
                ['user_id' => $userId],
            );
        }

        if ($removed === 0) {
            throw new NotFoundException(
                'That person does not hold that role.',
                ['user_id' => $userId, 'role' => $roleCode],
                'STAFF_ROLE_NOT_HELD',
            );
        }
    }

    public function roles(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, name FROM platform_roles ORDER BY code',
        );

        return array_map(
            static fn (array $row): array => [
                'code' => Row::string($row, 'code'),
                'name' => Row::string($row, 'name'),
            ],
            $rows,
        );
    }

    private function requireUser(string $userId): void
    {
        $exists = $this->connection->fetchOne(
            'SELECT 1 FROM users WHERE id = :id',
            ['id' => $userId],
        );

        if ($exists === false) {
            throw new NotFoundException('No such user.', [], 'USER_NOT_FOUND');
        }
    }

    private function roleId(string $roleCode): string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM platform_roles WHERE code = :code',
            ['code' => $roleCode],
        );

        if (!is_string($id)) {
            throw new NotFoundException(
                'No such platform role.',
                ['role' => $roleCode],
                'PLATFORM_ROLE_NOT_FOUND',
            );
        }

        return $id;
    }
}
