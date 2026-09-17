# ADR-047 — A tenant has products, and the console chooses one

**Status:** accepted
**Amends:** [ADR-041](ADR-041-the-storefront-sells-to-strangers.md) (there is
now a public list of products, and it lists advertisements);
[ADR-042](ADR-042-the-console-administers-products.md) (the console no longer
names its product in the URL); `docs/ui-spec.md` §3.5, §4.1, §4.2
**Amended by:** [ADR-049](ADR-049-a-tenant-lives-at-its-own-root-and-its-people-arrive-by-themselves.md)
— a tenant is made by the platform (`POST /staff/tenants`) and nowhere else;
memberships carry a status, and only ACTIVE ones are mirrored into anything
that resolves; and beside *assigning* a product the platform may *grant* the
entitlement to it (`entitlements.source = GRANT`), which is what a tenant that
holds a product it has not bought needs in order to use it.
**Relates to:** [ADR-013](ADR-013-product-context-resolution.md);
[ADR-015](ADR-015-tenant-resolution.md);
[ADR-039](ADR-039-the-installer-appoints-the-first-administrator.md);
[ADR-046](ADR-046-one-shell-two-authorities.md);
Architecture V2 §10.6, §12.1, §13.1; non-negotiables #13, #21, #22

## Context

Product is the root context of this platform, and until now the way a tenant
got one was invisible. The **only** link between a tenant and a product was
the membership triple `tenant_members (tenant_id, user_id, product_id)`: a
tenant "had" a product when somebody happened to be a member of it for that
product. No row said so. Nothing could assign one — a tenant wanting a second
product had an `INSERT` typed against production, the situation ADR-039 ended
for staff and ADR-042 ended for products, still open here. And adding a
colleague bound them to whichever product the administrator was using when
they typed the address, which was never a decision anybody meant to take.

Three smaller things had grown around that gap, and the operator's verdict on
the whole was "completely messy":

- **The console had a second product of its own.** ADR-042 moved every
  console screen to `?selected=` in the URL, because the context bar's
  switcher was filled from memberships and a platform role grants none, so
  the console had silently administered whichever product the person had last
  used the *application* in. The fix left two products on screen — one in the
  bar, one in the address — and console screens that said "no product chosen"
  under a bar naming one.
- **The public storefront needed `?product=code` in the address** to show
  anything, or a build-time default. ADR-041 decided there would be no endpoint
  listing products, because which products a deployment hosts is commercial
  information — and so a visitor arriving at `/` with no link saw an empty
  page telling them to edit the URL.
- **A platform administrator had no navigation of their own.** The console's
  fourteen screens sat in the left rail among everything else on a desktop,
  and on a phone the five-slot bottom bar held the first five and buried the
  rest under "More".

## Decision

**`tenant_products` is the assignment, and the platform writes it.** A tenant
holds zero or more products; the console gives and takes them behind
`staff.tenants.manage`, PLATFORM_ADMIN alone, on the trail like the
delegation of offer authoring beside it. Sign-up and the installer write the
product the account was created for, with `assigned_by` NULL — nobody on
staff decided that, the customer did by arriving. Every pair a membership
already implied is backfilled, so nothing that worked stops.

**A membership lives inside an assignment, and the schema says so.**
`tenant_members (tenant_id, product_id)` references `tenant_products` and
cascades: withdrawing a product removes the memberships in it, and a
membership in a product the tenant was never given is refused by the
database rather than by a service somebody has to remember to call.

**The membership schema does not change; its meaning does.** A person is a
member of the *tenant*, and the platform mirrors that membership onto every
product the tenant holds: assigning a product makes every current member a
member of it with the DISTINCT roles they already hold in the organisation;
adding, re-roling and removing a member act across every product the tenant
holds. The rows stay per product because every table this platform isolates
by is keyed on `(tenant, product)` and the resolver reads memberships that
way (ADR-015) — changing that would touch every adapter to remove a column
the mirror makes harmless.

