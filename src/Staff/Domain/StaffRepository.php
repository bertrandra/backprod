<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * Where platform roles are held.
 *
 * Deliberately narrow: it answers "what does this user hold across the
 * platform" and nothing about tenants. Reading tenant data is a different
 * question, asked of a different repository, and audited.
 */
interface StaffRepository
{
    /**
     * The staff identity of a user, or null if they hold no platform role.
     *
     * Null rather than an empty identity: "not staff" and "staff with no
     * permissions" are different facts, and only the first should make a
     * staff route answer 403.
     */
    public function find(string $userId): ?StaffIdentity;
}
