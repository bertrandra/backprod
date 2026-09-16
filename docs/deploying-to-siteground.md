# Deploying to SiteGround

One origin serves both halves: the built UI is the document root, and everything
under `/api` is PHP. The browser makes no cross-origin request, so §31's strict
CORS never has to be relaxed, and there is nothing to configure about it.

**Nothing here reaches outside the SiteGround account.** SiteGround hosts
PostgreSQL alongside PHP and MySQL, and since ADR-038 this platform issues its
own sessions — so the database, the API and the UI are all one account, with no
third party in the picture at all.

`bin/build-dist.sh` produces the bundle. **Your machine needs Node and Composer;
the host needs neither** — no build step ever runs on SiteGround, which is the
only arrangement that works on a host offering PHP, PostgreSQL and JavaScript and
nothing else.

**`deploy/siteground/setup.php` does §3's migration and seeding, §5's `.env`,
and "Somebody to sign in as" in one browser form, from the host itself.**
Upload it into `public_html/` alongside `index.php`, visit it, and fill in the
database Site Tools just created — no whitelisting your own machine's IP,
because nothing runs from anywhere but the account that already has the run of
its own PostgreSQL. It writes `.env`, runs the migrations, and seeds the
demonstration world ([docs/demo-world.html](demo-world.html): four products,
two organisations, one person per role) — it asks for no product and no
account of your own. Then it makes itself inert: a completion marker refuses a
second run even if you forget to delete the file. **The seeded accounts'
password is in this repository**, so the success page offers the one thing the
page still does afterwards, changing a password; change the platform
administrator's, confirm you can sign in with it, and delete the file — a page
that can rewrite `.env` has no business staying reachable once it has nothing
left to do. The sections below are what it automates; read them if you would
rather do it by hand, or need to understand what it did.

---

## 0. Before anything else: three facts to establish

All three are cheaper to check now than after an upload — R1 in
`docs/backend-roadmap.md` closed this question early in the project, and it is
restated here because it is the fact the whole deployment depends on.

**SiteGround hosts PostgreSQL, on the same account as the site.** Site Tools →
Site → PostgreSQL Manager creates a database and a user the same way the MySQL
manager does. That settles D2/R1: this platform is PostgreSQL throughout —
JSONB columns, partial indexes, triggers that freeze a closed VAT period,
`FOR UPDATE SKIP LOCKED` in the job queue, a gapless invoice sequence — and none
of it needs to port anywhere. **Nothing in this deployment reaches outside
SiteGround at all.** Since ADR-038 the platform also issues its own sessions, so
there is no identity provider either.

**One thing about it is easy to get wrong: what host value to use is not the
same on every SiteGround account.** An earlier version of this document said
the connection is never `localhost`, even for the app running on the same
account, and that every client connects over the network to the site's own
public IP. That was true for the accounts it was checked against — but a real
deployment's own SiteGround support ticket said the opposite for that
account: use `localhost`. Ask your host's support which value applies to
yours rather than assuming either. `setup.php` and `composer run migrate`
both just try whatever host you give them and report a plain connection
failure if it's wrong, rather than refusing a value on principle.

If your account does need the Site IP, find it at Site Tools → Site → Site
Information → IP and Name Servers ("Site IP"), use it as `DATABASE_DSN`'s
host, and in the PostgreSQL Manager's **Remote** tab whitelist that same IP —
the connection counts as remote as far as `pg_hba.conf` is concerned even
though app and database are on the same account — and separately whitelist
your own machine's IP so `composer run migrate` can run from wherever you
are, without SSH. An IP range works too (`1.2.3.0` covers everything starting
`1.2.3.`), and `0.0.0.0/0` allows any address, which is the wrong choice for
anything but a five-minute test. None of this Remote-tab whitelisting applies
if your account uses `localhost` instead.

