# ADR-051 — A product beside the platform connects with three credentials, none of them shared

**Status:** accepted 2026-09-20 (merged by the operator). Milestones B, C
and D are built (2026-09-21): `GET /me/context` and `products.app_url`;
product keys, the `product` authority with its three routes, the product
access log and reported usage, issued and revoked from the console; the
webhook address and secret per product, the `webhook_deliveries` outbox,
the `webhook.deliver` job and the console's view of deliveries. E is not;
§7 says what it costs. Details settled in building: a refused key answers
`401 UNAUTHENTICATED` when unknown and `403 PRODUCT_KEY_REVOKED` /
`PRODUCT_KEY_EXPIRED` / `PRODUCT_KEY_SCOPE` when recognised; a quota
refusal is `403 QUOTA_EXCEEDED`, the platform's own code, not a 409. For
D: subscription events are *collected* from the append-only
`subscription_events` history by a cursor rather than published by every
commerce path (§5 says why); the secret is sealed under a dedicated
`WEBHOOK_SECRET_KEY` rather than "the app key", because no such key
existed; a `400` from the product parks the delivery at once; and a
product with no address is told nothing rather than told later.

**Relates to:** ADR-013 (product context is a header), ADR-015 (tenant
resolution), ADR-037 (the shell gates on `/me`), ADR-038 (this platform
issues its own sessions), ADR-047 (a tenant has products), ADR-048 (webhook
signature scheme), §10.6, §12.1, §26, §27.1, §31, §38.3.

**Companion:** `docs/plan-service.md` — the product's side: what the Plan service is, owns, borrows and must never do.

## 1. Context

Backprod is the shared platform: people, organisations, offers,
subscriptions, entitlements, invoices, payments, mail. A product is what a
tenant belongs to and what an offer is priced for (ADR-047) — and until now
every product's *screens* lived inside this repository, in the one shell,
under `/projects` and the like.

The operator's next product does not. It is deployed **beside** backprod, on
its own host — `plan.raillard.org` next to the platform — with its own code,
its own deployment and, possibly, its own backend. It must nevertheless be
*the same product* the platform sells: the same people sign in, the same
organisation's subscription entitles them, the platform bills, and the
product's own consumption counts against the platform's quotas.

The question is therefore not "how does a product call an API" but **which
trust relationships exist between a separately deployed product and the
platform, and what credential carries each one** — because the wrong answer
here is a shared secret that lets the product mint the platform's sessions,
or a token in a URL, or a product that decides its own entitlements.

Facts this proposal builds on, as they stand in the code today:

- A session is a **JWT signed HS256** with `AUTH_SIGNING_SECRET`
  (`LocalJwtTokenIssuer`), `iss: backprod`, `aud: backprod-api`, `sub` the
  auth subject, ~15 minutes; the refresh token is an `HttpOnly`,
  `SameSite=Strict` cookie on `Path=/api/v1/auth` of the platform's host,
  with no `Domain` attribute (ADR-038, `RefreshCookie`).
- The product a request means is the **`X-Product` header, a claim**; the
  membership decides whether the person may use it (ADR-013/015). CORS is an
  exact allowlist, `CORS_ALLOWED_ORIGINS`, with credentials
  (`CorsMiddleware`), and `X-Product` is already among the allowed headers.
- `GET /me`, `/me/permissions`, `/me/entitlements`, `/products/{id}/configuration`
  answer for the signed-in person; `/tenants/current/usage` reads what a
  tenant has consumed; nothing yet *writes* usage from outside.
- Inbound webhooks (Stripe, e-invoicing) are verified with
  `t=<unix>,v1=<hmac-sha256("<t>.<body>")>` and constant-time compare
  (ADR-048); the M7 job queue delivers asynchronous work with retries.
- Platform staff routes are a separate authority with their own permission
  catalogue, audited on every crossing (§12.2, `staff_access_log`).

## 2. The three relationships

