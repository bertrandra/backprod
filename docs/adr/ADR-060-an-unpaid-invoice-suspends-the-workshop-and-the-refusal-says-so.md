# ADR-060 — An unpaid invoice suspends the workshop, and the refusal says so

*Accepted, 27 September 2026. Implements étape 5 of
`docs/subscription-lifecycle-spec.md` §5 (`PAST_DUE` et le recouvrement).
Extends ADR-053 (buying covers people), ADR-027 (the cron-polled job queue) and
§27.1 (notifications).*

---

## The problem

`PAST_DUE` did not exist. `grep` found it in neither `src/` nor `migrations/`:
the three statuses were `ACTIVE`, `CANCELLED` and `EXPIRED`, and an invoice a
customer never settled left the subscription `ACTIVE` and the product wide open
for ever. Nothing chased the debt either — there is no producer of any kind
against an unpaid invoice — so a customer who stopped paying simply kept the
product.

## The decisions

### A status, not a flag

`status` gains `PAST_DUE`. §2.2 of the specification gives the rule that
separates a status from an indicator, and it decides this: `cancel_at_period_end`
and `pending_offer_version_id` describe a *future* ending and leave today's
access entirely intact, so putting either in `status` would make `status` lie
about the access. Arrears describes today's access — it is suspended — so it
belongs there.

The payoff is measurable. `Subscription::isLiveAt()` tests `status === ACTIVE`,
so a fourth status suspended every entitlement query in the platform with no
branch on the new word written anywhere. A boolean column would have needed one
at each of them, and the one that was missed would have been a workshop left
open.

### Suspended, not restricted

Decided by the operator on 2026-09-26, and it is the hard answer rather than the
kind one. "Restricted" would have required deciding *what stays open*, product by
product: a question with no general answer, one that would have to be re-asked
for every new product, and one to which an oversight answers "open".

**What is suspended is the entitlements, never the documents.** The customer
keeps reaching `/invoices`, `/payments` and `/subscription`, or the door shut
would be the one they came to pay at, and the suspension would be unrecoverable
from inside the product. `requireSubscription()` is the gate, and it guards the
workspace; the document routes are gated on permissions and stay that way.

Two things are deliberately *not* suspended and are worth naming, because both
look like oversights:

- **the entitlement rows themselves.** They still list what the subscription
  grants, so the subscription screen can still say what is owned and paying
  restores access with nothing to re-grant. Coverage is what closes the
  workshop, which is exactly what §5.1 says: *« la suspension porte sur
  l'atelier »*;
- **the subscription itself.** A collection pass never cancels. Ending a
  customer's contract is a decision (§13.1) with a rule, an effective date and
  months owed, and a pass run by cron is not where one gets taken.

### The refusal is its own: `SUBSCRIPTION_PAST_DUE`

This is the decision the whole étape turns on. `SUBSCRIPTION_REQUIRED` means
*"your organisation has one and it does not cover you"*, and it is answered by a
colleague giving you a place. Arrears is answered by a card. Raised as the same
code with the same wording, the holder of a seat goes and asks a colleague for a
place they already hold — and the invoice stays unpaid while they wait, which is
the one outcome §5 exists to prevent.

It could not be fixed in the frontend: a screen cannot tell two identical codes
apart either, and the frontend is never the authority. So the distinction is
minted where the question is answered.

`EntitlementRepository::covers(): bool` therefore became
`coverageFor(): Coverage`, a three-valued answer — `COVERED`, `IN_ARREARS`,
`NONE` — and `RequestContext::requireSubscription()` raises one of two codes
from it. `COVERED` wins over `IN_ARREARS`, because a person may be reached by
their own seat *and* their organisation's subscription, and one of them being
owed for must not shut a workshop the other pays for. That is the same
most-generous rule that settles two entitlements granting one feature.

Nothing about the debt travels with the refusal: a refusal is a poor place to
publish a price, and the remedy is addressable — `GET /subscription` carries
`past_due_since` and `past_due_invoice_id`, and coverage does not gate it.

### Arrears is not an exit, so it does not free the scope

`subscriptions_one_active_tenant_subscription` and
`subscriptions_one_active_seat_per_person` were partial on `status = 'ACTIVE'`.
A fourth status silently moved a suspended subscription out from under both: a
customer owing for March could have bought a second subscription in April and
left the first unpaid for ever. Both widen to `status IN ('ACTIVE', 'PAST_DUE')`,
and `findActive`, `liveFor` and `Subscriptions::current()` read the same pair —
otherwise the screen would tell somebody who owes for a subscription that they
have none, and offer them a fresh purchase instead of the invoice.

`Subscription::isHeldAt()` is that question, and `isLiveAt()` stays the
entitlement one. Two names because there are two questions.

### The schedule is the product's, and the first chase is the grace

`product_configuration` carries it, as it carries the billing supplier (ADR-044)
and the free period's length (ADR-059):

```json
{"retries": [1, 3, 7]}
```

A constant would make changing a commercial delay a deployment, and two products
have no reason to chase at the same rhythm. **Absent means the default**, which
is the opposite choice from `FreemiumPeriod` — and deliberately: there, guessing
gives something away, and giving away is the direction that cannot be taken
back; here, failing closed would mean never chasing an unpaid invoice, which
loses money and leaves the product running for free.

