<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * A platform staff member: who they are, and what that lets them do across
 * tenants.
 *
 * Note what this does *not* carry: a tenant id. That absence is the design.
 * A handler holding a staff identity cannot read a tenant from it and act,
 * the way a tenant handler reads one from {@see \App\Shared\Context\RequestContext};
 * it has to be given a tenant explicitly, which is what makes the access
 * something the handler must justify and audit rather than something it
 * inherits.
 *
 * The same reasoning separates IdentityContext from RequestContext: if you
 * need a tenant, the type should say so.
 */
final class StaffIdentity
{
    /**
     * @param list<string> $roles
     * @param list<string> $permissions
     * @param ?string      $email       null once erased (§26); the identity
     *                                  outlives the person's details so the
     *                                  access log keeps naming a user id
     * @param ?string      $displayName what the shell shows next to the
     *                                  initial in the account menu
     */
    public function __construct(
        public readonly string $userId,
        public readonly array $roles,
        public readonly array $permissions,
        public readonly ?string $email = null,
        public readonly ?string $displayName = null,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }
}
