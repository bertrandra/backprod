#!/usr/bin/env bash
#
# Builds the deployable bundle: PHP backend, built UI, and the Apache
# configuration that puts them on one origin.
#
# The shape it produces is the point. `public_html/` holds the built UI and one
# PHP file; `backprod-app/` holds everything else and goes *beside* the document
# root rather than inside it. So `src/`, `vendor/`, `migrations/` and `.env` are
# not protected by a rewrite rule that somebody might delete — they are somewhere
# no URL can address.
#
# Nothing here is SiteGround-specific except the directory names in the
# instructions. Any Apache host with mod_rewrite and PHP 8.3 takes this bundle.
#
# Usage:
#   SUPABASE_URL=https://abc.supabase.co \
#   SUPABASE_ANON_KEY=eyJhbGci... \
#   DEFAULT_PRODUCT=atlas \
#   bin/build-dist.sh [--out DIR] [--mode MODE] [--skip-gates] [--slim-fonts]
#
# This checkout is never modified: dependencies are installed into the bundle, so
# an interrupted build leaves your working tree exactly as it was. Set
# COMPOSER_PREFER=--prefer-source on a network where zipballs are unreliable.
#
# The two Supabase values are required, and deliberately: a bundle built without
# them renders every screen and can sign nobody in, which is the defect U11 part 1
# existed to fix. `--no-auth` says you mean it.

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"

OUT="$ROOT/dist"
MODE="production"
SKIP_GATES=0
NO_AUTH=0
SLIM_FONTS=0

while [ $# -gt 0 ]; do
    case "$1" in
        --out) OUT="$2"; shift 2 ;;
        --mode) MODE="$2"; shift 2 ;;
        --skip-gates) SKIP_GATES=1; shift ;;
        --no-auth) NO_AUTH=1; shift ;;
        --slim-fonts) SLIM_FONTS=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

SUPABASE_URL="${SUPABASE_URL:-}"
SUPABASE_ANON_KEY="${SUPABASE_ANON_KEY:-}"
DEFAULT_PRODUCT="${DEFAULT_PRODUCT:-}"

if [ "$NO_AUTH" -eq 0 ] && { [ -z "$SUPABASE_URL" ] || [ -z "$SUPABASE_ANON_KEY" ]; }; then
    cat >&2 <<'MESSAGE'
Refusing to build: SUPABASE_URL and SUPABASE_ANON_KEY are not both set.

Both are public values — the anon key ships in every bundle and grants nothing on
its own — and the browser is what needs them, so they are baked in at build time
rather than read from the server's .env.

Without them the bundle's sign-in screen says the deployment has no identity
provider, which is true and useless. Pass --no-auth if that is genuinely what you
want (a UI embedded in something else that injects its own token).
MESSAGE
    exit 1
fi

# The provider's origin — for the Content-Security-Policy, and for the bundle.
#
# A Supabase dashboard shows several URLs for one project, and the *REST* endpoint
# is the most prominent: `https://<ref>.supabase.co/rest/v1/`. Someone pasted that
# here, which is the natural mistake, and until this line existed it produced a
# bundle that asked for a token at `/rest/v1/auth/v1/token` — a 404, reported to
# the person as "that email and password do not match an account". A deployment
# fault wearing a credential fault's message.
#
# So the origin is taken once, here, and used for both. The application normalises
# it again at runtime (`authConfig`), because a value can also arrive from an
# environment this script never saw — but it is announced here so that whoever
# built the bundle learns what was configured rather than being quietly corrected.
AUTH_ORIGIN="'none'"

if [ -n "$SUPABASE_URL" ]; then
    ORIGIN="$(printf '%s' "$SUPABASE_URL" | sed -E 's#^(https?://[^/]+).*#\1#')"

    if [ "$ORIGIN" != "$SUPABASE_URL" ]; then
        printf '\nNote: using the origin %s rather than %s.\n' "$ORIGIN" "$SUPABASE_URL"
        printf 'The identity provider is addressed at its origin; the path is not part of it.\n'
    fi

    SUPABASE_URL="$ORIGIN"
    AUTH_ORIGIN="$ORIGIN"
fi

