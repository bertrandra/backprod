<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * The platform's operational listings (§7's `/admin` block, non-negotiable #19).
 *
 * Cross-tenant by definition — that is what makes these admin surfaces rather
 * than tenant ones, and what makes the boundary matter. Every method here
 * returns *records about* tenant data and never tenant data itself: which
 * subscriptions exist and what they are worth, not what is inside anybody's
 * project; who a tenant's members are, not what they wrote to each other.
 *
 * The rows are arrays rather than domain objects on purpose. Each of these is
 * a read model assembled from several tables for one screen; turning them into
 * Subscription or Invoice objects would promise behaviour that is not there
 * and would drag the aggregates of four modules into the admin surface.
 */
interface AdminDirectory
{
    /**
     * Tenants with their operational standing: how many members, how many
     * live subscriptions, what is unpaid.
     *
     * @return DirectoryPage<array<string, mixed>>
     */
    public function tenants(?string $search, int $limit, int $offset): DirectoryPage;

    /**
     * People, across every tenant.
     *
     * The one admin listing that returns personal data, which is why
     * PLATFORM_ADMIN alone may call it. An erased person appears — the row
     * survives erasure by design (#14, #15) — carrying `erased_at` and no
     * identity, because the alternative is a directory that silently
     * disagrees with the count beside it.
     *
     * @return DirectoryPage<array<string, mixed>>
     */
    public function users(?string $search, int $limit, int $offset): DirectoryPage;

    /**
     * @return DirectoryPage<array<string, mixed>>
     */
    public function subscriptions(?string $tenantId, ?string $status, int $limit, int $offset): DirectoryPage;

    /**
     * @return DirectoryPage<array<string, mixed>>
     */
    public function invoices(?string $tenantId, ?string $status, int $limit, int $offset): DirectoryPage;

    /**
     * @return DirectoryPage<array<string, mixed>>
     */
    public function jobs(?string $status, ?string $type, int $limit, int $offset): DirectoryPage;
}
