#!/usr/bin/env bash
#
# Runs the bundle before a host does.
#
# "A backup nobody has restored is a file" is the reasoning behind
# `restore-drill.sh`, and a deployment bundle nobody has served is the same thing:
# every ingredient can be correct and the assembly still wrong — the application
# one directory from where the shim looks, an asset the entry document references
# and the archive does not carry, a policy that refuses the very origin the app
# must reach.
#
# So this unpacks a bundle, serves it, and asks it questions over HTTP.
#
# WHAT IT CANNOT PROVE, stated up front because a check whose limits are unclear
# is worse than none:
#
#   * **It is not Apache.** `php -S` cannot read `.htaccess`, so the routing and
#     the headers below are a *mirror* of those rules written in PHP. They can
#     agree with the file and both be wrong about what Apache does. The rules are
#     simple on purpose for exactly this reason, and `verify-deployment.sh` — run
#     against the live URL after uploading — is what actually proves them.
#   * It says nothing about the host's PHP extensions, its `open_basedir`, or
#     whether cron runs. `bin/preflight.php` on the host answers those.
#
# Usage:
#   bin/verify-dist.sh dist/backprod-x.y.z.zip
#   bin/verify-dist.sh dist/bundle
#   DATABASE_DSN=... bin/verify-dist.sh dist/bundle    # also exercises the API
#   bin/verify-dist.sh dist/bundle --browser           # the real bundle, real browser

set -euo pipefail

cd "$(dirname "$0")/.."
ROOT="$PWD"

TARGET="${1:-}"
BROWSER=0

shift || true

while [ $# -gt 0 ]; do
    case "$1" in
        --browser) BROWSER=1; shift ;;
        *) echo "Unknown option: $1" >&2; exit 2 ;;
    esac
done

if [ -z "$TARGET" ]; then
    echo "Usage: bin/verify-dist.sh <archive.tar.gz|directory> [--browser]" >&2
    exit 2
fi

SCRATCH="$(mktemp -d)"
PORT="${PORT:-8391}"
SERVER_PID=""
FAILURES=0
CHECKS=0

cleanup() {
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
    fi

    rm -rf "$SCRATCH"
}

trap cleanup EXIT

