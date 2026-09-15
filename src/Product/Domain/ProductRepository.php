<?php

declare(strict_types=1);

namespace App\Product\Domain;

interface ProductRepository
{
    public function findByCode(string $code): ?Product;

    /**
     * Every active product, in code order.
     *
     * Here rather than on `ProductDirectory`, deliberately. That port answers
     * "what exists" to platform staff, includes retired products, and writes;
     * a public page must not be able to inject it. This answers a narrower
     * question — what is switched on — to the one caller that has a reason
     * to ask without an identity: the storefront, which then keeps only the
     * products that advertise something (ADR-047 amending ADR-041).
     *
     * @return list<Product>
     */
    public function activeProducts(): array;
}
