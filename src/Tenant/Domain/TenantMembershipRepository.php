<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

interface TenantMembershipRepository
{
    /**
     * Every tenant this user belongs to within one product.
     *
     * The lookup is keyed by authenticated user and resolved product only —
     * there is deliberately no way to ask "is this user in tenant X", because
     * that shape invites passing a client-supplied tenant id straight through.
     *
     * @return list<TenantMembership>
     */
    public function findForUserAndProduct(string $userId, string $productId): array;
}
