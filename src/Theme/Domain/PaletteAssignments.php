<?php

declare(strict_types=1);

namespace App\Theme\Domain;

/**
 * Which palette each organisation wears in each product it holds — one page of
 * organisations, against every product (2026-10-04).
 *
 * A cell exists only where the tenant holds the product: a palette for a
 * product nobody there uses is not a choice anybody can make.
 */
final class PaletteAssignments
{
    /**
     * @param list<array{id: string, code: string, name: string}> $products
     * @param list<array{id: string, name: string, slug: string, palettes: array<string, ?string>}> $tenants palettes: product id => palette name, null for the platform's design
     */
    public function __construct(
        public readonly array $products,
        public readonly array $tenants,
        public readonly int $total,
    ) {
    }
}
