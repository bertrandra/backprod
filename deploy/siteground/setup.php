<?php

declare(strict_types=1);

/**
 * Onboarding: the twelve manual steps in docs/deploying-to-siteground.md §3-7,
 * done once, from a browser, by whoever just unzipped the bundle.
 *
 * Upload this file into public_html/ alongside index.php. Visit it, fill in the
 * database Site Tools just created, and it writes backprod-app/.env, runs the
 * migrations, and seeds the demonstration world — `App\Demo\Service\DemoSeeder`,
 * the same one `composer run demo:seed` builds: five products, three organisations,
 * one person per role. It asks for no product and no account of your own:
 * the platform is demonstrated from that world, and the accounts it creates
 * are the ones you sign in with. Their password is in this platform's source,
 * so the success page offers the form to change it, and says to.
 *
 * **Deactivated after setup, and not only by you deleting it.** The moment
 * every step below succeeds, this writes `backprod-app/var/.setup-complete` and
 * refuses to do anything mutating again — full setup or a stray resubmission —
 * for as long as that marker exists. Deleting this file afterwards is still the
 * right thing to do, exactly as you planned: `public_html/` is reachable by
 * anybody, and a setup page that can rewrite `.env` has no business staying
 * there once it has nothing left to do. The marker is what makes "forgot to
 * delete it" a non-event instead of a second admin account waiting for whoever
 * finds the URL — the same reasoning as everywhere else in this platform that
 * an invariant belongs where nothing can route around it, not in a step a
 * person might skip.
 *
 * One exception survives the marker: changing an account's password, gated on
 * the same database credentials Site Tools gave you — because the seeded
 * accounts' password is public knowledge and this platform has no
 * password-reset flow yet (ADR-038) to change it any other way. Change at
 * least the platform administrator's, confirm you can sign in with the new
 * one, and only then delete this file.
 *
 * No JavaScript: the document root's own Content-Security-Policy sets
 * `script-src 'self'`, and a second file just to satisfy that is not worth it
 * for one form. Every check here runs server-side, on submit.
 */

// --- Where the application lives ----------------------------------------------
//
// Same convention as index.php: the application is *beside* the document root,
// never inside it, so nothing here can be reached by a URL that only knows this
// file's directory.
const BACKPROD_APP = __DIR__ . '/../backprod-app';
const MARKER_PATH = BACKPROD_APP . '/var/.setup-complete';
const ENV_PATH = BACKPROD_APP . '/.env';


if (!is_file(BACKPROD_APP . '/vendor/autoload.php')) {
    fail(500, 'The application directory is missing. Upload backprod-app/ beside public_html/ first.');
}

require BACKPROD_APP . '/vendor/autoload.php';

use App\Demo\Domain\DemoWorld;
use App\Demo\Domain\SeededWorld;
use App\Demo\Service\DemoSeeder;
use App\Shared\Database\ConnectionFactory;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\MigratorConfiguration;

// --- Small helpers, kept local rather than reaching into the app -------------
//
// This page runs before the app is necessarily configured at all, so it uses
// nothing from src/ except the one class (ConnectionFactory) that turns a DSN
// into a connection the same way the app itself does — everything else here is
// self-contained on purpose.

/** Ends the request with a plain message. Never a stack trace: whoever is here is setting up, not debugging. */
function fail(int $status, string $message): never
{
    http_response_code($status);
    render('Setup', '<p class="error">' . htmlspecialchars($message) . '</p>');
    exit;
}


/** @param array<int|string, mixed> $data */
function field(array $data, string $key): string
{
    $value = $data[$key] ?? '';

    return is_string($value) ? trim($value) : '';
}

