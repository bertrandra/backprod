<?php

declare(strict_types=1);

use App\Billing\Service\Invoicing;
use App\Commerce\Service\Subscriptions;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * A demonstration world, seeded into an empty database.
 *
 *     php bin/seed-demo.php                       # seed `atlas`
 *     php bin/seed-demo.php --product=licorne     # a world of its own, beside it
 *     php bin/seed-demo.php --reset               # wipe the business tables first
 *
 * The world itself lives in `bin/demo-world.php`, which the installer calls too
 * — this file is the command line around it, and that one is the definition of
 * what a complete demonstration contains.
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
 * Which product this world is for.
 *
 * `atlas` when nobody says otherwise, which is what every existing invocation
 * and both composer scripts expect. The installer passes `licorne`, and a second
 * demo beside the first is `--product=…` away — every name in the world is
 * derived from this, so two of them never collide.
 */
$option = static function (string $name, string $fallback) use ($argv): string {
    foreach ($argv as $argument) {
        if (str_starts_with($argument, "--{$name}=")) {
            return substr($argument, strlen($name) + 3);
        }
    }

    return $fallback;
};

$productCode = strtolower(trim($option('product', 'atlas')));
$productName = trim($option('name', ucfirst($productCode)));

if (preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $productCode) !== 1) {
    fwrite(STDERR, "--product must be a code: lower-case letters, digits and hyphens.\n");
    exit(1);
}

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
// Narrowed from "this database has any data" to "this product code is taken".
// The old guard was right when there was one demo world and it was the only
// thing in the database; it is wrong now that the installer can seed a demo
// beside a real product it has just created, and that two demo products can
// coexist under codes of their own. What it was actually protecting against —
// doubling a catalogue, leaving a demo nobody could trust — is exactly what the
// unique product code catches, and catches per product rather than per database.

$taken = count_of(
    $connection,
    'SELECT count(*) FROM products WHERE code = :code',
    ['code' => $productCode],
);

if ($taken > 0 && !$reset) {
    fwrite(STDERR, "A product with the code '{$productCode}' already exists.\n\n");
    fwrite(STDERR, "Seeding on top would double its catalogue and leave a demo nobody\n");
    fwrite(STDERR, "could trust. Pass --reset to wipe the business tables first, or\n");
    fwrite(STDERR, "--product=<code> to seed a world of its own.\n");
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
 * @var callable(ContainerInterface, string, string): array{
 *     product: string,
 *     product_code: string,
 *     password: string,
 *     tenants: array{acme: string, globex: string},
 *     people: array{admin: string, user: string, staff: string},
 *     counts: array{plans: int, features: int, offers: int},
 *     subscription: \App\Commerce\Domain\Subscription,
 *     invoice: \App\Billing\Domain\Invoice,
 *     checks: array<string, bool>
 * } $seed
 */
$seed = require __DIR__ . '/demo-world.php';

$world = $seed($container, $productCode, $productName);

// --- What it made -------------------------------------------------------------

printf("\nSeeded a demonstration world.\n\n");
printf("  product      %-8s %s\n", $productCode, $world['product']);
printf("  tenants      acme     %s\n", $world['tenants']['acme']);
printf("               globex   %s\n", $world['tenants']['globex']);
printf("  people       %s (TENANT_ADMIN), %s (USER)\n", $world['people']['admin'], $world['people']['user']);
printf("  platform     %s (PLATFORM_ADMIN)\n", $world['people']['staff']);
printf(
    "  catalogue    %d plans, %d features, %d offers, all advertised\n",
    $world['counts']['plans'],
    $world['counts']['features'],
    $world['counts']['offers'],
);

$subscription = $world['subscription'];
$invoice = $world['invoice'];

printf("  subscription %s (%s)\n", $subscription->id, $subscription->status);
printf("  invoice      %s  %s\n", $invoice->number ?? '(no number)', $invoice->id);

printf("\n");

foreach ($world['checks'] as $what => $ok) {
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $what);
}

if (array_filter($world['checks'], static fn (bool $ok): bool => !$ok) !== []) {
    fwrite(STDERR, "\nThe seeded world does not hold. Nothing above can be trusted.\n");
    exit(1);
}

printf("\nSign in at /sign-in?product=%s as %s with the password %s\n", $productCode, $world['people']['admin'], DEMO_PASSWORD);
printf("Also seeded: %s (USER) and %s (platform support).\n", $world['people']['user'], $world['people']['staff']);
printf("The API needs AUTH_SIGNING_SECRET set to at least 32 characters, or sign-in answers 503.\n");

exit(0);
