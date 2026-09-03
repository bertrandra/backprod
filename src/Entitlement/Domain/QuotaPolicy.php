<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

use App\Shared\Exceptions\ForbiddenException;

/**
 * Whether a tenant may consume one more of something (§13).
 *
 * Three refusals live here and they are not interchangeable:
 *
 *   no entitlement   ENTITLEMENT_REQUIRED  — buy it
 *   quota exhausted  QUOTA_EXCEEDED        — upgrade, or delete something
 *   allowed          nothing happens
 *
 * Quotas are enforced against measured usage, never against a counter the
 * platform keeps separately. A counter drifts the first time a delete fails
 * halfway, and a quota enforced from a drifted counter is worse than one not
 * enforced at all: it refuses a customer who is within their allowance and
 * has no way to prove it.
 */
final class QuotaPolicy
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly UsageMeter $usage,
    ) {
    }

    /**
     * Refuses unless the tenant may consume one more.
     *
     * An unmetered quota passes. That is deliberate and visible: until
     * something counts a feature, enforcing a limit on it would mean
     * refusing at zero or at a number nobody measured, and the usage
     * endpoint reports the gap rather than implying enforcement.
     */
    public function assertMayConsume(string $tenantId, string $productId, string $featureCode): void
    {
        $entitlement = $this->find($tenantId, $productId, $featureCode);

        if ($entitlement === null) {
            throw ForbiddenException::entitlementRequired($featureCode);
        }

        if (!$entitlement->isQuota() || $entitlement->isUnlimited()) {
            return;
        }

        $limit = $entitlement->limit;
        $used = $this->usage->usage($featureCode, $tenantId, $productId);

        if ($limit === null || $used === null) {
            return;
        }

        // One *more*, so the comparison is against the count after this
        // request rather than before it: at limit 3 with 3 in hand, the
        // fourth is refused, and `$used > $limit` would have allowed it.
        if ($used >= $limit) {
            throw ForbiddenException::quotaExceeded($featureCode, $limit, $used);
        }
    }

    private function find(string $tenantId, string $productId, string $featureCode): ?Entitlement
    {
        foreach ($this->entitlements->entitlementsFor($tenantId, $productId) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement;
            }
        }

        return null;
    }
}
