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
}
