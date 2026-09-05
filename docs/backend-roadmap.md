# Backend Roadmap & Execution Plan

**Scope:** the PHP backend only (Architecture V2 §11, §12, §12.1, §41.1).
**Status:** proposed execution plan
**Governs:** build order, exit criteria, definition of done per milestone

This plan sequences the backend independently of the frontend phases in
Architecture V2 §38. That is deliberate: the backend is a shared platform
(§12.1), not the backend of one product, so it can be built and tested
against its OpenAPI contract before any React screen exists.

---

## 0. Decisions to lock before writing code

These are architecture decisions (§1.3) — each needs an ADR before the code
that depends on it. Building without them causes rework in the request
pipeline, which is the hardest layer to change later.

| # | Decision | Why it blocks | Proposed default |
|---|---|---|---|
| D1 | **How `product_id` reaches the backend** — header, subdomain, path prefix, or claim | The whole context pipeline resolves product *first* (§10.6). Changing it later touches every route. | `X-Product` header, validated against the product registry; backend remains the authority |
| D2 | **Hosting capability** — PostgreSQL 15+, PHP 8.3, cron, outbound HTTPS | ADR-009/ADR-011 assume PostgreSQL + JSONB; §33 assumes SiteGround | **Settled: PostgreSQL is available.** ADR-009 stands as written |
| D3 | **Job execution model** — cron-driven runner vs. persistent worker | §27 requires async jobs; shared hosting usually forbids daemons | **Settled: a cron-polled queue table** behind a worker-compatible interface. `bin/run-jobs` claims work with `FOR UPDATE SKIP LOCKED` and exits; nothing stays resident. A persistent worker can later call the same runner without the queue changing (ADR-027) |
| D4 | **Supabase JWT verification** — JWKS endpoint vs. shared secret | Determines `AuthProvider` adapter shape | Verify RS256 via JWKS, cache keys |
| D5 | **Tenant resolution rule** — one tenant per user, or explicit selection | §12 supports B2C and B2B; affects every request | Derive from membership; explicit selection only when a user has several |
| D6 | **Migration tool** — Doctrine Migrations vs. Phinx vs. plain SQL | Locked in from the first table | Doctrine Migrations (already a justified component per CLAUDE.md) |

**Rule:** no endpoint is implemented before D1, D4 and D5 are decided.

---

## 1. Milestone overview

```text
M0  Skeleton & quality gates        no business logic
      ↓
M1  Request context pipeline        the spine: auth → product → tenant → role → entitlement
      ↓
M2  Platform identity               users, tenants, memberships, roles
      ↓
M3  Product registry                products, catalog, features, configuration
      ↓
M4  Projects                        first product resource, JSONB + versions
      ↓
M5  Commerce                        offers → subscriptions → entitlements enforced
      ↓
M6  Billing & payments              invoices, PSP webhooks, e-invoicing adapter
      ↓
M6.1 Fiscalité / TVA                tax profiles, regimes, VAT transactions, reporting
      ↓
M6.2 Staff identity & messaging     platform roles, audited access, conversations
      ↓
M7  Storage & jobs                  assets, exports, async operations
      ↓
M5.1 Abonnements: engagement        total term, commitment, cancellation policy
      ↓
M7.1 Notifications                  one event, many channels, consent-gated
      ↓
M8  Admin, audit & hardening        financial dashboard API, observability, RGPD
```

M0–M2 are strictly sequential — they build the security boundary. M4 onward
can overlap once the pipeline is frozen.

A decimal number says which milestone a unit *extends*, not when it runs.
M5.1 revisits M5's `subscriptions` table long after M5 shipped; M7.1 needs
M7's queue before it can send anything.

---

## 2. Milestones

### M0 — Skeleton & quality gates

**Goal:** an empty but fully governed codebase. A PR can be merged and every
gate in §37.13 runs, with nothing business-related implemented yet.

**Deliverables**
- `composer.json` — PHP 8.3+, PSR-7/15, FastRoute, PHP-DI, Doctrine DBAL + Migrations
- `src/Shared/` — `Http/`, `Database/`, `Security/`, `Validation/`, `Exceptions/`
- Front controller `public/index.php` → PSR-15 middleware pipeline → FastRoute dispatcher
- Common error format (§10.4) as an exception handler, including `request_id`
- `config/` with environment loading; **no secrets in Git** (§31)
- `GET /api/v1/health` — the only route
- CI workflow running the full gate chain
- `deptrac.yaml` encoding the forbidden dependencies from §37.6
- `phpstan.neon` at max level, `.php-cs-fixer.php`

