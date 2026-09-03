# backprod

Shared multi-product SaaS backend platform.

This is **not** the backend of a single product. One platform serves several
products; `product_id` is a first-class request context alongside `tenant_id`.
See [`CLAUDE.md`](CLAUDE.md) for the rules that govern changes here, and
[`docs/architecture-v2.md`](docs/architecture-v2.md) for the architecture
decisions behind them.

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

M5 of [`docs/backend-roadmap.md`](docs/backend-roadmap.md) — commerce, in
full — on top of M1's context chain, M2's platform identity, M3's product
registry and M4's projects. Entitlements are no longer a placeholder: what a
tenant may use comes from what they subscribed to.

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
`{"amount_minor_units": 2900, "currency": "EUR"}` — never a float and never a
formatted string. `price_minor_units` rather than `price_cents`, because not
every currency has cents.

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
