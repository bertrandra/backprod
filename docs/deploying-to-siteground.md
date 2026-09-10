# Deploying to SiteGround

One origin serves both halves: the built UI is the document root, and everything
under `/api` is PHP. The browser makes no cross-origin request, so §31's strict
CORS never has to be relaxed, and there is nothing to configure about it.

`bin/build-dist.sh` produces the bundle. **Your machine needs Node and Composer;
the host needs neither** — no build step ever runs on SiteGround, which is the
only arrangement that works on a host offering PHP, PostgreSQL and JavaScript and
nothing else.

---

## 0. Before anything else: two facts to establish

Both of these can stop the deployment dead, and both are cheaper to check now
than after an upload.

**PostgreSQL is the only thing this deployment needs from outside the host** —
and it is not something SiteGround provides. Their databases are MySQL, and
this platform is PostgreSQL throughout — JSONB columns, partial indexes, triggers
that freeze a closed VAT period, `FOR UPDATE SKIP LOCKED` in the job queue, a
gapless invoice sequence. None of that ports to MySQL by changing a DSN, and
ADR-016's hand-written SQL is not portable by design. So **the database lives
somewhere else**, reached over TLS.

Supabase, Neon, Railway, or your own server — anything that speaks PostgreSQL over
TLS. Nothing else about the platform reaches outside SiteGround: since ADR-038 it
issues its own sessions, so there is no identity provider, no key in the browser,
and nothing to configure at build time.

**`pdo_pgsql` must be enabled on the host's PHP.** Without it PHP cannot speak to
PostgreSQL at all and every API request fails identically. Check it in Site Tools
→ Devs → PHP Manager, or over SSH:

```sh
php -m | grep -E 'pdo_pgsql|gd|mbstring|openssl'
```

Four lines back is what you want. `pdo_pgsql` and `gd` are the two that are
sometimes off — `gd` is what mPDF renders invoices with. If `pdo_pgsql` cannot be
enabled on your plan, this host cannot run this platform, and finding that out
before uploading 60 MB is the point of asking first.

---

## 1. Build the bundle

```sh
DEFAULT_PRODUCT=atlas bin/build-dist.sh --slim-fonts
```

**No keys, and nothing else to decide.** The browser holds no credential of any
kind, so a bundle built today works against any deployment — and the only thing
that ever configures identity is `.env` on the host.

`DEFAULT_PRODUCT` is the product code to act in when a URL does not name one. A
single-product install should set it, or every link needs `?product=CODE`.

`--slim-fonts` keeps only the DejaVu family mPDF actually uses: 8.9 MB instead of
47 MB, at the cost of a blank where a non-Latin legal name would go on an invoice
PDF.

You get:

```
dist/backprod-<version>.tar.gz     the archive
dist/bundle/                       the same thing as a tree, for a file manager
```

Inside either:

```
public_html/          → goes into the document root
  index.php           one file, 40 lines, hands over to the application
  .htaccess           routing, caching, security headers, the CSP
  index.html          the entry document
  assets/             fingerprinted CSS and JS
backprod-app/         → goes BESIDE the document root, never inside it
  src/ config/ migrations/ vendor/ bin/ public/
  .env.example        copy to .env on the host
  var/assets/         uploaded files
  var/pdf/            mPDF's scratch space
BUNDLE.txt            what this is, and which commit it came from
DEPLOY.md             this document
MANIFEST.sha256       every file, checksummed
```

**Why `backprod-app` is outside the document root.** So that `src/`, `vendor/`,
`migrations/` and above all `.env` are not protected by rewrite rules somebody
might edit — they are somewhere no URL can address. The `.htaccess` denies
dotfiles as well, but that is insurance on top of the actual defence.

## 2. Verify it before uploading

```sh
bin/verify-dist.sh dist/backprod-<version>.tar.gz
```

It unpacks the bundle, serves it, and asks it questions over HTTP: does the shim
find the application, does a deep link fall back to the shell, is `/.env`
refused, does an unconfigured deployment answer with the documented envelope
rather than a stack trace, is every asset the entry document references actually
in the archive.

