# ADR-053 — Buying covers people; membership does not

**Status:** accepted, 2026-09-25.
**Amends:** the subscriber rule in `CLAUDE.md` and `docs/architecture-v2.md`
§13.1, and the exclusion in `PostgresEntitlementRepository::IN_FORCE`.
**Related:** [ADR-015](ADR-015-the-backend-derives-tenant-context.md) (the
backend derives context, never the client), and §13.1's separation of offer,
subscription and entitlement.

## 1. Context

Until today the platform read a subscription's `subscriber_kind` to decide
who it entitled:

```text
subscriber_kind = TENANT   entitles every member
subscriber_kind = USER     entitles that person only (a seat)
```

The operator found what the first line means by using their own product.
They added a person to Acme, and that person — on no subscription, holding
no seat, having bought nothing — received all eleven of Pro's capabilities
and could list every project in the organisation.

Reproduced on the demonstration world before anything was changed:

```text
acme-user2, member of Acme, on no subscription
  GET /api/v1/projects          200, 2 projects
  GET /api/v1/me/entitlements   exports, max_projects, plan.3d, plan.cadastre,
                                plan.export.dxf, plan.ortho, plan.plu,
                                plan.terrasse, users, white_label
```

Two things were wrong, and only one of them was the rule above.

**The `users` quota was decorative for an organisation's subscription.**
Acme's Plan subscription sells three people (`users: 3` on Pro) and listed
none: `subscription_members` was empty, while every member was entitled by
membership regardless. The quota bounded a list nobody was on. A number a
customer pays for has to bound something, or it is a line on an invoice for
nothing.

**Reading the work required no entitlement at all.** `ProjectRoute` asked
for the `projects.read` permission, which every `USER` role carries by
membership, and asked nothing else. An organisation with no subscription
whatsoever still answered `200` to its members.

## 2. Decision

**The kind of subscriber no longer decides who is covered.** Either kind
entitles the same set:

- the person who took the subscription out (its `owner_user_id`, and its
  `subscriber_user_id` where one is named), and
- the people that person has added to it, within the `users` quota their
  offer sells.

Joining an organisation gets somebody a role. It does not get them an
entitlement.

**A workspace asks two questions, in this order:**

```php
$context->requireSubscription();   // is the work theirs to reach at all
$context->requirePermission($p);   // what may they do with it
```

Coverage first, because it is the broader refusal: somebody no subscription
covers should be told that, not told their role is insufficient for work
they were never entitled to reach.

**`SUBSCRIPTION_REQUIRED` is a fifth refusal**, distinct from the four the
platform already had, and the distinction is the whole point.
`ENTITLEMENT_REQUIRED` says the organisation never bought the feature; this
says it did and **you are not one of the people it covers**. Collapsing them
would tell somebody to buy what their colleague is already paying for. It is
answered by a colleague, not by an administrator, a purchase or a deletion.

## 3. Coverage is its own question

`EntitlementRepository::covers()`, not a derivation from capabilities. Both
obvious shortcuts are wrong, in opposite directions:

- **"Do they hold any capability?"** A tenant-wide override grants features
  to an organisation with no subscription behind them. Answered that way,
  one support exception would cover every member — the same shape as the
  hole being closed.
- **"Do they hold the workspace quota?"** Plan's *Lecture* seat sells no
  `max_projects`, because a reader stores nothing to count. A read-only seat
  is exactly somebody who should see the work, and that test refuses them.

So coverage is asked directly — owner, named subscriber, or on
`subscription_members` — against the **entitlement's** clock rather than a
second reading of the subscription's dates. One convention for "is this in
force", not two.

## 4. What did not change

**The tenant-wide answer.** Resolving capabilities with nobody named still
asks what the *organisation* bought: its own subscriptions, and no seat,
because one person's seat is not the tenant's. That answer is what usage is
measured against and what the console shows, and neither is about any one
person.

This is why the exclusion is a `CASE` and not a widened condition. Written
without it, `IS DISTINCT FROM NULL` is true of every subscription and the
tenant-wide answer would have silently emptied — quota measurement, staff
screens and readiness all reading zero, with nothing failing loudly.

**Overrides.** A negotiated exception still grants what it grants, to the
organisation. Staff hand out a feature; they do not hand out a seat.

**The `users` quota itself.** It counted the owner before and counts the
owner now. What changed is that it now bounds something.

## 5. Consequences

**Members who are on no subscription lose access the moment this deploys**,
and that is the rule working rather than a migration failure. There is no
backfill, deliberately: the operator's instruction was that a subscriber is
alone on their subscription until they add somebody, and a migration that
quietly put every existing member on one would have written exactly the
state this ADR removes — and, for the offers that sell one user, written it
over the quota.

The way back is one screen. `SubscriptionPeople` already exists, the owner
already manages it, and `POST /subscription/people` already refuses at the
quota:

```text
409 PEOPLE_QUOTA_REACHED
"This subscription covers 3 people, and they are all taken."
```

**A deployment whose offers sell fewer users than it has working members
will notice immediately.** That is information: the offer says what was
bought.

**One more query on the authenticated hot path.** An indexed `EXISTS` over
`entitlements`, `subscriptions` and `subscription_members`, resolved once
per request beside the capabilities. It is not derivable from what was
already being asked (§3), so it is asked.

## 6. Alternatives rejected

**Gate on a capability every workspace offer grants.** There is no such
capability that is not product-specific, and inventing one would be a
product's fact in a platform gate — what `gate:products` exists to forbid.
`max_projects` looked like the candidate until *Lecture* showed it refuses
readers.

**Keep `TENANT` entitling every member and bound access some other way.**
This keeps two answers to "who may use what" — entitlement says everyone,
access control says some — and the one nobody is looking at is the one that
will be wrong. The `users` quota would have stayed decorative.

**Backfill every current member onto their subscription.** Nobody loses
access on the day, and every deployment starts over its own quota with the
rule already broken. Offered to the operator and declined.