pass() { CHECKS=$((CHECKS + 1)); printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { CHECKS=$((CHECKS + 1)); FAILURES=$((FAILURES + 1)); printf '  \033[31m✗\033[0m %s\n' "$1"; }
note() { printf '    %s\n' "$1"; }
say()  { printf '\n\033[1m%s\033[0m\n' "$1"; }

check() {
    # check <description> <command...> — the command's exit status is the verdict.
    local description="$1"
    shift

    if "$@" >/dev/null 2>&1; then
        pass "$description"
    else
        fail "$description"
    fi
}

# --- Unpack ------------------------------------------------------------------

BUNDLE="$SCRATCH/bundle"
mkdir -p "$BUNDLE"

if [ -d "$TARGET" ]; then
    cp -R "$TARGET/." "$BUNDLE/"
    say "Verifying the tree at $TARGET"
elif [ "${TARGET%.zip}" != "$TARGET" ]; then
    # Whichever container the operator is going to upload is the one worth
    # checking: a zip and a tarball of the same tree can still differ in what
    # they carry, and the point of this script is to check the artefact rather
    # than the intention.
    unzip -q "$TARGET" -d "$BUNDLE"
    say "Verifying the zip $TARGET"
else
    tar -xzf "$TARGET" -C "$BUNDLE"
    say "Verifying the archive $TARGET"
fi

DOCROOT="$BUNDLE/public_html"
APP="$BUNDLE/backprod-app"

# --- 1. Is it the shape a host expects? --------------------------------------

say "Layout"

for path in public_html/index.php public_html/.htaccess public_html/index.html \
            backprod-app/public/index.php backprod-app/vendor/autoload.php \
            backprod-app/config/container.php backprod-app/.env.example \
            backprod-app/bin/run-jobs.php backprod-app/bin/preflight.php \
            BUNDLE.txt DEPLOY.md MANIFEST.sha256; do
    if [ -e "$BUNDLE/$path" ]; then
        pass "$path"
    else
        fail "$path is missing"
    fi
done

check "var/assets and var/pdf exist, so a first upload needs no mkdir" \
    test -d "$APP/var/assets" -a -d "$APP/var/pdf"

# --- 2. Did every byte arrive? ----------------------------------------------

say "Integrity"

if ( cd "$BUNDLE" && sha256sum --quiet --check MANIFEST.sha256 ) >/dev/null 2>&1; then
    pass "every file matches MANIFEST.sha256 ($(wc -l < "$BUNDLE/MANIFEST.sha256" | tr -d ' ') files)"
else
    fail "checksums do not match — the transfer or the build is incomplete"
fi

# --- 3. Is anything here that must never ship? -------------------------------
#
# The three that matter: a real `.env`, the development toolchain, and the tests.
# Each of them has been shipped to production by somebody, in some project, by
# copying a directory.

say "What must not be in it"

if find "$BUNDLE" -name '.env' -o -name '.env.local' -o -name '.env.production' | grep -q .; then
    fail "a .env file is in the bundle — secrets belong on the host, not in an archive"
else
    pass "no .env anywhere; only .env.example"
fi

if [ -d "$APP/tests" ]; then
    fail "tests/ is in the bundle"
else
    pass "no tests/"
fi

DEV_TOOLS=0

for tool in vendor/bin/phpunit vendor/bin/phpstan vendor/bin/php-cs-fixer vendor/bin/deptrac \
            vendor/phpunit vendor/phpstan vendor/friendsofphp vendor/deptrac; do
    if [ -e "$APP/$tool" ]; then
        fail "$tool is in the bundle — a public host does not need a test runner"
        DEV_TOOLS=1
    fi
done

if [ "$DEV_TOOLS" -eq 0 ]; then
    pass "no development dependencies (--no-dev held)"
fi

# Kept after U12 removed the only credential a bundle ever carried. Nothing should
# put one here now — the browser holds no key at all — which is exactly when a
# check like this earns its keep: it is looking for something that has no reason to
# exist, so a hit means something went wrong upstream of the build.
#
# setup.php is excluded from this scan on purpose: it is PHP source that writes
# `AUTH_SIGNING_SECRET=` as an .env *key* it generates a value for at runtime
# (`random_bytes`, never typed by a person or present in this file) — the same
# reason `docs/deploying-to-siteground.md` names it in prose without being a leak.
if grep -rlE 'sb_secret_|service_role|AUTH_SIGNING_SECRET' "$DOCROOT" --exclude=setup.php >/dev/null 2>&1; then
    fail "the document root contains something shaped like a secret — nothing should, since U12"
else
    pass "no credential of any kind in the document root"
fi

# Two PHP files in the document root: the shim, and the onboarding page that
# deactivates itself once it has run (a completion marker, not just the delete
# an operator is told to do afterwards). Anything else there is either reachable
# by URL when it should not be, or a copy of something that already exists in
# the application directory.
PHP_IN_DOCROOT="$(find "$DOCROOT" -name '*.php' -type f | wc -l | tr -d ' ')"

if [ "$PHP_IN_DOCROOT" = "2" ]; then
    pass "exactly two PHP files in the document root (the shim and setup.php)"
else
    fail "$PHP_IN_DOCROOT PHP files in the document root; expected 2"
fi

check "setup.php is in the document root" [ -f "$DOCROOT/setup.php" ]

check "the application is outside the document root" \
    bash -c "[ ! -d '$DOCROOT/../public_html/backprod-app' ] && [ -d '$APP' ]"

# --- 4. Is the configuration internally consistent? --------------------------

say "Apache configuration"

if grep -q '@@' "$DOCROOT/.htaccess"; then
    fail "the .htaccess still contains an unreplaced @@PLACEHOLDER@@"
else
    pass "no unreplaced placeholders in the .htaccess"
fi

# `'none'` beside another source is invalid, and an invalid directive behaves
# differently in each browser and identically in no test. It happened: appending
# a provider origin to `'self'` produced `connect-src 'self' 'none'` whenever a
# bundle was built with --no-auth.
CONNECT_SRC_LINE="$(grep -o "connect-src [^\"]*" "$DOCROOT/.htaccess" | tail -1)"

if printf '%s' "$CONNECT_SRC_LINE" | grep -q "'none'" && [ "$CONNECT_SRC_LINE" != "connect-src 'none'" ]; then
    fail "connect-src mixes 'none' with another source, which is invalid: $CONNECT_SRC_LINE"
else
    pass "connect-src is a valid source list ($CONNECT_SRC_LINE)"
fi

check "the API is routed to PHP before anything else" \
    grep -q 'RewriteRule \^api/ index.php' "$DOCROOT/.htaccess"

check "unknown paths fall back to the SPA rather than 404" \
    grep -q 'RewriteRule \^ index.html' "$DOCROOT/.htaccess"

check "dotfiles are denied" grep -qF '<FilesMatch "^\.">' "$DOCROOT/.htaccess"

# The entry document must reference only its own origin. An external script tag
# would be blocked by the policy this same file sets, and the failure mode is a
# blank page with one console error.
if grep -oE '(src|href)="https?://[^"]+"' "$DOCROOT/index.html" | grep -q .; then
    fail "index.html references an external origin, which its own CSP forbids"
else
    pass "index.html references nothing off-origin"
fi

MISSING_ASSETS=0

while read -r asset; do
    [ -z "$asset" ] && continue

    if [ ! -f "$DOCROOT/$asset" ]; then
        fail "index.html references $asset, which the bundle does not carry"
        MISSING_ASSETS=1
    fi
done <<< "$(grep -oE '(src|href)="/[^"]+"' "$DOCROOT/index.html" | sed -E 's/.*"\/([^"]*)"/\1/')"

