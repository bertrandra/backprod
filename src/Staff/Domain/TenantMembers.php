<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * Who belongs to a tenant, as the platform sees it.
 *
 * A separate port from the tenant's own {@see \App\Tenant\Domain\TenantMemberRepository}
 * because the question is different. A tenant administrator asks "who is in
 * *my* product?" and gets one product's membership. The platform asks "who
 * is in this tenant at all?" across every product it holds (ADR-047), and
 * gets each person once with the products they are on — the shape a console
 * reads rather than the one a membership screen edits. Reading only: the
 * console changes no membership; that is the tenant administrator's job and
 * non-negotiable #22's line.
 */
interface TenantMembers
{
    /**
     * Every member of the tenant, once each, with their roles and the codes
     * of the products they are a member on — narrowed to one product when
     * `$productId` is given.
     *
     * @return list<TenantMemberAcrossProducts>
     */
    public function of(string $tenantId, ?string $productId): array;
}
