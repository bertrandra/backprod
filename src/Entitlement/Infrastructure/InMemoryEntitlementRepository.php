<?php

declare(strict_types=1);

namespace App\Entitlement\Infrastructure;

use App\Entitlement\Domain\Entitlement;
use App\Entitlement\Domain\EntitlementRepository;

/**
 * Entitlements without a database, for tests about the request pipeline.
 *
 * Defaults to nothing for an unknown tenant/product pair: absence of a
 * subscription must read as "may use nothing", never as "may use anything".
 *
 * It holds Entitlement objects rather than bare codes, because the two
 * questions the port answers are the same set seen at different depths, and
 * a double that stored only codes could not answer the second one honestly.
 */
final class InMemoryEntitlementRepository implements EntitlementRepository
{
    /**
     * @param array<string, list<Entitlement>> $entitlements keyed by "tenantId:productId"
     */
    public function __construct(private readonly array $entitlements = [])
    {
    }

    /**
     * Builds a repository from plain capability codes, for the many tests
     * that care only about which capabilities are held.
     *
     * @param array<string, list<string>> $capabilities keyed by "tenantId:productId"
     */
    public static function granting(array $capabilities): self
    {
        $entitlements = [];

        foreach ($capabilities as $key => $codes) {
            $entitlements[$key] = array_map(
                static fn (string $code): Entitlement => new Entitlement(
                    $code,
                    $code,
                    Entitlement::BOOLEAN,
                    null,
                    null,
                    'SUBSCRIPTION',
                    null,
                ),
                $codes,
            );
        }

        return new self($entitlements);
    }

    public function capabilitiesFor(string $tenantId, string $productId, ?string $userId = null): array
    {
        return array_map(
            static fn (Entitlement $entitlement): string => $entitlement->featureCode,
            $this->entitlementsFor($tenantId, $productId, $userId),
        );
    }

    /**
     * $userId is accepted and ignored: this double holds entitlements by
     * tenant and product with no subscription behind them, so it has nothing
     * to tell a seat from a tenant grant. Tests that care about seats run
     * against PostgreSQL, where the distinction is a real column.
     */
    public function entitlementsFor(string $tenantId, string $productId, ?string $userId = null): array
    {
        return $this->entitlements[$tenantId . ':' . $productId] ?? [];
    }

    /**
     * Covered wherever this double grants anything at all (2026-09-25).
     *
     * It holds entitlements with no subscription behind them, so it cannot
     * tell the owner of one from somebody added to it — the same reason
     * `entitlementsFor()` ignores the person. What it *can* keep honest is
     * the other half: a tenant and product this double grants nothing for
     * covers nobody, so a pipeline test still cannot reach a workspace with
     * no subscription at all.
     *
     * Who is on which subscription is a real column, and the tests about it
     * run against PostgreSQL (`SubscriptionCoverageTest`).
     */
    public function covers(string $tenantId, string $productId, string $userId): bool
    {
        return $this->entitlementsFor($tenantId, $productId, $userId) !== [];
    }
}
