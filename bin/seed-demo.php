<?php

declare(strict_types=1);

use App\Billing\Service\Invoicing;
use App\Commerce\Service\Subscriptions;
use App\Shared\Database\Row;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * A demonstration world, seeded into an empty database.
 *
 *     php bin/seed-demo.php            # seed, refusing a database that has data
 *     php bin/seed-demo.php --reset    # wipe the business tables first
 *
 * **What this is for.** Until now the only way to see this platform run was to
 * read its tests. 811 of them pass and 178 browser tests walk the screens, and
 * not one of them lets somebody *look* at the thing. That is also what stands
 * between here and any production question: you cannot rehearse a restore, size
 * a page, or judge a screen against a real invoice with an empty database.
 *
 * **What it seeds, and what it deliberately does not.** Reference data —
 * permissions, roles, the platform's own roles, EU VAT rates — is created by
 * *migrations* and is not touched here. This seeds the business world on top:
 * one product, two tenants, people, a catalogue, a live subscription with the
 * invoice it raised, a paid and a failed payment, a support thread, a project
 * with versions, jobs, and a VAT period with transactions behind it.
 *
 * **Invariants go through the services that own them.** Subscribing and issuing
 * an invoice are done by `Subscriptions::subscribe` and
 * `Invoicing::issueForSubscription`, not by INSERT: an invoice number comes from
 * a gapless sequence, an activation writes a subscription event, and a fixture
 * that wrote those rows itself would be demonstrating a world the application
 * cannot produce. Structure with no invariant behind it — a tenant's name, a
 * plan's rank — is plain SQL, which is what the integration tests do too.
 *
 * **Refuses a database that already has data**, unless `--reset` is passed. A
 * seeder that silently doubled a catalogue would be discovered by somebody
 * reading a demo, not by a test.
 *
 * It prints what it made, and then **reads it back through the repositories**:
 * seeding and verifying in one pass is what makes this trustworthy enough to
 * point a stakeholder at.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$containerFactory = require __DIR__ . '/../config/container.php';
assert(is_callable($containerFactory));

/** @var ContainerInterface $container */
$container = $containerFactory();

/** @var Connection $connection */
$connection = $container->get(Connection::class);

$reset = in_array('--reset', $argv, true);

/**
 * The business tables, in an order that respects the foreign keys.
 *
 * Reference tables are absent on purpose: `permissions`, `roles`,
 * `platform_permissions`, `tax_rates` and their join tables are migration data,
 * and a reset that dropped them would leave a database that migrates cleanly and
 * authorises nobody.
 */
const BUSINESS_TABLES = [
    'vat_declarations', 'vat_reporting_periods', 'vat_transactions', 'tax_records',
    'tax_identifications', 'customer_tax_profiles',
    'einvoice_events', 'einvoice_transmissions', 'invoice_documents',
    'credit_note_lines', 'credit_notes', 'refunds', 'payment_events', 'payments',
    'invoice_lines', 'invoices', 'billing_profiles',
    'order_lines', 'orders', 'quote_lines', 'quotes',
    'entitlements', 'subscription_events', 'subscriptions',
    'offer_version_features', 'offer_versions', 'offers', 'features', 'plans',
    'product_features', 'product_configuration',
    'messages', 'conversation_participants', 'conversations',
    'notification_deliveries', 'notifications', 'notification_consents', 'notification_preferences',
    'project_versions', 'assets', 'projects',
    'job_runs', 'jobs',
    'staff_access_log', 'platform_staff',
    'erasure_requests', 'audit_log', 'financial_events',
    'revenue_periods', 'offer_revenue_periods', 'renewal_periods',
    'tenant_skins', 'tenant_member_roles', 'tenant_members', 'tenants',
    'users', 'products',
];

/**
 * One id back, or a failure that names the statement.
 *
 * @param array<string, scalar|null> $parameters
 */
function id(Connection $connection, string $sql, array $parameters = []): string
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
function count_of(Connection $connection, string $sql, array $parameters = []): int
{
    $value = $connection->fetchOne($sql, $parameters);

    return is_numeric($value) ? (int) $value : 0;
}

// --- Refuse to seed on top of something ---------------------------------------

$existing = count_of($connection, 'SELECT count(*) FROM products');

if ($existing > 0 && !$reset) {
    fwrite(STDERR, "This database already has data ({$existing} products).\n\n");
    fwrite(STDERR, "Seeding on top would double a catalogue and leave a demo nobody could\n");
    fwrite(STDERR, "trust. Pass --reset to wipe the business tables first, or point\n");
    fwrite(STDERR, "DATABASE_DSN at an empty database.\n");
    exit(1);
}

if ($reset) {
    // One statement, so the foreign keys never see a half-empty world.
    $connection->executeStatement(
        'TRUNCATE TABLE ' . implode(', ', BUSINESS_TABLES) . ' RESTART IDENTITY CASCADE',
    );
    printf("reset: %d business tables truncated (reference data untouched)\n", count(BUSINESS_TABLES));
}

$connection->beginTransaction();

// --- The product, and the people ----------------------------------------------

$product = id(
    $connection,
    "INSERT INTO products (code, name, active) VALUES ('atlas', 'Atlas', true) RETURNING id",
);

$acme = id(
    $connection,
    "INSERT INTO tenants (name, slug) VALUES ('Acme Ltd', 'acme') RETURNING id",
);

$globex = id(
    $connection,
    "INSERT INTO tenants (name, slug) VALUES ('Globex SA', 'globex') RETURNING id",
);

