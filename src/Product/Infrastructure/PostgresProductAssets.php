<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ProductAsset;
use App\Product\Domain\ProductAssets;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use App\Shared\Exceptions\ConflictException;
use Doctrine\DBAL\Connection;

final class PostgresProductAssets implements ProductAssets
{
    private const COLUMNS = 'id, product_id, storage_key, filename, content_type, byte_size, checksum, uploaded_by, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(
        string $productId,
        string $storageKey,
        string $filename,
        string $contentType,
        int $byteSize,
        string $checksum,
        ?string $uploadedBy,
    ): ProductAsset {
        $row = $this->connection->fetchAssociative(
            <<<SQL
                INSERT INTO product_assets
                    (product_id, storage_key, filename, content_type, byte_size, checksum, uploaded_by)
                VALUES (:product, :key, :filename, :type, :size, :checksum, :by)
                RETURNING
                SQL . ' ' . self::COLUMNS,
            [
                'product' => $productId,
                'key' => $storageKey,
                'filename' => $filename,
                'type' => $contentType,
                'size' => $byteSize,
                'checksum' => $checksum,
                'by' => $uploadedBy,
            ],
        );

        if ($row === false) {
            throw new ConflictException('ASSET_NOT_RECORDED', 'The picture could not be recorded.');
        }

        return self::toAsset($row);
    }

    public function of(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT ' . self::COLUMNS . ' FROM product_assets WHERE product_id = :product ORDER BY created_at DESC, id',
            ['product' => $productId],
        );

        return array_map(self::toAsset(...), $rows);
    }

    public function published(string $code, string $assetId): ?ProductAsset
    {
        if (!Uuid::isValid($assetId)) {
            return null;
        }

        // The join **is** the authorisation: a picture is served while the
        // product's story is published and not otherwise. One non-answer
        // for an unpublished product, an unknown id and another product's
        // picture, so an id is not a way to ask what a draft contains.
        $row = $this->connection->fetchAssociative(
            <<<SQL
                SELECT
                SQL . ' ' . self::prefixed() . <<<'SQL'

                  FROM product_assets a
                  JOIN products p ON p.id = a.product_id
                 WHERE a.id = :asset
                   AND p.code = :code
                   AND p.showcase_published_at IS NOT NULL
                SQL,
            ['asset' => $assetId, 'code' => $code],
        );

        return $row === false ? null : self::toAsset($row);
    }

    public function find(string $productId, string $assetId): ?ProductAsset
    {
        if (!Uuid::isValid($assetId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT ' . self::COLUMNS . ' FROM product_assets WHERE id = :asset AND product_id = :product',
            ['asset' => $assetId, 'product' => $productId],
        );

        return $row === false ? null : self::toAsset($row);
    }

    public function delete(ProductAsset $asset): void
    {
        $this->connection->executeStatement(
            'DELETE FROM product_assets WHERE id = :id',
            ['id' => $asset->id],
        );
    }

    private static function prefixed(): string
    {
        return implode(', ', array_map(
            static fn (string $column): string => 'a.' . trim($column),
            explode(',', self::COLUMNS),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toAsset(array $row): ProductAsset
    {
        return new ProductAsset(
            Row::string($row, 'id'),
            Row::string($row, 'product_id'),
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
