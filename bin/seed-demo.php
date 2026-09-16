<?php

declare(strict_types=1);

use App\Billing\Service\Invoicing;
use App\Commerce\Service\Subscriptions;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * The demonstration world, seeded into an empty database.
 *
 *     php bin/seed-demo.php               # seed it
 *     php bin/seed-demo.php --reset       # wipe the business tables first
 *
 * The world itself lives in `bin/demo-world.php`, which the installer calls too
 * — this file is the command line around it, and that one is the definition of
 * what a complete demonstration contains. `docs/demo-world.html` describes it
 * for whoever is about to sign in.
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
 * four products with a catalogue each, two organisations holding them, one
 * person per role the platform defines, and two live subscriptions with the
 * invoices they raised.
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
    'tenant_skins', 'tenant_member_roles', 'tenant_members', 'tenant_products', 'tenants',
    'users', 'products',
    // U12. Both cascade from `users`, so a reset that truncated users would take
    // them anyway — named here so the list stays a readable inventory of what a
    // reset removes rather than a list plus whatever happens to cascade.
    'local_credentials', 'auth_refresh_tokens',
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
//
// "One of the demo's product codes is taken" rather than "this database has
// any data": what the guard protects against — doubling a catalogue, leaving a
// demo nobody could trust — is exactly what the unique product code catches.

$seed = require __DIR__ . '/demo-world.php';

$taken = count_of(
    $connection,
    'SELECT count(*) FROM products WHERE code = ANY(CAST(:codes AS text[]))',
    ['codes' => '{' . implode(',', array_keys(DEMO_PRODUCTS)) . '}'],
);

if ($taken > 0 && !$reset) {
    fwrite(STDERR, 'A product with one of the demo codes (' . implode(', ', array_keys(DEMO_PRODUCTS)) . ") already exists.\n\n");
    fwrite(STDERR, "Seeding on top would double its catalogue and leave a demo nobody\n");
    fwrite(STDERR, "could trust. Pass --reset to wipe the business tables first.\n");
    exit(1);
}

if ($reset) {
    // One statement, so the foreign keys never see a half-empty world.
    $connection->executeStatement(
        'TRUNCATE TABLE ' . implode(', ', BUSINESS_TABLES) . ' RESTART IDENTITY CASCADE',
    );
    printf("reset: %d business tables truncated (reference data untouched)\n", count(BUSINESS_TABLES));
}

// --- The world itself, which bin/demo-world.php owns --------------------------

/**
 * The shape `bin/demo-world.php` returns, restated here.
 *
 * `require` answers `mixed`, so without this every field below is an offset on
 * an unknown value and PHPStan says so twenty times. Restated rather than
 * imported because the two are files returning a closure, not classes — there
 * is no type to import. A change there that is not made here fails the analysis
 * rather than passing quietly, which is the property worth having.
 *
 * @var callable(ContainerInterface): array{
 *     password: string,
 *     products: array<string, string>,
 *     tenants: array{acme: string, globex: string},
 *     people: array<string, array{name: string, email: string, scope: string, role: string, tenants: list<string>}>,
 *     counts: array{products: int, plans: int, features: int, offers: int},
 *     subscriptions: list<\App\Commerce\Domain\Subscription>,
 *     invoices: list<\App\Billing\Domain\Invoice>,
 *     checks: array<string, bool>
 * } $seed
 */
$world = $seed($container);

// --- What it made -------------------------------------------------------------

printf("\nSeeded the demonstration world.\n\n");

foreach ($world['products'] as $code => $id) {
    printf("  product      %-8s %s\n", $code, $id);
}

printf("  tenants      acme     %s  (holds all four)\n", $world['tenants']['acme']);
printf("               globex   %s  (holds atlas, boreas)\n", $world['tenants']['globex']);

foreach ($world['people'] as $person) {
    printf("  %-12s %-16s %-14s %s\n", $person['scope'], $person['email'], $person['role'], $person['name']);
}

printf(
    "  catalogue    %d products x (%d plans, %d features, %d offers), all advertised\n",
    $world['counts']['products'],
    intdiv($world['counts']['plans'], $world['counts']['products']),
    intdiv($world['counts']['features'], $world['counts']['products']),
    intdiv($world['counts']['offers'], $world['counts']['products']),
);

foreach ($world['subscriptions'] as $i => $subscription) {
    $invoice = $world['invoices'][$i] ?? null;
    printf("  subscription %s (%s)  invoice %s\n", $subscription->id, $subscription->status, $invoice === null ? '(none)' : ($invoice->number ?? '(no number)'));
}

printf("\n");

foreach ($world['checks'] as $what => $ok) {
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $what);
}

if (array_filter($world['checks'], static fn (bool $ok): bool => !$ok) !== []) {
    fwrite(STDERR, "\nThe seeded world does not hold. Nothing above can be trusted.\n");
    exit(1);
}

printf("\nEvery account above signs in with the password %s - see docs/demo-world.html.\n", DEMO_PASSWORD);
printf("Start at /sign-in?product=atlas as %s (tenant) or %s (console).\n", $world['people']['ada']['email'], $world['people']['sam']['email']);
printf("The API needs AUTH_SIGNING_SECRET set to at least 32 characters, or sign-in answers 503.\n");

exit(0);
