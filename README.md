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

M1 of [`docs/backend-roadmap.md`](docs/backend-roadmap.md): the request
context chain. Routes are `GET /api/v1/health` (public) and `GET /api/v1/me`.

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

**Persistence is not implemented.** Products, tenant memberships and
entitlements are served by in-memory adapters behind their ports, seeded
empty in production wiring. The PostgreSQL adapters arrive with the milestone
that owns each table — tenants in M2, products in M3, entitlements in M5 —
and swapping them changes no caller.
