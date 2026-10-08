<?php

declare(strict_types=1);

use App\Shared\Database\ConnectionFactory;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;
use Dotenv\Dotenv;

/**
 * Upgrades a running deployment to this bundle, keeping its database, its
 * configuration and everything it has stored (2026-10-08).
 *
 * Run it from the **new** bundle, unpacked on the host, and point it at the
 * live site — the directory holding `public_html/` and `backprod-app/`:
 *
 *     php ~/backprod-new/backprod-app/bin/upgrade.php ~/www/example.com --dry-run
 *     php ~/backprod-new/backprod-app/bin/upgrade.php ~/www/example.com
 *
 * `setup.php` is not the way: it installs, and once `var/.setup-complete`
 * exists it refuses to run at all — which is the point of it, because it
 * writes `.env`. An upgrade is a different act with different invariants,
 * and they are the ones this script exists to hold:
 *
 * - **`.env` and `var/` are the deployment's, never the bundle's.** The
 *   bundle carries neither a `.env` nor anything under `var/` but two empty
 *   directories, so an upload that replaced `backprod-app/` wholesale took
 *   the signing secrets, every uploaded file and the marker that keeps the
 *   installer shut. They are carried across here, by rename.
 * - **The database moves first.** Migrations add; they are written so the
 *   code already running keeps working on the newer schema, while new code
 *   on an old schema is what breaks. So the schema goes forward before any
 *   file changes, in one transaction, and a failure there changes nothing.
 * - **`setup.php` never comes back.** It is left out of the new document
 *   root, and one still sitting in the live one is moved aside.
 * - **Nothing is deleted.** The previous application and the public files it
 *   replaced go to `backprod-app.previous-<time>/`, and the script ends by
 *   printing the commands that put them back. Delete that directory once the
 *   new version has been seen working.
 *
 * Every rename stays on one filesystem — the script refuses otherwise — so
 * the swap is a handful of directory entries rather than a copy, and the
 * window in which a request could see half of each is milliseconds.
 *
 * What it does not do: flush SiteGround's cache (Site Tools → Speed →
 * Caching), which would otherwise go on serving the previous `index.html`
 * and the scripts it names, which no longer exist.
 */

const PUBLIC_OWNED = ['index.php', 'index.html', '.htaccess', 'assets'];

$newApp = dirname(__DIR__);
$newRoot = dirname($newApp);

$arguments = array_values(array_filter(array_slice($argv, 1), static fn (string $a): bool => !str_starts_with($a, '--')));
$dryRun = in_array('--dry-run', $argv, true);

if (count($arguments) !== 1) {
    fwrite(STDERR, "Usage: php bin/upgrade.php <live site directory> [--dry-run]\n"
        . "       The live site directory holds public_html/ and backprod-app/.\n");
    exit(2);
}

$live = rtrim((string) realpath($arguments[0]) ?: $arguments[0], '/');
$liveApp = $live . '/backprod-app';
$livePublic = $live . '/public_html';
$newPublic = $newRoot . '/public_html';

function say(string $line = ''): void
{
    fwrite(STDOUT, $line . "\n");
}

function refuse(string $why): never
{
    fwrite(STDERR, "\nRefused: {$why}\nNothing was changed.\n");
    exit(1);
}

// --- 1. Everything checked before anything is touched ----------------------

if (!is_file($newApp . '/vendor/autoload.php') || !is_file($newPublic . '/index.php') || !is_file($newRoot . '/BUNDLE.txt')) {
    refuse('run this from an unpacked bundle: it needs BUNDLE.txt, public_html/ and backprod-app/ beside each other.');
}

if (!is_dir($liveApp) || !is_dir($livePublic)) {
    refuse("{$live} does not hold both public_html/ and backprod-app/.");
}

if (realpath($liveApp) === realpath($newApp)) {
    refuse('the bundle and the live site are the same directory; unpack the new bundle somewhere else.');
}

if (!is_file($liveApp . '/.env')) {
    refuse("{$liveApp}/.env is missing. An upgrade keeps the deployment's configuration; without one there is nothing to keep — install instead.");
}

if (!is_file($liveApp . '/var/.setup-complete')) {
    // Without the marker the installer is open, and the installer writes
    // .env. Somebody upgrading a deployment that never finished setting up
    // should finish that first rather than discover it from a stranger.
    refuse("{$liveApp}/var/.setup-complete is missing, so setup.php would still run on this site. Finish the installation first.");
}

foreach ([$live, $livePublic, dirname($newApp)] as $directory) {
    if (!is_writable($directory)) {
        refuse("{$directory} is not writable by this user.");
    }
}

$liveStat = @stat($liveApp);
$newStat = @stat($newApp);

if ($liveStat === false || $newStat === false || $liveStat['dev'] !== $newStat['dev']) {
    // A rename across filesystems is a copy and a delete, and a copy can stop
    // halfway. Unpacking the bundle under the same home directory avoids it.
    refuse('the bundle and the live site are on different filesystems; unpack the bundle under the same home directory as the site.');
}

$liveIndex = (string) file_get_contents($livePublic . '/index.php');
$newIndex = (string) file_get_contents($newPublic . '/index.php');
$constant = '/^const BACKPROD_APP = .*$/m';

if (preg_match($constant, $liveIndex, $was) === 1 && preg_match($constant, $newIndex, $will) === 1 && $was[0] !== $will[0]) {
    // The one line deploying-to-siteground.md §4 says to edit where the host
    // refuses to read outside the document root. Overwriting it would point
    // the site at a directory it cannot open.
    refuse("public_html/index.php names the application differently from the bundle ({$was[0]}). Edit the bundle's index.php to match, then run this again.");
}

