<?php

declare(strict_types=1);

use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Psr\Container\ContainerInterface;

/**
 * What this deployment can and cannot do, said out loud before it serves anyone.
 *
 *     php bin/preflight.php          # report, exit 0 unless something is broken
 *     php bin/preflight.php --strict # also fail on anything a production
 *                                    # deployment should not be missing
 *
 * **The problem this exists for.** Every secret in this platform defaults to
 * "off", deliberately and for good reasons: an empty `SUPABASE_JWKS` verifies no
 * key and authenticates nobody, an empty payment secret means no webhook whose
 * signature anybody could forge is ever accepted. Each of those is the safe
 * default. Together they are a deployment that starts cleanly, answers
 * `/health` with `ok`, and quietly does almost nothing — and nothing in the
 * platform ever says so.
 *
 * That is the same class of defect as the six wrong permission codes in U1 and
 * the doubled API prefix in U6: a promise nothing checks. This checks it.
 *
 * **It reports rather than refuses, unless asked.** In development, half-
 * configured is the normal state and a script that exited non-zero would be
 * noise. `--strict` is what a deploy pipeline runs: there, a missing signing
 * secret is not a warning.
 *
 * It never prints a secret. It prints whether one is present, which is the only
 * thing an operator needs and the only thing safe to put in a deploy log (§31).
 */

require __DIR__ . '/../vendor/autoload.php';

Dotenv::createImmutable(dirname(__DIR__))->safeLoad();

$strict = in_array('--strict', $argv, true);

function env(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? getenv($key);

    return is_string($value) && $value !== '' ? $value : $default;
}

/** Present or absent — never the value. */
function configured(string $key): bool
{
    return env($key) !== '';
}

/**
 * One thing this deployment either can or cannot do.
 *
 * `required` means "a production deployment without this is misconfigured", and
 * is what `--strict` acts on. Everything else is a capability that is simply
 * absent, which is a fact rather than a fault.
 */
final class Capability
{
    public function __construct(
        public readonly string $what,
        public readonly bool $ready,
        public readonly string $consequence,
        public readonly bool $required = false,
    ) {
    }
}

$capabilities = [
    new Capability(
        'Authentication',
        // Either way of doing it counts. U12 made the platform able to issue its
        // own tokens; ADR-014's external provider still works, and a deployment
        // with neither authenticates nobody.
        // Length checked, not just presence: HS256 refuses a key shorter than the
        // hash, so a 20-character secret is a deployment that answers 503 on
        // sign-in and would otherwise be reported here as ready.
        (strlen(env('AUTH_SIGNING_SECRET')) >= LocalJwtTokenIssuer::MINIMUM_SECRET_BYTES)
        || (configured('SUPABASE_JWKS') && configured('SUPABASE_ISSUER')),
        'No signing secret of at least ' . LocalJwtTokenIssuer::MINIMUM_SECRET_BYTES . ' characters, and no external '
        . 'JWK set either — so nobody can sign in and no token verifies. Every request is anonymous and every '
        . 'resource endpoint refuses it. Generate one with: '
        . "php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'",
        required: true,
    ),
    new Capability(
        'Database',
        configured('DATABASE_DSN'),
        'No DSN, so nothing can be read or written at all.',
        required: true,
    ),
    new Capability(
        'Signed asset links',
        configured('ASSET_LINK_SIGNING_SECRET'),
        'Exports and uploaded files cannot be handed out: a download link is signed, '
        . 'and without a secret none can be produced.',
        required: true,
    ),
    new Capability(
        'Taking money',
        configured('STUB_PAYMENT_SIGNING_SECRET'),
        'No payment provider is configured. Checkout cannot complete, so no subscription '
        . 'is ever activated — nothing is provisioned before the money arrives (ADR-024), '
        . 'and the money cannot arrive.',
    ),
    new Capability(
        'Transmitting e-invoices',
        configured('STUB_EINVOICE_SIGNING_SECRET'),
        'No approved platform is configured, so nothing can be transmitted. Note that the '
        . 'adapter is a stub in any case (R3): the four transmission states are real and no '
        . 'certified Plateforme Agréée is connected.',
    ),
];

// --- The database, actually reached -------------------------------------------
//
// Configuration is a claim. This is the check.

$databaseNotes = [];
$migrationsPending = null;

if (configured('DATABASE_DSN')) {
    try {
        $containerFactory = require __DIR__ . '/../config/container.php';
        assert(is_callable($containerFactory));

        /** @var ContainerInterface $container */
        $container = $containerFactory();

        /** @var Connection $connection */
        $connection = $container->get(Connection::class);

        $connection->executeQuery('SELECT 1');
        $databaseNotes[] = 'reachable';

        // A deployment running against a database that is behind its migrations
        // is the failure mode that looks like a bug in the application.
        $applied = $connection->fetchOne('SELECT count(*) FROM schema_migrations');
        $onDisk = glob(__DIR__ . '/../migrations/Version*.php');
        $onDisk = $onDisk === false ? [] : $onDisk;

        $migrationsPending = count($onDisk) - (is_numeric($applied) ? (int) $applied : 0);

        $databaseNotes[] = $migrationsPending === 0
            ? 'schema up to date'
            : sprintf('%d migration(s) not applied', $migrationsPending);
    } catch (Throwable $failure) {
        // The message, not the trace: a DSN can carry a password and a stack
        // trace in a deploy log is how it escapes (§31).
        $databaseNotes[] = 'UNREACHABLE — ' . $failure->getMessage();
        $migrationsPending = null;
    }
}

// --- Report -------------------------------------------------------------------

printf("Deployment preflight — APP_ENV=%s%s\n\n", env('APP_ENV', 'unset'), $strict ? ' (strict)' : '');

$missingRequired = [];

foreach ($capabilities as $capability) {
    printf(
        "  %s %-24s %s\n",
        $capability->ready ? 'yes' : 'NO ',
        $capability->what,
        $capability->ready ? '' : $capability->consequence,
    );

    if (!$capability->ready && $capability->required) {
        $missingRequired[] = $capability->what;
    }
}

if ($databaseNotes !== []) {
    printf("\n  database: %s\n", implode(', ', $databaseNotes));
}

$broken = str_contains(implode(' ', $databaseNotes), 'UNREACHABLE')
    || ($migrationsPending !== null && $migrationsPending > 0);

printf("\n");

if ($broken) {
    fwrite(STDERR, "FAIL: the database is unreachable or behind its migrations.\n");
    fwrite(STDERR, "Neither is a capability that is merely absent — the application will fail\n");
    fwrite(STDERR, "at runtime in ways that look like bugs in the code.\n");
    exit(1);
}

if ($strict && $missingRequired !== []) {
    fwrite(STDERR, sprintf(
        "FAIL: %s not configured.\n\n",
        implode(', ', $missingRequired),
    ));
    fwrite(STDERR, "Each of these defaults to off for a good reason, and each default is safe.\n");
    fwrite(STDERR, "What is not safe is a production deployment that starts cleanly, answers\n");
    fwrite(STDERR, "/health with ok, and quietly does almost nothing.\n");
    exit(1);
}

if ($missingRequired !== []) {
    printf("Not production-ready: %s missing. Run with --strict to make that a failure.\n", implode(', ', $missingRequired));
} else {
    printf("Everything a production deployment needs is configured.\n");
}

exit(0);
