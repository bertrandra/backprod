<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * Member administration within one tenant.
 *
 * Every method takes the tenant and product from the caller's resolved
 * context, never from the request — so an administrator of one tenant cannot
 * reach another's members even by guessing ids (§31).
 *
 * A person is a member of the *tenant*, and the platform mirrors that
 * membership onto every product it has assigned to the tenant (ADR-047). So
 * the reads here answer for the product the caller is acting in, and the
 * writes — add, change roles, remove — act on every product the tenant
 * holds: the product the administrator was using when they typed a
 * colleague's address is not a decision about which products that colleague
 * may see.
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

    /**
     * Removes somebody from the tenant, across every product it holds.
     *
     * `$alsoApply` runs **inside** the same transaction, after the rows are
     * gone (2026-09-26), for the other thing leaving an organisation does:
     * giving up the places it was paying for. The two were two statements
     * until today, so a failure between them left a departed colleague
     * occupying a place with no way to free it — the removal that would have
     * freed it now answers MEMBER_NOT_FOUND.
     *
     * The same shape as `issue($alsoRecord)` and
     * `scheduleCancellation($alsoCharge)`: the caller opens nothing, because
     * no code in this repository nests a transaction.
     *
     * @param (callable(): void)|null $alsoApply
     */
    public function removeMember(string $tenantId, string $productId, string $userId, ?callable $alsoApply = null): void;

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