function generateSecret(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * backprod-app/migrations.php, checked rather than trusted: it is a sibling
 * file this page did not write and could in principle find replaced or
 * truncated.
 *
 * @return array<string, mixed>
 */
function migrationsConfig(): array
{
    $config = require BACKPROD_APP . '/migrations.php';

    if (!is_array($config)) {
        fail(500, 'backprod-app/migrations.php did not return the expected configuration array.');
    }

    foreach (array_keys($config) as $key) {
        if (!is_string($key)) {
            fail(500, 'backprod-app/migrations.php did not return the expected configuration array.');
        }
    }

    /** @var array<string, mixed> $config */
    return $config;
}

/**
 * The document, once. Every screen this page shows is this one shell around
 * different content, so nothing here needs to repeat the head or the styles.
 */
function render(string $title, string $body): void
{
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= htmlspecialchars($title) ?></title>
</head>
<body style="font-family:system-ui,-apple-system,sans-serif;max-width:640px;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#1a1a1a">
<h1 style="font-size:1.3rem"><?= htmlspecialchars($title) ?></h1>
<?= $body ?>
</body>
</html>
<?php
}

/** A field with its label, kept to one line per call site. */
function inputRow(string $label, string $name, string $type = 'text', string $placeholder = '', string $value = ''): string
{
    $id = 'f_' . $name;

    return '<div style="margin-bottom:0.9rem">'
        . '<label for="' . $id . '" style="display:block;font-size:0.85rem;font-weight:600;margin-bottom:0.25rem">' . htmlspecialchars($label) . '</label>'
        . '<input id="' . $id . '" name="' . htmlspecialchars($name) . '" type="' . $type . '"'
        . ($placeholder !== '' ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '')
        . ' value="' . htmlspecialchars($value) . '"'
        . ' style="width:100%;box-sizing:border-box;padding:0.5rem;border:1px solid #999;border-radius:4px;font-size:1rem">'
        . '</div>';
}


const STYLE_BLOCK = '<style>'
    . '.error{background:#fdecea;border:1px solid #d93025;color:#5f1b16;padding:0.75rem 1rem;border-radius:4px;margin:1rem 0}'
    . '.ok{background:#e6f4ea;border:1px solid #1e7e34;color:#0b3d17;padding:0.75rem 1rem;border-radius:4px;margin:1rem 0}'
    . '.hint{color:#555;font-size:0.85rem;margin:0 0 1rem}'
    . 'code{background:#f1f1f1;padding:0.1rem 0.35rem;border-radius:3px}'
    . 'fieldset{border:1px solid #ccc;border-radius:6px;margin:0 0 1.25rem;padding:1rem}'
    . 'legend{font-weight:600;padding:0 0.4rem}'
    . 'table{border-collapse:collapse;width:100%;margin:0.5rem 0 1rem;font-size:0.9rem}'
    . 'th,td{text-align:left;padding:0.35rem 0.5rem;border-bottom:1px solid #ddd;vertical-align:top}'
    . 'button{background:#1a1a1a;color:#fff;border:none;border-radius:4px;padding:0.6rem 1.2rem;font-size:1rem;cursor:pointer}'
    . '</style>';

// --- Already done? -------------------------------------------------------------
//
// Checked before anything else, and before even reading the request method: a
// GET must see this too, or the form for a setup that already ran would still
// render as if nothing had happened.

$alreadySetUp = is_file(MARKER_PATH);
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';

if ($alreadySetUp && $action !== 'reset-password') {
    $body = STYLE_BLOCK . '<p class="ok">This deployment is already set up.</p>'
        . '<p>Setup ran on ' . htmlspecialchars((string) file_get_contents(MARKER_PATH)) . ' and will not run again '
        . 'while <code>backprod-app/var/.setup-complete</code> exists.</p>'
        . '<p><strong>Delete this file now</strong> — <code>public_html/' . htmlspecialchars(basename(__FILE__)) . '</code> — '
        . 'it has nothing left to do and no reason to keep serving.</p>'
        . '<p class="hint">The one thing this page still does is change one account\'s password — nothing else — '
        . 'and only for whoever can also type the real database credentials. The seeded accounts all start with '
        . 'the password in this platform\'s source; if you have not changed the platform administrator\'s yet, '
        . 'do it here.</p>';

    $body .= resetPasswordForm();

    render('Already set up', $body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render('Set up Backprod', STYLE_BLOCK . setupForm());
    exit;
}

if ($action === 'reset-password') {
    handleResetPassword();
    exit;
}

handleSetup();
exit;

// --- The reset-password escape hatch --------------------------------------------

function resetPasswordForm(): string
{
    return '<form method="post" action="">'
        . '<input type="hidden" name="action" value="reset-password">'
        . '<fieldset><legend>Database</legend>'
        . inputRow('Host (the value your host gave you — often the Site IP, sometimes localhost)', 'db_host')
        . inputRow('Port', 'db_port', 'text', '5432', '5432')
        . inputRow('Database name', 'db_name')
        . inputRow('Username', 'db_user')
        . inputRow('Password', 'db_pass', 'password')
        . '</fieldset>'
        . '<fieldset><legend>Change a password</legend>'
        . inputRow('Account email', 'reset_email', 'email', 'backprod@raillard.org')
        . inputRow('New password (12–72 characters)', 'reset_password', 'password')
        . inputRow('Confirm new password', 'reset_password_confirm', 'password')
        . '</fieldset>'
        . '<button type="submit">Reset password</button>'
        . '</form>';
}

function handleResetPassword(): void
{
    $connection = connectOrFail($_POST);

    $email = field($_POST, 'reset_email');
    $password = is_string($_POST['reset_password'] ?? null) ? $_POST['reset_password'] : '';
    $confirm = is_string($_POST['reset_password_confirm'] ?? null) ? $_POST['reset_password_confirm'] : '';

    if ($email === '' || !str_contains($email, '@')) {
        fail(422, 'Enter the email address of the account to reset.');
    }

    // Not trimmed, and bounded the same way the platform's own sign-in form
    // bounds it: bcrypt ignores anything past 72 bytes, so more than that is
    // decoration rather than a longer password.
    if (strlen($password) < 12 || strlen($password) > 72) {
        fail(422, 'The new password must be between 12 and 72 characters.');
    }

    if (!hash_equals($password, $confirm)) {
        fail(422, 'The two passwords do not match.');
    }

    $updated = $connection->executeStatement(
        <<<'SQL'
            UPDATE local_credentials
            SET password_hash = :hash, updated_at = now()
            WHERE lower(email) = lower(:email)
            SQL,
        ['email' => $email, 'hash' => password_hash($password, PASSWORD_BCRYPT)],
    );

    if ($updated === 0) {
        fail(404, 'No account with that email address has a password to reset.');
    }

    render('Password reset', STYLE_BLOCK . '<p class="ok">Password updated. Sign in with the new one, then delete this file.</p>');
}

// --- The setup form and its handler ---------------------------------------------

function setupForm(): string
{
    return '<p class="hint">Runs once. Create the database in Site Tools → Site → PostgreSQL first. '
        . 'Ask your host what host value to use — some SiteGround accounts need the Site IP with its '
        . 'Remote tab whitelisted, others use localhost; support can tell you which. '
        . 'See docs/deploying-to-siteground.md §0.</p>'
        . '<form method="post" action="">'
        . '<input type="hidden" name="action" value="setup">'
        . '<fieldset><legend>Database</legend>'
        . inputRow('Host (the value your host gave you — often the Site IP, sometimes localhost)', 'db_host')
        . inputRow('Port', 'db_port', 'text', '5432', '5432')
        . inputRow('Database name', 'db_name')
        . inputRow('Username', 'db_user')
        . inputRow('Password', 'db_pass', 'password')
        . '</fieldset>'
        . '<fieldset><legend>What this creates</legend>'
        . '<p class="hint" style="margin:0">The demonstration world: five products (<code>atlas</code>, '
        . '<code>boreas</code>, <code>ceres</code>, <code>delos</code>, <code>plan</code> — the last deployed beside the platform), each with a catalogue, three '
        . 'organisations holding them, one person per role — two tenant roles, four platform roles — '
        . 'four live subscriptions with the invoices they raised, and five projects. No product or account of your own is asked '
        . 'for: you sign in as those people. Their password is in this platform\'s source, and the next '
        . 'page is where you change it. See <code>docs/demo-world.html</code> in the bundle.</p>'
        . '</fieldset>'
        . '<button type="submit">Set up</button>'
        . '</form>';
}

/**
 * The people the world made, as a table somebody can sign in from.
 *
 * Everything in it comes from the seeder's own answer — names, addresses,
 * roles, the password — so this page never carries a copy that could drift.
 *
 */
function accountsTable(SeededWorld $world, string $host): string
{
    $rows = '';

    foreach ($world->people() as $person) {
        $rows .= '<tr><td><code>' . htmlspecialchars($person['email']) . '</code></td>'
            . '<td>' . htmlspecialchars($person['role']) . '</td>'
            . '<td>' . ($person['scope'] === 'platform' ? 'the console' : htmlspecialchars(implode(', ', $person['tenants']))) . '</td></tr>';
    }

    return '<table><thead><tr><th>Sign in as</th><th>Role</th><th>Administers</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody></table>'
        . '<p>Every one of them has the password <code>' . htmlspecialchars(DemoWorld::PASSWORD) . '</code>. '
        . 'Sign in at <a href="https://' . $host . '/sign-in?product=atlas">' . htmlspecialchars($host)
        . '/sign-in?product=atlas</a> — the first time, the <code>?product=</code> is needed; after that this '
        . 'browser remembers it. Platform staff land in the console.</p>';
}

/** @param array<int|string, mixed> $data */
function connectOrFail(array $data): Connection
{
    $host = field($data, 'db_host');
    $port = field($data, 'db_port') !== '' ? field($data, 'db_port') : '5432';
    $name = field($data, 'db_name');
    $user = field($data, 'db_user');
    $pass = is_string($data['db_pass'] ?? null) ? $data['db_pass'] : '';

    if ($host === '' || $name === '' || $user === '') {
        fail(422, 'Fill in the database host, name and username.');
    }

    // Not refused here. `localhost` was believed to always fail against
    // SiteGround's PostgreSQL — remote connections only, even for the app on
    // the same account — until a real deployment's own support ticket said
    // the opposite for that account. Hosting setups vary by plan; the
    // connection attempt below is the actual test, not a guess made here.

    // rawurlencode() on the credentials: a password containing "@", ":" or "/"
    // would otherwise be parsed as part of the host or the path rather than as
    // itself, and that failure looks like a wrong password rather than an
    // unescaped one.
    $dsn = sprintf(
        'postgresql://%s:%s@%s:%s/%s',
        rawurlencode($user),
        rawurlencode($pass),
        $host,
        $port,
        rawurlencode($name),
    );

    try {
        $connection = ConnectionFactory::fromDsn($dsn);
        $connection->executeQuery('SELECT 1');
    } catch (\Throwable) {
        // The exception may carry the DSN, so nothing about it is shown (§31) —
        // only the checklist that actually gets somebody unstuck.
        fail(502, 'Could not connect. Check the host, port, database name, username and password — ask '
            . 'your host which host value to use if unsure — and, if using the Site IP, that it is '
            . 'whitelisted in the PostgreSQL Manager’s Remote tab.');
    }

    return $connection;
}

function handleSetup(): void
{
    if (is_file(MARKER_PATH)) {
        // Reached only if the marker appeared between the page load and this
        // submit — a double-click, or a second tab. Refused rather than
        // risked: re-running the inserts below would fail on a duplicate
        // product code or tenant slug anyway, just less clearly than this.
        fail(409, 'Setup has already completed. Reload this page.');
    }

    $connection = connectOrFail($_POST);

    // --- .env, written before anything else needs it ---------------------------
    //
    // Nothing here reads an existing .env first: this page only ever runs once,
    // enforced by the marker above, so there is nothing to preserve. Regenerated
    // fresh means these secrets are never typed by a person and never travel
    // through this form's fields at all.
    $authSecret = generateSecret();
    $assetSecret = generateSecret();
    $assetsDir = BACKPROD_APP . '/var/assets';
    $pdfDir = BACKPROD_APP . '/var/pdf';

    // Recreated defensively: some zip tools drop empty directories, and both of
    // these are empty until the first upload or invoice.
    foreach ([$assetsDir, $pdfDir, dirname(MARKER_PATH)] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            fail(500, 'Could not create ' . basename($dir) . '. Check backprod-app/var/ is writable.');
        }
    }

    // Resolved now that both directories exist, so .env holds a clean absolute
    // path rather than one with a literal "/../" segment in it.
    $assetsDir = realpath($assetsDir) ?: $assetsDir;
    $pdfDir = realpath($pdfDir) ?: $pdfDir;

    // https unless this very request came in over plain http, in which case
    // saying https would produce links that do not work.
    $scheme = (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off') ? 'https' : 'http';
    $appUrl = $scheme . '://' . (is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : 'localhost');

    $env = 'DATABASE_DSN=postgresql://' . rawurlencode(field($_POST, 'db_user')) . ':' . rawurlencode(is_string($_POST['db_pass'] ?? null) ? $_POST['db_pass'] : '')
        . '@' . field($_POST, 'db_host') . ':' . (field($_POST, 'db_port') !== '' ? field($_POST, 'db_port') : '5432')
        . '/' . rawurlencode(field($_POST, 'db_name')) . "\n"
        . "AUTH_SIGNING_SECRET={$authSecret}\n"
        . "ASSET_LINK_SIGNING_SECRET={$assetSecret}\n"
        // Seals the webhook secrets a product beside the platform is issued
        // (ADR-051 §5). Generated here like the two above, because a key an
        // operator has to add later is a feature that answers 503 until then.
        . 'WEBHOOK_SECRET_KEY=' . bin2hex(random_bytes(32)) . "\n"
        . "ASSET_STORAGE_ROOT={$assetsDir}\n"
        . "PDF_TEMPORARY_ROOT={$pdfDir}\n"
        // Where this deployment answers, taken from the address this very
        // request arrived on — which is the one moment the platform can know
        // it without guessing. It is what the links in its emails are built
        // from, and it is a plain line in .env anybody can correct afterwards.
        . 'APP_URL=' . $appUrl . "\n";

    if (file_put_contents(ENV_PATH, $env) === false) {
        fail(500, 'Could not write backprod-app/.env. Check backprod-app/ is writable.');
    }

    @chmod(ENV_PATH, 0o640);

    // --- Migrate -----------------------------------------------------------------
    //
    // In-process, through Doctrine's own API rather than shelling out to
    // vendor/bin/doctrine-migrations: shared hosting sometimes disables exec(),
    // proc_open() and their kin, and this way that setting never matters.
    try {
        $config = migrationsConfig();

        $dependencyFactory = DependencyFactory::fromConnection(
            new ConfigurationArray($config),
            new ExistingConnection($connection),
        );

        $dependencyFactory->getMetadataStorage()->ensureInitialized();

        $planCalculator = $dependencyFactory->getMigrationPlanCalculator();
        $target = $dependencyFactory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $planCalculator->getPlanUntilVersion($target);

        $dependencyFactory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setDryRun(false)->setAllOrNothing(true),
        );
    } catch (\Throwable $e) {
        // The one place a real message is shown, because a migration failure
        // names a table or a constraint — never a credential — and whoever
        // is looking at this screen needs to know which one. .env is already
        // written and correct; re-submitting after fixing whatever this names
        // is safe, because migrations are idempotent (a version already applied
        // is simply not planned again).
        fail(500, 'Migrations failed: ' . $e->getMessage() . ' backprod-app/.env is already written; fix the '
            . 'database and resubmit this form — already-applied migrations will not run twice.');
    }

    // --- The demonstration world ---------------------------------------------
    //
    // Through `DemoSeeder`, the one definition of what a complete
    // demonstration is, so this page, `composer run demo:seed` and the
    // console's reset build the same thing. Its failure *is* this page's
    // failure: there is no other account to fall back on, so a deployment
    // with no world is a deployment nobody can sign into. The marker is not
    // written on failure — fix what the message names and resubmit;
    // migrations already applied are not planned again, and the seeder
    // refuses to seed on top of itself (a resubmission after a failure
    // further down would otherwise double every catalogue).
    try {
        // The container needs .env, which is on disk but not in this
        // process's environment — nothing here has loaded it, because until
        // now this page only ever needed the connection it built by hand.
        Dotenv::createImmutable(BACKPROD_APP)->safeLoad();

        $containerFactory = require BACKPROD_APP . '/config/container.php';

        if (!is_callable($containerFactory)) {
            throw new \RuntimeException('config/container.php did not return a factory.');
        }

        $container = $containerFactory();

        if (!$container instanceof \Psr\Container\ContainerInterface) {
            throw new \RuntimeException('config/container.php did not build a container.');
        }

        $seeder = $container->get(DemoSeeder::class);

        if (!$seeder instanceof DemoSeeder) {
            throw new \RuntimeException('the container did not answer with the demo seeder.');
        }

        $world = $seeder->seed();

        if (!$world->holds()) {
            // The seeder verifies what it made. A world that does not hold
            // is worse than no world: somebody would demonstrate it.
            throw new \RuntimeException('the seeded world does not hold: ' . implode(', ', $world->failures()));
        }
    } catch (\Throwable $e) {
        fail(500, 'The demonstration world could not be created: ' . $e->getMessage() . '. backprod-app/.env '
            . 'is written and the migrations are applied; fix what this names and resubmit this form.');
    }

    // --- Done: the marker is the only thing that makes this page inert --------
    file_put_contents(MARKER_PATH, gmdate('Y-m-d H:i:s') . " UTC\n");

    $rawHost = $_SERVER['HTTP_HOST'] ?? 'your-domain';
    $host = htmlspecialchars(is_string($rawHost) ? $rawHost : 'your-domain');

    render('Set up', STYLE_BLOCK . '<p class="ok">Done. Migrations ran and the demonstration world is seeded: '
        . 'five products, three organisations, nine people, four live subscriptions with their invoices, five projects.</p>'
        . accountsTable($world, $host)
        . '<p class="error"><strong>Change the platform administrator\'s password now.</strong> Every account '
        . 'above has the password printed there, and it is in this platform\'s public source — anybody who '
        . 'reads it can administer this deployment until you do. Use the form below, sign in with the new one, '
        . 'and appoint colleagues from Console → Staff rather than in SQL.</p>'
        . resetPasswordForm()
        . '<p><strong>Then delete <code>public_html/' . htmlspecialchars(basename(__FILE__)) . '</code>.</strong> '
        . 'It cannot run full setup again — <code>backprod-app/var/.setup-complete</code> refuses that on its '
        . 'own — but it is still reachable by anybody who guesses the URL, and it has nothing left to do but '
        . 'change passwords.</p>');
}
