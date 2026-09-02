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
public/     front controller
src/
  Health/   liveness endpoint
  Shared/   Http (pipeline, router, errors), Exceptions, Logging
tests/
  Unit/
  Integration/
tools/      architecture gate proof and its fixture
docs/       architecture and roadmap
```

Modules follow `Module/{Controller,Service,Domain,Repository,Infrastructure}`
as they gain those layers; small modules stay lighter (§41.1).

## Status

M2 (persistence foundation) of
[`docs/backend-roadmap.md`](docs/backend-roadmap.md), on top of the M1 request
context chain. Routes are `GET /api/v1/health` (public) and `GET /api/v1/me`;
tenant and user management endpoints are the next increment.

Every request to a non-public path resolves, in this order (§10.6):

```text
authentication → product → tenant → roles → entitlements
```

The result is a `RequestContext`, and it is the **only** sanctioned source of
tenant and product identity. A handler that reads a tenant id from a header,
query or body is reading a claim, not a decision — see
[ADR-015](docs/adr/ADR-015-tenant-resolution.md).

Routes are protected by omission: only paths in the public-routes list skip
the chain, so a new route is secure unless someone deliberately exempts it.

Users, products, tenants and membership are stored in PostgreSQL. A person is
provisioned locally on their first authenticated request
([ADR-017](docs/adr/ADR-017-user-provisioning.md)); the internal user id, not
the identity provider's subject, is what every foreign key references.

Entitlements remain in memory and seeded empty until offers and subscriptions
land in M5, so no tenant may currently use any capability.

## Database

Requires PostgreSQL 16. Set `DATABASE_DSN`, then:

```bash
composer run migrate
```

Migrations are hand-written SQL
([ADR-016](docs/adr/ADR-016-migrations.md)). Tests that need a database skip
without `DATABASE_DSN` and always run in CI, which provisions PostgreSQL as a
service.
