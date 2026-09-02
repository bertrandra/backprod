<?php

declare(strict_types=1);

namespace App\Product\Service;

use App\Product\Domain\Product;
use App\Product\Domain\ProductFeature;
use App\Product\Domain\ProductRegistry;
use App\Shared\Exceptions\NotFoundException;

/**
 * Reading the product registry on behalf of a caller.
 *
 * Every method takes the authenticated user id and refuses anything they
 * cannot reach — so authorisation is not something each endpoint remembers
 * to do.
 */
final class ProductCatalogue
{
    public function __construct(private readonly ProductRegistry $registry)
    {
    }

    /**
     * @return list<Product>
     */
    public function available(string $userId): array
    {
        return $this->registry->reachableBy($userId);
    }

    public function product(string $userId, string $productId): Product
    {
        $product = $this->registry->reachableProduct($userId, $productId);

        if ($product === null) {
            // A product that does not exist and one the caller has no access
            // to are reported identically, so this cannot be used to discover
            // which products the platform hosts.
            throw new NotFoundException('Unknown product.', [], 'PRODUCT_NOT_FOUND');
        }

        return $product;
    }

    /**
     * @return list<ProductFeature>
     */
    public function features(string $userId, string $productId): array
    {
        return $this->registry->features($this->product($userId, $productId)->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function configuration(string $userId, string $productId): array
    {
        return $this->registry->configuration($this->product($userId, $productId)->id);
    }

    /**
     * The catalogue: what the product is, plus what it offers.
     *
     * §10.1 lists /catalog separately from /features and /configuration; this
     * is the combined view, so a client can populate a product switcher in one
     * request instead of three. Commercial offers are M5 and will extend it.
     *
     * @return array{product: Product, features: list<ProductFeature>, configuration: array<string, mixed>}
     */
    public function catalogue(string $userId, string $productId): array
    {
        $product = $this->product($userId, $productId);

        return [
            'product' => $product,
            'features' => $this->registry->features($product->id),
            'configuration' => $this->registry->configuration($product->id),
        ];
    }
}
