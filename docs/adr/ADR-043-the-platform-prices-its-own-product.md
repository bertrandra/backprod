# ADR-043 — The platform prices its own product

**Status:** accepted
**Relates to:** [ADR-033](ADR-033-a-published-offer-version-is-frozen.md);
[ADR-040](ADR-040-offer-authoring-is-lent-to-a-tenant.md);
[ADR-041](ADR-041-the-storefront-sells-to-strangers.md);
[ADR-042](ADR-042-the-console-administers-products.md);
Architecture V2 §12.1, §13, §13.1; non-negotiables #21, #22, #25

## Context

Two gaps, discovered by asking a simple question of a real deployment — *what
about offers?* — and they compound.

**Nothing on this platform could create a plan or a feature.** `INSERT INTO
plans` appeared exactly once in the whole repository, in `bin/seed-demo.php`.
There was no `POST /api/v1/plans`, no PATCH, no screen; the same for features.
`deploy/siteground/setup.php` creates a product, a tenant, a user, a
credential, a membership, a role and a platform grant — and no plans.

So a fresh installation had a product with nothing in it, and creating an offer
was impossible regardless of permissions: `OfferAuthoringRepository::createOffer`
inserts `SELECT … FROM plans WHERE id = :planId AND product_id = :productId`,
which matches nothing when there are no plans. The catalogue was unreachable
from its own first step.

**And ADR-040 made the second gap worse rather than better.** It established
that offers are the platform's — keyed on product, not on tenant — and then
left the only way to author one being *as a tenant*: all six authoring routes
sit on the tenant shell behind `catalog.manage`, which needs a `RequestContext`
resolved from a membership. A platform administrator has no membership
(non-negotiable #22), so those routes were unreachable for them. And after
ADR-040 they were unreachable for a tenant administrator too, until somebody
lent that tenant the catalogue.

The result was a contradiction shipped in this session: offers belong to the
platform, and the platform could price them only by lending its catalogue to a
customer and acting as that customer.

## Decision

**`CatalogueAdministration`, a new port, for the two things nothing could
create.** Plans and features, product-scoped like every other catalogue port.
Separate from `CatalogueRepository` (which reads) and from
`OfferAuthoringRepository` (which writes offers), because those two existed and
this hole sat between them.

**A staff-side authoring surface under `/api/v1/staff/catalogue`, behind
`staff.catalog.manage`** — the permission ADR-041 already created for deciding
what the storefront advertises. Nine operations: read the plans and features
together, create and update a plan, create and rename a feature, create and
rename an offer, add a version, publish one.

The tenant-side routes are untouched. `catalog.manage` keeps exactly the meaning
ADR-040 gave it — a *lending*, for a reseller who maintains their own price list
— rather than being the only way in. Two doors, two permissions, and the one
that should have existed first now does.

**`rank` is required on a plan and is chosen, not derived.** It is the only
ordering this platform has: an upgrade is a comparison of two integers, never of
two names (non-negotiable #25). Deriving it from creation order would make "Pro
sits above Starter" a fact about when somebody typed it in. Zero is legitimate —
a free tier at the bottom.

**A feature's `kind` is chosen once and can never change.** Every grant written
against a feature was written meaning BOOLEAN or QUOTA: a quota's grant carries
a limit, a boolean's carries null. Flipping the kind would reinterpret rows
already priced into live subscriptions. A feature that should have been the
other kind is a new feature. So the rename route takes a name and nothing else,
and `unit` is refused on a boolean at the boundary rather than by a CHECK
constraint's name.

**No deletes anywhere.** Offers point at plans by id and entitlements point at
features by id, and both appear in rows a live subscription is priced from. A
plan gets a rank that can be reordered and a name that can be corrected; that
is all.

**Offers are created DRAFT and publishing is a separate act.** Identical to the
tenant route, and `OfferDraftBody` is *shared* with it rather than
reimplemented: the two carry the same object, and two copies of that parsing
would be two places for a term added to §13.1 later to be accepted by one and
dropped by the other. ADR-033 then holds — a published version is frozen, so a
new price is always a new version.

**The console names the product in `?selected=`**, as ADR-042 established: the
console has no ambient product, and a link has to open the same catalogue for
whoever follows it.

**Reads are not recorded; writes are.** Non-negotiable #21 traces staff crossing
into a *tenant's* data, and a price list is the platform's own. What is recorded
is every act that changes what can be sold — `plan:CREATE`, `plan:REORDER`,
`feature:CREATE`, `offer:PUBLISH` — because "who put this price on sale?" is a
question an auditor eventually asks, and reordering plans is a commercial
decision rather than a cosmetic edit.

## Consequences

A fresh installation can now go from a product with nothing in it to an offer a
stranger can buy, entirely through the console. `ConsoleCatalogueTest`'s last
test is exactly that path, and it is the reason the rest of the file exists.

The screen states the order of operations rather than letting somebody discover
it: with no plans, the offer form is replaced by a sentence saying why. A form
that let somebody write an offer with no plan would send a request the API
refuses.

Two authoring surfaces now exist for offers, on two shells, with two
permissions. That is more surface than one, and it is the honest shape: the
platform's own catalogue and a tenant's borrowed one are different authorities
over the same rows. They share `OfferAuthoringRepository` and `OfferDraftBody`,
so the rules cannot drift; what differs is who may reach them.

Still not covered: `product_configuration`. A new product has no
`billing_supplier`, so a checkout against it refuses with
`BILLING_NOT_CONFIGURED` — named in ADR-042's consequences and still true. And
grants are not yet settable from this screen: an offer can be priced, but what
its plan *grants* is `offer_version_features`, which the API accepts on a draft
and the console does not yet ask for.

**Both closed by [ADR-044](ADR-044-a-product-cannot-invoice-until-somebody-says-who-is-selling.md).**
They turned out to be the same failure seen twice — the console could build
something it could not finish selling — and the grants gap was worse than
recorded here: "new version from this" repeated the price and dropped every
grant, because this view named each feature by code and the API takes an id.

## Alternatives rejected

**Move the tenant routes to the console and delete them from the tenant shell.**
It would break ADR-040's delegation, which is a real arrangement — a reseller
maintaining their own price list — and would be a breaking change to a
documented surface for no gain beyond having one door instead of two.

**Let the platform author offers by giving platform roles a `RequestContext`.**
It would make a platform role grant an ambient tenant, which non-negotiable #22
forbids for the reason this whole session keeps rediscovering: the moment the
two identities blur, `if (isStaff)` appears somewhere.

**Derive a plan's rank from creation order.** Simpler form, wrong semantics, and
impossible to correct afterwards without a reorder endpoint — which is the thing
it was trying to avoid building.

**Create a default plan alongside a new product.** It would make the offer form
work immediately, at the cost of every deployment carrying a plan nobody chose
and most having to rename or work around it. ADR-042 decided a new product is
genuinely empty; this keeps that true and says so on the screen instead.
