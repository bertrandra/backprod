<?php

declare(strict_types=1);

namespace App\Demo\Domain;

/**
 * What the demonstration world contains — the definition, not the rows.
 *
 * **Four products, two organisations, seven people.** The platform is
 * multi-product, and a demo with one product cannot show the part that
 * matters: a tenant holding several (ADR-047), the console switching between
 * them, the storefront asking which one a stranger wants. Acme holds all four,
 * Globex two. Each organisation has its administrator and two members, and
 * the platform has its one administrator; nobody holds two authorities at
 * once (non-negotiable #22 is easier to show when nobody is both). The
 * addresses are the operator's own since 2026-09-19, so the mails the
 * platform sends — a reset link, an invitation — land somewhere real.
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

    public const EMAIL_DOMAIN = 'raillard.org';

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
     * The organisation the bare host addresses (2026-09-17): Acme, as the
     * operator's own. Globex lives at `/globex/`.
     */
    public const DEFAULT_TENANT = 'acme';

    /**
     * The people, keyed by the local part of their address. `tenants` names
     * the organisations a tenant-role holder is a member of — mirrored onto
     * every product each holds; platform staff are members of nothing.
     *
     * @var array<string, array{name: string, scope: 'tenant'|'platform', role: string, tenants: list<string>}>
     */
    public const PEOPLE = [
        'backprod' => ['name' => 'Platform admin', 'scope' => 'platform', 'role' => 'PLATFORM_ADMIN', 'tenants' => []],
        'acme-admin' => ['name' => 'ACME tenant admin', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['acme']],
        'acme-user1' => ['name' => 'ACME user1', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        'acme-user2' => ['name' => 'ACME user2', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
        'globex-admin' => ['name' => 'Globex tenant admin', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['globex']],
        'globex-user1' => ['name' => 'Globex user1', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['globex']],
        'globex-user2' => ['name' => 'Globex user2', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['globex']],
    ];

    /** The one platform administrator, who assigns and grants on the seeder's behalf. */
    public const STAFF_ADMIN = 'backprod';

    /** Who activates each organisation's subscription, and so owns it: its administrator. */
    public const TENANT_ADMINS = ['acme' => 'acme-admin', 'globex' => 'globex-admin'];

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
