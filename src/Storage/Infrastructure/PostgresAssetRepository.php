<?php

declare(strict_types=1);

namespace App\Storage\Infrastructure;

use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Storage\Domain\Asset;
use App\Storage\Domain\AssetRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use RuntimeException;

final class PostgresAssetRepository implements AssetRepository
{
    private const COLUMNS = <<<'SQL'
        id, tenant_id, product_id, project_id, kind, storage_key, filename,
        content_type, byte_size, checksum, uploaded_by, created_at
        SQL;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        string $tenantId,
        string $productId,
        ?string $projectId,
        string $kind,
        string $storageKey,
        string $filename,
        string $contentType,
        int $byteSize,
        string $checksum,
        ?string $uploadedBy,
    ): Asset {
        $id = $this->connection->fetchOne(
            <<<'SQL'
                INSERT INTO assets
                    (tenant_id, product_id, project_id, kind, storage_key, filename,
                     content_type, byte_size, checksum, uploaded_by)
                VALUES (:tenant, :product, :project, :kind, :key, :filename,
                        :contentType, :byteSize, :checksum, :uploadedBy)
                RETURNING id
                SQL,
            [
                'tenant' => $tenantId,
                'product' => $productId,
                'project' => $projectId,
                'kind' => $kind,
                'key' => $storageKey,
                'filename' => $filename,
                'contentType' => $contentType,
                'byteSize' => $byteSize,
                'checksum' => $checksum,
                'uploadedBy' => $uploadedBy,
            ],
            ['byteSize' => ParameterType::INTEGER],
        );

        if (!is_string($id)) {
            throw new RuntimeException('Failed to record an asset.');
        }

        $asset = $this->findForSignedLink($id);

        if ($asset === null) {
            throw new RuntimeException('The asset vanished during the write that created it.');
        }

        return $asset;
    }

    public function find(string $tenantId, string $productId, string $assetId): ?Asset
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId) || !Uuid::isValid($assetId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM assets
                 WHERE id = :id AND tenant_id = :tenant AND product_id = :product
                SQL,
            ['id' => $assetId, 'tenant' => $tenantId, 'product' => $productId],
        );

        return $row === false ? null : self::toAsset($row);
    }

    public function findForSignedLink(string $assetId): ?Asset
    {
        if (!Uuid::isValid($assetId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM assets WHERE id = :id',
            ['id' => $assetId],
        );

        return $row === false ? null : self::toAsset($row);
    }

    public function listFor(
        string $tenantId,
        string $productId,
        ?string $projectId,
        int $limit,
        int $offset,
    ): array {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return [];
        }

        if ($projectId !== null && !Uuid::isValid($projectId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . <<<'SQL'
                  FROM assets
                 WHERE tenant_id = :tenant
                   AND product_id = :product
                   AND (CAST(:project AS UUID) IS NULL OR project_id = CAST(:project AS UUID))
                 ORDER BY created_at DESC, id
                 LIMIT :limit OFFSET :offset
                SQL,
            [
                'tenant' => $tenantId,
                'product' => $productId,
                'project' => $projectId,
                'limit' => $limit,
                'offset' => $offset,
            ],
            ['limit' => ParameterType::INTEGER, 'offset' => ParameterType::INTEGER],
        );

        return array_map(self::toAsset(...), $rows);
    }

    public function countFor(string $tenantId, string $productId, ?string $projectId): int
    {
        if (!Uuid::isValid($tenantId) || !Uuid::isValid($productId)) {
            return 0;
        }

        $count = $this->connection->fetchOne(
            <<<'SQL'
                SELECT count(*) FROM assets
                 WHERE tenant_id = :tenant
                   AND product_id = :product
                   AND (CAST(:project AS UUID) IS NULL OR project_id = CAST(:project AS UUID))
                SQL,
            ['tenant' => $tenantId, 'product' => $productId, 'project' => $projectId],
        );

        return is_numeric($count) ? (int) $count : 0;
    }

    public function delete(Asset $asset): void
    {
        $this->connection->executeStatement('DELETE FROM assets WHERE id = :id', ['id' => $asset->id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toAsset(array $row): Asset
    {
        return new Asset(
            Row::string($row, 'id'),
            Row::string($row, 'tenant_id'),
            Row::string($row, 'product_id'),
            Row::nullableString($row, 'project_id'),
            Row::string($row, 'kind'),
            Row::string($row, 'storage_key'),
            Row::string($row, 'filename'),
            Row::string($row, 'content_type'),
            Row::integer($row, 'byte_size'),
            Row::string($row, 'checksum'),
            Row::nullableString($row, 'uploaded_by'),
            Row::timestamp($row, 'created_at'),
        );
    }
}
