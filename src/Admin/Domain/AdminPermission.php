<?php

declare(strict_types=1);

namespace App\Admin\Domain;

/**
 * What an admin route may ask for.
 *
 * A third namespace in the same `platform_permissions` catalogue as
 * `staff.` and `support.`, not a second catalogue. /admin is a different
 * audience from /staff — operations and finance rather than support — but it
 * is the same identity model, and a separate table would be a second
 * authorisation path to get wrong rather than a boundary.
 *
 * The database keeps the namespaces honest: the code column is constrained
 * to these three prefixes, and tenant roles draw from a different table
 * entirely, so no tenant grant can name one of these.
 */
final class AdminPermission
{
    public const AUDIT_READ = 'admin.audit.read';

    /**
     * Held by FINANCE_ADMIN and SALES_ADMIN as well as PLATFORM_ADMIN, and
     * deliberately not by SUPPORT_ADMIN: support answers one customer's
     * question about their own account, which is not a reason to see every
     * customer's revenue.
     */
    public const FINANCE_READ = 'admin.finance.read';

    /**
     * Held by SUPPORT_ADMIN as well as PLATFORM_ADMIN, and for the opposite
     * reason to FINANCE_READ: "why has my export not arrived?" is a support
     * question whose honest answer is sometimes "the runner has not run since
     * Tuesday". The signal is timestamps and counts, never a customer's data.
     */
    public const HEALTH_READ = 'admin.health.read';

    /**
     * PLATFORM_ADMIN alone, and not SUPPORT_ADMIN. Support answers a
     * customer's questions; this destroys their identity irreversibly, and
     * the two are not the same authority however close the conversation that
     * leads to one is to the conversation that leads to the other.
     */
    public const PRIVACY_ERASE = 'admin.privacy.erase';
}