**Confirm the PostgreSQL major version before you migrate.** Every migration
here calls `gen_random_uuid()`, which has been built into PostgreSQL since
version 13; on an older version it exists only via the `pgcrypto` extension, and
shared hosting sometimes restricts `CREATE EXTENSION` to a superuser nobody but
the host has. Site Tools' PostgreSQL Manager states the version it provisions.
If `composer run migrate` fails with `function gen_random_uuid() does not exist`,
that is this: ask support to confirm or enable `pgcrypto`, or run
`CREATE EXTENSION IF NOT EXISTS pgcrypto;` yourself if the account has the
privilege.

**`pdo_pgsql` must also be enabled on the host's PHP.** Without it PHP cannot
speak to PostgreSQL at all and every API request fails identically. Check it in
Site Tools → Devs → PHP Manager, or over SSH:

```sh
php -m | grep -E 'pdo_pgsql|gd|mbstring|openssl'
```

Four lines back is what you want. `pdo_pgsql` and `gd` are the two that are
sometimes off — `gd` is what mPDF renders invoices with. This one genuinely can
stop the deployment: if `pdo_pgsql` cannot be enabled on your plan, this host
cannot run this platform, and finding that out before uploading 60 MB is the
point of asking first.

---

## 1. Build the bundle

```sh
DEFAULT_PRODUCT=atlas bin/build-dist.sh --slim-fonts --payment-provider stripe
```

**No keys, and one thing to decide.** The browser holds no credential of any
kind, so a bundle built today works against any deployment — and the only thing
that ever configures identity is `.env` on the host. The one build-time
decision is which payment provider the page may talk to:
`--payment-provider stripe` lets the Content-Security-Policy admit Stripe's
origins for the card form (ADR-048); built without it, the page talks to this
origin alone and Stripe's form cannot load, whatever `.env` says.

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

Create the database in Site Tools → Site → PostgreSQL → Create Database, and a
user for it in the Users tab there. If your host value is the Site IP (§0),
whitelist your own machine's IP in the Remote tab too, then migrate without
SSH, from wherever you are:

```sh
export DATABASE_DSN='postgresql://USER:PASSWORD@HOST:5432/DBNAME'
composer run migrate
```

`HOST` is whichever value §0 settled on for your account — the Site IP, or
`localhost` if that's what your host's support told you. Leave `sslmode` unset unless you have confirmed SiteGround's
PostgreSQL accepts TLS on this connection; if it does and you want it enforced,
`sslmode=require` is stricter than the default `prefer`, but an unverified
`require` fails the connection outright rather than degrading, so test it before
committing to it in production.

Then the world to sign in to:

```sh
composer run demo:seed
```

Four products with a three-plan catalogue each, two organisations holding
them, one person per role, and two live subscriptions with the invoices they
raised — [docs/demo-world.html](demo-world.html) lists who is in it and what
each one sees. It refuses a database where one of the four product codes
already exists unless you pass `--reset`, and it never touches reference data —
permissions, roles, EU VAT rates are migration data. Once deployed, the same
reset is a button on Console → Products for the platform administrator; it is
refused while a product that is not the demonstration's exists.

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
| `DATABASE_DSN` | nothing can be read or written; the API answers 503. The host is whatever §0 settled on for your account — check before assuming |
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

### Taking money through Stripe

Three values, all or none: `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` and
`STRIPE_PUBLISHABLE_KEY`. In the Stripe dashboard, add a webhook endpoint for
`https://your-domain/api/v1/webhooks/payments/stripe` listening to
`payment_intent.succeeded`, `payment_intent.payment_failed`,
`payment_intent.canceled`, `charge.succeeded`, `refund.updated` and
`charge.dispute.created`; its signing secret is the `whsec_…`. The charge
event is the one that says *what kind of instrument* paid — the intent's
does not — so without it the payments screen shows no method. Every other
event is accepted and ignored, so subscribing to more costs nothing but
traffic.

**Start in a sandbox.** Sandbox and test-mode keys read `sk_test_` / `pk_test_`
and move no money; the console's setup chain says *Stripe (sandbox)*, the
checkout shows a *Test payment* band, and `bin/preflight.php` warns when
`APP_ENV=prod` is running on one. Before switching to `sk_live_` keys, walk the
four test cards against the sandbox and watch each land where it should:

