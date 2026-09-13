<?php

declare(strict_types=1);

use App\Billing\Service\Invoicing;
use App\Commerce\Service\Subscriptions;
use App\Tax\Domain\SupplierTaxSettings;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

/**
 * One demonstration world, for one product, built the way the application
 * builds one.
 *
 * **Why this is a file and not a script.** It was the body of
 * `bin/seed-demo.php`, which could seed exactly one world called `atlas` into
 * exactly one empty database. The installer needs the same world for a product
 * of its own choosing, beside a real one that already exists — so the world
 * moved here, the script became a thin front for it, and there is one
 * definition of "a complete demo" rather than two that drift.
 *
 * **Everything is derived from the product code.** Tenant slugs, email
 * addresses, the lot. `products.code`, `tenants.slug` and `local_credentials`'
 * lower(email) are each unique across the whole installation, so a world with
 * fixed names could be seeded once and never again — not beside a second demo,
 * and not beside the real account an installer has just created. Deriving them
 * means two demo products coexist and neither can collide with anything real.
 *
 * **Complete means the readiness screen says so.** A product that has a
 * catalogue but no tax position, or published offers that are not advertised,
 * is a product the console reports as not sellable — which is correct, and
 * makes for a demonstration of a half-built platform. So this writes the tax
 * settings and advertises the offers, and the checks at the end assert the
 * whole chain rather than the parts somebody remembered.
 *
 * **Invariants go through the services that own them.** Subscribing and issuing
 * an invoice are `Subscriptions::subscribe` and `Invoicing::issueForSubscription`,
 * never INSERT: an invoice number comes from a gapless sequence and an
 * activation writes a subscription event. Structure with no invariant behind it
 * — a tenant's name, a plan's rank — is plain SQL, which is what the
 * integration tests do too.
 *
 * @return callable(ContainerInterface, string, string): DemoWorld
 *
 * @phpstan-type DemoWorld array{
 *     product: string,
 *     product_code: string,
 *     password: string,
 *     tenants: array{acme: string, globex: string},
 *     people: array{admin: string, user: string, staff: string},
 *     counts: array{plans: int, features: int, offers: int},
 *     subscription: \App\Commerce\Domain\Subscription,
 *     invoice: \App\Billing\Domain\Invoice,
 *     checks: array<string, bool>
 * }
 */

/**
 * The password every seeded person gets.
 *
 * In the output on purpose. This is demonstration data whose whole point is
 * being opened; a secret nobody is told is a database nobody can look at. What
 * keeps it away from anything real is that a demo world is only ever created
 * deliberately, under a product code of its own.
 */
const DEMO_PASSWORD = 'demo-password-1234';

/**
 * One id back, or a failure that names the statement.
 *
 * A named function and not a closure: a docblock above `$x = static function`
 * describes the variable, `@var` on a static closure is refused as not a
 * subtype of its native type, and either way the parameter array arrives at
 * `fetchOne` untyped. A function takes `@param` and PHPStan reads it.
 *
 * @param array<string, scalar|null> $parameters
 */
function demo_id(Connection $connection, string $sql, array $parameters = []): string
{
    $value = $connection->fetchOne($sql, $parameters);

    if (!is_string($value)) {
        throw new RuntimeException('Expected an id back from: ' . $sql);
    }

    return $value;
}

/**
 * A count, narrowed — `fetchOne` answers `mixed` and a cast on that is a guess.
 *
 * @param array<string, scalar|null> $parameters
 */
function demo_count(Connection $connection, string $sql, array $parameters = []): int
{
    $value = $connection->fetchOne($sql, $parameters);

    return is_numeric($value) ? (int) $value : 0;
}

