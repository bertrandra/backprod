# Production readiness

What this platform can prove about itself, what it cannot, and the two commands
that answer the first question honestly.

This document exists because "production ready" is a claim, and the rest of this
repository is built on the principle that a claim nothing checks is a claim that
will be wrong. So it lists what is *mechanically verified*, what is *built but
unrehearsed*, and what does not exist at all — in that order, because the third
list is the one that matters when somebody is deciding whether to launch.

---

## 1. Verified on every CI run

| Claim | What proves it |
|---|---|
| The contract describes every route the router serves | `gate:openapi` — 137 operations, both directions |
| Every operation has a screen, and every screen calls what it claims | `gate:ui` + `gate:screens` — 130 in 34 areas, all called |
| No code branches on a product, plan or tier name | `gate:products`, `gate:plans` |
| Entitlement decisions have one door | `gate:entitlements` |
| No mutation that moves money is optimistic | `gate:money` — 7 modules |
| Every permission the frontend gates on exists | `gate:permissions` |
| Domain code cannot reach SQL | `deptrac` + `gate:proof`, which proves the gate itself rejects a violation |
| The behaviour, against a real PostgreSQL 16 | 825 tests, 5 964 assertions |
| Every route is usable without a mouse and passes WCAG 2.1 AA | 60 axe scans across both shells and both viewports |
| A password becomes a session, and a spent refresh token revokes the account's sessions | `SignInTest` — 13 cases against the real database (ADR-038) |
| The §37.4 chain works for a person, not only in PHPUnit | `e2e/sales-chain.spec.ts` |

## 2. Built, and rehearsable here

**`bin/build-dist.sh` and `bin/verify-dist.sh`** — the bundle, and proof it serves.

The build puts the UI and one PHP file in the document root and everything else
*beside* it, so `src/`, `vendor/` and `.env` are not protected by a rewrite rule
somebody could delete — they are somewhere no URL can address. The verification
unpacks a bundle, serves it, and asks it questions: does the shim find the
application, does a deep link reach the shell, is `/.env` refused, does an
unconfigured deployment answer with the documented envelope rather than a stack
trace, does mPDF still render after the vendor tree was trimmed. `--browser` runs
the whole Playwright suite against the served bundle, which is the only thing that
proves the Content-Security-Policy does not break the application.

**`composer run preflight`** — what this deployment can and cannot do.

Every secret defaults to "off", deliberately: an empty `SUPABASE_JWKS` verifies
no key, an empty payment secret accepts no forgeable webhook. Each default is
safe. **Together they are a deployment that starts cleanly, answers `/health`
with `ok`, and quietly does almost nothing** — and until U10 nothing said so.
`--strict` is what a deploy pipeline runs; there, a missing signing secret is not
a warning. It never prints a secret, only whether one is present.

It also reaches the database and checks the schema is not behind its migrations,
because a deployment running against an old schema fails in ways that look like
bugs in the code.

**`composer run restore:drill`** — proves a backup is one.

Dumps, restores into a scratch database, and asks the restored copy what would
matter after a real incident: are the invoices there, are their legal numbers
identical, is the schema at the same migration, did the trigger that freezes a
closed VAT period survive, do subscriptions still reach their offer version.
Rows alone are not a restore — a copy that lost the freeze trigger would look
perfect and have lost the invariant.

It leaves the source untouched and drops the scratch database either way.
Proven by dumping schema-only (three checks fail) and data-only (the restore
itself fails).

**`composer run demo:seed`** — a world to look at, and to run the two above
against. It goes through `Subscriptions::subscribe` and
`Invoicing::issueForSubscription` rather than INSERT, so the invoice number comes
from the gapless sequence.

## 3. Does not exist

Stated plainly, because a readiness document that omits these is worse than none.

- **No staging, and no pipeline.** A deployment *procedure* now exists and is
  checked on every CI run — `bin/build-dist.sh` produces a bundle for any Apache
  host with PHP 8.3, `bin/verify-dist.sh` serves it and interrogates it over HTTP,
  and `docs/deploying-to-siteground.md` is the operator's copy. What does not
  exist is a *place*: no staging environment, nothing that deploys on merge, and
  no bundle has yet been uploaded to a host anybody uses. The verification is also
  explicit that it is not Apache — it mirrors the `.htaccess` rules in PHP, so the
  first real proof of those rules is a `curl` against a live URL.
- **No observability.** Logging is structured and carries a request id; nothing
  collects, stores or alerts on it. There is no dashboard and no on-call signal.
- **No backup schedule.** The drill above proves the *procedure*; nothing takes a
  backup on a timer, and nothing has been restored from a real snapshot.
- **No load testing.** The performance budgets in `e2e/resilience.spec.ts` measure
  a route against a stubbed API. Nobody knows what this does under concurrency.
- **No secret management.** Secrets come from `.env`. There is no vault, no
  rotation, and no audit of who read one.
- **No password reset, no email verification, no registration** (ADR-038). A
  credential is set by `demo:seed` or by SQL. That is honest while tenants are
  created by an operator and dishonest the moment anybody self-registers, which
  makes it the next thing to build rather than a gap to live with.
- **No certified e-invoicing platform** (R3). The four transmission states are
  real and the adapter is a stub. The first French obligation is dated
  **1 September 2026**, which has passed.
- **Auto-renewal is gated off** (R11). The pre-renewal notice mechanism exists;
  the legally required deadlines have never been confirmed against an official
  source, because every `gouv.fr` and `europa.eu` domain is refused by this
  environment's egress proxy — re-confirmed 2026-09-10. A committed subscription
  must not auto-renew unattended until they are.

## 4. The order these would go in

1. A deployment and a staging environment, so anything below can be observed at
   all.
2. Observability, because the next four items are unanswerable without it.
3. A backup schedule, then a drill against a real snapshot rather than a seeded
   database.
4. Secret management and rotation.
5. Load testing, once there is somewhere to load.
6. R3 and R11, which are external dependencies rather than engineering: a
   certified platform with a contract behind it, and legal deadlines confirmed
   against a primary source.
