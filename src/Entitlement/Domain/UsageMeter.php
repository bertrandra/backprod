<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

/**
 * Which quotas can actually be measured, and what they currently read.
 *
 * Not every quota an offer can grant has something counting it yet —
 * max_storage is meaningless until storage exists in M7. That gap is
 * reported rather than hidden: an unmetered quota is visibly unmetered, so
 * nobody reads a usage endpoint and concludes a limit is being enforced when
 * nothing is enforcing it.
 */
final class UsageMeter
{
    /**
     * @param array<string, UsageSource> $sources keyed by feature code
     */
    public function __construct(private readonly array $sources = [])
    {
    }

    public function measures(string $featureCode): bool
    {
        return isset($this->sources[$featureCode]);
    }

    /**
     * Null when nothing counts this feature — distinct from zero, which is a
     * measurement.
     */
    public function usage(string $featureCode, string $tenantId, string $productId): ?int
    {
        // `?->` guards a null value, not a missing key: without the lookup
        // first, an unmetered feature is an undefined-offset warning rather
        // than the null this promises.
        $source = $this->sources[$featureCode] ?? null;

        return $source?->usage($tenantId, $productId);
    }
}
