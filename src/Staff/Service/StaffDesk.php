<?php

declare(strict_types=1);

namespace App\Staff\Service;

use App\Shared\Exceptions\NotFoundException;
use App\Staff\Domain\AccessMotive;
use App\Staff\Domain\StaffAccess;
use App\Staff\Domain\StaffAccessEntry;
use App\Staff\Domain\StaffAccessLog;
use App\Staff\Domain\StaffIdentity;
use App\Staff\Domain\StaffPermission;
use App\Staff\Domain\TenantDirectory;
use App\Tenant\Domain\Tenant;

/**
 * What platform staff may do across tenants, and the trail it leaves.
 *
 * Every method that reads tenant data records the read. That pairing lives
 * here rather than in the controllers on purpose: a controller that forgets
 * to audit still compiles and still works, and the missing rows are only
 * noticed when somebody goes looking for them — which is exactly the moment
 * they are needed. Put the two together in the service and the only way to
 * read tenant data is to record having done so.
 *
 * Non-negotiable #21 is therefore enforced by there being no other route to
 * the data, not by a reviewer remembering to ask.
 */
final class StaffDesk
{
    public function __construct(
        private readonly TenantDirectory $tenants,
        private readonly StaffAccessLog $trail,
    ) {
    }

    /**
     * @return array{tenants: list<Tenant>, total: int, limit: int, offset: int}
     */
    public function tenants(StaffIdentity $staff, int $limit, int $offset): array
    {
        $tenants = $this->tenants->list($limit, $offset);

        // One row for the enumeration itself, naming no tenant: what happened
        // is "somebody listed the customers", and recording a row per result
        // would bury that fact under its own results.
        $this->trail->record(new StaffAccess(
            $staff->userId,
            null,
            null,
            'LIST',
            'tenant',
            null,
            StaffPermission::TENANTS_READ,
            ['returned' => count($tenants), 'limit' => $limit, 'offset' => $offset],
        ));

        return [
            'tenants' => $tenants,
            'total' => $this->tenants->count(),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * One tenant, and the reason somebody wanted it (R14).
     *
     * The motive is required here and not on `tenants()` above, and the line is
     * where the boundary actually is: listing customers reveals no customer's
     * data, while opening one does. A platform that demanded a ticket reference
     * to page through a list would teach its staff to type "support" into
     * everything, which is the failure mode R14 named.
     */
    public function tenant(StaffIdentity $staff, string $tenantId, AccessMotive $motive): Tenant
    {
        $tenant = $this->tenants->find($tenantId);

        if ($tenant === null) {
            // Recorded even so. An attempt to read a tenant that does not
            // exist is exactly the shape of somebody probing for ids, and a
            // trail that only holds successes cannot show it.
            $this->trail->record(new StaffAccess(
                $staff->userId,
                null,
                null,
                'READ_MISS',
                'tenant',
                $tenantId,
                StaffPermission::TENANTS_READ,
                [],
                // Recorded on the miss too. Somebody probing for ids is exactly
                // who would rather their stated reason were not kept.
                $motive,
            ));

            throw new NotFoundException('Tenant not found.', [], 'TENANT_NOT_FOUND');
        }

        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenant->id,
            null,
            'READ',
            'tenant',
            $tenant->id,
            StaffPermission::TENANTS_READ,
            [],
            $motive,
        ));

        return $tenant;
    }

    /**
     * @return array{entries: list<StaffAccessEntry>, total: int, limit: int, offset: int}
     */
    public function trail(StaffIdentity $staff, ?string $tenantId, int $limit, int $offset): array
    {
        $entries = $this->trail->recent($tenantId, $limit, $offset);

        // Reading the trail is itself a crossing and is itself recorded.
        // "Who has been looking at this customer?" is a question an auditor
        // asks; "who asked that?" is the one after it.
        $this->trail->record(new StaffAccess(
            $staff->userId,
            $tenantId,
            null,
            'LIST',
            'staff_access_log',
            null,
            StaffPermission::ACCESS_LOG_READ,
            ['returned' => count($entries)],
        ));

        return [
            'entries' => $entries,
            'total' => $this->trail->count($tenantId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