| Card | Expect |
|---|---|
| `4242 4242 4242 4242` | order → `COMPLETED`, invoice `PAID`, subscription active |
| `4000 0025 0000 3155` | a 3-D Secure challenge, then the same |
| `4000 0000 0000 9995` | order `PAYMENT_FAILED`, *insufficient_funds*, invoice still owed, *Try again* offered |
| `4000 0000 0000 0259` | succeeds, then a dispute arrives: payment `CHARGEBACK`, invoice untouched |

Then a refund from the payments screen, settled when Stripe's `refund.updated`
arrives. Only then swap the three keys for live ones — and the webhook endpoint
for the live-mode one, whose secret differs.

Two things a sandbox walk can trip over that production cannot. Stripe
remembers an idempotency key for 24 hours, and this platform's key is the
invoice number and attempt under the installation's webhook secret — so a
database reset on the *same* installation asks for `2026-000002/1` again
and is handed back yesterday's intent, already paid: the form never becomes
ready. Wait a day, or let the numbering move past the reused ones. (Two
installations on one sandbox do not collide, provided each has a webhook
endpoint — and secret — of its own.) And a second purchase for a tenant that already holds an active
subscription is refused by the database when the webhook settles it —
`subscriptions_one_active_tenant_subscription`, answered 500 so Stripe
retries and the log names the constraint — because the checkout does not
yet refuse it up front.

### Somebody to sign in as

There is no registration screen and no password reset (ADR-038): an operator
creates accounts. `composer run demo:seed` (and the installer) make six, one per
role, and print the password — [docs/demo-world.html](demo-world.html). Change
the platform administrator's before the deployment is reachable by anybody
else: the installer's page does it, or `UPDATE local_credentials SET
password_hash = …` with a `password_hash($password, PASSWORD_BCRYPT)` value.
For a new account, insert a `users` row, set its `auth_subject` to `'local:' || id`,
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
| Database | SiteGround PostgreSQL | Same account, port 5432 — the host value depends on the account (§0) |
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
- **No backup schedule of its own.** `bin/restore-drill.sh` proves the
  *procedure* and needs `pg_dump`, which shared hosting does not have — run it
  from your own machine against a snapshot. What you actually depend on day to
  day is whichever backup system your SiteGround plan includes; their
  documentation is more specific about MySQL than about PostgreSQL, so confirm
  with support that PostgreSQL databases are included before trusting it, rather
  than discovering the gap during an incident.
- **1 GB per database**, at least on the plans checked when this was written.
  Nothing here is close to that yet, but `staff_access_log`, `notifications` and
  the invoice tables grow without bound — worth a retention or export routine
  before it becomes the reason a write starts failing.
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
| Database connection refused, or `no pg_hba.conf entry for host` | Wrong host value for your account — try the other one (Site IP vs `localhost`, §0) and confirm with support. If using the Site IP, also check it's whitelisted in the PostgreSQL Manager's Remote tab |
| `function gen_random_uuid() does not exist` during `composer run migrate` | The account's PostgreSQL predates version 13 and lacks `pgcrypto`. Ask support to confirm the version or enable the extension (§0) |
| Sign-in answers 503 | `AUTH_SIGNING_SECRET` is missing or under 32 characters. The response says which |
| Everybody was signed out at once | `AUTH_SIGNING_SECRET` changed. Every existing token was minted with the old one |
| Signing in works and the next page asks again | The refresh cookie is not coming back. It is `Secure`, so the site must be https — check that SiteGround's certificate is live and that you are not on a plain-http URL |
| Uploads succeed and the files disappear later | `ASSET_STORAGE_ROOT` is unset, so they went to a swept temporary directory |
| Everything works; nothing queued ever happens | No cron entry for `bin/run-jobs.php` |
| Legitimate users are throttled together | `TRUSTED_PROXIES` is unset, so every visitor shares one bucket behind the host's proxy |
| A PHP fatal error appears in the browser | Something outside the front controller's `try`. Report it — the platform is meant to answer with the envelope and log the detail |
