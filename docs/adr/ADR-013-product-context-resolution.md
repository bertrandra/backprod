# ADR-013 — Product context is carried by an explicit header

**Status:** accepted
**Decides:** D1 in `docs/backend-roadmap.md`
**Relates to:** Architecture V2 §10.6, §12.1

## Context

The backend is a shared platform serving several products, and `product_id`
is resolved *before* tenant on every request (§10.6). How the frontend states
which product it is acting as therefore touches every route, and changing it
later is expensive.

Options considered:

- **Header** — `X-Product: <code>` sent by the frontend.
- **Subdomain** — `product-a.api.example.com`, resolved from `Host`.
- **Path prefix** — `/api/v1/products/{product}/…` on every route.

## Decision

The frontend states the product in an `X-Product` header. The backend
resolves it against the product registry and rejects unknown or inactive
products with `404 PRODUCT_NOT_FOUND`.

The header is a **claim, not an authorisation**. §12.1 is explicit that the
backend remains the authority for whether the user and tenant may use the
product; the header only says which product the caller means.

## Rationale

- A path prefix would duplicate the product segment across the whole API
  catalogue in §10.1, which is written without one.
- Subdomains couple product context to DNS and TLS provisioning, adding an
  operational step per product and complicating local development. They stay
  available later as an additional source that populates the same context.
- A header keeps resolution in one middleware and leaves the documented URL
  structure untouched.

## Consequences

- Requests to protected routes without `X-Product` are rejected with
  `400 PRODUCT_CONTEXT_REQUIRED`, rather than defaulting to some product.
  A default would silently grant access to whichever product came first.
- CORS configuration must allow the header.
- The header name is referenced in one place, so a later move to subdomains
  changes `ProductResolver` and nothing else.
