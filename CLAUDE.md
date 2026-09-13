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

OpenAPI 3.1 is the source of truth. The frontend's types and API client are
generated from it — see *Frontend API data flow* below.

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

## Frontend API data flow

**Build in this order. Do not skip a step, and do not start from React.**

```text
PHP API
   ↓
OpenAPI 3.1
   ↓
TypeScript types + API client (generated)
   ↓
TanStack Query
   ↓
React
```

OpenAPI is the source contract. The TypeScript types and API client are
generated from it. TanStack Query consumes that client exclusively to manage
server state. React consumes TanStack Query.

Never write, anywhere in the frontend:

```text
fetch() in a component            ❌
fetch() in a queryFn              ❌
axios                             ❌
a hand-written URL or HTTP verb   ❌
a hand-copied DTO or response type ❌
```

Each of those is a second contract, maintained by hand, that can drift from
the first — and will. The backend renames a field, the OpenAPI gate stays green
because the contract and the router still agree, generation stays green because
nobody re-ran it, and a customer's browser finds the difference. A single
contract is not defended by the discipline of whoever writes the `fetch()`; it
is defended by there being nowhere to write one.

Generated code is not edited by hand. A missing field is a field missing **from
OpenAPI**: fix the contract, regenerate. Editing the output produces a file the
next generation overwrites, and a fix that vanishes silently is worse than no
fix.

This applies to server state only. Client state — Zustand, form state, whether
a panel is open — never comes from the server and never goes through this
chain.

Full rule, with the reasoning: `docs/architecture-v2.md` §8.1. Non-negotiable
#25.

## UI structure

Full specification: `docs/ui-spec.md`; build order and exit criteria:
`docs/ui-roadmap.md`. The parts that constrain every change:

**One shell, two authorities, and every entry declares which.** There is a
single navigation: a person sees the screens their permissions actually allow,
tenant and platform together. Platform screens keep the `/console/*` prefix, so
an address still says which authority it answers to.

This used to read *"two shells, never merged"* and cited non-negotiable #22 for
it. That was a misreading, and it cost the operator of this platform real time:
#22 says **a platform role never grants a tenant membership and never the
reverse** — a rule about authorisation, which the server enforces with two
contexts, two permission catalogues and a gate on every route. It says nothing
about interfaces. Splitting the UI as well only hid the platform's own screens
from the person running it (ADR-046).

The rule now holds more explicitly than it did. Every `NavEntry` carries
`scope: 'tenant' | 'platform'`, and the filter consults *that* authority and no
other — so a tenant permission cannot light a platform entry, because the entry
never looks at the tenant's set. `gate:permissions` fails the build when an
entry's declared scope disagrees with the catalogue its permission lives in.

**Six named screen regions.** Context bar, primary nav, view (header + body),
inspector, status strip, overlay. Every screen is those regions with different
contents. No region disappears on mobile — it changes presentation, because a
region that vanished would be a capability only desktop users have.

**Product-agnostic vs product-scoped.** Billing, subscription, tax, members,
branding, notifications, messaging and the console work for any product. Only
the workspace is product-scoped. Adding a second product adds a workspace
module and touches nothing else.

Never write `if (product === '…')` or branch on a plan or tier name in a
component. That is the frontend copy of what `gate:products` and `gate:plans`
forbid in PHP. What varies per product comes from
`GET /products/{productId}/configuration`; what varies per customer comes from
entitlements.

**Gating is data.** The shell reads `/me/permissions` and `/me/entitlements`
and hides or disables from those. Hiding is courtesy only — the API refuses
regardless, and the frontend is never the authority.

**Every operation is reachable, or says why not.** `docs/ui-api-coverage.json`
maps all 136 operations to a screen area, to shell bootstrap, or to a written
reason for having no screen. `composer run gate:ui` checks it both ways: an
unmapped operation fails, and so does an area claiming an operation the
contract no longer declares. Add an endpoint and that gate fails until a screen
area claims it.

## Domain boundaries

Frontend:
- React renders UI.
- Zustand manages local/client state.
- TanStack Query manages server state, and reaches the API only through the
  generated client.
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
opposite of what a seat is.