```text
                 (1) person, in a browser           (2) product service → platform
   Person ───────────────────────► Product ◄──────────────────────────────┐
     │                              app                                    │
     │ backprod session             │ forwards the                         │ product key
     │ (bearer, memory)             │ person's bearer                      │ (server only)
     ▼                              ▼                                      ▼
   Backprod API ◄──── (1') the product's frontend is a client of the API ──┘
        │
        │ (3) platform → product: signed events
        ▼
   Product webhook endpoint
```

| # | Who talks to whom | What for | Credential | Who is the authority |
| --- | --- | --- | --- | --- |
| 1 | The person's browser, on the product's page, to the platform | sign in, `/me`, entitlements, the product's own reads and writes through the platform | **the person's backprod session** — the same access token and refresh cookie the shell uses | the platform, per request, as today |
| 2 | The product's server to the platform, with no person present | report usage against a quota, read a tenant's entitlement in a job, list who may use the product | **a product key** — issued by the console, hashed at rest, scoped, revocable | the platform; the product is identified *by the key*, never by a header |
| 3 | The platform to the product's server | tell it a subscription started, changed or ended; a member was added or removed; a person was erased | **an HMAC-signed event** delivered by the job queue | the product verifies the signature; the platform is the source of truth |

What is deliberately **not** on this list: the product signing the
platform's tokens (a shared `AUTH_SIGNING_SECRET` is impersonation of
everybody), a token carried in a URL to hand a session across hosts, and the
product deciding an entitlement on its own copy of the data.

## 3. Relationship 1 — the person, in the browser

**The product's frontend is a second client of the same contract.** It
generates its client from `openapi.json` exactly as `frontend/` does
(ADR-036), sends `X-Product: plan` on every call, signs in through the same
three operations (`POST /auth/token`, `POST /auth/refresh`,
`POST /auth/sign-out`), holds the access token in memory and nothing durable
in the browser (ADR-038). Nothing new is issued and nothing is shared: the
platform sees one more origin doing what its own shell does.

**Single sign-on comes from the cookie, and the cookie needs the same site.**
The refresh cookie is `SameSite=Strict`, which is a rule about *site*
(registrable domain), not origin. `plan.raillard.org` and the platform's
host under `raillard.org` are one site, so a `fetch('/api/v1/auth/refresh',
{ credentials: 'include' })` from the product's page carries the cookie, and
a person who signed in on the platform is signed in on the product with no
second password — provided the origin is on the CORS allowlist, which is what
`Access-Control-Allow-Credentials` requires and what the middleware already
does for exact origins. The reverse holds too: signing in on the product's
page sets the cookie for the platform's host, and the shell resumes from it.