It is explicit about what it cannot prove: it is not Apache, so the routing and
headers it checks are a *mirror* of `.htaccess` written in PHP, and the host's
extensions and cron are outside its reach. Step 7 is where those get proven.

## 3. Create the database and migrate — from your machine

The database is external, so **migrations do not need to run on SiteGround at
all**. This is the step people expect to need SSH for and do not:

```sh
export DATABASE_DSN='postgresql://USER:PASSWORD@HOST:5432/postgres?sslmode=require'
composer run migrate
```

Use the **session pooler** port (5432) or the direct connection. Supabase's
*transaction* pooler on 6543 does not support the prepared statements DBAL
issues, and the failure looks like a driver bug rather than a configuration
choice.

Optionally, a world to look at:

```sh
composer run demo:seed
```

One product, two tenants, a three-plan catalogue, a live subscription and the
invoice it raised. It refuses a database that already has data unless you pass
`--reset`, and it never touches reference data — permissions, roles, EU VAT
rates are migration data.

## 4. Upload

Site Tools → Site → File Manager, or SFTP. Given a domain at
`~/www/example.com/`:

```
~/www/example.com/public_html/     ← contents of public_html/
~/www/example.com/backprod-app/    ← the whole backprod-app/ directory
```

`public_html/index.php` looks for the application at `../backprod-app`. If your
host refuses to read outside the document root (`open_basedir`) or you put it
elsewhere, the `BACKPROD_APP` constant at the top of that file is the one line to
change.

Make `backprod-app/var/assets` and `backprod-app/var/pdf` writable by the web
server (`755` is usually enough on SiteGround, where PHP runs as your own user).

## 5. Configure it

Copy `backprod-app/.env.example` to `backprod-app/.env` and fill it in. That file
explains every value and what its absence costs. The four that are required:

| Value | Without it |
|---|---|
| `DATABASE_DSN` | nothing can be read or written; the API answers 503 |
| `AUTH_SIGNING_SECRET` | nobody can sign in: `/auth/token` answers 503 and says so. **At least 32 characters** — HS256 refuses less |
| `ASSET_LINK_SIGNING_SECRET` | no download link can be signed, so exports and uploads cannot be handed out |

Generate both secrets on the host:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Changing `AUTH_SIGNING_SECRET` signs everybody out at once, which is also how you
revoke every session in an emergency.

Set `ASSET_STORAGE_ROOT` and `PDF_TEMPORARY_ROOT` to the `var/` paths as well.
Unset, uploads go to the system temporary directory, which shared hosting sweeps —
so they vanish at a time unrelated to anything anybody did.

### Somebody to sign in as

There is no registration screen and no password reset (ADR-038): an operator
creates accounts. `composer run demo:seed` makes three, and prints the password.
For a real one, insert a `users` row, set its `auth_subject` to `'local:' || id`,
and add a `local_credentials` row whose `password_hash` comes from
`password_hash($password, PASSWORD_BCRYPT)`. The database refuses a
`password_hash` that is not a hash, so a mistake here fails rather than storing a
plaintext password.

## 6. Select PHP 8.3

Site Tools → Devs → PHP Manager. Leave it on a managed 7.x and nothing runs:
the code uses typed properties, enums and readonly classes throughout.

## 7. Prove the host can actually do it

Over SSH (GrowBig and above):

```sh
cd ~/www/example.com/backprod-app
php bin/preflight.php --strict
```

It names each capability and the consequence of its absence, reaches the
database, and refuses a schema behind its migrations. It never prints a secret —
only whether one is present.

**No SSH on your plan?** Use cron, which every plan has. Site Tools → Devs →
Cron Jobs, add a one-off job:

```
php /home/USER/www/example.com/backprod-app/bin/preflight.php --strict
```

Set it a few minutes out and let SiteGround email you the output. Delete it
afterwards. This works for any one-off command you would otherwise need a shell
for, migrations included.

Then check the live site — this is the step that actually proves the `.htaccess`,
because it is the first time Apache has been involved:

```sh
curl -s https://example.com/api/v1/health                 # {"status":"ok"}
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/.env         # 403
curl -s -o /dev/null -w '%{http_code}\n' https://example.com/invoices     # 200
curl -sI https://example.com/ | grep -i content-security-policy           # present
```

