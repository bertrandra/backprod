<?php

declare(strict_types=1);

namespace App\Commerce\Service;

use App\Entitlement\Domain\Entitlement;
use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Domain\UsageMeter;

/**
 * What a tenant may use, and how much of it they are using.
 *
 * The usage half exists to be honest about the gap between the two. An offer
 * can grant a quota nothing counts yet — max_storage before storage exists —
 * and reporting that as "0 used" would tell a customer their limit is being
 * enforced when nothing is enforcing it. Unmetered says unmetered.
 */
final class TenantEntitlements
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly UsageMeter $meter,
    ) {
    }

    /**
     * @return list<Entitlement>
     */
    public function all(string $tenantId, string $productId, ?string $userId = null): array
    {
        return $this->entitlements->entitlementsFor($tenantId, $productId, $userId);
    }

    /**
     * One row per quota, and **it names the caller since 2026-10-01**.
     *
     * Boolean capabilities are absent: there is nothing to count, and a usage
     * line for them would be noise.
     *
     * The tenant-wide question — naming nobody — asks what the *organisation*
     * bought, and since ADR-055 the answer is nothing: every subscription is a
     * seat. So this read answered with an empty list, and the Usage section of
     * the Organisation screen showed nothing to a customer whose quota is being
     * enforced.
     *
     * Resolved for the person, measured tenant-wide — exactly what
     * {@see \App\Entitlement\Domain\QuotaPolicy::assertMayConsume()} does, and
     * for the reason §13.1 gives: both gates have to ask about the same person,
     * or the screen shows a limit the refusal does not use.
     *
     * @return list<array<string, mixed>>
     */
    public function usage(string $tenantId, string $productId, ?string $userId = null): array
    {
        $usage = [];

        foreach ($this->all($tenantId, $productId, $userId) as $entitlement) {
            if (!$entitlement->isQuota()) {
                continue;
            }

            $used = $this->meter->usage($entitlement->featureCode, $tenantId, $productId);
            $limit = $entitlement->limit;

            $usage[] = [
                'feature' => $entitlement->featureCode,
                'name' => $entitlement->featureName,
                'unit' => $entitlement->unit,
                'limit' => $limit,
                'unlimited' => $entitlement->isUnlimited(),
                // Whether anything actually counts this. False means the
                // limit beside it is recorded but not enforced.
                'metered' => $this->meter->measures($entitlement->featureCode),
                'used' => $used,
                // Null when unlimited or unmetered — in both cases there is
                // no number to give, and zero would be a lie.
                'remaining' => $limit === null || $used === null ? null : max(0, $limit - $used),
            ];
        }

        return $usage;
    }
}
