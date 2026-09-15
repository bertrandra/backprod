# backprod

Shared multi-product SaaS backend platform.

This is **not** the backend of a single product. One platform serves several
products; `product_id` is a first-class request context alongside `tenant_id`.
See [`CLAUDE.md`](CLAUDE.md) for the rules that govern changes here, and
[`docs/architecture-v2.md`](docs/architecture-v2.md) for the architecture
decisions behind them. For who may do what —
the two identities, the six roles and the 46 permissions between them —
see [`docs/identities-and-permissions.md`](docs/identities-and-permissions.md).

## Stack

PHP 8.3+, no application framework — PSR-7/15 over FastRoute and PHP-DI.
PostgreSQL with JSONB. REST described by OpenAPI 3.1.

## Requirements

- PHP 8.3+ with `json`, `mbstring`, `pdo`
- Composer

## Setup

```bash
composer install
cp .env.example .env
```

`.env` is never committed (Architecture V2 §31).

## Running locally

```bash
php -S 127.0.0.1:8080 -t public
curl -i http://127.0.0.1:8080/api/v1/health
```

## Quality gates

The full chain, in the same order CI runs it:

```bash
composer run gates
```

Individually:

| Command | Checks |
|---|---|
| `composer run cs` | Coding standards (PSR-12 + strict types) |
| `composer run stan` | PHPStan, level max |
| `composer run deptrac` | Module and layer dependency rules (§37.6) |
| `composer run gate:proof` | That the architecture gate really rejects a violation |
| `composer run gate:products` | That no code branches on product identity (§12.1) |
| `composer run gate:plans` | That no code branches on a plan or tier name (§13) |
| `composer run gate:entitlements` | That no controller decides an entitlement question itself (§13, R4) |
| `composer run gate:openapi` | That `openapi.json` and the router describe the same API — all 116 operations, both directions |
| `composer run test` | PHPUnit |

`composer run cs:fix` applies formatting fixes.

Never disable a gate to make CI pass (`CLAUDE.md`).

### Why `gate:proof` exists

A gate nobody has watched fail is an assumption. `tools/prove-architecture-gate.php`
runs Deptrac against a fixture that deliberately breaks the `Domain → SQL`
rule and fails the build unless Deptrac catches it. The fixture lives in
`tools/architecture-gate-fixture/`, outside `src/` and outside the autoloader,
so it can never be reached by application code.

## Layout

```text
config/     container and route definitions
migrations/ hand-written SQL, one class per change
public/     front controller
src/
  Auth/        token verification behind a provider port
  Billing/     billing profiles, invoices, credit notes, VAT and the ledger
  EInvoice/    the approved-platform port and the transmission history
  Payment/     the PSP port, its webhook, payments and refunds
  Sales/       quotes, orders, and what paying for one causes
  Commerce/    plans, features, offers, subscriptions, entitlement rows
  Entitlement/ the narrow port the §10.6 chain reads, plus quotas
  Health/      liveness endpoint
  Identity/    the caller's own account
  Product/     the product registry and catalogue
  Project/     projects and their versions
  Tenant/      tenants, members and roles
  User/        platform users and provisioning
  Shared/      Context (the §10.6 chain), Http, Database, Exceptions, Logging
tests/
  Unit/
  Integration/
  Support/
tools/      the gate proofs and their fixture
docs/       architecture, roadmap and ADRs
```

Modules follow `Module/{Controller,Service,Domain,Repository,Infrastructure}`
as they gain those layers; small modules stay lighter (§41.1).

## Status

**M6 complete, M6.2 landed, M7 under way** in
[`docs/backend-roadmap.md`](docs/backend-roadmap.md) —
billing, payments and e-invoicing — on top of M5's commerce, M4's projects,
M3's product registry, M2's platform identity and M1's context chain.

Non-negotiable #20's chain now runs end to end:

```text
Quote → Order → Subscription → Invoice → Payment → e-invoice
```