if [ "$MISSING_ASSETS" -eq 0 ]; then
    pass "every asset the entry document references is present"
fi

# --- 4b. Did trimming the vendor tree break anything? ------------------------
#
# The build deletes test suites, VCS metadata and — with --slim-fonts — most of
# mPDF's font library. Deleting files from a dependency tree is the kind of
# optimisation that works for a year and then produces a blank invoice, so it is
# checked rather than trusted.
#
# An invoice is what mPDF is here for, and the template names exactly one font
# family. This renders a page in that family, with the accented and currency
# characters a European invoice actually contains.

say "The PDF renderer, after trimming"

cat > "$SCRATCH/render.php" <<'RENDER'
<?php

declare(strict_types=1);

require $argv[1] . '/vendor/autoload.php';

$temporary = sys_get_temp_dir() . '/backprod-verify-mpdf';

if (!is_dir($temporary)) {
    mkdir($temporary, 0o700, true);
}

// The same family the invoice stylesheet names. A missing font is not an error in
// mPDF — it silently falls back or renders nothing — so the check is that real
// glyphs come out, which shows up in the byte count.
$mpdf = new Mpdf\Mpdf(['tempDir' => $temporary, 'format' => 'A4']);
$mpdf->WriteHTML('<p style="font-family: dejavusans; font-size: 9pt">Facture Nº 2026-000001 — Ǎéîöü ЖΔ — 1 234,56 €</p>');

$pdf = $mpdf->Output('', Mpdf\Output\Destination::STRING_RETURN);

if (!str_starts_with($pdf, '%PDF-')) {
    fwrite(STDERR, "Output is not a PDF.\n");

    exit(1);
}

// A page whose text was dropped for want of a font still produces a valid, and
// much smaller, PDF. The floor is what separates "rendered" from "rendered empty".
if (strlen($pdf) < 2000) {
    fwrite(STDERR, sprintf("The PDF is %d bytes, which is too small to contain that line.\n", strlen($pdf)));

    exit(1);
}

printf("%d bytes\n", strlen($pdf));

exit(0);
RENDER

if RENDERED="$(php "$SCRATCH/render.php" "$APP" 2>"$SCRATCH/render.err")"; then
    pass "mPDF renders an invoice line in dejavusans ($RENDERED)"
else
    fail "mPDF cannot render: $(tr -d '\n' < "$SCRATCH/render.err")"
fi

if [ -d "$APP/vendor/mpdf/mpdf/ttfonts" ]; then
    FONTS="$(find "$APP/vendor/mpdf/mpdf/ttfonts" -type f | wc -l | tr -d ' ')"
    NON_DEJAVU="$(find "$APP/vendor/mpdf/mpdf/ttfonts" -type f ! -name 'DejaVu*' | wc -l | tr -d ' ')"

    if [ "$NON_DEJAVU" = "0" ]; then
        note "$FONTS fonts, DejaVu only: a name in a non-Latin script will render blank."
    else
        note "$FONTS fonts, complete set."
    fi
