# Running the gates locally

The gate chain is `cs → stan → deptrac → gate:* → test`. All of it runs in this
sandbox. Read §1 and §2, and ignore the rest unless something is actually
missing.

This file is written down because five consecutive pushes were once spent
discovering what PHPStan thought of a branch, and because two of the defects
that run found were wrong assertions in new tests — the failure mode a green CI
run cannot catch, since a test that asserts the wrong thing passes.

## 1. The toolchain

```sh
composer install --prefer-source --no-interaction
```

`--prefer-source` is the whole trick: a package whose `dist` is a GitHub
zipball needs authentication and fails, and cloning instead succeeds. Nothing
else is needed — not a hand-built `vendor/`, not a phar fetched from a tool's
own repository.

**If `vendor/bin` is missing a binary**, the tree was hydrated by hand at some
point and composer's own metadata (`vendor/composer/InstalledVersions.php`, the
bin links) never got written. The same command repairs it. Do not symlink
binaries by hand: `doctrine-migrations` fails with
`Class "Composer\InstalledVersions" not found` when only the link is fixed and
the metadata is not, which reads like a broken package and is not one.

## 2. The database

PostgreSQL 16 is installed and starts from init:

```sh
service postgresql start
su postgres -c "psql -c \"CREATE ROLE backprod LOGIN PASSWORD 'backprod' SUPERUSER\""
su postgres -c "psql -c 'CREATE DATABASE backprod_test OWNER backprod'"

export DATABASE_DSN='postgresql://backprod:backprod@127.0.0.1:5432/backprod_test'
composer run migrate
composer run gates
```

Same DSN as CI, so a failure here is a failure there.

The container is reclaimed between sessions, so the server is down and the role
is gone every time — that is not a broken environment, it is a cold one. If
migrating fails with `relation "users" already exists`, the database survived
from a previous session while its migration table did not: drop it, create it,
migrate again.

Migrations must run: without them `DATABASE_DSN is not configured` is *not* the
error you get. You get 92 `DI\DependencyException` errors that name the
container rather than the database, which is a confusing way to learn the
server is not running.

## 3. The frontend

From `frontend/`:

```sh
npm run gates          # lint, typecheck, Vitest, gate:client
npx vite build         # Playwright serves dist/ — build first, always
npm run e2e
```

`npm run e2e` starts `vite preview`, which serves whatever is in `dist/`. A
stale build makes a new screen fail as though it had never been written, and
the failure says nothing about staleness. Six E2E failures were spent on this
once.

Chromium is preinstalled and Playwright is pointed at it:

```sh
PLAYWRIGHT_CHROMIUM_PATH=/opt/pw-browsers/chromium-1194/chrome-linux/chrome
```

Never run `playwright install`.

## 4. What the egress policy actually refuses

Worth being precise about, because a vague version of this was recorded as a
blocker in the roadmap and repeated for several milestones before anyone tried
it:

- packagist metadata resolves normally; `composer update --lock` works;
- `getcomposer.org` is refused, so the self-update check in `composer diagnose`
  fails. It is irrelevant;
- a GitHub zipball `dist` needs authentication. `--prefer-source` clones.

So a dependency **can** be added here: `composer require --prefer-source
<package>` updates `composer.json` and `composer.lock` and installs it.

## 5. If composer cannot install at all

Kept for the case where §1 genuinely fails, not as the normal path.

Every package with a `source.url` is already cloned under
`$COMPOSER_HOME/cache/vcs` if composer has ever resolved it — the directory
name is the source URL with every non-alphanumeric character replaced by `-`,
case preserved. Clone each into `vendor/<name>`, check out its
`source.reference`, synthesise `vendor/composer/installed.json` from
`composer.lock` with an `install-path` per entry, and
`composer dump-autoload --no-scripts`.

Two packages resist this. `phpstan` and `deptrac` have no `source.url` in the
lock, so neither hydrates from the cache; both ship a phar in their own GitHub
repository. Deptrac additionally must **not** live under `vendor/`: its
committed `bootstrap.php` requires its own nested `vendor/autoload.php`, which
redeclares the phar's `ComposerAutoloaderInit` and dies. Drop it from
`installed.json`, keep the phar outside `vendor/`, and give
`tools/prove-architecture-gate.php` a two-line **sh** shim at
`vendor/bin/deptrac` that execs it.

A hand-built tree like this is what leaves `vendor/bin` half-empty and
`InstalledVersions.php` absent. When `composer install` starts working again,
run it — §1 repairs all of it.
