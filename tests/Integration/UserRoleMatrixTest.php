<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;

/**
 * What a USER may and may not do (docs/tenant-roots.md §2.6,
 * docs/identities-and-permissions.md).
 *
 * Since 2026-09-17 everybody who arrives by themselves is a USER of the
 * organisation at the root, so this is the matrix a stranger ends up with.
 * A USER sees — the catalogue, the invoices, the payments, the orders — and
 * cannot bind the organisation: no checkout, no refund, no credit note, no
 * invoice issued, no member added, no period closed. The reads are in the
 * migrations and so are the refusals; this holds both to the words in the
 * documentation, so a migration that quietly lent `billing.manage` to USER
 * fails here rather than at a customer's bank.
 */
#[CoversNothing]
final class UserRoleMatrixTest extends DatabaseTestCase
{
    /** The acts that bind the organisation, and which a USER never gets. */
    private const NEVER = [
        'billing.manage',
        'payments.manage',
        'sales.manage',
        'subscription.manage',
        'tenant.manage',
        'members.manage',
        'catalog.manage',
        'tax.manage',
        'jobs.manage',
        'skin.manage',
    ];

    /** What a USER sees, which is the point of letting them in. */
    private const ALWAYS = [
        'catalog.read',
        'billing.read',
        'payments.read',
        'sales.read',
        'subscription.read',
        'tax.read',
        'members.read',
        'tenant.read',
        'account.read',
        'account.manage',
    ];

    public function testAUserSeesAndCannotBindTheOrganisation(): void
    {
        $held = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT p.code
                  FROM role_permissions rp
                  JOIN roles r ON r.id = rp.role_id
                  JOIN permissions p ON p.id = rp.permission_id
                 WHERE r.code = 'USER'
                 ORDER BY p.code
                SQL,
        );
        $codes = array_values(array_filter($held, 'is_string'));

        foreach (self::NEVER as $forbidden) {
            self::assertNotContains($forbidden, $codes, $forbidden . ' must never be a USER permission');
        }

        foreach (self::ALWAYS as $granted) {
            self::assertContains($granted, $codes, $granted . ' is what a USER is let in to do');
        }
    }

    public function testEveryBindingActIsAPermissionATenantAdministratorHoldsAndAUserDoesNot(): void
    {
        // The other half: the refusals above are only meaningful if somebody
        // holds the permission — a code nobody has would be a dead gate.
        $admin = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT p.code
                  FROM role_permissions rp
                  JOIN roles r ON r.id = rp.role_id
                  JOIN permissions p ON p.id = rp.permission_id
                 WHERE r.code = 'TENANT_ADMIN'
                SQL,
        );
        $codes = array_values(array_filter($admin, 'is_string'));

        foreach (self::NEVER as $binding) {
            if ($binding === 'catalog.manage') {
                // Lent by the platform (ADR-040), never held by the role.
                continue;
            }

            self::assertContains($binding, $codes, $binding . ' is the administrator\'s');
        }
    }
}
