<?php

declare(strict_types=1);

namespace App\Product\Infrastructure;

use App\Product\Domain\Product;
use App\Product\Domain\ProductRepository;

/**
 * Placeholder store until the `products` table lands in M3.
 *
 * It exists so the context pipeline can be built and tested against the port
 * now; swapping in a PostgreSQL adapter changes no caller.
 */
final class InMemoryProductRepository implements ProductRepository
{
    /** @var array<string, Product> */
    private readonly array $byCode;

    /**
     * @param list<Product> $products
     */
    public function __construct(array $products)
    {
        $byCode = [];

        foreach ($products as $product) {
            $byCode[$product->code] = $product;
        }

        $this->byCode = $byCode;
    }

    public function findByCode(string $code): ?Product
    {
        return $this->byCode[$code] ?? null;
    }

    public function find(string $productId): ?Product
    {
        foreach ($this->byCode as $product) {
            if ($product->id === $productId) {
                return $product;
            }
        }

        return null;
    }

    public function activeProducts(): array
    {
        $active = array_values(array_filter($this->byCode, static fn (Product $product): bool => $product->active));

        usort($active, static fn (Product $a, Product $b): int => strcmp($a->code, $b->code));

        return $active;
    }
}
