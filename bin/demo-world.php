<?php

declare(strict_types=1);

use App\Billing\Service\Invoicing;
use App\Commerce\Service\Subscriptions;
use App\Tax\Domain\SupplierTaxSettings;
use Doctrine\DBAL\Connection;
use Psr\Container\ContainerInterface;

/**
 * The demonstration world, built the way the application builds one.
 *
 * **Why this is a file and not a script.** It was the body of
 * `bin/seed-demo.php`; the installer needs the same world, so the world moved
 * here, the script became a thin front for it, and there is one definition of
 * "a complete demo" rather than two that drift. `docs/demo-world.html` is the
 * human-readable copy of what is below; when one changes, so does the other.
 *
 * **Four products, two organisations, six people — one per role.** The platform
 * is multi-product, and a demo with one product cannot show the part that
 * matters: a tenant holding several (ADR-047), the console switching between
 * them, the storefront asking which one a stranger wants. Acme holds all four,
 * Globex two. Every role the platform defines — two tenant roles, four
 * platform roles — is held by exactly one person, so whoever demonstrates it
 * can sign in as each authority in turn and see what that authority sees, and
 * no account holds two authorities at once (non-negotiable #22 is easier to
 * show when nobody is both).
 *
 * **Complete means the readiness screen says so**, for every product. A
 * product with a catalogue but no tax position, or published offers that are
 * not advertised, is one the console reports as not sellable — correct, and a
 * demonstration of a half-built platform. So each product gets its supplier
 * identity and tax position, every offer is advertised, and the checks at the
 * end assert the whole chain rather than the parts somebody remembered.
 *
 * **Invariants go through the services that own them.** Subscribing and issuing
 * an invoice are `Subscriptions::subscribe` and `Invoicing::issueForSubscription`,
 * never INSERT: an invoice number comes from a gapless sequence and an
 * activation writes a subscription event. Structure with no invariant behind it
 * — a tenant's name, a plan's rank — is plain SQL, which is what the
 * integration tests do too.
 *
 * @return callable(ContainerInterface): DemoWorld
 *
 * @phpstan-type DemoPerson array{name: string, email: string, scope: 'tenant'|'platform', role: string, tenants: list<string>}
 * @phpstan-type DemoWorld array{
 *     password: string,
 *     products: array<string, string>,
 *     tenants: array{acme: string, globex: string},
 *     people: array<string, DemoPerson>,
 *     counts: array{products: int, plans: int, features: int, offers: int},
 *     subscriptions: list<\App\Commerce\Domain\Subscription>,
 *     invoices: list<\App\Billing\Domain\Invoice>,
 *     checks: array<string, bool>
 * }
 */

/**
 * The password every seeded person gets.
 *
 * In the output on purpose. This is demonstration data whose whole point is
 * being opened; a secret nobody is told is a database nobody can look at. It
 * is also in this platform's source, so a deployment that keeps these accounts
 * changes their passwords first — the installer says so, and offers the form.
 */
const DEMO_PASSWORD = 'demo-password-1234';

/**
 * The four products, in the order the console lists them. `base` is the
 * monthly Starter price in cents; the other two are derived from it, so the
 * four catalogues have the same shape and visibly different prices.
 */
const DEMO_PRODUCTS = [
    'atlas' => ['name' => 'Atlas', 'base' => 1_900],
    'boreas' => ['name' => 'Boreas', 'base' => 2_900],
    'ceres' => ['name' => 'Ceres', 'base' => 900],
    'delos' => ['name' => 'Delos', 'base' => 4_900],
];

/**
 * One person per role. `tenants` names the organisations a tenant-role
 * holder is a member of — mirrored onto every product each holds (ADR-047);
 * platform staff are members of nothing (non-negotiable #22).
 */
const DEMO_PEOPLE = [
    'ada' => ['name' => 'Ada Lovelace', 'scope' => 'tenant', 'role' => 'TENANT_ADMIN', 'tenants' => ['acme', 'globex']],
    'grace' => ['name' => 'Grace Hopper', 'scope' => 'tenant', 'role' => 'USER', 'tenants' => ['acme']],
    'sam' => ['name' => 'Sam Staff', 'scope' => 'platform', 'role' => 'PLATFORM_ADMIN', 'tenants' => []],
    'hedy' => ['name' => 'Hedy Lamarr', 'scope' => 'platform', 'role' => 'SUPPORT_ADMIN', 'tenants' => []],
    'fran' => ['name' => 'Frances Allen', 'scope' => 'platform', 'role' => 'FINANCE_ADMIN', 'tenants' => []],
    'sal' => ['name' => 'Sally Ride', 'scope' => 'platform', 'role' => 'SALES_ADMIN', 'tenants' => []],
];

