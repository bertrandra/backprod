# The Plan service — specification of a product beside the platform

**Status:** accepted 2026-09-20, with ADR-051. Milestones B and C are built on the platform (`/me/context`, `app_url`, product keys and the three `product/*` routes — §7 is live); §8 (events, milestone D) is not yet. Where this document said `409 QUOTA_EXCEEDED`, the platform answers `403 QUOTA_EXCEEDED`. This is the product's side of
that decision: what `plan.raillard.org` is, what it owns, what it borrows from
the platform and how, and what it must never do. It is written for the
people who build Plan — in its own repository, on its own host — so that the
platform's rules hold there without the platform's code.

What Plan *does* (its domain: the plans it draws, computes or stores) is the
operator's to write in §2; this document specifies everything around it.
Where the domain is named below it is an example, marked as such.

## 1. What Plan is, and is not

Plan is **one product of the platform** (ADR-047): a row in `products` with
code `plan`, held by tenants, sold through offers the platform prices,
invoices and collects. It is deployed beside the platform, not inside it —
its own code, its own release, its own host.

Plan **is** responsible for: its screens; its own resources and their storage;
its own calculations; reporting what it metered; reacting to what the
platform tells it.

Plan **is not** responsible for, and must not implement: accounts,
passwords, sessions, organisations, memberships, roles, offers, prices,
subscriptions, entitlements, invoices, payments, mail, erasure of a person.
Each of those is the platform's, and Plan reads the platform's answer rather
than keeping one of its own. A product that re-implements any of them is a
second authority, and two authorities disagree.

## 2. The domain (to be written by the operator)

```text
Plan resources — example, not decided:
  plan          a document a tenant's members draw and version
  plan_version  a snapshot of one
  plan_asset    a file attached to one
```

Rules that hold whatever the domain is:

- Every Plan resource is keyed by the platform's `tenant_id` (uuid) and,
  where a person owns it, `user_id`. Those ids come from `/me/context` on
  the request that created the resource, never from the client's body.
- Plan never stores a person's name or email as authority — it may cache
  them for display, and must be able to blank them on `user.erased` (§8.3).
- Plan does no money and no numbering: nothing in its tables is a price, a
  quantity to invoice or a document number. What it meters (§7) is a count.

## 3. Deployment

```text
https://plan.raillard.org          Plan's frontend, and its API if it has one
https://<platform host>            backprod: the shell and /api/v1
```

- **A sibling subdomain of the platform's host, over HTTPS.** This is the
  condition for single sign-on: the platform's refresh cookie is
  `SameSite=Strict`, which is a rule about the *site* (`raillard.org`), so a
  request from `plan.raillard.org` carries it (ADR-051 §3). A product on
  another registrable domain would not, and this specification does not
  cover that case.
- The nginx basic authentication in front of `plan.raillard.org` today must
  be removed before Plan is offered to anybody the platform signs in:
  a browser challenged with `401 Basic` on the page never reaches the
  application, and the platform's shell cannot send a person to a page that
  asks a second password.
- Plan's HTTP headers mirror the platform's (ADR-048, deploy template):
  `Content-Security-Policy` with `default-src 'self'`, **`connect-src 'self'
  https://<platform host>`**, `frame-ancestors 'none'`, `object-src 'none'`,
  `base-uri 'self'`; `X-Frame-Options: DENY`; `X-Content-Type-Options:
  nosniff`; `Referrer-Policy: strict-origin-when-cross-origin`;
  `Strict-Transport-Security` on HTTPS responses.
- If Plan has a backend, its API is served from the **same origin** as its
  frontend (`https://plan.raillard.org/api/…`), so Plan needs no CORS of its
  own. The only cross-origin calls Plan's page makes are to the platform,
  which allows the origin `https://plan.raillard.org` in
  `CORS_ALLOWED_ORIGINS`.

## 4. Configuration

Server-side environment, never in the bundle:

```text
BACKPROD_API_URL=https://<platform host>       the platform's origin, no path
BACKPROD_PRODUCT_CODE=plan                     what X-Product says
BACKPROD_PRODUCT_KEY=bpk_…                     ADR-051 §4 — server only, absent on a pure frontend
BACKPROD_WEBHOOK_SECRET=…                      ADR-051 §5 — server only
```

