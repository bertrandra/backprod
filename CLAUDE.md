# CLAUDE.md

## Mission

Build a multi-product SaaS platform following the Architecture V2 specification.

Core stack:
- React + TypeScript + Vite
- TypeScript Core independent from React
- PHP 8.3+ modular backend, no full application framework
- PostgreSQL + JSONB
- REST + OpenAPI 3.1
- Supabase Auth initially, behind an authentication abstraction
- Composer, PHPUnit, PHPStan
- Vitest, ESLint, Playwright
- FastRoute, PSR-7/15, PHP-DI, Doctrine and Symfony components where justified

## Critical architecture rule: Product is the root context

The backend is a shared **multi-product platform**. It is not the backend of a single product.

```text
Platform
  ↓
Product (Root)
  ↓
Tenant
  ↓
User / Role
  ↓
Subscription
  ↓
Entitlements
  ↓
Product Resources / Projects
```

A request is conceptually resolved as:

```text
Authentication
      ↓
Product resolution
      ↓
Tenant resolution
      ↓
Role / permissions
      ↓
Entitlements
      ↓
Resource authorization
```

`product_id` and `tenant_id` are different concepts. A tenant may use one or several products depending on the commercial model.

## Multi-product backend

The backend must be reusable by multiple products without duplicating the backend codebase.

Shared platform services include, where applicable:
- authentication
- users
- tenants
- roles/permissions
- offers/plans
- subscriptions
- entitlements
- billing
- payments
- invoices/e-invoicing
- storage
- jobs
- audit
- webhooks

Product-specific behavior belongs in explicit modules, configuration, capabilities and entitlements.

Never write product-specific branching such as:

```php
if ($product === 'product-a') { ... }
```

Prefer product modules, policies and configuration.

A new product should be addable without cloning the backend.

## API

API base path:

```text
/api/v1/
```

OpenAPI 3.1 is the source of truth.

The API catalog must include Product APIs such as:

```text
GET /api/v1/products
GET /api/v1/products/{productId}
GET /api/v1/products/{productId}/catalog
GET /api/v1/products/{productId}/features
GET /api/v1/products/{productId}/configuration
```

Conversations and staff support (§12.2, §12.3):

```text
GET    /api/v1/conversations
POST   /api/v1/conversations
GET    /api/v1/conversations/{id}
POST   /api/v1/conversations/{id}/messages
GET    /api/v1/conversations/{id}/messages?since_seq=
POST   /api/v1/conversations/{id}/read
POST   /api/v1/conversations/{id}/participants
DELETE /api/v1/conversations/{id}/participants/{userId}
POST   /api/v1/conversations/{id}/close
DELETE /api/v1/messages/{id}

GET    /api/v1/staff/conversations
GET    /api/v1/staff/conversations/{id}
POST   /api/v1/staff/conversations/{id}/messages
POST   /api/v1/staff/conversations/{id}/close
```

and Tax / VAT APIs (§25.3):

```text
GET  /api/v1/tax/profile
PUT  /api/v1/tax/profile
GET  /api/v1/tax/rates
POST /api/v1/tax/calculate
GET  /api/v1/tax/transactions
GET  /api/v1/tax/reports
GET  /api/v1/tax/reports/{period}
POST /api/v1/tax/reports/{period}/close
GET  /api/v1/tax/export
```

All API endpoints must define authentication, authorization, product scope, tenant scope, request/response schemas and errors.

## Domain boundaries

Frontend:
- React renders UI.
- Zustand manages local/client state.
- TanStack Query manages server state.
- React must not own core business rules.

TypeScript Core:
- independent of React, DOM, Zustand, TanStack Query and Three.js.
- contains domain models, geometry, calculations, rules and commands.

PHP:
- controllers are thin.
- application services orchestrate use cases.
- domain contains business invariants.
- infrastructure contains database and provider adapters.

## Provider independence

External providers must be behind interfaces/adapters:

```text
AuthProvider
PaymentProvider
EInvoiceProvider
VatNumberValidator
StorageProvider
CadastreProvider
```

The domain must not depend directly on Supabase, PSP SDKs, PDP implementations or other external providers.

## Multi-tenancy and security

Never trust a client-provided tenant ID.

The backend derives tenant context from authenticated identity and authorization.

Tenant isolation is a security boundary.

Never expose secrets, tokens, passwords, payment data, stack traces or SQL errors.

## Offers / subscriptions / entitlements

Keep these concepts separate:

```text
Offer = what is sold
Subscription = what the tenant subscribed to
Entitlement = what the tenant is allowed to use
```

