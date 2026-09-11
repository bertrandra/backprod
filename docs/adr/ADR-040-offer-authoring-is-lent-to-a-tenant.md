# ADR-040 — Offer authoring is lent to a tenant, not owned by its role

**Status:** accepted
**Relates to:** [ADR-033](ADR-033-a-published-offer-version-is-frozen.md);
[ADR-039](ADR-039-the-installer-appoints-the-first-administrator.md);
Architecture V2 §10.2, §10.3, §12.1, §12.2; non-negotiable #22

## Context

`catalog.manage` has been granted to `TENANT_ADMIN` since the catalogue became
writable. That looked right at the time — an administrator of an organisation
administers its things — and it is wrong, for a reason that is a property of
the schema rather than of the permission.

**Offers are keyed on product, not on tenant.** `offers.product_id` exists;
`offers.tenant_id` does not, and cannot, because an offer is what the platform
sells a product for. Every customer of Atlas is quoted, invoiced and renewed
from the same rows. So a tenant administrator holding `catalog.manage`
unconditionally is not editing their own price list. They are editing
everybody's, including the prices their competitors are paying, and including
the version a subscription somebody else holds is priced from.

The person installing this platform said it plainly: offers belong to the
staff administrator, and letting a tenant edit them is *an option* — off unless
the platform says otherwise, per tenant, and granted by a staff administrator.

Two shapes were available.

**Duplicate the authoring endpoints on the console side**, so staff write
offers through `/api/v1/staff/...` and tenants never can. Six endpoints become
twelve, each pair a copy that has to keep agreeing about validation, the
freeze rule, the one-on-sale-at-a-time window, and the presenter. Every future
authoring endpoint is two endpoints. The second copy is where the next bug
lives.

**Decide whether the permission is resolved at all**, per tenant.

## Decision

**`tenants.may_author_offers` (boolean, `NOT NULL DEFAULT false`) governs
whether `catalog.manage` is resolved for that tenant's members**, in the single
query that turns a membership into permissions:

```sql
LEFT JOIN permissions p
       ON p.id = rp.permission_id
      AND (p.code <> 'catalog.manage' OR t.may_author_offers)
```

Three consequences follow, and they are the reason for the shape:

- **Every route behind `catalog.manage` is refused, including the ones not yet
  written.** A permission that is never in the set cannot be checked
  incorrectly by the seventh endpoint somebody adds. A flag checked *beside*
  each route is a rule that has to be remembered seven times.
- **The console needs no new gate.** The shell already hides what the caller
  has no permission for, so the authoring controls disappear for a tenant that
  has not been lent the catalogue, without the frontend knowing this rule
  exists.
- **The role is untouched.** Somebody in this state is still `TENANT_ADMIN` —
  administrator of their organisation, not of the price list. Members, billing
  and the skin are unaffected, which is what makes this a delegation rather
  than a demotion.

**`staff.tenants.manage` is a platform permission, and PLATFORM_ADMIN alone
holds it.** Support can *see* the answer — the flag travels on the audited
tenant read, so "may they edit their prices?" is answerable at the support desk
— and cannot change it. A support engineer able to hand one customer the price
list could change what every other customer of that product pays, which is the
same objection that produced this ADR.

**The endpoint is `PUT /api/v1/staff/tenants/{tenantId}/offer-authoring`, and
it carries no motive.** PUT because it states a desired state: a console
toggle clicked twice on a slow connection asked for the same thing twice, and
should not have to reason about that. No motive because R14 requires a reason
where staff reveal a customer's own data, and this reveals none — it changes
what that customer may do. The trail records `DELEGATE` and
`REVOKE_DELEGATION` as distinct actions under `staff.tenants.manage`, because
somebody reading the log later is asking which way it went, and a row saying
only that the field was touched cannot answer that.

**Withdrawing the delegation does not delete what was authored.** It stops the
tenant writing; it does not unpublish a price somebody is currently paying.
Un-selling an active offer is a commercial decision with an invoice attached,
and it is not the same act as taking back a permission.

## Consequences

Every tenant that exists today has `may_author_offers = false`, which is the
behaviour this platform should have had from the start: the catalogue is the
platform's, and a customer edits it only when the platform has said so.

A tenant administrator who *was* able to author offers before this migration
can no longer do so until an administrator turns it on. That is the correction,
not a regression — but it is a behaviour change on an existing deployment, and
the console screen is the remedy: `console.support.tenants` shows the state and
offers the switch to whoever holds `staff.tenants.manage`.

The rule lives in one SQL condition. That is its strength and its cost: it is
invisible from the authoring endpoints, so a reader of `CreateOfferController`
sees `requirePermission('catalog.manage')` and no hint that the permission may
never arrive. The comment in `PostgresTenantMembershipRepository` is therefore
load-bearing documentation, and `OfferAuthoringDelegationTest` runs against the
real repository rather than a double — an in-memory membership would satisfy
the claim by returning whatever the fixture said, proving only that the test
and the fake agree about a rule neither enforces.

## Alternatives rejected

**A `catalog.manage` grant removed from `TENANT_ADMIN` outright.** Simplest,
and it makes the delegation impossible rather than optional — the user asked
for an option, not a prohibition.

**A separate `TENANT_CATALOG_AUTHOR` role, granted by staff.** It puts the
decision in the tenant's role catalogue, where a tenant administrator who can
manage members and roles would eventually be able to grant it to themselves.
The authority has to live on the platform's side of the boundary
(non-negotiable #22), and a column on `tenants` that only a platform
permission can write is that.

**Checking the flag inside `OfferAuthoring`.** One place, and still the wrong
one: the service is reached only after a route has already decided the caller
may write, so the refusal would arrive as a 403 from the service layer on a
permission the caller appears to hold — and `GET /api/v1/me/permissions` would
keep telling the console it was allowed.