VERSION="$(git -C "$ROOT" describe --tags --always --dirty 2>/dev/null || echo unknown)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

DOCROOT="$STAGE/public_html"
APP="$STAGE/backprod-app"
mkdir -p "$DOCROOT" "$APP"

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

# --- 1. The UI ---------------------------------------------------------------
#
# Built first. It is the half most likely to fail, and failing before a PHP
# dependency tree has been assembled saves a minute on every mistake.

say "Building the UI ($MODE)"

pushd "$ROOT/frontend" >/dev/null

if [ ! -d node_modules ]; then
    npm ci
fi

if [ "$SKIP_GATES" -eq 0 ]; then
    # The same gates CI runs. A bundle nobody linted or typechecked is not a
    # release candidate, and `npm run build` is the script that couples the two.
    npm run gates
fi

VITE_SUPABASE_URL="$SUPABASE_URL" \
VITE_SUPABASE_ANON_KEY="$SUPABASE_ANON_KEY" \
VITE_DEFAULT_PRODUCT="$DEFAULT_PRODUCT" \
    npx vite build --mode "$MODE" --outDir "$DOCROOT" --emptyOutDir

popd >/dev/null

# --- 2. The PHP application --------------------------------------------------

say "Assembling the application"

# Copied explicitly rather than by exclusion. An allowlist that forgets something
# produces a bundle that fails loudly on the host; a denylist that forgets
# something ships `tests/` and `.env` to production.
#
# `vendor/` is deliberately not in this list — see below.
for path in src config migrations bin public composer.json composer.lock migrations.php migrations-db.php openapi.json; do
    cp -R "$ROOT/$path" "$APP/"
done

say "Installing PHP dependencies (production only)"

# **Installed into the bundle, not into this checkout.**
#
# The first version of this script ran `composer install --no-dev` in the
# repository and put the development dependencies back afterwards. That is a
# build step that breaks a developer's working tree if it is interrupted — and it
# was: Composer refused halfway through with "does not have an installation source
# set", leaving the checkout without PHPUnit and this script without a way to
# finish. A build should not be able to damage the thing it is building from.
#
# `--no-dev`: PHPUnit, PHPStan, CS-Fixer and Deptrac are development tools, and
# shipping them puts a test runner and a code generator on a public host.
# `--classmap-authoritative`: every class is known at build time, so the
# autoloader never stats the filesystem looking for one — which on shared hosting,
# where the filesystem is the slow part, is the cheapest performance decision
# available.
composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    "${COMPOSER_PREFER:---prefer-dist}" \
    --optimize-autoloader \
    --classmap-authoritative \
    --working-dir "$APP" \
    --quiet

# --- Trim what a running deployment does not use -----------------------------
#
# Not cosmetic. Uploading to shared hosting is the slowest step in this whole
# procedure, and `--prefer-source` leaves a git checkout inside every package:
# the first bundle built this way was **273 MB, of which 231 MB was `.git`**.
#
# The list is deliberately conservative. Test suites, VCS metadata and CI
# configuration cannot be reached by a running application. `LICENSE` files stay —
# they are a legal obligation, not weight — and so does anything under `src`,
# `data` or `Resources`, because a package that reads its own data at runtime is
# common and guessing wrong is a failure that only appears in production.

say "Trimming the vendor tree"

BEFORE="$(du -sm "$APP/vendor" | cut -f1)"

find "$APP/vendor" -maxdepth 4 -type d \
    \( -name .git -o -name .github -o -name .circleci -o -name tests -o -name Tests \
       -o -name test -o -name local-tests -o -name scratches -o -name docs \) \
    -prune -exec rm -rf {} + 2>/dev/null || true

find "$APP/vendor" -maxdepth 3 -type f \
    \( -name 'phpunit.xml*' -o -name '.gitignore' -o -name '.gitattributes' \
       -o -name 'phpstan*.neon*' -o -name '.editorconfig' \) \
    -delete 2>/dev/null || true

