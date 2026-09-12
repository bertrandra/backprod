<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductFeature;
use App\Product\Domain\ProductRegistry;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

final class PostgresProductRegistry implements ProductRegistry
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function reachableBy(string $userId): array
    {
        // Membership is the only route to a product. DISTINCT because a user
        // may belong to several tenants within the same product.
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT DISTINCT p.id, p.code, p.name, p.active
                FROM products p
                JOIN tenant_members tm ON tm.product_id = p.id
                WHERE tm.user_id = :userId
                  AND p.active
                ORDER BY p.code
                SQL,
            ['userId' => $userId],
        );

        $products = [];

        foreach ($rows as $row) {
            $product = $this->toProduct($row);

            if ($product !== null) {
                $products[] = $product;
            }
        }

        return $products;
    }

    public function reachableProduct(string $userId, string $productId): ?Product
    {
        // A malformed id is unreachable, not an error. PostgreSQL raises on
        // `= 'banana'` against a UUID column, which would turn a typo in a
        // URL into a 500 — and make the shape of an id observable.
        if (!Uuid::isValid($productId)) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            <<<'SQL'
                SELECT p.id, p.code, p.name, p.active
                FROM products p
                JOIN tenant_members tm ON tm.product_id = p.id
                WHERE tm.user_id = :userId
                  AND p.id = :productId
                  AND p.active
                LIMIT 1
                SQL,
            ['userId' => $userId, 'productId' => $productId],
        );

        return $row === false ? null : $this->toProduct($row);
    }

    public function features(string $productId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT code, name, enabled FROM product_features WHERE product_id = :id ORDER BY code',
            ['id' => $productId],
        );

        $features = [];

        foreach ($rows as $row) {
            $code = $row['code'] ?? null;
            $name = $row['name'] ?? null;

            if (is_string($code) && is_string($name)) {
                $features[] = new ProductFeature($code, $name, (bool) ($row['enabled'] ?? false));
            }
        }

        return $features;
    }

    public function configuration(string $productId): array
    {
        // Decoded by the helper the console's writer shares, so that what a
        // tenant's product reads and what an administrator saves cannot
        // disagree about a malformed row.
        return ConfigurationRows::decode($this->connection->fetchAllAssociative(
            'SELECT key, value FROM product_configuration WHERE product_id = :id ORDER BY key',
            ['id' => $productId],
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toProduct(array $row): ?Product
    {
        $id = $row['id'] ?? null;
        $code = $row['code'] ?? null;
        $name = $row['name'] ?? null;

        if (!is_string($id) || !is_string($code) || !is_string($name)) {
            return null;
        }

        return new Product($id, $code, $name, (bool) ($row['active'] ?? false));
    }
}
