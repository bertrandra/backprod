<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * Member administration within one tenant and product.
 *
 * Every method takes the tenant and product from the caller's resolved
 * context, never from the request — so an administrator of one tenant cannot
 * reach another's members even by guessing ids (§31).
 */
interface TenantMemberRepository
{
    /**
     * @return list<TenantMember>
     */
    public function listMembers(string $tenantId, string $productId): array;

    public function findMember(string $tenantId, string $productId, string $userId): ?TenantMember;

    /**
     * @param list<string> $roleCodes
     */
    public function addMember(string $tenantId, string $productId, string $userId, array $roleCodes): void;

    /**
     * Replaces the member's roles outright rather than merging, so a PATCH
     * that omits a role removes it and the request body is the whole truth.
     *
     * @param list<string> $roleCodes
     */
    public function replaceRoles(string $tenantId, string $productId, string $userId, array $roleCodes): void;

    public function removeMember(string $tenantId, string $productId, string $userId): void;

    /**
     * @return list<string>
     */
    public function knownRoleCodes(): array;

    /**
     * How many members hold a role in this tenant and product — used to stop
     * a tenant removing its last administrator.
     */
    public function countMembersWithRole(string $tenantId, string $productId, string $roleCode): int;
}
