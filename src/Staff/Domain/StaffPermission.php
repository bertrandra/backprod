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

    /**
     * Appointing and removing staff, which PLATFORM_ADMIN alone holds: a
     * support engineer able to appoint support engineers could grant
     * themselves anything by way of somebody else.
     */
    public const GRANT = 'staff.grant';

    /**
     * Changing what a tenant is allowed to do — today, whether the platform's
     * catalogue is lent to them. PLATFORM_ADMIN alone, because a support
     * engineer who could hand one customer the price list could change what
     * every other customer of that product pays.
     */
    public const TENANTS_MANAGE = 'staff.tenants.manage';

    /**
     * Deciding what the public storefront advertises — PLATFORM_ADMIN alone.
     *
     * Deliberately not `catalog.manage`, which ADR-040 lets the platform lend
     * to a tenant. A tenant authoring its own offers must not thereby decide
     * what the platform's public page shows to everybody.
     */
    public const CATALOG_MANAGE = 'staff.catalog.manage';

    /**
     * Seeing every product on the platform, and creating or retiring one —
     * PLATFORM_ADMIN alone.
     *
     * Not a read anybody else gets either: which products a deployment hosts
     * is commercial information, which is why `ProductRegistry` answers only
     * about the ones a person is a member of and why this permission exists
     * to ask the other question.
     */
    public const PRODUCTS_MANAGE = 'staff.products.manage';

    /**
     * Emptying every business table and seeding the demonstration world
     * afresh — PLATFORM_ADMIN alone, and a permission of its own rather
     * than a side of `staff.products.manage`: creating a product and
     * destroying every one are not the same trust. The seeder refuses it
     * anyway while a product that is not the demonstration's exists.
     */
    public const DEMO_RESET = 'staff.demo.reset';

    /**
     * Showing the public demonstration page (2026-09-18) — what the
     * platform hosts, who is in it and as what, to anybody. PLATFORM_ADMIN
     * alone, and apart from DEMO_RESET: wiping the world and publishing it
     * are different trusts.
     */
    public const DEMO_PUBLISH = 'staff.demo.publish';

    /**
     * Choosing which menu entries the shell shows each kind of person, and
     * whether an entry with nothing behind it is shown at all. Platform-wide
     * and PLATFORM_ADMIN alone: what every customer's administrator can find
     * is the platform administrator's decision.
     */
    public const NAVIGATION_MANAGE = 'staff.navigation.manage';
}
