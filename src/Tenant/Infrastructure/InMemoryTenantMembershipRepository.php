<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;

/**
 * Placeholder store until `tenant_members` lands in M2.
 */
final class InMemoryTenantMembershipRepository implements TenantMembershipRepository
{
    /**
     * @param list<TenantMembership> $memberships
     */
    public function __construct(private readonly array $memberships)
    {
    }

    public function findForUserAndProduct(string $userId, string $productId): array
    {
        $matching = [];

        foreach ($this->memberships as $membership) {
            if ($membership->userId === $userId && $membership->productId === $productId) {
                $matching[] = $membership;
            }
        }

        return $matching;
    }
}
