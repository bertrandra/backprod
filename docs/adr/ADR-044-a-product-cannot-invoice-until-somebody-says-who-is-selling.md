# ADR-044 — A product cannot invoice until somebody says who is selling

**Status:** accepted
**Relates to:** [ADR-033](ADR-033-a-published-offer-version-is-frozen.md);
[ADR-041](ADR-041-the-storefront-sells-to-strangers.md);
[ADR-042](ADR-042-the-console-administers-products.md);
[ADR-043](ADR-043-the-platform-prices-its-own-product.md);
Architecture V2 §12.1, §25, §25.3; non-negotiables #21, #22

## Context

Two gaps, both named in ADR-043's own consequences, both closed here because they
are the same failure seen twice: **the console could build something it could not
finish selling.**

**A product built in the console could not raise an invoice.** ADR-042 gave the
console a way to create a product and ADR-043 a way to price it, and a product
made that way could then be advertised, chosen, paid for — and refused at the
last step with `BILLING_NOT_CONFIGURED`. A French invoice must name its issuer:
legal name, address, SIREN/SIRET and VAT number are mandatory mentions (§25),
and that identity lives in `product_configuration` under `billing_supplier`.
Exactly one thing on this platform ever wrote that table: `bin/seed-demo.php`.
So the only products that could invoice were the demo's and the installer's, and
an administrator who followed the console from end to end reached a customer-facing
409 with nothing on any screen explaining it.

**And an offer written in the console granted nothing.** `POST /offers` has taken
a `grants` array since offers existed; ADR-043's screen never sent one. Every
offer the console wrote could be priced, published and bought, and the
subscription it created resolved to no entitlements at all. Worse, the button
labelled *"New version from this"* repeated the price and dropped the grants,
because the authoring view named each feature by **code** and the API takes an
**id** — so a version created from a live one silently entitled the buyer to less
than the one they had compared it against.

## Decision

**`ProductSettings`, a fourth product port, that can write.** `ProductRegistry`
answers the same read, but its other methods resolve through membership, and a
platform role grants none (non-negotiable #22) — a console asking it would be
asking a port built for the tenant shell. `ProductDirectory` is the set of
products, not one product's settings.

**Two named keys, never a free-form JSONB editor.** Every key in
`product_configuration` is read by code that names it, so a writer taking an
arbitrary key would let a typo store configuration nothing reads — which on a
screen is indistinguishable from configuration that did not save. So:
`PUT /api/v1/staff/configuration/billing-identity` and
`PUT /api/v1/staff/configuration/tax`, with one `GET` returning both.

**`SupplierDetails`, a domain object, so there is one answer to "can this product
invoice?"** The rule — which fields exist, which two an invoice cannot do without,
what a country code looks like — used to live inside `SupplierIdentity`, an
application service on the invoicing path. The console needed the same rule to
refuse a bad submission, and a second copy would have been a second answer:
eventually a console reporting ready where a checkout refuses. The domain object
normalises and reports what is missing and **throws nothing**; what to *do* about
a missing `legal_name` legitimately differs between the two callers (a conflict
for something already configured, a validation failure for something being
submitted), and that decision stays theirs.

**PUT, not PATCH** — the only write in this console that is. The mandatory
mentions are one document, and a supplier that stops being liable for VAT has to
be able to *remove* its number; under "omitted means leave it" removing anything
is impossible. Sending the whole identity makes clearing a field the same act as
changing one.

**An incomplete identity is refused rather than stored.** The console exists to
make invoicing possible, and storing something that cannot invoice while answering
200 is how a broken form looks like a working one — the failure would surface
later, to a customer, at the checkout.

**`staff.products.manage`, not `staff.catalog.manage`.** Who the platform invoices
as is not a price. Somebody trusted with the shop window is not thereby trusted
with the legal identity on the documents.

**Every field of the tax position is required.**
`SupplierTaxSettings::fromConfiguration()` defaults what it cannot read, which is
right for a stored document written before a field existed and wrong for a form:
a screen omitting `oss_registered` would silently switch the OSS regime off. §25.3
keeps everything qualifying the supplier fiscally a human decision, and crossing
the distance-selling threshold is a dated event that changes the regime of
*subsequent* sales only — deriving it from turnover would retroactively restate
invoices already issued.

**The authoring view names each feature by id; the sale view does not.**
`AuthoredOfferGrant` is the sale shape plus `feature_id`. ADR-033 freezes a
published version, so changing what an offer grants means writing a new version
from the old one — which needs the ids. The public window is read by strangers,
and the internal key of a catalogue row is not something a shop window owes them.

**Reads are not recorded; writes are.** Non-negotiable #21 traces staff crossing
into a *tenant's* data, and a product's own fiscal identity is the platform's.
What is recorded is every change to it — `CONFIGURE_BILLING` carrying the legal
name and country, `CONFIGURE_TAX` carrying all four fields — because an invoice
copies this configuration at the moment it is raised, and "why does the January
batch name a different issuer?" is answerable only from a trail.

## Consequences

`ConsoleConfigurationTest`'s last test is the payoff and the reason the rest of
the file exists: a product created, priced and configured **entirely through the
console** takes somebody's money, and the invoice names the company the console
named. The same checkout, one console write earlier, refuses with
`BILLING_NOT_CONFIGURED` — so what changed is stated as a behaviour, not as a
row. `product_configuration` is never written by SQL in that file; a fixture
written the old way would have tested nothing about the gap being closed.

A quota with no limit is **unlimited**, and the screen says so where somebody is
about to leave the box empty. It is not a limit of zero, and the two are
different entitlements.

The offer form now states what an offer grants, including when the answer is
nothing: a product with no features gets a sentence saying that an offer granting
access and no more is legitimate, rather than a form that looks like it failed to
load.

`CataloguePresenter::authoredVersion()` composes with `array_merge` and not `+`.
The union operator keeps the **left** key, so the sale view's grants won and the
feature ids were silently discarded — the integration test caught it, and the
comment now says why the operator is not used there.

Still not covered: nothing else in `product_configuration` has a screen —
`project_schema_versions` is the other key any code reads, and it has no console
surface. That is deliberate rather than pending: it is a developer-facing setting
of one module, not something an administrator configures to make a product sell.

## Alternatives rejected

**A generic key/value editor over `product_configuration`.** It would have covered
every key at once, including ones nobody has written yet, at the cost of letting a
typed key store configuration no code reads. A screen that silently saves nothing
is worse than a screen that does not exist.

**Compute `can_invoice` in the console.** The frontend has the fields and could
decide. It would be a second implementation of a legal rule, and the first one to
drift would be the one the customer meets.

**Store an incomplete identity and warn about it.** Tempting, because a form you
cannot save is annoying. But the warning would sit on a screen nobody revisits,
while the refusal arrives at a checkout somebody is trying to complete.

**Put `feature_id` on the public grant shape too.** One shape instead of two, and
one fewer schema. It would publish the internal identifiers of catalogue rows to
anybody who loads the shop window — small, but given away for a convenience that
only the authoring screen needed.