*Consequence:* a product on **another registrable domain** does not get this.
The token endpoint still works (the person types their password on the
product's page), but the cookie is then cross-site and Strict, so every
reload asks again. That case needs a hand-off protocol (an authorisation
code, one-time, 60 seconds, exchanged server-side) which this ADR does not
build. **The recommendation is a sibling subdomain.** `plan.raillard.org` is
one.

**Where the product's own data lives decides the shape of its backend.**

- *Through the platform* — the product stores its documents as platform
  resources (projects, versions, assets, jobs are already product-scoped
  under `X-Product`): then the product may need no server at all, and every
  authorisation question is already answered per request.
- *In its own backend* — the product's server receives the person's bearer
  from its frontend and must decide who this is and what they may do. It
  does **not** verify the JWT itself: the key is symmetric, and giving it
  out is giving out the mint. It **asks the platform**, forwarding the
  bearer with `X-Product: plan`:

  ```text
  GET /api/v1/me/context        (new)   one call, one answer:
    user (id, email, display_name, locale), tenant (id, slug),
    product, roles, permissions, entitlements (with limits and usage),
    token_expires_at
  ```

  cached by the product per token until `token_expires_at` (fifteen
  minutes at most, so a revoked membership is honoured within that), and
  refused with the platform's own `401`/`403`/`404` codes, which the product
  relays. The authority stays in one place, no key travels, and the product
  gets exactly the answer the shell gets.

  Asymmetric tokens (EdDSA, with `GET /api/v1/auth/jwks`) would let a
  product verify locally with no round trip. They are the upgrade when
  latency proves to matter — §8 — not the starting point, because they add
  key rotation, an `aud` per product and a second verifier to keep honest.

**The person moves between the two by ordinary links.** The shell's product
switcher, for a product that has an `app_url`, navigates there with
`?product=plan` and nothing else — no token, no session id, nothing a
history or a proxy log could replay. The product's page resumes from the
cookie. `landing.ts` treats an external default product the same way. Sign
out from either side clears the one cookie and revokes the family
(ADR-038), so there is no second session to forget.

## 4. Relationship 2 — the product's server to the platform

Some things a product does have no person behind them: a nightly job that
counts what a tenant consumed, a check before an expensive operation, a
listing of who may use it. For those the product itself is the caller, and
it needs a credential that is **its own, not a person's** — a service
account would blur §12.2's rule that a role is held by a membership or a
platform role and nothing else.

**A product key.**

```text
product_credentials
  id            uuid
  product_id    → products, ON DELETE CASCADE
  key_id        text unique          public half, in the header
  secret_hash   text                 argon2id of the secret; the secret is shown once
  scopes        text[]               among product.usage.write, product.entitlements.read, product.members.read
  label         text                 "plan production", "plan staging"
  created_by    → users              who issued it, from the console
  created_at, expires_at, revoked_at, last_used_at
```

- Sent as `Authorization: Bearer bpk_<key_id>_<secret>`; recognised by the
  prefix before any JWT parsing, so a product key never reaches the session
  verifier and a session never reaches the key verifier. Compared in
  constant time against the hash; `last_used_at` written on success.
- **The product is derived from the key.** These routes read no `X-Product`;
  a key issued to `plan` cannot say it is `atlas`. This is the whole reason
  the routes are their own authority, `product`, beside `tenant` and
  `platform` (ADR-046's rule: every route says which authority it answers
  to, and `gate:permissions` checks the catalogue it lives in).
- Scopes are the key's permission catalogue. A key with
  `product.usage.write` alone can report usage and read nothing.
- Every call writes a `product_access_log` row: key, product, tenant,
  route, outcome — the same reasoning as `staff_access_log`: an actor that
  is not a person still crosses a tenant boundary, and "did the product
  read this tenant?" has to have an answer.
- Issued, listed, rotated and revoked from the console's Products screen
  (`staff.products.manage`). Rotation issues a second key first and revokes
  the first afterwards, so a deployment never has zero valid keys.
  `expires_at` defaults to a year; the console says which keys expire
  within thirty days.
- Rate-limited per key, and refused with `401 PRODUCT_KEY_INVALID` /
  `403 PRODUCT_KEY_SCOPE` / `403 PRODUCT_KEY_REVOKED` — three different
  answers, because a rotated key and a stolen one are diagnosed differently.

**The routes**, under `/api/v1/product/*`:

```text
GET  /api/v1/product/tenants/{tenantId}/entitlements     product.entitlements.read
     what this tenant holds on *this* product, with limits and usage —
     the same resolution the person's chain uses, asked for nobody

POST /api/v1/product/tenants/{tenantId}/usage            product.usage.write
     { feature, quantity, period_start, period_end, idempotency_key }
     a metered fact (§38.3); refused 409 QUOTA_EXCEEDED when the tenant's
     entitlement does not cover it, so the product can refuse *before*
     doing the work; idempotent by key, because a retried job must not
     count twice

GET  /api/v1/product/tenants/{tenantId}/members          product.members.read
     who may use this product at this tenant: user id, email, roles —
     never a password hash, never another product's membership
```

A tenant the product's key cannot see — one that does not hold the product
(ADR-047) — answers `404`, the same non-answer the person's routes give, so
existence does not leak by service key either.

Why bearer keys rather than signed requests: the platform already has the
HMAC scheme (ADR-048) and could demand
`X-Product-Signature: t=…,k=…,v1=hmac("<t>.<method>.<path>.<sha256(body)>")`
with a five-minute window and a replay cache. It is the stronger design
against a key read in transit. It is also a scheme the product's developer
has to get byte-exact, and TLS already carries the bearer. The bearer is the
proposal; the signature is the documented upgrade for a product on a network
the operator trusts less, and the key table is built so that adding
`signing_secret_hash` beside `secret_hash` is a column, not a redesign.

## 5. Relationship 3 — the platform to the product

The product must learn what happened on the platform without polling it:
the organisation's subscription started, was changed, was cancelled and its
period ended; a member joined or left; a person asked to be forgotten (§26 —
the product holds their name too, and the erasure is not done until it
propagates). These are events (§27.1's vocabulary), delivered as webhooks.

```text
products
  app_url          text null      where the person is sent (relationship 1)
  webhook_url      text null      where events are delivered (relationship 3)
  webhook_secret   text null      the current HMAC secret, at rest encrypted with the app key;
                                  shown once; rotated with an overlap window

webhook_deliveries
  id, product_id, event_id, event_type, payload jsonb,
  attempt, next_attempt_at, delivered_at, last_status, last_error
```

- Signed like the inbound ones: `X-Backprod-Signature: t=<unix>,v1=<hex>`,
  HMAC-SHA256 over `"<t>.<rawBody>"`, so the product's verifier is the
  mirror of `StripeWebhookController`'s and a product developer can copy a
  known-good implementation. During a rotation both `v1=` values are sent.
- Delivered by the M7 queue, never inside the request that caused them; a
  `2xx` within ten seconds is delivered, anything else is retried with
  backoff (1 min, 10 min, 1 h, 6 h, 24 h) and then parked, visible in the
  console with its last error. The product must be idempotent by `event_id`,
  because a delivery that timed out after the product wrote is a duplicate
  the product will see.
- The payload names ids and states, never money and never a credential:
  `{ event_id, type, occurred_at, product, tenant_id, subscription: { id,
  status, offer, current_period_end, subscriber: { kind, user_id } } }`.
  The product fetches anything richer through relationship 2, where the
  read is authorised and logged.
- Event types, first set: `subscription.started`, `subscription.changed`,
  `subscription.cancellation_scheduled`, `subscription.ended`,
  `member.added`, `member.removed`, `tenant.product.assigned`,
  `tenant.product.unassigned`, `user.erased`. The last one is not optional
  for a product that stores a person's name: §26's erasure is a platform
  promise, and a product that keeps the name breaks it.
- **Where the rows come from** (as built). Members, assignments and
  erasures are published by the service that does the act, through a
  `ProductEvents` port whose adapter writes the outbox row — for every
  product the tenant holds, since a membership is mirrored onto each
  (ADR-047). Subscription events are not published anywhere: the
  `webhook.deliver` job *collects* them from `subscription_events`, the
  append-only history that non-negotiable #18 already requires to be
  complete, reading forward from a cursor that trails the clock by a
  minute so a late commit is not passed over. Hooking every commerce path
  instead would have taught the commerce module that products exist
  beside the platform, and missed the next path somebody adds.
  `ACTIVATED` → `subscription.started`; `OFFER_CHANGED`, `RESUMED`,
  `RENEWED` → `subscription.changed`; `CANCELLED`, `EXPIRED` →
  `subscription.ended`.

## 6. What the product has to do (the checklist its developer gets)

1. Be served from a sibling subdomain of the platform's host (§3), over
   HTTPS, with a CSP whose `connect-src` names the platform's origin.
2. Generate its API client from the platform's `openapi.json`; send
   `X-Product: <its code>`; sign in with `/auth/token`; resume with
   `/auth/refresh` and `credentials: 'include'`; keep the access token in
   memory only; call `/auth/sign-out` to sign out.
3. Read what the person may do from `/me/context`, never from a local copy;
   cache per token until it expires; render the platform's `401`/`403`
   as the platform's shell does — one is answered by an administrator, the
   other by an upgrade (ADR-037).
4. If it has a server: forward the person's bearer to the platform for any
   decision about the person; keep its product key on the server only, in
   an environment variable, and use it for nothing a person could have
   asked for.
5. Report metered features with `POST /product/tenants/{id}/usage` and an
   idempotency key; refuse the work on `409 QUOTA_EXCEEDED`.
6. Expose one webhook endpoint; verify `X-Backprod-Signature` before
   parsing the body; be idempotent by `event_id`; delete the person's data on
   `user.erased`.
7. Never put a token, a key or a tenant id it did not receive from the
   platform into a URL, a log or a browser store.

## 7. Milestones, and what each costs

| Step | What it gives | Platform changes | Product changes |
| --- | --- | --- | --- |
| **A — switch on today** | a person signs in on `plan.raillard.org` and reads their entitlements; SSO from the platform's shell via the cookie | none in code: register the product (`products`, `tenant_products`, an offer), add the origin to `CORS_ALLOWED_ORIGINS` | the generated client, `X-Product`, the three auth calls |
| **B — the person's context and the way over** | one call answers who this is and what they hold; the switcher and the landing send the person to the product | `GET /me/context`; `products.app_url` + console field; switcher/landing follow it (ui-spec §4) | read `/me/context` |
| **C — the product as a caller** | usage counted against quotas; entitlements and members readable by a job | `product_credentials`, the `product` authority and its three routes, `product_access_log`, console issue/rotate/revoke, `gate:permissions` learns the third catalogue | the key on its server; usage reporting |
| **D — events** | the product learns of subscriptions, members and erasures without polling | `products.webhook_url/secret`, `webhook_deliveries`, the queue job, console view of deliveries | one endpoint, signature check, idempotency |
| **E — optional, later** | local token verification with no round trip | EdDSA issuer + `GET /auth/jwks`, `aud` per product, rotation | a JWT verifier |

A and B are a small pull request each. C is the one with a new authority
and a new permission catalogue, and it is where `gate:permissions`,
`gate:ui` (the console screens) and an ADR amendment to ADR-046 ("two
authorities" becomes three) all move together. D reuses the queue and the
signature code that exist. E is deliberately last.

## 8. Alternatives considered

- **Share `AUTH_SIGNING_SECRET` with the product** so it verifies tokens
  locally. Rejected: HS256 verification *is* the ability to sign; the product
  would hold the mint for every session on the platform, and a compromise of
  the product is a compromise of the platform.
- **Hand the session over in the URL** (`?token=…`) when the switcher sends
  the person to the product. Rejected: a URL is logged by proxies, kept in
  history, sent in `Referer`. The cookie already carries the session across
  the site; a product elsewhere gets a one-time code, not a token.
- **Let the product decide entitlements from a copy** pushed by webhook.
  Rejected as the *authority*: a copy is right until a cancellation lands
  between two deliveries. Events are for reacting; `/me/context` and the
  `product` routes are for deciding.
- **A service account user with a membership** instead of a product key.
  Rejected: §12.2 keeps roles on memberships and platform roles, and a fake
  person in `tenant_members` is the forgotten-filter privilege escalation the
  separate table exists to prevent.
- **Signed requests from the start** (§4). Deferred: correct, heavier for the
  product developer, and TLS carries the bearer; kept as the documented
  upgrade with the schema ready for it.
- **The product inside this repository**, as a workspace module (ui-spec §6).
  Still the right answer for a product with no deployment of its own. This
  ADR is for one that has one.

## 9. Security invariants, restated

- No credential is shared between the platform and a product: the person's
  session is issued and verified by the platform alone; the product key is
  the product's; the webhook secret is per product and never leaves the
  console except once, at issue.
- A credential never travels in a URL, a log line, a browser store or a
  webhook payload.
- The product on a service route is identified by its key; `X-Product` is a
  claim on person routes only, and a product key on a person route is
  refused, as a session on a product route is.
- Every read that crosses a tenant boundary is logged with who — person or
  product key — and under which permission or scope.
- A tenant that does not hold the product is absent (`404`) to that
  product, by session and by key alike.
- Erasure propagates: `user.erased` is delivered and its acknowledgement is
  recorded, so §26's promise has a receipt.
- What can be revoked, is: sessions by family (ADR-038), keys by id, webhook
  secrets by rotation — and the console shows what is live.
