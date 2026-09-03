<?php

declare(strict_types=1);

namespace App\Project\Infrastructure;

use App\Entitlement\Domain\UsageSource;
use App\Project\Domain\ProjectRepository;

/**
 * How many projects a tenant currently has.
 *
 * It lives in the project module and implements the entitlement module's
 * port, so the direction of knowledge is right: commerce never learns what a
 * project is, and the count comes from the same repository the endpoints
 * read, not from a counter kept alongside it.
 *
 * That matters more than it looks. A separate counter drifts the first time
 * a delete half-fails, and a quota enforced from a drifted counter refuses a
 * customer who is within their allowance and cannot prove it.
 */
final class ProjectUsageSource implements UsageSource
{
    public function __construct(private readonly ProjectRepository $projects)
    {
    }

    public function usage(string $tenantId, string $productId): int
    {
        return $this->projects->countForTenant($tenantId, $productId);
    }
}