require $newApp . '/vendor/autoload.php';

// The live configuration, read through the new code: the database it names
// is the one being upgraded.
$env = Dotenv::createArrayBacked($liveApp)->safeLoad();
$dsn = $env['DATABASE_DSN'] ?? '';

if ($dsn === '') {
    refuse("{$liveApp}/.env sets no DATABASE_DSN.");
}

try {
    $connection = ConnectionFactory::fromDsn($dsn);
    $connection->executeQuery('SELECT 1');
} catch (\Throwable $e) {
    refuse('the database in .env does not answer: ' . $e->getMessage());
}

$config = require $newApp . '/migrations.php';

if (!is_array($config)) {
    refuse('backprod-app/migrations.php in the bundle did not return its configuration.');
}

/** @var array<string, mixed> $config */
$migrations = DependencyFactory::fromConnection(new ConfigurationArray($config), new ExistingConnection($connection));
$migrations->getMetadataStorage()->ensureInitialized();
$plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(
    $migrations->getVersionAliasResolver()->resolveVersionAlias('latest'),
);

$from = preg_match('/^\s*from\s+(\S+)/m', (string) file_get_contents($newRoot . '/BUNDLE.txt'), $m) === 1 ? $m[1] : 'unknown';

say("Upgrading {$live}");
say("  to the bundle built from {$from}");
say(sprintf('  migrations to apply: %d', count($plan)));

foreach ($plan->getItems() as $item) {
    say('    ' . $item->getVersion());
}

if ($dryRun) {
    say();
    say('Dry run: every check passed and nothing was changed.');
    exit(0);
}

// --- 2. The database, first, in one transaction -----------------------------

if (count($plan) > 0) {
    try {
        $migrations->getMigrator()->migrate($plan, (new MigratorConfiguration())->setAllOrNothing(true));
    } catch (\Throwable $e) {
        refuse('the migrations failed and were rolled back: ' . $e->getMessage());
    }

    say('Migrated.');
}

// --- 3. The application, swapped by rename ----------------------------------

$previous = $live . '/backprod-app.previous-' . gmdate('Ymd-His');
$previousPublic = $previous . '/public_html-replaced';
$done = [];

/** Moves one entry, remembering it so a failure can put everything back. */
$move = static function (string $from, string $to) use (&$done): void {
    if (!@rename($from, $to)) {
        throw new \RuntimeException("could not move {$from} to {$to}");
    }

    $done[] = [$from, $to];
};

try {
    // The bundle's own var/ holds two empty directories; the deployment's
    // takes its place.
    foreach (['assets', 'pdf'] as $empty) {
        @rmdir($newApp . '/var/' . $empty);
    }
    @rmdir($newApp . '/var');

    $move($liveApp, $previous);
    $move($newApp, $liveApp);
    $move($previous . '/var', $liveApp . '/var');

    // Copied rather than moved, so the previous application keeps a working
    // configuration for as long as it might be needed back.
    if (!copy($previous . '/.env', $liveApp . '/.env')) {
        throw new \RuntimeException('could not copy .env');
    }

    @chmod($liveApp . '/.env', 0o640);

    if (!mkdir($previousPublic)) {
        throw new \RuntimeException("could not create {$previousPublic}");
    }

    foreach (PUBLIC_OWNED as $name) {
        if (file_exists($livePublic . '/' . $name)) {
            $move($livePublic . '/' . $name, $previousPublic . '/' . $name);
        }

        $move($newPublic . '/' . $name, $livePublic . '/' . $name);
    }

    if (file_exists($livePublic . '/setup.php')) {
        $move($livePublic . '/setup.php', $previousPublic . '/setup.php');
    }
} catch (\Throwable $e) {
    // Undone in reverse, so the site is back as it was. The database stays
    // migrated, which the code that was running tolerates by construction.
    foreach (array_reverse($done) as [$from, $to]) {
        @rename($to, $from);
    }

    // The copy of .env, if it was made, is back inside the unpacked bundle
    // now, and a bundle is not where secrets are kept.
    @unlink($newApp . '/.env');

    fwrite(STDERR, "\nFailed while swapping files: {$e->getMessage()}\n"
        . "Every move was undone; the site runs the previous version on the migrated database.\n");
    exit(1);
}

say('Swapped. The previous version is in ' . $previous);

// --- 4. Proved, then how to go back -----------------------------------------

say();

$status = 0;
$preflight = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($liveApp . '/bin/preflight.php') . ' --strict';
$disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));

if (function_exists('passthru') && !in_array('passthru', $disabled, true)) {
    passthru($preflight, $status);
    say();
    say($status === 0 ? 'Preflight passed.' : 'Preflight reported problems — read them above before going further.');
} else {
    say('This PHP cannot run another command. Run the preflight yourself:');
    say('  ' . $preflight);
}

$p = escapeshellarg($previous);
$a = escapeshellarg($liveApp);
$pub = escapeshellarg($livePublic);

say();
say('Next: flush the cache in Site Tools → Speed → Caching, then sign in and look.');
say();
say('To go back to the previous version (the database stays migrated):');
say("  mv {$a}/var {$p}/var && mv {$a} {$a}.failed && mv {$p} {$a}");
say("  rm -rf {$pub}/assets && cp -a {$a}/public_html-replaced/. {$pub}/ && rm {$pub}/setup.php 2>/dev/null");
say();
say("Once the new version has been seen working: rm -rf {$p}");

exit($status === 0 ? 0 : 1);
