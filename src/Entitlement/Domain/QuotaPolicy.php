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
     *
     * $userId names whose seats count, and it has to be the same person the
     * capability chain resolved (§13.1). Asking the tenant-wide question here
     * while the chain asked a personal one is the worst of both: the
     * capability check lets a seat holder through and this one then tells
     * them they need the entitlement they are holding.
     *
     * Where a seat and the tenant both grant the feature, the repository
     * already returns the more generous — the same rule that settles a
     * negotiated override against a subscription grant. Usage stays measured
     * tenant-wide, because that is what the feature actually counts.
     */
    public function assertMayConsume(
        string $tenantId,
        string $productId,
        string $featureCode,
        ?string $userId = null,
    ): void {
        $entitlement = $this->find($tenantId, $productId, $featureCode, $userId);

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

    private function find(
        string $tenantId,
        string $productId,
        string $featureCode,
        ?string $userId,
    ): ?Entitlement {
        foreach ($this->entitlements->entitlementsFor($tenantId, $productId, $userId) as $entitlement) {
            if ($entitlement->featureCode === $featureCode) {
                return $entitlement;
            }
        }

        return null;
    }
}
