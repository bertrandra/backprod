<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * The product access log (ADR-051 §4): written on every call a product key
 * makes about a tenant, refusals included, for the reason the staff access
 * log is — an actor that is not a person still reads a customer's data.
 */
interface ProductAccessLog
{
    public function record(ProductAccess $access): void;

    /**
     * Newest first, for one product.
     *
     * @return list<ProductAccess>
     */
    public function recent(string $productId, int $limit): array;
}
