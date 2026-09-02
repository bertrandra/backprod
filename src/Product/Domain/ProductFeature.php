<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * Something a product offers.
 *
 * Distinct from an entitlement: a feature is what the product *has*, an
 * entitlement is what a tenant has *bought* (§13). A feature disabled here is
 * off for everyone, however much they subscribed to.
 */
final class ProductFeature
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly bool $enabled,
    ) {
    }
}
