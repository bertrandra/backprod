# ADR-030 — The payment period is not the commitment

**Status:** accepted
**Decides:** what a subscription commits to, who it entitles, and what leaving early costs
**Relates to:** Architecture V2 §13, §13.1, §25, §25.3, §37.4;
extends the entitlement resolution of [ADR-020](ADR-020-entitlement-resolution.md)

## Context

M5 gave a subscription a billing period, a status and `cancel_at_period_end`.
That is enough to bill somebody every month and to stop. It is not enough to
sell them anything, because it cannot express the thing a B2B contract is
mostly made of:

> a 24-month subscription billed monthly is **one** 24-month commitment billed
> 24 times — not 24 one-month subscriptions that happen to follow each other

Without that distinction the platform has no way to say no to a customer who
signed for two years and wants out in month three, and no way to charge them
if the contract says leaving early costs something. Both of those are options
a commercial team sets per offer, not a policy the platform holds.

The same table also assumed one subscriber: the tenant. §13.1 says a *user*
may take one out too — a seat — which is a different scope entirely, and one
that entitlement resolution had no way to represent.

## Decision

### Terms are snapshotted from the offer version, like an invoice

What a subscriber agreed to is copied onto the subscription when it is taken
out: term, commitment, cancellation policy, renewal, early-termination rule
and notice period. This is §25's invoice-snapshot rule applied to the
contract. A repriced or re-termed offer must not change one condition a
customer already agreed to, and reading terms live through the version would
do exactly that, silently, the morning after a price change.

### A commitment is a date, evaluated against the clock

`commitment_ends_at` is stored, and `subscriptions_commitment_is_dated` makes
a commitment and its date inseparable in both directions. Whether a
subscription is under commitment is a question about *now* versus that date —
never about whether a job has run. It is the same rule the offer windows,
entitlements, quote expiry, job leases, signed links and VAT rate windows all
follow:

> a lapse is a fact about the clock, never about whether something ran

### Refusals are motivated, and the diagnostic shares the code path

Cancelling under a commitment returns a decision carrying which rule decided,
when it takes effect, what it costs and what the customer was told. "No"
without a reason is the answer that generates a support ticket, and an
unrecorded refusal leaves "I cancelled" against "we received nothing" with no
arbiter.

`GET /subscription/schedule` answers the same question with no side effect by
running the same `CancellationPolicy` call. The two cannot disagree, for the
same reason `/tax/calculate` is not a second implementation of invoicing.

An unknown cancellation policy is refused rather than guessed. Guessing would
either trap a customer who may leave or release one who may not.

### Leaving early raises a real document, on the cancellation's transaction

Where the offer sells the exit, the buy-out is an invoice — numbered, dated,
taxed, and taxed *now* under §25.3 rather than at whatever rate the original
subscription was billed at. A charge that exists only as a number on a
subscription row is a charge nobody can dispute, refund or declare.

It runs through a participating method on the caller's open transaction, like
every other cross-module write in this codebase (`applyActivate`,
`applyIssue`, `applyTransition`, `recordTransactions`). A subscription
released with its buy-out unbilled is revenue given away; a buy-out billed
against a subscription still running is a customer charged for an exit they
did not get. Neither may be observable, including after a crash between the
two.

Three details of the price were each decided against a plausible alternative:

- **Counted from the end of the period already paid for**, not from now. A
  yearly plan left in month 11 of a 24-month commitment has twelve months
  outstanding, not thirteen. Counting from now bills the year the customer has
  already settled a second time.
- **Priced in billing periods**, not months. The commitment is counted in
  months because that is the unit it was sold in, but the price agreed is a
  price *per period*. A yearly price multiplied by a number of months is a
  figure that appears on no contract.
- **Nothing outstanding raises nothing.** Numbering is gapless, so a €0
  document is a permanent, unremovable record of no transaction.

A buy-out on a `CUSTOM` billing period cannot be priced at all — there is no
period to count — so the catalogue refuses to sell one:
`offer_versions_buyout_is_priceable`. The invariant is in the database rather
than in the service that would otherwise have to guess.

### A seat is addressed to a person, and entitlement resolution asks who

`subscriber_kind` distinguishes a tenant subscription from a seat, and two
partial unique indexes replace M5's single one: one active subscription per
tenant and product, one active seat per person. Partial, because "already
subscribed" is a different question for each scope.

Capability resolution takes the person and excludes exactly one thing: an
entitlement whose subscription is a seat belonging to somebody else. Written
with `IS DISTINCT FROM`, so that naming nobody does not collapse the condition
to null but means what it should — with no person in the question, every seat
is somebody else's.

Without this the feature is decorative: one person buys a seat and the whole
tenant is entitled, which is the opposite of what a seat is.

## Consequences

Offers gain a real commercial vocabulary: committed or not, cancellable when,
exit forbidden or free or chargeable, fixed-term or rolling. None of it is a
platform policy; all of it is per-offer paramétrage, so no code branches on
which plan or product is involved (§13, non-negotiable).

Every M5 offer keeps meaning what it meant. `SubscriptionTerms::openEnded()`
is the default carried by `OfferVersion`, and the migration's column defaults
match it: month-to-month, no commitment, cancellable at any time.

Cancellation is now a two-part answer — the subscription and the decision —
so the endpoint returns both. Callers that only looked at the subscription
still find it where it was.

What this does **not** do, deliberately:

- **Quotas stay tenant-scoped.** A seat must not silently raise the tenant's
  limit, so `QuotaPolicy` keeps asking the tenant-wide question.
  `/api/v1/entitlements` likewise still reports what the *tenant* holds.
- **A seat is bought by its holder.** Buying one on behalf of a colleague
  needs a check that they belong to this tenant, and is a different endpoint.
- **Tacit renewal has no notice job yet.** The notice period is stored and the
  pre-renewal notification exists (M7.1), but which legal deadline applies is
  jurisdictional and unasserted — carried as R11 rather than guessed at.