| Route | Permission |
|---|---|
| `GET /api/v1/health` | public |
| `GET /api/v1/products` | authenticated only |
| `GET /api/v1/products/{id}` | authenticated only |
| `GET /api/v1/products/{id}/catalog` | authenticated only |
| `GET /api/v1/products/{id}/features` | authenticated only |
| `GET /api/v1/products/{id}/configuration` | authenticated only |
| `GET /api/v1/me` | — |
| `PATCH /api/v1/me` | `account.manage` |
| `GET /api/v1/me/permissions` | — |
| `GET /api/v1/tenants/current` | `tenant.read` |
| `PATCH /api/v1/tenants/current` | `tenant.manage` |
| `GET /api/v1/tenants/current/members` | `members.read` |
| `POST /api/v1/tenants/current/members` | `members.manage` |
| `PATCH /api/v1/tenants/current/members/{userId}` | `members.manage` |
| `DELETE /api/v1/tenants/current/members/{userId}` | `members.manage` |
| `GET /api/v1/projects` | `projects.read` |
| `POST /api/v1/projects` | `projects.write` |
| `GET /api/v1/projects/{id}` | `projects.read` |
| `PATCH /api/v1/projects/{id}` | `projects.write` |
| `DELETE /api/v1/projects/{id}` | `projects.write` |
| `GET /api/v1/projects/{id}/versions` | `projects.read` |
| `POST /api/v1/projects/{id}/versions` | `projects.write` |
| `GET /api/v1/projects/{id}/versions/{versionId}` | `projects.read` |
| `POST /api/v1/projects/{id}/duplicate` | `projects.write` |
| `POST /api/v1/projects/{id}/restore` | `projects.write` |
| `GET /api/v1/plans` | `catalog.read` |
| `GET /api/v1/features` | `catalog.read` |
| `GET /api/v1/offers` | `catalog.read` |
| `GET /api/v1/offers/{id}` | `catalog.read` |
| `GET /api/v1/subscription` | `subscription.read` |
| `POST /api/v1/subscription` | `subscription.manage` |
| `POST /api/v1/subscription/change-offer` | `subscription.manage` |
| `POST /api/v1/subscription/cancel` | `subscription.manage` |
| `POST /api/v1/subscription/resume` | `subscription.manage` |
| `GET /api/v1/entitlements` | `entitlements.read` |
| `GET /api/v1/me/entitlements` | — |
| `GET /api/v1/tenants/current/usage` | `entitlements.read` |
| `GET /api/v1/billing/profile` | `billing.read` |
| `PUT /api/v1/billing/profile` | `billing.manage` |
| `GET /api/v1/billing/invoices` | `billing.read` |
| `POST /api/v1/billing/invoices` | `billing.manage` |
| `GET /api/v1/billing/invoices/{id}` | `billing.read` |
| `POST /api/v1/billing/invoices/{id}/pay` | `billing.manage` |
| `POST /api/v1/billing/invoices/{id}/cancel` | `billing.manage` |
| `POST /api/v1/billing/invoices/{id}/credit` | `billing.manage` |
| `GET /api/v1/billing/credit-notes` | `billing.read` |
| `GET /api/v1/billing/payments` | `payments.read` |
| `GET /api/v1/billing/payments/{id}` | `payments.read` |
| `POST /api/v1/billing/invoices/{id}/payments` | `payments.manage` |
| `POST /api/v1/billing/payments/{id}/refund` | `payments.manage` |
| `GET /api/v1/sales/quotes` | `sales.read` |
| `POST /api/v1/sales/quotes` | `sales.manage` |
| `GET /api/v1/sales/quotes/{id}` | `sales.read` |
| `POST /api/v1/sales/quotes/{id}/accept` | `sales.manage` |
| `POST /api/v1/sales/quotes/{id}/reject` | `sales.manage` |
| `GET /api/v1/sales/orders` | `sales.read` |
| `POST /api/v1/sales/orders` | `sales.manage` |
| `GET /api/v1/sales/orders/{id}` | `sales.read` |
| `POST /api/v1/sales/orders/{id}/fulfil` | `sales.manage` |
| `POST /api/v1/sales/orders/{id}/cancel` | `sales.manage` |
| `POST /api/v1/billing/invoices/{id}/transmit` | `billing.manage` |
| `GET /api/v1/billing/invoices/{id}/transmissions` | `billing.read` |
| `POST /api/v1/webhooks/payments/{provider}` | **none — signature** |
| `POST /api/v1/webhooks/einvoice/{provider}` | **none — signature** |

Authorisation asks about **permissions**, never role names (§13). Roles map
to permissions in the database, so moving a permission between roles changes
no code.

**Four refusals, four different fixes** — conflating them sends a customer to
the wrong screen:

| Code | Means | Fixed by |
|---|---|---|
| `NO_TENANT_ACCESS` | not a member | an invitation |
| `PERMISSION_DENIED` | wrong role | a role change |
| `ENTITLEMENT_REQUIRED` | not bought | a subscription change |
| `QUOTA_EXCEEDED` | bought, and used up | an upgrade, or deleting something |

