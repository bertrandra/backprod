<?php

declare(strict_types=1);

namespace App\Product\Domain;

/**
 * One crossing of a tenant boundary by a product key (ADR-051 §4): which
 * key, which product, which tenant it asked for — as it asked, since the
 * asking may name nothing that exists — and what it was answered.
 */
final class ProductAccess
{
    public function __construct(
        public readonly string $credentialId,
        public readonly string $productId,
        /** The tenant, when the asked-for id names one the product holds. */
        public readonly ?string $tenantId,
        public readonly string $askedFor,
        public readonly string $method,
        public readonly string $path,
        public readonly int $status,
    ) {
    }
}
