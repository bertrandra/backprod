<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ProductAccess;
use App\Product\Domain\ProductAccessLog;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

final class PostgresProductAccessLog implements ProductAccessLog
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function record(ProductAccess $access): void
    {
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_access_log (credential_id, product_id, tenant_id, asked_for, method, path, status)
                VALUES (:credential, :product, :tenant, :askedFor, :method, :path, :status)
                SQL,
            [
                'credential' => $access->credentialId,
                'product' => $access->productId,
                'tenant' => $access->tenantId,
                'askedFor' => $access->askedFor,
                'method' => $access->method,
                'path' => $access->path,
                'status' => $access->status,
            ],
        );
    }

    public function recent(string $productId, int $limit): array
    {
        if (!Uuid::isValid($productId)) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT credential_id, product_id, tenant_id, asked_for, method, path, status
                  FROM product_access_log
                 WHERE product_id = :product
                 ORDER BY occurred_at DESC
                 LIMIT :limit
                SQL,
            ['product' => $productId, 'limit' => max(1, min($limit, 500))],
        );

        return array_map(
            static fn (array $row): ProductAccess => new ProductAccess(
                Row::string($row, 'credential_id'),
                Row::string($row, 'product_id'),
                Row::nullableString($row, 'tenant_id'),
                Row::string($row, 'asked_for'),
                Row::string($row, 'method'),
                Row::string($row, 'path'),
                Row::integer($row, 'status'),
            ),
            $rows,
        );
    }
}
