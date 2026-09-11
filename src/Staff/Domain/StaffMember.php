<?php

declare(strict_types=1);

namespace App\Staff\Domain;

use DateTimeImmutable;

/**
 * One person on the platform's staff roster, as the console shows them.
 *
 * Carries the user's own identifying columns rather than only an id, because
 * the screen that lists staff is the screen that decides whether to revoke
 * somebody, and "revoke 8f3a…" is not a decision anybody can make. That the
 * roster therefore shows email addresses is the reason it sits behind
 * `staff.grant` rather than `staff.self.read`.
 *
 * `roles` is a list because `platform_staff` is keyed on (user, role): one
 * person may hold several, and the roster shows the person once with what
 * they hold rather than once per grant.
 */
final class StaffMember
{
    /**
     * @param list<string> $roles
     */
    public function __construct(
        public readonly string $userId,
        public readonly ?string $email,
        public readonly ?string $displayName,
        public readonly array $roles,
        public readonly DateTimeImmutable $grantedAt,
    ) {
    }

    public function isAdmin(): bool
    {
        return in_array(PlatformRole::PLATFORM_ADMIN, $this->roles, true);
    }
}
