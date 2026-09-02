<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\ForbiddenException;
use App\Tenant\Domain\TenantMembership;
use App\Tenant\Domain\TenantMembershipRepository;

/**
 * Derives the tenant for a request from membership (ADR-015).
 *
 * The returned tenant id always comes from a membership record. The optional
 * selection argument narrows *among already-authorised* memberships; it is
 * never the source of the id. A selection naming a tenant the user does not
 * belong to is rejected exactly as an unknown tenant would be, so the two are
 * indistinguishable to a caller probing for tenant existence.
 */
final class TenantResolver
{
    public const SELECTION_HEADER = 'X-Tenant';

    public function __construct(private readonly TenantMembershipRepository $memberships)
    {
    }

    public function resolve(string $userId, string $productId, string $selected): TenantMembership
    {
        $memberships = $this->memberships->findForUserAndProduct($userId, $productId);

        if ($memberships === []) {
            throw ForbiddenException::noTenantAccess();
        }

        if ($selected !== '') {
            foreach ($memberships as $membership) {
                if ($membership->tenantId === $selected) {
                    return $membership;
                }
            }

            throw ForbiddenException::noTenantAccess();
        }

        if (count($memberships) === 1) {
            return $memberships[0];
        }

        // Picking the first would make which tenant a write lands in depend on
        // row order, so the caller is asked to choose from tenants it already
        // has access to — listing them discloses nothing new to this user.
        throw new ConflictException(
            'TENANT_SELECTION_REQUIRED',
            sprintf('Several tenants are available; name one with the %s header.', self::SELECTION_HEADER),
            ['tenants' => array_map(static fn (TenantMembership $m): string => $m->tenantId, $memberships)],
        );
    }
}