if [ "$SLIM_FONTS" -eq 1 ]; then
    # mPDF ships 88 MB of TrueType fonts covering every script it knows. The
    # invoice template names exactly one family — `dejavusans` — so keeping only
    # DejaVu takes the bundle from 153 MB to about 60 MB.
    #
    # **What that costs.** A character DejaVu does not have renders blank rather
    # than wrong: Latin, Greek and Cyrillic are covered, so a European customer
    # base is fine, and a tenant whose legal name is in Chinese, Japanese, Korean,
    # Arabic, Hebrew, Thai or Devanagari would get an invoice with a hole in it.
    # That is a business decision, which is why it is a flag and not the default.
    say "Keeping only the DejaVu fonts (--slim-fonts)"

    find "$APP/vendor/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' -delete 2>/dev/null || true
fi

printf '  vendor: %s MB → %s MB\n' "$BEFORE" "$(du -sm "$APP/vendor" | cut -f1)"

# The build tooling is not part of a deployment. `bin/build-dist.sh` and
# `bin/verify-dist.sh` build and check bundles; a bundle does not build itself,
# and `restore-drill.sh` needs pg_dump, which shared hosting does not have.
rm -f "$APP/bin/build-dist.sh" "$APP/bin/verify-dist.sh" "$APP/bin/restore-drill.sh"

# Writable, and outside the document root. Created here so that a first upload
# does not need somebody to remember two `mkdir`s and a `chmod`.
mkdir -p "$APP/var/assets" "$APP/var/pdf"

cp "$ROOT/deploy/siteground/env.production.example" "$APP/.env.example"
cp "$ROOT/deploy/siteground/docroot-index.php" "$DOCROOT/index.php"

sed "s#@@AUTH_ORIGIN@@#${AUTH_ORIGIN}#" \
    "$ROOT/deploy/siteground/htaccess.template" > "$DOCROOT/.htaccess"

cp "$ROOT/docs/deploying-to-siteground.md" "$STAGE/DEPLOY.md"

# --- 3. What this bundle is --------------------------------------------------
#
# Recorded rather than remembered. Somebody looking at a tarball six months from
# now needs to know which commit it came from and whether it can sign anybody in.

cat > "$STAGE/BUNDLE.txt" <<INFO
Backprod deployment bundle

  built            $(date -u '+%Y-%m-%d %H:%M:%SZ')
  from             $VERSION
  vite mode        $MODE
  identity         $([ -n "$SUPABASE_URL" ] && echo "$SUPABASE_URL" || echo 'NONE — this bundle can sign nobody in')
  default product  $([ -n "$DEFAULT_PRODUCT" ] && echo "$DEFAULT_PRODUCT" || echo 'none — links need ?product=CODE')
  php required     8.3+, with pdo_pgsql, gd, mbstring, openssl, json
  fonts            $([ "$SLIM_FONTS" -eq 1 ] && echo 'DejaVu only — non-Latin scripts render blank in PDFs' || echo 'complete mPDF set')

Upload public_html/ into the document root, backprod-app/ beside it, then read
DEPLOY.md. Nothing in here contains a secret: the server's own configuration is
.env, which you create on the host from .env.example.
INFO

# Checksums, so a half-finished FTP transfer is a failed check rather than a
# mystery at runtime. Paths are relative to the bundle root.
( cd "$STAGE" && find . -type f ! -name MANIFEST.sha256 -print0 | sort -z | xargs -0 sha256sum > MANIFEST.sha256 )

mkdir -p "$OUT"
ARCHIVE="$OUT/backprod-${VERSION}.tar.gz"
tar -czf "$ARCHIVE" -C "$STAGE" .

# Left as a tree as well as an archive: an operator with only a file manager
# uploads a directory, and comparing a suspect deployment against it is a diff
# rather than an unpack.
rm -rf "$OUT/bundle"
mkdir -p "$OUT/bundle"
cp -R "$STAGE/." "$OUT/bundle/"

say "Done"

printf '  archive   %s (%s)\n' "$ARCHIVE" "$(du -h "$ARCHIVE" | cut -f1)"
printf '  tree      %s\n' "$OUT/bundle"
printf '  files     %s\n' "$(find "$OUT/bundle" -type f | wc -l | tr -d ' ')"
printf '\n  Verify it before uploading:  bin/verify-dist.sh %s\n\n' "$ARCHIVE"
