# Tenants at their own URL root — specification and complexity

**Status:** proposed, not scheduled. Written 2026-09-17 at the operator's
request, before any code. Nothing below is built.
**Reading confirmed with the operator (revised the same day):** *the tenant
stays a customer of the platform* — `acme` is an organisation that buys
from the platform, its users are its own people, invoices are issued by
the product's supplier to acme, exactly as today. What changes is where a
tenant lives (its own URL root), how its people arrive (self-service, as
`USER`), who creates it (the platform), and how its entitlement can be
given. `hostname/` is *the operator's own tenant*; every other one lives
at `hostname/{slug}/`.
**Relates to:** Architecture V2 §12.1 (root context), §12.2 (roles), §13.1
(subscriptions, seats, entitlements), §24 (payments), §25 (invoices), §25.3
(VAT); ADR-013/015 (context resolution), ADR-017 (users), ADR-041
(storefront), ADR-047 (tenant products).

---

## 0. The eight points, as asked

1. A tenant has its own URL root: `hostname/acme/`, `hostname/globex/`;
   the default tenant uses `hostname/`.
2. The catalogue is the starting page at each tenant root, and where sign-out
   returns to.
3. Platform administrators create and manage tenants, and nobody else does.
4. Every self-service sign-up arrives as `USER`, never `TENANT_ADMIN`.
5. An invoice is generated before payment, so the PDF must state its status
   plainly.
6. A user cannot initiate a refund, a credit note, or issue an invoice.
7. VAT periods, rates and regime are at tenant level.
8. A tenant's entitlement to a product can be **given by the platform
   administrator** — not only bought.

## 1. What actually changes, and what does not

```text
Platform ──sells / grants──▶ Tenant (customer organisation, at /slug/)
                              └── members: TENANT_ADMIN buys and runs it,
                                           USER works — and arrives by itself
Invoice: supplier = the product's billing identity      (unchanged)
         customer = the tenant's billing profile        (unchanged)
Money:   one Stripe account, the platform's             (unchanged)
VAT:     the tenant's regime and periods across its products (moves, §2.7)
Storefront: one per tenant at its root, showing what that tenant may buy
Sign-up: joins the tenant at the root as USER; never creates a tenant
```

The axis of the platform does **not** turn. The sales chain, the payment
gate, the webhook pipeline, gapless numbering, the ledger, the customer
workspace in the console, notifications: unchanged. There is no money-flow
decision to take. What this specification adds is *addressing* (a root per
tenant), *arrival* (self-service membership), *creation* (by staff) and two
readings of what a tenant may use (granted, and reported).

## 2. Point by point

Complexity is in developer-days for this codebase, tests and docs included,
by somebody who knows it. **S** ≤ 1, **M** 2–4, **L** 5–8.

### 2.1 A URL root per tenant — **M–L (4–6 days)**

**Today.** The SPA lives at `/`; the tenant is derived from the membership
behind the token (ADR-015), with `X-Tenant` when a person belongs to several.
Public routes (storefront, sign-up) know no tenant.

**Target.** `hostname/{slug}/…` for every tenant, `hostname/…` for the
default one. The slug is *addressing*: for a stranger, which organisation's
shop window; for a member, which of their memberships this page is about.

**Design.**

- **Server:** a `TenantRoot` step in the context chain, before tenant
  resolution. The slug on the path — or none, meaning the default tenant, a
  platform setting `platform_settings.default_tenant` — names the
  *asked-for* tenant. For a member it must match a membership, else `403`.
  It never grants anything, so ADR-015 holds: the tenant is still derived
  from membership, the slug only chooses among them, as `X-Tenant` does
  today. The API keeps `/api/v1/...`; the client sends the slug as
  `X-Tenant` (decision D3). Public routes gain the tenant:
  `GET /api/v1/public/{slug}/offers`, `POST /api/v1/auth/sign-up` with the
  slug in the body.
