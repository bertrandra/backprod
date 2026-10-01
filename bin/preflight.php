<?php

declare(strict_types=1);

use App\Auth\Infrastructure\LocalJwtTokenIssuer;
use App\Demo\Domain\DemoWorld;
use App\Job\Domain\JobRepository;
use App\Job\Domain\QueueLiveness;
use App\Project\Domain\SchemaDrift;
use App\Project\Domain\SchemaVersions;
use App\Project\Service\SchemaVersionPolicy;
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
 * **Not only the environment.** Two of the promises nothing checked turned out
 * to live in the database rather than in `.env`: whether the job runner has
 * ever run (R10), and whether each product accepts the document versions the
 * code it is being deployed with will send it (ADR-018). Both are invisible in
 * exactly the way a missing secret is — the deployment starts cleanly, answers
 * `/health` with `ok`, and refuses work it looks able to do.
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
        'Webhooks to a product beside the platform',
        strlen(env('WEBHOOK_SECRET_KEY')) >= 32,
        'WEBHOOK_SECRET_KEY is empty or shorter than 32 characters, so no product can be issued a '
        . 'webhook secret and no event is delivered (ADR-051 §5). A product inside this shell needs '
        . 'none. Generate one with: ' . "php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'",
    ),
    new Capability(
        'Single sign-on across hosts',
        // **Both halves, because either one alone does nothing** (2026-09-26).
        // The cookie has to be willing to travel to the sibling host, and the
        // browser has to be willing to attach it to a cross-origin call. Miss
        // one and the symptom is identical to missing the other: a sign-in
        // form on a product this person is already signed in for. Reporting
        // them separately sends an operator round the loop twice.
        configured('AUTH_COOKIE_DOMAIN') && configured('CORS_ALLOWED_ORIGINS'),
        'Single sign-on to a product beside the platform (ADR-051 §3) needs two lines and has '
        . (configured('AUTH_COOKIE_DOMAIN') || configured('CORS_ALLOWED_ORIGINS') ? 'one' : 'neither')
        . '. AUTH_COOKIE_DOMAIN widens the refresh cookie to the registrable domain — without it the '
        . 'cookie is host-only, returned to exactly the host that set it and to no sibling subdomain, '
        . 'whatever SameSite says. CORS_ALLOWED_ORIGINS lists the product\'s origin — without it the '
        . 'browser will not attach the cookie to the product\'s call at all. Either one missing gives '
        . 'the same symptom: somebody who has just bought a seat is asked for their password on the '
        . 'way to the thing they bought, and the way back can ask again. The cookie domain also makes '
        . 'an apex and a www host one session rather than two, which is worth having on its own. It '
        . 'widens a credential\'s reach — every host under the domain can receive the token, on '
        . '/api/v1/auth only, over Secure, unreadable by script — so a deployment hosting anything '
        . 'it does not trust on a subdomain must not set it. A single-host deployment with nothing '
        . 'beside it needs none of this.',
    ),
    new Capability(
        'Taking money',
        // Stripe needs all three of its keys (config/container.php says why);
        // the stub is a provider too, for a demo or the test suite.
        (configured('STRIPE_SECRET_KEY') && configured('STRIPE_WEBHOOK_SECRET') && configured('STRIPE_PUBLISHABLE_KEY'))
        || configured('STUB_PAYMENT_SIGNING_SECRET'),
        'No payment provider is configured. Checkout cannot complete, so no subscription '
        . 'is ever activated — nothing is provisioned before the money arrives (ADR-024), '
        . 'and the money cannot arrive. Stripe needs STRIPE_SECRET_KEY, STRIPE_WEBHOOK_SECRET '
        . 'and STRIPE_PUBLISHABLE_KEY, all three.',
    ),
    new Capability(
        'Sending mail',
        configured('MAIL_DSN'),
        'MAIL_DSN is empty, so no mail leaves this deployment: a password reset, an '
        . 'invitation and an address confirmation are recorded but never sent. Set a Symfony '
        . 'Mailer DSN (smtp://user:pass@host:port) and MAIL_FROM.',
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

/**
 * Whether the cron-polled runner has ever run, and when it last finished.
 *
 * Read here rather than left to `GET /admin/queue` because the person who has
 * just added the crontab entry is in a shell, not a browser with a platform
 * role. Null when the database was not reached — absence of an answer, never
 * a claim that nothing has run.
 */
$queue = null;

/**
 * Products that live on their own host and therefore need both halves set,
 * by code with the address they answer on.
 *
 * @var array<string, string>
 */
$sideloaded = [];

/**
 * Which document schema versions each active product accepts, as the database
 * answers — and which ones this deployment's code expects it to.
 *
 * `stored` is the configuration row, read with the same reader every project
 * write uses. `declared` is what this code says the product accepts, or null
 * for a product the code has never heard of. `documents` is the versions the
 * product already has projects written in.
 *
 * @var array<string, array{stored: list<int>, declared: ?list<int>, documents: list<int>}>
 */
$schemas = [];

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

        // The runner leaves its passes in `job_runs`, and that table is the
        // only thing that tells a quiet queue apart from a cron that never
        // fires — both produce silence (R10).
        if ($migrationsPending === 0) {
            /** @var JobRepository $jobs */
            $jobs = $container->get(JobRepository::class);
            $queue = $jobs->liveness();
        }

        // Which versions each product accepts. That list is configuration and
        // not a constant, deliberately (ADR-018, non-negotiable #10): a second
        // product declares its own as a row, and no code learns either
        // product's name. The price is a fact the code and the database can
        // hold differently, and nothing ever compared them — a database keeps
        // the list it was seeded with, so shipping a release that accepts one
        // more version changes nothing until somebody opens the console.
        //
        // That gap has cost this platform twice, the same way both times:
        // Plan's 2.2.0 saving in schema 2 against a list of [1], and its roofs
        // saving in schema 3 against [1, 2]. Each looked like a bug in Plan.
        if ($migrationsPending === 0) {
            $declarations = $connection->fetchAllAssociative(
                <<<'SQL'
                SELECT p.code, c.value
                  FROM products p
                  LEFT JOIN product_configuration c
                         ON c.product_id = p.id AND c.key = :key
                 WHERE p.active
                 ORDER BY p.code
                SQL,
                ['key' => SchemaVersionPolicy::CONFIGURATION_KEY],
            );

            foreach ($declarations as $row) {
                $code = $row['code'] ?? null;

                if (!is_string($code)) {
                    continue;
                }

                // The configuration reader itself, never a second one written
                // here: the whole question is whether this deployment and this
                // database agree, and a preflight parsing the row its own way
                // could answer yes while every project write answered no.
                $value = $row['value'] ?? null;
                $stored = SchemaVersions::read(
                    is_string($value) ? json_decode($value, true) : $value,
                );

                // `DemoWorld` is what seeded this database — `composer run
                // demo:seed`, the console's reset and the host's setup page all
                // build their world from it — so it is this code's declaration
                // of what each product accepts, not a fixture borrowed for the
                // occasion. A product created in the console is not in it, and
                // there is then nothing to compare, rather than a default to be
                // mistaken for an opinion.
                $schemas[$code] = [
                    'stored' => $stored,
                    'declared' => isset(DemoWorld::PRODUCTS[$code]) ? DemoWorld::schemaVersionsFor($code) : null,
                    'documents' => [],
                ];
            }

            // What is already written. A list narrowed below a version some
            // document is stored in leaves its owner able to open that document
            // and unable to save it — the same failure, arriving from the other
            // direction and from the console rather than from a deployment.
            $written = $connection->fetchAllAssociative(<<<'SQL'
                SELECT p.code, pr.schema_version
                  FROM projects pr
                  JOIN products p ON p.id = pr.product_id
                 GROUP BY p.code, pr.schema_version
                 ORDER BY p.code, pr.schema_version
                SQL);

            foreach ($written as $row) {
                $code = $row['code'] ?? null;
                $version = $row['schema_version'] ?? null;

                if (is_string($code) && isset($schemas[$code]) && is_numeric($version)) {
                    $schemas[$code]['documents'][] = (int) $version;
                }
            }
        }

        // A product deployed beside the platform, without both halves of the
        // setting, is not a capability that is merely absent: it is single
        // sign-on that cannot work for a product this deployment is actually
        // offering. The capability above says what the two lines do; this says
        // that something here needs them, and what to write.
        if (!configured('AUTH_COOKIE_DOMAIN') || !configured('CORS_ALLOWED_ORIGINS')) {
            $beside = $connection->fetchAllAssociative(
                "SELECT code, app_url FROM products WHERE active AND coalesce(app_url, '') <> '' ORDER BY code",
            );

            foreach ($beside as $row) {
                $code = $row['code'] ?? null;
                $url = $row['app_url'] ?? null;

                if (is_string($code) && is_string($url)) {
                    $sideloaded[$code] = $url;
                }
            }
        }
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

if ($queue instanceof QueueLiveness) {
    // Said as facts and in this order because they are three different things
    // to go and fix: a cron that stopped ages the last finish, a runner that
    // died mid-pass leaves a run open, and a wedged handler grows the backlog
    // while the clock looks perfectly healthy.
    $queueNotes = [];

    if ($queue->neverRan()) {
        // Every count is zero and nothing is overdue, which reads exactly like
        // a calm idle queue. It is the opposite, so it is said out loud — and
        // it is also the expected answer before step 8 of the deployment
        // guide has been done, which is why it is a note and not a failure.
        $queueNotes[] = 'no pass has ever run — add the crontab entry for bin/run-jobs.php';
    } else {
        $queueNotes[] = $queue->secondsSinceFinished === null
            ? 'no pass has finished yet'
            : sprintf('last pass finished %ds ago', $queue->secondsSinceFinished);
    }

    if ($queue->unfinishedRuns > 0) {
        $queueNotes[] = sprintf(
            '%d unfinished (oldest %ds)',
            $queue->unfinishedRuns,
            $queue->oldestUnfinishedSeconds ?? 0,
        );
    }

    $queueNotes[] = $queue->dueJobs === 0
        ? 'nothing due'
        : sprintf('%d due (oldest %ds)', $queue->dueJobs, $queue->oldestDueSeconds ?? 0);

    printf("  queue:    %s\n", implode(', ', $queueNotes));
}

/**
 * One product each, and what it will accept.
 *
 * @var list<string>
 */
$schemaNotes = [];

/**
 * Why this deployment is not ready to be sent documents — each a whole
 * sentence, because `--strict` prints them with nothing around them.
 *
 * @var list<string>
 */
$schemaDrift = [];

/**
 * The products those sentences are about, which is not their number: one
 * product can be wrong in two ways at once, and saying "3 products" about two
 * of them would be this report making its own small mistake about counting.
 *
 * @var array<string, true>
 */
$schemaDriftProducts = [];

/**
 * What each reason reads as. The deciding is {@see SchemaDrift}'s, which is
 * what lets it be sabotaged in a unit test rather than demonstrated once by
 * hand against a database somebody has to set up first; the speaking is this
 * script's, because prose belongs to whoever is doing it.
 *
 * @var array<string, string>
 */
$schemaSays = [
    SchemaDrift::DECLARES_NOTHING => '%1$s has declared no schema versions, so it accepts no project of any version.',
    SchemaDrift::BEHIND_THE_CODE => '%1$s accepts %2$s, and this deployment expects it to accept %3$s as well.',
    SchemaDrift::DOCUMENTS_REFUSED => '%1$s stores documents in schema %3$s, which it does not accept: their owners can open them and cannot save them.',
];

foreach ($schemas as $code => $product) {
    $schemaNotes[] = sprintf(
        '%s %s',
        $code,
        $product['stored'] === [] ? 'nothing' : implode(',', $product['stored']),
    );

    foreach (SchemaDrift::of($product['stored'], $product['declared'], $product['documents']) as $finding) {
        $schemaDrift[] = sprintf(
            $schemaSays[$finding['reason']] ?? '%1$s: %2$s',
            $code,
            implode(', ', $product['stored']),
            implode(', ', $finding['versions']),
        );

        $schemaDriftProducts[$code] = true;
    }
}

if ($schemaNotes !== []) {
    printf("  schemas:  %s\n", implode(' | ', $schemaNotes));
}

if ($schemaDrift !== []) {
    printf(
        "\n  WARNING: %s\n\n%s\n\n"
        . "  The accepted list lives in the database and no deployment rewrites it, so\n"
        . "  shipping the code is not the fix. The console writes it: Invoicing, then the\n"
        . "  product, then its project schema versions. A document of a version a product\n"
        . "  does not accept is refused UNSUPPORTED_SCHEMA_VERSION, and that refusal reads\n"
        . "  to everybody as a bug in the product.\n",
        count($schemaDriftProducts) === 1
            ? 'a product cannot accept the documents it is about to be sent.'
            : sprintf('%d products cannot accept the documents they are about to be sent.', count($schemaDriftProducts)),
        implode("\n", array_map(static fn (string $line): string => '    ' . $line, $schemaDrift)),
    );
}

if ($sideloaded !== []) {
    $origins = [];
    $domains = [];

    foreach ($sideloaded as $url) {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (!is_string($host) || $host === '') {
            continue;
        }

        $origins[(is_string($scheme) && $scheme !== '' ? $scheme : 'https') . '://' . $host] = true;

        // The registrable domain, as far as a deployment can know it without a
        // public-suffix list: the last two labels. `plan.raillard.org` gives
        // `raillard.org`, which is the answer here; a host under a two-part
        // suffix like `co.uk` would need the operator's eye, which is why this
        // is printed as a suggestion and not written anywhere.
        $labels = explode('.', $host);
        $domains[count($labels) >= 2 ? implode('.', array_slice($labels, -2)) : $host] = true;
    }

    printf(
        "\n  WARNING: %s %s on %s, and single sign-on is not configured for %s.\n"
        . "  Somebody signed in here who opens %s is shown a sign-in form, and the way\n"
        . "  back can ask again. Both of these lines are needed in .env:\n\n"
        . "    AUTH_COOKIE_DOMAIN=%s\n"
        . "    CORS_ALLOWED_ORIGINS=%s\n",
        implode(', ', array_keys($sideloaded)),
        count($sideloaded) === 1 ? 'answers' : 'answer',
        implode(', ', array_keys($origins)),
        count($sideloaded) === 1 ? 'it' : 'them',
        count($sideloaded) === 1 ? 'it' : 'any of them',
        implode(' or ', array_keys($domains)),
        implode(',', array_keys($origins)),
    );
}

// A production-shaped deployment on a sandbox key is legitimate — a demo,
// a rehearsal — and it is also the mistake that costs the most if it was not
// meant. Said, never refused (ADR-048).
if (env('APP_ENV') === 'prod' && str_starts_with(env('STRIPE_SECRET_KEY'), 'sk_test_')) {
    printf("\n  WARNING: APP_ENV=prod with a Stripe sandbox key (sk_test_…). No real money will move.\n");
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

// After the missing secrets rather than before them: a deployment that can
// neither sign a link nor send mail has a more fundamental problem than a
// product's version list, and whichever check runs first is the one an
// operator reads.
if ($strict && $schemaDrift !== []) {
    fwrite(STDERR, "FAIL: a product cannot accept the documents it is about to be sent.\n\n");

    foreach ($schemaDrift as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }

    fwrite(STDERR, "\nThis is not a capability that is merely absent. The product saves, the save is\n");
    fwrite(STDERR, "refused, and the refusal looks like a bug in the product. Name the versions in\n");
    fwrite(STDERR, "the console before deploying the release that writes them.\n");
    exit(1);
}

if ($missingRequired !== []) {
    printf("Not production-ready: %s missing. Run with --strict to make that a failure.\n", implode(', ', $missingRequired));
} else {
    printf("Everything a production deployment needs is configured.\n");
}

exit(0);