There is deliberately no `/tenants/{id}` — the tenant is whichever one the
context chain resolved.

Product routes are **authenticated but product-agnostic**: a client cannot
send `X-Product` before it knows which products it may use, and it learns
that from `/products`. They authorise per product from membership instead. A
product you have no membership in is reported exactly as one that does not
exist.

Everything else requires the full chain by omission — a new route is
protected unless someone deliberately relaxes it.

Every request to a non-public path resolves, in this order (§10.6):

```text
authentication → product → tenant → roles → entitlements
```

The result is a `RequestContext`, and it is the **only** sanctioned source of
tenant and product identity. A handler that reads a tenant id from a header,
query or body is reading a claim, not a decision — see
[ADR-015](docs/adr/ADR-015-tenant-resolution.md).

Users, products, tenants and membership are stored in PostgreSQL. A person is
provisioned locally on their first authenticated request
([ADR-017](docs/adr/ADR-017-user-provisioning.md)); the internal user id, not
the identity provider's subject, is what every foreign key references.

## Commerce

§12's chain is **Offer → Subscription → Entitlements → Tenant**, and the three
words mean different things: an offer is what is *sold*, a subscription is
what is *subscribed to*, and entitlements are what a tenant may *actually
use*. Part 1 builds the first.

An offer's identity is separate from its terms. `offers` holds what does not
change; `offer_versions` holds price, billing period, commercial window and
grants. A subscription will point at a **version**, so raising a price never
retroactively changes what an existing customer bought
([ADR-019](docs/adr/ADR-019-offer-model.md)).

`valid_from` / `valid_until` are the window in which a version may be **sold**.
§12 keeps this deliberately distinct from a tenant's subscription period, and
they are never the same column.

Whether an offer may be sold is decided **against the clock**, not by status
alone: an offer whose window has closed stops selling even if no job has run
to mark it expired. The window is half-open — open at `valid_from`, closed at
`valid_until` — so an offer withdrawn on the 1st and its replacement starting
that instant do not both apply for one tick.

An offer with nothing on sale is reported exactly as one that does not exist.
What a company is about to launch, or has stopped selling, is commercial
information. The tenant who bought a withdrawn offer still reads its terms
through their subscription, which is where it legitimately stays visible.

**Prices are integer minor units** with an ISO 4217 currency —
`"price": {"minor_units": 2900, "currency": "EUR"}` — never a float and never a
formatted string. `minor_units` rather than `cents`, because not every currency
has cents. The field is bare because the key it sits under already says which
amount it is; where a money value has no such key it carries the whole name
instead, as `amount_minor_units` beside its own `currency`.

A grant's `limit` is null both for a capability you simply hold and for a
quota with no ceiling, so an explicit `unlimited` flag says which. A sentinel
like `-1` would compare as the *smallest* allowance the first time a check was
forgotten.

Plans are rows with a `rank`, not names in code. Asking whether a change is an
upgrade is then a comparison of two numbers, and `composer run gate:plans`
rejects the `if ($plan === 'PRO')` shape §13 forbids — including the ones that
do not mention `$plan` at all.

Nothing writes to the catalogue yet: plans, features and offers are seeded by
migration or by an administrator. Authoring endpoints are M8.

## Subscriptions and entitlements

A subscription points at an **offer version**, so what a tenant agreed to
cannot change under them when the offer is repriced. Withdrawing an offer from
sale does not cancel the people on it: their terms stay readable through their
subscription, which is the one place a withdrawn offer remains visible.

Entitlements are **stored rows with a validity window**, written when a
subscription changes ([ADR-020](docs/adr/ADR-020-entitlement-resolution.md)).
They are read by the §10.6 chain on every authenticated request, which is why
they are not a four-table join, and why they are not a cache either.

The window is the point. **In this platform a lapse is a fact about the clock,
never about whether something ran** — the same rule that decides whether an
offer may be sold. A subscription's `status` may sit at `ACTIVE` long after
its period ended; the tenant is entitled to nothing regardless, and there is a
test that leaves the column deliberately stale to prove it.

A `SUBSCRIPTION` grant is replaced on every plan change; an `OVERRIDE` — a
negotiated exception — survives one. Where a feature is held twice the **most
generous wins**, because the alternative is a support team's deliberate
exception silently doing nothing.

