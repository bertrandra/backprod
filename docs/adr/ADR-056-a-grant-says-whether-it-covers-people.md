# ADR-056 — A grant says whether it covers people, and a seat is billed by whoever sold it

**Status:** accepted, 2026-09-25.
**Amends:** [ADR-053](ADR-053-buying-covers-people-membership-does-not.md)
(coverage excluded grants unconditionally),
[ADR-055](ADR-055-the-tenant-surface-sells-seats-and-nothing-else.md) (which
missed a door), and the parties on every document raised against a sale.
**Related:** [ADR-024](ADR-024-payment-gated-activation.md) (nothing is
provisioned before the money arrives),
[ADR-054](ADR-054-a-gapless-series-belongs-to-its-issuer.md).

Three things the operator flagged after reading the previous two ADRs, and one
they did not, which was mine.

## 1. The platform could not give a trial

ADR-053 made coverage a question about subscriptions and excluded grants on a
stated principle: *staff hand out a feature, never a seat.* The principle is
right — a support engineer restoring one missing capability must not thereby
hand every member of the organisation a workspace — and its consequence went
unnoticed: **a granted product lit up its features, said "provided by the
platform" on the subscription screen, and refused every workspace.** The
platform could give a customer a feature and could not give them a trial.

The answer is not to make grants cover people. It is to let the platform *say*
which of the two it is doing, because they really are two things:

```text
covers_people = false   this tenant has this feature      (an exception)
covers_people = true    these people may use this product (a trial)
```

**False by default**, which is what every grant written before today meant and
what a support exception means when nobody thinks about it. The wider answer is
the one that takes a decision.

A covering grant reaches **every member of the tenant**, and names nobody —
because a trial has nobody to name, and because the caller reached the product
through a membership the request context resolved before any of this ran. The
flag sits on every row of a grant rather than on a header, because a grant has
no header: it is the set of `entitlements` rows with `source = 'GRANT'` for a
(tenant, product), rewritten whole each time. A `CHECK` refuses it on a
subscription's rows, where coverage is already answered by the people named on
the subscription.

## 2. A buy-out billed nobody who was party to it

`ChargeOnEarlyTermination` raised its invoice *product supplier → organisation*
for every subscription, including a seat. A seat is the organisation selling to
one of its own people (2026-09-19), so ending one was billed as the platform
charging the company for a contract the company had sold to a member of its
staff. Nobody on that document was a party to the thing being ended.

The decision now lives in one place, `WhoSellsAndWhoBuys`, which answers the
issuer, the supplier, the customer and the VAT jurisdiction **together** —
because they are one decision, and a document whose supplier block names the
organisation while its number came from the platform's series is one neither
party can account for. Both services that raise an invoice against a sale ask
it.

## 3. A product's billing identity gated a sale it never appeared on

`InvoiceThenSubscribe` read `SupplierIdentity::forProduct()` before branching,
so a product with no issuer configured refused a *seat* — a document its
identity would never have appeared on. It was read for a jurisdiction the
organisation's profile had not given, and the fallback was the platform's own
country: a seat sold by a company that had not said where it trades filed its
VAT in somebody else's return.

Both are gone. A seat never reads the product's identity, and an organisation
that has not said which country it sells from does not sell: `BILLING_PROFILE_REQUIRED`
with `missing: ['country_code']`, refused before anything is written, because
numbering is gapless.

The console's issuer still decides the **platform's** own invoice, which is
what `issueForSubscription` raises against an organisation's subscription.
`ConsoleConfigurationTest` now says the two halves separately instead of
asserting the one that stopped being true.

## 4. ADR-055 did not close the door it said it closed

This one is mine, and it is worth writing down rather than quietly fixing.

ADR-055 states that `Subscriber::tenant()` is unreachable from the tenant
surface. It was not. `POST /api/v1/subscription` — `SubscribeController` —
still existed, defaulted to the organisation, and was gated on
`subscription.manage`, **which every USER holds**. Worse than the sale it
allowed: it started a subscription with *no invoice and no payment*, which is
ADR-024's rule broken outright. The frontend hook that called it
(`useSubscribe`) was used by no screen, which is why nothing noticed.

I found it while writing a test for something else. The route, the controller,
the operation and the hook are gone. `Subscriptions::subscribe` stays as a
service — the platform still holds subscriptions of its own, and the fixtures
that set that state call what the platform calls.

**What this says about the gates.** `gate:screens` proves every claimed
operation is *called through the generated client*; it does not prove a screen
calls it. A hook nobody uses satisfies it. That is not a hole worth closing by
making the gate stricter — a hook written before its screen is ordinary — but
it is worth knowing that "the operation is covered" and "somebody can reach
it" are different claims, and only the first is checked.

## 5. What was considered and rejected

**Make any grant cover people.** Simple, no migration, and unsafe: `ProjectRoute`
asks coverage before permission, so a grant of any feature at all would let
every member read every project. That is precisely the hole ADR-053 closed.

**Make a trial a subscription the platform grants.** It would cover its owner
and the people added to it — so a trial granted that way covers one person the
platform picked, which is not what "this organisation may try this product"
means.

**Leave the buy-out billing as it was and only fix the numbering.** The number
would then have followed a supplier that was wrong. Consistency with a mistake
is not a property worth keeping.
