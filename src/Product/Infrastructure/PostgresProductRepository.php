<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;
use Doctrine\DBAL\Connection;

final class PostgresProductRepository implements ProductRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findByCode(string $code): ?Product
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, code, name, active FROM products WHERE code = :code',
            ['code' => $code],
        );

        if ($row === false) {
            return null;
        }

        $id = $row['id'] ?? null;
        $foundCode = $row['code'] ?? null;
        $name = $row['name'] ?? null;

        if (!is_string($id) || !is_string($foundCode) || !is_string($name)) {
            return null;
        }

        // Inactive products are returned as-is; ProductResolver decides that
        // they are indistinguishable from absent. Keeping that judgement in
        // one place means a future admin view can still see them.
        return new Product($id, $foundCode, $name, (bool) ($row['active'] ?? false));
    }
}