The frontend bundle carries exactly two facts, both public: the platform's
origin and the product code. A build that embeds a key or a secret is
refused by Plan's own verify step (mirror of `bin/verify-dist.sh`: grep the
bundle for `bpk_` and for the secret, fail on a hit).

## 5. Identity and session (relationship 1 of ADR-051)

Plan's frontend is **a client of the platform's contract**: it generates its
types and client from the platform's `openapi.json` at build time and calls
nothing by hand (ADR-036's rule, applied across a repository boundary — the
contract file is fetched from the platform repository at a pinned commit and
regenerated by a `gate:client` of Plan's own). Every call carries
`X-Product: plan`; the bearer is attached by middleware, never by a call
site.

### 5.1 Boot

```text
page loads
  → POST /api/v1/auth/refresh   credentials: 'include'   (the platform's cookie, same site)
      200 → access token in memory; expires_in → renewal timer (60 s margin)
      401 → nobody is signed in → the sign-in form (§5.2)
  → GET /api/v1/me/context      Authorization: Bearer, X-Product: plan
      200 → who, which tenant, roles, permissions, entitlements, locale
      403 NO_TENANT_ACCESS / 404 PRODUCT_NOT_FOUND → "this account holds no Plan" (§5.4)
```

Nothing renders behind the gate before `/me/context` has answered; nothing
durable is written to the browser — no token, no user id, no tenant id.
`localStorage` may hold the language (`backprod.locale`, ADR-050) and
per-viewer conveniences, nothing that identifies anybody.

### 5.2 Signing in on Plan's page

The same form the platform's shell shows, against the same operation:
`POST /api/v1/auth/token { email, password }` with `credentials: 'include'`,
so the cookie the platform sets is stored for the platform's host and the
shell will resume from it too. Plan never sees the password after the
request leaves; it never stores it; it clears the field on failure. Forgot
password and address confirmation stay the platform's screens — Plan links
to them on the platform's host rather than re-implementing them.

### 5.3 Staying signed in, and leaving

- The access token is renewed 60 s before expiry through `/auth/refresh`;
  a `401` on any call triggers one renewal and one retry, then the sign-in
  form (the platform client's `renewalMiddleware`, reused by generating the
  same client).
- Sign out is `POST /api/v1/auth/sign-out`: the platform clears the cookie
  and revokes the family (ADR-038); Plan forgets the token and returns to
  its landing. There is no "sign out of Plan only".
- A session that lapsed overnight lands on the sign-in form, not on a page
  of errors (the platform fixed this on its side on 2026-09-19; Plan
  inherits the behaviour with the client).

### 5.4 What a person may do in Plan

Read from `/me/context`, cached per token until `token_expires_at`, never
from a copy Plan keeps:

| Question | Answered by | Plan gates on |
| --- | --- | --- |
| may this person use Plan at all? | `capabilities` contains `plan.access` | `isEntitled('plan.access')` — the plan decides, so it is a capability |
| may they change what the organisation shares? | `permissions` contains the platform's `projects.write` (or Plan's own code, registered on the platform) | `can('…')` — the role decides, so it is a permission |
| how many plans may the organisation hold? | `entitlements[feature = 'plan.documents']` with `limit`, `unlimited`, `usage` | shown, and refused with the platform's own `409 QUOTA_EXCEEDED` when metering (§7) |
| which language? | `user.locale` | `setLocale()` the way the shell does (ADR-050) |

Two refusals, two sentences, as the shell says them (ADR-037): a missing
permission is answered by an administrator of the organisation; a missing
capability by an upgrade. Plan never branches on a role name, a plan name or
an offer name — that is the `gate:products` rule, moved to a product.

A person whose `/me/context` names no Plan on any tenant is told so, with
a link to the platform's catalogue for the product — Plan sells nothing
itself.

### 5.5 Coming and going

The platform's shell sends a person to Plan at `app_url?product=plan` (ADR-051
§3). Plan's landing reads nothing from the address but `product` and `lang`;
anything else in the query is ignored. Plan links back to the platform's
shell for everything that is the platform's: the subscription, the invoices,
the members, the profile — by ordinary links to the platform's host, never by
re-implementing the screen.

