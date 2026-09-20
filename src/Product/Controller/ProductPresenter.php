<?php

declare(strict_types=1);

namespace App\Product\Controller;

use App\Product\Domain\Product;
use App\Product\Domain\ProductFeature;

/**
 * One shape for a product and its features, wherever they are returned.
 */
final class ProductPresenter
{
    /**
     * @return array{id: string, code: string, name: string, app_url: ?string}
     */
    public static function one(Product $product): array
    {
        // `active` is not exposed: every product a caller can see is active,
        // so the field would always be true and would only invite clients to
        // branch on it.
        return ['id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'app_url' => $product->appUrl];
    }

    /**
     * @param list<Product> $products
     *
     * @return list<array{id: string, code: string, name: string, app_url: ?string}>
     */
    public static function many(array $products): array
    {
        return array_map(self::one(...), $products);
    }

    /**
     * @param list<ProductFeature> $features
     *
     * @return list<array{code: string, name: string, enabled: bool}>
     */
    public static function features(array $features): array
    {
        return array_map(
            static fn (ProductFeature $f): array => [
                'code' => $f->code,
                'name' => $f->name,
                'enabled' => $f->enabled,
            ],
            $features,
        );
    }
}
