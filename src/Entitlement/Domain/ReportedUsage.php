<?php

declare(strict_types=1);

namespace App\Entitlement\Domain;

/**
 * Usage the platform did not count itself but was told (ADR-051 §4): a
 * product beside the platform metering a feature only it can see. The
 * meter asks here for any feature it has no source of its own for, so a
 * quota sold on such a feature is enforced the same way as one the
 * platform counts — and reported as unmetered until the first report,
 * rather than as zero.
 */
interface ReportedUsage
{
    /** Whether anything has ever been reported for this feature — the reader's "metered" flag. */
    public function measures(string $featureCode): bool;

    /** The level: the sum of the deltas reported for this tenant and product. Null when nothing was ever reported. */
    public function usage(string $featureCode, string $tenantId, string $productId): ?int;
}