**Quotas are enforced against measured usage**, never a counter the platform
maintains: a counter drifts the first time a delete half-fails, and a quota
enforced from a drifted counter refuses a customer who is within their
allowance. A quota nothing counts yet is reported as `"metered": false` with
no invented number, rather than a confident zero that looks like enforcement.

**Absence grants nothing.** An offer that does not mention `max_projects`
grants no projects, not unlimited — otherwise forgetting a line in a price
list would be the way to give something away.

Renewal is a service method with no endpoint: renewing is what time does, and
the job that notices is M7. It is written and tested now rather than first
exercised in production.

## Billing

An invoice **keeps its own snapshot** ([ADR-021](docs/adr/ADR-021-invoice-snapshots-and-numbering.md)).
Both parties, every line's description, unit price, discount and VAT rate, and
the tax totalled per rate are copied onto the document when it is issued. The
offer version is recorded for lineage; **no amount is ever read back through
it**. Repricing an offer, renaming a company or moving office changes nothing
about an invoice already filed by somebody's accountant — which is §25's
requirement, not a denormalisation for speed.

The **number is allocated from `max + 1` under a table lock**, inside the
transaction that writes the document. Deliberately not a PostgreSQL sequence:
sequences are fast because they do not roll back, so a failed transaction
burns a number. French numbering must be sequential and without gaps, and a
missing number is a question from an auditor rather than a cosmetic problem.

Cancelling **voids, never deletes**. The number stays allocated and the row
stays readable, because an auditor asking about `2026-000042` must get an
answer and "cancelled" is one. For the same reason a tenant with invoices
cannot be deleted: §25.1 separates legal accounting retention from RGPD
erasure, and here that separation is a foreign key, not a convention.

**Money is integers everywhere** — minor units plus an ISO currency, in the
domain, in the database and in JSON. VAT rates are **basis points**, because
5.5% is `550` exactly and `0.055` approximately. Rounding is half-up on the
magnitude, in one place, so a credit line exactly undoes the line it corrects.

`VatPolicy` applies a configured rate per country and **is not a tax engine**.
§25.1's real treatment depends on the operation, the customer's VAT status and
their country; none of that is decided here. What makes that honest is that
the rate used is stored on the line and in `tax_records`, so the gap is visible
on the document rather than buried in code that looked like it knew.

Two pieces of configuration, both per product so a second product sold by a
second company needs no code (§12.1):

- `billing_supplier` — who is issuing, with the mandatory mentions
- `vat_rates` — `{"FR": 2000, "default": 0}`, in basis points

A product with no supplier identity, or a tenant with no billing profile,
**cannot be invoiced**. Both refusals happen before a number is allocated:
failing early costs a 409, failing late costs a permanent row in a legal
sequence.

`invoices.status` accepts all nine states of §25.1 so the e-invoicing adapter
needs no migration, but only the transitions this milestone can perform are
reachable, and an unknown status grants nothing. The refusals are the point:
re-issuing an issued invoice would allocate a second number for one document,
paying a cancelled one would put money against a debt that no longer exists,
and cancelling a paid one would erase a payment that happened.

Every issue and every transition appends to `financial_events`, which is
append-only — an audit trail added after payments exist is one with a hole in
exactly the period anyone would want to inspect.

Nothing decides *when* to bill. `POST /api/v1/billing/invoices` bills the
current subscription period on demand; M7's scheduler is what will call it.

## Payments

**The webhook is the source of truth** ([§24](docs/architecture-v2.md),
[ADR-022](docs/adr/ADR-022-payments-and-webhook-idempotency.md)). Nothing in
the API can mark a payment collected; a platform that could would be trusting
the browser that redirected back.

**Exactly-once is a unique index, not a check.** The delivery is recorded
against `UNIQUE (provider, provider_event_id)` in the same transaction that
acts on it, so a replay hits the constraint before it can do anything — and
because PostgreSQL aborts a transaction on a constraint violation, every
statement after it is refused too. The obvious alternative, looking for the
event id first, is a race: two copies arriving together both find nothing and
both proceed, and money is counted twice.

A replay is answered **202**, never an error. A provider retries anything that
is not a 2xx.

**The signature is verified over the raw bytes, before parsing.** A signature
is over bytes; parsing first and verifying the result verifies something the
provider never signed. This is the only unauthenticated write endpoint in the
platform, so `RoutePolicy` carries it as a *public prefix* — the most
dangerous kind of route, and the rule is that anything mounted under one must
authenticate the request itself.