**Every gate must ask about the same person.** The quota check takes the
caller too. Asking the tenant-wide question there while the capability chain
asked a personal one is the worst of both: the capability check lets a seat
holder through and the quota check then tells them they need the entitlement
they are holding. Where a seat and the tenant both grant a feature, the most
generous wins — the same rule that settles a negotiated override. The seat
does not raise the *tenant-wide* answer, because that question names nobody,
and usage stays measured tenant-wide because that is what the feature counts.

A seat is taken out and given up by its holder, and both name the scope with
a flag, never an id: the only two subscribers are the tenant and the caller,
and both come from the context, so there is no id to supply and nothing to
check one against.

**Renewal may not roll past a cancellation already due**, or the subscription
is quietly extended beyond the date the customer was given. A deferral
further out does not block anything — it is only due once its date arrives.

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

Delivery rows are written **up front**, one per candidate channel, before
anything is sent — that is what makes a suppression recordable. Deciding at
send time and skipping what fails the gate leaves nothing behind.

The consent and preference decision lives in `DeliveryGate`, never inside a
channel adapter. An adapter that could also refuse would put one decision in
two places, and the SMS one is where a mistake costs money.

An absent preference means enabled — except marketing, which is off until
chosen. Somebody who never opened the settings should still hear that their
payment failed.

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

## Frontend commands

The frontend lives in `frontend/`. From that directory:

```text
npm run generate      openapi.json -> src/api/generated/schema.d.ts
npm run gate:client   regenerate and compare; fails if the client is stale
npm run lint          ESLint, including the ban on fetch outside the client
npm run typecheck     tsc --noEmit
npm run test          Vitest
npm run gates         lint + typecheck + test + gate:client
npm run build         gates, then Vite build
npm run e2e           Playwright, desktop and mobile
```

`npm run e2e` serves `dist/`, so **build first**: Playwright starts
`vite preview`, and a stale `dist/` makes every new screen fail as though it had
never been written.

**The contract's paths already carry `/api/v1`**, so the client's base URL is
empty. A base of `/api/v1` composed `/api/v1/api/v1/…` and went unnoticed from U0
to U5: the screen tests replace the client, the Playwright stubs match a doubled
path too, and the test that looked at it asserted the value rather than what it
composed to. Assert what a value *does*.

`src/api/client.ts` is the only module allowed to reach the API, and ESLint
enforces it. `src/api/generated/` is generated: never edit it, regenerate it
(ADR-036).

**One door, and no exceptions.** U11 briefly needed a second (`src/api/auth.ts`)
because Supabase issued the token and its endpoint could not be in `openapi.json`.
ADR-038 moved issuance into PHP, so signing in is three contract operations and
that file is gone. If you need an HTTP call the generated client cannot make, the
answer is to put it in the contract.

Signing in: `POST /api/v1/auth/token` returns an access token for the page to hold
in memory, and sets the refresh token as an `HttpOnly` cookie the page cannot read.
`state/session.ts` holds the access token and **nothing durable** — no browser
store has a credential in it. `SignInGate` renders instead of the router when there
is no session, so no screen ever mounts unauthenticated, and a reload resumes by
asking `/auth/refresh` rather than by reading storage.

A refresh rotates the token and revokes the one it was given. Presenting a spent
one revokes every session for that account: replay and theft are indistinguishable
from the server, and their costs are not (ADR-038).

Every request needs a product: pass `ambientParams(sessionSnapshot)` as the
call's init. The contract declares `X-Product` required, so a call that omits it
does not compile — which is the point (ADR-037). The bearer token is different:
it is a security scheme, so middleware attaches it and no call site mentions it.

Gate what a screen offers with `can(permission)` / `isEntitled(capability)` over
the strings `/me` returned. Never on a role or plan name — that is §13's rule in
the frontend (ADR-037).

**Permission codes are not free-form.** Read them from the controller that
requires them, never from the endpoint's name: six were wrong in the first
navigation table and every test passed anyway. `composer run gate:permissions`
compares what the frontend gates on with what the migrations create.

