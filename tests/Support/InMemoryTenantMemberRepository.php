<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenant\Domain\TenantMember;
use App\Tenant\Domain\TenantMemberRepository;

/**
 * Member storage without a database, so the invariants in
 * MemberAdministration can be tested without one.
 *
 * The Postgres implementation is covered separately against a real database;
 * this stands in only for the parts those tests are not about.
 */
final class InMemoryTenantMemberRepository implements TenantMemberRepository
{
    /** @var array<string, TenantMember> */
    private array $members = [];

    /**
     * @param list<TenantMember> $members
     */
    public function __construct(array $members = [])
    {
        foreach ($members as $member) {
            $this->members[$member->userId] = $member;
        }
    }

    public function listMembers(string $tenantId, string $productId): array
    {
        return array_values($this->members);
    }

    public function findMember(string $tenantId, string $productId, string $userId): ?TenantMember
    {
        return $this->members[$userId] ?? null;
    }

    public function addMember(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        $existing = $this->members[$userId] ?? null;

        $this->members[$userId] = new TenantMember(
            $userId,
            $existing?->email,
            $existing?->displayName,
            $roleCodes,
        );
    }

    public function replaceRoles(string $tenantId, string $productId, string $userId, array $roleCodes): void
    {
        $existing = $this->members[$userId] ?? null;

        if ($existing === null) {
            return;
        }

        $this->members[$userId] = new TenantMember(
            $existing->userId,
            $existing->email,
            $existing->displayName,
            $roleCodes,
        );
    }

    public function removeMember(string $tenantId, string $productId, string $userId): void
    {
        unset($this->members[$userId]);
    }

    public function knownRoleCodes(): array
    {
        return ['TENANT_ADMIN', 'USER'];
    }

    public function countMembersWithRole(string $tenantId, string $productId, string $roleCode): int
    {
        $count = 0;

        foreach ($this->members as $member) {
            if (in_array($roleCode, $member->roles, true)) {
                ++$count;
            }
        }

        return $count;
    }
}