## 6. Plan's backend, if it has one (`plan-api`)

Optional. A Plan whose resources fit the platform's product-scoped
resources (projects, versions, assets, jobs) needs none. One that stores its
own data does, and then:

### 6.1 Deciding who is asking

Every request to `plan-api` carries the person's platform bearer in
`Authorization`. `plan-api` does **not** verify the JWT — the platform's
signing key is symmetric, and holding it would be holding the mint. It
forwards the bearer:

```text
GET https://<platform host>/api/v1/me/context
    Authorization: Bearer <the person's token>
    X-Product: plan
```

and caches the answer keyed by the token's hash until `token_expires_at`
(at most the platform's access-token lifetime, ~15 minutes), so a
membership removed on the platform is honoured within that. The platform's
`401`, `403` and `404` are relayed as they came — same code, same envelope
shape — not translated into Plan's own vocabulary.

`tenant_id` and `user_id` on every `plan-api` write come from that answer.
A body that names a tenant is ignored; a path that names one is compared to
the context's and refused with `404` when they differ — the platform's own
rule that a resource belonging to somebody else is indistinguishable from
one that does not exist.

### 6.2 Its own contract

`plan-api` has an `openapi.json` of its own, generated types and client on
its frontend, and the same gates the platform runs (`gate:openapi`,
`gate:client`, a screens-call-their-operations check). Its paths are
`/api/v1/plan/…` on Plan's origin. It documents, per operation, which
platform permission or capability it requires, and its tests assert the
refusal.

### 6.3 Storage and isolation

Plan's tables carry `tenant_id` on every row that is a tenant's; every query
filters by the context's tenant; there is no query without it. A `plan-api`
that adds a row without a tenant is a bug its test suite fails on (mirror of
`tests/Integration/RouteSurfaceTest` — every route declares its scope).

## 7. Metering (relationship 2)

When Plan's usage counts against a quota the platform sold — number of plan
documents, exports, storage — Plan reports it with its product key, from its
server, never from the browser:

```text
POST https://<platform host>/api/v1/product/tenants/{tenantId}/usage
Authorization: Bearer bpk_<key_id>_<secret>
{ "feature": "plan.documents", "quantity": 1,
  "period_start": "…", "period_end": "…",
  "idempotency_key": "<plan document id>:created" }
```

- **Before** the work when the platform must be able to refuse it: create
  the document only after `2xx`; on `409 QUOTA_EXCEEDED` Plan shows the
  platform's message and a link to the subscription screen.
- **Idempotent** by a key Plan derives from the fact itself (the document
  id and the event), so a retried job counts once.
- Batched for high-volume features (a nightly job summing storage), with one
  idempotency key per tenant and period.
- The key lives in `BACKPROD_PRODUCT_KEY` on `plan-api` only; a pure-frontend
  Plan cannot meter and therefore only sells boolean features, which is a
  legitimate offer (ADR-033's spirit: access alone is a product).

Plan reads a tenant's entitlement without a person the same way
(`GET /product/tenants/{id}/entitlements`) — for a job, not for a request a
person made, where `/me/context` already answered.

## 8. Events (relationship 3)

One endpoint, `POST https://plan.raillard.org/api/v1/plan/platform-events`,
reachable by the platform only.

### 8.1 Verifying

Before parsing the body: read `X-Backprod-Signature: t=<unix>,v1=<hex>[,v1=…]`,
compute HMAC-SHA256 over `"<t>.<rawBody>"` with `BACKPROD_WEBHOOK_SECRET`,
compare in constant time against each `v1`, refuse when none matches or
when `|now − t| > 300 s`. The raw bytes as received, never a re-serialised
body (the platform's own `StripeWebhookController` is the reference: same
scheme, same pitfalls). A refused delivery answers `400`; the platform does
not retry a `400`.

### 8.2 Idempotency

`event_id` is stored before the handler runs (`plan_platform_events(event_id
primary key, type, received_at, handled_at)`); a second delivery of a known
id answers `200` and does nothing. The platform retries on timeout, so a
delivery that arrives twice is normal, not an error.

### 8.3 What Plan does with each event

| Event | Plan's reaction |
| --- | --- |
| `subscription.started` | nothing to unlock — entitlements are read live; at most, warm a cache |
| `subscription.changed` | drop the tenant's cached entitlements |
| `subscription.cancellation_scheduled` | show the end date in Plan where the organisation's administrators see it (read from context; the event only invalidates) |
| `subscription.ended` | the tenant's plans become **read-only** in Plan; nothing is deleted — the data is the customer's, the service is what ended |
| `member.added` / `member.removed` | drop that person's cached context; a removed person's open session is refused by the platform on the next call anyway |
| `tenant.product.unassigned` | read-only, as above; export stays available for the retention period the operator sets |
| `user.erased` | **blank every copy of the person's name and email** Plan holds, keep the rows keyed by `user_id` (so the tenant's documents keep their author as "someone since erased"), answer `200` only once that is done — the platform records the acknowledgement as the receipt of §26 |

Events never carry money, credentials or a person's details beyond ids;
anything richer Plan fetches through §7's routes, where the read is
authorised and logged.

## 9. Language (ADR-050, applied to Plan)

Plan speaks the five languages the platform speaks, with the same
mechanism: `t('English sentence')`, catalogues keyed by the English, one
chunk per language, a `gate:i18n` of its own. The language comes from
`?lang=`, else the browser's memory, else the browser, else English; once
`/me/context` answers, `user.locale` applies unless the address named one.
Dates and amounts use the chosen locale. Plan does not offer a language
setting of its own — that is on the platform's profile, one click away.

## 10. What Plan must never do

- Hold, log, or put in a URL a platform token, a product key or a webhook
  secret; ship any of them in a bundle.
- Verify the platform's session token itself, or ask for the signing secret.
- Keep its own users, passwords, roles or memberships; keep a copy of an
  entitlement as authority.
- Decide by product, plan or offer *name*; decide by capability and
  permission code.
- Write a row without a `tenant_id` from the context; read across tenants.
- Meter from the browser, or sell a quota it cannot meter.
- Answer a webhook before verifying its signature; handle an `event_id`
  twice; delete a tenant's data because a subscription ended.
- Keep a person's name after `user.erased`.

## 11. Registration on the platform (what the operator does, once)

1. Console → Products: create `plan` (code, name), assign it to the tenants
   that will use it (ADR-047), set `app_url = https://plan.raillard.org`
   (ADR-051 milestone B).
2. Console → Catalogue for `plan`: features (`plan.access` boolean;
   `plan.documents` quota in `documents`; whatever else Plan meters), at
   least one plan, one offer, one published version, advertised
   (ADR-045's readiness path).
3. Platform `.env`: `CORS_ALLOWED_ORIGINS` gains `https://plan.raillard.org`.
4. Console → Products → Plan → Integration (milestones C/D): issue a product
   key for `plan-api`, set the webhook URL, issue the webhook secret; both
   shown once and put in `plan-api`'s environment by the operator.
5. Remove the basic authentication in front of `plan.raillard.org`.

## 12. Verification before Plan is offered

- **Contract**: Plan's generated client matches the platform's
  `openapi.json` at the pinned commit; a platform change that removes or
  renames a field fails Plan's `gate:client` before it fails a customer.
- **Sign-in and SSO**, end to end against a staging platform: sign in on the
  platform, open Plan → signed in; sign in on Plan, open the platform →
  signed in; sign out on either → out on both; a lapsed session on Plan →
  the form, not errors.
- **Refusals**: a person without `plan.access` sees the upgrade sentence; a
  USER without the write permission sees the administrator sentence; a
  tenant not holding Plan gets the "holds no Plan" page.
- **Metering**: a quota reached → `409` → the document is not created;
  a retried report counts once.
- **Events**: a bad signature → `400`; a stale `t` → `400`; a duplicate
  `event_id` → `200`, no second effect; `user.erased` → no name left in a
  database dump.
- **Bundle**: no `bpk_`, no secret, no platform token in the built assets;
  the headers of §3 present on the live host.
