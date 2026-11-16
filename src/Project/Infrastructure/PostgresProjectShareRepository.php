<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\Project;
use App\Project\Domain\ProjectShare;
use App\Project\Domain\ProjectShareRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;
use RuntimeException;

/**
 * Share links in PostgreSQL (ADR-073).
 *
 * The status is computed in every read against `now()`, the database's
 * clock — the same clock the constraint bounding `expires_at` reads — so
 * "expired" is never a column somebody forgot to update.
 *
 * Every lookup that comes from a project names the project's tenant and
 * product as well as its id, and the composite foreign key holds the row to
 * the same three: a link cannot be found through, or written onto, a project
 * of another scope.
 */
final class PostgresProjectShareRepository implements ProjectShareRepository
{
    private const COLUMNS = <<<'SQL'
        s.id, s.tenant_id, s.product_id, s.project_id, s.display_name, s.recipient_label,
        s.created_by, s.created_at, s.expires_at, s.revoked_at, s.revoked_by,
        s.open_count, s.last_opened_at,
        CASE WHEN s.revoked_at IS NOT NULL THEN 'REVOKED'
             WHEN s.expires_at <= now() THEN 'EXPIRED'
             ELSE 'ACTIVE' END AS status
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(
        Project $project,
        string $tokenHash,
        ?string $displayName,
        ?string $recipientLabel,
        int $days,
        ?string $createdBy,
    ): ProjectShare {
        // `now()` twice in one statement is one instant, so the expiry is
        // exactly `days` after the creation the constraint compares it with.
        $row = $this->connection->fetchAssociative(
            'WITH s AS (' . <<<'SQL'
                INSERT INTO project_shares
                    (tenant_id, product_id, project_id, token_hash, display_name, recipient_label,
                     created_by, created_at, expires_at)
                VALUES (:tenantId, :productId, :projectId, :tokenHash, :displayName, :recipientLabel,
                        :createdBy, now(), now() + make_interval(days => :days))
                RETURNING *
                SQL . ') SELECT ' . self::COLUMNS . ' FROM s',
            [
                'tenantId' => $project->tenantId,
                'productId' => $project->productId,
                'projectId' => $project->id,
                'tokenHash' => $tokenHash,
                'displayName' => $displayName,
                'recipientLabel' => $recipientLabel,
                'createdBy' => $createdBy !== null && Uuid::isValid($createdBy) ? $createdBy : null,
                'days' => $days,
            ],
        );

        if ($row === false) {
            throw new RuntimeException('The share link was not stored.');
        }

        return self::toShare($row);
    }

    public function listFor(Project $project): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM project_shares s
                WHERE s.tenant_id = :tenantId AND s.product_id = :productId AND s.project_id = :projectId
                ORDER BY s.created_at DESC, s.id DESC
                SQL,
            self::scopeOf($project),
        );

        return array_map(self::toShare(...), $rows);
    }

    public function countLiveFor(Project $project): int
    {
        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*)
                  FROM project_shares s
                 WHERE s.tenant_id = :tenantId AND s.product_id = :productId AND s.project_id = :projectId
                   AND s.revoked_at IS NULL AND s.expires_at > now()
                SQL,
            self::scopeOf($project),
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(Project $project, string $shareId): ?ProjectShare
    {
        if (!Uuid::isValid($shareId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM project_shares s
                WHERE s.id = :id
                  AND s.tenant_id = :tenantId AND s.product_id = :productId AND s.project_id = :projectId
                SQL,
            ['id' => $shareId] + self::scopeOf($project),
        );

        return $row === false ? null : self::toShare($row);
    }

    public function revoke(ProjectShare $share, ?string $revokedBy): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_shares
                   SET revoked_at = now(), revoked_by = :revokedBy
                 WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                   AND revoked_at IS NULL
                SQL,
            [
                'id' => $share->id,
                'tenantId' => $share->tenantId,
                'productId' => $share->productId,
                'revokedBy' => $revokedBy !== null && Uuid::isValid($revokedBy) ? $revokedBy : null,
            ],
        );
    }

    public function findLiveByTokenHash(string $tokenHash): ?ProjectShare
    {
        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                 FROM project_shares s
                WHERE s.token_hash = :tokenHash
                  AND s.revoked_at IS NULL AND s.expires_at > now()
                SQL,
            ['tokenHash' => $tokenHash],
        );

        return $row === false ? null : self::toShare($row);
    }

    public function recordOpening(ProjectShare $share): void
    {
        // An increment in the statement rather than a value computed here, so
        // two readers opening at once count twice.
        $this->connection->executeStatement(
            <<<'SQL'
                UPDATE project_shares
                   SET open_count = open_count + 1, last_opened_at = now()
                 WHERE id = :id
                SQL,
            ['id' => $share->id],
        );
    }

    /**
     * @return array{tenantId: string, productId: string, projectId: string}
     */
    private static function scopeOf(Project $project): array
    {
        return ['tenantId' => $project->tenantId, 'productId' => $project->productId, 'projectId' => $project->id];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toShare(array $row): ProjectShare
    {
        return new ProjectShare(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::string($row, 'project_id'),
            Row::nullableString($row, 'display_name'),
            Row::nullableString($row, 'recipient_label'),
            Row::nullableString($row, 'created_by'),
            Row::timestamp($row, 'created_at'),
            Row::timestamp($row, 'expires_at'),
            Row::nullableTimestamp($row, 'revoked_at'),
            Row::nullableString($row, 'revoked_by'),
            Row::integer($row, 'open_count'),
            Row::nullableTimestamp($row, 'last_opened_at'),
            Row::string($row, 'status'),
        );
    }
}
