<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

interface EntitlementRepository
{
    /**
     * The capabilities a tenant may currently use for a product.
     *
     * Capabilities, not plan names (§13): the caller of RequestContext::allows()
     * must never need to know which offer produced them.
     *
     * @return list<string>
     */
    public function capabilitiesFor(string $tenantId, string $productId): array;
}
