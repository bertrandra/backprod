<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Entitlement\Domain\UsageSource;
use App\Tenant\Domain\TenantMemberRepository;

/**
 * How many people a tenant has in a product.
 *
 * Counted from the membership rows themselves, for the same reason projects
 * are: the number a quota is enforced against has to be the number that is
 * actually true.
 */
final class MemberUsageSource implements UsageSource
{
    /**
     * The entitlement that says how many people a tenant may have.
     */
    public const QUOTA = 'max_users';

    public function __construct(private readonly TenantMemberRepository $members)
    {
    }

    public function usage(string $tenantId, string $productId): int
    {
        return count($this->members->listMembers($tenantId, $productId));
    }
}
