# ADR-049 — A tenant lives at its own root, and its people arrive by themselves

**Status:** accepted; amended 2026-09-18 — **a USER can buy.** The operator
read self-service as "sign up at the root and pay there and then", which two
things below contradicted: the default join policy `APPROVAL` (the newcomer
waited) and the checkout behind `billing.manage` (which also issues invoices
and credit notes). So: `OPEN` is a fourth join policy and the default —
organisations that never chose one moved to it; paying is its own permission,
`billing.pay` (checkout, pay an invoice, retry a payment), held by both
tenant roles; `subscription.manage` is held by USER too; issuing, cancelling,
crediting, marking paid by hand and refunding stay `billing.manage` /
`payments.manage`, the administrator's. The storefront opens the checkout on
the session the sign-up issued when the membership is live and an offer was
chosen, and goes to the root to wait otherwise. `PublicTenant.join_policy`
lets the form say which before the person types. Migration
`Version20260918090000`. The paragraphs "A USER sees and cannot bind" and
"the door is a request to join, not a purchase" below read as they were
decided on 2026-09-17; this note is what stands.
**Implements:** [docs/tenant-roots.md](../tenant-roots.md), with the
departures recorded in §"What the spec had wrong"
**Amends:** [ADR-041](ADR-041-the-storefront-sells-to-strangers.md) (the
storefront is per organisation, and its door is a request to join rather
than a purchase); [ADR-047](ADR-047-a-tenant-has-products-and-the-console-chooses-one.md)
(a tenant is made by the platform, and may be *given* an entitlement as
well as assigned a product); [ADR-015](ADR-015-tenant-resolution.md) (the
slug selects among memberships); `docs/ui-spec.md` §3.5, §4.2;
`docs/identities-and-permissions.md`
**Relates to:** [ADR-013](ADR-013-product-context-resolution.md);
[ADR-038](ADR-038-this-platform-issues-its-own-sessions.md);
[ADR-039](ADR-039-the-installer-appoints-the-first-administrator.md);
[ADR-046](ADR-046-one-shell-two-authorities.md);
Architecture V2 §10.6, §12.1, §12.2, §13.1, §25, §25.3; non-negotiables
#13, #21, #22

## Context

Until 2026-09-17 the platform had one front door. `/` was the shop window
for whichever product was named, a stranger who bought became *a tenant*
— six rows: user, credential, tenant, billing profile, membership,
`TENANT_ADMIN` — and every organisation lived behind the same address,
distinguished only by the membership a session resolved. That was right
for a platform selling to individuals and wrong for one whose customers
are organisations with people in them: the second person at Acme had no
address to arrive at, no way to ask to be let in, and the operator of the
platform had no way to make Acme except by signing up as it.

The operator asked for eight things at once (the spec lists them). This
ADR records the shape they took and the three places the spec's premise
turned out to be wrong.

## Decision

**An organisation has a URL root.** `hostname/acme/` is Acme's; the bare
host is the platform's *default tenant*, a platform setting
(`platform_settings.default_tenant`), so that a single-organisation
deployment needs no slug in its links. A slug may not be one of the
application's own first path segments (`console`, `sign-in`, `checkout`,
…): the list is `CreateTenantController::RESERVED` on the server and
`RESERVED` in `src/app/root.ts` on the client, and the two are the same
list because a slug that only one of them refused would be a tenant
nobody can reach or a page nobody can open.

The client detects the root once at boot (`detectRoot`), keeps it in the
session store, builds its router under it (`buildRouter(basepath)`), and
names it on every request as `X-Tenant`. **The slug selects, it does not
grant** (ADR-013, ADR-015 unchanged): `TenantResolver` matches it against
the caller's memberships exactly as it matched an id, and a slug of an
organisation the caller is not in is refused like an unknown one.

**Organisations are made by the platform, nowhere else.**
`POST /api/v1/staff/tenants` — name, slug, products, first administrator —
and `PATCH` to rename, re-slug or mark as the default; `staff.tenants.manage`.
Re-slugging is refused once an invoice exists (`TENANT_HAS_INVOICES`): an
address printed on a legal document does not move. Sign-up stopped making
organisations, and so did the demonstration world's own shortcuts.

**Self-service arrives as a USER, by the organisation's join policy.** A
sign-up at `hostname/acme/` makes an account and a USER membership of Acme
on every product Acme holds — and nothing else: no organisation, no
billing profile, no administrator. Acme's `join_policy` decides whether
the membership is live:

```text
APPROVAL     the default — written PENDING; the administrators are told
             (member.requested) and accept or decline from the Members
             screen
DOMAIN       an address on one of tenant_join_domains is ACTIVE at once,
             any other is refused (403 JOIN_DOMAIN_NOT_ALLOWED)
INVITATION   nobody arrives by themselves (403 JOIN_BY_INVITATION)
```

