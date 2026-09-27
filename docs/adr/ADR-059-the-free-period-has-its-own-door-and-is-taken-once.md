# ADR-059 — The free period has its own door, and it is taken once for all

**Status:** accepted, 2026-09-27.
**Implements:** `docs/subscription-lifecycle-spec.md` §6 and §8, étape 6.
**Amends:** [ADR-056](ADR-056-a-grant-says-whether-it-covers-people.md) §4,
which closed the last tenant door that started a subscription with no invoice,
and the ladder's plan ranks in `DemoWorld`.
**Related:** [ADR-024](ADR-024-payment-gated-activation.md) (nothing priced is
provisioned before the money arrives),
[ADR-055](ADR-055-the-tenant-surface-sells-seats-and-nothing-else.md),
[ADR-033](ADR-033-a-published-offer-version-is-frozen.md),
[ADR-054](ADR-054-a-gapless-series-belongs-to-its-issuer.md) (why a €0 invoice
is a permanent record of nothing).

## 1. Context

The operator asked for a freemium: a plan that gives a product away for a short
period, **one user, one project, five days** in the demonstration. Three things
about it do not fit the paths that exist.

**It raises no document, and must not.** The checkout chain composes an order,
its invoice and a payment at the provider. `InvoiceThenSubscribe` raises the
invoice at fulfilment whatever the amount — *"nothing to collect is not the same
as nothing to do"* — which is right for a priced order settled by credit and
wrong for a free period: numbering is gapless, so the €0 document would be a
permanent, unremovable record of no transaction sitting in the middle of a series
a tax authority reads. `CLAUDE.md` already says it: nothing outstanding raises no
document at all.

**It is bounded in days, and no column says days.** `term_months` cannot express
five days, and spec §6.3 weighs the two ways round: a `trial_days` column on
`subscriptions`, or `current_period_end = now() + interval` written once at
subscription with no term at all. It recommends the second.

**A tenant door that starts a subscription with no invoice is exactly what
ADR-056 §4 removed**, and that is worth facing rather than working around.
`POST /api/v1/subscription` defaulted to the organisation, was gated on a
permission every member holds, and started a subscription with no invoice and no
payment **for a priced offer** — credit extended to anybody who could reach the
endpoint.

## 2. Decision

**`POST /api/v1/subscription/freemium` (`startFreemium`), in
`Commerce\Service\Freemium`.** Not the checkout, which has nothing to compose;
not `Subscriptions::subscribe`, whose door was closed for reasons that still
hold. It shares none of the three faults ADR-056 names:

- the subscriber is always the caller, a seat, taken from the resolved context —
  there is no id in the body and so nothing to check one against;
- the permission is `billing.pay`, the member's and not the administrator's,
  because acquiring a subscription for oneself is the act it names;
- and the offer must be **free and non-renewing**, refused otherwise. No money
  is waited for because none is owed, which leaves ADR-024 exactly where it was.

`Sales::order()` refuses the same offer from the other side
(`FREEMIUM_IS_NOT_SOLD`), before an order exists — so there is one door and not
one and a half.

**A freemium is recognised by its properties, never by a plan's code.**
`OfferVersion::isFreemium()` is `isFree() && terms->endsAtTerm()`. Both halves,
because neither alone is the thing: a free offer that *renews* is a free tier
somebody may hold for years, and a priced offer that ends at its term is an
ordinary fixed-term contract. Asking "is this the freemium?" of a name is the
shape §13 forbids and `gate:plans` catches.

**Once, and once for all — an index, and without a status filter.**

```sql
ALTER TABLE subscriptions ADD COLUMN is_freemium boolean NOT NULL DEFAULT false;

CREATE UNIQUE INDEX subscriptions_one_freemium_ever
    ON subscriptions (product_id, coalesce(subscriber_user_id, tenant_id))
 WHERE is_freemium;
```

The absent status filter *is* the rule: a freemium that expired six months ago
still forbids a new one, or five free days are retaken every five days and the
product is free for ever by recurrence. An index and never an application check,
for the reason §13.1 gives about the active-subscription indexes — two
simultaneous requests each read "none yet" and both insert.

