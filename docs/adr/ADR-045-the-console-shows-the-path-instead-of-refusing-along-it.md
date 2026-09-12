# ADR-045 — The console shows the path instead of refusing along it

**Status:** accepted
**Relates to:** [ADR-040](ADR-040-offer-authoring-is-lent-to-a-tenant.md);
[ADR-041](ADR-041-the-storefront-sells-to-strangers.md);
[ADR-042](ADR-042-the-console-administers-products.md);
[ADR-043](ADR-043-the-platform-prices-its-own-product.md);
[ADR-044](ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md);
Architecture V2 §12.1; non-negotiables #21, #22, #25

## Context

Four ADRs built the surfaces a fresh installation needs, and an operator still
could not use them. Two failures, both found the same way — by the same person
losing the same afternoon twice.

**The console had no door.** `useStaffIdentity` was called in exactly one place
in the whole frontend: `ConsoleShell`. The tenant application therefore never
asked whether the person using it was also platform staff, and could not offer a
link it did not know was warranted. After signing in you landed on the tenant
app, and the console was reachable only by typing `/console/...` into the address
bar — which the operator of a fresh installation has no way to guess. The
complaint arrived twice in one session, in the same words: *I cannot find the
product.*

**And the order of the chain was discoverable only by walking into its
refusals.** A fresh product has no plans, so `createOffer` selects a plan by id
and matches nothing. An offer is born a draft, so publishing is a separate act.
A published version still shows nothing publicly, because advertising is a third
decision (ADR-041). And a checkout that gets all the way through refuses with
`BILLING_NOT_CONFIGURED`, because an invoice must name its issuer (ADR-044).
Every one of those refusals is correct. Together they are a maze, and the only
way to learn the order was to get it wrong.

The console's navigation made it worse: it opened on support and put Products
seventh. The screen somebody needed first was the one they could not find.

## Decision

**A chain, not a checklist, and the order is the point.**
`GET /api/v1/staff/readiness?product=CODE` returns nine steps in dependency
order: an active product, an issuer for its invoices, a tax position, a plan,
features, an offer, a published version, an advertised one, a payment provider.
A step that cannot be started until an earlier one is done sits after it, so
working down the list never meets a refusal. That shape carries information a
set of independent boxes would lose.

**Every fact is read through the port the enforcing code reads.** The issuer
comes from `SupplierDetails` — the same domain object the invoice path refuses
over, so `missing` here is exactly the field list a checkout is failing on. The
catalogue comes from `CatalogueRepository`. And **sellability is asked of the
clock**, through `OfferCandidate::sellableAt()`, not read from a status column:
a version can be ACTIVE and outside its selling window, and a chain that read the
column would report a price on sale that nothing will sell. This cannot say ready
where a sale would refuse, because it asks the same questions.

**Steps carry facts, not sentences.** `key`, `done`, `blocking`, and a `detail`
holding what was counted or found missing. The words live in the surface that
speaks to a person, in whatever language it speaks — a step shipping its own
English prose would make the API the wrong place to fix a typo.

**`tax` and `features` are listed and are not blocking.** A supplier selling at
home invoices correctly without a stated tax position, and an offer granting
access to the product and nothing more is a legitimate offer. Marking them
required would send somebody to do work the platform does not need; omitting
them would hide two things that matter the moment a sale crosses a border.

**The navigation is ordered by dependency.** The console opens on `Set up` —
readiness, products, invoicing, catalogue, storefront — then customers, then the
platform's own machinery. Invoicing sits *before* the catalogue because a product
can be priced and advertised and still refuse at the checkout, and meeting that
refusal after building a whole catalogue is the failure this order prevents. A
menu whose order contradicts the order of operations teaches the wrong sequence
every time it is read.

**A door into the console, gated on the console's own identity.** A link in the
tenant application's context bar, rendered only when `GET /staff/me` answers with
a role. It is not in `TENANT_NAV` and is not built from a tenant permission, so
non-negotiable #22 holds in the direction it was written for: a tenant role still
reveals nothing. What changes is that a *platform* role stops being invisible to
the person holding it.

**`PaymentProviders` is read in the controller, not in the desk.** Whether a
deployment can take money is a fact about the server, held by an application
service the desk may not depend on (§37.6 keeps Application from reaching
Application). The controller has both and hands one to the other.

## Consequences

`ConsoleReadinessTest`'s last test is unlike the others: it does not check a list
of facts, it checks that **the list is a path**. At every turn it does only what
`next` names, and after five console calls the product is sellable — never once
having been told to do something it was not yet allowed to do. A checklist of
independent boxes would pass every other assertion in that file and still let
somebody start at the end.

The chain is one read, refreshed by every write that could advance it — a stale
chain tells somebody to do what they just did.

`/console` alone now lands on the chain, so an address typed without a section is
a useful page rather than nothing. `router.test.ts`'s pattern was widened from
`^/console/` to `^/console(/|$)` for it, in both directions: a bare `/console` in
the tenant tree would still be caught.

The tenant application now issues one request to `/staff/me` per session. For the
overwhelming majority of users, who are not staff, it is refused once, not
retried, and renders nothing. That is the cost of the door, and it is paid by
everybody so that the few who need it can find it.

`payments` is the one step no screen can fix, and it deliberately offers no
button: a control leading somewhere that cannot change the thing is worse than a
sentence saying where it lives.

## Alternatives rejected

**Compute readiness in the browser from the calls the console already makes.**
It would have needed no endpoint. It would also have been a second
implementation of "can this sell", and the first to drift would be the one the
operator trusts — a console reporting ready while the checkout refuses is worse
than a console that says nothing.

**Sort done steps out of the way.** Tidier, and it would move the shape of the
chain under somebody halfway through it. The order is what teaches the order;
keeping completed steps in place is what lets it.

**Put the console entry in `TENANT_NAV` behind a staff permission.** One
navigation instead of two. It would make a nav entry's `permission` field mean
two different things depending on the row, and put a console route one mistaken
permission string away from a tenant. The trees stay separate.

**Let the installer create a demo plan and offer.** The chain would start
half-done and a first sale would be minutes away. It would also mean every
deployment carrying a plan nobody chose, and ADR-042 already decided a new
product is genuinely empty. Saying what to do next is the honest version of the
same help.