A PENDING membership is not a membership: every resolver and every list
reads `status = 'ACTIVE'`, `/me` answers 403, and `listProducts` names
the organisation under `pending_memberships` so the shell can say
"waiting for Acme" rather than "nothing". Declining drops the rows and
keeps the account.

**A USER sees and cannot bind.** The matrix was already right —
`billing.read`, `payments.read`, `sales.read`, and none of the `*.manage`
those acts need — and is now said (`docs/identities-and-permissions.md`)
and held (`UserRoleMatrixTest`). The consequence for the storefront is the
one visible change of policy in this ADR: **the door is a request to join,
not a purchase**. The sign-up form carries the offer the person chose,
says that an administrator buys, and ends at the root — where the shell
opens the catalogue for a member or says "waiting". Buying is the
administrator's, on the catalogue, and the pay-through-Stripe end-to-end
test moved there with it.

**The root is the catalogue for a member.** The landing route renders the
catalogue: the same products a stranger sees in the storefront at that
root, with *Buy* where the permission allows and prices where it does not.
Signing out returns to the root, so the page a member leaves is the page a
stranger knows.

**The platform may give an entitlement, not only sell one.**
`entitlements.source` gains `GRANT` beside `SUBSCRIPTION` and `OVERRIDE`:
no subscription, a `granted_by`, an expiry or none, one row per feature.
`PUT/DELETE /staff/tenants/{id}/products/{productId}/entitlement`, on a
product the tenant *holds* — assigning says an organisation may see a
product, granting says what it may do with it, and the second without the
first would be an entitlement nobody can reach. The resolver reads a grant
exactly as it reads a subscription's rows; where both hold a feature, the
more generous wins, as between a seat and the tenant (§13.1). A lapsed
grant is a lapsed entitlement — nothing is invoiced and nothing renews
itself; a person renews it. The trail carries the feature list, because a
grant is a commercial decision somebody should be able to trace.

**The invoice document says where the money stands.** The stored PDF is
the legal document and never changes (§25). Payment is not part of its
content, so `GET /invoices/{id}/pdf` stamps a *status band* on page one at
download time — "EN ATTENTE DE PAIEMENT — ÉCHÉANCE LE …", "IMPAYÉE —
ÉCHÉANCE DÉPASSÉE LE …", "PAYÉE LE … — PAR CARTE, RÉF. …", "ANNULÉE",
"CRÉDITÉE" — composed from `invoices.status` and the settling payment, the
same facts the screen shows. The band lives in the top margin the
rendered page leaves blank, in a core font and uncompressed, so the words
can be read back out of the bytes and a test can hold the document to
them. The `ETag` names the stored checksum *and* the band, so a paid
invoice is fetched afresh.

**A tenant's fiscal history is one.** `GET /tax/transactions` answers
across every product the tenant holds, each row naming its product, and
`?product=` narrows on request rather than the header deciding.

## What the spec had wrong

Three premises in `docs/tenant-roots.md` did not survive contact with the
code, and the code is right.

1. **VAT periods and declarations are not per (tenant, product).** They
   are per *jurisdiction*, platform-wide: the supplier's obligation, which
   the tenant — a customer — has no part in. Re-keying them on the tenant
   would have made the platform declare its VAT once per customer. They
   stay as they are; §2.7 reduces to the tenant's own history, above.
2. **A plan does not carry grants; an offer version does.** The "plan as
   a shorthand" is therefore the grants of the plan's most recently
   activated offer version, resolved at grant time — the same rows a
   subscription would have copied.
3. **The storefront cannot hand off to a checkout any more**, because the
   person it just created is a USER. The spec said "buying stays the
   administrator's" and did not say what the form does afterwards; it goes
   to the root.

## Consequences

- A deployment has a default tenant or a bare host that says so (`404
  NO_DEFAULT_TENANT` at `GET /public/tenant`); the demonstration world
  makes Acme the default.
- Every link a tenant's people share carries the slug; re-slugging is a
  one-line refusal once an invoice exists, and a `tenant_slug_history`
  with a redirect is the alternative if that refusal ever bites.
- `signUp` takes `tenant` and answers `membership`; the `organisation`
  and `country` fields are gone with the organisation they described.
  `Member.status`, `Tenant.join_policy` / `join_domains`,
  `listProducts.pending_memberships`, `Entitlement.source = GRANT` and
  `VatTransaction.product` are additions; clients that ignored them
  keep working.
- A pending member holds an account and a session and can reach nothing:
  the shell's "waiting" page is the whole of what they see, and the
  administrator's Members screen is the whole of what changes it.
- Migrations: `Version20260917160000` (join policy, domains, membership
  status) and `Version20260917180000` (`GRANT`, `granted_by`). Neither
  rewrites a row that exists.
