# Tenants become merchants — specification and complexity

**Status:** proposed, not scheduled. Written 2026-09-17 at the operator's
request, before any code. Nothing below is built.
**Reading confirmed with the operator:** *the tenant sells to its users* —
`acme` is a merchant hosted by this platform, its users are its customers,
its invoices are issued by acme; and `hostname/` is *the operator's own
tenant*, every other one lives at `hostname/{slug}/`.
**Relates to:** Architecture V2 §12.1 (root context), §12.2 (roles), §13.1
(subscriptions and seats), §24 (payments), §25 (invoices), §25.3 (VAT);
ADR-013/015 (context resolution), ADR-017 (users), ADR-024 (payment-gated
activation), ADR-040 (offer authoring lent), ADR-041 (storefront), ADR-047
(tenant products), ADR-048 (Stripe).

---

## 0. The seven points, as asked

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

Taken one by one, five of the seven are small. Taken together with the
reading above, they are **one change**: the axis of the platform turns.

## 1. What actually changes

Today:

```text
Platform ──sells──▶ Tenant (customer organisation, one billing profile)
                     └── members: TENANT_ADMIN buys, USER works
Invoice: supplier = the product's billing identity (product_configuration)
         customer = the tenant's billing profile
Money:   one Stripe account, the platform's
VAT:     supplier position per product; customer profile per tenant;
         numbering one per-year sequence for the whole platform
Storefront: the platform's window, per product; sign-up creates a tenant
```

Tomorrow:

```text
Platform ──hosts──▶ Tenant (merchant, at /slug/) ──sells──▶ User (its customer)
                     └── members: TENANT_ADMIN runs the shop, USER buys
Invoice: supplier = the tenant's billing identity
         customer = the user (a person, or a company they represent)
Money:   the tenant's — see §3, the decision that sizes everything
VAT:     supplier position, rates, periods per tenant; customer profile per user;
         numbering one gapless sequence per tenant
Storefront: one per tenant at its root; sign-up joins the tenant as USER
```

What does **not** change, and the design leans on it:

- Product stays the root context (§12.1). A tenant holds products (ADR-047);
  a merchant sells subscriptions to the products it holds. `X-Product` stays.
- Non-negotiable #22: a platform role never grants membership. Staff manage
  tenants through `/staff/*` with the tenant as a parameter, as today.
- Seats exist already (§13.1 `subscriber_kind = USER`, `{"seat": true}` on
  subscribe, per-person entitlement resolution). A user buying for themselves
  is a seat. This is the piece of luck in the whole change.
- The sales chain (quote → order → invoice → payment → activation), the
  payment gate (ADR-024), the webhook pipeline, the ledger, gapless numbering,
  the job queue, notifications: all keep their shape. What moves is *who* is
  on each end of an invoice, and *whose* settings price it.

## 2. Point by point

Complexity is in developer-days for this codebase, tests and docs included,
by somebody who knows it. **S** ≤ 1, **M** 2–4, **L** 5–8, **XL** > 8.

### 2.1 A URL root per tenant — **M–L (4–6 days)**

**Today.** The SPA lives at `/`; the tenant is derived from the membership
behind the token (ADR-015), with `X-Tenant` when a person belongs to several.
Public routes (storefront, sign-up) know no tenant at all.

**Target.** `hostname/{slug}/…` for every tenant, `hostname/…` for the
default one. The slug is *addressing*: it says which merchant's shop, and
for a signed-in member which of their memberships.

**Design.**

- **Server side:** a `TenantRoot` resolver in the context chain, before
  tenant resolution: the slug on the request path (or none → the default
  tenant, a platform setting `platform_settings.default_tenant`) names the
  *asked-for* tenant. For a member it must match a membership, else `403` —
  it never *grants* anything, so ADR-015 holds: the tenant is still derived
  from membership, the slug only chooses among them, exactly as `X-Tenant`
  does today. The API keeps `/api/v1/...` with the slug carried as `X-Tenant`
  by the client (already in the contract); public routes gain a
  `tenant` parameter (`/api/v1/public/{slug}/offers`, or the header — see
  decision D3).
- **Client side:** TanStack Router `basepath` per tenant is not enough,
  because the default tenant has none. One route tree with an optional
  leading `$tenant` segment resolved once at boot (the first path segment
  that is a known slug — the client asks `GET /api/v1/public/tenants/{slug}`
  or reads it from the storefront reply), stored in the session store beside
  the product, and sent as `X-Tenant`. Every `Link` and every `navigate`
  goes through one helper that prefixes the root. `?product=` stays a deep
  link; the *default product* (2026-09-17) stays the fallback.
