# ADR-041 — The storefront sells to strangers, and says nothing else to them

**Status:** accepted
**Relates to:** [ADR-034](ADR-034-a-checkout-session-is-an-order.md);
[ADR-038](ADR-038-this-platform-issues-its-own-sessions.md);
[ADR-040](ADR-040-offer-authoring-is-lent-to-a-tenant.md);
Architecture V2 §10.6, §12.1, §24, §31; non-negotiables #22, #25

## Context

Every account on this platform was created by somebody who already had one:
the installer, or a tenant administrator adding a colleague by address. Every
catalogue read required a membership. Both were true because the product had
never been sold to anybody who was not already a customer — which made the
front door a login form and made the price list invisible to the people
deciding whether to pay for it.

The ask was direct: offers readable without a session, filtered by product; a
person who chooses one creates their account as part of buying it; that page is
the default landing, and signing in is secondary.

Three things stood in the way, and each is a decision rather than a detail.

**"On sale" is not "advertised."** The catalogue already knows when a version
may be sold — its window. It has never known whether an offer may be *shown*,
because until now only members could see any of it. An offer negotiated with
one reseller, a grandfathered price a live subscription still renews on, a plan
the sales desk quotes by hand: all sellable, none belonging on a page anybody
can open. Shipping the storefront against the sale window alone would have put
every private arrangement on the front page the day it deployed.

**A public endpoint leaks by answering.** `ProductCatalogue` is careful that
"which products exist is commercial information"; a storefront that 404s for an
unknown product code answers that question for any code somebody tries.

**A purchase needs more than an account.** A checkout refuses an order it
cannot invoice, and invoice numbering is gapless — a document raised by mistake
cannot be deleted — so an account with no billing profile cannot buy anything,
which would have made the storefront's whole promise false.

## Decision

**`offers.publicly_listed`, default false, and `staff.catalog.manage` decides
it.** Shipping the storefront advertises nothing that was not deliberately
advertised. The permission is a platform one and PLATFORM_ADMIN alone holds it
— deliberately *not* `catalog.manage`, which ADR-040 lets the platform lend to
a tenant: a tenant authoring its own offers must not thereby decide what the
platform's public page shows to everybody. `console.admin.storefront` is where
that decision is made, and it lists hidden offers alongside advertised ones,
because choosing what to advertise means seeing what you are choosing between.

Withdrawing an offer from the page does not withdraw it from sale. Subscribers
keep their terms, members keep seeing it in the catalogue, and a saved link
stops working. Un-selling an active offer is a commercial act with an invoice
attached and is not this one.

**`/api/v1/public/*` is a public prefix, and what is mounted there must be safe
to show a stranger by construction.** Not by a permission check — there is no
caller to check. The storefront qualifies because it returns only offers
somebody marked as advertised *and* that are inside their sale window, and
because the filter is applied in SQL: a request with no session behind it never
pulls a private price into memory at all.

**Nothing is explained to a stranger.** A code naming no product, an inactive
product and a product advertising nothing produce one identical empty window.
An offer id that is private, another product's, imaginary or merely out of its
window produces one identical 404. The product arrives as `?product=` — the
filter the caller names — and there is no endpoint listing products, because
which products a deployment hosts stays commercial information.

**`POST /api/v1/auth/sign-up` creates five rows in one transaction** — user,
credential, tenant, membership, TENANT_ADMIN role — plus a minimal billing
profile, and answers with a session exactly as signing in does. The purchase
that follows is therefore an ordinary authenticated checkout: ADR-034 stays
true, a session is an order, wherever it started. There is no second anonymous
purchase flow with rules of its own.

`organisation` is optional. Somebody buying for themselves has no company and
must not be made to invent one; the tenant takes their own name, an invoice
still has somebody to be addressed to, and **nothing records which of the two
cases it was** — a column saying B2C would invite something downstream to
branch on it, and nothing should.

**The address is not verified first.** A session is issued immediately,
`users.email_verified_at` stays null, and a confirmation link goes out through
the notification chain. An interrupted purchase is a purchase that does not
happen, and a verification mail landing in a spam folder is not something to
put between somebody and a subscription. The column records the doubt for
whoever downstream needs to act on it — billing, dunning, VAT — which is where
that decision belongs rather than in a gate on the front door.

The confirmation link **never issues a session**. A link that did would be a
credential sitting in an inbox for as long as that mail is kept, readable by
anybody who ever gains access to it.

**Sign-up says plainly that an address is taken.** This is the one place the
platform tells somebody an account exists, and it contradicts the care taken
everywhere else — `Sessions::TIMING_EQUALISER` exists precisely so that "no
such account" cannot be told from "wrong password" by a stopwatch. The
asymmetry is deliberate: a person who cannot be told cannot finish the purchase
they came for, and the sign-in form is one click away. What bounds the
enumeration this permits is §31's rate limiter, which on a public route is the
tighter allowance.

**The gate decides by where somebody was going.** Asking for `/` is arriving,
and gets the storefront; asking for any other path is asking for something
behind the gate, and gets the form. Signing in is therefore one line of text on
the way in and immediate for anybody following a link — the weighting a product
that sells to strangers needs, and the opposite of a login-first front door.

## Consequences

A deployment that upgrades advertises nothing until somebody opens
`console/storefront` and says so. That is the safe direction and it is also a
step somebody has to know about; the console entry appears for PLATFORM_ADMIN
without being looked for, which is the whole reason the flag is a screen rather
than a column.

`APP_URL` is now configuration this platform needs, because a confirmation link
must be absolute and the platform must not derive a host from a request header
— anybody who can reach the API could then decide where the link points.
Unset, the link is relative and useless in a mail client, which is the honest
failure. `setup.php` writes it from the address its own request arrived on,
which is the one moment the platform can know it without guessing.

The email body is still `DispatchNotifications`' generic key-value rendering.
A confirmation mail reading `link: https://…` is usable and ugly, and a real
template catalogue with locales remains the open piece it already was.

Self-service sign-up means anybody can create a tenant. That is what a public
storefront is; the rate limiter is what bounds it, and the platform's own
console shows every tenant created.

## Alternatives rejected

**Reuse `/api/v1/offers` with an anonymous mode.** One endpoint whose answer
depends on whether a caller exists is one endpoint two audiences read
differently, and the private-price leak would be a missing branch rather than a
missing route.

**Verify the address before allowing a purchase.** Safer for billing and fatal
to conversion, and it makes deliverability of a single email the thing standing
between the platform and its revenue. The column is there for whoever wants to
act on it.

**A separate anonymous checkout that creates the account on payment.** It would
mean a second purchase path with its own idempotency, its own failure modes and
its own relationship to ADR-034's "a session is an order". Signing somebody in
first makes all of that the existing path.

**Let `catalog.manage` govern public listing.** It would hand the platform's
front page to whichever tenant the platform had lent the catalogue to — the one
thing ADR-040 was careful not to do.
