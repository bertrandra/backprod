# ADR-068 — A paid period renews itself, and is billed for it

**Status:** accepted (2026-10-01)
**Relates to:** ADR-067 (the platform schedules its own work), §13.1 (terms,
commitment, cancellation), §25 / §25.3 (invoices, VAT), ADR-033 (a published
version is frozen), ADR-057 (who sells and who buys), ADR-060 (arrears), R11

## Context

`Subscriptions::renew()` was written, tested, careful — and reachable by nothing.
No endpoint, no job. Its own docblock said so: *"renewal is something time does,
and the job that notices arrives with M7."*

So every subscription on this platform ended at its first paid period, whatever
its term said. A 24-month commitment billed monthly got one month. ADR-067 did
not create that — `isLiveAt()` has always read the clock, so those subscriptions
had already stopped entitling anybody — but it started the sweep that moves the
column to `EXPIRED`, which **displays** it.

And a second thing, worse, which only showed once anything was going to call it:

```php
public function renew(Subscription $subscription, ?DateTimeImmutable $periodEnd): Subscription
```

It moved `current_period_end` and the entitlements forward and **raised no
invoice at all**. Harmless while nothing called it; the moment a job did, every
period after the first would have been given away.

### Two renewals, and conflating them is the expensive mistake

```text
current_period_end   the paid period rolls      the contract running
term_ends_at         the term rolls             a new contract, tacitly
```

Rolling period 7 of a 24-month commitment into period 8 is the agreed contract
doing what it says. Rolling the *term* into a second 24 months is a fresh
commitment, and consumer law requires a prior notice whose deadline
`SendRenewalNotices` states is unconfirmed — *"especially toward consumers where
they vary by member state"*. That is a legal question, not a coding one.

## Decision

**This rolls periods inside a term and stops at it.** The term stays a decision
somebody takes. R11 remains open for exactly the half it was opened for, and
nothing here renews a commitment unattended.

**Billing is a required argument of renewing.** `renew()` takes a `callable
$alsoBill` with no default, so a caller that forgets does not compile — the rule
`Reach` is required for and the one ADR-066 restated about optional subscribers.
`RenewalCharge` / `ChargeOnRenewal` is the sibling of `EarlyTerminationCharge`,
deliberately shaped like it so the two cannot drift about who sells, who buys and
how tax is decided. The invoice is numbered, dated, taxed, and raised **on the
renewal's transaction**: a period extended unbilled is revenue given away, and an
invoice for a period the subscription never got is a customer charged for nothing.

**One period, at the price the customer holds.** From the offer **version the
subscription snapshotted**, never the offer as it stands today (ADR-033, §13.1).
A renewal is where that promise is actually tested, being the first document
raised after the catalogue has had time to move. The quantity is one period and
never a number of months: the agreed price is per period.

**The rate is decided now.** This is a supply happening today, so §25.3 applies
to it today rather than the rate the first period was billed at.

**Off unless the operator chose it.** `RenewalPolicy`, read from
`product_configuration` on every pass like the dunning schedule — never a
constant, because two products have no reason to renew on the same rhythm. Absent
means off, which is exactly today's behaviour: a deployment upgrading into this
changes nothing until somebody says so. The reason is this setting's own, as
ADR-063's was — what silence would cost here is a customer billed for a period
nobody decided to sell them.

**It renews before the period ends, never after.** The first design renewed what
had lapsed, which put it in a race with `sweep.subscriptions` over the same rows:
both daily, the sweep expiring exactly what `current_period_end < now()` selects,
and whichever won deciding whether somebody kept their subscription. A lead window
removes the race rather than ordering it — a subscription still inside its period
is not lapsed, so the sweep never sees it. It also bills the period before it
starts, which is what a continuous subscription and "payable on receipt" already
mean.

**One period is billed once, by the statement and not by a check.** The update is
conditioned on the `current_period_end` it was asked about, so two overlapping
passes cannot both roll it and cannot both invoice it. The loser gets
`RENEWAL_ALREADY_APPLIED` and nothing is billed.

**Nothing in the job decides anything about one subscription.** It asks
`renew()`, which holds every rule in order: a cancellation already due, a change
the customer asked for, an offer sold as ending at its term, the term itself, a
period with no price. Deciding any of that in the handler would be a second place
it could be decided from.

**Arrears are not renewed.** A `PAST_DUE` subscription owes for the period it
already had and is in the dunning pass's hands (ADR-060); a second invoice against
a suspended service compounds a debt somebody is already being chased for.

**A period that would run past the term is refused, not shortened.** Billing a
whole period for part of one overcharges; capping the end would sell service past
the contract. `RENEWAL_WOULD_PASS_TERM`, and the subscription reaches its term.

## Consequences

- Nothing changes on any deployment until `product_configuration` says
  `{"renewal": {"automatic": true}}` for a product. Switching it on is the
  decision to sell further periods automatically.
- A `CUSTOM` billing period cannot be renewed — `RENEWAL_NOT_PRICEABLE`, the same
  refusal the buy-out makes, for the same reason. Before this, `renew()` rolled
  such a subscription into an open-ended period for free. The freemium is
  unaffected: it is `ENDS_AT_TERM` and expires one step earlier.
- `renew()` has one caller and it is now a job. The service method keeps its
  signature, so a future endpoint or a console button is unaffected.
- **R11 is narrowed, not closed.** What remains open is tacit renewal of a term,
  and what it needs is a confirmed notice deadline per jurisdiction — not code.
  `SendRenewalNotices` already produces the notice and keeps the rendered body;
  nothing yet reads it as a precondition, because nothing yet renews a term.
- The lead window is also the outage window: cron down for longer than a product's
  lead lapses the subscription, and the sweep then expires it. That is an
  operational failure the operator must fix in any case — `preflight` reports a
  stopped cron — and `renew()` stays reachable by hand.
- `subscription.renewal` is declared before `sweep.subscriptions` in `Schedule`
  as a courtesy and not as a guarantee. The two never hold the same row, so the
  order between them decides nothing. Ordering them would have been the fix for a
  race; not sharing rows is the fix for there being one.
