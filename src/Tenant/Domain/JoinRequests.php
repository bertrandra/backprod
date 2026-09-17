<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * People who asked to join an organisation and are waiting (2026-09-17),
 * and the policy that decides who waits.
 *
 * A PENDING membership is not a membership: the resolver and the products
 * list ignore it. What it is, is a row on the administrator's list, and a
 * name the person can be told they are waiting on.
 */
interface JoinRequests
{
    /**
     * Organisations this person has asked to join and is still waiting on.
     *
     * @return list<array{tenant_id: string, slug: string, name: string}>
     */
    public function pendingFor(string $userId): array;

    /**
     * The people waiting on this organisation, oldest first.
     *
     * @return list<TenantMember>
     */
    public function pendingIn(string $tenantId): array;

    /** Live, on every product the organisation holds. False if nobody waited. */
    public function accept(string $tenantId, string $userId): bool;

    /** Gone, roles and all. False if nobody waited. */
    public function decline(string $tenantId, string $userId): bool;

    /**
     * @return array{policy: string, domains: list<string>}
     */
    public function policyOf(string $tenantId): array;

    /**
     * @param list<string> $domains lowercase, replacing the list
     */
    public function setPolicy(string $tenantId, string $policy, array $domains): void;

    /**
     * The administrators to tell when somebody asks — every TENANT_ADMIN of
     * the organisation, once, whichever products they hold it on.
     *
     * @return list<string> user ids
     */
    public function administratorsOf(string $tenantId): array;
}
