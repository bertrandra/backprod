<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Project\Domain\Project;
use App\Project\Domain\ProjectChanges;
use App\Project\Domain\ProjectDraft;
use App\Project\Domain\ProjectRepository;
use App\Project\Domain\ProjectVersion;
use App\Shared\Database\Uuid;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Exception;
use RuntimeException;

/**
 * Projects in PostgreSQL.
 *
 * Two things here are load-bearing:
 *
 * The document is never decoded and re-encoded on its way through. It is
 * decoded into an object tree, not an associative array, because PHP arrays
 * cannot tell {"0":"a"} from ["a"] — a project whose layers are keyed by
 * numeric ids would come back a different shape than it went in, silently.
 *
 * Version numbers are assigned under a row lock on the project. Two
 * concurrent snapshots would otherwise read the same maximum; the unique
 * constraint would catch it, but as a failed request rather than as two
 * correctly numbered versions.
 */
final class PostgresProjectRepository implements ProjectRepository
{
    private const PROJECT_COLUMNS = <<<'SQL'
        id, tenant_id, product_id, name, description, schema_version,
        document::text AS document, created_by, created_at, updated_at
        SQL;

    private const VERSION_COLUMNS = <<<'SQL'
        id, project_id, version_number, label, name, description, schema_version,
        document::text AS document, created_by, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function listForTenant(string $tenantId, string $productId, int $limit, int $offset): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::PROJECT_COLUMNS . <<<'SQL'
                 FROM projects
                WHERE tenant_id = :tenantId AND product_id = :productId
                ORDER BY updated_at DESC, id
                LIMIT :limit OFFSET :offset
                SQL,
            ['tenantId' => $tenantId, 'productId' => $productId, 'limit' => $limit, 'offset' => $offset],
            // Bound as integers rather than left to inference: LIMIT and
            // OFFSET are the two places PostgreSQL will not take a text
            // parameter.
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map($this->toProject(...), $rows);
    }

    public function countForTenant(string $tenantId, string $productId): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM projects WHERE tenant_id = :tenantId AND product_id = :productId',
            ['tenantId' => $tenantId, 'productId' => $productId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function find(string $tenantId, string $productId, string $projectId): ?Project
    {
        if (!Uuid::isValid($projectId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::PROJECT_COLUMNS . <<<'SQL'
                 FROM projects
                WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                SQL,
            ['id' => $projectId, 'tenantId' => $tenantId, 'productId' => $productId],
        );

        return $row === false ? null : $this->toProject($row);
    }

    public function create(ProjectDraft $draft): Project
    {
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO projects
                    (tenant_id, product_id, name, description, schema_version, document, created_by)
                VALUES
                    (:tenantId, :productId, :name, :description, :schemaVersion, CAST(:document AS jsonb), :createdBy)
                RETURNING
                SQL . ' ' . self::PROJECT_COLUMNS,
            [
                'tenantId' => $draft->tenantId,
                'productId' => $draft->productId,
                'name' => $draft->name,
                'description' => $draft->description,
                'schemaVersion' => $draft->schemaVersion,
                'document' => self::encode($draft->document),
                'createdBy' => $draft->createdBy,
            ],
        );

        return $this->requireRow($row, 'insert a project');
    }

    public function update(Project $project, ProjectChanges $changes): Project
    {
        // Column names come from this fixed set, never from the request; only
        // the values are bound. A PATCH cannot name a column.
        $assignments = ['updated_at = now()'];
        $parameters = [
            'id' => $project->id,
            'tenantId' => $project->tenantId,
            'productId' => $project->productId,
        ];

        if ($changes->name !== null) {
            $assignments[] = 'name = :name';
            $parameters['name'] = $changes->name;
        }

        if ($changes->descriptionProvided) {
            $assignments[] = 'description = :description';
            $parameters['description'] = $changes->description;
        }

        if ($changes->schemaVersion !== null) {
            $assignments[] = 'schema_version = :schemaVersion';
            $parameters['schemaVersion'] = $changes->schemaVersion;
        }

        if ($changes->document !== null) {
            $assignments[] = 'document = CAST(:document AS jsonb)';
            $parameters['document'] = self::encode($changes->document);
        }

        $row = $this->connection->fetchAssociative(
            'UPDATE projects SET ' . implode(', ', $assignments) . <<<'SQL'
                 WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId
                RETURNING
                SQL . ' ' . self::PROJECT_COLUMNS,
            $parameters,
        );

        return $this->requireRow($row, 'update a project');
    }

    public function delete(Project $project): void
    {
        // Versions go with it through ON DELETE CASCADE: they are the
        // project's history, not history of their own.
        $this->connection->executeStatement(
            'DELETE FROM projects WHERE id = :id AND tenant_id = :tenantId AND product_id = :productId',
            ['id' => $project->id, 'tenantId' => $project->tenantId, 'productId' => $project->productId],
        );
    }

    public function listVersions(Project $project): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::VERSION_COLUMNS . <<<'SQL'
                 FROM project_versions
                WHERE project_id = :projectId
                ORDER BY version_number DESC
                SQL,
            ['projectId' => $project->id],
        );

        return array_map($this->toVersion(...), $rows);
    }

    public function findVersion(Project $project, string $versionId): ?ProjectVersion
    {
        if (!Uuid::isValid($versionId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::VERSION_COLUMNS . <<<'SQL'
                 FROM project_versions
                WHERE id = :id AND project_id = :projectId
                SQL,
            ['id' => $versionId, 'projectId' => $project->id],
        );

        return $row === false ? null : $this->toVersion($row);
    }

    public function snapshot(Project $project, ?string $label, ?string $createdBy): ProjectVersion
    {
        return $this->connection->transactional(
            fn (): ProjectVersion => $this->snapshotLocked($project->id, $label, $createdBy),
        );
    }

    public function restore(Project $project, ProjectVersion $version, ?string $restoredBy): Project
    {
        return $this->connection->transactional(function () use ($project, $version, $restoredBy): Project {
            // The current state is captured before it is overwritten, in the
            // same transaction, so restoring can never be the operation that
            // loses work. Its label says why it exists.
            $this->snapshotLocked(
                $project->id,
                sprintf('Before restoring version %d', $version->versionNumber),
                $restoredBy,
            );

            $row = $this->connection->fetchAssociative(
                <<<'SQL'
                    UPDATE projects p
                       SET name = v.name,
                           description = v.description,
                           schema_version = v.schema_version,
                           document = v.document,
                           updated_at = now()
                      FROM project_versions v
                     WHERE v.id = :versionId
                       AND v.project_id = p.id
                       AND p.id = :projectId
                    RETURNING
                    SQL . ' ' . self::prefixed('p'),
                ['versionId' => $version->id, 'projectId' => $project->id],
            );

            return $this->requireRow($row, 'restore a project version');
        });
    }

    public function duplicate(Project $project, string $name, ?string $createdBy): Project
    {
        // Copied inside the database rather than round-tripping the document
        // through PHP: the copy is then exactly the stored bytes, and a
        // document too large to hold twice in memory is not a special case.
        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO projects
                    (tenant_id, product_id, name, description, schema_version, document, created_by)
                SELECT p.tenant_id, p.product_id, :name, p.description, p.schema_version, p.document, :createdBy
                  FROM projects p
                 WHERE p.id = :projectId
                RETURNING
                SQL . ' ' . self::PROJECT_COLUMNS,
            ['name' => $name, 'createdBy' => $createdBy, 'projectId' => $project->id],
        );

        return $this->requireRow($row, 'duplicate a project');
    }

    /**
     * Snapshots the project's stored state, numbering the version under a
     * lock. Must be called inside a transaction.
     *
     * The snapshot reads from the row rather than from an in-memory Project:
     * whatever is stored is what gets captured, even if the caller's copy is
     * a request older.
     */
    private function snapshotLocked(string $projectId, ?string $label, ?string $createdBy): ProjectVersion
    {
        // Locks the project row for the rest of the transaction, which is
        // what makes the next version number safe to compute.
        $this->connection->fetchOne('SELECT id FROM projects WHERE id = :id FOR UPDATE', ['id' => $projectId]);

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                INSERT INTO project_versions
                    (project_id, version_number, label, name, description, schema_version, document, created_by)
                SELECT p.id,
                       coalesce((SELECT max(v.version_number) FROM project_versions v WHERE v.project_id = p.id), 0) + 1,
                       :label, p.name, p.description, p.schema_version, p.document, :createdBy
                  FROM projects p
                 WHERE p.id = :projectId
                RETURNING
                SQL . ' ' . self::VERSION_COLUMNS,
            ['label' => $label, 'createdBy' => $createdBy, 'projectId' => $projectId],
        );

        if ($row === false) {
            // The project vanished between the read that authorised this and
            // the insert. Nothing was written: the transaction rolls back.
            throw new RuntimeException('Failed to snapshot a project.');
        }

        return $this->toVersion($row);
    }

    /**
     * The project columns qualified by a table alias, for statements that
     * join and so cannot use a bare column list.
     */
    private static function prefixed(string $alias): string
    {
        return sprintf(
            '%1$s.id, %1$s.tenant_id, %1$s.product_id, %1$s.name, %1$s.description, '
            . '%1$s.schema_version, %1$s.document::text AS document, %1$s.created_by, '
            . '%1$s.created_at, %1$s.updated_at',
            $alias,
        );
    }

    /**
     * @param array<string, mixed>|false $row
     */
    private function requireRow(array|false $row, string $what): Project
    {
        if ($row === false) {
            // RETURNING produced nothing: the row vanished between the read
            // that authorised this and the write. A controlled failure, not a
            // half-applied change — the transaction or statement did nothing.
            throw new RuntimeException(sprintf('Failed to %s.', $what));
        }

        return $this->toProject($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toProject(array $row): Project
    {
        return new Project(
            self::string($row, 'id'),
            self::string($row, 'tenant_id'),
            self::string($row, 'product_id'),
            self::string($row, 'name'),
            self::nullableString($row, 'description'),
            self::integer($row, 'schema_version'),
            self::document($row),
            self::nullableString($row, 'created_by'),
            self::timestamp($row, 'created_at'),
            self::timestamp($row, 'updated_at'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toVersion(array $row): ProjectVersion
    {
        return new ProjectVersion(
            self::string($row, 'id'),
            self::string($row, 'project_id'),
            self::integer($row, 'version_number'),
            self::nullableString($row, 'label'),
            self::string($row, 'name'),
            self::nullableString($row, 'description'),
            self::integer($row, 'schema_version'),
            self::document($row),
            self::nullableString($row, 'created_by'),
            self::timestamp($row, 'created_at'),
        );
    }

    private static function encode(object $document): string
    {
        $encoded = json_encode($document);

        if ($encoded === false) {
            // The document was validated before reaching here, so this is a
            // programming error rather than bad input.
            throw new RuntimeException('Failed to encode a project document.');
        }

        return $encoded;
    }

    /**
     * The column is selected as ::text so PHP decodes it once, here, rather
     * than the driver guessing. An object tree, not an array: see the class
     * comment.
     *
     * @param array<string, mixed> $row
     */
    private static function document(array $row): object
    {
        $decoded = json_decode(self::string($row, 'document'), false);

        if (!is_object($decoded)) {
            // A CHECK constraint keeps every stored document an object, so
            // this means the schema was changed out from under the code.
            throw self::malformed('document');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function string(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!is_string($value)) {
            throw self::malformed($column);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function nullableString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function integer(array $row, string $column): int
    {
        $value = $row[$column] ?? null;

        if (!is_int($value) && !(is_string($value) && $value !== '' && ctype_digit($value))) {
            throw self::malformed($column);
        }

        return (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function timestamp(array $row, string $column): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable(self::string($row, $column));
        } catch (Exception) {
            throw self::malformed($column);
        }
    }

    private static function malformed(string $column): RuntimeException
    {
        // The column name only; never the value, which is project content.
        return new RuntimeException(sprintf('Unexpected value in projects column "%s".', $column));
    }
}
