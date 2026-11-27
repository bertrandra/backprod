# ADR-077 — A change of plan is not a way out, nor a way to keep what was not bought

**Status:** accepted (2026-11-27)
**Relates to:** ADR-053 (buying covers people; membership does not), ADR-055
(the tenant surface sells a seat), ADR-071/072 (renewal), ADR-076 (money follows
the document it pays), spec §3.3 (the commitment survives a change of plan), §13.1

## Context

The second half of the 2026-11-27 audit of the subscription cycle: what a change
of offer did to the *contract*, as opposed to the money.

**The commitment's date survived a change of plan and its meaning did not.**
`reSnapshot` kept `commitment_months` and `commitment_ends_at` — spec §3.3, "a
change of plan is not a new contract" — and re-copied `cancellation_policy` and
`early_termination` from the arriving offer. `CancellationPolicy` reads those
two. A customer committed for twenty-four months on `AT_COMMITMENT_END` /
`FORBIDDEN` who moved to an offer sold `ANYTIME` could then leave at the end of
the month; onto one sold `FREE`, at once and for nothing. And where a buy-out
*was* charged, it was priced at the version held on the day of leaving — a
figure the customer chose by changing plan the day before. It cut the other way
too: a commitment sold as cancellable at any time acquired the arriving offer's
stricter rule without anybody agreeing to it.

**`POST /subscription/pending` skipped a refusal `changeOffer` makes.** A move
down onto an offer whose term the surviving commitment outlasts was accepted; at
the renewal that applied it the database's `commitment_months <= term_months`
refused the row on every pass; the period lapsed and the sweep expired a
committed subscription with no buy-out.

**A move up ignored a cancellation already scheduled.** It reset the period to a
whole new one from today and billed it, while `cancel_effective_at` kept the old
date — which renewal skips the row for — so the service ran to the end of the
new period, past the date the customer had been given.

**A move up left a scheduled move down in place**, and the next renewal applied
it: the customer who had just taken the top plan found themselves on the bottom
one.

**The `users` quota was checked when somebody was added and nowhere else.** A
subscription for five moved to an offer for one went on covering five.

## Decision

**1. The exit conditions agreed with a commitment survive beside it.** While the
commitment already held is the one that survives a move — it still binds at the
moment the move takes effect, and the arriving offer's own does not end later —
`cancellation_policy` and `early_termination` are **kept**, not re-copied
(`Subscription::keepsAgreedExitAfterMovingTo()`, `termsAfterMovingTo()`). Where
the arriving offer's commitment ends later, that is the commitment now in force,
agreed today on that offer's conditions, and they apply. Where no commitment is
in force, the arriving offer's conditions apply, as before. The term, the
renewal mode and the notice are the arriving offer's in every case: they
describe the offer held, not what was promised about leaving.

**2. A buy-out is priced at the version the commitment was agreed on.**
`subscriptions.commitment_version_id` names it when it is no longer the version
held; **null means "the one held"**, so nothing is backfilled and no insert path
changes. It is written when a move keeps the commitment, cleared when a later
commitment takes over, and cleared when a term re-arms (ADR-071): a commitment
re-armed is agreed on the offer held when it re-arms. `ChargeOnEarlyTermination`
reads `Subscription::agreedOffer()`.

**3. Scheduling a move down makes the refusal `changeOffer` makes**, and both
evaluate it **at the boundary the move takes effect at** — now for a move up,
the end of the paid period for a move down — by the same call the repository
then makes when it writes.

**4. One ending, and the cancellation is it.** A subscription due to end refuses
*any* change of plan with `SUBSCRIPTION_ENDING`, the code a move down already
answered with; the preview refuses the same way. The customer resumes first and
then chooses a plan, rather than the platform deciding which of the two they
meant.

**5. One intention at a time.** An immediate change clears a pending one, on the
same `UPDATE`, and records `CHANGE_CANCELLED` with `superseded_by:
immediate_change` so the destination that was waiting stays answerable.

**6. A change to an offer that sells fewer places stops covering the people
beyond the new number** (the operator's decision, 2026-11-27).

- **On the transaction that moves the plan** — at once for a move up, at the
  renewal that applies a move down — never later and never by a sweep.
- **The most recently added go first** (`added_at DESC`, the user id settling a
  tie). Whoever has been on the subscription longest keeps their place: the
  order a holder would guess, and the only one that asks them nothing at the
  moment the plan changes. Somebody who wants a different person kept removes
  one and adds the other back.
- **Never the holder**, whom a subscription covers by definition; and nobody who
  administers the organisation, who takes no place to begin with (2026-09-30).
  One number in one place still: the count is `PlacesUsedSql`'s.
- **Deactivated, not deleted.** The person keeps their account, their membership
  and their role, and the work stays where ADR-064 puts it — with whoever is
  paying. They lose coverage: the workshop answers `SUBSCRIPTION_REQUIRED`.
- **Everybody concerned is told.** `subscription.places_withdrawn` to the holder,
  naming who; `subscription.place_withdrawn` to each person. `BILLING`, raised on
  the same transaction (rows up front), sent through the queue, once per person
  per change by the dedup index.
- **Said before the click.** `preview-change` answers
  `people_losing_their_place` — the same count the change then acts on — and the
  catalogue's confirm step renders it.

## Consequences

- An existing assertion changes on purpose: a 24-month commitment sold
  `ANYTIME` moved onto an `AT_COMMITMENT_END` offer used to *become*
  `AT_COMMITMENT_END`; it stays `ANYTIME` until the commitment ends.
- A term that re-arms keeps the exit conditions on the row and prices its
  buy-out at the version held. A re-armed commitment whose plan moved in the term
  before therefore keeps rules agreed two offers ago; correcting that is a
  decision about tacit renewal, not about a change of plan, and is left to one.
- The count shown in a preview is true when asked. Somebody added between the
  preview and the change is counted by the change, under the row's lock.
- Not decided here: usage over a *quota* after a move down (projects beyond
  `max_projects`). Creation is refused; what exists is kept, as it was.

## Proof

`SubscriptionLifecycleTest` — the exit conditions kept and the agreed offer
priced; a later commitment bringing its own; the scheduled move down refused;
the move up refused while ending, and its preview; the pending change cleared;
places withdrawn most-recent-first with both notices and the preview's count.
`CatalogueScreen.test.tsx` — the warning, from the server's count.