- **Client:** one route tree with an optional leading `$tenant` segment
  resolved once at boot — the first path segment that is a known slug,
  confirmed by `GET /api/v1/public/tenants/{slug}` (name, branding) — held
  in the session store beside the product and sent as `X-Tenant`. Every
  `Link` and `navigate` goes through one helper that prefixes the root.
  `?product=` stays a deep link; the default product (2026-09-17) stays the
  fallback.
- **Hosting:** the `.htaccess` rewrite already sends every non-file path to
  `index.html`; nothing changes. Slugs are checked against the
  application's own first segments (`console`, `sign-in`, `api`,
  `checkout`, …) — a tenant cannot be called `console`.
- **Sign-out** returns to the root the person was in (2.2).

**Risks.** Every `to="/…"` in the frontend (≈120 links, the navigation
table, the palette, the e2e specs) goes through the root helper; the
coverage map and `gate:screens` are untouched because no operation moves.
The refresh cookie is host-wide, so one browser is one person across
tenants — right: a person is one account with several memberships.

### 2.2 The tenant's catalogue as its starting page — **S–M (2–3 days)**

**Today.** ADR-041: the storefront at `/` is the platform's shop window,
per product, for strangers; a signed-in member lands on "Choose an area".

**Target.** `hostname/acme/` shows the catalogue **for acme**: the offers
on sale for the products acme holds (ADR-047), painted with acme's
branding. A stranger sees prices and a "Sign in / Sign up" line; a member
lands there too, with *Buy* where their permission allows (a `TENANT_ADMIN`)
and prices only where it does not (a `USER`). Sign-out returns here.

**Design.** `listPublicOffers` takes the tenant and lists what that
tenant's products advertise (the same windows, narrowed to its holdings);
the `Storefront` and the member's `CatalogueScreen` become one screen with
two authorities — the stranger's and the member's — rendered from the same
rows, which is how the console already treats a support thread (ui-spec
§2). The landing route for a member becomes the catalogue rather than
"Choose an area".

### 2.3 Platform administrators create and manage tenants — **S–M (2–3 days)**

**Today.** Staff list tenants, assign products, lend offer authoring,
browse a customer (R14). Nobody *creates* a tenant except sign-up and the
installer.

**Target.** `POST /api/v1/staff/tenants` (name, slug, products, the first
`TENANT_ADMIN` by user id or by an invitation to an address), `PATCH`
(rename, re-slug against the reserved list, mark as default),
`staff.tenants.manage`. Sign-up stops creating tenants (2.4). The installer
and the demo world go through the same path. The console's Tenants screen
gains "New tenant" and the default marker.

**Risk.** Re-slugging moves an organisation's URL: old links die. Refuse
it once the tenant has an issued invoice, or keep a `tenant_slug_history`
for a redirect. The refusal is one line; recommended.

### 2.4 Self-service arrives as USER of the tenant at the root — **M (3–4 days)**

**Today.** Sign-up creates *a tenant* with the person as `TENANT_ADMIN`
(ADR-041, six rows). A `USER` is somebody a tenant administrator invited.

**Target.** Sign-up at `hostname/acme/` creates the user and a `USER`
membership of acme on every product acme holds (mirrored, ADR-047) — and
nothing else. No tenant, no billing profile, no `TENANT_ADMIN`. Buying
stays the administrator's: a `USER` sees the catalogue and cannot check
out (`billing.manage`), which is point 6 by construction.

**The question this raises is not code: who may join.** An open sign-up
at `hostname/acme/` lets any stranger become a member of acme — with
`projects.read`, `billing.read`, `members.read` — by typing an email
address. That is a tenant-isolation hole (§"Multi-tenancy and security"),
not a convenience. So sign-up *asks* to join, and the tenant's **join
policy** decides — decision D2:

| Policy | What happens at sign-up |
|---|---|
| `INVITATION` | refused unless an invitation to that address exists (the members screen already invites by email) |
| `DOMAIN` | accepted when the address's domain is on the tenant's allow-list (`@acme.test`), refused otherwise |
| `APPROVAL` | the account is created and the membership is `PENDING`; a `TENANT_ADMIN` accepts or declines from the members screen; until then the person can sign in and sees only "your request is waiting" |

