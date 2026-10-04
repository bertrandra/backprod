<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Shared\Exceptions\ConflictException;
use App\Staff\Domain\PlatformRole;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\StaffRoster;

/**
 * Appointing and removing platform staff. `platform_staff.granted_by` names
 * who appointed somebody.
 */
final class StaffAppointments
{
    public function __construct(
        private readonly StaffRoster $roster,
    ) {
    }

    /**
     * @return array{members: list<StaffMember>, roles: list<array{code: string, name: string}>}
     */
    public function roster(): array
    {
        return [
            'members' => $this->roster->members(),
            'roles' => $this->roster->roles(),
        ];
    }

    public function grant(StaffIdentity $staff, string $userId, string $roleCode): void
    {
        $this->roster->grant($userId, $roleCode, $staff->userId);
    }

    public function revoke(StaffIdentity $staff, string $userId, string $roleCode): void
    {
        // Refused before the database is asked, because "you may not remove
        // your own administrator role" and "the platform must keep one" are
        // different rules with different remedies. The second is the schema's
        // and answers "grant it to somebody else first"; this one answers
        // "ask a colleague", and letting the trigger speak for both would give
        // a lone administrator advice that cannot work.
        if ($userId === $staff->userId && $roleCode === PlatformRole::PLATFORM_ADMIN) {
            throw new ConflictException(
                'CANNOT_REVOKE_OWN_ADMIN',
                'You cannot remove your own administrator role. Another administrator can.',
                ['user_id' => $userId],
            );
        }

        $this->roster->revoke($userId, $roleCode);
    }
}
