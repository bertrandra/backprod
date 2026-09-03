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
| D3 | **Job execution model** — cron-driven runner vs. persistent worker | §27 requires async jobs; shared hosting usually forbids daemons | Cron-polled queue table, worker-compatible interface |
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
M7  Storage & jobs                  assets, exports, async operations
      ↓
M8  Admin, audit & hardening        financial dashboard API, observability, RGPD
```

M0–M2 are strictly sequential — they build the security boundary. M4 onward
can overlap once the pipeline is frozen.

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

### M6.1 — Fiscalité / TVA

**Goal:** produce and retain the fiscal data a sale generates, so that VAT can
be justified, declared and exported — without turning the platform into an
accounting package. Specified in `architecture-v2.md` §25.3.

M6 invoices carry a VAT rate. That rate comes from `VatPolicy`, whose own
docblock says what it is not: a configured per-country number, applied
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

### M7 — Storage & jobs

**Goal:** large assets out of the database and long operations off the request path.

**Deliverables**
- `StorageProvider` adapter; private assets with signed URLs (§31)
- Endpoints: `/projects/{id}/assets*`, `/projects/{id}/exports*`
- Job queue per D3: `POST /jobs`, `GET /jobs/{id}`, `POST /jobs/{id}/cancel`
- Upload validation: type, size, content sniffing
- `GeoProvider` interface for spatial operations — **implemented over
  PostgreSQL, no PostGIS dependency**, so a spatial backend can be introduced
  later without the domain knowing

**Exit criteria:** a long export returns a job id, never blocking HTTP; no
base64 payload can reach JSONB.

---

### M8 — Admin, audit & hardening

**Goal:** operable, auditable, compliant.

**Deliverables**
- `/admin/*` endpoints, strictly separated from tenant-visible data (non-negotiable #19)
- Financial dashboard API computed from `financial_events` / aggregates, not
  live recomputation over all transactions (§25.2)
- Audit log with `tenant_id`, `user_id`, `request_id` correlation (§30)
- Rate limiting, strict CORS, Argon2id where passwords are held locally (§31)
- Retention + RGPD service separating deletion from legal accounting retention
  (non-negotiables #14, #15)

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
| R2 | No persistent workers on shared hosting | High — §27 async jobs | D3: cron-polled queue behind a worker-compatible interface. Still open, and now the nearest risk: M7's scheduler is what sweeps lapsed quotas, quotes and subscriptions |
| R3 | French e-invoicing deadlines (Sept 2026 / Sept 2027) drive M6 timing | High, regulatory | Confirm dates and PDP obligations against current official sources — the source citations in `architecture-v2.md` §25.1 are broken and resolve to nothing |
| R4 | Entitlement checks leaking into controllers as plan-name conditionals | Medium — erodes §13 | Single `EntitlementChecker`; CI grep + Deptrac rule |
| R5 | Product context added late | High — pipeline rework | M1 before any resource endpoint; D1 decided up front |
| R6 | Supabase coupling spreading past the adapter | Medium — violates provider independence | Deptrac rule: only `Auth/Infrastructure` may reference the Supabase SDK |
| R7 | **VAT rates are wrong or stale.** The EU-27 table in §25.3 is a paramétrage seed, not a fiscal authority; four standard rates moved between 2024 and 2025, and an invoice issued at a wrong rate is a legal document that cannot be quietly recomputed | High, regulatory | Cross-checked against public sources on 2026-09-03, which caught the four recent moves (EE, RO, SK, FI). Still to do before M6.1 ships: confirm against official sources (Commission européenne, administrations nationales), as R3 requires for the e-invoicing deadlines. Load each rate **with its validity window** so a correction closes one window and opens another instead of overwriting history |
| R8 | **Reverse charge granted on an unverified VAT number.** Invoicing intra-EU B2B at zero without proof of verification leaves the supplier liable for the tax | High, financial | `VatNumberValidator` fails closed; the verification result is stored with its date as audit evidence; the §37.4 fiscal scenarios cover VIES being unreachable |

---

## 6. Suggested first three PRs

1. **`feature/backend-skeleton`** — M0 in full, including the deliberately
   failing Deptrac test that proves the gate works.
2. **`feature/request-context`** — M1 pipeline with `GET /me`, plus the tenant
   isolation and forged-`tenant_id` test suites.
3. **`feature/platform-identity`** — M2 migrations and tenant/user modules.

Nothing in M3+ should start until PR 2 is merged and its isolation tests are green.