The first retry day is also the grace, and that is one number rather than two.
§5.1 dates arrears from the invoice's due date, but every invoice this platform
raises says "payable on receipt", so due is *now*: declaring arrears at the due
second would suspend a customer in the middle of paying, since an upgrade's
proration invoice is issued and settled seconds apart. `retries[0]` answers both
questions — how long silence is tolerated, and when the chasing starts — because
they are the same question.

The pass reads the clock and the configuration separately on purpose. The
repository answers *how many whole days overdue*, computed by PostgreSQL from
the invoice's own dates; the handler asks the product's configuration what that
means. A query that joined the schedule would read a JSON document per row in
SQL and put a commercial decision inside a statement.

### A chase is a notification and a fresh attempt, from the queue

Never inside an HTTP request (§27.1), and there is no request to put it in
anyway: noticing a debt is something the clock does. `subscription.dunning` is a
job handler, claimed by cron through `bin/run-jobs.php`.

Per debt, per pass, in this order:

```text
1. declare the arrears      once, by status in the WHERE — not by a prior read
2. raise the notice         claims this step, by the dedup unique index
3. start a fresh attempt    a new payments row, never an old one revived
```

**The notice comes before the attempt**, and the ordering is a choice between two
failures. A crash between 2 and 3 loses one attempt and the customer is still
told — which they can act on, since paying from the invoice screen is the only
way the money can actually arrive. A crash between 3 and 2 would leave money
asked for and nobody told, and *"did we tell them?"* would answer no for ever.

**Exactly once per step, by index.** The dedup key is `{invoice}:{step}`, so a
pass running nightly through a seven-day window writes three notices and not
seven, and two runners overlapping write one between them. It also keeps the
*attempt* to one per step, because the attempt is behind the same claim.

**What an attempt honestly is.** §24 keeps the instrument outside this platform —
a card never reaches it — so there is no card here to charge off-session. An
attempt is a fresh authorization the customer completes from the invoice screen,
and it is a new `payments` row every time: "a new attempt, never the same one
revived", the rule `Checkout::retry` already states, because `PaymentStatus` is
one-way and two attempts that must be told apart cannot share a provider
reference.

**Stopping needs no state.** Past the last retry day the schedule keeps answering
the last step, whose notice already exists, and the index refuses a second. "Then
stop" is the absence of a next step rather than a flag anybody has to clear.

### The demand keeps its rendered body

A formal demand has legal effect (§27.1), so the notification is raised with
`legalEffect: true` and `DispatchNotifications` keeps the body as it went out —
for the reason an invoice keeps its snapshot. It has words of its own in all five
languages (`MailWording`), because the generic rendering would reach an inbox as
`days overdue: 3` under a title, which is not a demand anybody can act on.

It is a notification and **never a message** (§12.3): a conversation has
participants, an order and a reply, and a chase has none of the three. Putting it
in a support thread would put system noise there.

Nothing in the payload is a secret, a token, payment data or an exception — a
provider being unreachable is logged with its exception class and never travels
on a channel that leaves the platform.

### Paying lifts it, through the invoice

`InvoicePaid` gained a second implementation, so the port fans out through a
composite (`EverythingWaitingOnAPaidInvoice`) rather than one listener calling
the other — which would make Sales know about arrears or Commerce know about
orders, and the port exists so Billing has to learn neither.

It hangs off the **invoice** and not the payment webhook, because §25 lets an
invoice reach PAID two ways and a gate hung off the card alone would leave every
transfer-paying customer locked out. And it is keyed on *which* invoice: a
subscription suspended by March's document is not reopened by April's being
settled.

Both ends are in `subscription_events` (`ARREARS_DECLARED`, `ARREARS_CLEARED`),
because the column only says where things stand now and *"why could I not reach
my work last week?"* has to be answerable.

## What this does not do

- **Nothing raises a renewal invoice yet.** §5.1 says a subscription goes
  `PAST_DUE` when a *renewal* invoice goes unpaid; nothing in the platform bills
  a renewal (M6 never landed this part). The mechanism is written against any
  invoice raised against a subscription — a proration, a buy-out, a renewal when
  one exists — which is the honest generalisation and needs no change when
  renewal billing arrives.
- **`invoices.due_at` is never written.** The pass reads
  `coalesce(due_at, issued_at)`: an invoice whose terms say "payable on receipt"
  is due when it was issued, and the column is honoured the day a deployment
  sets one.
- **No screen triggers the collection**, and none should: it is a job, requested
  through `POST /jobs` or claimed by cron.
- **No screen edits the schedule either**, which is the same gap ADR-059 left
  for the free period's length: `ConfigurationDesk` is deliberately not a
  free-form JSONB editor, and it knows the billing supplier and the tax position
  and nothing else. Until it learns this key, a deployment sets `dunning` the
  way it sets `freemium` — in its seed. That is a missing screen and not a
  missing decision: the value is already configuration, and moving it under an
  editor changes no code that reads it.
- **The demonstration world seeds no debt.** Every invoice in it is paid, so
  nothing there is suspended — a suspended tenant in the demonstration would
  make half its screens refuse.