**Exit criteria**
- `/api/v1/health` returns 200 with the documented envelope
- PHPStan, PHP-CS-Fixer, Deptrac, PHPUnit all green in CI
- A deliberately illegal dependency (`Domain → SQL`) fails Deptrac in CI — gate proven, not assumed

---

### M1 — Request context pipeline

**Goal:** the security spine of §10.6, resolved in order, as middleware. This
is the most important milestone in the plan: every later endpoint inherits it.

```text
Authentication → Product resolution → Tenant resolution
      → Role/permissions → Entitlements → Resource authorization
```

**Deliverables**
- `AuthProvider` interface + `SupabaseAuthProvider` (JWT/JWKS verification)
- `ProductResolver` — resolves and validates `product_id` per D1
- `TenantResolver` — derives tenant from authenticated identity per D5
- `RequestContext` value object: `product_id`, `tenant_id`, `user_id`, roles, entitlements
- `EntitlementChecker` — single choke point for capability checks
- `AuthorizationMiddleware` — resource-level authorization hook
- `GET /api/v1/me` — first authenticated endpoint, exercises the whole chain

**Tests (mandatory, §37.10)**
- Tenant A cannot reach tenant B's resources → 403/404, never data
- Client-supplied `tenant_id` is ignored — the non-negotiable in §31 and CLAUDE.md
- Expired session, invalid token, missing product, unknown product
- Each role in the matrix resolves to the expected permission set

**Exit criteria**
- `RequestContext` is the *only* way downstream code learns tenant or product
- A test proves a forged `tenant_id` in body, query and header changes nothing
- Deptrac forbids any module reading tenant identity outside the context

---

### M2 — Platform identity

**Goal:** users, tenants and membership, so context resolution has real data.

**Deliverables**
- Migrations: `users`, `tenants`, `tenant_members`, `roles`, `permissions`
- `Tenant/` and `User/` modules (Controller / Service / Repository / DTO / Validator)
- Endpoints: `GET|PATCH /me`, `GET /me/permissions`, `GET|PATCH /tenants/current`,
  `GET|POST|PATCH|DELETE /tenants/current/members[/{userId}]`
- Every business table carries `tenant_id` (§12)

**Tests:** tenant creation, isolation, roles, permissions, member add/remove/role change.

**Exit criteria:** a user in two tenants sees strictly separate data in every endpoint.

---

### M3 — Product registry

**Goal:** make multi-product real, so no product name ever appears in a
conditional (§10.6, CLAUDE.md).

**Deliverables**
- Migrations: `products`, `product_features`, `product_configuration`
- Endpoints: `GET /products`, `/products/{id}`, `/{id}/catalog`, `/{id}/features`, `/{id}/configuration`
- Product module registration mechanism — a new product is added by
  configuration + module, never by cloning the backend

**Exit criteria**
- A second, throwaway product is registered in tests and served by the same code paths
- A grep for `=== 'product-` in `src/` returns nothing, enforced by a CI check

---

### M4 — Projects

**Goal:** the first real product resource, with the versioning model from §17.

**Deliverables**
- Migrations: `projects` (JSONB payload + searchable columns), `project_versions`
- Endpoints: full `/projects` CRUD, `/projects/{id}/versions[/{versionId}]`,
  `/duplicate`, `/restore`
