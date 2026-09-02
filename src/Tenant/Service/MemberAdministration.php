<?php

declare(strict_types=1);

namespace App\Tenant\Service;

use App\Shared\Exceptions\BadRequestException;
use App\Shared\Exceptions\ConflictException;
use App\Shared\Exceptions\NotFoundException;
use App\Tenant\Domain\TenantMember;
use App\Tenant\Domain\TenantMemberRepository;
use App\User\Domain\UserRepository;

/**
 * Member administration for one tenant and product.
 *
 * The invariants live here rather than in the controllers, so they hold
 * however a member is changed.
 */
final class MemberAdministration
{
    public const ADMIN_ROLE = 'TENANT_ADMIN';

    public function __construct(
        private readonly TenantMemberRepository $members,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return list<TenantMember>
     */
    public function list(string $tenantId, string $productId): array
    {
        return $this->members->listMembers($tenantId, $productId);
    }

    /**
     * Adds an existing platform user by email address.
     *
     * A person who has never signed in has no user record yet (ADR-017), so
     * they cannot be added: inviting someone who does not exist is a separate
     * concern with its own lifecycle, and pretending otherwise would create
     * half a user with no way to finish.
     *
     * @param list<string> $roleCodes
     */
    public function add(string $tenantId, string $productId, string $email, array $roleCodes): TenantMember
    {
        $this->assertRolesAreKnown($roleCodes);

        $candidates = $this->users->findByEmail($email);

        if ($candidates === []) {
            throw new NotFoundException(
                'No platform user has that email address.',
                ['email' => $email],
                'USER_NOT_FOUND',
            );
        }

        // Email is not unique (ADR-017), so an ambiguous match is refused
        // rather than resolved by guessing which person was meant.
        if (count($candidates) > 1) {
            throw new ConflictException(
                'AMBIGUOUS_USER',
                'Several users share that email address; add the member by id instead.',
                ['email' => $email],
            );
        }

        $user = $candidates[0];

        if ($this->members->findMember($tenantId, $productId, $user->id) !== null) {
            throw new ConflictException(
                'ALREADY_A_MEMBER',
                'That user is already a member of this tenant for this product.',
            );
        }

        $this->members->addMember($tenantId, $productId, $user->id, $roleCodes);

        return $this->requireMember($tenantId, $productId, $user->id);
    }

    /**
     * @param list<string> $roleCodes
     */
    public function replaceRoles(string $tenantId, string $productId, string $userId, array $roleCodes): TenantMember
    {
        $this->assertRolesAreKnown($roleCodes);

        $member = $this->requireMember($tenantId, $productId, $userId);

        $losesAdmin = in_array(self::ADMIN_ROLE, $member->roles, true)
            && !in_array(self::ADMIN_ROLE, $roleCodes, true);

        if ($losesAdmin) {
            $this->assertNotTheLastAdministrator($tenantId, $productId);
        }

        $this->members->replaceRoles($tenantId, $productId, $userId, $roleCodes);

        return $this->requireMember($tenantId, $productId, $userId);
    }

    public function remove(string $tenantId, string $productId, string $userId): void
    {
        $member = $this->requireMember($tenantId, $productId, $userId);

        if (in_array(self::ADMIN_ROLE, $member->roles, true)) {
            $this->assertNotTheLastAdministrator($tenantId, $productId);
        }

        $this->members->removeMember($tenantId, $productId, $userId);
    }

    /**
     * A tenant with no administrator cannot appoint one, so it would be
     * permanently stuck. Refusing the last removal is cheaper than the
     * support ticket it prevents.
     */
    private function assertNotTheLastAdministrator(string $tenantId, string $productId): void
    {
        if ($this->members->countMembersWithRole($tenantId, $productId, self::ADMIN_ROLE) <= 1) {
            throw new ConflictException(
                'LAST_ADMINISTRATOR',
                'A tenant must keep at least one administrator.',
            );
        }
    }

    /**
     * @param list<string> $roleCodes
     */
    private function assertRolesAreKnown(array $roleCodes): void
    {
        $known = $this->members->knownRoleCodes();
        $unknown = array_values(array_diff($roleCodes, $known));

        if ($unknown !== []) {
            throw new BadRequestException(
                'UNKNOWN_ROLE',
                'One or more roles do not exist.',
                ['unknown' => $unknown, 'known' => $known],
            );
        }
    }

    private function requireMember(string $tenantId, string $productId, string $userId): TenantMember
    {
        $member = $this->members->findMember($tenantId, $productId, $userId);

        if ($member === null) {
            // Scoped to the caller's tenant, so this is also what a member of
            // another tenant looks like: not found, never "exists elsewhere".
            throw new NotFoundException('No such member in this tenant.', [], 'MEMBER_NOT_FOUND');
        }

        return $member;
    }
}
