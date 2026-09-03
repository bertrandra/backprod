<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entitlement\Domain\UsageSource;

/**
 * A usage source that reports a number decided by the test.
 */
final class FixedUsage implements UsageSource
{
    public function __construct(private readonly int $used)
    {
    }

    public function usage(string $tenantId, string $productId): int
    {
        return $this->used;
    }
}
