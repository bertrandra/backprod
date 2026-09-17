<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use App\Tenant\Domain\Tenant;

/**
 * Reading tenants without belonging to one.
 *
 * A port of its own rather than extra methods on `TenantRepository`, and the
 * reason is the reason for most of §12.2: an unscoped `listAll()` sitting on
 * the repository every tenant-scoped service already injects is an accident
 * waiting to be typed. Here, the only code that can enumerate tenants is code
 * that asked for this interface — and everything that asks for it is behind a
 * staff route and writes to the trail.
 */
interface TenantDirectory
{
    /**
     * @return list<Tenant>
     */
    public function list(int $limit, int $offset): array;

    public function count(): int;

    public function find(string $tenantId): ?Tenant;

    /**
     * Lend the platform's catalogue to a tenant, or take it back.
     *
     * The one write on this port, and it belongs here for the same reason the
     * reads do: only code that asked for this interface can reach it, and
     * everything that asks for it sits behind a staff route that records what
     * it did.
     *
     * Returns the tenant as it now stands, or null when there is no such
     * tenant — a missing row and a row already in the asked-for state are
     * different answers, and only the first is a 404.
     */
    public function setOfferAuthoring(string $tenantId, bool $mayAuthor): ?Tenant;

    /**
     * Makes an organisation (2026-09-17): the platform administrator's act,
     * since sign-up stopped making them. Null when the slug is taken — a
     * slug is an address, and two organisations cannot share one.
     */
    public function create(string $name, string $slug): ?Tenant;

    public function rename(string $tenantId, string $name): ?Tenant;

    /**
     * Moves an organisation's address. Null when the slug is taken or the
     * tenant unknown. The caller decides whether it may move at all: an
     * organisation with an invoice has links in the world that would die.
     */
    public function reslug(string $tenantId, string $slug): ?Tenant;

    public function hasInvoices(string $tenantId): bool;
}
