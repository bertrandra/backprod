<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * Who holds a platform role, and the two writes that change it.
 *
 * Separate from {@see StaffRepository}, which answers "what may *this* user
 * do" on every staff request. That question is asked constantly and by every
 * route; these are asked rarely, by one screen, under a permission of their
 * own. Keeping them apart means the hot authorisation path has no method on
 * it that could grant anything.
 */
interface StaffRoster
{
    /**
     * Everyone holding a platform role, with what they hold.
     *
     * @return list<StaffMember>
     */
    public function members(): array;

    /**
     * Grant a platform role.
     *
     * Idempotent: granting a role somebody already holds is the state the
     * caller asked for, and answering 409 would make a screen that re-sends
     * on a flaky connection look broken to the person using it.
     *
     * @throws \App\Shared\Exceptions\NotFoundException when no such user or role exists
     */
    public function grant(string $userId, string $roleCode, string $grantedBy): void;

    /**
     * Revoke a platform role.
     *
     * @throws \App\Shared\Exceptions\NotFoundException when the user does not hold it
     * @throws \App\Shared\Exceptions\ConflictException when it is the last PLATFORM_ADMIN
     */
    public function revoke(string $userId, string $roleCode): void;

    /**
     * The roles that may be granted, so the screen offers what exists rather
     * than a list compiled into it that the database can contradict.
     *
     * @return list<array{code: string, name: string}>
     */
    public function roles(): array;
}