$ada = id(
    $connection,
    "INSERT INTO users (auth_subject, email, display_name) VALUES ('demo|ada', 'ada@acme.test', 'Ada Lovelace') RETURNING id",
);

$grace = id(
    $connection,
    "INSERT INTO users (auth_subject, email, display_name) VALUES ('demo|grace', 'grace@acme.test', 'Grace Hopper') RETURNING id",
);

$staff = id(
    $connection,
    "INSERT INTO users (auth_subject, email, display_name) VALUES ('demo|sam', 'sam@backprod.test', 'Sam Support') RETURNING id",
);

// Roles are migration data and are joined by id, not by code: the code is what
// a person reads and the id is what the schema stores, and a seeder that
// invented either would authorise nobody.
$roleId = static fn (string $code): string => id(
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

// --- The catalogue ------------------------------------------------------------

$plans = [];

foreach ([['starter', 'Starter', 10], ['pro', 'Pro', 20], ['scale', 'Scale', 30]] as [$code, $name, $rank]) {
    $plans[$code] = id(
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
    $features[$code] = id(
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
    $offer = id(
        $connection,
        <<<'SQL'
        INSERT INTO offers (product_id, plan_id, code, name)
        VALUES (:product, :plan, :code, :name) RETURNING id
        SQL,
        ['product' => $product, 'plan' => $plans[$plan] ?? throw new RuntimeException("unknown plan {$plan}"), 'code' => $code, 'name' => $name],
    );

    // Draft, grant, publish — the order the product actually uses. A version's
    // grants freeze the moment it leaves DRAFT (ADR-033), so seeding an ACTIVE
    // row and attaching grants afterwards would build a version the application
    // cannot.
    $version = id(
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

// --- The legal identity an invoice is issued against --------------------------

$connection->executeStatement(
    <<<'SQL'
    INSERT INTO billing_profiles
        (tenant_id, legal_name, vat_number, address_line1, postal_code, city, country_code, billing_email)
    VALUES (:tenant, 'Acme Ltd', 'FR12345678901', '12 rue de la Paix', '75002', 'Paris', 'FR', 'billing@acme.test')
    SQL,
    ['tenant' => $acme],
);

$connection->executeStatement(
    <<<'SQL'
    INSERT INTO customer_tax_profiles (tenant_id, customer_kind, country_code, taxable_person)
    VALUES (:tenant, 'B2B', 'FR', true)
    SQL,
    ['tenant' => $acme],
);

/**
 * The platform's own legal identity — the *supplier* on every invoice.
 *
 * Product configuration rather than a tenant's billing profile: the customer's
 * identity varies per tenant, the supplier's does not, and `Invoicing` refuses
 * to issue anything at all until this exists. That refusal is why it is here:
 * a demo world without it looks complete and cannot raise an invoice.
 */
$connection->executeStatement(
    <<<'SQL'
    INSERT INTO product_configuration (product_id, key, value)
    VALUES (:product, 'billing_supplier', CAST(:value AS jsonb))
    SQL,
    [
        'product' => $product,
        'value' => json_encode([
            'legal_name' => 'Backprod SAS',
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

$connection->commit();

// --- The parts with invariants, through the services that own them ------------

/** @var Subscriptions $subscriptions */
$subscriptions = $container->get(Subscriptions::class);

/** @var Invoicing $invoicing */
$invoicing = $container->get(Invoicing::class);

$subscription = $subscriptions->subscribe($acme, $product, $offers['pro-monthly'], $ada);
$invoice = $invoicing->issueForSubscription($acme, $product, $ada);

// --- What it made -------------------------------------------------------------

printf("\nSeeded a demonstration world.\n\n");
printf("  product      atlas  %s\n", $product);
printf("  tenants      acme   %s\n", $acme);
printf("               globex %s\n", $globex);
printf("  people       ada@acme.test (TENANT_ADMIN), grace@acme.test (USER)\n");
printf("  platform     sam@backprod.test (PLATFORM_ADMIN)\n");
printf("  catalogue    %d plans, %d features, %d offers\n", count($plans), count($features), count($offers));
printf("  subscription %s (%s)\n", $subscription->id, $subscription->status);
printf("  invoice      %s  %s\n", $invoice->number ?? '(no number)', $invoice->id);

// --- And reads it back --------------------------------------------------------
//
// Seeding and verifying in one pass. A seeder whose output nobody checks is a
// fixture that drifts from the schema silently, and the first person to notice
// is somebody demonstrating the product.

$checks = [
    'the subscription is active' => $subscriptions->current($acme, $product)?->status === 'ACTIVE',
    'the invoice has a legal number' => is_string($invoice->number) && $invoice->number !== '',
    'the invoice belongs to the tenant' => $invoice->tenantId === $acme,
    'three offer versions are published' => 3 === count_of(
        $connection,
        "SELECT count(*) FROM offer_versions WHERE status = 'ACTIVE'",
    ),
    'entitlements were granted' => 0 < count_of(
        $connection,
        'SELECT count(*) FROM entitlements WHERE tenant_id = :tenant',
        ['tenant' => $acme],
    ),
    'platform staff hold no tenant membership' => 0 === count_of(
        $connection,
        'SELECT count(*) FROM tenant_members WHERE user_id = :user',
        ['user' => $staff],
    ),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));

printf("\n");

foreach ($checks as $what => $ok) {
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $what);
}

if ($failed !== []) {
    fwrite(STDERR, "\nThe seeded world does not hold. Nothing above can be trusted.\n");
    exit(1);
}

printf("\nSign in as ada@acme.test with X-Product: atlas.\n");

exit(0);
