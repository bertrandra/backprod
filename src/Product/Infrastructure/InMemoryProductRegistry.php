<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductFeature;
use App\Product\Domain\ProductRegistry;

/**
 * Registry without a database, for tests about the request pipeline rather
 * than persistence. The Postgres registry is covered separately against a
 * real database.
 */
final class InMemoryProductRegistry implements ProductRegistry
{
    /**
     * @param array<string, list<Product>>          $reachable    products keyed by user id
     * @param array<string, list<ProductFeature>>   $features     features keyed by product id
     * @param array<string, array<string, mixed>>   $configuration configuration keyed by product id
     */
    public function __construct(
        private readonly array $reachable = [],
        private readonly array $features = [],
        private readonly array $configuration = [],
    ) {
    }

    public function reachableBy(string $userId): array
    {
        return $this->reachable[$userId] ?? [];
    }

    public function reachableProduct(string $userId, string $productId): ?Product
    {
        foreach ($this->reachableBy($userId) as $product) {
            if ($product->id === $productId) {
                return $product;
            }
        }

        return null;
    }

    public function features(string $productId): array
    {
        return $this->features[$productId] ?? [];
    }

    public function configuration(string $productId): array
    {
        return $this->configuration[$productId] ?? [];
    }
}