- `schemaVersion` enforced on write (non-negotiable #10); reject unknown versions
- Large assets explicitly rejected from JSONB (non-negotiable #9)

**Exit criteria:** version snapshot → restore round-trips byte-identically; a
project without `schemaVersion` is refused with a documented 422.

---

### M5 — Commerce: offers, subscriptions, entitlements

**Goal:** authorization driven by entitlements, never by plan-name checks (§13).

**Deliverables**
- Migrations: `offers`, `offer_versions`, `plans`, `features`, `entitlements`,
  `subscriptions`, `subscription_events`
- Offer versioning — a price or quota change creates a version, never rewrites history (§12)
- `valid_from` / `valid_until` kept distinct from subscription dates
- Endpoints: `/offers*`, `/plans`, `/features`, `/entitlements`, `/me/entitlements`,
  `/tenants/current/usage`, `/subscription*` including `change-offer`, `cancel`, `resume`
- Quota enforcement wired into `EntitlementChecker` from M1

**Tests (§37.4 SaaS):** activation, expiration, renewal, upgrade, downgrade,
cancellation, quota exhaustion.

**Exit criteria:** a CI check proves no `$plan === 'PRO'`-style branch exists;
expiring an offer leaves historical subscriptions intact and readable.

---

### M6 — Billing, payments, e-invoicing

**Goal:** the traceable chain of non-negotiable #20.

```text
Quote → Order → Subscription/Purchase → Invoice → Payment
```

**Deliverables**
- Migrations: `quotes`, `quote_lines`, `orders`, `order_lines`, `invoices`,
  `invoice_lines`, `payments`, `payment_events`, `refunds`, `credits`,
  `financial_events`, `billing_profiles`, `tax_records`
- Invoices store their own snapshot — never derived from current plan values (§25)
- `PaymentProvider` adapter; **webhook is the source of truth** (§24)
- Idempotent webhook handling: signature verified, replay-safe, duplicate-safe
- `EInvoiceProvider` adapter with the status machine
  (`DRAFT → ISSUED → READY_FOR_EINVOICE → SUBMITTED → ACCEPTED/REJECTED → PAID/CANCELLED/CREDITED`)
- PDP-agnostic — no single-provider coupling (non-negotiable #17)

**Tests (§37.4 Billing):** the happy chain plus payment failed, webhook
duplicated, webhook delayed, refund, chargeback, invoice rejected.

**Exit criteria:** replaying a webhook twice produces exactly one activation;
an invoice remains correct after its offer is re-versioned.

**Delivered** in three parts: invoicing (ADR-021), payments and credit notes
(ADR-022), and the sales chain with e-invoicing (ADR-023).

**Amended** by ADR-024, which put the chain in the order money actually moves:

```text
Quote → Order → Invoice → Payment → Subscription
```

Fulfilment used to start the subscription on the assumption payment would
follow — a decision to extend credit to everyone who could reach the endpoint,
taken by default. Now fulfilling raises the invoice and parks the order at
`AWAITING_PAYMENT`, and the subscription starts when that invoice reaches
`PAID` by either sanctioned route: a provider's webhook, or an operator
reconciling a bank transfer.

---

### M6.1 — Fiscalité / TVA — *delivered ([ADR-029](adr/ADR-029-tax-regimes-and-fiscal-facts.md))*

**Goal:** produce and retain the fiscal data a sale generates, so that VAT can
be justified, declared and exported — without turning the platform into an
accounting package. Specified in `architecture-v2.md` §25.3.

M6 invoices carried a VAT rate from `VatPolicy` (now deleted), whose own
docblock said what it was not: a configured per-country number, applied
blindly, with no notion of who the customer is or which regime governs the
sale. That is honest for a single-country B2C launch and wrong the moment a
German company buys with a VAT number.

```text
today:      country code → rate → invoice line
M6.1:       customer profile + supply + verified number + place of taxation
                → rule → regime → rate → VATTransaction
```

**Deliverables**
- Migrations: `customer_tax_profiles`, `tax_identifications`, `tax_rates`
  (with validity windows), `tax_rules`, `vat_transactions`,
  `vat_reporting_periods`, `vat_declarations`
- `tax_records` keeps the per-rate breakdown *inside* an invoice;
  `vat_transactions` carries the declarable fiscal fact
- Seed the EU-27 standard rates from §25.3, each with its validity window —
  **verified against official sources before production** (see R6)
- `TaxRule` engine: STANDARD / REVERSE_CHARGE / OSS / EXEMPT / ZERO_RATED /
  OUT_OF_SCOPE, returning a **motivated** decision, never a bare rate
- `VatNumberValidator` adapter (VIES), fail-closed, result stored with its
  date as audit evidence
- Endpoints: `/tax/profile`, `/tax/rates`, `/tax/calculate`,
  `/tax/transactions`, `/tax/reports`, `/tax/reports/{period}/close`,
  `/tax/export`; permissions `tax.read` / `tax.manage`
- Mentions légales on the invoice when the regime requires them
  (autoliquidation, exonération)

**Tests (§37.4 Fiscalité):** B2C national, B2B intra-EU verified and
unverified, B2C intra-EU under OSS, export outside the EU; plus the history
invariants — a rate change moves no invoiced VAT, a replayed period gives the
same figure, VAT transactions sum to the invoice total, a closed period
refuses modification, VIES unreachable grants no reverse charge.

**Exit criteria:** changing a rate leaves every existing invoice and every
closed period byte-identical; a B2B intra-EU sale with a verified number
invoices at zero with the mention and its `vat_transactions` row says
`REVERSE_CHARGE`; the same sale with an unverified number does not.

**Why before M7:** every invoice raised between now and M6.1 carries a rate
chosen without a regime. Those are documents with legal retention — they
cannot be quietly recomputed later, and the longer the gap, the larger the
population that has to be corrected by hand rather than by rule.

---

### M6.2 — Platform staff identity & messaging

**Goal:** let the platform talk to its customers, and let a customer's team
talk among themselves — without either becoming a way across the tenant
boundary. Specified in `architecture-v2.md` §12.2 and §12.3.

**Why this pulls M8 work forward.** Messaging *your* users needs a sender who
is not a member of the tenant being written to, and no such identity exists:
`TENANT_ADMIN` is the customer's administrator, and the platform-wide roles
of §25.2 are a comment in the M2 migration saying they arrive with M8. So
M6.2 brings that identity forward — not the whole admin surface, only the
identity, its permissions, and the audit trail that makes it accountable.

**Part 1 — platform staff identity** — *delivered ([ADR-025](adr/ADR-025-platform-staff-identity.md))*
- Migrations: `platform_roles`, `platform_staff`, `platform_role_permissions`
- `platform_staff` is a **separate table** from `tenant_members`: one table
  holding both would make a forgotten filter into privilege escalation
- Context pipeline resolves the two axes independently; neither converts into
  the other
- `/api/v1/staff/*` routes: authorized by platform role, tenant passed as an
  explicit parameter, every access audited (who, when, which tenant, why)
- Non-negotiables #21 and #22

**Part 2 — conversations and messages** — *delivered ([ADR-026](adr/ADR-026-conversations-and-messages.md))*
- Migrations: `conversations`, `conversation_participants`, `messages`
- Invariants in the database: a conversation names `(tenant, product)`; an
  author is a participant (foreign key, not a check);
  `UNIQUE (conversation_id, seq)`; a STAFF participant implies a SUPPORT
  conversation
- Per-participant read watermark, monotone
- Polling with `since_seq` — R2 forbids a held-open connection, so no
  WebSocket and no SSE
- Deletion is real deletion of the body, with a tombstone row keeping the
  thread's order — the opposite of an invoice, and deliberately so

**Tests (§37.4 Messagerie):** isolation first — cross-tenant read, cross-product
read, writing to a thread one does not belong to, a STAFF joining an INTERNAL
conversation, a platform role opening a tenant route, a `TENANT_ADMIN` opening
a staff route, and the audit row for every staff access. Then behaviour: the
watermark never goes backwards, `since_seq` returns only what followed, a
deleted message loses its body while the thread keeps its order.

**Exit criteria:** no route lets any identity read a conversation outside the
`(tenant, product)` it was resolved for; every staff read of tenant data has
an audit row written in the same transaction as the act it justifies.

**Deferred:** attachments (they belong to the `StorageProvider` of §15, so
M7), and email notification of unread messages (a §27 job, so M7).

---

### M7 — Storage & jobs

**Goal:** large assets out of the database and long operations off the request path.

**Deliverables**
- `StorageProvider` adapter; private assets with signed URLs (§31) — *delivered ([ADR-028](adr/ADR-028-assets-and-signed-links.md))*
- Endpoints: `/projects/{id}/assets*`, `/projects/{id}/exports*`
- Job queue per D3: `POST /jobs`, `GET /jobs/{id}`, `POST /jobs/{id}/cancel` — *delivered ([ADR-027](adr/ADR-027-cron-polled-job-queue.md))*, with `sweep.quotes` and `sweep.subscriptions` as its first handlers
- Upload validation: type, size, content sniffing — *delivered*; the sniffed type is what is stored, and the request's claim is never read
- `GeoProvider` interface for spatial operations — **implemented over
  PostgreSQL, no PostGIS dependency**, so a spatial backend can be introduced
  later without the domain knowing

**Exit criteria:** a long export returns a job id, never blocking HTTP; no
base64 payload can reach JSONB.

---

### M5.1 — Abonnements : durée, engagement, résiliation — *delivered ([ADR-030](adr/ADR-030-commitment-terms-and-motivated-cancellation.md))*

**Goal:** make a subscription a contract with a duration rather than a
recurring charge that anyone can stop at any time. Specified in
`architecture-v2.md` §13.1.

M5 gives a subscription a billing period and `cancel_at_period_end`, which is
the right answer for a month-to-month plan and the wrong one for a B2B deal.
Nothing today can express "24 months, billed monthly, no exit before month
12" — so nothing today can refuse an exit at month two.

```text
today:   billing_period + cancel_at_period_end
M5.1:    billing_period + term + commitment + cancellation policy + renewal
```

**Deliverables**
- Migration extending `offer_versions` (`term_months`, `commitment_months`,
  `cancellation_policy`, `renewal`, `early_termination`, `notice_days`) and
  `subscriptions` (the same, snapshotted, plus `subscriber_kind`,
  `subscriber_user_id`, `term_ends_at`, `commitment_ends_at`,
  `cancel_effective_at`)
- The invariants as CHECK constraints, not service code: commitment ≤ term, a
  commitment date exactly when there is a commitment, a named subscriber
  exactly when the subscriber is a person
- Replace `subscriptions_one_active_per_product` with the two partial unique
  indexes — one per subscriber kind
- Seat subscriptions: `subscriber_kind = USER` entitles that person only;
  entitlement resolution learns the distinction, and a `USER` subscriber
  outside the tenant is refused
- Cancellation service returning a **motivated** decision (accepted with its
  effective date, or refused with which rule refused it) — never a bare
  boolean
- `CHARGE_REMAINING` early exit invoiced through the existing billing chain
- Endpoints: `POST /subscriptions/{id}/cancel`, `/resume`,
  `GET /subscriptions/{id}/schedule`

**Tests (§37.4 Abonnements):** cancellation under a `FORBIDDEN` commitment
refused; under `AT_COMMITMENT_END` deferred; outside commitment effective at
period end; a repriced offer changing no subscribed condition; two
simultaneous subscriptions racing the index; a `USER` subscription entitling
one person and not the tenant.

**Exit criteria:** a 24-month commitment billed monthly refuses a month-two
cancellation and says which rule refused it; changing the offer's terms
leaves every existing subscription byte-identical; the seat index refuses a
second active subscription for the same person under concurrency.

**Why now:** every subscription sold before this ships is sold without a
term. Those are contracts — re-deriving a commitment after the fact means
asserting a customer agreed to something the database never recorded.

---

### M7.1 — Notifications multi-canal — *delivered ([ADR-030](adr/ADR-030-notifications-across-channels.md))*

**Goal:** one event, many channels, none of them in the request path.
Specified in `architecture-v2.md` §27.1.

Four features are already waiting on this: unread messages (§12.3, deferred
from M6.2), payment failure (§24), export ready (§15), and the pre-renewal
notice M5.1 needs in order to renew anything tacitly.

**Deliverables**
- Migration: `notifications`, `notification_deliveries`,
  `notification_preferences`, `notification_consents`
- **`UNIQUE (notification_id, channel)`** — the retried job must not send a
  second SMS (ADR-027 requires idempotent handlers; here a duplicate costs
  money)
- `Notifier` port with `ScreenChannel`, `EmailChannel`, `SmsChannel`,
  `WhatsAppChannel` adapters; no provider name in the domain, and a Deptrac
  rule saying so
- `notify.dispatch` job handler; producers write the notification inside
  their own transaction and the queue delivers it
- `subscription.renewal_notice` job handler — §13.1's prior notice, and the
  first producer to actually write into the channel above (R11)
- Consent gate: no consent, no attempt — the delivery is written
  `SUPPRESSED` with its reason, never dropped silently
- `SECURITY` category not switchable off, including staff access to tenant
  data (non-negotiable #21)
- Rendered body retained for notifications with legal effect
- Endpoints: `/notifications`, `/notifications/{id}/read`,
  `/notifications/preferences`, `/notifications/consents`

**Tests (§37.4 Notifications):** refusals first — no consent, revoked
consent, muted channel, and `SECURITY` delivered anyway; then the replayed
job that must not double-send, one failed channel not taking the others down,
and a burst deduplicated to one notice.

**Exit criteria:** an SMS is never attempted without a stored, dated consent;
running the dispatch job twice on the same notification sends once; a
notification never appears in a conversation.

---

### M8 — Admin, audit & hardening

**Goal:** operable, auditable, compliant.

**Deliverables**
- `/admin/*` endpoints, strictly separated from tenant-visible data (non-negotiable #19)
- Financial dashboard API computed from `financial_events` / aggregates, not
  live recomputation over all transactions (§25.2)
- Audit log with `tenant_id`, `user_id`, `request_id` correlation (§30)
- Rate limiting, strict CORS, Argon2id where passwords are held locally (§31)
  — *rate limiting and CORS delivered*; a fixed window counted in PostgreSQL
  because D3 rules out a resident store, keyed on the caller's address and
  placed **before** the context chain so a flood of forged tokens is bounded
  too. Argon2id has nothing to hash: identity is a Supabase JWT and no
  password is held locally anywhere
- Retention + RGPD service separating deletion from legal accounting retention
  (non-negotiables #14, #15) — *delivered*. A person is **anonymised, never
  deleted**, because the schema says so: of twenty foreign keys into `users`,
  five RESTRICT and four CASCADE, so a DELETE would either be refused or would
  silently take notification history with it. `POST /api/v1/admin/erasures`
  clears the identity, the person's own message bodies and their name from the
  audit trail, and keeps — counted, and with the ground recorded per
  category — the accounting, fiscal, audit, legal-notice and traceability
  records. The invoice still names them afterwards, which is #15 working
  rather than failing: altering an issued invoice falsifies a legal document

**Exit criteria:** a `TENANT_ADMIN` provably cannot read cross-tenant financials;
a deletion request preserves records under legal retention.

---

## 3. Per-endpoint execution loop

Every endpoint in every milestone follows the CLAUDE.md sequence — no exceptions:

```text
1. Update OpenAPI            contract first, always
2. Define schemas            request + response + errors
3. Define product scope
4. Define tenant scope
5. Define authorization + entitlement
6. Implement application/domain behavior
7. Implement infrastructure  repository, adapter
8. Add tests                 including the failure cases
9. Validate OpenAPI + quality gates
```

Required status coverage per endpoint (§37.9): 200/201, 400, 401, 403, 404,
409, 422, 429, and a controlled 500.

---

## 4. Definition of done (§37.16)

A backend feature is done only when: PHP is typed; PHPStan clean; formatting
validated; Deptrac passes; unit tests added; integration tests added where
relevant; coverage respected; **OpenAPI updated**; migrations documented;
tenant isolation verified by test; documentation updated.

Quality gate order, per PR:

```text
install → PHP-CS-Fixer → PHPStan → Deptrac → PHPUnit → coverage
       → integration tests → OpenAPI validation → build
```

Never disable a gate to make CI pass (CLAUDE.md).

Branches stay short-lived: `feature/tenant-members`, `fix/webhook-idempotency`.
A PR carries code + tests + architecture impact + migration.

---

## 5. Risks

| # | Risk | Impact | Mitigation |
|---|---|---|---|
| ~~**R1**~~ | ~~**SiteGround may not offer PostgreSQL.**~~ **Closed: PostgreSQL is available.** The data architecture and the deployment target agree, so ADR-009 needs no revision and the MySQL contingency is moot. Everything built through M6 depends on it — JSONB documents, partial unique indexes, `LOCK TABLE` for gapless numbering — and none of that is now at risk. | Closed | — |
| ~~R2~~ | ~~No persistent workers on shared hosting~~ **Closed by D3's cron-polled queue.** The runner is a process that starts, claims what is due, and exits — the shape shared hosting actually permits. What replaces it as a live concern is narrower and is tracked as R10 | Closed | — |
| ~~R10~~ | ~~**A cron-driven queue has a floor on latency and no liveness signal.**~~ **Closed.** The latency floor is accepted and always was: jobs carry `run_after`, so a delayed run is late rather than wrong. The liveness half is what was missing — `job_runs` had recorded every pass since M7 and nothing ever read it back, so a cron that stopped firing still looked exactly like a quiet queue. `GET /api/v1/admin/queue` now answers it, and answers it in the shape the failures actually take: **a cron that stopped** ages `seconds_since_finished`, **a runner that died mid-pass** leaves a run open with its own age, and **a handler that is wedged** shows a growing backlog while the clock looks perfectly healthy — three different things to go and fix, so three fields rather than one flag. `never_ran` is said out loud because that state is all zeroes and reads like a calm idle queue. **No staleness verdict is invented**: how often cron fires is deployment configuration this process does not know, so the endpoint reports the clock and applies a threshold only when the caller supplies one. Support holds the permission alongside PLATFORM_ADMIN, unlike the financial dashboard — "why has my export not arrived?" is a support question whose honest answer is sometimes "the runner has not run since Tuesday", and the signal is timestamps and counts, never a customer's data | Closed | — |
| R3 | **French e-invoicing deadlines have started to arrive.** The first obligation is dated 1 September 2026 — *now* — and it drove M6 timing | **Critical**, regulatory | **Deferred to a scheduled piece of work, deliberately.** Connecting a certified Plateforme Agréée is not a risk-register fix — it is an integration with a contract, a certification and a test environment behind it, and it is planned as its own job rather than folded into a hardening sweep. Until it lands the position is unchanged and stated plainly: M6 has the four transmission states and a `StubEInvoiceProvider`, so the shape is right and no real platform is connected. Everything below stands as the record of what is known and what is not. Corroborated 2026-09-04 across several independent secondary sources (no primary source readable: every `gouv.fr` and `europa.eu` domain is refused by this environment's egress proxy, as for R7). What they agree on: from **1 September 2026** every company regardless of size must be able to *receive* e-invoices, and large and intermediate-sized companies must *issue* them; SMEs and micro-enterprises follow on **1 September 2027**. Exchange goes through a certified Plateforme Agréée in UBL 2.1, CII or Factur-X to EN 16931. Penalties are reported as not automatic for good-faith actors until **31 December 2026**, which is a runway, not an exemption. Against that, M6 has the four transmission states and a `StubEInvoiceProvider` — the shape is right and no real platform is connected. The §25.1 citations are still broken and resolve to nothing, so the dates above remain unconfirmed against an official source. Confirm them, then decide whether reception is in scope before the end of 2026 |
| ~~R4~~ | ~~Entitlement checks leaking into controllers as plan-name conditionals~~ **Closed.** The leak never happened and now cannot: no controller reaches the entitlement machinery, and `gate:entitlements` fails CI if one starts to. The mitigation named a single `EntitlementChecker`; what was actually built is one door per question, which is better — `RequestContext::has()` for "may this request do X", resolved once by the middleware from `capabilitiesFor()`; `QuotaPolicy::assertMayConsume()` called by the service that does the consuming; `TenantEntitlements` for reporting, which decides nothing. Deptrac could not have caught a controller reaching past those, because it reasons in layers and Controller may legitimately reach Domain. The gate catches the port named anywhere in a controller, either deciding call, and `has()` rewritten by hand over `$context->capabilities` — all four shapes proved to fail it before it was wired in. `gate:plans` already covered the loud shape, `if ($plan === 'PRO')` | Closed | — |
| ~~R5~~ | ~~Product context added late~~ **Closed — it did not happen, and it cannot happen backwards.** M1 shipped before any resource endpoint, as planned, and `RoutePolicy` is default-deny: a path matching nothing gets the full §10.6 chain, so forgetting to classify a new route fails safe rather than open. The residual gap was that nothing tested the policy the application is *configured with* — `RoutePolicyTest` builds its own fixture, so adding `/api/v1/invoices` to `publicPrefixes` would have shipped green. `RouteSurfaceTest` now classifies all 114 registered routes through the production policy and names every one of the 9 relaxed below FULL: 4 public (the liveness probe, two webhooks, one signed download — each authenticating the *request*, since there is no caller to authenticate) and 5 identity-only (product discovery, which cannot require a product without depending on its own result). Widening that surface now costs a deliberate edit | Closed | — |
| ~~R6~~ | ~~Supabase coupling spreading past the adapter~~ **Accepted as designed — the coupling never existed to spread.** No Supabase SDK is a dependency: `SupabaseJwtAuthProvider` verifies RS256 access tokens locally against a `SigningKeySource`, so the only trace of the provider in the codebase is that class's name and a few comments. Everything upstream depends on the `AuthProvider` port. The planned Deptrac rule would have constrained an SDK that was never introduced; the layer rules already stop `Auth/Infrastructure` leaking upward. Swapping provider remains an adapter change | Accepted | — |
| ~~R7~~ | ~~**VAT rates are wrong or stale.**~~ The EU-27 table in §25.3 is a paramétrage seed, not a fiscal authority; four standard rates moved between 2024 and 2025, and an invoice issued at a wrong rate is a legal document that cannot be quietly recomputed | Accepted | **Accepted as it stands, by decision, rather than closed by confirmation — and the difference matters.** The check that would close it was attempted twice and could not be completed here, so what follows is corroboration and is recorded as corroboration. If a rate is later found wrong, the validity windows below are what make that recoverable: a correction closes one window and opens another instead of restating an invoice already issued. Cross-checked against public sources twice — 2026-09-03 and again 2026-09-04 — with no discrepancy found either time: all 27 standard rates and all four windowed moves (EE 22→24 on 2025-07-01, RO 19→21 on 2025-08-01, SK 20→23 on 2025-01-01, FI 24→25.5 on 2024-09-01) agree across several independent sources, and the 2026 changes that have surfaced (BE, NL, LT, SK, AT) are all to *reduced* or category-specific rates, which this table does not hold. That is corroboration, not confirmation. The official confirmation this risk actually requires — Commission européenne and the administrations nationales — **could not be obtained**: every `europa.eu` and national tax domain is refused by the build environment's egress proxy, so no primary source was ever read. Closing R7 needs either those domains allowed here or the check run from an unrestricted machine. Rates stay loaded **with their validity windows**, so a correction closes one window and opens another instead of restating history |
| ~~R9~~ | ~~**Messaging becomes a cross-tenant leak.**~~ **Closed.** Every part of the mitigation is built and checked, and two of them are in the database rather than in code that has to remember. Participation is a foreign key: `messages_author_is_a_participant` points at `(conversation_id, author_user_id, author_kind)`, so a message whose author is not a participant *of that kind* cannot be written at all. Staff-in-tenant-threads is a second composite key — `conversation_participants` references `conversations (id, kind)` and a CHECK confines `STAFF` participants to `SUPPORT` conversations — so a staff member cannot be added to an internal thread even by a bug. The surfaces are separated end to end, with no controller branching on `isStaff`. And the isolation tests come before the behaviour ones (§37.4): another tenant, another *product* of the same tenant, a member of the right tenant who is not a participant, writing to a thread one is not in, adding an outsider, staff joining an internal thread, staff seeing internal threads in a listing, and a tenant member reaching the staff surface — eight refusals, all before the first test of what messaging does | Closed | — |
| R11 | **Tacit renewal without the legally required prior notice.** Renewing a committed subscription silently is the kind of clause consumer law constrains, and the constraint is a deadline — a notice sent late is a notice not sent | High, regulatory | **Open, and deliberately so — the mechanism now exists, the deadlines still do not.** `subscription.renewal_notice` sends the notice: the window is `notice_days` before `term_ends_at`, evaluated by PostgreSQL; the recipient is whoever can act on it (a tenant's administrators, a seat's holder); the notice carries legal effect so the words are kept as sent; and once-per-term is the notifications dedup index rather than a check two passes would both pass. A tenant with no administrator is **counted as unaddressed** rather than skipped, because an obligation reaching nobody must not read as an empty queue. It was also the platform's first notification producer — M7.1 built the whole channel and nothing wrote into it. What is still missing is the part no code can supply: the exact deadlines, especially toward consumers where they vary by member state, have never been confirmed against official sources (same egress wall as R3 and R7). So the gate stands unchanged — **a committed subscription must not auto-renew unattended in production** until those are confirmed. This makes the notice possible and observable; it does not make the renewal safe |
| ~~R12~~ | ~~**A retried job sends a second SMS.**~~ **Closed.** The mitigation was half-built and the missing half was load-bearing. `UNIQUE (notification_id, channel)` did guarantee one row per channel, but the claim left that row at `PENDING` and its row locks died with the statement, so an overlapping cron pass — the ordinary case on a polled queue — re-selected it and sent the message again. Reproduced against a real PostgreSQL: two sequential claims under the old SQL both returned the same deliveries. The claim now moves the row to `SENDING` in the statement that selects it, as a job moves to `RUNNING` (ADR-027), so the second pass cannot see it; under a lease, so a runner that dies does not strand the notice; bounded by attempts, so a message that kills whoever picks it up is failed rather than retried for ever. `testDispatchingTwiceSendsOnce` never caught this because it dispatched *in sequence*, where the first pass has already finished — the new test claims without finishing, which is the shape the bug actually had | Closed | — |
| ~~R8~~ | ~~**Reverse charge granted on an unverified VAT number.**~~ Invoicing intra-EU B2B at zero without proof of verification leaves the supplier liable for the tax | Accepted | **Accepted, with the refusal now made visible.** The risk as stated does not occur: `VatNumberValidator` fails closed, an unproved number is taxed at the standard regime rather than reclassified, `UNAVAILABLE` is its own status distinguishable from "nobody asked", the dated verification result is kept as evidence, and the §37.4 scenarios cover VIES being unreachable. What was missing was not the refusal but the telling. A customer typed a VAT number expecting to be zero-rated, was charged standard VAT, and found out from an invoice — a legal document that cannot then be quietly recomputed. `tax.vat_number_unverified` now goes to the person who entered it, while it is still fixable, and `UNAVAILABLE` is sent precisely because it is not their fault and asking again is the whole remedy. Deduplicated per identification, outcome and day, so a repeated save is one notice. Residual risk accepted: fail-closed can still cost a legitimate customer their zero-rating during a VIES outage, which is the right side to err on |

---

## 6. Suggested first three PRs

1. **`feature/backend-skeleton`** — M0 in full, including the deliberately
   failing Deptrac test that proves the gate works.
2. **`feature/request-context`** — M1 pipeline with `GET /me`, plus the tenant
   isolation and forged-`tenant_id` test suites.
3. **`feature/platform-identity`** — M2 migrations and tenant/user modules.

Nothing in M3+ should start until PR 2 is merged and its isolation tests are green.
