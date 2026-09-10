# Running the gates locally

The gate chain is `cs → stan → deptrac → gate:* → test`, and normally
`composer install` puts everything it needs in place. In a sandbox without
GitHub credentials that install fails partway, and the gates then look
unavailable when they are not.

This is how to get all of them running anyway. It is written down because five
consecutive pushes were once spent discovering what PHPStan thought of a
branch, and because two of the defects that run found were wrong assertions in
new tests — the failure mode a green CI run cannot catch, since a test that
asserts the wrong thing passes.

## 1. vendor/ from the composer VCS cache

Every package with a `source.url` is already cloned under
`$COMPOSER_HOME/cache/vcs` if composer has ever resolved it.
The cache directory name is the source URL with every non-alphanumeric
character replaced by `-`, **case preserved**.

For each package in composer.lock with a `source.url`:
  git clone <cache-dir> vendor/<name>
  git -C vendor/<name> checkout <source.reference>

Then synthesise vendor/composer/installed.json from composer.lock, adding
`install-path: ../<name>` to each entry, and run:
  composer dump-autoload --no-scripts

## 2. phpstan and deptrac are dist-only

Neither has a `source.url` in the lock, so neither hydrates from the VCS
cache. Both ship a phar in their own GitHub repo:

  git clone --depth 1 https://github.com/phpstan/phpstan.git
  git fetch --depth 1 origin <the lock's dist reference>   # 2.2.12
  git checkout FETCH_HEAD                                  # -> phpstan.phar

Copy phpstan into vendor/phpstan/phpstan and symlink vendor/bin/phpstan.

Deptrac is different: it must **not** live under vendor/. Its committed
bootstrap.php requires its own nested vendor/autoload.php, which redeclares
the phar's ComposerAutoloaderInit class and dies. So:
  - drop deptrac/deptrac from installed.json, re-dump the autoloader
  - keep deptrac.phar outside vendor/ and run `php deptrac.phar analyse`
  - for tools/prove-architecture-gate.php, put a two-line sh shim at
    vendor/bin/deptrac that execs the phar (a shell script, not PHP — do not
    try to run it with `php`)

## 3. The database

  DATABASE_DSN="postgresql://postgres:pw@127.0.0.1:5432/backprod_test"

allmig.sql is extracted from migrations/, and **it skips any SQL a migration
builds with sprintf** — most importantly the 31 VAT rate windows in
Version20260904041000. An empty tax_rates table fails 46 tests that have
nothing wrong with them. Seed it by reflecting on that migration's private
static rates() method.

## 4. What to run

  php vendor/phpunit/phpunit/phpunit --no-coverage
  PHP_CS_FIXER_IGNORE_ENV=1 php vendor/friendsofphp/php-cs-fixer/php-cs-fixer check
  php vendor/bin/phpstan analyse --no-progress
  php <scratch>/deptrac.phar analyse --no-progress
  php tools/prove-*.php

That is the whole CI chain. Before this existed, five pushes were spent
learning what PHPStan thought.

## 5. `composer install` fails, but composer works

Worth being precise about, because a vague version of this was recorded as a
blocker in the roadmap and repeated for several milestones before anyone tried
it:

- packagist metadata resolves normally; `composer update --lock` works
- `getcomposer.org` is refused by the egress policy, so the self-update check
  in `composer diagnose` fails. It is irrelevant
- a package whose `dist` is a GitHub zipball needs authentication and fails.
  `--prefer-source` clones instead and succeeds

So a dependency **can** be added here: `composer require --prefer-source
<package>` updates `composer.json` and `composer.lock`, and the only packages
that then need hand-installing are the dist-only ones from §2. After adding
anything, the new packages must be appended to the synthesised
`vendor/composer/installed.json` (§1) or the autoloader will not see them.
