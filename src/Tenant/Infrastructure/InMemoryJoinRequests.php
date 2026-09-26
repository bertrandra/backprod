<?php

declare(strict_types=1);

namespace App\Tenant\Infrastructure;

use App\Auth\Domain\JoinDecision;
use App\Tenant\Domain\JoinRequests;
use App\Tenant\Domain\TenantMember;

/**
 * Join requests for suites that run without a database: nobody is waiting,
 * every organisation admits by approval, and nobody administers anything.
 */
final class InMemoryJoinRequests implements JoinRequests
{
    /**
     * @param array<string, list<array{tenant_id: string, slug: string, name: string}>> $pending by user id
     * @param array<string, list<array{tenant_id: string, slug: string, name: string, default_product: string|null}>> $members by user id
     */
    public function __construct(private array $pending = [], private array $members = [])
    {
    }

    public function pendingFor(string $userId): array
    {
        return $this->pending[$userId] ?? [];
    }

    public function memberOf(string $userId): array
    {
        return $this->members[$userId] ?? [];
    }

    /** @return list<TenantMember> */
    public function pendingIn(string $tenantId): array
    {
        return [];
    }

    public function accept(string $tenantId, string $userId): bool
    {
        return false;
    }

    public function decline(string $tenantId, string $userId): bool
    {
        return false;
    }

    public function policyOf(string $tenantId): array
    {
        return ['policy' => JoinDecision::OPEN, 'domains' => []];
    }

    public function setPolicy(string $tenantId, string $policy, array $domains): void
    {
    }

    public function administratorsOf(string $tenantId): array
    {
        return [];
    }
}
