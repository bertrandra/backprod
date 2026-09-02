<?php

declare(strict_types=1);

namespace App\Tenant\Domain;

/**
 * A member as shown in a tenant's member list: the person plus their roles.
 *
 * Distinct from TenantMembership, which answers "what is this request's
 * context". This answers "who is in this tenant", and so carries the identity
 * details an administrator needs to tell members apart.
 */
final class TenantMember
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly ?string $displayName,
        public readonly array $roles,
    ) {
    }
}