Recommended default: `APPROVAL`, with `DOMAIN` as the setting an
administrator switches on once they have seen who turns up. `OPEN` is not
offered. Whatever the policy, the address is confirmed by the existing
email verification before a membership becomes live.

**Design.** `tenants.join_policy` + `tenant_join_domains`;
`tenant_members.status` (`ACTIVE | PENDING`) with the resolver treating
`PENDING` as no membership; sign-up takes the slug and returns `201` with a
session and `membership: PENDING|ACTIVE`; the members screen lists
requests with accept/decline (`members.manage`); a `member.requested`
notification (§27.1) to the tenant's administrators. The existing
`EMAIL_TAKEN` becomes "sign in, then ask to join" for a known address
joining a second organisation — one user, several memberships, as today.

### 2.5 The invoice PDF states its status — **S–M (1–2 days)**

**Today.** The PDF is rendered at issue and stored once; it shows the due
date, never whether it was paid.

**Target.** "Facture — **en attente de paiement**, échéance le …" at issue;
after payment "**Payée le …**, par carte, référence …"; past due and unpaid,
it says so.

**Design.** The stored PDF is the legal document and does not change (§25:
what was issued is what was issued). Payment status is not part of its
content, so the download endpoint serves the stored PDF with a **status
band** composed at download time (an mPDF overlay on page one, or a
re-render from the same snapshot with the status block filled) — the body
never differs from the issued one, only the band on top. The status comes
from `invoices.status` and the settling payment, as the screen shows it.
One test renders the three states and asserts the words. "Acquittée" is the
customary French word once paid; the platform prints "Payée le …" with the
payment reference, which is what an auditor asks for.

### 2.6 A user cannot refund, credit, or issue — **S (½ day)**

Already true: `USER` holds `billing.read`, `payments.read`, `sales.read`
and none of the `*.manage` permissions those acts require, so the API
refuses and the screens hide. The change is to *say* it — in
`identities-and-permissions.md`, in a test that asserts the `USER` matrix
never gains a `*.manage`, and under 2.4 in the sign-up copy ("you will be
able to see, not to buy").

### 2.7 VAT periods, rates and regime at tenant level — **M (2–4 days)**

**Today.** The customer's tax profile (B2B/B2C, country, VAT number) is per
tenant already, and it is what decides the regime and the rate of every
sale to that tenant (§25.3). VAT transactions, reporting periods and
declarations are recorded per `(tenant, product)`, and the tenant's *VAT
periods* screen reads one product at a time.

**Target.** A tenant's fiscal picture is **one**, across the products it
holds: the regime and rate the tenant is under (from its profile — one
profile, already), and its VAT periods and declarations *for the whole
organisation*, not per product. The supplier's side (the product's
country, OSS registration, supply type — `product_configuration.tax`)
stays where it is: it is the seller's position, not the customer's.

**Design.** `vat_reporting_periods` and `vat_declarations` re-keyed on the
tenant alone (a period row per tenant per month; transactions keep their
`product_id` and roll up); `listVatPeriods`, `showVatReport`,
`closeVatPeriod`, `exportVat` lose their product narrowing and answer
across the tenant's holdings; the *VAT periods* screen drops the product
switcher's effect. Closed periods stay immutable and corrections still go
into a later period. Historical transactions keep the rate and rule they
recorded; nothing is recomputed. Migration: existing per-product periods
of the same month merge into one tenant period if none is closed, and are
kept side by side (read-only, flagged) if one is.

### 2.8 Product entitlement given to the tenant by the platform administrator — **M (3–4 days)**

**Today.** A tenant *holds* a product by staff assignment (`tenant_products`,
ADR-047) — reachability, nothing more. What it may *use* comes from a
subscription: `entitlements` rows written when an offer version is
activated (`source = SUBSCRIPTION`), with `OVERRIDE` for a negotiated
exception. A tenant that holds a product it has not bought cannot use it.

**Target.** The platform administrator can give a tenant its entitlement
to a product directly — which features, until when — without a sale: a
pilot, a partner, an internal organisation, the operator's own default
tenant. Buying stays the ordinary road; this is the other one.

**Design.**

