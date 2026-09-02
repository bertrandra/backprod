<?php

declare(strict_types=1);

namespace App\Entitlement\Infrastructure;

use App\Entitlement\Domain\EntitlementRepository;

/**
 * Placeholder store until offers and subscriptions land in M5.
 *
 * Defaults to no capabilities for an unknown tenant/product pair: absence of
 * a subscription must read as "may use nothing", never as "may use anything".
 */
final class InMemoryEntitlementRepository implements EntitlementRepository
{
    /**
     * @param array<string, list<string>> $capabilities keyed by "tenantId:productId"
     */
    public function __construct(private readonly array $capabilities)
    {
    }

    public function capabilitiesFor(string $tenantId, string $productId): array
    {
        return $this->capabilities[$tenantId . ':' . $productId] ?? [];
    }
}