const DEMO_EMAIL_DOMAIN = 'demo.test';

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

/** The address a seeded person signs in with. */
function demo_email(string $who): string
{
    return $who . '@' . DEMO_EMAIL_DOMAIN;
}

return static function (ContainerInterface $container): array {
    // The application's own connection, not one the caller built beside it:
    // the services below are resolved from this container and run their
    // invariants on its connection, and seeding through a second one would put
    // the structure and the invariants in two transactions against one
    // database.
    /** @var Connection $connection */
    $connection = $container->get(Connection::class);

    $connection->beginTransaction();

    // --- The products ---------------------------------------------------------

    /** @var array<string, string> $products code => id */
    $products = [];

    foreach (DEMO_PRODUCTS as $code => $product) {
        $products[$code] = demo_id(
            $connection,
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $code, 'name' => $product['name']],
        );
    }

    // --- The organisations, and which products each holds ---------------------

    $acme = demo_id(
        $connection,
        "INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id",
    );

    $globex = demo_id(
        $connection,
        "INSERT INTO tenants (name, slug) VALUES ('Globex SA', 'globex') RETURNING id",
    );

    $tenants = ['acme' => $acme, 'globex' => $globex];

    /** @var array<string, list<string>> $holdings tenant key => product codes */
    $holdings = [
        'acme' => array_keys(DEMO_PRODUCTS),
        'globex' => ['atlas', 'boreas'],
    ];

    // --- The people -----------------------------------------------------------

    /**
     * A distinct placeholder per person: `auth_subject` is unique, so six rows
     * sharing one literal is a constraint violation rather than six people. It
     * is rewritten to `local:<id>` below; this only has to be unique for the
     * length of the transaction.
     */
    /** @var array<string, string> $users key => id */
    $users = [];

    foreach (DEMO_PEOPLE as $who => $person) {
        $users[$who] = demo_id(
            $connection,
            'INSERT INTO users (auth_subject, email, display_name) VALUES (:subject, :email, :name) RETURNING id',
            ['subject' => 'seeding:' . $who, 'email' => demo_email($who), 'name' => $person['name']],
        );
    }

    // `auth_subject` becomes `local:<id>` — the same `prefix:id` shape erasure
    // uses — because that is what a token this platform issues carries, and a
    // subject the token cannot name is a person who cannot sign in.
    foreach ($users as $user) {
        $connection->executeStatement(
            "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
            ['id' => $user],
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
            ['id' => $user, 'hash' => password_hash(DEMO_PASSWORD, PASSWORD_BCRYPT)],
        );
    }

    $staffAdmin = $users['sam'];

    // Every tenant holds its products before anybody is a member of them
    // (ADR-047). Assigned by the platform administrator, which is who would.
    foreach ($holdings as $tenant => $codes) {
        foreach ($codes as $code) {
            $connection->executeStatement(
                'INSERT INTO tenant_products (tenant_id, product_id, assigned_by) VALUES (:tenant, :product, :user)',
                ['tenant' => $tenants[$tenant], 'product' => $products[$code], 'user' => $staffAdmin],
            );
        }
    }

    // Roles are migration data and are joined by id, not by code: the code is
    // what a person reads and the id is what the schema stores, and a seeder
    // that invented either would authorise nobody.
    $roleId = static fn (string $code): string => demo_id(
        $connection,
        'SELECT id FROM roles WHERE code = :code',
        ['code' => $code],
    );

    $platformRoleId = static fn (string $code): string => demo_id(
        $connection,
        'SELECT id FROM platform_roles WHERE code = :code',
        ['code' => $code],
    );

    foreach (DEMO_PEOPLE as $who => $person) {
        if ($person['scope'] === 'platform') {
            // Platform staff: a separate identity, never a tenant membership
            // (non-negotiable #22). Granted by the platform administrator —
            // who grants their own, which is the honest answer for a seeded
            // world: there was nobody else to do it.
            $connection->executeStatement(
                'INSERT INTO platform_staff (user_id, platform_role_id, granted_by) VALUES (:user, :role, :by)',
                ['user' => $users[$who], 'role' => $platformRoleId($person['role']), 'by' => $staffAdmin],
            );

            continue;
        }

        // A member of the tenant, mirrored onto every product it holds
        // (ADR-047): the same rows `PostgresTenantMemberRepository::addMember`
        // writes, so the demo is a world the application could have made.
        foreach ($person['tenants'] as $tenant) {
            foreach ($holdings[$tenant] as $code) {
                $connection->executeStatement(
                    'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:tenant, :product, :user)',
                    ['tenant' => $tenants[$tenant], 'product' => $products[$code], 'user' => $users[$who]],
                );

                $connection->executeStatement(
                    <<<'SQL'
                    INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id)
                    VALUES (:tenant, :product, :user, :role)
                    SQL,
                    ['tenant' => $tenants[$tenant], 'product' => $products[$code], 'user' => $users[$who], 'role' => $roleId($person['role'])],
                );
            }
        }
    }

    // --- One catalogue per product --------------------------------------------

    /** @var array<string, array<string, string>> $offers product code => offer code => id */
    $offers = [];
    $planCount = 0;
    $featureCount = 0;

    foreach (DEMO_PRODUCTS as $code => $definition) {
        $product = $products[$code];
        $base = $definition['base'];

        $plans = [];

        foreach ([['starter', 'Starter', 10], ['pro', 'Pro', 20], ['scale', 'Scale', 30]] as [$planCode, $name, $rank]) {
            $plans[$planCode] = demo_id(
                $connection,
                <<<'SQL'
                INSERT INTO plans (product_id, code, name, rank)
                VALUES (:product, :code, :name, :rank) RETURNING id
                SQL,
                ['product' => $product, 'code' => $planCode, 'name' => $name, 'rank' => $rank],
            );
            ++$planCount;
        }

        $features = [];

        foreach ([['projects', 'Projects', 'QUOTA', 'projects'], ['exports', 'Exports', 'QUOTA', 'exports'], ['white_label', 'White label', 'BOOLEAN', null]] as [$featureCode, $name, $kind, $unit]) {
            $features[$featureCode] = demo_id(
                $connection,
                <<<'SQL'
                INSERT INTO features (product_id, code, name, kind, unit)
                VALUES (:product, :code, :name, :kind, :unit) RETURNING id
                SQL,
                ['product' => $product, 'code' => $featureCode, 'name' => $name, 'kind' => $kind, 'unit' => $unit],
            );
            ++$featureCount;
        }

        $offers[$code] = [];

        foreach ([
            ['starter-monthly', 'Starter, monthly', 'starter', $base, 'MONTHLY', ['projects' => 3, 'exports' => 10]],
            ['pro-monthly', 'Pro, monthly', 'pro', intdiv($base * 26, 10), 'MONTHLY', ['projects' => 25, 'exports' => 200, 'white_label' => null]],
            ['scale-yearly', 'Scale, yearly', 'scale', $base * 26, 'YEARLY', ['projects' => null, 'exports' => null, 'white_label' => null]],
        ] as [$offerCode, $name, $plan, $price, $period, $grants]) {
            $offer = demo_id(
                $connection,
                <<<'SQL'
                INSERT INTO offers (product_id, plan_id, code, name, publicly_listed)
                VALUES (:product, :plan, :code, :name, true) RETURNING id
                SQL,
                ['product' => $product, 'plan' => $plans[$plan] ?? throw new RuntimeException("unknown plan {$plan}"), 'code' => $offerCode, 'name' => $name],
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

            $offers[$code][$offerCode] = $offer;
        }

        /**
         * The platform's own legal identity — the *supplier* on every invoice —
         * and the fiscal position it issues under, per product.
         *
         * Product configuration rather than a tenant's billing profile: the
         * customer's identity varies per tenant, the supplier's does not, and
         * `Invoicing` refuses to issue anything at all until the first of these
         * exists. The second is what the readiness screen calls the tax
         * position; without it a demo has a catalogue and a console that
         * correctly reports it as not ready to sell across a border.
         */
        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO product_configuration (product_id, key, value)
            VALUES (:product, 'billing_supplier', CAST(:value AS jsonb))
            SQL,
            [
                'product' => $product,
                'value' => json_encode([
                    'legal_name' => $definition['name'] . ' SAS',
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
    }

    // --- The legal identities invoices are issued against ---------------------

    foreach ([
        [$acme, 'Acme Ltd', 'FR12345678901', '12 rue de la Paix', '75002', 'Paris'],
        [$globex, 'Globex SA', 'FR98765432109', '1 rue de la Paix', '75002', 'Paris'],
    ] as [$tenant, $legalName, $vat, $address, $postalCode, $city]) {
        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO billing_profiles
                (tenant_id, legal_name, vat_number, address_line1, postal_code, city, country_code, billing_email)
            VALUES (:tenant, :name, :vat, :address, :postalCode, :city, 'FR', :email)
            SQL,
            ['tenant' => $tenant, 'name' => $legalName, 'vat' => $vat, 'address' => $address, 'postalCode' => $postalCode, 'city' => $city, 'email' => demo_email('billing')],
        );

        $connection->executeStatement(
            <<<'SQL'
            INSERT INTO customer_tax_profiles (tenant_id, customer_kind, country_code, taxable_person)
            VALUES (:tenant, 'B2B', 'FR', true)
            SQL,
            ['tenant' => $tenant],
        );
    }

    $connection->commit();

    // --- The parts with invariants, through the services that own them --------

    /** @var Subscriptions $subscriptions */
    $subscriptions = $container->get(Subscriptions::class);

    /** @var Invoicing $invoicing */
    $invoicing = $container->get(Invoicing::class);

    $ada = $users['ada'];

    // Acme on Atlas Pro, Globex on Boreas Starter: two live subscriptions on
    // two products, each with the invoice it raised, so the console's
    // invoicing and the tenant's billing screens have something on them for
    // more than one product.
    $offerOf = static fn (string $product, string $offer): string => $offers[$product][$offer]
        ?? throw new RuntimeException("no offer {$offer} on {$product}");

    $live = [
        $subscriptions->subscribe($acme, $products['atlas'], $offerOf('atlas', 'pro-monthly'), $ada),
        $subscriptions->subscribe($globex, $products['boreas'], $offerOf('boreas', 'starter-monthly'), $ada),
    ];

    $invoices = [
        $invoicing->issueForSubscription($acme, $products['atlas'], $ada),
        $invoicing->issueForSubscription($globex, $products['boreas'], $ada),
    ];

    // --- And reads it back ----------------------------------------------------
    //
    // Seeding and verifying in one pass. A seeder whose output nobody checks is
    // a fixture that drifts from the schema silently, and the first person to
    // notice is somebody demonstrating the product.

    $productCount = count(DEMO_PRODUCTS);

    $checks = [
        'both subscriptions are active' => $subscriptions->current($acme, $products['atlas'])?->status === 'ACTIVE'
            && $subscriptions->current($globex, $products['boreas'])?->status === 'ACTIVE',
        'both invoices have a legal number' => array_filter(
            $invoices,
            static fn ($invoice): bool => !is_string($invoice->number) || $invoice->number === '',
        ) === [],
        'each invoice belongs to its tenant' => $invoices[0]->tenantId === $acme && $invoices[1]->tenantId === $globex,
        'three offer versions are published per product' => 3 * $productCount === demo_count(
            $connection,
            <<<'SQL'
            SELECT count(*) FROM offer_versions v
            JOIN offers o ON o.id = v.offer_id
            WHERE v.status = 'ACTIVE'
            SQL,
        ),
        // The two the readiness screen adds to "it has a catalogue": a product
        // can be entirely priced and still sell to nobody.
        'every offer is advertised' => 3 * $productCount === demo_count(
            $connection,
            'SELECT count(*) FROM offers WHERE publicly_listed',
        ),
        'every product has its tax position' => $productCount === demo_count(
            $connection,
            'SELECT count(*) FROM product_configuration WHERE key = :key',
            ['key' => SupplierTaxSettings::CONFIGURATION_KEY],
        ),
        'entitlements were granted' => 0 < demo_count(
            $connection,
            'SELECT count(*) FROM entitlements WHERE tenant_id = :tenant',
            ['tenant' => $acme],
        ),
        'a member is mirrored onto every product the tenant holds' => count($holdings['acme']) === demo_count(
            $connection,
            'SELECT count(*) FROM tenant_members WHERE tenant_id = :tenant AND user_id = :user',
            ['tenant' => $acme, 'user' => $users['grace']],
        ),
        'every role is held by exactly one person' => 2 === demo_count(
            $connection,
            'SELECT count(DISTINCT r.code) FROM tenant_member_roles m JOIN roles r ON r.id = m.role_id',
        ) && 4 === demo_count(
            $connection,
            'SELECT count(*) FROM platform_staff',
        ),
        'platform staff hold no tenant membership' => 0 === demo_count(
            $connection,
            'SELECT count(*) FROM tenant_members m JOIN platform_staff s ON s.user_id = m.user_id',
        ),
    ];

    $people = [];

    foreach (DEMO_PEOPLE as $who => $person) {
        $people[$who] = [
            'name' => $person['name'],
            'email' => demo_email($who),
            'scope' => $person['scope'],
            'role' => $person['role'],
            'tenants' => $person['tenants'],
        ];
    }

    return [
        // Returned rather than left for the caller to know: the installer prints
        // it on its success page, and a second copy of this string somewhere
        // else is an instruction that goes wrong the day this one changes.
        'password' => DEMO_PASSWORD,
        'products' => $products,
        'tenants' => $tenants,
        'people' => $people,
        'counts' => ['products' => $productCount, 'plans' => $planCount, 'features' => $featureCount, 'offers' => 3 * $productCount],
        'subscriptions' => $live,
        'invoices' => $invoices,
        'checks' => $checks,
    ];
};
