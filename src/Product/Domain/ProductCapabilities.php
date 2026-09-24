<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * What a product has built, written by the product itself (2026-09-24,
 * ADR-051 §4 and ADR-052).
 *
 * A second port rather than a write method on {@see ProductRegistry}, for
 * the reason offers keep authoring apart from reading: every screen and
 * service that asks what a product offers depends on that interface, and
 * none of them may declare one.
 *
 * **Replaced as a set**, like a translation: what the product sends is what
 * it has built, so a capability it stops shipping is one it stops
 * declaring. A merge would make removing one impossible without a route
 * whose whole purpose was removal — and the caller here is a program
 * redeploying, which knows its whole list every time.
 */
interface ProductCapabilities
{
    /**
     * Declares this product's built capabilities, replacing the set.
     *
     * Every code must already be on the platform's list of features. That
     * is checked before the write *and* by `product_features_code_known`,
     * because a foreign key refuses with a constraint's name and a program
     * integrating for the first time is owed the reason.
     *
     * @param list<ProductFeature> $capabilities
     *
     * @return list<ProductFeature> what the product now declares, in code order
     *
     * @throws \App\Shared\Exceptions\BadRequestException FEATURE_CODE_UNKNOWN
     */
    public function declare(string $productId, array $capabilities): array;
}
