# ADR-066 — Every subscription is a seat

**Status:** accepted (2026-10-01)
**Relates to:** ADR-053 (buying covers people), ADR-055 (the tenant surface sells
seats), ADR-056 (no subscription without a sale), ADR-057 (who sells and who
buys), §13.1, §25.3

## Context

`subscriptions.subscriber_kind` has held two values since 2026-09-04:

```text
TENANT   the organisation is the contracting party
USER     one person is (a seat)
```

ADR-055 stopped selling the first on 2026-09-25. `Sales::order()` gained no
argument for it, `openCheckoutSession` no field, and ADR-056 removed `POST
/subscription`, the last door that started one. From then on the column was
constant in practice.

What the variant left behind was not nothing:

- **Four production defaults** fell back to the organisation for any caller that
  omitted an optional argument — `applyActivate()`, `Subscriptions::subscribe()`,
  `placeOrder()`, and `Order`'s own constructor.
- **A `Subscriber` value object** with a `tenant()` constructor nothing called,
  an `isSeat()` every caller branched on, and a `kind` carried into the contract
  and out to products over webhooks.
- **Two partial unique indexes** where one bounded a set that is always empty.
- **A second gate on `/subscription/people`, `/subscription/cancel` and
  `/subscription/schedule`** — a `seat` flag choosing between the caller's seat
  and a subscription that cannot exist.

The defaults are the part that mattered. Since ADR-053 an organisation's
subscription covers nobody by itself, so a subscription created by a forgetful
caller would be paid for and entitle no one — the buyer included. That is the
shape the `Reach` leak had (ADR-064): an argument you may omit is one that
eventually is.

The operator confirmed on 2026-10-01 that no deployment is in production, which
is what makes a destructive migration available.

## Decision

- **`subscriber_kind` is dropped** from `subscriptions` and from `orders`, and
  `subscriber_user_id` becomes NOT NULL on both.
- **`Subscriber` is deleted.** The subscriber is a `string $subscriberUserId`,
  **required** at every call site, so a caller that forgets does not compile.
- **The migration refuses rather than deletes.** It counts the rows that are not
  seats and aborts naming the number, because a deployment holding them has real
  subscriptions and this cannot guess whose.
- **One unique index**: one live subscription per person per `(tenant, product)`,
  `ACTIVE` or `PAST_DUE` (ADR-060 — arrears are not an exit).
- **`findActive(tenant, product)` is gone.** There is no "the subscription" in a
  scope any more; every question about one names somebody.
- **The response collapses**: `subscription` (the organisation's) and
  `organisation_subscribed` leave `showSubscription`, which answers `seat` —
  the caller's own — and `coverage` — what covers them, whoever holds it.
- **`reportProductUsage` takes a `user_id`**, required.
- **The holder may act on their own subscription**, not only whoever holds
  `subscription.manage`.

## Rationale

### Why the column goes rather than the defaults alone

Removing the four defaults was the smaller change and was the first
recommendation. The operator chose the larger one, and it is the better end
state for a reason the smaller one does not reach: a column that can hold a
value nothing can create is a column every reader has to keep having an opinion
about. `isSeat()` appeared in nine places, each branching on a question with one
possible answer, and each of those branches was a path no test could exercise
and no customer could reach.

### Why the migration refuses

A deployment from before 2026-09-25 has `TENANT` rows that are real
subscriptions somebody is paying for. Dropping the column would turn each into a
seat belonging to nobody; making `subscriber_user_id` NOT NULL would fail
halfway with the table already altered. So it looks first, and says how many it
found, because the number is what decides what to do about them.

### What this costs, and it is not nothing

**Reverse charge becomes unreachable by any sale.** The only sale left is a
seat, which ADR-057 makes domestic: the organisation, in its own country, to one
of its own people. §25.3 already said reverse charge cannot arise on one — what
is new is that no *other* sale exists, so the platform raises no cross-border
B2B document at all.

The regime is not removed. It stays decided in `TaxRule` and proved there, and
the fiscal fact that records one is still refused unless the customer's number
was verified — a constraint in the database since the first fiscal migration,
and now proved by a test that asserts *which* constraint refuses, because the
first version of that test tripped a different one and proved nothing.

**The tenant-wide entitlement answer becomes empty.** Naming nobody asks what
the *organisation* bought, and an organisation buys nothing. A test written
precisely to catch that answer emptying — "quotas, staff screens and readiness
all reading zero, with nothing failing loudly" — is what said so.

The fix is not to redefine the question. It is to stop asking it: the usage read,
the quota check and a product reporting usage all name a person now. That is
§13.1's own rule — every gate asks about the same somebody — reaching the two
places it had not. A product reporting usage therefore takes a `user_id`, and it
is required rather than optional with a fallback, because an absent field
silently meaning "nobody" is the shape this decision exists to remove.

### Why the holder may act

`YourSeat` rendered the caller's seat beside the organisation's subscription and
carried the controls a holder needs. With one subscription it and the main
section are the same thing twice, so it went — and the controls behind it were
gated on `subscription.manage`, which was right for a subscription binding
everybody and wrong for one bought with a person's own card.

Left there, a seat would be something you can take out and not give up, which
§13.1 calls not a subscription at all. So the controls answer "it is yours"
(`coverage.own`) as well as the role. Courtesy, as every frontend gate is: the
API decides, and `cancel` with no id can only mean the caller's own.

### What is deliberately left

**The `seat` flags on three requests.** `/subscription/people`,
`/subscription/cancel` and `/subscription/schedule` still accept one, and it
decides nothing. Removing a field a client sends is its own change, with its own
regeneration and its own screens to follow; doing it here would have mixed a
schema removal with an API break.

**`NOT_THE_OWNER` is now unreachable**, and nothing in the suite reached it
before either: a seat's owner is always the person it is addressed to, and the
only other way in is gone. The guard stays in `SubscriptionPeople::owned()`
because `owner_user_id` is nullable and deleting a refusal is not something to
do in passing — but it is dead, and the tests say so where a reader will find
it.

## Consequences

- A deployment with `TENANT` rows cannot run this migration until somebody
  decides what those rows are. That is the intended cost.
- `subscriptions_one_freemium_ever` still reads
  `COALESCE(subscriber_user_id, tenant_id)` over a column that can no longer be
  null. Harmless, and left alone rather than changed for tidiness.
- The webhook envelope says `subscriber_user_id` where it said
  `subscriber: {kind, user_id}`. A product reading the old shape gets nothing;
  the field it ever acted on is beside it.