fi

note "bundle: $(du -sh "$BUNDLE" | cut -f1), $(find "$BUNDLE" -type f | wc -l | tr -d ' ') files"

# --- 5. Serve it -------------------------------------------------------------
#
# The router below mirrors the .htaccess rules. It is not Apache; see the note at
# the top of this file.

say "Serving the bundle"

cat > "$SCRATCH/router.php" <<'ROUTER'
<?php

declare(strict_types=1);

/**
 * A mirror of public_html/.htaccess, for `php -S`.
 *
 * The built-in server cannot read .htaccess, so these rules are restated here in
 * the same order. Keep the two in step: a divergence makes this script agree with
 * itself and disagree with the host, which is the one failure a verification
 * script must not have.
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = is_string($path) ? $path : '/';
$docroot = __DIR__ . '/public_html';

// Rule: nothing beginning with a dot is served.
foreach (explode('/', $path) as $segment) {
    if ($segment !== '' && $segment[0] === '.') {
        http_response_code(403);
        header('Content-Type: text/plain');

        echo "Forbidden\n";

        return true;
    }
}

$headers = static function () use ($docroot): void {
    $htaccess = (string) file_get_contents($docroot . '/.htaccess');

    // Read out of the file rather than repeated here, so the policy under test is
    // the one that will be deployed.
    if (preg_match('/Content-Security-Policy "([^"]+)"/', $htaccess, $found) === 1) {
        header('Content-Security-Policy: ' . $found[1]);
    }

    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Frame-Options: DENY');
};

// Rule 1: the API.
if (str_starts_with($path, '/api/')) {
    $headers();
    require $docroot . '/index.php';

    return true;
}

// Rule 2: anything that exists on disk is served as itself.
$file = $docroot . $path;

if ($path !== '/' && is_file($file)) {
    $headers();

    if (str_ends_with($file, '.js') || str_ends_with($file, '.mjs')) {
        header('Content-Type: text/javascript');
        header('Cache-Control: public, max-age=31536000, immutable');
    } elseif (str_ends_with($file, '.css')) {
        header('Content-Type: text/css');
        header('Cache-Control: public, max-age=31536000, immutable');
    }

    readfile($file);

    return true;
}

// Rule 3: everything else is a client-side route.
$headers();
header('Content-Type: text/html');
header('Cache-Control: no-cache, must-revalidate');
readfile($docroot . '/index.html');

return true;
ROUTER

cp "$SCRATCH/router.php" "$BUNDLE/router.php"

# The shim expects the application beside the document root, which is exactly how
# the bundle is laid out — so serving it from here exercises that relationship
# rather than assuming it.
# `exec`, so $! is PHP's own pid rather than a subshell's. Without it the trap
# kills the wrapper, PHP keeps the port, and the next run of this script does all
# its HTTP checks against a *previous* bundle still listening — which is exactly
# how this section first reported nine failures that had nothing to do with the
# bundle under test.
( cd "$BUNDLE" && exec env DATABASE_DSN="${DATABASE_DSN:-}" php -S "127.0.0.1:$PORT" router.php >"$SCRATCH/server.log" 2>&1 ) &
SERVER_PID=$!

READY=0

for _ in $(seq 1 40); do
    if curl -fsS -o /dev/null "http://127.0.0.1:$PORT/" 2>/dev/null; then
        READY=1
        break
    fi

    sleep 0.25
done

if [ "$READY" -eq 0 ]; then
    # Stop here rather than printing a page of failures that all mean "nothing
    # answered". A check whose subject is absent is not a failing check.
    fail "the bundle would not serve on 127.0.0.1:$PORT"
    printf '\n'
    sed 's/^/    /' "$SCRATCH/server.log" | head -10
    printf '\n  Set PORT= to something else if it is in use.\n\n'
    exit 1
fi

get() { curl -sS -o "$SCRATCH/body" -D "$SCRATCH/headers" -w '%{http_code}' "http://127.0.0.1:$PORT$1"; }

expect_status() {
    local path="$1" expected="$2" description="$3"
    local actual

    actual="$(get "$path")"

    if [ "$actual" = "$expected" ]; then
        pass "$description"
    else
        fail "$description (got $actual, expected $expected)"
    fi
}

expect_body() {
    local path="$1" needle="$2" description="$3"

    get "$path" >/dev/null

    if grep -q "$needle" "$SCRATCH/body"; then
        pass "$description"
    else
        fail "$description"
    fi
}

say "Over HTTP"

expect_body "/" 'id="root"' "/ serves the application shell"
expect_body "/invoices/inv-1?tab=lines" 'id="root"' "a deep link serves the shell, not a 404"
expect_status "/.env" 403 "/.env is refused"
expect_status "/.htaccess" 403 "/.htaccess is refused"

ASSET="$(grep -oE 'src="/[^"]+\.js"' "$DOCROOT/index.html" | head -1 | sed -E 's/src="\/([^"]*)"/\1/')"

if [ -n "$ASSET" ]; then
    expect_status "/$ASSET" 200 "the fingerprinted bundle is served"

    get "/$ASSET" >/dev/null

    if grep -qi 'immutable' "$SCRATCH/headers"; then
        pass "assets are cacheable forever, as fingerprinted files should be"
    else
        fail "assets carry no immutable cache header"
    fi
fi

get "/" >/dev/null

if grep -qi 'content-security-policy' "$SCRATCH/headers"; then
    pass "a Content-Security-Policy is sent"
else
    fail "no Content-Security-Policy on the entry document"
fi

# --- 6. The API, and what it must never say ---------------------------------

say "The API"

if [ -n "${DATABASE_DSN:-}" ]; then
    expect_body "/api/v1/health" '"status":"ok"' "the health endpoint answers ok"
    expect_status "/api/v1/me" 401 "an unauthenticated read is refused"
    expect_body "/api/v1/me" 'UNAUTHENTICATED' "the refusal uses the documented envelope"
else
    # Not a gap in the bundle. Without a DSN the platform cannot start, and saying
    # so with the envelope instead of a stack trace is the behaviour under test.
    expect_status "/api/v1/health" 503 "an unconfigured deployment answers 503, not 200"
    expect_body "/api/v1/health" 'SERVICE_UNAVAILABLE' "and says so in the documented envelope"
    note "DATABASE_DSN is unset, so the authenticated path was not exercised."
    note "Re-run with DATABASE_DSN set to check it too."
fi

# The rule that matters most on shared hosting, where php.ini is somebody else's
# decision: no response, on any path, may carry a trace or a filesystem path.
LEAKED=0

for path in / /api/v1/health /api/v1/me /nonexistent; do
    get "$path" >/dev/null

    if grep -qE 'Stack trace|/home/|/var/www|\.php on line|SQLSTATE' "$SCRATCH/body"; then
        fail "$path leaked a trace, a path or a SQL error"
        LEAKED=1
    fi
done

if [ "$LEAKED" -eq 0 ]; then
    pass "no response carries a stack trace, a filesystem path or a SQL error"
fi

# --- 7. Optionally, a real browser ------------------------------------------

if [ "$BROWSER" -eq 1 ]; then
    say "In a browser"
    note "No special build is needed since U12: signing in is stubbed like any other API call."

    if ( cd "$ROOT/frontend" && PLAYWRIGHT_DIST_URL="http://127.0.0.1:$PORT" \
        npx playwright test --config=playwright.dist.config.ts >"$SCRATCH/playwright.log" 2>&1 ); then
        pass "the browser suite passes against the served bundle"
        note "$(grep -E '[0-9]+ passed' "$SCRATCH/playwright.log" | tail -1)"
    else
        fail "the browser suite failed against the served bundle"
        tail -30 "$SCRATCH/playwright.log" | sed 's/^/    /'
    fi
fi

# --- Verdict ----------------------------------------------------------------

say "Result"

if [ "$FAILURES" -eq 0 ]; then
    printf '  \033[32m%d checks passed.\033[0m\n\n' "$CHECKS"
    printf '  Not proven here: Apache itself, the host'"'"'s PHP extensions, and cron.\n'
    printf '  Run bin/preflight.php --strict on the host, then verify the live URL.\n\n'
    exit 0
fi

printf '  \033[31m%d of %d checks failed.\033[0m\n\n' "$FAILURES" "$CHECKS"
sed 's/^/    /' "$SCRATCH/server.log" | tail -20
exit 1