The fact is **snapshotted** onto the subscription, like the terms, and never
joined to the offer version, which says what that plan is today rather than what
was sold. It survives a move up to a paid plan: the right has been used. There is
therefore **no CHECK** tying `is_freemium` to `renewal = 'ENDS_AT_TERM'` — the
constraint reads well and would refuse the upgrade out of the free plan, locking
somebody inside it.

**How long it runs is the product's configuration**, under the key
`FreemiumPeriod::CONFIGURATION_KEY` (`freemium`, `{"days": 5}`), beside the
billing supplier (ADR-044) and the tax position. Absent means the product gives
no free period and the door refuses (`FREEMIUM_NOT_OFFERED`) rather than guessing
an interval.

**The ladder's ranks step by ten from ten.** The freemium is the lowest plan, so
freemium 10, lecture 20, starter 30, pro 40, scale 50 — one table for the
platform rather than one per product, because a rank is a tier and `starter`
meaning 30 in one product and 10 in another would make every cross-product
reading a translation. `plans` carries no constraint on `rank`; it is an ordering
read by `Subscriptions::directionBetween()`, so this is a change of constants in
`DemoWorld` and **no migration**.

**Renewal ends what was sold as ending.** `Subscriptions::renew()` gains a third
step, after a cancellation already due and after a change the customer asked for:
a subscription whose snapshotted terms say `ENDS_AT_TERM` is expired rather than
rolled. It is not only the freemium's — `ENDS_AT_TERM` has been sellable since
§13.1 and nothing read it, so every fixed-term contract renewed itself for ever.
The waiting change wins over the term deliberately: "does not renew" means this
offer does not roll into another period of itself, not that the subscription may
not become the thing its holder chose.

## 3. Consequences

The demonstration has a free period in it — one person on Plan, one project, five
days — seeded through the only door it has, and `DemoFixtures::verify` refuses a
world without it: the offer free and finite, the days configured, one user and one
project sold, somebody on it with their five days running, and **no order and no
invoice against it**. A world seeded without it renders identically, with one
fewer card, which is exactly why it is counted rather than looked at.

What is deliberately **not** decided here:

- **the catalogue screen** (spec §7, étape 7). `useStartFreemium` exists and no
  screen calls it yet, which is the ordinary order and the thing ADR-056 §4 says
  the gates do not prove. Until it lands, somebody who has already had their free
  period discovers it at the click, as `FREEMIUM_ALREADY_USED` — spec §6.4 asks
  the catalogue to say so beforehand, and hiding is courtesy either way;
- **changing a seat's offer.** `change-offer` and `pending` resolve the
  *organisation's* subscription, so freemium → Lecture as spec §6.2 describes it
  is not reachable for a seat today. That is a gap older than this step and it
  belongs with étapes 3 and 4, which are about that move.

## 4. Alternatives considered

**Let the freemium go through `openCheckoutSession`.** One path for everything,
and it raises the €0 invoice at fulfilment. Rejected by the rule the platform
already states, and it would have been discovered by an accountant reading a
series with a free trial in it.

**A `trial_days` column on `subscriptions`.** Weighed in spec §6.3 and rejected
there: the period already exists and knows how to end, and a second number saying
when would be a second answer to one question.

**Five days as a constant in PHP.** The shortest change, and a commercial term an
operator cannot alter without a release — the same defect `VITE_DEFAULT_PRODUCT`
had before an organisation could set its own default product.

**A `free_period_days` column on `offer_versions`.** Defensible: it is a term,
and terms live on the version. Rejected because it would have to be authored,
exposed in the offer-drafting contract and regenerated, for a number that is one
per product in practice — and a published version is frozen (ADR-033), so an
operator changing their trial length would have to publish a new version of the
offer to do it.

**A status filter on the index, or an application check.** Both were written
down only to be refused: the first makes the rule "one at a time", which is no
limit at all, and the second is a race two requests walk through.
