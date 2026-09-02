<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What products exist, what they offer, and how they are configured.
 *
 * Separate from ProductRepository, which answers the single question the
 * context chain asks on every request ("which product is this code?").
 * Keeping the hot path narrow means the registry can grow without widening
 * what every request depends on.
 */
interface ProductRegistry
{
    /**
     * Products the user may reach, derived from membership.
     *
     * Not "all active products": which products exist is commercial
     * information, and a user learns only about the ones they belong to
     * (§12.1 — the backend is the authority on product access).
     *
     * @return list<Product>
     */
    public function reachableBy(string $userId): array;

    /**
     * A single product, only if the user may reach it. Returning null for
     * both "no such product" and "not yours" is deliberate: the two must be
     * indistinguishable, or this becomes a way to enumerate the catalogue.
     */
    public function reachableProduct(string $userId, string $productId): ?Product;

    /**
     * @return list<ProductFeature>
     */
    public function features(string $productId): array;

    /**
     * @return array<string, mixed> configuration keyed by setting name
     */
    public function configuration(string $productId): array;
}
