<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * What the demonstration world contains — the definition, not the rows.
 *
 * **Four products, two organisations, six people, one per role.** The platform
 * is multi-product, and a demo with one product cannot show the part that
 * matters: a tenant holding several (ADR-047), the console switching between
 * them, the storefront asking which one a stranger wants. Acme holds all four,
 * Globex two. Every role the platform defines — two tenant roles, four
 * platform roles — is held by exactly one person, so whoever demonstrates it
 * can sign in as each authority in turn, and nobody holds two authorities at
 * once (non-negotiable #22 is easier to show when nobody is both).
 *
 * `docs/demo-world.html` is the human-readable copy of this file; when one
 * changes, so does the other. The rows are written by {@see DemoFixtures}
 * and the invariants run by the seeder in `App\Demo\Service`.
 */
final class DemoWorld
{
    /**
     * The password every seeded person gets.
     *
     * In the output on purpose. This is demonstration data whose whole point
     * is being opened; a secret nobody is told is a database nobody can look
     * at. It is also in this platform's source, so a deployment that keeps
     * these accounts changes their passwords first — the installer says so,
     * and offers the form.
     */
    public const PASSWORD = 'demo-password-1234';

    public const EMAIL_DOMAIN = 'demo.test';

    /**
     * The four products, in the order the console lists them. `base` is the
     * monthly Starter price in cents; Pro and Scale are derived from it, so
     * the four catalogues have the same shape and visibly different prices.
     *
     * @var array<string, array{name: string, base: int}>
     */
    public const PRODUCTS = [
        'atlas' => ['name' => 'Atlas', 'base' => 1_900],
        'boreas' => ['name' => 'Boreas', 'base' => 2_900],
        'ceres' => ['name' => 'Ceres', 'base' => 900],
        'delos' => ['name' => 'Delos', 'base' => 4_900],
    ];

    /**
     * The organisations, and which products each holds (ADR-047).
     *
     * @var array<string, array{name: string, holds: list<string>}>
     */
    public const TENANTS = [
        'acme' => ['name' => 'Acme Ltd', 'holds' => ['atlas', 'boreas', 'ceres', 'delos']],
        'globex' => ['name' => 'Globex SA', 'holds' => ['atlas', 'boreas']],
    ];

    /**
     * One person per role. `tenants` names the organisations a tenant-role
     * holder is a member of — mirrored onto every product each holds;
     * platform staff are members of nothing.
     *
     * @var array<string, array{name: string, scope: 'tenant'|'platform', role: string, tenants: list<string>}>
     */
    public const PEOPLE = [
        'ada' => ['name' => 'Ada Lovelace', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['acme', 'globex']],
        'grace' => ['name' => 'Grace Hopper', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        'sam' => ['name' => 'Sam Staff', 'scope' => 'platform', 'role' => 'PLATFORM_ADMIN', 'tenants' => []],
        'hedy' => ['name' => 'Hedy Lamarr', 'scope' => 'platform', 'role' => 'SUPPORT_ADMIN', 'tenants' => []],
        'fran' => ['name' => 'Frances Allen', 'scope' => 'platform', 'role' => 'FINANCE_ADMIN', 'tenants' => []],
        'sal' => ['name' => 'Sally Ride', 'scope' => 'platform', 'role' => 'SALES_ADMIN', 'tenants' => []],
    ];

    /** The one platform administrator, who assigns and grants on the seeder's behalf. */
    public const STAFF_ADMIN = 'sam';

    /**
     * The two subscriptions that run, each with the invoice it raised, so the
     * console's invoicing and the tenants' billing screens have something on
     * them for more than one product.
     *
     * @var list<array{tenant: string, product: string, offer: string}>
     */
    public const SUBSCRIPTIONS = [
        ['tenant' => 'acme', 'product' => 'atlas', 'offer' => 'pro-monthly'],
        ['tenant' => 'globex', 'product' => 'boreas', 'offer' => 'starter-monthly'],
    ];

    /** @return list<string> */
    public static function productCodes(): array
    {
        return array_keys(self::PRODUCTS);
    }

    /** The address a seeded person signs in with. */
    public static function email(string $who): string
    {
        return $who . '@' . self::EMAIL_DOMAIN;
    }
}
