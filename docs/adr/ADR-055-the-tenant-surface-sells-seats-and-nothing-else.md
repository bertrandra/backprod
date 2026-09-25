# ADR-055 — The tenant surface sells seats, and nothing else

**Status:** accepted, 2026-09-25.
**Amends:** §13.1's two subscriber kinds as a *sales* matter; `Sales::order`,
`Checkout::open`, the `seat` field on `openCheckoutSession`, and the five
tenant quote operations, which are withdrawn.
**Related:** [ADR-053](ADR-053-buying-covers-people-membership-does-not.md)
(buying covers people), [ADR-054](ADR-054-a-gapless-series-belongs-to-its-issuer.md)
(a series belongs to its issuer), [ADR-034](ADR-034-a-checkout-session-is-an-order.md).

## 1. Context

The operator's model, settled over 2026-09-25: **a person decides to use a
product and buys a seat for themselves; the organisation administers.** It
runs its members, its billing identity, its VAT and its documents, and reads
what its people hold. The demonstration world was rebuilt on that model the
same day, and every invoice in it is *the organisation → one of its own
people*.

The screens did not say the same thing. The catalogue offered three ways out:

```text
Quote                     sales.manage     → the organisation's subscription
Buy for the organisation  billing.manage   → the organisation's subscription
Buy for yourself          billing.pay      → a seat
```

Removing the middle button is what was asked for. Removing only the button
would have left `POST /checkout/sessions` accepting `seat: false` and
`POST /sales/orders` defaulting to it — the frontend is never the authority
(CLAUDE.md), so "we sell seats" would have been a habit rather than a fact,
held up by whoever next writes a client.

The quote was the same sale with a document in front of it. `acceptQuote`
placed an order with no subscriber named, which is `Subscriber::tenant()`; a
quote's customer snapshot is the organisation's billing profile. So a
storefront that sells only seats cannot raise one and have it addressed to
anybody.

## 2. Decision

**`Subscriber::tenant()` is not reachable from the tenant surface.**

- `Sales::order()` lost its `$seat` flag and always places a seat for the
  caller, who must be named. There is no argument for the other sale.
- `Checkout::open()` lost the same flag, and `seat` is gone from
  `openCheckoutSession`'s request body. A request that cannot express the
  organisation's purchase cannot make one by accident or by an old client.
- The five tenant quote operations are withdrawn: `listQuotes`, `showQuote`,
  `createQuote`, `acceptQuote`, `rejectQuote`, with their routes, controllers
  and the `/quotes` screen.
- `Sales::refuseWhileSubscribed` is gone with them. It guarded a second
  organisation subscription before any document existed; with no way to buy
  the first it guarded nothing, and the partial unique index it stood in front
  of is still in the schema, which is where the rule belongs.
- The refusal a customer now meets is `SEAT_ALREADY_ACTIVE`, in every place
  `SUBSCRIPTION_ALREADY_ACTIVE` used to appear on this surface.

**What stays, deliberately:**

- `subscriber_kind = TENANT` remains a column, and rows a deployment already
  has keep working — they are read, invoiced, cancelled and renewed exactly as
  before. `Subscriptions::subscribe` still writes one when something else asks
  for it, which is how a platform grant or a migration would.
- `Sales::SUBSCRIPTION_ALREADY_ACTIVE` survives as a **hold reason**, not a
  refusal: an order placed before today may still be awaiting its payment, and
  when that payment lands `CompleteOrderOnPayment` has to record why it is not
  starting a second subscription.
- `Quote` and its table stay. The console still reads a tenant's quotes
  (`listTenantQuotesForStaff`), and the job that lapses them still runs over
  what a deployment already has. Deleting rows to tidy a surface would destroy
  records of what was offered to a customer.

## 2b. Who may buy — amended 2026-09-25

The decision above said what may be sold and not *who may buy it*, and the
answer was inherited rather than chosen: `billing.pay` went to both tenant
roles on 2026-09-18 so a stranger who had just signed up could pay for what
they chose, and the administrator held it because the role is a superset.

The operator found the consequence on their own catalogue: **an administrator
who subscribes to nothing was offered *Buy for yourself* on every offer.** It
would have worked — a seat in their own name, invoiced by their organisation
to themselves — which is coherent and is not the model.

So `billing.pay` is the member's alone (`Version20260925160000`). It gates
taking out a seat, giving one up before paying, and paying an invoice through
the provider; all three are the buyer's. An administrator reads what their
people hold and records the money that arrived — `billing.manage`, and
`markPaid` — which assumes the money reaches the organisation outside the
platform, as it does.

**And placing an order moved with it.** `POST /sales/orders` answered to
`sales.manage`, so after this change the only person who could place one was
the person who may not buy, while the member who may could not. It now answers
to `billing.pay`, because placing an order *is* buying. The checkout had hidden
that — `Checkout::open` calls `Sales::order` through the service, so no route
permission ever applied and self-service worked all along; the route was simply
pointing at the wrong person.

Fulfilling stays `sales.manage`: raising the invoice is the **seller's** act,
and the seller is the organisation. What is left is a two-party flow that says
the model out loud — the member orders, the organisation invoices, the
administrator records the payment.

## 3. Consequences worth stating plainly

**A working feature was removed.** Quotes did what they said: priced an offer
for a business customer, held it to a date, lapsed on the clock, and refused a
private person. Nothing was wrong with them — they simply have no buyer on
this surface any more. If the organisation ever buys again, this is the
decision to revisit, and the domain is still there to build on.

**The product's supplier identity now gates a sale it never appears on.**
`InvoiceThenSubscribe` still reads `SupplierIdentity::forProduct()` and still
refuses `BILLING_NOT_CONFIGURED` without it — but a seat's invoice is issued
by the *organisation*, so the configured issuer is on none of them.
`ConsoleConfigurationTest` now says both halves out loud rather than asserting
the old one away. Whether the platform should still demand it is a separate
question, and it is the operator's.

**Nothing changed about who is covered.** ADR-053 already made both subscriber
kinds cover the person who took it out plus those they added. This decision is
about what can be *sold*, not about what a subscription entitles.

## 4. Alternatives rejected

**Hide the button and leave the API.** The frontend is never the authority, so
this would have been a claim rather than a rule — and the first client written
against the old contract would have quietly sold an organisation subscription
nobody meant to sell.

**Keep the quote as the negotiated path to an organisation subscription.**
Defensible — self-serve sells seats, a negotiated sale goes through a document
an administrator deliberately raises — and rejected by the operator, who chose
the coherent model over the smaller change when both were put to them.

**Make a quote sell a seat.** A quote carries the organisation's billing
profile as its customer snapshot. The document would have said one party and
the subscription another, which is the kind of disagreement §26 exists to
prevent.
