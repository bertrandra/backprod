<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use App\Shared\Database\Row;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

final class PostgresProductRepository implements ProductRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByCode(string $code): ?Product
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, code, name, active, app_url, webhook_url, webhook_secret_issued_at FROM products WHERE code = :code',
            ['code' => $code],
        );

        // Inactive products are returned as-is; ProductResolver decides that
        // they are indistinguishable from absent. Keeping that judgement in
        // one place means a future admin view can still see them.
        return $row === false ? null : self::toProduct($row);
    }

    public function find(string $productId): ?Product
    {
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, code, name, active, app_url, webhook_url, webhook_secret_issued_at FROM products WHERE id = :id',
            ['id' => $productId],
        );

        return $row === false ? null : self::toProduct($row);
    }

    public function activeProducts(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, code, name, active, app_url, webhook_url, webhook_secret_issued_at FROM products WHERE active ORDER BY code',
        );

        $products = [];

        foreach ($rows as $row) {
            $product = self::toProduct($row);

            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toProduct(array $row): ?Product
    {
        $id = $row['id'] ?? null;
        $code = $row['code'] ?? null;
        $name = $row['name'] ?? null;

        if (!is_string($id) || !is_string($code) || !is_string($name)) {
            return null;
        }

        $appUrl = $row['app_url'] ?? null;
        $webhookUrl = $row['webhook_url'] ?? null;

        return new Product(
            $id,
            $code,
            $name,
            (bool) ($row['active'] ?? false),
            is_string($appUrl) ? $appUrl : null,
            is_string($webhookUrl) ? $webhookUrl : null,
            Row::nullableTimestamp($row, 'webhook_secret_issued_at'),
        );
    }
}