**Withdrawing a product is refused while service is owed.** A subscription on
that tenant and product that is active, or cancelled with paid time left
(§13.1: the service stays owed until the period ends), answers
`409 PRODUCT_IN_USE`. The records keyed on the pair — projects, conversations,
invoices — stay after a withdrawal: a record of what happened is not access
to it, and an invoice is a document the law keeps. Assigning a retired product
is refused too: a retired product has every door closed, and handing a
customer a locked door is not an assignment.

**The console follows the switcher, and the switcher lists the platform's own
products there.** On `/console/*` the context bar's switcher is fed by
`listPlatformProducts` — every product the platform hosts, retired ones
marked — and chooses the first active one when nothing is chosen yet. Every
console screen reads the same `productCode` the application's screens read.
`?selected=` stops carrying a product. ADR-042's objection was that the
console *silently* administered a product chosen elsewhere; the product is now
chosen in the bar, visibly, from the platform's list, and nothing else on the
console names one. `?product=` remains the deep link, because
`useProductContext` seeds the store from it.

**There is a public list of products, and it lists shop windows.**
`GET /api/v1/public/products` names only the active products with a
publicly-listed offer sellable right now — exactly the set a stranger could
already assemble by trying `?product=` codes one at a time, so the list
reveals nothing the windows do not. A product with nothing on sale is absent.
What the platform *runs* stays private; what it *advertises* was never
private. The storefront offers the list as a dropdown when there are several,
chooses the one when there is one, and says so when there are none.

**The console gets a menu of its own, in region C's header.** A bar with one
native disclosure per platform section on a desktop; a button in the context
bar opening a full-screen sheet on a phone; one way back to the application
— the first tenant screen the person may open, else the front door. It is
built from the same navigation tree the rail is built from, filtered the same
way, so it invents nothing and `gate:permissions` is unchanged.

## Consequences

- A tenant with a product assigned and no member yet is a valid state: the
  platform decided, nobody has been invited. That row is what `down()` would
  lose, which is why the migration is irreversible (ADR-016).
- `MemberAdministration` is untouched. The fan-out is the repository's, in
  SQL: `addMember` inserts one row per assigned product, `replaceRoles` and
  `removeMember` act on every product's rows. The in-memory double was already
  keyed by user alone.
- The staff reads answer with `StaffTenant` — `Tenant` plus `products` — and
  not with `Tenant` itself, which is what a tenant's own administrator reads
  about their organisation. Which products the platform gave them is the
  platform's answer to give.
- `listProducts` (by membership) is unchanged and still what the application's
  switcher uses. A platform role still grants no membership (#22).
- `docs/ui-spec.md` §4.1 says region C never holds global concerns. A section
  menu is not one: it is the navigation of the screen family the view belongs
  to, shown only while that family is in the view. Region B stays the primary
  navigation of the whole application.
- A remembered product code that no longer exists on the platform renders as a
  bare code chip until the switcher is used — the same as before.
- Pre-existing, not fixed here: the tenant-side `TenantPresenter` omits
  `may_author_offers` although the shared `Tenant` schema requires it.
  `StaffTenant` sidesteps it.

## Alternatives rejected

**Make `tenant_members` per tenant and drop `product_id`.** The cleaner
schema, and the one the mirror approximates. Rejected for size: every table
keyed on `(tenant, product)`, the resolver, twenty-five test fixtures and
every adapter that joins memberships would change, to remove a column the
mirror makes harmless. Named rather than discovered; it stays available.

**Assign a product without touching memberships.** Then a product the
platform gave a tenant would be one nobody in it could reach until somebody
re-added every member from inside it — an assignment that assigns nothing.

**List every active product publicly.** Products with nothing on sale would
appear, which is the one thing ADR-041 was right to keep private: a product
being built is not yet a product being sold.

**Keep `?selected=` and make the switcher write it.** Two places for one
fact, kept in step by discipline. The switcher already wrote a store every
other screen read; the console is now one of those screens.

**A seventh region for the console menu.** The spec's regions are what lets a
reader place a thing before seeing it, and a region that appears on one
family of screens is a special case wearing a region's name. The header of
the view is where the view's own navigation belongs.
