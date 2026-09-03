<?php

declare(strict_types=1);

namespace App\Staff\Domain;

/**
 * What a platform role may do.
 *
 * A separate catalogue from the tenant permission codes, and the database
 * keeps them separate too: `platform_permissions` is the only table a staff
 * grant draws from, and its codes are constrained to the `staff.` and
 * `support.` namespaces. A tenant role cannot be granted one of these
 * because the row is not in the table its grants come from.
 */
final class StaffPermission
{
    public const SELF_READ = 'staff.self.read';
    public const TENANTS_READ = 'staff.tenants.read';
    public const ACCESS_LOG_READ = 'staff.access_log.read';
    public const SUPPORT_READ = 'support.read';
    public const SUPPORT_RESPOND = 'support.respond';
}
