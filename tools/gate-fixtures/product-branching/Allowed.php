<?php

declare(strict_types=1);

namespace App\GateFixture\ProductBranching;

/**
 * Shapes the gate must NOT report: an empty-string check is a presence test,
 * comparing two products is not naming one, and a product code as data (a
 * key in a table read from configuration) is not a branch.
 */
final class Allowed
{
    public function present(string $productId): bool
    {
        return $productId !== '';
    }

    public function same(object $product, object $other): bool
    {
        return $product->code === $other->code;
    }

    /** @param list<string> $assigned */
    public function held(string $productId, array $assigned): bool
    {
        return in_array($productId, $assigned, true);
    }

    /** @return array<string, int> */
    public function data(): array
    {
        return ['atlas' => 20];
    }
}
