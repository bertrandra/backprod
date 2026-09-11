<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Shared\Exceptions\ConflictException;
use App\Staff\Domain\PlatformRole;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffMember;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\StaffRoster;

/**
 * Appointing and removing platform staff.
 *
 * The same shape as {@see StaffDesk}: the write and the record of it are made
 * together here, so the only way to change who holds platform authority is a
 * way that leaves a trail. `platform_staff.granted_by` already names who
 * appointed somebody, but it dies with the row — a revoke would otherwise
 * erase the only evidence that the grant ever existed, which is precisely the
 * history worth keeping.
 *
 * **Reading the roster is not recorded**, and the asymmetry is deliberate.
 * Non-negotiable #21 is about crossing into a tenant's data; the staff list is
 * the platform's own. Recording every look at it would file thousands of rows
 * saying nothing next to the handful that say somebody was made an
 * administrator, which is how an audit trail stops being read.
 */
final class StaffAppointments
{
    public function __construct(
        private readonly StaffRoster $roster,
        private readonly StaffAccessLog $trail,
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

        $this->trail->record(new StaffAccess(
            $staff->userId,
            null,
            null,
            'GRANT',
            'platform_staff',
            $userId,
            StaffPermission::GRANT,
            ['role' => $roleCode],
        ));
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

        $this->trail->record(new StaffAccess(
            $staff->userId,
            null,
            null,
            'REVOKE',
            'platform_staff',
            $userId,
            StaffPermission::GRANT,
            ['role' => $roleCode],
        ));
    }
}