Offers are versioned and historical offers must not be destructively rewritten.

`valid_from` / `valid_until` describe commercial validity of an offer and are distinct from subscription dates.

Authorization must use capabilities/entitlements, not scattered plan-name checks.

## Platform staff vs tenant membership

Full specification in `docs/architecture-v2.md` §12.2.

`TENANT_ADMIN` is the **customer's** administrator, not the platform
operator. Admin is a role held on a membership, never a property of a user:

```text
tenant membership     (tenant, user, product) → TENANT_ADMIN | USER
platform staff role   (user, platform_role)   → PLATFORM_ADMIN | SUPPORT_ADMIN
                                                 FINANCE_ADMIN | SALES_ADMIN
```

The two axes never convert into one another. A platform role grants no
tenant membership and must never fabricate one; a membership grants no
platform role.

`platform_staff` is a separate table from `tenant_members`. One table
holding both would turn a forgotten filter into privilege escalation.

Staff routes live under `/api/v1/staff/*`, take the tenant as an explicit
parameter, and are authorized by the platform role — never by the parameter.
Every staff access to tenant data writes an audit row: who, when, which
tenant, which resource, on what grounds.

Never put `if (isStaff)` inside a tenant controller. The two surfaces are
separate end to end — routes, permissions, controllers.

## Messaging

Full specification in `docs/architecture-v2.md` §12.3.

A conversation belongs to a `(tenant, product)` pair, like every other
resource. Two kinds, and the difference is a database invariant, not a
convention:

```text
INTERNAL   tenant members only  — staff must never appear
SUPPORT    tenant members + platform staff
```

A message's author must be a participant of the conversation — enforced by
foreign key, not by an application check.

Read state is a per-participant watermark (`last_read_seq`), monotone, never
decreasing. Not a row per message read.

No WebSockets and no held-open SSE: R2 means no persistent process. Polling
with `since_seq`; unread notification is an M7 job.

A deleted message is really deleted — the body is erased, the row remains as
a tombstone so the thread keeps its order. That is the opposite of an
invoice, which the law requires be kept (§26). Both rules are deliberate.

## Fiscalité / TVA

Full specification in `docs/architecture-v2.md` §25.3.

Keep these concepts separate, exactly as offers/subscriptions/entitlements
are kept separate:

```text
CustomerTaxProfile = who the customer is, fiscally
TaxRate            = a rate, for a country, over a validity window
TaxRule            = which regime applies, and why
VATTransaction     = the fiscal fact, immutable, declarable
```

Never recompute historical VAT with today's rates.

A `VATTransaction` records the rate and the rule that were applied, as
values — never as a foreign key to a rate row that can move. A rate change
must not shift a single euro of VAT already invoiced.

A rate is valid over a window, and the clock decides which one applies: the
rate in force at the date of the taxable event, never "the current rate".

Never infer a tax regime from a country code alone. The regime depends on
B2B/B2C status, on whether the VAT number was *verified*, on the nature of
the supply and on the place of taxation.

Reverse charge requires a verified VAT number, not a submitted one.
Verification goes through the `VatNumberValidator` adapter, its result is
stored with its date as audit evidence, and it fails closed — if VIES is
unreachable, the sale is not silently reclassified as reverse-charged.

A VAT number prefix is not an ISO country code: Greece is `GR` / `EL`, and
Northern Ireland uses `XI`.

A closed reporting period is immutable. Corrections go into a later period,
never back into a closed one — the same rule as gapless numbering and credit
notes.

The backend produces and retains fiscal data, and exports it. It is not an
accounting package: no chart of accounts, no general ledger, no filing with
the tax authority.

## Quality gates

Before considering work complete:

```text
Typecheck
→ ESLint
→ Vitest
→ PHPStan
→ PHPUnit
→ OpenAPI validation
→ Playwright when applicable
```

Never disable a quality gate merely to make CI pass.

## Claude Code workflow

Before coding:
1. Read CLAUDE.md.
2. Read the relevant architecture/specification sections.
3. Search the repository for existing implementations.
4. Identify the correct layer and module.
5. Make the smallest coherent change.

When adding an API:
1. Update OpenAPI.
2. Define schemas.
3. Define product/tenant scope.
4. Define authorization and entitlement.
5. Implement application/domain behavior.
6. Implement infrastructure if needed.
7. Add tests.
8. Validate OpenAPI and quality gates.

Do not introduce a framework, provider or architectural dependency without documenting the decision.
