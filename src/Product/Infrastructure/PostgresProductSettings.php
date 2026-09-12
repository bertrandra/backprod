<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\ProductSettings;
use App\Shared\Database\Uuid;
use Doctrine\DBAL\Connection;

final class PostgresProductSettings implements ProductSettings
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function all(string $productId): array
    {
        if (!Uuid::isValid($productId)) {
            // A malformed id has no configuration rather than raising: `= 'x'`
            // against a UUID column makes PostgreSQL refuse, which would turn a
            // typo in a URL into a 500.
            return [];
        }

        return ConfigurationRows::decode($this->connection->fetchAllAssociative(
            'SELECT key, value FROM product_configuration WHERE product_id = :id ORDER BY key',
            ['id' => $productId],
        ));
    }

    public function put(string $productId, string $key, array $value): void
    {
        // One statement rather than a read and a branch: two administrators
        // saving the same key at the same moment would otherwise race, and the
        // loser's INSERT would fail on the primary key rather than overwriting.
        $this->connection->executeStatement(
            <<<'SQL'
                INSERT INTO product_configuration (product_id, key, value)
                VALUES (:product, :key, CAST(:value AS jsonb))
                ON CONFLICT (product_id, key)
                DO UPDATE SET value = EXCLUDED.value, updated_at = now()
                SQL,
            [
                'product' => $productId,
                'key' => $key,
                // JSON_THROW_ON_ERROR: a value this cannot encode is a bug
                // here, not a row to write half of.
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
            ],
        );
    }
}
