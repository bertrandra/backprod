<?php

declare(strict_types=1);

use App\Demo\Domain\DemoWorld;
use App\Demo\Service\DemoSeeder;
use App\Shared\Exceptions\ConflictException;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * The demonstration world, seeded into an empty database.
 *
 *     php bin/seed-demo.php               # seed it
 *     php bin/seed-demo.php --reset       # wipe the business tables first
 *
 * The world itself is `App\Demo\Service\DemoSeeder`, which the installer and
 * the console's reset use too — this file is the command line around it, and
 * that one is the definition of what a complete demonstration contains.
 * `docs/demo-world.html` describes it for whoever is about to sign in.
 *
 * **What this is for.** Until it existed the only way to see this platform run
 * was to read its tests, and not one of them lets somebody *look* at the
 * thing. That is also what stands between here and any production question:
 * you cannot rehearse a restore, size a page, or judge a screen against a real
 * invoice with an empty database.
 *
 * **What it seeds, and what it deliberately does not.** Reference data —
 * permissions, roles, the platform's own roles, EU VAT rates — is created by
 * *migrations* and is not touched here. This seeds the business world on top:
 * four products with a catalogue each, two organisations holding them, one
 * person per role the platform defines, and two live subscriptions with the
 * invoices they raised.
 *
 * **Refuses a database that already holds the demo**, unless `--reset` is
 * passed. A seeder that silently doubled a catalogue would be discovered by
 * somebody reading a demo, not by a test. `--reset` is refused in turn while
 * a product that is not the demonstration's exists: that is somebody's real
 * product, and no flag on a command line should take it.
 *
 * It prints what it made, and then **reads it back**: seeding and verifying in
 * one pass is what makes this trustworthy enough to point a stakeholder at.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
// And the payment provider's keys, when kept apart (2026-09-18); `.env` wins.
Dotenv::createImmutable(dirname(__DIR__), 'payment.env')->safeLoad();

$containerFactory = require __DIR__ . '/../config/container.php';
assert(is_callable($containerFactory));

/** @var ContainerInterface $container */
$container = $containerFactory();

/** @var DemoSeeder $seeder */
$seeder = $container->get(DemoSeeder::class);

$reset = in_array('--reset', $argv, true);

try {
    $world = $reset ? $seeder->reset() : $seeder->seed();
} catch (ConflictException $refused) {
    fwrite(STDERR, $refused->getMessage() . "\n\n");

    if ($refused->errorCode() === DemoSeeder::ALREADY_SEEDED) {
        fwrite(STDERR, "Pass --reset to wipe the business tables first.\n");
    } else {
        $products = $refused->details()['products'] ?? [];
        fwrite(STDERR, 'Products that are not the demonstration\'s: ' . implode(', ', is_array($products) ? array_filter($products, is_string(...)) : []) . "\n");
        fwrite(STDERR, "Retire and remove them first, or seed into a database of their own.\n");
    }

    exit(1);
}

// --- What it made -------------------------------------------------------------

printf("\n%s the demonstration world.\n\n", $reset ? 'Reset' : 'Seeded');

foreach ($world->structure->products as $code => $id) {
    printf("  product      %-8s %s\n", $code, $id);
}

foreach (DemoWorld::TENANTS as $key => $tenant) {
    printf("  tenant       %-8s %s  (holds %s)\n", $key, $world->structure->tenant($key), implode(', ', $tenant['holds']));
}

foreach ($world->people() as $person) {
    printf("  %-12s %-16s %-14s %s\n", $person['scope'], $person['email'], $person['role'], $person['name']);
}

printf("  catalogue    %d products x (3 plans, 3 features, 3 offers), all advertised\n", count(DemoWorld::PRODUCTS));

foreach ($world->subscriptions as $i => $subscription) {
    $invoice = $world->invoices[$i] ?? null;
    printf(
        "  subscription %s (%s)  invoice %s\n",
        $subscription->id,
        $subscription->status,
        $invoice === null ? '(none)' : ($invoice->number ?? '(no number)'),
    );
}

printf("\n");

foreach ($world->checks as $what => $ok) {
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $what);
}

if (!$world->holds()) {
    fwrite(STDERR, "\nThe seeded world does not hold. Nothing above can be trusted.\n");
    exit(1);
}

printf("\nEvery account above signs in with the password %s - see docs/demo-world.html.\n", DemoWorld::PASSWORD);
printf("Start at /sign-in?product=atlas as %s (tenant) or %s (console).\n", DemoWorld::email('ada'), DemoWorld::email('sam'));
printf("The API needs AUTH_SIGNING_SECRET set to at least 32 characters, or sign-in answers 503.\n");

exit(0);
