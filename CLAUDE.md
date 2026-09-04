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
Notifier
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

Commitment terms — total term, commitment period, cancellation policy — are
in the section below.

## Subscription terms: duration, commitment, cancellation

Full specification in `docs/architecture-v2.md` §13.1.

Five durations, and they are not the same duration:

```text
billing_period       how often the customer pays
term_months          how long the subscription runs        NULL = open-ended
commitment_months    how long it cannot be cancelled       0 = no commitment
current_period_end   how long the service is owed for
notice_days          delay between the request and effect
```

**The payment period is not the commitment.** A 24-month subscription paid
monthly is one 24-month commitment billed 24 times, not 24 one-month
subscriptions. Confusing the two lets a customer walk out of a two-year
contract after a month.

A subscriber is a tenant or a named user:

```text
subscriber_kind = TENANT   entitles every member
subscriber_kind = USER     entitles that person only (a seat)
```

A subscription **always names a tenant and a product**, even when the
subscriber is a person — the tenant is the isolation context, the subscriber
is the contracting party. A `USER` subscriber must be a member of that tenant.

One active subscription per scope is a **partial unique index**, one per
subscriber kind — never an application check, which two simultaneous
subscriptions race straight through.

Terms are carried by the offer version and **snapshotted into the
subscription** when it is taken out, as values. Repricing or re-terming an
offer must not change one condition a customer already agreed to — the same
rule as the invoice snapshot and the fiscal snapshot.

Cancellation obeys the clock, twice over: the service stays owed until the
end of the paid period, and under commitment the request is refused or
deferred by the offer's `cancellation_policy` — never silently accepted and
then ignored. Every request is recorded with its effective date.

Early exit is a product decision (`FORBIDDEN` / `CHARGE_REMAINING` / `FREE`).
When it is charged, it is invoiced through the normal billing chain, never a
special path — and on the **cancellation's own transaction**, because a
release with the buy-out unbilled is revenue given away and a buy-out against
a subscription still running is a customer charged for an exit they did not
get.

Two rules about what that charge costs, both easy to get wrong:

- Count from the **end of the period already paid for**, never from now. A
  yearly plan left in month 11 of a 24-month commitment owes twelve months,
  not thirteen; counting from now bills a year the customer has settled.
- Price in **billing periods**, never in months. The commitment is counted in
  months because that is how it was sold, but the agreed price is per period.
  A yearly price times a number of months is a figure on no contract. A
  `CUSTOM` period has no period to count, so an offer may not sell a buy-out
  on one — the constraint is on `offer_versions`, not in a service.

Nothing outstanding raises **no document at all**. Numbering is gapless, so a
€0 invoice is a permanent, unremovable record of no transaction.

A seat is addressed to a **person**, so capability resolution asks who is
asking and excludes seats held by somebody else. Resolve capabilities without
the person and one colleague's seat entitles the whole tenant, which is the
opposite of what a seat is. Quotas stay tenant-scoped on purpose: a seat must
not silently raise the tenant's limit.

Renewal does not silently re-arm the commitment. Tacit renewal requires
notifying the customer beforehand — that notice is a notification, and
whether it was attempted must be answerable.

## Notifications

Full specification in `docs/architecture-v2.md` §27.1.

Four things, kept apart:

```text
event          what happened                    payment.failed
notification   the intent to inform someone     (recipient, event)
delivery       one attempt on one channel       with its own outcome
message        a human conversation (§12.3)     NOT a notification
```

A notification is never a message. A conversation has participants, an order
and a reply; a notification is one-way. Mixing them puts system noise in
support threads.

Channels are adapters behind a `Notifier` port — screen, email, SMS,
WhatsApp. No provider name in the domain, exactly like `PaymentProvider`,
`EInvoiceProvider`, `StorageProvider` and `VatNumberValidator`.

Sending goes through the M7 job queue. Never send inside the HTTP request: a
slow SMS provider would become a slow API.

Delivery is exactly-once by **unique index on `(notification_id, channel)`**,
not by a check. A retried job must not send a second SMS — that one is billed
and it annoys the recipient.

Store the payload, render at send time. **Except** where the notification has
legal effect (pre-renewal notice, formal demand, suspension notice): keep the
rendered body, for the reason an invoice keeps its snapshot.

SMS and WhatsApp are not email: no consent recorded, no send attempted. The
delivery is written `SUPPRESSED` with its reason — fail closed, and never
silent, because "did we tell them?" has to have an answer.

`SECURITY` notifications cannot be switched off. A security notice the
recipient can mute is one an attacker can mute.

Never put a secret, a token, payment data or an exception trace in a
notification payload. A channel leaves the platform.

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
with `since_seq`; unread notification is a notification (§27.1) delivered by
the M7 queue, not a message.

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
