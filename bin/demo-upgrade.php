<?php

declare(strict_types=1);

use App\Demo\Domain\DemoWorld;
use App\Demo\Service\DemoSeeder;
use App\Shared\Exceptions\ConflictException;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * Completes the demonstration world on a deployment already seeded with it,
 * without a reset (2026-11-17).
 *
 *     php backprod-app/bin/demo-upgrade.php --dry-run    # say what would be added
 *     php backprod-app/bin/demo-upgrade.php              # add it
 *
 * A deployment is seeded once by its installer and then keeps everything its
 * people make, so it is never reset. When the world gains an organisation —
 * Parso, Acme's twin — this adds it, with its people, seats, invoices and
 * projects, through `DemoSeeder::complete()`: the same code `setup.php`'s
 * upgrade button runs after the migrations, for whoever has SSH. Nothing that
 * exists is changed, and a second run adds nothing.
 *
 * Since 2026-11-21 it brings a product's page what the world gained too — the
 * bands that sell and the layouts — and only what the page never said: a band
 * that has rows gets none, a heading or a layout somebody wrote stays theirs.
 *
 * Run it **after** the migrations (`bin/upgrade.php`, or `setup.php`): it is
 * written against the schema of the code it ships with. Refused on a
 * deployment that is not the demonstration's — one hosting a product the
 * world does not name, or one where it was never seeded.
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$containerFactory = require __DIR__ . '/../config/container.php';
assert(is_callable($containerFactory));

/** @var ContainerInterface $container */
$container = $containerFactory();

/** @var DemoSeeder $seeder */
$seeder = $container->get(DemoSeeder::class);

$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    if (!$seeder->isDemoDeployment()) {
        fwrite(STDERR, "This deployment is not the demonstration's — never seeded with it, or hosting a product it does not name. Nothing would be done.\n");
        exit(1);
    }

    $missing = $seeder->missing();

    if ($missing === []) {
        printf("The demonstration is complete. Nothing would be added.\n");
        exit(0);
    }

    printf("Would complete the demonstration (nothing changed):\n");

    foreach ($missing as $slug) {
        printf("  %s\n", DemoWorld::describeAddition($slug));
    }

    exit(0);
}

try {
    $completed = $seeder->complete();
} catch (ConflictException $refused) {
    fwrite(STDERR, $refused->getMessage() . "\n");
    $products = $refused->details()['products'] ?? [];

    if (is_array($products) && $products !== []) {
        fwrite(STDERR, 'Products that are not the demonstration\'s: ' . implode(', ', array_filter($products, is_string(...))) . "\n");
    }

    exit(1);
}

if ($completed->addedNothing()) {
    printf("The demonstration is complete. Nothing was added.\n");
    exit(0);
}

printf("Completed the demonstration.\n\n");

foreach ($completed->summary() as $line) {
    printf("  %s\n", $line);
}

printf("\n");

foreach ($completed->checks as $what => $ok) {
    printf("  %s %s\n", $ok ? 'ok  ' : 'FAIL', $what);
}

if ($completed->people !== []) {
    printf("\nThe new accounts sign in with the password %s, which is in this platform's source.\n", DemoWorld::PASSWORD);
}

exit(0);
