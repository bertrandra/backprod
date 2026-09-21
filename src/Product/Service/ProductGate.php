<?php

declare(strict_types=1);

namespace App\Product\Service;

use App\Entitlement\Domain\EntitlementRepository;
use App\Entitlement\Domain\UsageMeter;
use App\Product\Domain\ProductAccess;
use App\Product\Domain\ProductAccessLog;
use App\Product\Domain\ProductKey;
use App\Product\Domain\ProductUsageLedger;
use App\Product\Domain\TenantHoldings;
use App\Shared\Exceptions\ForbiddenException;
use App\Shared\Exceptions\NotFoundException;
use DateTimeImmutable;

/**
 * What every product route does before its own work (ADR-051 §4): checks
 * the key's scope, checks that the tenant asked for holds this product, and
 * writes the crossing to the product access log — refusals included, because
 * a run of refusals is what probing looks like and a log of successes cannot
 * show it.
 */
final class ProductGate
{
    public function __construct(
        private readonly TenantHoldings $holdings,
        private readonly ProductAccessLog $log,
        private readonly EntitlementRepository $entitlements,
        private readonly UsageMeter $meter,
        private readonly ProductUsageLedger $ledger,
    ) {
    }

    /**
     * The tenant a product route may read, or a recorded refusal.
     *
     * A tenant that does not hold this product is *absent* — 404, the same
     * non-answer a person's routes give — so a key cannot enumerate the
     * platform's customers by asking about ids.
     */
    public function tenant(ProductKey $key, string $scope, string $askedFor, string $method, string $path): string
    {
        if (!$key->allows($scope)) {
            $this->log->record(new ProductAccess($key->id, $key->productId, null, $askedFor, $method, $path, 403));

            throw new ForbiddenException('PRODUCT_KEY_SCOPE', 'This key does not hold the scope this route needs.', ['scope' => $scope]);
        }

        // The holdings adapter treats an id that is not a uuid as not held,
        // so a malformed id and a foreign tenant get the same non-answer.
        if (!$this->holdings->holds($askedFor, $key->productId)) {
            $this->log->record(new ProductAccess($key->id, $key->productId, null, $askedFor, $method, $path, 404));

            throw new NotFoundException('No such organisation.', [], 'TENANT_NOT_FOUND');
        }

        $this->log->record(new ProductAccess($key->id, $key->productId, $askedFor, $askedFor, $method, $path, 200));

        return $askedFor;
    }

    /**
     * Records what a product metered, unless the quota does not cover it.
     *
     * Asked *before* the product does the work, so a refusal costs nothing:
     * the delta is checked against the level the meter reads now — what the
     * platform counts itself, or what was reported so far — and against the
     * limit the entitlement carries. A feature the tenant holds no
     * entitlement for is a limit of nothing. Unlimited records without
     * counting.
     *
     * @return array{recorded: bool, feature: string, used: ?int, limit: ?int, unlimited: bool}
     */
    public function reportUsage(
        ProductKey $key,
        string $tenantId,
        string $feature,
        int $quantity,
        string $idempotencyKey,
        ?DateTimeImmutable $periodStart,
        ?DateTimeImmutable $periodEnd,
    ): array {
        $productId = $key->productId;
        $entitlement = null;

        foreach ($this->entitlements->entitlementsFor($tenantId, $productId) as $candidate) {
            if ($candidate->featureCode === $feature && $candidate->isQuota()) {
                $entitlement = $candidate;

                break;
            }
        }

        if ($entitlement === null) {
            throw ForbiddenException::quotaExceeded($feature, 0, 0);
        }

        $used = $this->meter->usage($feature, $tenantId, $productId) ?? 0;

        if (!$entitlement->isUnlimited()) {
            $limit = $entitlement->limit ?? 0;

            if ($quantity > 0 && $used + $quantity > $limit) {
                throw ForbiddenException::quotaExceeded($feature, $limit, $used);
            }
        }

        $recorded = $this->ledger->record(
            $tenantId,
            $productId,
            $key->id,
            $feature,
            $quantity,
            $idempotencyKey,
            $periodStart,
            $periodEnd,
        );

        return [
            'recorded' => $recorded,
            'feature' => $feature,
            'used' => $this->meter->usage($feature, $tenantId, $productId),
            'limit' => $entitlement->isUnlimited() ? null : $entitlement->limit,
            'unlimited' => $entitlement->isUnlimited(),
        ];
    }
}
