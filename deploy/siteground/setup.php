<?php

declare(strict_types=1);

/**
 * Onboarding: the twelve manual steps in docs/deploying-to-siteground.md §3-7,
 * done once, from a browser, by whoever just unzipped the bundle.
 *
 * Upload this file into public_html/ alongside index.php. Visit it, fill in the
 * database Site Tools just created, and it writes backprod-app/.env, runs the
 * migrations, and creates the first product, tenant and admin account — the
 * same inserts `bin/seed-demo.php` makes, by hand, once, for a real account
 * rather than a demo one.
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
 * One exception survives the marker: resetting the admin password you just set,
 * gated on the same database credentials Site Tools gave you — because a typo
 * here otherwise locks you out of a database this page can no longer touch, and
 * this platform has no password-reset flow yet (ADR-038) to get you back in any
 * other way. Confirm you can actually sign in before you delete this file.
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

/**
 * The demonstration product, when the box is ticked.
 *
 * A name of its own rather than the real product's, so the two are never
 * confused in a console listing them side by side — and a fixed one rather than
 * a field, because a demo is a thing you delete, not a thing you name.
 */
const DEMO_PRODUCT_CODE = 'licorne';
const DEMO_PRODUCT_NAME = 'Licorne';


if (!is_file(BACKPROD_APP . '/vendor/autoload.php')) {
    fail(500, 'The application directory is missing. Upload backprod-app/ beside public_html/ first.');
}

require BACKPROD_APP . '/vendor/autoload.php';

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

/** Lowercase, ASCII, hyphenated. Good enough for a code or a slug; never empty for a non-empty input. */
function slugify(string $value): string
{
    $slug = strtolower(trim($value));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

    return trim($slug, '-');
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

/** A checkbox with its consequence under it, since this one creates a whole world. */
function checkboxRow(string $label, string $name, string $hint): string
{
    $id = 'f_' . $name;

    return '<div style="margin-bottom:0.9rem">'
        . '<label for="' . $id . '" style="display:flex;gap:0.5rem;align-items:flex-start;font-size:0.95rem">'
        . '<input id="' . $id . '" name="' . htmlspecialchars($name) . '" type="checkbox" value="yes" style="margin-top:0.25rem">'
        . '<span>' . htmlspecialchars($label)
        . '<span style="display:block;color:#555;font-size:0.8rem;margin-top:0.15rem">' . htmlspecialchars($hint) . '</span>'
        . '</span></label></div>';
}

const STYLE_BLOCK = '<style>'
    . '.error{background:#fdecea;border:1px solid #d93025;color:#5f1b16;padding:0.75rem 1rem;border-radius:4px;margin:1rem 0}'
    . '.ok{background:#e6f4ea;border:1px solid #1e7e34;color:#0b3d17;padding:0.75rem 1rem;border-radius:4px;margin:1rem 0}'
    . '.hint{color:#555;font-size:0.85rem;margin:0 0 1rem}'
    . 'code{background:#f1f1f1;padding:0.1rem 0.35rem;border-radius:3px}'
    . 'fieldset{border:1px solid #ccc;border-radius:6px;margin:0 0 1.25rem;padding:1rem}'
    . 'legend{font-weight:600;padding:0 0.4rem}'
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
        . '<p class="hint">Locked yourself out with a password typo before deleting it? The one thing this page still '
        . 'does is reset that one password — nothing else — and only for whoever can also type the real database '
        . 'credentials.</p>';

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
        . '<fieldset><legend>Reset</legend>'
        . inputRow('Account email', 'reset_email')
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
        . '<fieldset><legend>Product</legend>'
        . inputRow('Product name', 'product_name', 'text', 'Atlas')
        . inputRow('Product code (leave blank to derive it from the name)', 'product_code', 'text', 'atlas')
        . '</fieldset>'
        . '<fieldset><legend>Your organisation</legend>'
        . inputRow('Organisation name', 'tenant_name', 'text', 'Acme Ltd')
        . '</fieldset>'
        . '<fieldset><legend>Demonstration data</legend>'
        . checkboxRow(
            'Also create a demonstration product called licorne',
            'seed_demo',
            'A second, separate product with a full catalogue, two organisations, three people, '
            . 'a live subscription and the invoice it raised — so there is something to look at '
            . 'before your own product has any data. Nothing it creates touches the product above. '
            . 'Delete it later from Console → Products.',
        )
        . '</fieldset>'
        . '<fieldset><legend>Your account</legend>'
        . inputRow('Your name', 'admin_name', 'text', 'Ada Lovelace')
        . inputRow('Your email', 'admin_email', 'email')
        . inputRow('Password (12–72 characters)', 'admin_password', 'password')
        . inputRow('Confirm password', 'admin_password_confirm', 'password')
        . '</fieldset>'
        . '<button type="submit">Set up</button>'
        . '</form>';
}