**Payment status is a one-way machine.** Providers deliver out of order, and a
retried `payment.failed` landing after the `payment.succeeded` that superseded
it must not reverse a collected payment. A move that is not permitted is
recorded as `IGNORED_STALE` rather than discarded — an operator needs to know
whether a delivery never arrived, arrived for something unknown, or arrived
too late.

**No card data, structurally.** There is no column that could hold a PAN, an
expiry or a CVV, and `payments.method` is constrained to a small set of labels
so an adapter cannot quietly put an instrument there. The one credential that
exists — the client secret for completing the payment — is returned and never
stored.

**The invoice settles inside the payment's transaction.** A collected payment
and an invoice still saying it is owed must never be observable together. That
is done through a participating repository method rather than a nested
transaction, so correctness does not depend on how the driver handles nesting.

A **chargeback** sets the payment to `CHARGEBACK` and records it, and
deliberately does not move the invoice: a bank dispute is not a decision this
platform makes on the customer's behalf, and the instrument for correcting a
paid invoice is a credit note somebody issues.

Refunds and chargebacks share one table — the money moves identically, only
who decided differs — with `disputed` surfaced so a client does not tell a
customer the wrong story.

A **credit note** is how a finalised invoice is corrected: never by editing it,
which would leave a hole in a legal sequence. It has its own gapless series
(`AV2026-000001`) through the same `max + 1` mechanism as invoices. Crediting
is full-invoice only for now.