- **Hosting:** `.htaccess` rewrites already send every non-file path to
  `index.html`; nothing changes there. Slugs are reserved against the
  application's own first segments (`console`, `sign-in`, `api`, `checkout`,
  …): a tenant cannot be called `console`. The slug rule gets a CHECK and a
  reserved list.
- **Sign-out** returns to the tenant root the person was in (point 2).

**Risks.** Every existing `to="/…"` in the frontend (≈120 links, the
navigation table, the palette, the e2e specs) is touched by the root
helper; `gate:screens` and the coverage map stay as they are because
operations do not move. Cookie scope: the refresh cookie is host-wide, so
one browser is one person across tenants — that is right (a person is one
account with several memberships).

### 2.2 The tenant's catalogue as its starting page — **M (3–4 days)**

**Today.** ADR-041: the storefront is the platform's shop window, per
product, listing offers a product advertises; a tenant may be *lent*
offer authoring (ADR-040) for the offers it may sell.

**Target.** `hostname/acme/` shows acme's catalogue: the offers acme sells,
for the products acme holds. A stranger sees it; a signed-in user sees it as
their landing page with "Buy" leading to a seat.

**Design.** The public window becomes per tenant:
`listPublicOffers(slug)` returns offers *owned by* the tenant (offer
authoring stops being lent and becomes the merchant's own — ADR-040's
`may_author_offers` flips from exception to rule; the platform's own offers
are the default tenant's). The tenant's branding (skin, already per tenant)
paints its root. `Storefront` and `SignUpForm` take the tenant from the
root. Sign-out lands on the root (2.1).

**Decision D1** — are offers per tenant, or platform offers that a tenant
*lists*? Per tenant is what "the tenant sells" means and is assumed; a
merchant reselling platform-authored offers is a different product (a
marketplace with a catalogue owner) and is not specified here.

### 2.3 Platform administrators create and manage tenants — **S–M (2–3 days)**

**Today.** Staff list tenants, assign products, lend offer authoring, browse
a customer (R14). Nobody *creates* a tenant except sign-up and the installer.

**Target.** `POST /api/v1/staff/tenants` (name, slug, products, the first
`TENANT_ADMIN` by user id or invitation), `PATCH` (rename, re-slug with the
reserved list, mark default), `staff.tenants.manage` as today. Sign-up stops
creating tenants (2.4). The demo world seeds the two tenants through the
same path. The console's Tenants screen gets "New tenant" and the default
marker; the *Menus* setup gains nothing.

**Risk.** Re-slugging moves a merchant's URL: old links die. Keep a
`tenant_slug_history` for a redirect, or refuse re-slugging once the
tenant has a paid invoice. The latter is one line; recommended.

### 2.4 Self-service arrives as USER of the tenant at the root — **XL (8–12 days)**

This is where the axis turns, and most of the effort is here.

**Today.** Sign-up creates *a tenant* with the person as `TENANT_ADMIN`, a
billing profile for it, and a membership on the product signed up for
(ADR-041, six rows). A `USER` is somebody a tenant admin invited.

**Target.** Sign-up at `hostname/acme/` creates a user and a `USER`
membership of acme on the products acme holds (mirrored, ADR-047), nothing
else. Then that user buys **for themselves**: a seat (`{"seat": true}`),
invoiced to *them*.

**What has to exist for an invoice to be addressed to a user:**

| Piece | Today | Change |
|---|---|---|
| Customer identity on an invoice | `billing_profiles` per tenant, snapshotted at issue | **`user_billing_profiles`** (legal name or person's name, address, VAT number for a customer who is a company) per user *per tenant* — a person may buy from two merchants under two identities; snapshotted at issue as today |
| `invoices.customer` | the tenant (`tenant_id` is both scope and customer) | `invoices.customer_user_id` nullable: null = the tenant itself is the customer (the existing model, kept for the default tenant's B2B sales and for migration), set = a member |
| Orders, quotes, payments, credit notes | scoped `(tenant, product)` | gain `customer_user_id` the same way; a `USER` reads **only their own rows** — a row filter in the repositories keyed on the caller, the way seats already filter entitlements by person (§13.1). Not a new permission: `billing.read` means "your invoices", and for a `TENANT_ADMIN` "the shop's" |
| Checkout | places an order for the tenant | places an order for the caller as a seat; `Checkout::open` passes the subscriber; the one-live-subscription refusal (2026-09-17) becomes per seat (the index already is) |
| Tax profile (customer side) | per tenant | per user-per-tenant, inside `user_billing_profiles`; B2B when a VAT number is verified, B2C otherwise; the quote-only-for-B2B rule follows the customer, whoever it is |
| Notifications | recipient by user already | unchanged |
| Screens | Invoices, Payments, Orders, Quotes, Subscription for the tenant | the same screens, filtered to *mine* for a `USER`; the `TENANT_ADMIN` sees the shop's, with the customer named on each row |

**Migration of what exists.** Every current tenant is a customer of the
platform. Under the new model they become merchants with no customers, and
the platform's own sales to them become the **default tenant's** sales, to
each tenant's administrator as a customer? That is not the same contract
the invoices were written under. The honest path: existing invoices keep
`customer_user_id = NULL` (customer = the tenant, as issued) and are
readable by that tenant's admin, exactly as today; new sales follow the
new model. Both shapes coexist by the nullable column, with no rewrite of
issued documents — the invoice snapshot rule (§25) applied to the
migration.

**Decision D2** — can a `TENANT_ADMIN` still buy on behalf of the shop
(the tenant as customer of the platform, e.g. the hosting fee)? If the
platform charges its merchants, yes, and that is the default tenant selling
to the other tenants — the existing model, which the nullable column keeps.
Assumed yes.

### 2.5 The invoice PDF states its status — **S–M (1–2 days)**

**Today.** The PDF is rendered at issue and stored once; it shows the due
date, never whether it was paid.

**Target.** "Facture — **en attente de paiement**, échéance le …" at issue,
and after payment "**Payée le …**, par carte, référence …".

**Design.** The stored PDF is the legal document and does not change
(§25: what was issued is what was issued). The *payment status* is not part
of its content, so the download endpoint serves the stored PDF with a
**status band** composed at download time (mPDF overlay on page one, or
regeneration from the same snapshot with the status block filled) — the
document body never differs from the issued one, only the band on top of
it. The status comes from `invoices.status` and the settling payment, as the
screen shows it. An unpaid invoice past due says so. One test renders the
three states and asserts the words.

**Note for the operator.** In France an invoice issued before payment is
normal; what matters is that the mention is not misleading. "Acquittée" is
the customary word once paid; the platform keeps "Payée le …" and the
payment reference, which is what an auditor asks for.

### 2.6 A user cannot refund, credit, or issue — **S (½ day)**

Already true: `USER` holds `billing.read`, `payments.read`, `sales.read` and
none of the `*.manage` permissions those acts require. The change is to
*say* it — in `identities-and-permissions.md`, in a test that asserts the
`USER` matrix never gains them, and in the screens, which already hide by
permission. Under 2.4 a `USER` also cannot mark an invoice paid by hand.

### 2.7 VAT periods, rates and regime at tenant level — **L (5–7 days)**

**Today.** The supplier's fiscal position (`country`, `oss_registered`,
`supply_type`, `currency`) is `product_configuration.tax`, per product; VAT
transactions and reporting periods are per `(tenant, product)` where the
tenant is the *customer*; rates are a platform table by country and window;
the customer's profile is per tenant.

**Target.** The tenant is the supplier, so its fiscal position, its
reporting periods and its declarations are its own: `tenant_tax_settings`
(the four fields above), `vat_reporting_periods` and `vat_declarations` keyed
by tenant-as-supplier, the customer's profile per user (2.4). Rates stay a
platform table (a rate is a fact about a country, not about a merchant) —
"rates at tenant level" is read as *which rate applies to this merchant's
sale*, decided by the merchant's country and OSS registration, which is
what moves.

**Design.** `Taxation` takes a `Supplier` resolved from the tenant instead
of from the product; `SupplierIdentity::forProduct` becomes `forTenant`;
the console's *Invoicing* screen (per product) becomes the tenant's
*Invoicing* screen (billing identity + tax position, per tenant, edited by
its `TENANT_ADMIN` and readable by staff through the workspace). VAT
reports move under the tenant's Money section. Historical transactions keep
the rate and rule they recorded (§25.3): nothing recomputes.

**Numbering.** One gapless sequence per supplier: `DocumentNumbering`
counts within the tenant (`WHERE tenant_id = :tenant AND number LIKE …`),
and the prefix may carry the tenant's own series. Existing numbers stay.

## 3. The decision that sizes everything: whose money

Today one Stripe account, the platform's, takes every payment. If acme
sells to its users, who is paid?

| Option | What it means | Complexity |
|---|---|---|
| **A. The platform collects for everybody** | One Stripe account; the platform owes each merchant its takings; payouts are outside the platform (a report) | **S** in code, but the platform becomes a payment intermediary — a regulatory question (PSD2 agent/marketplace exemptions) the operator must settle with counsel before choosing it |
| **B. Each tenant brings its own Stripe account** | Keys per tenant (`tenant_settings`, pasted by the tenant admin, never seen by anybody else), webhook endpoint per tenant or one endpoint resolving the account from the event, idempotency keys per tenant (already per installation) | **M (3–5 days)**; the platform never touches money; each merchant is a Stripe customer; the console's readiness chain gains "payment provider configured" per tenant |
| **C. Stripe Connect** | The platform onboards merchants as connected accounts, takes payments on their behalf with an application fee, Stripe handles payouts and KYC | **L–XL (8–15 days)** plus Connect onboarding UX; the right answer for a marketplace at scale, and the most to build |

**Recommendation:** B. It keeps the platform out of the money (the stance
ADR-048 already takes), reuses the adapter whole, and turns "configure
Stripe" into a per-tenant setup step the readiness screen already knows
how to show. A later move to C replaces the per-tenant key with a connected
account id in the same table.

## 4. Order of work

Each phase leaves `main` deployable and every gate green.

| Phase | Content | Days |
|---|---|---|
| 1 | Tenant roots (2.1), sign-out to root, default tenant setting, reserved slugs | 4–6 |
| 2 | Staff create/manage tenants (2.3); sign-up no longer creates one; demo world through the new path | 2–3 |
| 3 | Supplier moves to the tenant (2.7): billing identity, tax position, numbering per tenant, VAT periods per tenant | 5–7 |
| 4 | Customer becomes the user (2.4): profiles, nullable customer on documents, row filters for USER, checkout as seat, screens | 8–12 |
| 5 | Money per tenant (3.B), readiness per tenant | 3–5 |
| 6 | Per-tenant storefront (2.2), offers owned by the tenant | 3–4 |
| 7 | PDF status (2.5); the USER matrix said and tested (2.6); docs, ADRs (ADR-049 "a tenant is a merchant", amendments to 013/015/040/041/047/048), demo world walk-through | 2–3 |
| | **Total** | **27–40 days** (with option C for money: 35–50) |

Phases 1–2 are worth doing on their own; 3–6 are the axis turn and belong
together, behind a feature flag or on a branch that lands whole, because
the platform in between — supplier moved, customer not yet — would issue
invoices from the tenant to itself.

## 5. What this breaks that is not obvious

- **ADR-041's sign-up promise** ("you create your account as part of the
  purchase") survives, but the account is *at a merchant*: a person who buys
  from two merchants signs up twice and is one user with two memberships.
  Fine, as long as the email is the same; a second sign-up with a known
  address joins rather than refuses (`EMAIL_TAKEN` becomes "sign in to
  join").
- **The console's customer workspace** (R14) reads a tenant's payments,
  invoices, orders: those are now the merchant's *sales*, with a customer on
  each. Same screens, one more column, and the access-log motive still
  applies — staff are reading the merchant's customers.
- **Erasure** (§16, #15): erasing a user who is a merchant's customer strips
  identity from *that merchant's* accounting records, tenant by tenant; the
  legal-retention grounds are unchanged.
- **E-invoicing** (§25.1): the supplier on a transmission becomes the tenant;
  a merchant needs its own e-invoicing registration or the platform's as a
  PDP acting for it — decision D4, deferred with the e-invoicing provider.
- **Metrics** (`admin.finance.read`): revenue per product becomes revenue per
  merchant per product; the aggregates are re-keyed, not re-invented.

## 6. Decisions to take before starting

- **D1** — offers are owned by the tenant (assumed yes).
- **D2** — the platform still sells to its merchants through the default
  tenant (assumed yes; the nullable customer keeps it).
- **D3** — the tenant on API calls: `X-Tenant` with the slug as value
  (assumed; the header exists and the client sets it once).
- **D4** — e-invoicing supplier per merchant: deferred.
- **Money** — §3, option B recommended; this one decides the estimate and
  is not a code question.
- **Naming** — "tenant" stays the word in code and contract; the screens
  say *organisation* to a merchant's staff and nothing to a customer, as now.