**Business calculation belongs to the Core, in the frontend too** (§4, §5).
A component transports and renders; it never derives a quantity. Area, perimeter
and every spatial relation come from `/geometry/*` — the shoelace formula is four
lines of JavaScript, which is exactly why it must not be written: added once for
a tooltip, it disagrees with PostgreSQL in the eleventh digit and leaves two
numbers for one parcel with nothing to say which is right. Shaping a payload to
the contract's format (closing a GeoJSON ring, mapping a tap to a coordinate) is
presentation, not calculation.

**Gate on a capability when the plan decides, on a permission when the role
does.** `isEntitled('gis.access')`, not `can(...)`: geometry touches no tenant
data, so there is no question of what a role may do with it. The two refusals
read differently — one is answered by an administrator, the other by an upgrade.
`composer run gate:permissions` now checks both vocabularies against the
platform.

**Money is integer minor units all the way to the screen.** Convert only in
`ui/Money.tsx`, which asks `Intl` for the currency's exponent — `/ 100` is right
for EUR, under-reports JPY a hundredfold and over-reports TND tenfold. VAT rates
arrive as basis points and are divided by 100 for display, so 550 reads as 5.5%.
Never add two amounts in the frontend: every total on screen is the server's
(§4, §25).

**A published offer version is frozen** (ADR-033), and the UI proves it by
*absence*: no input, no disabled control, no edit that explains itself. A quote
pins the version that priced it, and that guarantee is worth nothing if the
version can move. Changing published terms means adding a version and publishing
it.

**Money mutations are never optimistic**, and `composer run gate:money` proves
it rather than trusting it: `onMutate` must not appear in any module that carries
money. Issuing an invoice allocates a gapless legal number, and a screen that
assumed success would have invented a document — the number either collides with
a real one or leaves a hole in a sequence that must not have one. Optimism
belongs where nothing binding is created (`queries/conversations.ts`).

**A document's number is never invented.** `Invoice.number` is null until issued
and a draft says so. No placeholder, no "pending number", nothing written into
the cache ahead of the server.

**Periodicity is not commitment** (non-negotiable #23). How often somebody is
billed and how long they agreed to stay are separate facts, shown separately. A
cancellation is a **decision** — rule id, effect, effective date, chargeable
months, reasons — never `cancelled: true`.

**Derived fields are the server's answer, not a local calculation.** `open` on a
quote, `status` on a checkout session, `unread` on a notification: all derived on
read. A screen that recomputed one from a date or a count would disagree with the
server a second later, and then two answers would exist for one document.

**Product configuration is read, never assumed.** A project's `schema_version`
must be one the product declares, so it comes from
`GET /products/{productId}/configuration`. Hard-coding it is UR5: one product's
fact baked into a shared client, which is `gate:products` in PHP moved somewhere
the backend gates cannot see it.

**What a mutation does to the cache** (established in U3,
`src/queries/notifications.ts`):

- if the response *is* the new state, write it into the cache;
- if the server derives the state, **invalidate** and let the server answer;
- **never adjust a count locally.** An unread badge decremented in JavaScript is
  right until the same person reads something in another tab, and then it is
  wrong with nothing to correct it.

Optimistic updates are for changes that create nothing anyone can act on — a
chat message, not an invoice, a legal number or a gapless sequence (U6 forbids
them outright). Where one is used, the rollback must be *provable*: hold the
reconciling refetch open in the test, or the refetch clears the placeholder and
the test passes with no rollback at all.

## Quality gates

Before considering work complete:

```text
Typecheck
→ ESLint
→ Vitest
→ PHPStan
→ PHPUnit
→ OpenAPI validation
→ Every operation is reachable in the UI (composer run gate:ui)
→ The frontend gates on permissions that exist (composer run gate:permissions)
→ No money mutation is optimistic (composer run gate:money)
→ Generated client matches OpenAPI (npm run gate:client)
→ Playwright when applicable
```

Never disable a quality gate merely to make CI pass.

If the contract changed and the generated client did not follow, that gap must
fail CI. A drift discovered at runtime is a drift discovered by a customer.

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