return static function (
    ContainerInterface $container,
    string $productCode,
    string $productName,
): array {
    // The application's own connection, not one the caller built beside it:
    // the services below are resolved from this container and run their
    // invariants on its connection, and seeding through a second one would put
    // the structure and the invariants in two transactions against one
    // database.
    /** @var Connection $connection */
    $connection = $container->get(Connection::class);

    // Every name this world uses, derived rather than fixed. See the note above
    // about the three unique constraints this would otherwise walk into.
    $slug = static fn (string $suffix): string => $productCode . '-' . $suffix;
    $email = static fn (string $who): string => $who . '@' . $productCode . '.test';

    $connection->beginTransaction();

    // --- The product, and the people ------------------------------------------

    $product = demo_id(
        $connection,
        'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
        ['code' => $productCode, 'name' => $productName],
    );

    $acme = demo_id(
        $connection,
        'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
        ['name' => 'Acme Ltd', 'slug' => $slug('acme')],
    );

    $globex = demo_id(
        $connection,
        'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
        ['name' => 'Globex SA', 'slug' => $slug('globex')],
    );

    /**
     * A distinct placeholder per person: `auth_subject` is unique, so three
     * rows sharing one literal is a constraint violation rather than three
     * people. It is rewritten to `local:<id>` below; this only has to be unique
     * for the length of the transaction, and carries the product so that two
     * demo worlds do not collide either.
     */
    $person = static fn (string $who, string $name): string => demo_id(
        $connection,
        'INSERT INTO users (auth_subject, email, display_name) VALUES (:subject, :email, :name) RETURNING id',
        ['subject' => 'seeding:' . $productCode . '|' . $who, 'email' => $email($who), 'name' => $name],
    );

    // Named rather than collected in a loop: the three are used individually
    // below, and a loop's array leaves every one of those uses unprovable.
    $ada = $person('ada', 'Ada Lovelace');
    $grace = $person('grace', 'Grace Hopper');
    $staff = $person('sam', 'Sam Support');
    $people = ['ada' => $ada, 'grace' => $grace, 'sam' => $staff];

    // `auth_subject` becomes `local:<id>` — the same `prefix:id` shape erasure
    // uses — because that is what a token this platform issues carries, and a
    // subject the token cannot name is a person who cannot sign in.
    foreach ($people as $person) {
        $connection->executeStatement(
            "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
            ['id' => $person],
        );

        $connection->executeStatement(
            <<<'SQL'
                INSERT INTO local_credentials (user_id, email, password_hash)
                SELECT id, email, :hash FROM users WHERE id = :id AND email IS NOT NULL
                SQL,
            // Hashed here rather than written as a literal: the database refuses a
            // password_hash that does not start with `$`, so a seeder that stored the
            // plaintext would fail rather than seed a world with a plaintext password
            // in it.
            ['id' => $person, 'hash' => password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT)],
        );
    }

    // Roles are migration data and are joined by id, not by code: the code is
    // what a person reads and the id is what the schema stores, and a seeder
    // that invented either would authorise nobody.
    $roleId = static fn (string $code): string => demo_id(
        $connection,
        'SELECT id FROM roles WHERE code = :code',
        ['code' => $code],
    );

    foreach ([[$acme, $ada, 'TENANT_ADMIN'], [$acme, $grace, 'USER'], [$globex, $ada, 'TENANT_ADMIN']] as [$tenant, $user, $role]) {
        $connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:tenant, :product, :user)',
            ['tenant' => $tenant, 'product' => $product, 'user' => $user],
        );

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
            VALUES (:tenant, :product, :user, :role)
            SQL,
            ['tenant' => $tenant, 'product' => $product, 'user' => $user, 'role' => $roleId($role)],
        );
    }

    // Platform staff: a separate identity, never a tenant membership
    // (non-negotiable #22). `granted_by` is themselves here, which is the honest
    // answer for a seeded world — there was nobody else to grant it.
    $connection->executeStatement(
        <<<'SQL'
        INSERT INTO platform_staff (user_id, platform_role_id, granted_by)
        VALUES (:user, (SELECT id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'), :user)
        SQL,
        ['user' => $staff],
    );

    // --- The catalogue --------------------------------------------------------

    $plans = [];

    foreach ([['starter', 'Starter', 10], ['pro', 'Pro', 20], ['scale', 'Scale', 30]] as [$code, $name, $rank]) {
        $plans[$code] = demo_id(
            $connection,
            <<<'SQL'
            INSERT INTO plans (product_id, code, name, rank)
            VALUES (:product, :code, :name, :rank) RETURNING id
            SQL,
            ['product' => $product, 'code' => $code, 'name' => $name, 'rank' => $rank],
        );
    }

    $features = [];

    foreach ([['projects', 'Projects', 'QUOTA', 'projects'], ['exports', 'Exports', 'QUOTA', 'exports'], ['white_label', 'White label', 'BOOLEAN', null]] as [$code, $name, $kind, $unit]) {
        $features[$code] = demo_id(
            $connection,
            <<<'SQL'
            INSERT INTO features (product_id, code, name, kind, unit)
            VALUES (:product, :code, :name, :kind, :unit) RETURNING id
            SQL,
            ['product' => $product, 'code' => $code, 'name' => $name, 'kind' => $kind, 'unit' => $unit],
        );
    }

    /** @var array<string, string> $offers */
    $offers = [];

    foreach ([
        ['starter-monthly', 'Starter, monthly', 'starter', 1_900, 'MONTHLY', ['projects' => 3, 'exports' => 10]],
        ['pro-monthly', 'Pro, monthly', 'pro', 4_900, 'MONTHLY', ['projects' => 25, 'exports' => 200, 'white_label' => null]],
        ['scale-yearly', 'Scale, yearly', 'scale', 49_000, 'YEARLY', ['projects' => null, 'exports' => null, 'white_label' => null]],
    ] as [$code, $name, $plan, $price, $period, $grants]) {
        $offer = demo_id(
            $connection,
            <<<'SQL'
            INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
            VALUES (:product, :plan, :code, :name, true) RETURNING id
            SQL,
            ['product' => $product, 'plan' => $plans[$plan] ?? throw new RuntimeException("unknown plan {$plan}"), 'code' => $code, 'name' => $name],
        );

        // Draft, grant, publish — the order the product actually uses. A version's
        // grants freeze the moment it leaves DRAFT (ADR-033), so seeding an ACTIVE
        // row and attaching grants afterwards would build a version the application
        // cannot.
        $version = demo_id(
            $connection,
            <<<'SQL'
            INSERT INTO offer_versions
                (offer_id, version, status, billing_period, price_minor_units, currency, valid_from)
            VALUES (:offer, 1, 'DRAFT', :period, :price, 'EUR', now() - interval '30 days')
            RETURNING id
            SQL,
            ['offer' => $offer, 'period' => $period, 'price' => $price],
        );

        foreach ($grants as $feature => $limit) {
            $connection->executeStatement(
                <<<'SQL'
                INSERT INTO offer_version_features (offer_version_id, feature_id, limit_value)
                VALUES (:version, :feature, :limit)
                SQL,
                [
                    'version' => $version,
                    'feature' => $features[$feature] ?? throw new RuntimeException("unknown feature {$feature}"),
                    // A null limit is what this schema stores for "unlimited", and
                    // for a BOOLEAN feature it is the only meaningful value.
                    'limit' => $limit,
                ],
            );
        }

        $connection->executeStatement(
            "UPDATE offer_versions SET status = 'ACTIVE' WHERE id = :id",
            ['id' => $version],
        );

        $offers[$code] = $offer;
    }

    // --- The legal identity an invoice is issued against ----------------------

    $connection->executeStatement(
        <<<'SQL'
        INSERT INTO billing_profiles
            (tenant_id, legal_name, vat_number, address_line1, postal_code, city, country_code, billing_email)
        VALUES (:tenant, 'Acme Ltd', 'FR12345678901', '12 rue de la Paix', '75002', 'Paris', 'FR', :email)
        SQL,
        ['tenant' => $acme, 'email' => $email('billing')],
    );

    $connection->executeStatement(
        <<<'SQL'
        INSERT INTO customer_tax_profiles (tenant_id, customer_kind, country_code, taxable_person)
        VALUES (:tenant, 'B2B', 'FR', true)
        SQL,
        ['tenant' => $acme],
    );

    /**
     * The platform's own legal identity — the *supplier* on every invoice — and
     * the fiscal position it issues under.
     *
     * Product configuration rather than a tenant's billing profile: the
     * customer's identity varies per tenant, the supplier's does not, and
     * `Invoicing` refuses to issue anything at all until the first of these
     * exists. The second is what the readiness screen calls the tax position;
     * without it a demo has a catalogue and a console that correctly reports it
     * as not ready to sell across a border.
     */
    $connection->executeStatement(
        <<<'SQL'
        INSERT INTO product_configuration (product_id, key, value)
        VALUES (:product, 'billing_supplier', CAST(:value AS jsonb))
        SQL,
        [
            'product' => $product,
            'value' => json_encode([
                'legal_name' => $productName . ' SAS',
                'vat_number' => 'FR99887766554',
                'registration_number' => '912 345 678 R.C.S. Paris',
                'address_line1' => '1 avenue du Code',
                'address_line2' => null,
                'postal_code' => '75011',
                'city' => 'Paris',
                'country_code' => 'FR',
            ], JSON_THROW_ON_ERROR),
        ],
    );

    $connection->executeStatement(
        <<<'SQL'
        INSERT INTO product_configuration (product_id, key, value)
        VALUES (:product, :key, CAST(:value AS jsonb))
        SQL,
        [
            'product' => $product,
            'key' => SupplierTaxSettings::CONFIGURATION_KEY,
            // Through the domain object rather than a hand-written literal: the
            // console writes this key and the tax engine reads it, and a seeder
            // that spelled `oss_registered` differently would configure nothing
            // while answering that it had.
            'value' => json_encode(
                (new SupplierTaxSettings('FR', true, 'DIGITAL_SERVICES', 'EUR'))->toConfiguration(),
                JSON_THROW_ON_ERROR,
            ),
        ],
    );

    $connection->commit();

    // --- The parts with invariants, through the services that own them --------

    /** @var Subscriptions $subscriptions */
    $subscriptions = $container->get(Subscriptions::class);

    /** @var Invoicing $invoicing */
    $invoicing = $container->get(Invoicing::class);

    $subscription = $subscriptions->subscribe($acme, $product, $offers['pro-monthly'], $ada);
    $invoice = $invoicing->issueForSubscription($acme, $product, $ada);

    // --- And reads it back ----------------------------------------------------
    //
    // Seeding and verifying in one pass. A seeder whose output nobody checks is
    // a fixture that drifts from the schema silently, and the first person to
    // notice is somebody demonstrating the product.

    $checks = [
        'the subscription is active' => $subscriptions->current($acme, $product)?->status === 'ACTIVE',
        'the invoice has a legal number' => is_string($invoice->number) && $invoice->number !== '',
        'the invoice belongs to the tenant' => $invoice->tenantId === $acme,
        'three offer versions are published' => 3 === demo_count(
            $connection,
            <<<'SQL'
            SELECT count(*) FROM offer_versions v
            JOIN offers o ON o.id = v.offer_id
            WHERE o.product_id = :product AND v.status = 'ACTIVE'
            SQL,
            ['product' => $product],
        ),
        // The two the readiness screen adds to "it has a catalogue": a product
        // can be entirely priced and still sell to nobody.
        'every offer is advertised' => 3 === demo_count(
            $connection,
            'SELECT count(*) FROM offers WHERE product_id = :product AND publicly_listed',
            ['product' => $product],
        ),
        'the tax position is configured' => 1 === demo_count(
            $connection,
            'SELECT count(*) FROM product_configuration WHERE product_id = :product AND key = :key',
            ['product' => $product, 'key' => SupplierTaxSettings::CONFIGURATION_KEY],
        ),
        'entitlements were granted' => 0 < demo_count(
            $connection,
            'SELECT count(*) FROM entitlements WHERE tenant_id = :tenant',
            ['tenant' => $acme],
        ),
        'platform staff hold no tenant membership' => 0 === demo_count(
            $connection,
            'SELECT count(*) FROM tenant_members WHERE user_id = :user',
            ['user' => $staff],
        ),
    ];

    return [
        'product' => $product,
        'product_code' => $productCode,
        // Returned rather than left for the caller to know: the installer prints
        // it on its success page, and a second copy of this string somewhere
        // else is an instruction that goes wrong the day this one changes.
        'password' => DEMO_PASSWORD,
        'tenants' => ['acme' => $acme, 'globex' => $globex],
        'people' => [
            'admin' => $email('ada'),
            'user' => $email('grace'),
            'staff' => $email('sam'),
        ],
        'counts' => ['plans' => count($plans), 'features' => count($features), 'offers' => count($offers)],
        'subscription' => $subscription,
        'invoice' => $invoice,
        'checks' => $checks,
    ];
};
