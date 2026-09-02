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
  Auth/     token verification behind a provider port
  Entitlement/ what a tenant has bought
  Health/   liveness endpoint
  Identity/ the caller's own account
  Product/  the product registry and catalogue
  Project/  projects and their versions
  Tenant/   tenants, members and roles
  User/     platform users and provisioning
  Shared/   Context (the §10.6 chain), Http, Database, Exceptions, Logging
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

M4 of [`docs/backend-roadmap.md`](docs/backend-roadmap.md) — projects, the
first product resource — on top of M1's context chain, M2's platform identity
and M3's product registry.

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

Authorisation asks about **permissions**, never role names (§13). Roles map
to permissions in the database, so moving a permission between roles changes
no code. Three refusals mean three different things and are fixed in three
different places: `PERMISSION_DENIED` is a role change, `ENTITLEMENT_REQUIRED`
is a subscription change, `NO_TENANT_ACCESS` is an invitation.

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

Entitlements remain in memory and seeded empty until offers and subscriptions
land in M5, so no tenant may currently use any capability. The `max_projects`
quota is an entitlement, so project creation is not yet quota-limited.

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