Providers are a **registry**, not a dependency (non-negotiable #17): a platform
migrating between PSPs runs both while payments started with the old one are
still settling. A deployment with no configured signing secret has no provider
and cannot take money.

## Sales and e-invoicing

§20's chain starts before the subscription. A **quote** prices an offer and
holds it until a date; accepting it places an **order**; fulfilling the order
raises the invoice; paying that invoice starts the subscription.

**A quote lapses on the clock** — the fourth place this platform applies that
rule, after offer windows, entitlement validity and subscription periods.
`valid_until` is not nullable, and a quote past it cannot be accepted whatever
its status column says. A test leaves the column at `SENT` with the date a day
past, which is exactly the state a platform with no sweeper is in.

**A quote has no number.** French law numbers invoices and credit notes in
unbroken sequences; a devis is not subject to that, and a second numbering
scheme living beside the legal one is how one eventually gets mistaken for it.

**Nothing starts before the money arrives.** Fulfilling an order raises its
invoice and parks the order at `AWAITING_PAYMENT`; the subscription starts when
that invoice is paid. Activating on the assumption that payment will follow is
a decision to extend credit to everyone who can reach the endpoint, and it is
worth taking on purpose rather than by default. An order with nothing to
collect — a free offer — completes at fulfilment, because there is no payment
for it to wait on.

**The trigger is the invoice, not the card.** §25 lets an invoice reach `PAID`
two ways: a provider's webhook, and an operator reconciling a bank transfer.
Both fire the same `InvoicePaid` port, so the rule stays one sentence rather
than one sentence and an exception that would have left every transfer-paying
customer switched off.

**Each half resolves the offer differently, and that is the point.** Invoicing
asks what is *on sale* — billing against withdrawn terms would charge for
something nobody agreed to, and refusing costs nothing because no money has
moved. Activating asks what was *sold* — the money has arrived, possibly after
the offer was withdrawn, and refusing there would take a payment and give
nothing back.

**Both halves are one transaction each.** `orders_completed_is_traceable`
refuses a completed order that does not name both its invoice and its
subscription; `orders_awaiting_payment_has_invoice` refuses one waiting for
money it has raised no invoice for; and a partial unique index on
`orders.invoice_id` makes one invoice belong to exactly one sale, which is what
stops a single payment starting two subscriptions. Getting there extended part
2's pattern: `applyActivate()`, `applyIssue()` and `applyCompleteOrder()` sit
beside their transactional twins, so nothing nests.

**The invoice bills what the quote priced.** The lines travel quote → order →
invoice as data. A test reprices the offer between quote and fulfilment and
asserts the invoice does not move — otherwise a quote would be decorative.

**An invoiced order is undone by crediting it**, not by cancelling the order.
That holds from the moment the invoice exists, whether or not it has been paid:
numbering is gapless, so an issued document cannot simply be dropped.

**Transmission is its own history** ([§25.1](docs/architecture-v2.md)). An
invoice has nine states; a transmission has four, and there is one row per
attempt. Squeezing them together would lose the fact that a document was
rejected, corrected and re-sent — which is precisely the answer an inspector
wants, rather than "accepted". `REJECTED → READY_FOR_EINVOICE` is that remedy;
`ISSUED → PAID` stays reachable because not every invoice goes through a
platform.

Submitting is **resumable rather than transactional**, because it contains a
network call and holding a transaction across one kills a connection pool. The
*verdict* is one transaction, applied exactly once by the same unique index as
payments.

The platform is a configured adapter (non-negotiable #17). With no signing
secret there is none, and nothing can be transmitted — which matters more here
than for payments: a transmission record from an adapter nobody can verify
could be mistaken for evidence of compliance.

## Platform staff

Every authority elsewhere in this backend comes from membership. Support is
not a member of the customer it supports, so §12.2 adds a **second axis**
([ADR-025](docs/adr/ADR-025-platform-staff-identity.md)):

```text
tenant membership     (tenant, user, product) → TENANT_ADMIN | USER
platform staff role   (user, platform_role)   → PLATFORM_ADMIN | SUPPORT_ADMIN
                                                 FINANCE_ADMIN | SALES_ADMIN
```

**Neither converts into the other.** `TENANT_ADMIN` is the *customer's*
administrator and opens no staff route; a platform role grants no membership
and opens no tenant route. The tables are separate, and so are the permission
catalogues — `platform_permissions.code` is constrained to the `staff.` and
`support.` namespaces, so a tenant permission code cannot be granted to a
platform role because the database refuses to store it there.

**Staff routes take the tenant as an explicit path parameter**, the one place
this platform lets a client name one. It is not the exception to ADR-015 it
looks like: the path names the tenant, the platform role authorises the read,
and the read is recorded either way.

**Crossing the boundary is never silent** (non-negotiable #21). Every staff
read of tenant data writes to `staff_access_log` — who, when, which tenant,
which resource, and *under which permission*. The pairing lives in the service
rather than in the controllers, so the only way to read tenant data is to
record having read it. Failed lookups are recorded too: a trail holding only
successes cannot show somebody probing for ids. Reading the trail is itself
recorded.

Both actor and subject are `ON DELETE RESTRICT`. A staff member who has read
customer data can no longer be deleted — the intended consequence, since
revoking access means deleting their grant, and an access log whose actor
column can be emptied is not a log.

```text
GET /api/v1/staff/me           what the caller holds (records nothing)
GET /api/v1/staff/tenants      every tenant (recorded once, naming none)
GET /api/v1/staff/tenants/{id} one tenant (recorded, naming it)
GET /api/v1/staff/access-log   the trail, ?tenant_id= to narrow it
```

## Conversations

One model for two needs (§12.3,
[ADR-026](docs/adr/ADR-026-conversations-and-messages.md)):

```text
INTERNAL   tenant members only  — staff must never appear
SUPPORT    tenant members + platform staff
```

**Four invariants live in the schema**, not in a service:

- a conversation names `(tenant, product)`, like every other resource;
- the author of a message **is a participant, by foreign key** — writing into
  a thread you do not belong to is refused by PostgreSQL;
- `UNIQUE (conversation_id, seq)`, so ordering and paging are stable;
- **a STAFF participant implies a SUPPORT conversation**, carried by a
  composite foreign key onto `conversations (id, kind)`. Adding staff to an
  internal thread fails the check; claiming `SUPPORT` to get past it fails the
  foreign key.

The author key names three columns — `(conversation, user, kind)` — so a
member cannot post as `STAFF` by sending the field.

**`seq` is monotone and unique, not gapless.** Gapless numbering is a legal
requirement for invoices and costs a table lock; a chat message is entitled to
no such thing, so posting takes a row lock on the one conversation.

**Read state is a per-participant watermark**, moved with `GREATEST` so a
second tab reporting an older position cannot rewind it.

**No WebSocket, no held-open SSE** — R2 means no persistent process on shared
hosting. Reading is polling with `since_seq`, which the watermark makes cheap.

**A deleted message is really deleted**: the body is erased and a tombstone
keeps the thread's order. The opposite of an invoice, deliberately — §26
separates RGPD erasure from legal retention.

A member who is not a participant gets the same 404 as a conversation that
does not exist, so nobody can probe for threads they are not in. Adding a
participant checks tenant membership first; that one check is the difference
between a conversation and a hole in the tenant boundary.

```text
GET    /api/v1/conversations                                  threads you are in
POST   /api/v1/conversations                                  open one
GET    /api/v1/conversations/{id}                             with participants
POST   /api/v1/conversations/{id}/messages                    post
GET    /api/v1/conversations/{id}/messages?since_seq=         poll
POST   /api/v1/conversations/{id}/read                        move the watermark
POST   /api/v1/conversations/{id}/participants                add (membership checked)
DELETE /api/v1/conversations/{id}/participants/{userId}       mark as left
POST   /api/v1/conversations/{id}/close
DELETE /api/v1/conversations/{id}/messages/{messageId}        erase the body

GET    /api/v1/staff/conversations         support threads, ?tenant_id= to narrow
GET    /api/v1/staff/conversations/{id}    thread and messages together
POST   /api/v1/staff/conversations/{id}/messages   reply to a customer
POST   /api/v1/staff/conversations/{id}/close
```

Every staff read and reply writes to `staff_access_log`, as with every other
crossing of the boundary.

## Jobs

Asynchronous work runs without a resident process, because the deployment
target has none to offer (D3, [ADR-027](docs/adr/ADR-027-cron-polled-job-queue.md)).

```bash
# crontab
* * * * * /usr/bin/php /path/to/bin/run-jobs.php >> /path/to/jobs.log 2>&1
```

`bin/run-jobs.php` starts, claims what is due, runs it, and exits. Its
correctness rests on one statement:

```sql
UPDATE jobs SET status = 'RUNNING', leased_until = now() + …, attempts = attempts + 1
 WHERE id IN (
   SELECT id FROM jobs
    WHERE attempts < max_attempts
      AND ((status = 'QUEUED' AND run_after <= now())
        OR (status = 'RUNNING' AND leased_until < now()))
    ORDER BY priority DESC, run_after, created_at
    FOR UPDATE SKIP LOCKED
    LIMIT :n
 )
RETURNING …
```

**`FOR UPDATE SKIP LOCKED` is what makes overlapping runs safe.** A cron
firing while the previous pass is still going is the normal case, not an
error: the second run takes different rows rather than waiting behind the
first or claiming the same ones. Selection and leasing are one statement, so
no window exists in which a job is chosen but unclaimed. Verified with two
real concurrent sessions.

**A lease, not a lock.** A running job holds `leased_until`; when that lapses
the job is claimable again — the same rule that governs offer windows,
entitlements and quote expiry, now applied to the runner itself. It is what
stops a crashed worker stranding work, with no recovery step to schedule.

**`attempts` increments at claim, not at failure**, because a handler that
kills the process never reaches a failure path.

**Handlers must be idempotent.** A job can run twice — a lapsed lease while
the original is still working — and no lock available on this hosting
prevents it, so the requirement sits where it can be met.

**`failure_reason` holds the exception class, not its message**: the field is
served over the API and a driver's message can carry the SQL that failed
(§31). The message goes to the log.

`job_runs` records every pass, so a stopped cron is distinguishable from a
quiet queue — otherwise they look identical (R10).

```text
GET  /api/v1/jobs               this tenant's jobs
POST /api/v1/jobs               202 and an id; nothing runs in the request
GET  /api/v1/jobs/{id}          what a client polls after the 202
POST /api/v1/jobs/{id}/cancel   only before it starts
```

Two sweeps ship with it — `sweep.quotes` and `sweep.subscriptions`. Neither
changes what a lapsed quote or subscription *means*: acceptance and
entitlement resolution have asked the clock since M5 and M6. They make the
status column agree with the clock, which is what listings read.

## Assets

Large files live outside PostgreSQL (non-negotiable #9,
[ADR-028](docs/adr/ADR-028-assets-and-signed-links.md)). The `assets` table
records an object — key, type, size, checksum — and never holds one.

**The stored content type is sniffed from the bytes, never taken from the
request.** A client's `Content-Type` is a claim, and a claim is what an
attacker controls. Verified against real bytes, not assumed:

| Uploaded as | Sniffed as | Stored? |
|---|---|---|
| `image/png`, actually a PNG | `image/png` | ✅ |
| `application/pdf`, actually a PNG | `image/png` | ✅ as PNG |
| `image/png`, a PHP script behind PNG magic bytes | `application/octet-stream` | ❌ |
| anything, an SVG | `image/svg+xml` | ❌ |

SVG is excluded on purpose: an image to a user, a script container to a
browser, and serving one from this origin would be stored XSS with a friendly
extension.

**Storage keys are generated, never derived from the filename** — a key built
from user input is a path traversal waiting to be written. The filename
survives as a display label only, and the local adapter refuses any key that
is not the shape this platform generates.

**Downloads use signed, expiring links.** A browser fetching an image in an
`<img>` tag sends no Authorization header, so the URL carries its own proof —
the same shape as the payment webhook. The expiry is inside the signed
material, so it cannot be extended by editing the query string; comparison is
constant-time; and with no configured secret, verification fails closed.

The public prefix is `/api/v1/downloads/` and covers exactly one route.
Mounting it under `/assets/` would have exposed the whole asset surface.

```text
POST   /api/v1/projects/{id}/assets     the request body IS the file
GET    /api/v1/projects/{id}/assets
POST   /api/v1/projects/{id}/exports    202 and a job id; the runner renders it
GET    /api/v1/assets/{id}
DELETE /api/v1/assets/{id}
POST   /api/v1/assets/{id}/link         a short-lived signed URL
GET    /api/v1/downloads/{id}/content   the only route needing no session
```

An orphaned object is possible and is the failure deliberately chosen: upload
writes the object then the row, delete does the reverse, so a half-success
leaves something unreachable rather than a listing that lies.

## Projects

A project is a document the Core owns, stored as JSONB, plus the columns the
backend needs to find it again — tenant, product, name, schema version,
timestamps. Filtering never reaches inside the document.

Two rules are enforced on every write, and both are refusals:

- **A project declares its schema version** (non-negotiable #10). Which
  versions a product accepts is per-product configuration under the key
  `project_schema_versions` — `{"supported": [1, 2]}` — so a second product
  declares its own and no code learns either product's name. A product that
  has declared none accepts none. A missing `schema_version` is `422
  SCHEMA_VERSION_REQUIRED`; an unrecognised one is `422
  UNSUPPORTED_SCHEMA_VERSION`, whose details list what is accepted.

  The wire field is `schema_version`, matching the rest of this API; it is
  the same concept the Core calls `schemaVersion`.

- **Documents are not where assets live** (non-negotiable #9). A `data:` URI
  is refused whatever its size, as is any string over 64 KiB, and the error
  names the path inside the document. A document over 1 MiB is `413`. Assets
  get their own endpoints and object storage in M7.

Versions are complete snapshots ([ADR-018](docs/adr/ADR-018-project-versioning.md)).
Restoring captures the state it replaces in the same transaction, so the one
operation that overwrites a project is also the one that cannot lose it —
undo a restore by restoring the version it created. Duplicating copies the
document and starts a fresh history.

JSONB normalises a document on its first write: object keys come back sorted
and insignificant whitespace is gone. What is stored is what comes back, and
that is the guarantee snapshot and restore keep. Array order is preserved.

## Database

Requires PostgreSQL 16. Set `DATABASE_DSN`, then:

```bash
composer run migrate
```

Migrations are hand-written SQL
([ADR-016](docs/adr/ADR-016-migrations.md)). Tests that need a database skip
without `DATABASE_DSN` and always run in CI, which provisions PostgreSQL as a
service.

## Hardening settings (§31)

Every one of these defaults to the safe answer, so an unconfigured deployment
is restrictive rather than open.

| Variable | Default | What it does |
|---|---|---|
| `CORS_ALLOWED_ORIGINS` | *(none)* | Exact origins, comma-separated. Empty allows no cross-origin call at all. There is no wildcard: this API is read with a bearer token, and `*` cannot carry credentials. |
| `RATE_LIMIT_WINDOW_SECONDS` | `60` | Width of the fixed window. |
| `RATE_LIMIT_PER_WINDOW` | `600` | Requests per window per caller. |
| `RATE_LIMIT_PUBLIC_PER_WINDOW` | `60` | The same, for paths reachable with no credential — the cheap surface to attack. |
| `TRUSTED_PROXIES` | *(none)* | Addresses whose `X-Forwarded-For` may be believed. Empty means none, and the header is ignored. |

`TRUSTED_PROXIES` is the one worth reading twice. `X-Forwarded-For` is a
request header anybody may send, so believing it from an arbitrary connection
would give the rate limiter a bypass: a fresh address per request and an
allowance that never runs out. Left empty behind a real proxy the failure is
the other way — every client shares one bucket and the limit is too strict,
which costs a retry rather than an outage.