/**
 * What the demonstration product is, or why there isn't one.
 *
 * Three outcomes and three messages: not asked for (nothing), built (where it
 * is and how to sign into it), or attempted and failed. The third says so
 * plainly rather than quietly rendering the first — somebody who ticked the box
 * and sees no mention of it would reasonably conclude it worked.
 *
 * @param array<string, mixed>|null $demo
 */
function demoNote(?array $demo, ?string $failure, string $host): string
{
    if ($failure !== null) {
        return '<p class="error">Your product, organisation and account are set up and work — but the '
            . 'demonstration product could not be created: ' . htmlspecialchars($failure) . '. Nothing else '
            . 'was affected. You can create it later from a shell with '
            . '<code>php bin/seed-demo.php --product=' . DEMO_PRODUCT_CODE . '</code>, or simply not have one.</p>';
    }

    if ($demo === null) {
        return '';
    }

    $people = is_array($demo['people'] ?? null) ? $demo['people'] : [];
    $admin = is_string($people['admin'] ?? null) ? $people['admin'] : '';
    // From the seeder, so this page never carries its own copy of the password.
    $password = is_string($demo['password'] ?? null) ? $demo['password'] : '';

    return '<p class="ok">A demonstration product <code>' . DEMO_PRODUCT_CODE . '</code> was also created: '
        . 'a full catalogue, two organisations, three people, a live subscription and the invoice it raised. '
        . 'It is entirely separate from your own product — a different catalogue, different organisations, '
        . 'different people.</p>'
        . '<p>Look at it by signing in at <a href="https://' . $host . '/sign-in?product=' . DEMO_PRODUCT_CODE . '">'
        . htmlspecialchars($host) . '/sign-in?product=' . DEMO_PRODUCT_CODE . '</a> as <code>'
        . htmlspecialchars($admin) . '</code> with the password <code>' . htmlspecialchars($password) . '</code>. '
        . '<strong>That password is public knowledge</strong> — it is in this platform\'s source. Delete the '
        . 'product from Console → Products when you have finished looking, which takes its people with it.</p>';
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

    $productName = field($_POST, 'product_name');
    $productCode = field($_POST, 'product_code') !== '' ? slugify(field($_POST, 'product_code')) : slugify($productName);
    $tenantName = field($_POST, 'tenant_name');
    $adminName = field($_POST, 'admin_name');
    $adminEmail = field($_POST, 'admin_email');
    $adminPassword = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';
    $adminPasswordConfirm = is_string($_POST['admin_password_confirm'] ?? null) ? $_POST['admin_password_confirm'] : '';

    if ($productName === '' || $productCode === '') {
        fail(422, 'Enter a product name (and a product code, or one derivable from the name).');
    }

    if ($tenantName === '') {
        fail(422, 'Enter your organisation’s name.');
    }

    if ($adminName === '' || $adminEmail === '' || !str_contains($adminEmail, '@')) {
        fail(422, 'Enter your name and a real email address.');
    }

    if (strlen($adminPassword) < 12 || strlen($adminPassword) > 72) {
        fail(422, 'The password must be between 12 and 72 characters.');
    }

    if (!hash_equals($adminPassword, $adminPasswordConfirm)) {
        fail(422, 'The two passwords do not match — there is no password reset yet if this one is wrong and '
            . 'you have already deleted this file, so it is worth getting right now.');
    }

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

    // --- The first product, tenant and admin account, in one transaction -------
    //
    // The same three inserts bin/seed-demo.php makes for its demo world, once,
    // for a real one: a product row, a tenant row, a user promoted from a
    // placeholder subject to `local:<id>` — the shape a token this platform
    // issues carries — a credential, and a TENANT_ADMIN role assignment.
    try {
        $connection->beginTransaction();

        $productId = $connection->fetchOne(
            'INSERT INTO products (code, name, active) VALUES (:code, :name, true) RETURNING id',
            ['code' => $productCode, 'name' => $productName],
        );

        $tenantSlug = slugify($tenantName);
        $tenantId = $connection->fetchOne(
            'INSERT INTO tenants (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => $tenantName, 'slug' => $tenantSlug !== '' ? $tenantSlug : bin2hex(random_bytes(4))],
        );

        $userId = $connection->fetchOne(
            "INSERT INTO users (auth_subject, email, display_name) VALUES ('pending', :email, :name) RETURNING id",
            ['email' => $adminEmail, 'name' => $adminName],
        );

        if (!is_string($productId) || !is_string($tenantId) || !is_string($userId)) {
            throw new \RuntimeException('One of the inserts above did not return an id.');
        }

        $connection->executeStatement(
            "UPDATE users SET auth_subject = 'local:' || id WHERE id = :id",
            ['id' => $userId],
        );

        $connection->executeStatement(
            'INSERT INTO local_credentials (user_id, email, password_hash) VALUES (:id, :email, :hash)',
            ['id' => $userId, 'email' => $adminEmail, 'hash' => password_hash($adminPassword, PASSWORD_BCRYPT)],
        );

        // The product just created is the first one this tenant holds
        // (ADR-047); the membership below has to sit inside that assignment.
        $connection->executeStatement(
            'INSERT INTO tenant_products (tenant_id, product_id, assigned_by) VALUES (:tenant, :product, :user)',
            ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId],
        );

        $connection->executeStatement(
            'INSERT INTO tenant_members (tenant_id, product_id, user_id) VALUES (:tenant, :product, :user)',
            ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId],
        );

        $roleId = $connection->fetchOne("SELECT id FROM roles WHERE code = 'TENANT_ADMIN'");

        $connection->executeStatement(
            'INSERT INTO tenant_member_roles (tenant_id, product_id, user_id, role_id) VALUES (:tenant, :product, :user, :role)',
            ['tenant' => $tenantId, 'product' => $productId, 'user' => $userId, 'role' => $roleId],
        );

        // And PLATFORM_ADMIN, which is the other half of what this person is.
        //
        // TENANT_ADMIN above administers the organisation just created; this
        // administers the platform that hosts it — every tenant, the audit
        // trail, and who else may hold a platform role. Whoever ran this
        // installer is both, because on a self-hosted deployment there is
        // nobody else to be the second one.
        //
        // Granted here rather than left to a later SQL statement typed by
        // hand: without it the first sign-in lands on a console its owner
        // cannot enter, with no route in that does not go through the
        // database — which is the exact situation this installer exists to
        // remove. `granted_by` is the same person, since the alternative is a
        // null that says the platform appointed itself.
        $platformRoleId = $connection->fetchOne(
            "SELECT id FROM platform_roles WHERE code = 'PLATFORM_ADMIN'",
        );

        $connection->executeStatement(
            'INSERT INTO platform_staff (user_id, platform_role_id, granted_by) VALUES (:user, :role, :user)',
            ['user' => $userId, 'role' => $platformRoleId],
        );

        $connection->commit();
    } catch (\Throwable $e) {
        $connection->rollBack();

        fail(500, 'Could not create the product, organisation or account: ' . $e->getMessage() . ' Nothing was '
            . 'saved (it was one transaction) — backprod-app/.env and the migrations already applied are fine; '
            . 'fix whatever this names (a product code or organisation name already taken, most likely) and '
            . 'resubmit.');
    }

    // --- The demonstration product, if it was asked for -----------------------
    //
    // After the real account and never instead of it, and its failure is never
    // this page's failure: by the time we are here the product, the
    // organisation and the administrator exist and work. A demo that could not
    // be built is worth saying out loud and worth nothing else — refusing the
    // whole setup over it would throw away the part that matters, and the
    // marker below has to be written either way or a reload would try to create
    // that account a second time.
    $demo = null;
    $demoFailure = null;

    if (($_POST['seed_demo'] ?? '') === 'yes') {
        try {
            // The container needs .env, which is on disk but not in this
            // process's environment — nothing here has loaded it, because until
            // now this page only ever needed the connection it built by hand.
            Dotenv::createImmutable(BACKPROD_APP)->safeLoad();

            $containerFactory = require BACKPROD_APP . '/config/container.php';

            if (!is_callable($containerFactory)) {
                throw new \RuntimeException('config/container.php did not return a factory.');
            }

            $seed = require BACKPROD_APP . '/bin/demo-world.php';

            if (!is_callable($seed)) {
                throw new \RuntimeException('bin/demo-world.php did not return a seeder.');
            }

            $world = $seed($containerFactory(), DEMO_PRODUCT_CODE, DEMO_PRODUCT_NAME);

            $failed = array_filter(
                is_array($world['checks'] ?? null) ? $world['checks'] : [],
                static fn (mixed $ok): bool => $ok !== true,
            );

            if ($failed !== []) {
                // The seeder verifies what it made. A world that does not hold
                // is worse than no world: somebody would demonstrate it.
                throw new \RuntimeException('the seeded world does not hold: ' . implode(', ', array_keys($failed)));
            }

            $demo = $world;
        } catch (\Throwable $e) {
            $demoFailure = $e->getMessage();
        }
    }

    // --- Done: the marker is the only thing that makes this page inert --------
    file_put_contents(MARKER_PATH, gmdate('Y-m-d H:i:s') . " UTC\n");

    $rawHost = $_SERVER['HTTP_HOST'] ?? 'your-domain';
    $host = htmlspecialchars(is_string($rawHost) ? $rawHost : 'your-domain');

    render('Set up', STYLE_BLOCK . '<p class="ok">Done. Migrations ran, and ' . htmlspecialchars($adminEmail)
        . ' can sign in as an administrator of ' . htmlspecialchars($tenantName)
        . ' <em>and</em> of the platform itself — the console, every tenant, and who else may hold '
        . 'a platform role. Appoint colleagues from Console → Staff rather than in SQL.</p>'
        . '<p><a href="https://' . $host . '/">Open the sign-in screen</a>. The first time, add '
        . '<code>?product=' . htmlspecialchars($productCode) . '</code> to the address — after that, '
        . 'this browser remembers it.</p>'
        . demoNote($demo, $demoFailure, $host)
        . '<p><strong>Confirm you can sign in before you delete this file.</strong> There is no password-reset '
        . 'screen yet (ADR-038); if the password above has a typo, this page can still fix only that — nothing '
        . 'else — for as long as it exists.</p>'
        . '<p><strong>Then delete <code>public_html/' . htmlspecialchars(basename(__FILE__)) . '</code>.</strong> '
        . 'It cannot run full setup again — <code>backprod-app/var/.setup-complete</code> refuses that on its '
        . 'own — but it is still reachable by anybody who guesses the URL, and it has nothing left to do.</p>');
}