- `entitlements.source` gains **`GRANT`** beside `SUBSCRIPTION` and
  `OVERRIDE`; `subscription_id` null; `granted_by` (staff user id) and
  `valid_until` (nullable). The resolver already reads entitlements by
  `(tenant, product)` whatever the source, so capability checks, quotas and
  `/me/entitlements` change nothing.
- **Staff API:** `PUT /api/v1/staff/tenants/{tenantId}/products/{productId}/entitlement`
  `{plan?: code, features: [codes], valid_until?}` — a plan is a shorthand
  for its feature set, resolved into rows at grant time as an offer
  version's grants are at activation; `DELETE` withdraws.
  `staff.tenants.manage`, recorded in the access log with the feature list —
  a grant is a commercial decision somebody should be able to trace.
  Assigning a product (ADR-047) and granting its entitlement stay two acts:
  the first says the organisation may see the product, the second what it
  may do with it.
- **Console:** the tenant workspace's Overview gets an *Entitlement* panel
  per held product — features ticked, expiry, grantor — beside the product
  assignment. The tenant's own Subscription screen shows a granted
  entitlement as "provided by the platform until …" rather than as a
  subscription it could cancel.
- **Where both exist:** a grant and a subscription on the same product
  resolve as the most generous, the rule §13.1 already applies between a
  seat and the tenant. Expiry of a grant is a lapsed entitlement, swept by
  `expireLapsed()`; nothing is invoiced and nothing renews itself — a grant
  is renewed by a person.

## 3. Order of work

Each phase leaves `main` deployable and every gate green; none of them
turns any axis, so they can land one PR at a time.

| Phase | Content | Days |
|---|---|---|
| 1 | Tenant roots (2.1): resolver, root helper, default tenant setting, reserved slugs, sign-out to root | 4–6 |
| 2 | Staff create/manage tenants (2.3); sign-up no longer creates one; installer and demo world through the new path | 2–3 |
| 3 | Self-service joins as USER (2.4): join policy, pending memberships, requests on the members screen, notification | 3–4 |
| 4 | Catalogue at the root (2.2): per-tenant public window, one screen two authorities, member landing | 2–3 |
| 5 | Entitlement granted by staff (2.8) | 3–4 |
| 6 | VAT across the tenant (2.7) | 2–4 |
| 7 | PDF status (2.5); the USER matrix said and tested (2.6); docs and ADRs (ADR-049 "a tenant lives at its own root and its people arrive by themselves"; amendments to ADR-041 and ADR-047) | 2–3 |
| | **Total** | **18–27 days** |

Phases 1–2 first, together: a root per tenant is only worth having once
staff can make tenants. Phase 3 depends on both. 4–7 are independent of
each other.

## 4. What this touches that is not obvious

- **ADR-041's promise** ("create your account as part of the purchase")
  changes meaning: the account is created *at an organisation*, and the
  purchase is that organisation's administrator's to make. A stranger who
  wants their own organisation asks the operator (2.3) — or, if the
  operator wants self-serve organisations back one day, that is a fourth
  join policy (`SELF_SERVE_ORGANISATION`) reinstating today's six rows,
  behind the default tenant's root only.
- **The console's customer workspace** (R14) is unchanged in what it reads;
  its Members tab gains the pending requests.
- **Erasure** (§16): a pending member is a user like any other.
- **Metrics**: unchanged — revenue is still per product, customers are
  still tenants.
- **Demo world**: two roots (`/` for Acme as the operator's own, `/globex/`),
  Grace re-seeded as a self-service joiner approved by Ada, one granted
  entitlement (Globex on Boreas, "provided by the platform").

## 5. Decisions to take before starting

- **D1** — which tenant is the default at `hostname/`: the operator's own
  (assumed), chosen in the platform settings beside the menu setup.
- **D2** — the join policy default: `APPROVAL` recommended; `DOMAIN` as the
  switch; no `OPEN`.
- **D3** — the tenant on API calls: `X-Tenant` carrying the slug (the header
  exists; the client sets it once from the root).
- **D4** — re-slugging: refused once an invoice exists (assumed).
