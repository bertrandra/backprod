# ADR-065 — A product declares the document versions it writes

**Status:** accepted (2026-10-01)
**Relates to:** ADR-018 (project versioning), ADR-051 (a product beside the
platform), non-negotiable #10

## Context

Which project document schema versions a product accepts is per-product
configuration, deliberately: a second product declares its own as a row, and no
code learns either product's name (ADR-018, non-negotiable #10). The list lives
in `product_configuration` and exactly one thing writes it — a staff screen.

That makes the platform's list a **copy, made by hand, of a fact the product
owns.** The product writes the migrations and ships the spec; the platform holds
a transcription of it, updated when somebody remembers.

The copy has fallen behind twice:

- Plan 2.2.0 saved in schema 2 for the façade survey, against a list of `[1]`;
- Plan's roofs deduced from BD TOPO saved in schema 3, against `[1, 2]`.

Both times every save was refused `UNSUPPORTED_SCHEMA_VERSION`, and both times
it read as a bug in Plan. `bin/preflight.php` now refuses a deployment in that
state, but only at deployment: the platform is deployed on its own schedule, and
the product's release is what moves the fact.

## Decision

**A product may serve a manifest about itself**, at a fixed path under the
`app_url` staff already configured for it:

```text
GET https://plan.example/.well-known/product.json

{"product": "plan", "app_version": "2.3.0", "schema_versions": [1, 2, 3]}
```

- `product` and `schema_versions` are required; `app_version` is shown to an
  operator and decides nothing.
- Unknown keys are ignored. It is the product's file and it will grow things the
  platform has no opinion about.
- An empty `schema_versions` is **not** a manifest.

**The platform reads it and never obeys it.** `GET
/api/v1/staff/configuration/product-manifest` fetches it and writes nothing. The
console shows what the product declares beside what the database holds, names
what applying would add, and applying is a separate `PUT
…/project-schema-versions` that a person presses.

**Nothing proposes a removal.** The difference offered is only what the product
declares and the configuration lacks.

**The manifest names its product**, and the platform checks that against the
product it asked about.

## Rationale

### Why the product is asked rather than trusted

A manifest that wrote straight into `product_configuration` would hand the
product's own host two things it must not have.

The first is **un-retirement**. ADR-018 makes removing a version from the list
the way a product's format is retired: editing a document under a retired
version is refused, and restoring one is allowed. A list that re-opened itself
whenever a host said so would make retirement impossible to express — the next
fetch would undo it, silently, and no screen would ever show the decision having
been reversed.

The second is **reach**. `app_url` is one staff field away from pointing
somewhere else: copied between two products, left on a staging address, or
changed by somebody who should not have. A read that only informs costs nothing
when that happens; a read that writes reconfigures a product nobody was looking
at.

So this is the same bargain `DeclareCapabilitiesController` strikes in the other
direction (ADR-052): a program may say what it has built, and may not invent
what is priced. Here a product may say what it writes, and may not decide what
the platform accepts.

### Why a static file rather than the product's own answer to a query

Plan already knows both facts — its bundle carries `app_version` and the highest
schema its migrations read — and it already serves `/?version`, which a person
can open. That address answers with the application: the parameter is handled by
JavaScript in the browser, so there is nothing for the platform to read.

A file written at build time from the constants the product already has is
readable by a program, costs an SPA host no server-side code, and cannot drift
from the release that carries it.

### Why a well-known path and not a configured one

A path staff could set is a second field that can be wrong, pointing at a second
place a manifest might be. The address is already configured — `app_url` — and
one fixed path under it is the whole contract.

### Why `NOT_SERVED` is not a failure

Most products will never serve a manifest, and a product that runs inside this
shell has no address to ask. A console that showed a red failure for each of
them would teach its operator to ignore the one that matters. Only
`WRONG_PRODUCT` is called out, because it means an address is pointing at
somebody else and nothing else on that screen can be trusted.

### What is deliberately not decided here

**A product pushing its own declaration.** `ProductKeyRoute` and `ProductScope`
already authenticate a product calling the platform as itself, so `PUT
/api/v1/product/schema-versions` is a small addition and a better fit for the
moment the fact changes — a product's deploy knows it has shipped; the platform
has to be asked. It is left out of this decision only because the pull was built
first; if it is added, what it writes must be a *declaration* the console still
applies, never the accepted list, for the two reasons above.

## Consequences

- A product that serves no manifest is exactly as it was: the list is kept by
  hand, and `bin/preflight.php` is what notices.
- The platform makes an outbound HTTPS request when the Invoicing screen is
  opened for a product with an `app_url`. https only, no redirects, five seconds
  to connect and ten to answer, 64 KiB at most — the posture `CurlWebhookTransport`
  already uses, and the address is one staff typed rather than one a caller
  supplied.
- Nothing notices between visits to that screen. A job that asks each product on
  a schedule and notifies staff would close that, and is where this goes next.