Then open the site, sign in, and land on a screen with data on it.

## 8. The job queue

Nothing is resident — the runner starts, claims what is due, runs it and exits,
which is the only shape shared hosting permits (ADR-027). Site Tools → Devs →
Cron Jobs:

```
* * * * * php /home/USER/www/example.com/backprod-app/bin/run-jobs.php >> /home/USER/jobs.log 2>&1
```

Overlapping runs are safe by design: claiming uses `FOR UPDATE SKIP LOCKED`, so a
run that starts while the last one is still going takes different work rather than
colliding with it.

Without this cron entry the application still works and **nothing queued ever
happens**: no notification is delivered, no export is produced, no pre-renewal
notice is sent. Queued work simply accumulates, and `/console/queue` is where you
will see it doing so.

---

## What runs where

| | Where | Notes |
|---|---|---|
| UI (React) | SiteGround, static | Fingerprinted; cached forever. One 726 kB JS file, 200 kB gzipped |
| API (PHP 8.3) | SiteGround | Everything under `/api`; needs `pdo_pgsql` and `gd` |
| Database | External PostgreSQL | Not SiteGround. Supabase or any managed Postgres, over TLS |
| Identity | SiteGround, in PHP | This platform issues and verifies its own tokens (ADR-038). No external provider |
| Jobs | SiteGround cron | One minute is the shortest interval that matters |
| Uploaded files | `backprod-app/var/assets` | Swap `StorageProvider` for S3 when the disk stops being enough |
| Invoice PDFs | mPDF, in-process | Needs `gd` and a few MB of `PDF_TEMPORARY_ROOT` |

## Known limits of this deployment

Stated here rather than discovered later. `docs/production-readiness.md` is the
fuller list; these are the ones specific to shipping it this way.

- **No password reset, no email verification, no registration, no second factor**
  (ADR-038). Accounts are created by an operator, which is honest for a platform
  whose tenants are too — and the first thing to build if anybody self-registers.
- **No observability.** Logs are structured and carry a request id; on shared
  hosting they go to the host's error log and nothing collects or alerts on them.
  A failing cron job is silent unless you read `jobs.log`.
- **No backup schedule.** `bin/restore-drill.sh` proves the *procedure* and needs
  `pg_dump`, which shared hosting does not have — run it from your own machine
  against a snapshot. Your Postgres provider's own backups are what you actually
  depend on, and nothing here takes one.
- **One JS bundle.** 726 kB, 200 kB gzipped, cached forever after the first load.
  Code-splitting by route would help the first visit; nothing does it yet.
- **R3 — e-invoicing is a stub.** The four transmission states are real; no
  certified Plateforme Agréée is connected. The first French obligation was dated
  1 September 2026.
- **R11 — auto-renewal stays off.** The legally required notice deadlines have
  never been confirmed against a primary source.

## When something is wrong

| What you see | What it is |
|---|---|
| Every page is the raw `index.html` with no styling | `assets/` did not upload, or `mod_rewrite` is off |
| Deep links 404 but `/` works | The last `.htaccess` rule is missing, or `AllowOverride` is off |
| `503 SERVICE_UNAVAILABLE` on every API call | The platform could not start. `DATABASE_DSN`, almost always. The reason is in the host's error log, never in the response |
| Sign-in answers 503 | `AUTH_SIGNING_SECRET` is missing or under 32 characters. The response says which |
| Everybody was signed out at once | `AUTH_SIGNING_SECRET` changed. Every existing token was minted with the old one |
| Signing in works and the next page asks again | The refresh cookie is not coming back. It is `Secure`, so the site must be https — check that SiteGround's certificate is live and that you are not on a plain-http URL |
| Uploads succeed and the files disappear later | `ASSET_STORAGE_ROOT` is unset, so they went to a swept temporary directory |
| Everything works; nothing queued ever happens | No cron entry for `bin/run-jobs.php` |
| Legitimate users are throttled together | `TRUSTED_PROXIES` is unset, so every visitor shares one bucket behind the host's proxy |
| A PHP fatal error appears in the browser | Something outside the front controller's `try`. Report it — the platform is meant to answer with the envelope and log the detail |
